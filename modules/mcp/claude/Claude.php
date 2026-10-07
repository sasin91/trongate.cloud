<?php

require_once APPPATH . 'modules/customer/traits/Customer_guard.php';
require_once __DIR__ . '/Claude_provider.php';

/**
 * Child module of mcp: Claude, the first assistant that can connect to the
 * MCP endpoint (Claude_provider). integrations/claude explains how to add
 * trongate.cloud to Claude and Claude Code, and lists the customer's Claude
 * connections, each of which they can disconnect.
 *
 * Another assistant (OpenAI, ...) gets a sibling child module with its own
 * Mcp_provider and page; the OAuth server and the tools are shared.
 *
 * @property-read Claude_model $model
 * @property-read Trongate_tokens $trongate_tokens
 * @property-read Templates $templates
 * @property-read Validation $validation
 */
class Claude extends Trongate {
    use Customer_guard;

    /**
     * integrations/claude
     */
    function index(): void {
        $this->_make_sure_allowed();
        $customer = $this->_customer_or_redirect();
        $provider = new Claude_provider();

        $data = [
            'view_module' => 'mcp/claude',
            'view_file' => 'index',
            'server_url' => BASE_URL . 'mcp',
            'connections' => $this->model->customer_grants((int) $customer->id, $provider->key()),
            'crumbs' => [[$provider->setup_path(), $provider->name()]],
            'additional_includes_top' => ['apps_module/css/apps.css', 'mcp-claude_module/css/claude.css'],
        ];
        $this->module('templates');
        $this->templates->customer($data);
    }

    /**
     * POST integrations/claude/{grant id}/disconnect: its tokens stop working at once
     */
    function disconnect(): void {
        $this->_make_sure_allowed();
        $this->_require_post_with_csrf();
        $customer = $this->_customer_or_redirect();

        set_flashdata($this->model->revoke_grant((int) $customer->id, segment(3, 'int'))
            ? 'Disconnected. Claude can no longer use trongate.cloud from there until you connect it again.'
            : 'That connection was already gone.');

        redirect('integrations/claude');
    }
}
