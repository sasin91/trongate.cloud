<?php

// Constants normally defined by engine/ignition.php at request time.
define('APPPATH', __DIR__ . '/');
define('REQUEST_TYPE', 'GET');
define('SEGMENTS', []);
define('ASSUMED_URL', '');

require_once __DIR__ . '/config/platform.php';
require __DIR__ . '/config/config.php';
require __DIR__ . '/config/custom_routing.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/config/site_owner.php';
