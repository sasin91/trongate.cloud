<?php
/**
 * The wizard's first administrator, inside the Administrators set of
 * Trongate's default tables (views/_sql_file_options.php): a button that
 * opens views/_administrator_form.php in a Trongate MX modal, or the account
 * it made, posted with the wizard as hidden fields. Setup_sql::administrator
 * answers with this too, swapped into #wz-administrator.
 * Expects: $administrator (null, or username, email, hash, hashed_with).
 */
$modal = '{"id":"wz-administrator-modal","modalHeading":"First administrator","width":"32rem","marginTop":"8vh"}';
?>
<?php if ($administrator === null): ?>
    <button type="button" class="wz-link" mx-get="setup-sql/administrator" mx-build-modal="<?= htmlspecialchars($modal) ?>">Add the first administrator&hellip;</button>
<?php else: ?>
    <input type="hidden" name="administrator_username" value="<?= htmlspecialchars($administrator['username']) ?>">
    <input type="hidden" name="administrator_email" value="<?= htmlspecialchars($administrator['email']) ?>">
    <input type="hidden" name="administrator_hash" value="<?= htmlspecialchars($administrator['hash']) ?>">
    <p class="wz-hint">
        First administrator <code><?= htmlspecialchars($administrator['username']) ?></code>, password hashed with <?= htmlspecialchars($administrator['hashed_with']) ?>.
        <button type="button" class="wz-link" mx-get="setup-sql/administrator" mx-build-modal="<?= htmlspecialchars($modal) ?>">Change&hellip;</button>
    </p>
<?php endif; ?>
