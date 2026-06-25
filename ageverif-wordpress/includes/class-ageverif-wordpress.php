<?php
namespace AgeVerif;

defined( 'ABSPATH' ) || exit;

class AgeVerif_WordPress {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->load_hooks();
		$this->version_check();
	}

	/**
	 * Version compatibility check for future updates.
	 */
	private function version_check() {
		$installed_version = get_option( 'ageverif_version', '0.0.0' );
		if ( version_compare( $installed_version, AGEVERIF_VERSION, '<' ) ) {
			// Run upgrade routines for new version
			update_option( 'ageverif_version', AGEVERIF_VERSION );
		}
	}

	private function load_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'init_admin' ) );
		add_action( 'plugins_loaded', array( $this, 'init_frontend' ) );
		add_filter( 'plugin_action_links_' . AGEVERIF_BASENAME, array( $this, 'add_action_links' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'ageverif-wordpress',
			false,
			dirname( AGEVERIF_BASENAME ) . '/languages'
		);
	}

	public function init_admin() {
		if ( is_admin() ) {
			require_once AGEVERIF_PLUGIN_DIR . 'admin/class-ageverif-admin.php';
			new Admin\AgeVerif_Admin();
		}
	}

	public function init_frontend() {
		require_once AGEVERIF_PLUGIN_DIR . 'includes/class-ageverif-frontend.php';
		new AgeVerif_Frontend();
	}

	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=ageverif' ),
			esc_html__( 'Settings', 'ageverif-wordpress' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
}
