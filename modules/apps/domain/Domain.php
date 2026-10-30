<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once APPPATH . 'modules/apps/traits/App_page.php';
require_once APPPATH . 'modules/apps/traits/App_rules.php';
require_once APPPATH . 'modules/platform/manifests/Manifests.php';
require_once APPPATH . 'modules/platform/manifests/Domain_dns.php';

/**
 * Child module of apps: apps/{id}/domain, the app's custom domain. Set or
 * clear it, see the exact DNS records to add (Domain_dns::setup_records) and
 * whether each one is in place yet, verify ownership, and follow it until it
 * is live with HTTPS (recheck_domains does the rest by itself). Every member
 * of the app's project may use it, like settings.
 *
 * @property-read Domain_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 * @property-read Validation $validation
 * @property-read Secrets $secrets
 */
class Domain extends Trongate {
    use Customer_guard;
    use App_page;
    use App_rules;

    /**
     * apps/{id}/domain
     */
    function index(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));

        $domain_input = (string) ($app->domain ?? '');

        if (REQUEST_TYPE === 'POST') {
            $domain_input = (string) post('domain', true);
        }

        $data = [
            'view_module' => 'apps/domain',
            'view_file' => 'index',
            'app' => $app,
            'domain_input' => $domain_input,
            'platform_host' => $app->slug . '.' . PLATFORM_APPS_DOMAIN,
            'serves_slug_host' => Manifests::serves_slug_host((array) $app),
            ...$this->_app_header_data($app),
            ...$this->_domain_progress($app),
            'records' => $this->_unchecked_records($app),
            'crumbs' => [...$this->_app_crumbs($app), ['apps/' . $app->id . '/domain', 'Domain']],
            'additional_includes_top' => ['apps_module/css/apps.css', 'apps-domain_module/css/domain.css'],
            'additional_includes_btm' => ['apps-domain_module/js/domain.js'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * apps/{id}/domain/records: _records.php with what DNS says about each
     * record right now. The page loads it once, so a slow lookup never holds
     * up the page itself.
     */
    function records(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $app = $this->model->find_member_app((int) $customer->id, segment(3, 'int'));

        if ($app === false) {
            http_response_code(404);
            return;
        }

        $records = [];
        $domain = (string) ($app->domain ?? '');

        if ($domain !== '') {
            $verified = $domain === (string) ($app->verified_domain ?? '');
            $records = Domain_dns::checked_records($this->_setup_records($app), $verified);
        }

        $this->view('_records', ['records' => $records]);
    }

    /**
     * POST apps/{id}/domain/submit: set, change or clear the custom domain
     */
    function submit(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->editing_app_id = (int) $app->id;

        $this->validation->set_rules('domain', 'Custom Domain', 'max_length[253]|callback__validate_domain');

        if ($this->validation->run() !== true) {
            $this->index();
            return;
        }

        $old_domain = (string) ($app->domain ?? '');
        $domain = $this->_normalise_domain((string) post('domain', true));

        $this->model->update_app_domain((int) $app->project_id, (int) $app->id, $domain);

        if ($domain === null) {
            set_flashdata($old_domain === '' ? 'No custom domain set.' : 'Custom domain removed.');
        } elseif ($domain === $old_domain) {
            set_flashdata('Nothing changed.');
        } else {
            set_flashdata('Saved. Add the DNS records below, then press Verify.');
        }

        redirect('apps/' . $app->id . '/domain');
    }

    /**
     * POST apps/{id}/domain/verify: check the domain's TXT record, then (in
     * the worker) whether its DNS points here yet.
     */
    function verify(): void {
        $this->_make_sure_allowed();
        $app = $this->_member_app_or_redirect(segment(3, 'int'));
        $this->_require_post_with_csrf();

        $result = $this->model->verify_app_domain((int) $app->project_id, (int) $app->id);

        if ($result === true) {
            set_flashdata('Domain verified. It goes live by itself once its DNS points here; checking now.');
        } else {
            set_flashdata('Could not verify the domain: ' . htmlspecialchars((string) $result));
        }

        redirect('apps/' . $app->id . '/domain');
    }

    /**
     * How far the custom domain got, as plain values for the view: $domain,
     * $steps (verify, point, HTTPS), $summary and which button to show.
     *
     * @return array{domain: string, steps: list<array{label: string, state: string}>, summary: string, verify_label: string}
     */
    function _domain_progress(object $app): array {
        $domain = (string) ($app->domain ?? '');

        if ($domain === '') {
            return ['domain' => '', 'steps' => [], 'summary' => '', 'verify_label' => ''];
        }

        $verified = $domain === (string) ($app->verified_domain ?? '');
        $routed = $verified && Manifests::custom_domains((array) $app) !== [];
        $secured = $routed && Manifests::secured_domains((array) $app) !== [];

        $steps = [
            ['label' => 'Verify ownership', 'state' => $this->_step_state($verified, true)],
            ['label' => 'Point DNS here', 'state' => $this->_step_state($routed, $verified)],
            ['label' => 'HTTPS certificate', 'state' => $this->_step_state($secured, $routed)],
        ];

        $checked = '';

        if (!empty($app->domain_checked_at)) {
            $checked = ' Last checked ' . (string) $app->domain_checked_at . ' UTC.';
        }

        if (!$verified) {
            $summary = 'Add these records at your DNS host, then press Verify. The TXT record proves you own the domain; it is not served until then.';
            $verify_label = 'Verify';
        } elseif (!$routed) {
            $summary = 'Verified. It goes live by itself once its records below point here.' . $checked;
            $verify_label = 'Check again';
        } elseif (!$secured) {
            $summary = 'It points here. HTTPS switches on once the certificate is issued, usually within minutes. If that takes longer, check that no CAA record for the domain excludes Let\'s Encrypt.' . $checked;
            $verify_label = 'Check again';
        } else {
            $summary = 'Live with HTTPS. It is your app\'s base URL.' . $this->_www_hint($domain);
            $verify_label = '';
        }

        return ['domain' => $domain, 'steps' => $steps, 'summary' => $summary, 'verify_label' => $verify_label];
    }

    /**
     * What happens to "www.{domain}" (Manifests::www_alias), for a live domain
     */
    function _www_hint(string $domain): string {
        if (str_starts_with($domain, 'www.')) {
            return '';
        }

        return " www.{$domain} redirects here once it has the same A and AAAA records.";
    }

    /**
     * done, current (the step to work on now) or todo
     */
    function _step_state(bool $done, bool $previous_done): string {
        if ($done) {
            return 'done';
        }

        if ($previous_done) {
            return 'current';
        }

        return 'todo';
    }

    /**
     * The records to add, before any lookup (records() fills in their status)
     *
     * @return list<array{type: string, name: string, content: string, proxy: string, state: string, status: string}>
     */
    function _unchecked_records(object $app): array {
        if ((string) ($app->domain ?? '') === '') {
            return [];
        }

        $records = [];

        foreach ($this->_setup_records($app) as $record) {
            $records[] = $record + ['state' => 'checking', 'status' => 'Checking…'];
        }

        return $records;
    }

    /**
     * @return list<array{type: string, name: string, content: string, proxy: string}>
     */
    function _setup_records(object $app): array {
        $domain = (string) $app->domain;
        $verification = $this->model->domain_verification_record((int) $app->id, $domain);

        return Domain_dns::setup_records($domain, $verification);
    }
}
