<?php
/**
 * Shared helpers: defaults schema, preserved as a single source of truth for
 * the option array consumed by AgeVerif_Admin and AgeVerif_Frontend.
 *
 * Keeping this in one place prevents drift when new options are added.
 */

namespace AgeVerif;

defined( 'ABSPATH' ) || exit;

class AgeVerif_Helper {

	/**
	 * Canonical defaults for the ageverif_options option.
	 *
	 * Field naming convention: snake_case keys (serializer-friendly),
	 * boolean flags stored as 0/1 integers (WP Settings API convention),
	 * collection defaults as empty arrays.
	 *
	 * NOTE: any new option MUST be added here in addition to:
	 *   - admin: register_settings → field_* renderer → sanitize_options entry
	 *   - frontend: consumption site in is_post_protected / enqueue_scripts etc.
	 */
	public static function defaults() {
		return array(
			'api_key'               => '',
			'test_key'              => '',
			'enabled_post_types'    => array(),
			'excluded_urls'         => '',
			'language'              => 'auto',
			'challenges'            => array(),
			'display_mode'          => 'popup',
			'closable'              => 0,
			'manual_start'          => 0,
			'content_blur'          => 0,
			'bypass_logged_in'      => 0,
			'admin_bypass'          => 1,
			'bypass_roles'          => array( 'administrator' ),
			'bot_bypass_presets'    => array( 'googlebot', 'googleother', 'bingbot', 'duckduckbot' ),
			'bot_bypass_custom'     => '',
			'underage_redirect_url' => '',
			'custom_css'            => '',
			'easy_mode'             => 0,
			'test_mode'             => 0,
		);
	}
}
