<?php
/**
 * The database password, answered by Database::show_password and swapped into
 * the Credentials dialog by MX. Expects: $password.
 */
?>
<code><?= htmlspecialchars($password) ?></code>
<button type="button" class="db-credentials-button db-copy" data-copy="<?= htmlspecialchars($password) ?>">Copy</button>
