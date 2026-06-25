<?php
/**
 * Plugin Name:     AgeVerif – Age Verification
 * Plugin URI:      https://ageverif.com/wordpress
 * Description:     Integrate the ageverif.com age verification service into your WordPress site. Protect age-restricted content with a simple, privacy-focused verification gate.
 * Version:         1.0.0
 * Author:          AgeVerif
 * Author URI:      https://ageverif.com
 * License:         GPL-2.0-or-later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:     ageverif-wordpress
 * Domain Path:     /languages
 * Requires at least: 5.6
 * Requires PHP:       7.4
 *
 * @package AgeVerif
 */

defined( 'ABSPATH' ) || exit;

define( 'AGEVERIF_VERSION', '1.0.0' );
define( 'AGEVERIF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGEVERIF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGEVERIF_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix   = 'AgeVerif\\';
		$base_dir = AGEVERIF_PLUGIN_DIR . 'includes/';

		// Validate directory existence for security
		if ( ! is_dir( $base_dir ) ) {
			return;
		}

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

/**
 * Initialize the plugin.
 */
function ageverif_wordpress_init() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new AgeVerif\AgeVerif_WordPress();
	}
	return $instance;
}

require AGEVERIF_PLUGIN_DIR . 'includes/class-ageverif-wordpress.php';
ageverif_wordpress_init();
