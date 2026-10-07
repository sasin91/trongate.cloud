<?php
// Pretty URLs => module/method. First match wins, so list specific routes before (:any).
$routes = [
    // Auth (login module, user level 2 = customer)
    'tg-admin' => 'login/login/tg-admin',
    'login' => 'login/login/login', // last segment = customer secret_login_word (config/login.php)
    'logout' => 'login/logout',
    'register' => 'customer/register',
    'register/submit' => 'customer/submit_register',
    'auth/github' => 'github_auth/start',
    'auth/github/callback' => 'github_auth/callback',
    'auth/github/installed' => 'github_auth/installed',

    // Dashboard: the customer's projects
    'dashboard' => 'customer',

    // Onboarding
    'onboarding' => 'customer-onboarding',
    'onboarding/branches' => 'customer-onboarding/repository_branch_options',
    'onboarding/(:any)' => 'customer-onboarding/$1',

    // Projects (apps grouped in one namespace, with members)
    'projects/new' => 'projects/create',
    'projects/new/submit' => 'projects/submit',
    'projects/(:num)/apps/new' => 'apps/create/$1',
    'projects/(:num)/apps/submit' => 'apps/submit/$1',
    'projects/(:num)/settings' => 'projects/settings/$1',
    'projects/(:num)/settings/submit' => 'projects/submit_settings/$1',
    'projects/(:num)/delete' => 'projects/submit_delete/$1',
    'projects/(:num)/members/add' => 'projects/add_member/$1',
    'projects/(:num)/members/(:num)/remove' => 'projects/remove_member/$1/$2',
    'projects/(:num)' => 'projects/show/$1',

    // Apps (each in one project; ids are global, membership is checked per request)
    'apps/branches' => 'apps/repository_branch_options',
    'apps/(:num)/deploy' => 'apps/deploy/$1',
    'apps/(:num)/rollback' => 'apps/rollback/$1',
    'apps/(:num)/settings' => 'apps/settings/$1',
    'apps/(:num)/settings/submit' => 'apps/submit_settings/$1',
    'apps/(:num)/env/submit' => 'apps/submit_env/$1',
    'apps/(:num)/env/delete' => 'apps/delete_env/$1',
    'apps/(:num)/verify-domain' => 'apps/verify_domain/$1',
    'apps/(:num)/live-state' => 'apps/live_state/$1',
    'apps/(:num)/logs' => 'apps/logs/$1',
    'apps/(:num)/logs/poll' => 'apps/logs_partial/$1',
    'apps/(:num)/database' => 'apps-database/index/$1',
    'apps/(:num)/database/query' => 'apps-database/query/$1',
    'apps/(:num)/database/row' => 'apps-database/row/$1',
    'apps/(:num)/database/row/submit' => 'apps-database/submit_row/$1',
    'apps/(:num)/database/row/delete' => 'apps-database/delete_row/$1',
    'apps/(:num)/delete' => 'apps/delete/$1',
    'apps/(:num)/delete/submit' => 'apps/submit_delete/$1',
    'apps/(:num)' => 'apps/show/$1',

    // Deployments
    'deployments/(:num)' => 'deployments/show/$1',
    'deployments/(:num)/logs' => 'deployments/logs_partial/$1',

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
