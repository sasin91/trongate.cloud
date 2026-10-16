<?php

/**
 * Tests for Domain_dns: the DNS gate for custom domains (check), the records
 * the Domain tab tells customers to add (setup_records) and what DNS says
 * about each one (checked_records), including Cloudflare's proxy.
 *
 * Run: php tests/Domain_dns_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

define('PLATFORM_APPS_DOMAIN', 'trongate.dev');
define('PLATFORM_INGRESS_IPV4', '142.132.241.236');
define('PLATFORM_INGRESS_IPV6', '2a01:4f8:c01e:2396::1');

require_once dirname(__DIR__) . '/modules/platform/manifests/Domain_dns.php';

/** DNS answered from Fake_dns::$zone: [name][type] => records */
class Fake_dns extends Domain_dns {

    /** @var array<string, array<int, list<array>>> */
    static array $zone = [];

    static function records(string $domain, int $type): array {
        return self::$zone[$domain][$type] ?? [];
    }
}

$a = fn(string ...$ips) => array_map(fn($ip) => ['ip' => $ip], $ips);
$aaaa = fn(string ...$ips) => array_map(fn($ip) => ['ipv6' => $ip], $ips);
$verification = ['name' => '_trongate-cloud.app.example.com', 'value' => 'trongate-cloud-verify=abc'];

$statuses = function (bool $verified) use ($verification): array {
    $records = Fake_dns::checked_records(Fake_dns::setup_records('app.example.com', $verification), $verified);

    return array_map(fn($record) => $record['type'] . ' ' . $record['state'] . ': ' . $record['status'], $records);
};

test('setup_records: the TXT record, then DNS-only A and AAAA records at the load balancer', function () use ($verification) {
    assert_same([
        ['type' => 'TXT', 'name' => '_trongate-cloud.app.example.com', 'content' => 'trongate-cloud-verify=abc', 'proxy' => ''],
        ['type' => 'A', 'name' => 'app.example.com', 'content' => '142.132.241.236', 'proxy' => 'DNS only'],
        ['type' => 'AAAA', 'name' => 'app.example.com', 'content' => '2a01:4f8:c01e:2396::1', 'proxy' => 'DNS only'],
    ], Domain_dns::setup_records('app.example.com', $verification));
});

test('nothing added yet: every record is missing', function () use ($statuses) {
    Fake_dns::$zone = [];

    assert_same(['TXT missing: Not found yet', 'A missing: Not found yet', 'AAAA missing: Not found yet'], $statuses(false));
});

test('everything in place, before and after verifying', function () use ($statuses, $a, $aaaa) {
    Fake_dns::$zone = [
        '_trongate-cloud.app.example.com' => [DNS_TXT => [['txt' => 'trongate-cloud-verify=abc']]],
        'app.example.com' => [DNS_A => $a('142.132.241.236'), DNS_AAAA => $aaaa('2a01:04f8:c01e:2396:0:0:0:1')],
    ];

    assert_same(['TXT ok: Found. Press Verify', 'A ok: Found', 'AAAA ok: Found'], $statuses(false));
    assert_same(['TXT ok: Verified', 'A ok: Found', 'AAAA ok: Found'], $statuses(true));
    assert_same(true, Fake_dns::check('app.example.com')['points']);
});

test('a wrong TXT value and a stale AAAA at the old host', function () use ($statuses, $a, $aaaa) {
    Fake_dns::$zone = [
        '_trongate-cloud.app.example.com' => [DNS_TXT => [['txt' => 'something-else']]],
        'app.example.com' => [DNS_A => $a('142.132.241.236', '203.0.113.9'), DNS_AAAA => $aaaa('2001:db8::1')],
    ];

    assert_same([
        'TXT warn: Found a different value',
        'A warn: Also points at 203.0.113.9. Delete that record',
        'AAAA warn: Points at 2001:db8::1. Change it to this address',
    ], $statuses(false));
    assert_same(false, Fake_dns::check('app.example.com')['points']);
});

test('Cloudflare proxy (orange cloud) is named, in the records and in the gate', function () use ($statuses, $a, $aaaa) {
    Fake_dns::$zone = [
        'app.example.com' => [DNS_A => $a('104.21.48.7', '172.67.140.2'), DNS_AAAA => $aaaa('2606:4700:3030::6815:3007')],
    ];

    assert_same([
        'TXT ok: Verified',
        'A warn: Proxied by Cloudflare. Set Proxy status to DNS only',
        'AAAA warn: Proxied by Cloudflare. Set Proxy status to DNS only',
    ], $statuses(true));

    $check = Fake_dns::check('app.example.com');
    assert_same(false, $check['points']);
    assert_same(true, $check['proxied']);
});

test('a CNAME into the apps domain covers the A and AAAA records', function () use ($statuses, $a) {
    Fake_dns::$zone = [
        'app.example.com' => [DNS_CNAME => [['target' => 'my-app.trongate.dev']], DNS_A => $a('142.132.241.236')],
    ];

    assert_same([
        'TXT ok: Verified',
        'A ok: Covered by your CNAME to my-app.trongate.dev',
        'AAAA ok: Covered by your CNAME to my-app.trongate.dev',
    ], $statuses(true));
    assert_same(['points' => true, 'proxied' => false, 'detail' => 'CNAME to my-app.trongate.dev'], Fake_dns::check('app.example.com'));
});

test('in_range handles IPv4 and IPv6 prefixes that end mid-byte', function () {
    assert_same(true, Domain_dns::in_range('104.31.255.255', '104.16.0.0/12'));
    assert_same(false, Domain_dns::in_range('104.32.0.0', '104.16.0.0/12'));
    assert_same(true, Domain_dns::in_range('2a06:98c7::1', '2a06:98c0::/29'));
    assert_same(false, Domain_dns::in_range('2a06:98c8::1', '2a06:98c0::/29'));
    assert_same(false, Domain_dns::in_range('104.16.0.1', '2606:4700::/32'));
    assert_same(false, Domain_dns::is_cloudflare('142.132.241.236'));
});

finish_tests();
