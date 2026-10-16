<?php
/**
 * New-app questions, one at a time: repository, branch, environment, name, Trongate version, database, build, settings.
 * Shared by onboarding/app and projects/{id}/apps/new.
 *
 * Expects: $provider, $github_connected, $repositories, $connect_url, $public_link, $install_url, $app_types, $branches_url (returns <option>s),
 * $env_url (returns the Environment inputs), $sql_url (returns the Database question's files),
 * $sql_check_url (checks the typed SQL while it is typed),
 * $build_url (returns the Build question's fields), $env_fields, $env_loaded_for, $settings, $setup_sql,
 * $build and $error_step (App_form::_app_form_data),
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
        <?= form_open($form_action, ['class' => 'wz-stage', 'data-steps' => '', 'data-offset' => (string) (int) $q_offset, 'data-total' => (string) (int) $q_total, 'data-branches' => $branches_url, 'data-env' => $env_url, 'data-sql' => $sql_url, 'data-build' => $build_url, 'data-provider' => $provider, 'data-error-step' => $error_step, 'data-apps-domain' => PLATFORM_APPS_DOMAIN]) ?>
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
                    <?php if (!$github_connected): ?>
                        <p class="wz-hint"><a href="<?= $e($connect_url) ?>">Connect GitHub</a> to pick from your repositories, private ones included, and deploy on every push.</p>
                    <?php elseif (empty($repositories)): ?>
                        <p class="wz-hint">The trongate.cloud GitHub app cannot read any of your repositories yet. <a href="<?= $e($install_url) ?>">Install it on GitHub</a> and pick the repositories to deploy.</p>
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
                <label class="wz-field">
                    <span class="wz-label"><?= $github_connected ? 'Or paste a link to a public repository' : 'Paste a link to a public GitHub repository' ?></span>
                    <input type="text" name="public_link" class="wz-in mono" placeholder="https://github.com/owner/name" value="<?= $e($public_link) ?>" maxlength="300" autocomplete="off" spellcheck="false" <?= $github_connected ? '' : 'required' ?>>
                    <span class="wz-hint">No GitHub app needed. It deploys when you press Deploy, not on every push.</span>
                </label>
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
                <p class="wz-hint" data-public-only <?= $public_link === '' ? 'hidden' : '' ?>>A public link deploys when you press Deploy. To deploy on every push, connect GitHub and install the trongate.cloud app on the repository.</p>
                <label class="wz-toggle-row" data-push-row <?= $public_link === '' ? '' : 'hidden' ?>>
                    <span>
                        <strong>Deploy every push</strong>
                        <small>Turn off to deploy only when you press the button.</small>
                    </span>
                    <input type="hidden" name="auto_deploy" value="0">
                    <input type="checkbox" name="auto_deploy" value="1" class="wz-switch" <?= $auto_deploy ? 'checked' : '' ?>>
                </label>
            </fieldset>

            <fieldset class="wz-step" data-step="env" data-label="Environment">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 3 ?> &rarr;</span>
                    <legend>Fill in its environment variables.</legend>
                </div>
                <div data-env-fields data-loaded-for="<?= $e($env_loaded_for) ?>">
                    <?php if ($env_loaded_for !== ''): ?>
                        <?php require __DIR__ . '/_env_suggestion_fields.php'; ?>
                    <?php else: ?>
                        <p class="wz-hint">Pick a repository and branch first.</p>
                    <?php endif; ?>
                </div>
            </fieldset>

            <fieldset class="wz-step" data-step="name" data-label="Name">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 4 ?> &rarr;</span>
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
                    <span class="wz-num"><?= $q_offset + 5 ?> &rarr;</span>
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

            <fieldset class="wz-step" data-step="database" data-label="Database">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 6 ?> &rarr;</span>
                    <legend>Set up its database? (optional)</legend>
                </div>
                <p class="wz-hint">Your app gets its own MariaDB database, already set up in <code>config/database.php</code>. This SQL runs once, on the first deploy. Later deploys never change its tables; run SQL from the app's Database tab.</p>
                <div data-sql-files data-loaded-for="<?= $e($setup_sql['sql_files_loaded_for']) ?>">
                    <?php if ($setup_sql['sql_files_loaded_for'] !== ''): ?>
                        <?php $sql_files = $setup_sql['sql_files']; require APPPATH . 'modules/apps/setup_sql/views/_sql_file_options.php'; ?>
                    <?php else: ?>
                        <p class="wz-hint">Pick a repository and branch first.</p>
                    <?php endif; ?>
                </div>
                <label class="wz-field">
                    <span class="wz-label">Another file in the repository</span>
                    <input type="text" name="sql_path" class="wz-in mono" placeholder="modules/shop/tables.sql" value="<?= $e($setup_sql['sql_path']) ?>" maxlength="500">
                </label>
                <label class="wz-field">
                    <span class="wz-label">Or type SQL</span>
                    <textarea name="setup_sql" class="wz-in mono wz-sql" rows="5" spellcheck="false" placeholder="CREATE TABLE posts (id INT PRIMARY KEY AUTO_INCREMENT, title VARCHAR(255));" aria-describedby="wz-sql-check" data-sql-check="<?= $e(BASE_URL . $sql_check_url) ?>" data-sql-check-result="wz-sql-check"><?= $e($setup_sql['sql']) ?></textarea>
                    <span id="wz-sql-check" class="sql-check wz-sql-check" aria-live="polite" hidden></span>
                    <span class="wz-hint">Runs after the files. Kept encrypted until it has run. If a statement fails, the deploy carries on and the app page shows what did not run.</span>
                </label>
            </fieldset>

            <fieldset class="wz-step" data-step="build" data-label="Build">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 7 ?> &rarr;</span>
                    <legend>How is it built?</legend>
                </div>
                <div data-build-fields data-loaded-for="<?= $e($build['loaded_for']) ?>">
                    <?php require __DIR__ . '/_build_fields.php'; ?>
                </div>
            </fieldset>

            <fieldset class="wz-step" data-step="settings" data-label="Settings">
                <div class="wz-q">
                    <span class="wz-num"><?= $q_offset + 8 ?> &rarr;</span>
                    <legend>Anything else before launch?</legend>
                </div>
                <div class="wz-settings">
                    <label class="wz-field">
                        <span class="wz-label">Trongate ENV</span>
                        <input type="text" name="trongate_env" class="wz-in mono" list="wz-trongate-envs" value="<?= $e($settings['trongate_env']) ?>" required>
                        <datalist id="wz-trongate-envs">
                            <?php foreach (Runtime_settings::TRONGATE_ENV_SUGGESTIONS as $option): ?>
                                <option value="<?= $e($option) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <span class="wz-hint">In <code>dev</code>, Trongate shows error details. Use <code>prod</code> to hide them from visitors.</span>
                    </label>
                    <label class="wz-field">
                        <span class="wz-label">Health check path</span>
                        <input type="text" name="health_check_path" class="wz-in mono" placeholder="/" value="<?= $e($settings['health_check_path']) ?>">
                        <span class="wz-hint">A deploy goes live once this path answers with a 2xx or 3xx. The home page by default.</span>
                    </label>
                    <label class="wz-field">
                        <span class="wz-label">Persistent paths</span>
                        <textarea name="persistent_paths" class="wz-in mono" rows="2" placeholder="modules/blog/uploads"><?= $e($settings['persistent_paths']) ?></textarea>
                        <span class="wz-hint">One folder per line. Files written there survive deploys.</span>
                    </label>
                    <div class="wz-field">
                        <span class="wz-label">Background process</span>
                        <div class="wz-process">
                            <input type="text" name="process_name[]" class="wz-in mono" placeholder="worker" aria-label="Process name" value="<?= $e($settings['process_name']) ?>">
                            <input type="text" name="process_command[]" class="wz-in mono" placeholder="php bin/queue.php work" aria-label="Process command" value="<?= $e($settings['process_command']) ?>">
                            <input type="hidden" name="process_replicas[]" value="1">
                        </div>
                        <span class="wz-hint">Runs next to your app and never sleeps, such as a queue worker. Add more in Settings.</span>
                    </div>
                    <label class="wz-toggle-row">
                        <span>
                            <strong>Always on</strong>
                            <small>Skip the few seconds an idle app takes to wake up.</small>
                        </span>
                        <input type="hidden" name="always_on" value="0">
                        <input type="checkbox" name="always_on" value="1" class="wz-switch" <?= $settings['always_on'] ? 'checked' : '' ?>>
                    </label>
                    <label class="wz-toggle-row">
                        <span>
                            <strong>Capture mail</strong>
                            <small>Mail your app sends shows up on its Mail tab instead of being delivered.</small>
                        </span>
                        <input type="hidden" name="captures_mail" value="0">
                        <input type="checkbox" name="captures_mail" value="1" class="wz-switch" <?= $settings['captures_mail'] ? 'checked' : '' ?>>
                    </label>
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
                <div><dt>env</dt><dd data-show="env"></dd></div>
                <div><dt>address</dt><dd data-show="address"></dd></div>
                <div><dt>domain</dt><dd data-show="domain"></dd></div>
                <div><dt>trongate</dt><dd data-show="type"></dd></div>
                <div><dt>database</dt><dd data-show="database"></dd></div>
                <div><dt>build</dt><dd data-show="build"></dd></div>
            </dl>
        </div>
    </aside>
</div>
<script src="<?= BASE_URL ?>apps_module/js/app_steps.js"></script>
<script src="<?= BASE_URL ?>apps_module/js/sql_check.js"></script>
