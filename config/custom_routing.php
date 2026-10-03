<?php
// Pretty URLs => module/method. First match wins, so list specific routes before (:any).
$routes = [
    // Auth (login module, user level 2 = customer)
    'tg-admin' => 'login/login/tg-admin',
    'login' => 'login/login/customer',
    'logout' => 'login/logout',
    'register' => 'customer/register',
    'register/submit' => 'customer/submit_register',

    // Dashboard
    'dashboard' => 'customer',
    'switch-team' => 'customer/switch_team',

    // Onboarding
    'onboarding' => 'customer-onboarding',
    'onboarding/branches' => 'customer-onboarding/repository_branch_options',
    'onboarding/(:any)' => 'customer-onboarding/$1',

    // Projects
    'projects/new' => 'projects/create',
    'projects/branches' => 'projects/repository_branch_options',
    'projects/(:num)/deploy' => 'projects/deploy/$1',
    'projects/(:num)/rollback' => 'projects/rollback/$1',
    'projects/(:num)/settings' => 'projects/settings/$1',
    'projects/(:num)/settings/submit' => 'projects/submit_settings/$1',
    'projects/(:num)/verify-domain' => 'projects/verify_domain/$1',
    'projects/(:num)' => 'projects/show/$1',

    // Deployments
    'deployments/(:num)' => 'deployments/show/$1',
    'deployments/(:num)/logs' => 'deployments/logs_partial/$1',

    // GitHub push webhooks (public, HMAC-signed per project)
    'webhooks/github/(:num)' => 'webhooks/github/$1',

    // Admin MCP endpoint (modules/mcp), MCP_SECRET bearer auth
    'mcp' => 'mcp/index',
];
define('CUSTOM_ROUTES', $routes);
