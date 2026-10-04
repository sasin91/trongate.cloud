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
// BuildKit daemon for Railpack builds. Empty (the cluster default): the worker starts
// a throwaway daemon per build (Manifests::buildkitd). Set it only for local dev.
defined('BUILDKIT_HOST') || define('BUILDKIT_HOST', getenv('BUILDKIT_HOST') ?: '');
// Kubernetes API; empty values fall back to the in-cluster service account (Kubernetes::from_env())
defined('KUBE_API_URL') || define('KUBE_API_URL', getenv('KUBE_API_URL') ?: '');
defined('KUBE_TOKEN') || define('KUBE_TOKEN', getenv('KUBE_TOKEN') ?: '');
defined('KUBE_CA_FILE') || define('KUBE_CA_FILE', getenv('KUBE_CA_FILE') ?: '');
// RWX StorageClass (JuiceFS) for per-project persistent paths
defined('FILES_STORAGE_CLASS') || define('FILES_STORAGE_CLASS', getenv('FILES_STORAGE_CLASS') ?: 'tenant-files');
