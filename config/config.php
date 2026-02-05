<?php
/**
 * Trongate v2 – Pre-release Notice
 *
 * This is a pre-release version of Trongate v2.
 * It may contain bugs, incomplete features, or breaking changes.
 *
 * Use in production environments is not yet recommended.
 */

//The main config file
define('BASE_URL', getenv('BASE_URL') ?: 'https://trongate.cloud.test/');
define('ENV', 'dev');
define('DEFAULT_MODULE', 'welcome');
define('DEFAULT_METHOD', 'index');
define('MODULE_ASSETS_TRIGGER', '_module');
define('ERROR_404', 'templates/error_404');

// SSH Certificate Authority Public Key
// This CA public key is trusted by all provisioned servers for user authentication
// Servers will accept any certificate signed by this CA
// Generate CA with: ssh-keygen -t ed25519 -C "ca@trongate.cloud" -f ca_user_key
define('PLATFORM_SSH_CA_PUBLIC_KEY', getenv('PLATFORM_SSH_CA_PUBLIC_KEY') ?: '');