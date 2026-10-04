<?php
// Pretty URLs => module/method. First match wins, so list specific routes before (:any).
$routes = [
    // Auth (login module, user level 2 = customer)
    'tg-admin' => 'login/login/tg-admin',
    'login' => 'login/login/customer',
    'logout' => 'login/logout',
    'register' => 'customer/register',
    'register/submit' => 'customer/submit_register',

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
    'projects/(:num)/apps/source' => 'apps/submit_source/$1',
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
    'apps/(:num)/verify-domain' => 'apps/verify_domain/$1',
    'apps/(:num)/live-state' => 'apps/live_state/$1',
    'apps/(:num)/delete' => 'apps/delete/$1',
    'apps/(:num)/delete/submit' => 'apps/submit_delete/$1',
    'apps/(:num)' => 'apps/show/$1',

    // Deployments
    'deployments/(:num)' => 'deployments/show/$1',
    'deployments/(:num)/logs' => 'deployments/logs_partial/$1',

    // GitHub push webhooks (public, HMAC-signed per app)
    'webhooks/github/(:num)' => 'webhooks/github/$1',

    // Admin MCP endpoint (modules/mcp), MCP_SECRET bearer auth
    'mcp' => 'mcp/index',
];
define('CUSTOM_ROUTES', $routes);
