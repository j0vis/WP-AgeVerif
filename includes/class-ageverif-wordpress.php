<?php
/**
 * Core plugin class. Loads admin + frontend + per-post meta helpers.
 */

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
	 * Version compatibility check + one-time migrations.
	 *
	 * Each migration is keyed by `version_compare( $installed, '<target>', '<' )`
	 * so it runs exactly once per upgrade. Migrations MUST preserve any user
	 * un-ticks (we merge over stored values rather than overwriting).
	 */
	private function version_check() {
		$installed_version = get_option( 'ageverif_version', '0.0.0' );

		// v1.1.x — expand bot_bypass_presets so existing sites get all major
		// search engines (Baidu, Yandex, Apple, …) and AI / social preview bots
		// enabled by default. Without this, wp_parse_args wouldn't backfill
		// the new slugs because the stored key already exists for prior users.
		if ( version_compare( $installed_version, '1.1.2', '<' ) ) {
			$opts = get_option( 'ageverif_options', array() );
			if (
				is_array( $opts )
				&& isset( $opts['bot_bypass_presets'] )
				&& is_array( $opts['bot_bypass_presets'] )
			) {
				$opts['bot_bypass_presets'] = array_values(
					array_unique(
						array_merge(
							$opts['bot_bypass_presets'],
							array(
								'slurp', 'baiduspider', 'yandexbot', 'applebot',
								'facebookbot', 'twitterbot', 'linkedinbot', 'discordbot', 'telegrambot',
								'openai', 'claudebot', 'perplexitybot',
							)
						)
					)
				);
				update_option( 'ageverif_options', $opts );
			}
		}

		if ( version_compare( $installed_version, AGEVERIF_VERSION, '<' ) ) {
			update_option( 'ageverif_version', AGEVERIF_VERSION );
		}
	}

	private function load_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'init_admin' ) );
		add_action( 'plugins_loaded', array( $this, 'init_frontend' ) );
		add_action( 'plugins_loaded', array( $this, 'init_meta' ) );
		add_action( 'plugins_loaded', array( $this, 'init_oauth' ) );
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
		if ( ! is_admin() ) {
			return;
		}
		require_once AGEVERIF_PLUGIN_DIR . 'admin/class-ageverif-admin.php';
		new Admin\AgeVerif_Admin();
	}

	public function init_frontend() {
		require_once AGEVERIF_PLUGIN_DIR . 'includes/class-ageverif-frontend.php';
		new AgeVerif_Frontend();
	}

	public function init_meta() {
		// Per-post meta box needs to register globally (hook into
		// add_meta_boxes fires on admin post-edit screens).
		if ( ! is_admin() ) {
			return;
		}
		require_once AGEVERIF_PLUGIN_DIR . 'includes/class-ageverif-meta.php';
		new AgeVerif_Meta();
	}

	public function init_oauth() {
		// OAuth callback handler must run regardless of admin context —
		// it's a publicly-reachable URL that AgeVerif redirects to.
		require_once AGEVERIF_PLUGIN_DIR . 'includes/class-ageverif-oauth.php';
		new AgeVerif_OAuth();
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
