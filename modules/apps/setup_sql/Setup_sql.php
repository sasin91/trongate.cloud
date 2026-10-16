<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/platform/live/Setup_sql_plan.php';

/**
 * Child module of apps: the SQL an app's database starts with. The new-app
 * wizard asks for it (traits/Setup_sql_step.php, through App_form), the
 * first deploy runs it once (Platform_task::run_setup_sql), and when it
 * stops part way the overview shows views/_failure.php until it is
 * dismissed here. Every member of the app's project may dismiss it.
 *
 * The wizard's first administrator (views/_administrator_form.php) is
 * hashed here too, before there is an app, for whoever may add one.
 *
 * @property-read Setup_sql_model $model
 * @property-read Validation $validation
 */
class Setup_sql extends Trongate {
    use Customer_guard;

    /**
     * POST apps/{id}/setup-sql/dismiss: hide the alert about a failed setup SQL
     */
    function dismiss(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $this->model->dismiss_setup_sql((int) $app->id);

        redirect('apps/' . $app->id);
    }

    /**
     * setup-sql/administrator: the first administrator form, for a Trongate
     * MX modal (mx-build-modal)
     */
    function administrator_form(): void {
        $this->_make_sure_allowed();
        $this->_customer_or_redirect();

        $choices = App_password::choices(null, 'varchar(255)');

        require __DIR__ . '/views/_administrator_form.php';
    }

    /**
     * POST setup-sql/administrator/submit: hash the first administrator's password
     * (username, email, password, password_confirm, algorithm) and answer with
     * views/_administrator.php for the wizard, or 422 with JSON errors that
     * MX shows in the form. Nothing is stored; the wizard posts the hash.
     */
    function administrator(): void {
        $this->_make_sure_allowed();
        $this->_customer_or_redirect();
        $this->_require_post_with_csrf();

        $this->validation->set_rules('username', 'username', 'required|max_length[65]');
        $this->validation->set_rules('email', 'email', 'max_length[255]');
        $this->validation->set_rules('password', 'password', 'required');
        $this->validation->set_rules('password_confirm', 'password confirmation', 'required|matches[password]');

        if ($this->validation->run() !== true) {
            echo validation_errors(422); // JSON that MX shows next to each field (highlight-errors)
            return;
        }

        $username = trim((string) post('username'));
        $email = trim((string) post('email'));

        try {
            $hash = App_password::hash((string) post('password'), null, (string) post('algorithm'));
        } catch (RuntimeException $e) {
            $this->_refuse_administrator('password', $e->getMessage());
            return;
        }

        $refusal = Setup_sql_plan::administrator_refusal($username, $email, $hash);

        if ($refusal !== null) {
            $this->_refuse_administrator('username', $refusal);
            return;
        }

        $administrator = [
            'username' => $username,
            'email' => $email,
            'hash' => $hash,
            'hashed_with' => App_password::describe_value($hash),
        ];

        header('Content-Type: text/html');
        require __DIR__ . '/views/_administrator.php';
    }

    /**
     * Answer the first administrator form with why it was refused: the same
     * 422 JSON as validation_errors(422), shown under $field
     */
    function _refuse_administrator(string $field, string $message): void {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode([$field => [$message]], JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
