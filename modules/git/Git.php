<?php

require_once APPPATH . 'modules/platform/git/Git_repo.php';
require_once __DIR__ . '/Git_http.php';
require_once APPPATH . 'modules/log/Log.php';

/**
 * trongate.cloud git over HTTPS: git/p{app_id}.git, the repository an app on
 * trongate.cloud git builds from (Git_repo). Git's smart HTTP protocol, served
 * by `git http-backend` (Git_http):
 *
 *   GET  git/p12.git/info/refs?service=...   what the repository has
 *   POST git/p12.git/git-upload-pack         clone and fetch
 *   POST git/p12.git/git-receive-pack        push
 *
 * Every request needs an access token of that repository (Git_tokens) as the
 * HTTP Basic password; the user name is ignored. A push that moves a branch
 * queues a deploy of each app built from it, unless Deploy on push is off.
 *
 * @property-read Git_model $model
 */
class Git extends Trongate {

    function info_refs(): void {
        $this->_serve('info/refs', false);
    }

    function upload_pack(): void {
        $this->_serve('git-upload-pack', false);
    }

    function receive_pack(): void {
        $this->_serve('git-receive-pack', true);
    }

    /**
     * Check the repository and the token, then hand the request to git
     */
    function _serve(string $service_path, bool $is_push): void {
        $repo = Git_repo::from_path_name((string) segment(3));

        if ($repo === null || !$repo->exists()) {
            Git_http::refuse(404, 'Repository not found.');
            return;
        }

        $token = $this->model->find_git_token($repo->app_id(), $this->_basic_password());

        if ($token === false) {
            Git_http::refuse(401, 'Use an access token from the app\'s Git tab as the password.');
            return;
        }

        $this->model->mark_git_token_used((int) $token->id);
        $remote_user = 'customer-' . (int) $token->customer_id;

        if (!$is_push) {
            Git_http::serve($repo, $service_path, $remote_user);
            return;
        }

        $moved = $this->_receive($repo, $service_path, $remote_user);
        $this->_queue_push_deploys($repo, $moved);
    }

    /**
     * Run the push with the repository locked, so pushes take turns; returns
     * the branches it moved, with their new SHA
     *
     * @return array<string, string>
     */
    function _receive(Git_repo $repo, string $service_path, string $remote_user): array {
        $lock = fopen($repo->path() . '/trongate-push.lock', 'c');

        if ($lock === false || !flock($lock, LOCK_EX)) {
            Git_http::refuse(503, 'The repository is busy. Please push again.');
            return [];
        }

        $before = $repo->branch_heads();
        Git_http::serve($repo, $service_path, $remote_user);
        $after = $repo->branch_heads();

        flock($lock, LOCK_UN);
        fclose($lock);

        return Git_repo::moved_branches($before, $after);
    }

    /**
     * A deploy of each app built from a moved branch, as a GitHub push does
     *
     * @param array<string, string> $moved
     */
    function _queue_push_deploys(Git_repo $repo, array $moved): void {
        foreach ($moved as $branch => $sha) {
            foreach ($this->model->internal_apps_on_branch($repo->app_id(), $branch) as $app) {
                if (empty($app->auto_deploy)) {
                    continue;
                }

                $this->_queue_push_deploy($app, $sha);
            }
        }
    }

    function _queue_push_deploy(object $app, string $sha): void {
        try {
            $this->model->queue_deploy((int) $app->project_id, (int) $app->id, null, 'push', $sha);
        } catch (RuntimeException $e) {
            (new Log([new Error_log_writer('[git] ')]))->error("Push: no deploy queued for app #{$app->id}: {$e->getMessage()}");
        }
    }

    /**
     * The HTTP Basic password: the access token
     */
    function _basic_password(): string {
        if (isset($_SERVER['PHP_AUTH_PW'])) {
            return (string) $_SERVER['PHP_AUTH_PW'];
        }

        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!str_starts_with($header, 'Basic ')) {
            return '';
        }

        $decoded = base64_decode(substr($header, 6), true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return '';
        }

        return explode(':', $decoded, 2)[1];
    }
}
