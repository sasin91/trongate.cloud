<?php

require_once __DIR__ . '/../../../config/platform.php';

/**
 * Whether a custom domain's DNS reaches the cluster: the gate in front of
 * routing it (Manifests::custom_domains), and the per-record checks on the
 * app's Domain tab.
 *
 * A domain points here when it is a CNAME into PLATFORM_APPS_DOMAIN (the apex
 * or a {slug} host), or when it has A/AAAA records and every one of them is a
 * load balancer address (PLATFORM_INGRESS_IPV4/IPV6). Every one, because Let's
 * Encrypt may validate over any of them (preferring IPv6), so a stale AAAA at
 * the old host fails HTTP-01 just like no record at all.
 *
 * Records proxied through Cloudflare (orange cloud) resolve to Cloudflare's
 * addresses, never ours, so they never pass; the detail says so, because the
 * fix is a toggle in Cloudflare (Proxy status: DNS only) rather than a new record.
 *
 * Framework-free: used by the recheck_domains task, by domain verification and
 * by the Domain tab. Override records() in a subclass to test without DNS.
 */
class Domain_dns {

    /**
     * Cloudflare's published proxy ranges (https://www.cloudflare.com/ips/)
     */
    const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * @return array{points: bool, proxied: bool, detail: string} detail says why, for task logs and the UI
     */
    static function check(string $domain): array {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $apps_domain = strtolower(PLATFORM_APPS_DOMAIN);

        $target = static::platform_cname($domain);

        if ($target !== null) {
            return ['points' => true, 'proxied' => false, 'detail' => "CNAME to {$target}"];
        }

        $addresses = array_merge(static::addresses($domain, DNS_A), static::addresses($domain, DNS_AAAA));

        if (!$addresses) {
            return ['points' => false, 'proxied' => false, 'detail' => "{$domain} has no A, AAAA or CNAME record pointing at {$apps_domain}"];
        }

        $foreign = array_values(array_diff(array_unique($addresses), self::ingress_addresses()));

        if ($foreign && self::all_cloudflare($foreign)) {
            return ['points' => false, 'proxied' => true, 'detail' => "{$domain} is proxied by Cloudflare; set its Proxy status to DNS only"];
        }

        if ($foreign) {
            return ['points' => false, 'proxied' => false, 'detail' => "{$domain} resolves to " . implode(', ', $foreign) . ', not to the platform'];
        }

        return ['points' => true, 'proxied' => false, 'detail' => "{$domain} resolves to " . implode(', ', array_unique($addresses))];
    }

    static function points_at_cluster(string $domain): bool {
        return static::check($domain)['points'];
    }

    /**
     * The DNS records a customer adds for a custom domain, as the Domain tab
     * lists them: the TXT record that proves ownership, then an A/AAAA record
     * per load balancer address (DNS only, the way trongate.cloud itself is set up).
     * Names are full hostnames, which every DNS host (Cloudflare included) accepts.
     *
     * @param array{name: string, value: string} $verification Platform_actions::domain_verification_record
     * @return list<array{type: string, name: string, content: string, proxy: string}>
     */
    static function setup_records(string $domain, array $verification): array {
        $records = [
            ['type' => 'TXT', 'name' => $verification['name'], 'content' => $verification['value'], 'proxy' => ''],
        ];

        foreach (self::ingress_addresses() as $address) {
            $type = str_contains($address, ':') ? 'AAAA' : 'A';
            $records[] = ['type' => $type, 'name' => $domain, 'content' => $address, 'proxy' => 'DNS only'];
        }

        return $records;
    }

    /**
     * setup_records() with what DNS says about each one right now: state is
     * ok, warn (something there needs changing) or missing, and status says
     * what, in a few words. A verified domain's TXT record is not looked up:
     * it has done its job.
     *
     * @param list<array{type: string, name: string, content: string, proxy: string}> $records
     * @return list<array{type: string, name: string, content: string, proxy: string, state: string, status: string}>
     */
    static function checked_records(array $records, bool $verified): array {
        $checked = [];

        foreach ($records as $record) {
            if ($record['type'] === 'TXT') {
                $checked[] = $record + static::txt_status($record, $verified);
            } else {
                $checked[] = $record + static::address_status($record);
            }
        }

        return $checked;
    }

    /**
     * @param array{type: string, name: string, content: string, proxy: string} $record
     * @return array{state: string, status: string}
     */
    static function txt_status(array $record, bool $verified): array {
        if ($verified) {
            return ['state' => 'ok', 'status' => 'Verified'];
        }

        $values = static::txt_values($record['name']);

        if (in_array($record['content'], $values, true)) {
            return ['state' => 'ok', 'status' => 'Found. Press Verify'];
        }

        if ($values === []) {
            return ['state' => 'missing', 'status' => 'Not found yet'];
        }

        return ['state' => 'warn', 'status' => 'Found a different value'];
    }

