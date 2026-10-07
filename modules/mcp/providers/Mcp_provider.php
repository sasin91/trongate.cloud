<?php

/**
 * An AI assistant that connects to the MCP endpoint (Claude today; OpenAI and
 * others later). The OAuth server and the tools are the same for all of them:
 * a provider only says which clients are its own, by their redirect URIs, and
 * where its setup page is. Each provider lives in its own child module of mcp
 * (modules/mcp/claude) and is listed in Mcp_providers::PROVIDERS.
 */
interface Mcp_provider {

    /** Stored on the client row (mcp_oauth_client.provider), e.g. 'claude' */
    function key(): string;

    /** Shown to customers, e.g. 'Claude' */
    function name(): string;

    /**
     * Whether a client that registers with this redirect URI is one of this
     * provider's (its OAuth callback, or a loopback URI for its local apps)
     */
    function accepts_redirect_uri(string $uri): bool;

    /** Control panel page explaining how to connect, e.g. 'integrations/claude' */
    function setup_path(): string;
}

/**
 * The providers clients may register as. A redirect URI no provider accepts
 * is refused, so dynamic client registration cannot be used to send a
 * customer's authorization code to an arbitrary site.
 */
final class Mcp_providers {

    /** key => [file under modules/, class] */
    const PROVIDERS = [
        'claude' => ['mcp/claude/Claude_provider.php', 'Claude_provider'],
    ];

    /**
     * @return array<string, Mcp_provider>
     */
    static function all(): array {
        static $providers = null;

        if ($providers === null) {
            $providers = [];

            foreach (self::PROVIDERS as $key => [$file, $class]) {
                require_once APPPATH . 'modules/' . $file;
                $providers[$key] = new $class();
            }
        }

        return $providers;
    }

    static function find(string $key): ?Mcp_provider {
        return self::all()[$key] ?? null;
    }

    /**
     * The one provider that accepts every URI, or null
     *
     * @param list<string> $uris
     */
    static function for_redirect_uris(array $uris): ?Mcp_provider {
        if ($uris === []) {
            return null;
        }

        foreach (self::all() as $provider) {
            if (array_filter($uris, fn(string $uri) => !$provider->accepts_redirect_uri($uri)) === []) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * http://localhost, 127.0.0.1 or [::1] on any port: a desktop or command line
     * app receiving the code on the customer's own machine (RFC 8252)
     */
    static function is_loopback(string $uri): bool {
        $parts = parse_url($uri);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }
}
