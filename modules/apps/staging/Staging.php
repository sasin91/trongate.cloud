<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';

/**
 * Child module of apps: a production app's staging app
 * (Platform_actions::create_staging_app). Verifying a custom domain adds one
 * by itself; this adds one to an app that went live before that, or whose
 * staging app was deleted. Every member of the app's project may use it.
 *
 * @property-read Staging_model $model
 * @property-read Validation $validation
 */
class Staging extends Trongate {
    use Customer_guard;

    /**
     * POST apps/{id}/staging/submit
     */
    function submit(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        if ($app->environment !== 'production') {
            redirect('apps/' . $app->id);
        }

        $staging_id = $this->model->create_staging_app((int) $app->project_id, (int) $app->id);
        $staging = $this->model->find_staging_app((int) $app->id);

        set_flashdata('Staging added. Pushes to ' . htmlspecialchars((string) $staging->source_branch) . ' deploy it; change the branch in its Settings.');
        redirect('apps/' . $staging_id);
    }
}
