<?php
/**
 * HM ACM WP-CLI functionality
 */

declare( strict_types=1 );

namespace HM\ACM\CLI;

use WP_CLI;

/**
 * Add hooks.
 */
function bootstrap() : void {
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\register_commands' );
}

/**
 * Regsiters the WP-CLI commands.
 */
function register_commands() : void {
	$acm = new Commands\Acm();
	WP_CLI::add_command( 'hm-acm', [ $acm, 'acm' ] );
}
