<?php

require_once APPPATH . 'modules/platform/manifests/Manifests.php';

/**
 * The Database tab's Credentials dialog: the host, port, database, user and
 * password the app connects with (the same values Manifests::app writes into
 * its config/database.php). The password stays hidden until Show, and every
 * Show is written to app_database_log, so History says who saw it.
 *
 * The using controller's model uses App_database_log.
 */
trait App_database_credentials {

    /**
     * apps/{id}/database/credentials: views/_credentials.php, for a Trongate MX
     * modal (mx-build-modal, Database::CREDENTIALS_MODAL)
     */
    function credentials(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $server = $this->_credentials_server($app);

        $data = [
            'base' => 'apps/' . $app->id . '/database',
            'error' => null,
            'fields' => [],
        ];

        if ($server === null) {
            $data['error'] = 'This app has no database yet. It is created on the first deployment.';
            $this->view('_credentials', $data);
            return;
        }

        $name = Manifests::database_name((int) $app->id);
        $data['fields'] = [
            ['label' => 'Host', 'value' => (string) $server->host],
            ['label' => 'Port', 'value' => (string) $server->port],
            ['label' => 'Database', 'value' => $name],
            ['label' => 'Username', 'value' => $name],
        ];

        $this->view('_credentials', $data);
    }

    /**
     * POST apps/{id}/database/credentials/password: log that the customer asked
     * for the password, then answer with it (views/_credentials_password.php),
     * which MX swaps in where the dots were
     */
    function show_password(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        if ($this->_credentials_server($app) === null) {
            http_response_code(404);
            echo 'This app has no database yet.';
            return;
        }

        $this->model->log_action([
            'app_id' => (int) $app->id,
            'customer_id' => $this->_database_customer_id(),
            'action' => 'credentials',
            'statement' => 'Showed the database password',
            'allow_changes' => false,
            'affected' => null,
            'error' => null,
        ]);

        $this->view('_credentials_password', [
            'password' => Manifests::database_password((int) $app->id),
        ]);
    }

    /**
     * The database server the app's database is on, or null when it has none yet
     */
    function _credentials_server(object $app): ?object {
        if (empty($app->database_server_id)) {
            return null;
        }

        $server = $this->model->database_server((int) $app->database_server_id);

        if ($server === false) {
            return null;
        }

        return $server;
    }
}
