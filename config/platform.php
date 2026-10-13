<?php

// Platform (k3s hosting) settings. The worker reads the same names via getenv().
// Guarded so the worker and web app can both load this file safely.

// Apps get "{slug}." . PLATFORM_APPS_DOMAIN as their default hostname. Its own
// registrable domain, so apps cannot set cookies for trongate.cloud.
defined('PLATFORM_APPS_DOMAIN') || define('PLATFORM_APPS_DOMAIN', getenv('PLATFORM_APPS_DOMAIN') ?: 'trongate.dev');
// Registry + path prefix that built app images are pushed to
defined('PLATFORM_REGISTRY') || define('PLATFORM_REGISTRY', getenv('PLATFORM_REGISTRY') ?: 'registry.trongate.cloud/tc');
// Gateway API Gateway that tenant HTTPRoutes attach to
defined('PLATFORM_GATEWAY_NAME') || define('PLATFORM_GATEWAY_NAME', getenv('PLATFORM_GATEWAY_NAME') ?: 'platform');
defined('PLATFORM_GATEWAY_NAMESPACE') || define('PLATFORM_GATEWAY_NAMESPACE', getenv('PLATFORM_GATEWAY_NAMESPACE') ?: 'traefik');
// Custom domains get their own Gateway of this class in the tenant namespace, with a
// certificate from this ClusterIssuer (HTTP-01 only, see Manifests::domain_gateway)
defined('PLATFORM_GATEWAY_CLASS') || define('PLATFORM_GATEWAY_CLASS', getenv('PLATFORM_GATEWAY_CLASS') ?: 'traefik');
defined('PLATFORM_DOMAIN_ISSUER') || define('PLATFORM_DOMAIN_ISSUER', getenv('PLATFORM_DOMAIN_ISSUER') ?: 'letsencrypt-tenants');
// The load balancer's public addresses (comma-separated). A custom domain is routed
// once its A/AAAA records all point here, or it is a CNAME into PLATFORM_APPS_DOMAIN.
// Empty (local dev): only the CNAME check can pass.
defined('PLATFORM_INGRESS_IPV4') || define('PLATFORM_INGRESS_IPV4', getenv('PLATFORM_INGRESS_IPV4') ?: '');
defined('PLATFORM_INGRESS_IPV6') || define('PLATFORM_INGRESS_IPV6', getenv('PLATFORM_INGRESS_IPV6') ?: '');
// Sablier API, reachable from Traefik (scale-to-zero middleware)
defined('SABLIER_URL') || define('SABLIER_URL', getenv('SABLIER_URL') ?: 'http://sablier.sablier.svc.cluster.local:10000');
// BuildKit daemon for app and platform builds. Empty (the cluster default): the worker starts
// a throwaway daemon per build (Manifests::buildkitd). Set it only for local dev.
defined('BUILDKIT_HOST') || define('BUILDKIT_HOST', getenv('BUILDKIT_HOST') ?: '');
// Kubernetes API; empty values fall back to the in-cluster service account (Kubernetes::from_env())
defined('KUBE_API_URL') || define('KUBE_API_URL', getenv('KUBE_API_URL') ?: '');
defined('KUBE_TOKEN') || define('KUBE_TOKEN', getenv('KUBE_TOKEN') ?: '');
defined('KUBE_CA_FILE') || define('KUBE_CA_FILE', getenv('KUBE_CA_FILE') ?: '');
// RWX StorageClass (JuiceFS) for per-project persistent paths
defined('FILES_STORAGE_CLASS') || define('FILES_STORAGE_CLASS', getenv('FILES_STORAGE_CLASS') ?: 'tenant-files');
// The platform deploying itself: a push to PLATFORM_SOURCE_BRANCH of this GitHub
// repository ("owner/name", where the GitHub App is installed) makes the worker
// build Dockerfile and Dockerfile.worker (build_platform task) and push them to
// PLATFORM_IMAGES_REGISTRY as :main-{commit time}-{short sha}, which Flux image
// automation pins in the infra repository. Empty turns it off.
defined('PLATFORM_SOURCE_REPOSITORY') || define('PLATFORM_SOURCE_REPOSITORY', strtolower(getenv('PLATFORM_SOURCE_REPOSITORY') ?: ''));
defined('PLATFORM_SOURCE_BRANCH') || define('PLATFORM_SOURCE_BRANCH', getenv('PLATFORM_SOURCE_BRANCH') ?: 'main');
defined('PLATFORM_IMAGES_REGISTRY') || define('PLATFORM_IMAGES_REGISTRY', getenv('PLATFORM_IMAGES_REGISTRY') ?: 'registry.trongate.cloud/platform');
// What every customer app is built FROM (docker/app-base/Dockerfile); build_platform
// pushes it as app-base:php8.4 before the worker image, so a worker always finds it
defined('PLATFORM_APP_BASE_IMAGE') || define('PLATFORM_APP_BASE_IMAGE', getenv('PLATFORM_APP_BASE_IMAGE') ?: PLATFORM_IMAGES_REGISTRY . '/app-base:php8.4');
// Seconds between reconcile_deploys runs: the worker queues a deploy for a branch
// head that a missed GitHub push webhook never built. 0 turns it off.
defined('DEPLOY_RECONCILE_INTERVAL') || define('DEPLOY_RECONCILE_INTERVAL', getenv('DEPLOY_RECONCILE_INTERVAL') !== false ? (int) getenv('DEPLOY_RECONCILE_INTERVAL') : 600);
// Seconds between redeploy_outdated_apps runs: the worker redeploys apps whose
// current deployment was applied with an older Trongate_config::CONFIG_VERSION. 0 turns it off.
defined('APP_CONFIG_REDEPLOY_INTERVAL') || define('APP_CONFIG_REDEPLOY_INTERVAL', getenv('APP_CONFIG_REDEPLOY_INTERVAL') !== false ? (int) getenv('APP_CONFIG_REDEPLOY_INTERVAL') : 600);
// Seconds between schedule_domain_rechecks runs: the worker queues recheck_domains
// for every project with a verified custom domain (the DNS gate and promotion). 0 turns it off.
defined('DOMAIN_RECHECK_INTERVAL') || define('DOMAIN_RECHECK_INTERVAL', getenv('DOMAIN_RECHECK_INTERVAL') !== false ? (int) getenv('DOMAIN_RECHECK_INTERVAL') : 600);

