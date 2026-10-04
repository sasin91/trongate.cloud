<?php

/**
 * Public GitHub webhook endpoint: POST webhooks/github/{project_id}.
 *
 * Each project has its own secret (derived from the project.webhook_secret seed and
 * APP_KEY, shown in project settings); GitHub signs the raw body with it in
 * X-Hub-Signature-256. A signed push to the project's branch queues a deployment,
 * unless the customer turned Deploy on push off (project.auto_deploy).
 * The deploy task builds the branch head, so replaying an old push cannot roll back.
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

        $project = $this->model->find_webhook_project(segment(3, 'int'));

        if ($project === false || empty($project->webhook_secret)) {
            $this->_respond(404, ['error' => 'not_found']);
            return;
        }

        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $this->_respond(413, ['error' => 'payload_too_large']);
            return;
        }

        $secret = $this->model->webhook_signing_secret((int) $project->id, (string) $project->webhook_secret);

        if (!$this->_valid_signature($body, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $secret)) {
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
            $this->_handle_push($project, $payload);
        } else {
            $this->_respond(202, ['ok' => true, 'ignored' => 'event']);
        }
    }

    /**
     * Queue a deploy for a push to the project's branch; ignore other refs, tags and branch deletions.
     */
    function _handle_push(object $project, array $payload): void {
        if (empty($project->auto_deploy)) {
            $this->_respond(202, ['ok' => true, 'ignored' => 'auto_deploy_off']);
            return;
        }

        $ref = (string) ($payload['ref'] ?? '');
        $sha = strtolower((string) ($payload['after'] ?? ''));
        $branch = (string) ($project->source_branch ?: 'main');

        if ($ref !== 'refs/heads/' . $branch) {
            $this->_respond(202, ['ok' => true, 'ignored' => 'ref', 'ref' => $ref]);
            return;
        }

        if (!empty($payload['deleted']) || !preg_match('/^[0-9a-f]{40}$/', $sha) || trim($sha, '0') === '') {
            $this->_respond(202, ['ok' => true, 'ignored' => 'deleted_or_empty']);
            return;
        }

        if (!$this->_same_repository($project, $payload)) {
            $this->_respond(202, ['ok' => true, 'ignored' => 'repository']);
            return;
        }

        try {
            $deployment_id = $this->model->queue_deploy((int) $project->team_id, (int) $project->id, null, null, 'webhook', $sha);
        } catch (RuntimeException $e) {
            $this->_respond(422, ['error' => 'not_deployable', 'message' => $e->getMessage()]);
            return;
        }

        $this->_respond(202, ['ok' => true, 'deployment_id' => $deployment_id, 'commit_sha' => $sha]);
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

    /**
     * The push must come from the project's repository (guards against a secret reused across repos).
     */
    function _same_repository(object $project, array $payload): bool {
        $expected = $this->_repository_path((string) $project->source_url);
        $actual = $this->_repository_path((string) ($payload['repository']['html_url'] ?? ''));

        return $expected !== '' && $expected === $actual;
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
