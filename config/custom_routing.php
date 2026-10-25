<?php
// Pretty URLs => module/method. First match wins, so list specific routes before (:any).
$routes = [
    // Auth (login module, user level 2 = customer)
    'tg-admin' => 'login/login/tg-admin',
    'login' => 'login/login/login', // last segment = customer secret_login_word (config/login.php)
    'logout' => 'login/logout',
    'register' => 'customer/register',
    'register/submit' => 'customer/submit_register',
    'impersonate/stop' => 'impersonation/stop', // the panel banner while tg-admin is signed in as a customer
    'auth/github' => 'github_auth/start',
    'auth/github/callback' => 'github_auth/callback',
    'auth/github/installed' => 'github_auth/installed',

    // Live SQL check of any SQL box (sql_check module)
    'sql/check' => 'sql_check/check',

    // News: the changelog and writing (modules/news/posts)
    'news/feed' => 'news/feed',
    'news/changelog' => 'news/index/changelog',
    'news/writing' => 'news/index/writing',
    'news/(:any)' => 'news/show/$1',

    // Dashboard: the customer's projects
    'dashboard' => 'customer',

    // Onboarding
    'onboarding' => 'customer-onboarding',
    'onboarding/branches' => 'customer-onboarding/repository_branch_options',
    'onboarding/env-suggestions' => 'customer-onboarding/env_suggestion_fields',
    'onboarding/sql-files' => 'customer-onboarding/sql_file_options',
    'onboarding/build-suggestions' => 'customer-onboarding/build_suggestion_fields',
    'onboarding/app/reset' => 'customer-onboarding/reset',
    'onboarding/app/(:any)' => 'customer-onboarding/app/$1',
    'onboarding/(:any)' => 'customer-onboarding/$1',

    // Projects (apps grouped in one namespace, with members)
    'projects/new' => 'projects/create',
    'projects/new/submit' => 'projects/submit',
    'projects/(:num)/apps/new' => 'apps/create/$1',
    'projects/(:num)/apps/new/reset' => 'apps/reset/$1',
    'projects/(:num)/apps/new/(:any)' => 'apps/create/$1/$2',
    'projects/(:num)/apps/submit' => 'apps/submit/$1',
    'projects/(:num)/apps/draft' => 'apps/save_draft/$1',
    'projects/(:num)/apps/cancel' => 'apps/cancel/$1',
    'projects/(:num)/settings' => 'projects/settings/$1',
    'projects/(:num)/traffic' => 'projects/traffic/$1',
    'projects/(:num)/traffic/poll' => 'projects/traffic_poll/$1',
    'projects/(:num)/settings/submit' => 'projects/submit_settings/$1',
    'projects/(:num)/delete' => 'projects/submit_delete/$1',
    'projects/(:num)/members/add' => 'projects/add_member/$1',
    'projects/(:num)/members/(:num)/remove' => 'projects/remove_member/$1/$2',
    'projects/(:num)/services' => 'projects/services/$1',
    'projects/(:num)/services/add' => 'projects/add_service/$1',
    'projects/(:num)/services/(:num)/redeploy' => 'projects/redeploy_service/$1/$2',
    'projects/(:num)/services/(:num)/delete' => 'projects/delete_service/$1/$2',
    'projects/(:num)' => 'projects/show/$1',

    // Apps (each in one project; ids are global, membership is checked per request)
    'apps/branches' => 'apps/repository_branch_options',
    'apps/env-suggestions' => 'apps/env_suggestion_fields',
    'apps/sql-files' => 'apps/sql_file_options',
    'apps/build-suggestions' => 'apps/build_suggestion_fields',
    'apps/(:num)/deploy' => 'apps/deploy/$1',
    'apps/(:num)/setup-sql/dismiss' => 'apps-setup_sql/dismiss/$1',
    'setup-sql/administrator/submit' => 'apps-setup_sql/administrator',
    'setup-sql/administrator' => 'apps-setup_sql/administrator_form',
    'apps/(:num)/rollback' => 'apps/rollback/$1',
    'apps/(:num)/settings' => 'apps/settings/$1',
    'apps/(:num)/settings/submit' => 'apps/submit_settings/$1',
    'apps/(:num)/env/submit' => 'apps/submit_env/$1',
    'apps/(:num)/env/variables' => 'apps/submit_plain_env/$1',
    'apps/(:num)/env/delete' => 'apps/delete_env/$1',
    'apps/(:num)/live-state' => 'apps/live_state/$1',
    'apps/(:num)/processes' => 'apps/processes_partial/$1',
    'apps/(:num)/logs' => 'apps/logs/$1',
    'apps/(:num)/logs/poll' => 'apps/logs_partial/$1',
    'apps/(:num)/upgrade' => 'apps-upgrade/index/$1',
    'apps/(:num)/upgrade/status' => 'apps-upgrade/status/$1',
    'apps/(:num)/upgrade/submit' => 'apps-upgrade/submit/$1',
    'apps/(:num)/traffic' => 'apps-traffic/index/$1',
    'apps/(:num)/traffic/poll' => 'apps-traffic/poll/$1',
    'apps/(:num)/mail' => 'apps-mail/index/$1',
    'apps/(:num)/mail/poll' => 'apps-mail/poll/$1',
    'apps/(:num)/mail/source' => 'apps-mail/source/$1',
    'apps/(:num)/mail/delete' => 'apps-mail/delete/$1',
    'apps/(:num)/domain' => 'apps-domain/index/$1',
    'apps/(:num)/domain/records' => 'apps-domain/records/$1',
    'apps/(:num)/domain/submit' => 'apps-domain/submit/$1',
    'apps/(:num)/domain/verify' => 'apps-domain/verify/$1',
    'apps/(:num)/staging/submit' => 'apps-staging/submit/$1',
    'apps/(:num)/database' => 'apps-database/index/$1',
    'apps/(:num)/database/query' => 'apps-database/query/$1',
    'apps/(:num)/database/copy-tables' => 'apps-database/copy_tables/$1',
    'apps/(:num)/database/row' => 'apps-database/row/$1',
    'apps/(:num)/database/row/submit' => 'apps-database/submit_row/$1',
    'apps/(:num)/database/row/delete' => 'apps-database/delete_row/$1',
    'apps/(:num)/database/rows/delete' => 'apps-database/delete_rows/$1',
    'apps/(:num)/database/rows/delete/confirm' => 'apps-database/delete_rows_form/$1',
    'apps/(:num)/database/row/password' => 'apps-database/password_form/$1',
    'apps/(:num)/database/row/password/submit' => 'apps-database/change_password/$1',
    'apps/(:num)/database/credentials' => 'apps-database/credentials/$1',
    'apps/(:num)/database/credentials/password' => 'apps-database/show_password/$1',
    'apps/(:num)/database/backups/restore' => 'apps-database/restore_backup/$1',
    'apps/(:num)/database/import/start' => 'apps-database/import_start/$1',
    'apps/(:num)/database/import/chunk' => 'apps-database/import_chunk/$1',
    'apps/(:num)/database/import/finish' => 'apps-database/import_finish/$1',
    'apps/(:num)/database/import/status' => 'apps-database/import_status/$1',
    'apps/(:num)/database/import/run' => 'apps-database/import_run/$1',
    'apps/(:num)/database/import/stop' => 'apps-database/import_stop/$1',
    'apps/(:num)/database/import/continue' => 'apps-database/import_continue/$1',
    'apps/(:num)/database/import/discard' => 'apps-database/import_discard/$1',
    'apps/(:num)/delete' => 'apps/delete/$1',
    'apps/(:num)/delete/submit' => 'apps/submit_delete/$1',
    'apps/(:num)/delete/confirm' => 'apps/delete_confirm/$1',
    'apps/(:num)/backup/(database|files)' => 'apps-backups/download/$1/$2',
    'apps/(:num)' => 'apps/show/$1',

    // Deployments
    'deployments/(:num)' => 'deployments/show/$1',
    'deployments/(:num)/logs' => 'deployments/logs_partial/$1',
    'deployments/(:num)/log' => 'deployments/log_modal/$1',

    // The GitHub App's webhook (public, HMAC-signed with GITHUB_APP_WEBHOOK_SECRET)
    'webhooks/github' => 'webhooks/github',

    // MCP endpoint (modules/mcp): OAuth access tokens act as a customer, MCP_SECRET as the admin
    'mcp' => 'mcp/index',

    // OAuth 2.1 for assistants connecting to the MCP endpoint (modules/mcp/oauth)
    '.well-known/oauth-protected-resource' => 'mcp-oauth/protected_resource',
    '.well-known/oauth-protected-resource/mcp' => 'mcp-oauth/protected_resource',
    '.well-known/oauth-authorization-server' => 'mcp-oauth/metadata',
    '.well-known/openid-configuration' => 'mcp-oauth/metadata',
    'oauth/register' => 'mcp-oauth/register',
    'oauth/authorize' => 'mcp-oauth/authorize',
    'oauth/consent' => 'mcp-oauth/consent',
    'oauth/consent/submit' => 'mcp-oauth/submit_consent',
    'oauth/token' => 'mcp-oauth/token',
    'oauth/revoke' => 'mcp-oauth/revoke',

    // Assistants (one child module of mcp each)
    'integrations/claude' => 'mcp-claude/index',
    'integrations/claude/(:num)/disconnect' => 'mcp-claude/disconnect/$1',
];
define('CUSTOM_ROUTES', $routes);
