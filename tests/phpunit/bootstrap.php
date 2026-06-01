<?php
/**
 * PHPUnit bootstrap for wp-module-ai-assistant.
 *
 * @package NewfoldLabs\WP\Module\AIAssistant
 */

$module_root = dirname( dirname( __DIR__ ) );

if ( file_exists( $module_root . '/vendor/autoload.php' ) ) {
	require $module_root . '/vendor/autoload.php';
} elseif ( file_exists( dirname( dirname( dirname( $module_root ) ) ) . '/vendor/autoload.php' ) ) {
	require dirname( dirname( dirname( $module_root ) ) ) . '/vendor/autoload.php';
}

$wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $wp_phpunit_dir ) {
	$wp_phpunit_dir = '/tmp/wordpress-tests-lib';
}

require $wp_phpunit_dir . '/includes/bootstrap.php';
