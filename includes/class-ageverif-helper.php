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

	const ALLOWED_LANGUAGES = array( 'auto', 'en', 'de', 'es', 'fr', 'it', 'pt' );
	const ALLOWED_CHALLENGES = array( 'selfie', 'ticket' );

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
		'oauth_button_color'    => 'blue',       // 'blue' | 'white' | 'black' | 'gray'
		'oauth_language'        => 'auto',       // 'auto' | en | de | es | fr | it | pt
		'oauth_challenges'      => array(),      // subset of allowed challenges (see sanitize)
	);
	}

	/**
	 * Single source of truth for loading + merging options.
	 */
	public static function get_options() {
		$stored = get_option( 'ageverif_options', array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Whether OAuth2 is active (enabled + client_id configured).
	 */
	public static function oauth_is_active( array $options ) {
		if ( empty( $options['oauth_enabled'] ) ) {
			return false;
		}
		$client_id = isset( $options['oauth_client_id'] )
			? trim( (string) $options['oauth_client_id'] )
			: '';
		return '' !== $client_id;
	}

	/**
	 * Sanitize an array of string keys (checkbox groups).
	 */
	public static function sanitize_key_array( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		return array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $input ),
					static function ( $v ) { return '' !== $v; }
				)
			)
		);
	}

	/**
	 * Sanitize a multi-line textarea field.
	 */
	public static function sanitize_textarea_lines( $input ) {
		$lines = preg_split( '/\r?\n/', (string) $input );
		$clean = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$clean[] = sanitize_text_field( $line );
			}
		}
		return implode( "\n", $clean );
	}

	/**
	 * Sanitize challenges array against the allowed list.
	 */
	public static function sanitize_challenges( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$clean = array();
		foreach ( $input as $c ) {
			$c = sanitize_key( $c );
			if ( in_array( $c, self::ALLOWED_CHALLENGES, true ) ) {
				$clean[] = $c;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Validate a language code against the allowed list.
	 */
	public static function sanitize_language( $lang ) {
		$lang = sanitize_text_field( $lang );
		return in_array( $lang, self::ALLOWED_LANGUAGES, true ) ? $lang : 'auto';
	}

	/**
	 * Default OAuth button label based on flow.
	 */
	public static function default_button_label_from_options( array $options ) {
		$flow = isset( $options['oauth_flow'] ) ? sanitize_key( $options['oauth_flow'] ) : 'checker';
		if ( 'login' === $flow ) {
			return __( 'Continue with AgeVerif', 'ageverif-wordpress' );
		}
		return __( 'Verify with AgeVerif', 'ageverif-wordpress' );
	}

	/**
	 * CSS hex for the chosen OAuth button color.
	 */
	public static function button_color_css( $color ) {
		switch ( $color ) {
			case 'white': return '#ffffff';
			case 'black': return '#000000';
			case 'gray':  return '#e0e0e0';
			default:      return '#004db3';
		}
	}

	/**
	 * WCAG-friendly text color for the chosen button background.
	 */
	public static function button_text_color_css( $color ) {
		switch ( $color ) {
			case 'white': return '#000000';
			case 'gray':  return '#1d2327';
			default:      return '#ffffff';
		}
	}
}
