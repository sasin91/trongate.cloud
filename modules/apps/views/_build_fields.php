<?php
/**
 * The Build question's fields: build commands and extra PHP extensions,
 * prefilled from the repository (App_form::build_suggestion_fields).
 * Expects: $build (App_form::_build_fields).
 */
?>
<?php if ($build['note'] !== ''): ?>
    <p class="wz-hint"><?= htmlspecialchars($build['note']) ?></p>
<?php endif; ?>
<label class="wz-field">
    <span class="wz-label">Build commands</span>
    <textarea name="build_commands" class="wz-in mono" rows="3" spellcheck="false" placeholder="npm ci&#10;npm run build"><?= htmlspecialchars($build['commands']) ?></textarea>
    <span class="wz-hint">One per line, run on every deploy after <code>composer install</code>. <code>npm</code>, <code>pnpm</code>, <code>yarn</code> and <code>bun</code> are there when a command uses them.</span>
</label>
<label class="wz-field">
    <span class="wz-label">Extra PHP extensions</span>
    <input type="text" name="php_extensions" class="wz-in mono" placeholder="imagick, sqlsrv" value="<?= htmlspecialchars($build['php_extensions']) ?>">
    <span class="wz-hint">The ones your <code>composer.json</code> requires are filled in.</span>
</label>
