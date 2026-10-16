<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/platform/live/App_backup.php';
require_once APPPATH . 'modules/platform/live/Object_storage.php';

/**
 * Child module of apps: downloading the backup taken of a deleted app
 * (App_backup). The project page lists them (Projects::show); every member
 * of the project may download one until it expires.
 *
 * @property-read Backups_model $model
 */
class Backups extends Trongate {
    use Customer_guard;

    /**
     * apps/{id}/backup/{database|files}: sends the browser to a short-lived
     * signed link to that part of the backup in the bucket
     */
    function download(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app_id = segment(3, 'int');
        $part = segment(4);

        $backup = $this->model->member_app_backup((int) $customer->id, $app_id);

        if ($backup === false) {
            set_flashdata('That backup is no longer available.');
            redirect('dashboard');
        }

        $storage = Object_storage::for_app_backups();

        if ($storage === null || !isset(App_backup::PARTS[$part])) {
            http_response_code(404);
            die('Not found');
        }

        $key = App_backup::key((string) $backup->object_prefix, $part);
        $query = App_backup::download_query((string) $backup->slug, $part);

        redirect($storage->link('GET', $key, App_backup::DOWNLOAD_LINK_SECONDS, $query));
    }
}
