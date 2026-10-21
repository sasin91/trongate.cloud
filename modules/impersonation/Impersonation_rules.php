<?php

/**
 * Who tg-admin may sign in as, for how long, and the audit lines written to
 * the platform's logs when an impersonation starts and stops.
 */
final class Impersonation_rules {

    /** How long a customer sign-in from tg-admin lasts */
    const MINUTES = 60;

    /**
     * Why the admin cannot sign in as this customer, or null when they can
     */
    static function refusal(object $customer): ?string {
        if (empty($customer->active)) {
            return 'This customer is not active.';
        }

        if ((int) ($customer->trongate_user_id ?? 0) === 0) {
            return 'This customer has no sign-in.';
        }

        return null;
    }

    /**
     * How the banner and the logs name a customer
     */
    static function customer_label(object $customer): string {
        $name = (string) ($customer->github_login ?? '');

        if ($name === '') {
            $name = (string) ($customer->email ?? '');
        }

        if ($name === '') {
            return "customer #{$customer->id}";
        }

        return "{$name} (customer #{$customer->id})";
    }

    static function started_line(string $admin, string $customer_label, string $ip): string {
        return "tg-admin: {$admin} signed in as {$customer_label} from {$ip}, for " . self::MINUTES . ' minutes';
    }

    static function stopped_line(string $admin, string $customer_label, int $started_at, int $now): string {
        $minutes = intdiv(max(0, $now - $started_at), 60);

        return "tg-admin: {$admin} stopped signing in as {$customer_label} after {$minutes} minutes";
    }
}
