<?php

require_once APPPATH . 'modules/customer/traits/Current_customer.php';
require_once APPPATH . 'modules/platform/traits/Platform_actions.php';
require_once APPPATH . 'modules/apps/traits/App_records.php';
require_once APPPATH . 'modules/git/traits/Git_tokens.php';
require_once APPPATH . 'modules/platform/git/Git_repo.php';

/**
 * @property-read Db $db
 */
class Repository_model extends Model {
    use Current_customer;
    use Platform_actions;
    use App_records;
    use Git_tokens;

    /**
     * An app on trongate.cloud git: the app row with an empty repository of
     * its own (Git_repo), main as its branch, Deploy on push on. Nothing is
     * deployed until the first push to main.
     */
    function create_internal_app(int $project_id, string $label, string $type): int {
        return $this->db->transaction(function () use ($project_id, $label, $type) {
            $app_id = $this->create_app(
                project_id: $project_id,
                label: $label,
                type: $type,
                source_url: '',
                source_provider: Git_repo::PROVIDER,
            );

            if ($app_id === false) {
                throw new RuntimeException('The app could not be created');
            }

            $this->db->update($app_id, ['source_url' => Git_repo::clone_url(BASE_URL, $app_id)], 'app');
            Git_repo::for_app($app_id)->create();

            return $app_id;
        });
    }
}
