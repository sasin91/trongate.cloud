<?php
/**
 * Asks once more before deleting an app, loaded into a Trongate MX modal
 * (mx-build-modal) by Apps::delete_confirm. The typed name is in the delete
 * page's form (#app-delete-form); the Delete button belongs to it through its
 * form attribute, so it posts to Apps::submit_delete.
 * Expects: $name.
 */
?>
<div class="app-delete-confirm">
    <p>Delete <?= htmlspecialchars($name) ?>? It goes offline within a few moments, and this cannot be undone here.</p>

    <div class="form-actions">
        <button type="submit" class="btn-danger" form="app-delete-form">Yes, delete <?= htmlspecialchars($name) ?></button>
        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
    </div>
</div>
