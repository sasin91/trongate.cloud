<?php

require_once __DIR__ . '/../../../config/platform.php';

/**
 * Whether a custom domain's DNS reaches the cluster: the gate in front of
 * routing it (Manifests::custom_domains).
 *
 * A domain points here when it is a CNAME into PLATFORM_APPS_DOMAIN (the apex
 * or a {slug} host), or when it has A/AAAA records and every one of them is a
 * load balancer address (PLATFORM_INGRESS_IPV4/IPV6). Every one, because Let's
 * Encrypt may validate over any of them (preferring IPv6), so a stale AAAA at
 * the old host fails HTTP-01 just like no record at all.
 *
 * Framework-free: used by the recheck_domains task and by domain verification.
 * Override records() in a subclass to test without DNS.
 */
class Domain_dns {

    /**
     * @return array{points: bool, detail: string} detail says why, for task logs and the UI
     */
    static function check(string $domain): array {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $apps_domain = strtolower(PLATFORM_APPS_DOMAIN);

        foreach (static::records($domain, DNS_CNAME) as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));

            if ($target === $apps_domain || str_ends_with($target, '.' . $apps_domain)) {
                return ['points' => true, 'detail' => "CNAME to {$target}"];
            }
        }

        $addresses = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            foreach (static::records($domain, $type) as $record) {
                $packed = isset($record[$field]) ? @inet_pton((string) $record[$field]) : false;

                if ($packed !== false) {
                    $addresses[] = (string) inet_ntop($packed);
                }
            }
        }

        if (!$addresses) {
            return ['points' => false, 'detail' => "{$domain} has no A, AAAA or CNAME record pointing at {$apps_domain}"];
        }

        $foreign = array_values(array_diff(array_unique($addresses), self::ingress_addresses()));

        if ($foreign) {
            return ['points' => false, 'detail' => "{$domain} resolves to " . implode(', ', $foreign) . ', not to the platform'];
        }

        return ['points' => true, 'detail' => "{$domain} resolves to " . implode(', ', array_unique($addresses))];
    }

    static function points_at_cluster(string $domain): bool {
        return static::check($domain)['points'];
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
     * DNS records of one type; empty when there are none or the lookup failed
     * (both count as "not pointing", which the recheck task's hysteresis absorbs)
     */
    static function records(string $domain, int $type): array {
        return @dns_get_record($domain, $type) ?: [];
    }
}
