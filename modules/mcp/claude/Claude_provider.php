<?php

require_once APPPATH . 'modules/mcp/providers/Mcp_provider.php';

/**
 * Claude as an MCP client: claude.ai and the Claude apps (custom connectors,
 * which call back to claude.ai or claude.com), and Claude Code, which receives
 * the code on a loopback port.
 */
final class Claude_provider implements Mcp_provider {

    /** Claude's OAuth callbacks for custom connectors */
    const CALLBACKS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    function key(): string {
        return 'claude';
    }

    function name(): string {
        return 'Claude';
    }

    function accepts_redirect_uri(string $uri): bool {
        return in_array($uri, self::CALLBACKS, true) || Mcp_providers::is_loopback($uri);
    }

    function setup_path(): string {
        return 'integrations/claude';
    }
}
