<?php
/**
 * The DNS records to add for the custom domain, with what DNS says about each.
 * Rendered inside #domain-records by Domain::index (status "Checking…") and
 * again by Domain::records once the lookups are done.
 *
 * Expects: $records (list of {type, name, content, proxy, state, status});
 * state is checking, ok, warn or missing.
 */
?>
<div class="dns-table-wrap">
    <table class="dns-table">
        <thead>
            <tr>
                <th>Type</th>
                <th>Name</th>
                <th>Content</th>
                <th>Proxy status</th>
                <th>TTL</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($records as $record): ?>
            <tr>
                <td data-label="Type"><code><?= htmlspecialchars($record['type']) ?></code></td>
                <td data-label="Name">
                    <span class="dns-value">
                        <code><?= htmlspecialchars($record['name']) ?></code>
                        <button type="button" class="dns-copy" data-copy="<?= htmlspecialchars($record['name']) ?>">Copy</button>
                    </span>
                </td>
                <td data-label="Content">
                    <span class="dns-value">
                        <code><?= htmlspecialchars($record['content']) ?></code>
                        <button type="button" class="dns-copy" data-copy="<?= htmlspecialchars($record['content']) ?>">Copy</button>
                    </span>
                </td>
                <td data-label="Proxy status"><?= htmlspecialchars($record['proxy']) ?></td>
                <td data-label="TTL">Auto</td>
                <td data-label="Status"><span class="dns-status is-<?= $record['state'] ?>"><?= htmlspecialchars($record['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
