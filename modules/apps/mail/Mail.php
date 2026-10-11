<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/platform/mail/Mail_capture.php';
require_once APPPATH . 'modules/platform/mail/Mail_message.php';

/**
 * Child module of apps: apps/{id}/mail, the mail the app sent while mail
 * capture is on (Mail_capture): the platform's inbox kept it instead of
 * delivering it. The list polls poll() for new messages. Every member of the
 * app's project may read and delete them, like logs.
 *
 * @property-read Mail_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 */
class Mail extends Trongate {
    use Customer_guard;
    use App_page;

    /**
     * apps/{id}/mail, with ?open={mail id} showing one message
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $open = $this->_open_message($app);

        $data = [
            'view_module' => 'apps/mail',
            'view_file' => 'index',
            'app' => $app,
            ...$this->_app_header_data($app),
            'capture_on' => Mail_capture::enabled((array) $app),
            'messages' => $this->model->messages((int) $app->id),
            'open' => $open,
            'parsed' => $open !== false ? Mail_message::parse((string) $open->raw) : null,
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/mail', 'Mail']],
            'additional_includes_top' => ['apps_module/css/apps.css', 'apps-mail_module/css/mail.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * The message list, polled by the page every few seconds (?open= keeps the open one marked)
     */
    function poll(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app = $this->model->find_member_app((int) $customer->id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $this->view('_list', [
            'view_module' => 'apps/mail',
            'app' => $app,
            'messages' => $this->model->messages((int) $app->id),
            'open_id' => (int) ($_GET['open'] ?? 0),
        ]);
    }

    /**
     * apps/{id}/mail/source?id={mail id}: the message as the app sent it (.eml)
     */
    function source(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $message = $this->model->message((int) $app->id, (int) ($_GET['id'] ?? 0));

        if ($message === false) {
            redirect('apps/' . $app->id . '/mail');
        }

        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="' . $app->slug . '-mail-' . (int) $message->id . '.eml"');
        header('X-Content-Type-Options: nosniff');
        echo $message->raw;
    }

    /**
     * POST apps/{id}/mail/delete: one message (mail_id), or all of them (all=1)
     */
    function delete(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        if ((string) post('all') === '1') {
            $this->model->delete_all((int) $app->id);
            set_flashdata('All captured mail deleted.');
        } else {
            $this->model->delete_message((int) $app->id, (int) post('mail_id'));
        }

        redirect('apps/' . $app->id . '/mail');
    }

    /**
     * The message ?open= names, if it is the app's
     */
    function _open_message(object $app): object|false {
        $mail_id = (int) ($_GET['open'] ?? 0);

        return $mail_id > 0 ? $this->model->message((int) $app->id, $mail_id) : false;
    }
}