    /**
     * @param array{type: string, name: string, content: string, proxy: string} $record
     * @return array{state: string, status: string}
     */
    static function address_status(array $record): array {
        $cname = static::platform_cname($record['name']);

        if ($cname !== null) {
            return ['state' => 'ok', 'status' => "Covered by your CNAME to {$cname}"];
        }

        $type = $record['type'] === 'AAAA' ? DNS_AAAA : DNS_A;
        $addresses = static::addresses($record['name'], $type);

        if ($addresses === []) {
            return ['state' => 'missing', 'status' => 'Not found yet'];
        }

        if (self::all_cloudflare($addresses)) {
            return ['state' => 'warn', 'status' => 'Proxied by Cloudflare. Set Proxy status to DNS only'];
        }

        $others = array_values(array_diff($addresses, self::ingress_addresses()));

        if ($others === [] && !in_array($record['content'], $addresses, true)) {
            return ['state' => 'missing', 'status' => 'Not found yet'];
        }

        if (!in_array($record['content'], $addresses, true)) {
            return ['state' => 'warn', 'status' => 'Points at ' . implode(', ', $others) . '. Change it to this address'];
        }

        if ($others !== []) {
            return ['state' => 'warn', 'status' => 'Also points at ' . implode(', ', $others) . '. Delete that record'];
        }

        return ['state' => 'ok', 'status' => 'Found'];
    }

    /**
     * The domain's CNAME target when it is PLATFORM_APPS_DOMAIN or a host under it; null otherwise
     */
    static function platform_cname(string $domain): ?string {
        $apps_domain = strtolower(PLATFORM_APPS_DOMAIN);

        foreach (static::records($domain, DNS_CNAME) as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));

            if ($target === $apps_domain || str_ends_with($target, '.' . $apps_domain)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * The domain's A (DNS_A) or AAAA (DNS_AAAA) addresses, in canonical form
     *
     * @return list<string>
     */
    static function addresses(string $domain, int $type): array {
        $field = $type === DNS_AAAA ? 'ipv6' : 'ip';
        $addresses = [];

        foreach (static::records($domain, $type) as $record) {
            $packed = isset($record[$field]) ? @inet_pton((string) $record[$field]) : false;

            if ($packed !== false) {
                $addresses[] = (string) inet_ntop($packed);
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The TXT values published at a name
     *
     * @return list<string>
     */
    static function txt_values(string $name): array {
        $values = [];

        foreach (static::records($name, DNS_TXT) as $record) {
            $values[] = trim((string) ($record['txt'] ?? ''));
        }

        return $values;
    }

    /**
     * The load balancer addresses a custom domain's A/AAAA records must point at,
     * in canonical form. Empty (local dev): only the CNAME check can pass.
     *
     * @return list<string>
     */
    static function ingress_addresses(): array {
        $addresses = [];

        foreach (explode(',', PLATFORM_INGRESS_IPV4 . ',' . PLATFORM_INGRESS_IPV6) as $address) {
            $packed = @inet_pton(trim($address));

            if ($packed !== false) {
                $addresses[] = (string) inet_ntop($packed);
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Whether every address is one of Cloudflare's proxy addresses
     *
     * @param list<string> $addresses
     */
    static function all_cloudflare(array $addresses): bool {
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!self::is_cloudflare($address)) {
                return false;
            }
        }

        return true;
    }

    static function is_cloudflare(string $address): bool {
        foreach (self::CLOUDFLARE_RANGES as $range) {
            if (self::in_range($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an IPv4 or IPv6 address lies in a CIDR range
     */
    static function in_range(string $address, string $range): bool {
        [$network, $bits] = explode('/', $range);
        $address_bytes = @inet_pton($address);
        $network_bytes = @inet_pton($network);

        if ($address_bytes === false || $network_bytes === false) {
            return false;
        }

        if (strlen($address_bytes) !== strlen($network_bytes)) {
            return false;
        }

        $bits = (int) $bits;
        $whole_bytes = intdiv($bits, 8);

        if (substr($address_bytes, 0, $whole_bytes) !== substr($network_bytes, 0, $whole_bytes)) {
            return false;
        }

        $left_over = $bits % 8;

        if ($left_over === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $left_over)) & 0xFF;

        return (ord($address_bytes[$whole_bytes]) & $mask) === (ord($network_bytes[$whole_bytes]) & $mask);
    }

    /**
     * DNS records of one type; empty when there are none or the lookup failed
     * (both count as "not pointing", which the recheck task's hysteresis absorbs)
     */
    static function records(string $domain, int $type): array {
        return @dns_get_record($domain, $type) ?: [];
    }
}
