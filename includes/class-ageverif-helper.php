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
			// Default to enabling every major search engine, AI crawler, and social
			// preview bot so SEO crawlers (incl. Baidu / Yandex) never see the gate.
			// Existing users are backfilled via AgeVerif_WordPress::version_check().
			'bot_bypass_presets'    => array(
				'googlebot', 'googleother', 'bingbot', 'slurp', 'duckduckbot',
				'baiduspider', 'yandexbot', 'applebot',
				'facebookbot', 'twitterbot', 'linkedinbot', 'discordbot', 'telegrambot',
				'openai', 'claudebot', 'perplexitybot',
			),
			'bot_bypass_custom'     => '',		'underage_redirect_url' => '',
		'test_mode'             => 0,

		// OAuth2 — when enabled, replaces the checker.js flow entirely.
		// Visitor clicks a "Verify with AgeVerif" button → redirected to
		// api.ageverif.com/v1/oauth2/{checker|login} → redirected back to
		// {site}/?ageverif_oauth=callback → server exchanges code for token
		// and sets a signed verification cookie.
		// Reference: https://docs.ageverif.com/oauth2.html
		'oauth_enabled'         => 0,
		'oauth_client_id'       => '',
		'oauth_client_secret'   => '',
		'oauth_flow'            => 'checker',    // 'checker' (Verify age) | 'login' (Sign in)
		'oauth_button_label'    => '',           // empty = use AgeVerif default label
		'oauth_button_color'    => 'blue',       // 'blue' | 'white' | 'black'
		'oauth_language'        => 'auto',       // 'auto' | en | de | es | fr | it | pt
		'oauth_challenges'      => array(),      // subset of allowed challenges (see sanitize)
	);
	}
}
