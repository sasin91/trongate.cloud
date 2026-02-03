<?php

/**
 * Hetzner Cloud Provider
 * https://docs.hetzner.cloud/
 */
class Hetzner {

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
            $this->_request('GET', '/locations?per_page=1');
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function create_server(array $config): array {
        $payload = [
            'name' => $config['name'],
            'server_type' => $config['type'],
            'location' => $config['region'],
            'image' => $config['image'] ?? 'ubuntu-24.04',
            'ssh_keys' => [$config['ssh_key_id']],
            'labels' => $config['labels'] ?? [],
        ];

        if (!empty($config['user_data'])) {
            $payload['user_data'] = $config['user_data'];
        }

        $response = $this->_request('POST', '/servers', $payload);

        return [
            'provider_id' => (string) $response['server']['id'],
            'ip' => $response['server']['public_net']['ipv4']['ip'] ?? '',
            'status' => $this->_map_status($response['server']['status']),
        ];
    }

    function get_server(string $provider_id): ?array {
        try {
            $response = $this->_request('GET', "/servers/{$provider_id}");
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
            $this->_request('DELETE', "/servers/{$provider_id}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function reboot_server(string $provider_id): bool {
        try {
            $this->_request('POST', "/servers/{$provider_id}/actions/reboot");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function upload_ssh_key(string $name, string $public_key): string {
        $response = $this->_request('POST', '/ssh_keys', [
            'name' => $name,
            'public_key' => $public_key,
        ]);

        return (string) $response['ssh_key']['id'];
    }

    function delete_ssh_key(string $key_id): bool {
        try {
            $this->_request('DELETE', "/ssh_keys/{$key_id}");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    function list_regions(): array {
        $response = $this->_request('GET', '/locations');
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

    function list_server_types(): array {
        $response = $this->_request('GET', '/server_types');
        $types = [];

        foreach ($response['server_types'] as $type) {
            // Filter to shared CPU types for cost efficiency
            if ($type['cpu_type'] !== 'shared') {
                continue;
            }

            $types[] = [
                'id' => $type['name'],
                'name' => $type['description'],
                'vcpus' => $type['cores'],
                'memory' => $type['memory'] * 1024, // GB to MB
                'disk' => $type['disk'],
                'price_monthly' => $this->_get_monthly_price($type['prices']),
            ];
        }

        return $types;
    }

    function list_images(): array {
        $response = $this->_request('GET', '/images?type=system');
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

    /**
     * Make API request to Hetzner
     */
    function _request(string $method, string $endpoint, array $data = []): array {
        $url = $this->api_base . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            throw new Exception("Hetzner API error: {$error}");
        }

        if ($http_code >= 400) {
            $body = json_decode($response, true);
            $message = $body['error']['message'] ?? "HTTP {$http_code}";
            throw new Exception("Hetzner API error ({$http_code}): {$message}");
        }

        return json_decode($response, true) ?? [];
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
    function _get_monthly_price(array $prices): float {
        foreach ($prices as $price) {
            if ($price['location'] === 'fsn1') { // Use Falkenstein as reference
                return (float) $price['price_monthly']['gross'];
            }
        }
        return 0.0;
    }
}