// Seconds between sample_app_logs runs: the worker counts new error and warning
// lines in each running app's log (App_log_rates, the app page's Errors card). 0 turns it off.
defined('APP_LOG_SAMPLE_INTERVAL') || define('APP_LOG_SAMPLE_INTERVAL', getenv('APP_LOG_SAMPLE_INTERVAL') !== false ? (int) getenv('APP_LOG_SAMPLE_INTERVAL') : 60);
// Seconds between check_sql_imports runs: the worker carries on .sql file imports
// whose task died and deletes stored files of abandoned or failed ones (Sql_import). 0 turns it off.
defined('SQL_IMPORT_CHECK_INTERVAL') || define('SQL_IMPORT_CHECK_INTERVAL', getenv('SQL_IMPORT_CHECK_INTERVAL') !== false ? (int) getenv('SQL_IMPORT_CHECK_INTERVAL') : 300);

// The trongate.cloud GitHub App (docs/github-app.md). One App does both jobs:
// its OAuth client signs customers in and lists the repositories they may
// pick; its installation tokens let the worker read those repositories, and
// its webhook (POST webhooks/github, signed with the webhook secret) delivers
// pushes. The private key is the App's PEM (literal "\n" sequences are
// accepted, for one-line .env files); only the worker needs it.
defined('GITHUB_APP_SLUG') || define('GITHUB_APP_SLUG', getenv('GITHUB_APP_SLUG') ?: '');
defined('GITHUB_APP_CLIENT_ID') || define('GITHUB_APP_CLIENT_ID', getenv('GITHUB_APP_CLIENT_ID') ?: '');
defined('GITHUB_APP_CLIENT_SECRET') || define('GITHUB_APP_CLIENT_SECRET', getenv('GITHUB_APP_CLIENT_SECRET') ?: '');
defined('GITHUB_APP_PRIVATE_KEY') || define('GITHUB_APP_PRIVATE_KEY', str_replace('\n', "\n", getenv('GITHUB_APP_PRIVATE_KEY') ?: ''));
defined('GITHUB_APP_WEBHOOK_SECRET') || define('GITHUB_APP_WEBHOOK_SECRET', getenv('GITHUB_APP_WEBHOOK_SECRET') ?: '');

// Shared Valkey (deploy/k8s/cache) that customer apps and the panel keep their PHP
// sessions in, so a redeploy or a scale-to-zero wake-up does not log anyone out.
// The worker signs in as VALKEY_WORKER_USER to create one ACL user per app and one
// for the panel (Valkey_sessions); only the worker needs the password. Without it,
// apps keep PHP's default file sessions inside the pod and panel sessions fail.
defined('VALKEY_HOST') || define('VALKEY_HOST', getenv('VALKEY_HOST') ?: 'valkey.cache.svc.cluster.local');
defined('VALKEY_PORT') || define('VALKEY_PORT', (int) (getenv('VALKEY_PORT') ?: 6379));
defined('VALKEY_WORKER_USER') || define('VALKEY_WORKER_USER', getenv('VALKEY_WORKER_USER') ?: 'trongate-cloud');
defined('VALKEY_WORKER_PASSWORD') || define('VALKEY_WORKER_PASSWORD', getenv('VALKEY_WORKER_PASSWORD') ?: '');

// The platform's SMTP inbox (deploy/k8s/platform/mail.yaml, modules/platform/mail):
// apps with mail capture on (the default) send here, and their mail shows on
// the app's Mail tab instead of being delivered (Mail_capture).
defined('PLATFORM_MAIL_HOST') || define('PLATFORM_MAIL_HOST', getenv('PLATFORM_MAIL_HOST') ?: 'trongate-cloud-mail.trongate-cloud.svc.cluster.local');
defined('PLATFORM_MAIL_PORT') || define('PLATFORM_MAIL_PORT', (int) (getenv('PLATFORM_MAIL_PORT') ?: 2525));
// Signs the apps' inbox passwords (Mail_capture::password). Its own key, not
// APP_KEY: the inbox is reachable from tenant pods, so it only gets this one.
// The worker (which writes the passwords) and the inbox need it.
defined('MAIL_KEY') || define('MAIL_KEY', getenv('MAIL_KEY') ?: '');
