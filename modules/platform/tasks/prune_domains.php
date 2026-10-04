<?php

/**
 * Prune Domains Task
 *
 * Takes a project's custom domain off the cluster once it no longer routes it:
 * deletes its custom-domain Gateways (and certificates) for every domain
 * Manifests::custom_domains no longer lists.
 *
 * Payload: {team_id, project_id}
 *
 * Queued by Project_records::update_project_settings when a verified domain is
 * changed or removed. Clearing verified_domain frees the UNIQUE claim at once,
 * so another project, of any team, may verify and route the domain next.
 * Waiting for this project's next deploy (Platform_task::apply prunes too)
 * would leave it a live HTTPS listener, and a certificate cert-manager keeps
 * renewing, for a domain it gave up, indefinitely if it never deploys again.
 * The new owner's deploy also deletes any Gateway left for the domain
 * (Platform_task::release_domains), in case this task failed or is late.
 *
 * The app's HTTPRoute keeps listing the old hostname until the next deploy.
 * That is harmless: its parentRef names the deleted Gateway, and the platform
 * Gateway's listeners only match {slug} and platform hosts.
 */

require_once __DIR__ . '/lib/Platform_task.php';

return function (Task_log $log) use ($payload, $runtime): int {
    $team_id = (int) ($payload['team_id'] ?? 0);
    $project_id = (int) ($payload['project_id'] ?? 0);

    if ($team_id <= 0 || $project_id <= 0) {
        throw new InvalidArgumentException('prune_domains needs team_id and project_id');
    }

    // The current row: a domain verified again meanwhile is kept
    $project = Platform_task::project($runtime->db(), $team_id, $project_id);

    return Platform_task::prune_domain_gateways($runtime, $log, $project) ? 0 : 1;
};
