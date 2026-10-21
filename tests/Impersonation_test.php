<?php

/**
 * Tests for Impersonation_rules: which customers tg-admin may sign in as,
 * how they are named, and the audit lines for the platform's logs.
 *
 * Run: php tests/Impersonation_test.php (exits non-zero on a failure)
 */

require_once __DIR__ . '/bootstrap.php';

require_once dirname(__DIR__) . '/modules/impersonation/Impersonation_rules.php';

function customer_row(array $columns = []): object {
    return (object) ($columns + ['id' => 9, 'trongate_user_id' => 14, 'email' => 'dc@example.test', 'github_login' => 'trongate', 'active' => 1]);
}

test('an active customer with a sign-in may be impersonated', function () {
    assert_same(null, Impersonation_rules::refusal(customer_row()));
});

test('an inactive customer is refused', function () {
    assert_same('This customer is not active.', Impersonation_rules::refusal(customer_row(['active' => 0])));
});

test('a customer without a trongate user is refused', function () {
    assert_same('This customer has no sign-in.', Impersonation_rules::refusal(customer_row(['trongate_user_id' => 0])));
    assert_same('This customer has no sign-in.', Impersonation_rules::refusal(customer_row(['trongate_user_id' => null])));
});

test('a customer is named by GitHub login, then email, then id', function () {
    assert_same('trongate (customer #9)', Impersonation_rules::customer_label(customer_row()));
    assert_same('dc@example.test (customer #9)', Impersonation_rules::customer_label(customer_row(['github_login' => null])));
    assert_same('customer #9', Impersonation_rules::customer_label(customer_row(['github_login' => '', 'email' => null])));
});

test('the log lines say who, as whom, from where and for how long', function () {
    assert_same(
        'tg-admin: lotd signed in as trongate (customer #9) from 203.0.113.7, for 60 minutes',
        Impersonation_rules::started_line('lotd', 'trongate (customer #9)', '203.0.113.7')
    );
    assert_same(
        'tg-admin: lotd stopped signing in as trongate (customer #9) after 12 minutes',
        Impersonation_rules::stopped_line('lotd', 'trongate (customer #9)', 1000, 1000 + 12 * 60 + 30)
    );
});

finish_tests();
