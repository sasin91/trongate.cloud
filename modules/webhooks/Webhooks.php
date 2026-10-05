<?php

/**
 * The GitHub App's webhook: POST webhooks/github.
 *
 * GitHub signs the raw body with the App's webhook secret
 * (GITHUB_APP_WEBHOOK_SECRET) in X-Hub-Signature-256, for every repository
 * the App is installed on. A signed push queues a deployment of every app
 * built from that repository and branch, unless its customer turned Deploy on
 * push off (app.auto_deploy). The deploy task builds the branch head, so
 * replaying an old push cannot roll back. A push to the platform's own
 * repository and branch (PLATFORM_SOURCE_REPOSITORY) also queues build_platform.
 *
 * @property-read Webhooks_model $model
 */
class Webhooks extends Trongate {

    /** GitHub caps payloads at 25 MB */
    const MAX_BODY_BYTES = 26214400;

    function github(): void {
        if (REQUEST_TYPE !== 'POST') {
            $this->_respond(405, ['error' => 'method_not_allowed']);
            return;
        }

        if (GITHUB_APP_WEBHOOK_SECRET === '') {
            $this->_respond(503, ['error' => 'not_configured']);
            return;
        }

        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->_respond(413, ['error' => 'payload_too_large']);
            return;
        }

        if (!$this->_valid_signature($body, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), GITHUB_APP_WEBHOOK_SECRET)) {
            $this->_respond(401, ['error' => 'invalid_signature']);
            return;
        }

        $event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
        $payload = $this->_decode_payload($body);

        if ($payload === null) {
            $this->_respond(400, ['error' => 'invalid_payload']);
            return;
        }

        if ($event === 'ping') {
            $this->_respond(200, ['ok' => true, 'message' => 'pong']);
        } elseif ($event === 'push') {
            $this->_handle_push($payload);
        } else {
            $this->_respond(202, ['ok' => true, 'ignored' => 'event']);
        }
    }

    /**
     * Queue a deploy of each app on the pushed repository and branch; ignore
     * tags, branch deletions and repositories no app is built from.
     */
    function _handle_push(array $payload): void {
        $ref = (string) ($payload['ref'] ?? '');
        $sha = strtolower((string) ($payload['after'] ?? ''));
        $repository = $this->_repository_path((string) ($payload['repository']['html_url'] ?? ''));

        if (!str_starts_with($ref, 'refs/heads/') || $repository === '') {
            $this->_respond(202, ['ok' => true, 'ignored' => 'ref', 'ref' => $ref]);
            return;
        }

        if (!empty($payload['deleted']) || !preg_match('/^[0-9a-f]{40}$/', $sha) || trim($sha, '0') === '') {
            $this->_respond(202, ['ok' => true, 'ignored' => 'deleted_or_empty']);
            return;
        }

        $branch = substr($ref, strlen('refs/heads/'));
        $deployments = [];
        $skipped = [];
        $platform_build = null;

        // The platform's own repository: rebuild its images, which Flux rolls out
        if (PLATFORM_SOURCE_REPOSITORY !== '' && $repository === PLATFORM_SOURCE_REPOSITORY && $branch === PLATFORM_SOURCE_BRANCH) {
            $platform_build = $this->model->queue_platform_build($sha) ?? 'already_queued';
        }

        foreach ($this->model->apps_on_branch('https://github.com/' . $repository, $branch) as $app) {
            if (empty($app->auto_deploy)) {
                $skipped[(int) $app->id] = 'auto_deploy_off';
                continue;
            }

            try {
                $deployments[(int) $app->id] = $this->model->queue_deploy((int) $app->project_id, (int) $app->id, null, 'webhook', $sha);
            } catch (RuntimeException $e) {
                $skipped[(int) $app->id] = 'not_deployable';
            }
        }

        if ($deployments === [] && $skipped === [] && $platform_build === null) {
            $this->_respond(202, ['ok' => true, 'ignored' => 'no_app']);
            return;
        }

        $body = ['ok' => true, 'deployments' => $deployments, 'skipped' => $skipped, 'commit_sha' => $sha];

        if ($platform_build !== null) {
            $body['platform_build'] = $platform_build;
        }

        $this->_respond(202, $body);
    }

    /**
     * Constant-time check of "sha256=<hex hmac of raw body>".
     */
    function _valid_signature(string $body, string $header, string $secret): bool {
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $header);
    }

    /**
     * JSON body, or the form-encoded "payload" field when the webhook uses application/x-www-form-urlencoded.
     */
    function _decode_payload(string $body): ?array {
        $content_type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));

        if (str_starts_with($content_type, 'application/x-www-form-urlencoded')) {
            parse_str($body, $fields);
            $body = is_string($fields['payload'] ?? null) ? $fields['payload'] : '';
        }

        $payload = json_decode($body, true);

        return is_array($payload) ? $payload : null;
    }

    function _repository_path(string $url): string {
        $path = (string) parse_url(rtrim($url, '/'), PHP_URL_PATH);

        return strtolower(preg_replace('/\.git$/', '', trim($path, '/')) ?? '');
    }

    function _respond(int $status, array $body): void {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
    }
}
