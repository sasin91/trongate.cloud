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
define('ENV', getenv('ENV') ?: 'dev');
define('DEFAULT_MODULE', 'welcome');
define('DEFAULT_METHOD', 'index');
define('MODULE_ASSETS_TRIGGER', '_module');
define('ERROR_404', 'templates/error_404');

// Application key used to encrypt secrets (base64 encoded 32 byte libsodium key)
// Generate with: php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
define('APP_KEY', getenv('APP_KEY') ?: '');

// Platform (k3s hosting) settings
require_once __DIR__ . '/platform.php';
