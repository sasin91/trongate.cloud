<?php

/**
 * Hetzner Cloud Provider
 * https://docs.hetzner.cloud/
 */
class Hetzner extends Client {

    private string $api_base = 'https://api.hetzner.cloud/v1';

    function __construct(private string $token) {}

    // --- Static metadata ---

    static function get_id(): string {
        return 'hetzner';
    }

    static function get_label(): string {
        return 'Hetzner Cloud';
    }

    static function get_url(): string {
        return 'https://www.hetzner.com/cloud';
    }

    static function get_credential_fields(): array {
        return [
            ['name' => 'token', 'label' => 'API Token', 'type' => 'password'],
        ];
    }

    // --- Instance methods ---

    function validate_credentials(): bool {
        if (empty($this->token)) {
            return false;
        }
        try {
            $this->request('GET', '/locations?per_page=1');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function create_server(
        string $name,
        string $type,
        string $region,
        array $ssh_keys,
        array $labels,
        string $user_data,
        string $image = 'docker-ce',
    ): array {
        $response = $this->request('POST', '/servers', [
            'name' => $name,
            'server_type' => $type,
            'location' => $region,
            'image' => $image,
            'labels' => $labels,
            'ssh_keys' => $ssh_keys,
            'user_data' => $user_data,
            'start_after_create' => true,
            'automount' => false,
            'public_net' => [
                'enable_ipv4' => true,
                'enable_ipv6' => true,
            ]
        ]);

        return [
            'provider_id' => (string) $response['server']['id'],
            'ip' => $response['server']['public_net']['ipv4']['ip'] ?? '',
            'status' => $this->_map_status($response['server']['status']),
        ];
    }

    function get_server(string $provider_id): ?array {
        try {
            $response = $this->request('GET', "/servers/{$provider_id}");
            $server = $response['server'];

            return [
                'provider_id' => (string) $server['id'],
                'ip' => $server['public_net']['ipv4']['ip'] ?? '',
                'status' => $this->_map_status($server['status']),
                'type' => $server['server_type']['name'],
                'region' => $server['datacenter']['location']['name'],
            ];
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404') !== false) {
                return null;
            }
            throw $e;
        }
    }

    function delete_server(string $provider_id): bool {
        try {
            $this->request('DELETE', "/servers/{$provider_id}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function reboot_server(string $provider_id): bool {
        try {
            $this->request('POST', "/servers/{$provider_id}/actions/reboot");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function list_ssh_keys(): array {
        $response = $this->request('GET', '/ssh_keys');
        
        return $response['ssh_keys'];
    }

    function upload_ssh_key(string $name, string $public_key): string {
        $response = $this->request('POST', '/ssh_keys', [
            'name' => $name,
            'public_key' => $public_key,
        ]);

        return (string) $response['ssh_key']['id'];
    }

    function delete_ssh_key(string $key_id): bool {
        try {
            $this->request('DELETE', "/ssh_keys/{$key_id}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function list_regions(): array {
        $response = $this->request('GET', '/locations');
        $regions = [];

        foreach ($response['locations'] as $loc) {
            $regions[] = [
                'id' => $loc['name'],
                'name' => $loc['description'],
                'country' => $loc['country'],
            ];
        }

        return $regions;
    }

    function list_server_types(string $location = 'fsn1'): array {
        $response = $this->request('GET', '/server_types');
        $types = [];

        foreach ($response['server_types'] as $type) {
            if (isset($types[$type['id']])) {
                continue;
            }

            $price_monthly = $this->_get_monthly_price($type['prices'], $location);

            if ($price_monthly === false) {
                continue;
            }

            $data = [
                'id' => $type['name'],
                'name' => $type['description'],
                'vcpus' => $type['cores'],
                'memory' => $type['memory'] * 1024, // GB to MB
                'disk' => $type['disk'],
                'architecture' => $type['architecture'],
                'price_monthly' => $price_monthly,
            ];

            $types[$type['id']] = $data;
        }

        usort($types, fn($a, $b) => $a['price_monthly'] <=> $b['price_monthly']);

        return $types;
    }

    function list_images(): array {
        $response = $this->request('GET', '/images?type=system');
        $images = [];

        foreach ($response['images'] as $img) {
            if ($img['status'] !== 'available') {
                continue;
            }

            $images[] = [
                'id' => $img['name'],
                'name' => $img['description'],
                'type' => $img['os_flavor'],
            ];
        }

        return $images;
    }

    function headers(): array {
        return [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ];
    }

    /**
     * Make API request to Hetzner
     */
    function request(string $method, string $endpoint, array $data = []): array {
        $url = $this->api_base . $endpoint;

        return $this->_request(
            $method,
            $url,
            $data
        );
    }

    /**
     * Map Hetzner status to normalized status
     */
    function _map_status(string $status): string {
        $map = [
            'initializing' => 'provisioning',
            'starting' => 'provisioning',
            'running' => 'active',
            'stopping' => 'active',
            'off' => 'stopped',
            'deleting' => 'deleted',
            'migrating' => 'active',
            'rebuilding' => 'provisioning',
            'unknown' => 'error',
        ];

        return $map[$status] ?? 'unknown';
    }

    /**
     * Extract monthly price from Hetzner pricing array
     */
    function _get_monthly_price(array $prices, string $location): float | false {
        foreach ($prices as $price) {
            if ($price['location'] === $location) {
                return (float) $price['price_monthly']['gross'];
            }
        }

        return false;
    }
}
