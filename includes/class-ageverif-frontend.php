<?php
/**
 * Frontend: decides when / how to load the AgeVerif checker, exposes
 * the [ageverif] shortcode for manual-start mode, and emits any custom CSS.
 */

namespace AgeVerif;

defined( 'ABSPATH' ) || exit;

class AgeVerif_Frontend {

	const CHECKER_URL = 'https://www.ageverif.com/checker.js';
	const INTEGRATION_HANDLE = 'ageverif-integration';
	const CHECKER_HANDLE = 'ageverif-checker';

	private $options;

	public function __construct() {
		$stored        = get_option( 'ageverif_options', array() );
		$this->options = wp_parse_args( is_array( $stored ) ? $stored : array(), \AgeVerif\AgeVerif_Helper::defaults() );
		add_action( 'wp', array( $this, 'maybe_enqueue_checker' ) );
		add_shortcode( 'ageverif', array( $this, 'render_shortcode' ) );
	}

	public function maybe_enqueue_checker() {
		if ( ! $this->should_load_checker() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );
	}

	private function should_load_checker() {
		// Test mode is admin-only preview.
		if ( ! empty( $this->options['test_mode'] ) && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Nothing to look up without a key.
		if ( '' === $this->get_active_key() ) {
			return false;
		}

		if ( $this->is_excluded_url() ) {
			return false;
		}

		if ( $this->is_user_role_bypassed() ) {
			return false;
		}

		if ( $this->is_user_agent_bypassed() ) {
			return false;
		}

		if ( ! $this->is_post_protected() ) {
			return false;
		}

		return true;
	}

	public function enqueue_scripts() {
		$key = $this->get_active_key();
		if ( '' === $key ) {
			return;
		}

		$args = array( 'key' => $key );

		$lang = isset( $this->options['language'] ) ? (string) $this->options['language'] : 'auto';
		if ( 'auto' !== $lang ) {
			$args['language'] = sanitize_text_field( $lang );
		}

		if ( ! empty( $this->options['challenges'] ) && is_array( $this->options['challenges'] ) ) {
			$challenges = array_values(
				array_filter(
					array_map( 'sanitize_key', (array) $this->options['challenges'] ),
					static function ( $c ) { return '' !== $c; }
				)
			);
			if ( $challenges ) {
				$args['challenges'] = implode( ',', $challenges );
			}
		}

		$display = isset( $this->options['display_mode'] ) ? (string) $this->options['display_mode'] : 'popup';
		if ( in_array( $display, array( 'tab', 'redirect' ), true ) ) {
			$args['target'] = sanitize_key( $display );
		}

		if ( ! empty( $this->options['closable'] ) ) {
			$args['closable'] = '1';
		}

		if ( ! empty( $this->options['manual_start'] ) ) {
			$args['nostart'] = '1';
		}

		$checker_url = add_query_arg( $args, self::CHECKER_URL );

		wp_register_script( self::CHECKER_HANDLE, $checker_url, array(), null, true );
		wp_enqueue_script( self::CHECKER_HANDLE );
		// `defer` alone keeps load order and respects WP's footer semantics.
		// Combining async+defer is redundant per the HTML spec.
		wp_script_add_data( self::CHECKER_HANDLE, 'defer', true );

		// Load the integration glue whenever the checker fires events that we
		// can act on (blur, redirect, manual-start trigger).
		if ( $this->options_need_integration() ) {
			$this->register_integration_script();
		}
	}

	private function options_need_integration() {
		if ( ! empty( $this->options['content_blur'] ) ) {
			return true;
		}
		if ( ! empty( $this->options['underage_redirect_url'] ) ) {
			return true;
		}
		if ( ! empty( $this->options['manual_start'] ) ) {
			return true;
		}
		return false;
	}

	private function register_integration_script() {
		$cfg = array(
			'blurContent'         => ! empty( $this->options['content_blur'] ),
			'underageRedirectUrl' => ! empty( $this->options['underage_redirect_url'] )
				? esc_url_raw( $this->options['underage_redirect_url'] )
				: '',
			'manualStart'         => ! empty( $this->options['manual_start'] ),
		);

		wp_register_script(
			self::INTEGRATION_HANDLE,
			AGEVERIF_PLUGIN_URL . 'js/ageverif-integration.js',
			array( self::CHECKER_HANDLE ),
			AGEVERIF_VERSION,
			true
		);
		wp_add_inline_script(
			self::INTEGRATION_HANDLE,
			'window.ageverifIntegration = ' . wp_json_encode( $cfg ) . ';',
			'before'
		);
		wp_enqueue_script( self::INTEGRATION_HANDLE );
		wp_script_add_data( self::INTEGRATION_HANDLE, 'defer', true );
	}

	private function get_active_key() {
		$test_mode = ! empty( $this->options['test_mode'] );
		if ( $test_mode ) {
			if ( ! empty( $this->options['test_key'] ) ) {
				return (string) $this->options['test_key'];
			}
			return '';
		}
		return ! empty( $this->options['api_key'] ) ? (string) $this->options['api_key'] : '';
	}

	private function is_excluded_url() {
		if ( empty( $this->options['excluded_urls'] ) ) {
			return false;
		}
		$current_path = $this->get_current_path();
		$current_url  = home_url( '/' . ltrim( $current_path, '/' ) );
		$lines        = preg_split( '/\r?\n/', (string) $this->options['excluded_urls'] );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '/' === substr( $line, 0, 1 ) ) {
				if ( $this->glob_match( $current_path, $line ) ) {
					return true;
				}
				$absolute = home_url( $line );
				if ( $this->glob_match( $current_url, $absolute ) ) {
					return true;
				}
			} else {
				if ( $this->glob_match( $current_url, $line ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function glob_match( $subject, $pattern ) {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		if ( false === strpos( $pattern, '*' ) ) {
			// Plain literal: tolerate a missing or extra trailing slash.
			$a = untrailingslashit( $subject );
			$b = untrailingslashit( $pattern );
			return $a === $b;
		}
		// Convert * into .* (other characters preserved exactly).
		$quoted = preg_quote( $pattern, '#' );
		$regex  = '#^' . str_replace( '\*', '.*', $quoted ) . '$#i';
		return (bool) preg_match( $regex, $subject );
	}

	private function get_current_path() {
		global $wp;
		if ( isset( $wp->request ) && is_string( $wp->request ) ) {
			return $wp->request;
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
			return is_string( $uri ) ? ltrim( $uri, '/' ) : '';
		}
		return '';
	}

	private function is_user_role_bypassed() {
		// Test mode forces its own admin-only gating logic; bypass rules
		// would only confuse that flow (and Test Mode is specifically meant
		// to let administrators preview the gate).
		if ( ! empty( $this->options['test_mode'] ) ) {
			return false;
		}
		// Dedicated admin bypass — on by default so site owners aren't gated
		// on their own pages. Capability check covers single-site admins and,
		// on multisite, super-admins too.
		if ( ! empty( $this->options['admin_bypass'] ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( empty( $this->options['bypass_logged_in'] ) ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user    = wp_get_current_user();
		$roles   = (array) $user->roles;
		$allowed = isset( $this->options['bypass_roles'] ) ? (array) $this->options['bypass_roles'] : array();
		return (bool) array_intersect( $roles, array_map( 'strval', $allowed ) );
	}

	private function is_user_agent_bypassed() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		if ( '' === $ua ) {
			return false;
		}

		$presets = isset( $this->options['bot_bypass_presets'] ) ? (array) $this->options['bot_bypass_presets'] : array();
		$signatures = $this->bot_preset_signatures();
		foreach ( $presets as $slug ) {
			$slug = sanitize_key( $slug );
			if ( ! isset( $signatures[ $slug ] ) ) {
				continue;
			}
			if ( false !== stripos( $ua, $signatures[ $slug ]['needle'] ) ) {
				return true;
			}
		}

		$custom = isset( $this->options['bot_bypass_custom'] ) ? (string) $this->options['bot_bypass_custom'] : '';
		if ( '' === $custom ) {
			return false;
		}
		$lines = preg_split( '/\r?\n/', $custom );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Accept user-agent substring matches and /regex/ entries.
			if ( strlen( $line ) >= 2 && '/' === $line[0] && '/' === substr( $line, -1 ) ) {
				$pattern = substr( $line, 1, -1 );
				if ( '' !== $pattern && @preg_match( '#' . $pattern . '#i', $ua ) ) {
					return true;
				}
			} elseif ( false !== stripos( $ua, $line ) ) {
				return true;
			}
		}
		return false;
	}

	public static function bot_preset_signatures() {
		return array(
			'googlebot'      => array( 'label' => 'Googlebot',         'needle' => 'Googlebot' ),
			'googleother'    => array( 'label' => 'Google (extended)', 'needle' => 'Google' ),
			'bingbot'        => array( 'label' => 'Bingbot',           'needle' => 'Bingbot' ),
			'slurp'          => array( 'label' => 'Yahoo Slurp',       'needle' => 'Slurp' ),
			'duckduckbot'    => array( 'label' => 'DuckDuckGo',        'needle' => 'DuckDuckBot' ),
			'baiduspider'    => array( 'label' => 'Baidu',             'needle' => 'Baiduspider' ),
			'yandexbot'      => array( 'label' => 'Yandex',            'needle' => 'YandexBot' ),
			'applebot'       => array( 'label' => 'Applebot',          'needle' => 'Applebot' ),
			'facebookbot'    => array( 'label' => 'Facebook',          'needle' => 'facebookexternalhit' ),
			'twitterbot'     => array( 'label' => 'Twitter',           'needle' => 'Twitterbot' ),
			'linkedinbot'    => array( 'label' => 'LinkedIn',          'needle' => 'LinkedInBot' ),
			'discordbot'     => array( 'label' => 'Discord',           'needle' => 'Discordbot' ),
			'telegrambot'    => array( 'label' => 'Telegram',          'needle' => 'TelegramBot' ),
			'openai'         => array( 'label' => 'GPTBot / OAI',      'needle' => 'GPTBot' ),
			'claudebot'      => array( 'label' => 'ClaudeBot',         'needle' => 'ClaudeBot' ),
			'perplexitybot'  => array( 'label' => 'PerplexityBot',     'needle' => 'PerplexityBot' ),
		);
	}

	private function is_post_protected() {
		// Per-post override supersedes site settings.
		if ( is_singular() ) {
			$override = AgeVerif_Meta::get_override( (int) get_queried_object_id() );
			if ( 'on' === $override ) {
				return true;
			}
			if ( 'off' === $override ) {
				return false;
			}
		}

		$enabled = isset( $this->options['enabled_post_types'] ) ? (array) $this->options['enabled_post_types'] : array();
		// Empty selection deliberately protects nothing.
		if ( empty( $enabled ) ) {
			return false;
		}

		if ( is_singular() ) {
			return in_array( (string) get_post_type(), $enabled, true );
		}

		if ( is_front_page() ) {
			return in_array( 'page', $enabled, true );
		}

		if ( is_home() ) {
			return in_array( 'post', $enabled, true ) || in_array( 'page', $enabled, true );
		}

		if ( is_post_type_archive() ) {
			$pt = get_query_var( 'post_type' );
			if ( is_array( $pt ) ) {
				$pt = reset( $pt );
			}
			return $pt && in_array( (string) $pt, $enabled, true );
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$queried = get_queried_object();
			if ( $queried && ! empty( $queried->taxonomy ) ) {
				$pts = $this->taxonomy_object_types( (string) $queried->taxonomy );
				if ( $pts ) {
					return (bool) array_intersect( $pts, $enabled );
				}
			}
			return false;
		}

		return false;
	}

	private function taxonomy_object_types( $taxonomy ) {
		global $wp_taxonomies;
		if ( ! isset( $wp_taxonomies[ $taxonomy ] ) ) {
			return array();
		}
		$pts = (array) $wp_taxonomies[ $taxonomy ]->object_type;
		return array_values( array_filter( $pts, 'post_type_exists' ) );
	}

	public function render_shortcode( $atts ) {
		if ( empty( $this->options['manual_start'] ) ) {
			return '';
		}
		// Ensure the checker is actually enqueued for this page.
		if ( ! wp_script_is( self::CHECKER_HANDLE, 'enqueued' )
			&& ! wp_script_is( self::CHECKER_HANDLE, 'registered' ) ) {
			$this->enqueue_scripts();
		}

		$atts = shortcode_atts(
			array(
				'label' => __( 'Verify age', 'ageverif-wordpress' ),
				'class' => 'ageverif-trigger button',
			),
			$atts,
			'ageverif'
		);

		$label = sanitize_text_field( $atts['label'] );
		$class = sanitize_html_class( $atts['class'] );
		$class = '' !== $class ? $class : 'ageverif-trigger button';

		$hint = '<!-- [ageverif]: Manual Start is off in Settings → AgeVerif, so this button does nothing. -->';

		return $hint . sprintf(
			'<button type="button" class="%s ageverif-trigger">%s</button>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

}
