<?php
/**
 * New-app questions, one at a time: repository, branch, name, Trongate version.
 * Shared by onboarding/app and projects/{id}/apps/new.
 *
 * Expects: $provider, $repositories, $app_types, $branches_url (returns <option>s),
 * $form_action, $q_offset (questions answered before this page), $q_total,
 * $back_href (optional link for the first question's Back).
 */
$e = fn($value) => htmlspecialchars((string) $value);
$selected_repository = (string) post('repository');
$selected_type = post('type') ?: 'trongate_v2';
$auto_deploy = post('auto_deploy') === '' ? true : post('auto_deploy') === '1';
$domain = (string) post('domain');
$keys = range('A', 'Z');
$type_descriptions = ['trongate_v2' => 'The current release', 'trongate_v1' => 'Older apps'];
$errors = validation_errors('<p>', '</p>');
?>
<div class="wz-wrap">
    <main class="wz-main">
        <?= form_open($form_action, ['class' => 'wz-stage', 'data-steps' => '', 'data-offset' => (string) (int) $q_offset, 'data-total' => (string) (int) $q_total, 'data-branches' => $branches_url, 'data-provider' => $provider, 'data-apps-domain' => PLATFORM_APPS_DOMAIN]) ?>
            <?= form_hidden('provider', $provider) ?>

            <button type="button" class="wz-back" data-back hidden>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                <span>Back</span>
            </button>

            <?php if ($errors): ?><div class="wz-errors" role="alert"><?= $errors ?></div><?php endif; ?>

            <fieldset class="wz-step" data-step="repository" data-label="Repository">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 1 ?> &rarr;</span>
                    <legend>Which app are we putting online?</legend>
                </div>
                <?php if (count($repositories) > 6): ?>
                    <label class="wz-field">
                        <span class="wz-sr">Filter repositories</span>
                        <input type="search" class="wz-in" placeholder="Filter repositories" data-filter>
                    </label>
                <?php endif; ?>
                <div class="wz-choices" data-repositories>
                    <?php if (empty($repositories)): ?>
                        <p class="wz-hint">We could not find any repositories for this GitHub token.</p>
                    <?php endif; ?>
                    <?php foreach (array_values($repositories) as $i => $repo): ?>
                        <label class="wz-choice" data-name="<?= $e(strtolower($repo['full_name'])) ?>">
                            <input type="radio" name="repository" value="<?= $e($repo['full_name']) ?>" required <?= $selected_repository === $repo['full_name'] ? 'checked' : '' ?>>
                            <span class="wz-key"><?= $keys[$i] ?? '' ?></span>
                            <span class="wz-choice-text">
                                <strong><?= $e($repo['full_name']) ?></strong>
                                <small><?= !empty($repo['private']) ? 'Private' : 'Public' ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="wz-step" data-step="branch" data-label="Branch">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 2 ?> &rarr;</span>
                    <legend>Which branch goes live?</legend>
                </div>
                <label class="wz-field">
                    <span class="wz-sr">Branch</span>
                    <select name="repository_branch" id="repository_branch" class="wz-select" required>
                        <?php if (post('repository_branch') !== ''): ?>
                            <option value="<?= $e(post('repository_branch')) ?>" selected><?= $e(post('repository_branch')) ?></option>
                        <?php else: ?>
                            <option value="">Pick a repository first</option>
                        <?php endif; ?>
                    </select>
                </label>
                <label class="wz-toggle-row">
                    <span>
                        <strong>Deploy every push</strong>
                        <small>Turn off to deploy only when you press the button.</small>
                    </span>
                    <input type="hidden" name="auto_deploy" value="0">
                    <input type="checkbox" name="auto_deploy" value="1" class="wz-switch" <?= $auto_deploy ? 'checked' : '' ?>>
                </label>
            </fieldset>

            <fieldset class="wz-step" data-step="name" data-label="Name">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 3 ?> &rarr;</span>
                    <legend>Name it. That's your address.</legend>
                </div>
                <label class="wz-field">
                    <span class="wz-sr">App name</span>
                    <input type="text" name="label" id="label" class="wz-big" placeholder="Storefront" value="<?= $e(post('label')) ?>" required minlength="3" maxlength="50">
                </label>
                <p class="wz-address"><span>https://<b data-slug>your-app</b>.<?= $e(PLATFORM_APPS_DOMAIN) ?></span></p>
                <button type="button" class="wz-reveal" data-reveal="domain-field" <?= $domain !== '' ? 'hidden' : '' ?>>+ Add your own domain (optional)</button>
                <label class="wz-field" id="domain-field" <?= $domain === '' ? 'hidden' : '' ?>>
                    <span class="wz-label">Your own domain</span>
                    <input type="text" name="domain" class="wz-in" placeholder="shop.example.com" value="<?= $e($domain) ?>">
                    <span class="wz-hint">After launch we show you the DNS records to add. It switches on with HTTPS once they resolve.</span>
                </label>
            </fieldset>

            <fieldset class="wz-step" data-step="type" data-label="Trongate version">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 4 ?> &rarr;</span>
                    <legend>Which Trongate is it built on?</legend>
                </div>
                <div class="wz-choices row">
                    <?php foreach ($app_types as $value => $type_label): ?>
                        <label class="wz-choice">
                            <input type="radio" name="type" value="<?= $e($value) ?>" required <?= $value === $selected_type ? 'checked' : '' ?>>
                            <span class="wz-big-num"><?= $e(preg_replace('/^Trongate\s+/i', '', $type_label)) ?></span>
                            <span class="wz-choice-text">
                                <strong><?= $e($type_label) ?></strong>
                                <small><?= $e($type_descriptions[$value] ?? '') ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="wz-nav">
                <button type="submit" class="wz-ok" data-next>Launch</button>
                <span class="wz-enter">press <strong>Enter &#8629;</strong></span>
                <?php if (!empty($back_href)): ?>
                    <a href="<?= $e($back_href) ?>" class="wz-back" data-cancel>Cancel</a>
                <?php endif; ?>
            </div>
        <?= form_close() ?>
    </main>

    <aside class="wz-side" aria-label="Your app so far">
        <div class="wz-sheet">
            <div class="wz-sheet-head"><span>Your app so far</span><span>draft</span></div>
            <dl>
                <div><dt>repository</dt><dd data-show="repository"></dd></div>
                <div><dt>branch</dt><dd data-show="branch"></dd></div>
                <div><dt>address</dt><dd data-show="address"></dd></div>
                <div><dt>domain</dt><dd data-show="domain"></dd></div>
                <div><dt>trongate</dt><dd data-show="type"></dd></div>
            </dl>
        </div>
    </aside>
</div>
<script src="<?= BASE_URL ?>apps_module/js/app_steps.js"></script>
