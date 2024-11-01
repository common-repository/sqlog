<?php
/*
* Plugin name: SQLog
* Description: Log WordPress MySQL queries in csv file (and log file). Useful when you need to improve the performance or debug something.
* Author: Xuan NGUYEN
* Author URI: https://xuxu.fr/
* Version: 1.0.0
* Text-domain: sqlog
*/

define( 'SQLOG_PLUGIN_VERSION', '1.0.0' );
define( 'SQLOG_PATH', __DIR__ );
define( 'SQLOG_SLUG', 'sqlog' );
define( 'SQLOG_SLUG_CAMELCASE', 'SQLog' );
define( 'SQLOG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SQLOG_PLUGIN_FILE', __FILE__ );

//
require SQLOG_PATH . '/classes/class-sqlog.php';

// Launch
\SQLog::run();
