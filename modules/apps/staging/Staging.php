<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';

/**
 * Child module of apps: a production app's staging app (tasks/split_staging.php).
 * A domain going live adds one by itself; this adds one to an app that went
 * live before that, or whose staging app was deleted. Every member of the
 * app's project may use it.
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

        try {
            $this->model->queue_staging_split((int) $app->project_id, (int) $app->id);
        } catch (RuntimeException $e) {
            error_log("Add staging for app #{$app->id} refused: {$e->getMessage()}");
            set_flashdata(htmlspecialchars($e->getMessage()));
            redirect('apps/' . $app->id);
        }

        set_flashdata('Setting up staging. ' . htmlspecialchars($app->slug . '.' . PLATFORM_APPS_DOMAIN) . ' becomes your staging app, and production stays on ' . htmlspecialchars((string) $app->verified_domain) . '.');
        redirect('apps/' . $app->id);
    }
}
