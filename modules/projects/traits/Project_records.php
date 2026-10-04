<?php

/**
 * Project writes shared by onboarding and the projects module.
 * Requires the Platform_actions trait in the same model (slug + webhook secret helpers).
 *
 * @property-read Db $db
 */
trait Project_records {

    /**
     * Create a project with a unique slug and a fresh webhook secret.
     */
    function create_project(int $team_id, string $label, string $type, string $source_url, string $source_provider, string $source_branch = 'main', ?string $domain = null, bool $auto_deploy = true): int|false {
        return $this->db->insert([
            'team_id' => $team_id,
            'label' => $label,
            'slug' => $this->generate_project_slug($label),
            'type' => $type,
            'source_url' => $source_url,
            'source_provider' => $source_provider,
            'source_branch' => $source_branch ?: 'main',
            'domain' => $domain,
            'auto_deploy' => $auto_deploy ? 1 : 0,
            'persistent_paths' => '[]',
            'webhook_secret' => $this->generate_webhook_secret(),
        ], 'project');
    }

    /**
     * Update a team project's platform settings.
     *
     * @param array{domain: ?string, persistent_paths: list<string>, php_extensions: string, always_on: bool, resource_tier: string, auto_deploy: bool} $settings
     */
    function update_project_settings(int $team_id, int $project_id, array $settings): void {
        $old = $this->find_team_project($team_id, $project_id);
        $old_domain = $old !== false ? (string) ($old->verified_domain ?? '') : '';

        $sql = <<<SQL
            UPDATE project
            SET domain = :domain,
                -- Before verified_domain: MariaDB assigns left to right, so these
                -- still compare against the old value. A new domain starts ungated
                -- (db/019) and is checked afresh once verified.
                domain_pointed_at = IF(verified_domain <=> :same_domain_1, domain_pointed_at, NULL),
                domain_dns_failures = IF(verified_domain <=> :same_domain_2, domain_dns_failures, 0),
                domain_checked_at = IF(verified_domain <=> :same_domain_3, domain_checked_at, NULL),
                domain_secured_at = IF(verified_domain <=> :same_domain_4, domain_secured_at, NULL),
                verified_domain = IF(verified_domain <=> :same_domain, verified_domain, NULL),
                persistent_paths = :persistent_paths,
                php_extensions = :php_extensions,
                always_on = :always_on,
                resource_tier = :resource_tier,
                auto_deploy = :auto_deploy
            WHERE id = :project_id AND team_id = :team_id
        SQL;
        $this->db->query_bind($sql, [
            'domain' => $settings['domain'],
            'same_domain' => $settings['domain'],
            'same_domain_1' => $settings['domain'],
            'same_domain_2' => $settings['domain'],
            'same_domain_3' => $settings['domain'],
            'same_domain_4' => $settings['domain'],
            'persistent_paths' => json_encode($settings['persistent_paths']),
            'php_extensions' => $settings['php_extensions'],
            'always_on' => $settings['always_on'] ? 1 : 0,
            'resource_tier' => $settings['resource_tier'],
            'auto_deploy' => $settings['auto_deploy'] ? 1 : 0,
            'project_id' => $project_id,
            'team_id' => $team_id,
        ]);

        // Giving up a verified domain frees it for any other project at once, so
        // its Gateway and certificate leave the cluster now, not on this
        // project's next deploy (tasks/prune_domains.php)
        if ($old_domain !== '' && $old_domain !== (string) $settings['domain']) {
            $this->_queue_task($team_id, $project_id, null, 'prune_domains', [
                'team_id' => $team_id,
                'project_id' => $project_id,
            ]);
        }
    }

    /**
     * Whether another project already serves (has verified) this custom domain.
     * Unverified requests never block anyone: the DNS owner wins.
     */
    function domain_taken(string $domain, ?int $ignore_project_id = null): bool {
        $sql = <<<SQL
            SELECT COUNT(*)
            FROM project
            WHERE verified_domain = :domain AND id <> :ignore_id
        SQL;
        $count = $this->db->query_bind($sql, ['domain' => $domain, 'ignore_id' => $ignore_project_id ?? 0], 'column');

        return (int) $count > 0;
    }

    /**
     * The resource tier of every live project in a team, for the tenant quota
     * check (Manifests::quota_shortfall). Deleted projects keep their row but
     * no pods, so they do not count.
     *
     * @return list<string>
     */
    function team_resource_tiers(int $team_id, ?int $except_project_id = null): array {
        $sql = <<<SQL
            SELECT resource_tier
            FROM project
            WHERE team_id = :team_id AND id <> :except_id
              AND deleted_at IS NULL
        SQL;
        $rows = $this->db->query_bind($sql, ['team_id' => $team_id, 'except_id' => $except_project_id ?? 0], 'array');

        return array_map(fn(array $row) => (string) $row['resource_tier'], $rows);
    }
}
