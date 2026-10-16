<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';

/**
 * Child module of apps: the SQL an app's database starts with. The new-app
 * wizard asks for it (traits/Setup_sql_step.php, through App_form), the
 * first deploy runs it once (Platform_task::run_setup_sql), and when it
 * stops part way the overview shows views/_failure.php until it is
 * dismissed here. Every member of the app's project may dismiss it.
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
}
