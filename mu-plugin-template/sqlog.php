<?php
// define SAVEQUERIES to true if sqlog_enabled
if ( get_option( 'sqlog_enabled' ) ) {
	if ( ! defined( 'SAVEQUERIES' ) ) {
		define( 'SAVEQUERIES', true );
	}
}
