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
	const OAUTH_HANDLE = 'ageverif-oauth';
	const OAUTH_POPOVER_HANDLE = 'ageverif-oauth-popover';
	const FRONTEND_CSS_HANDLE = 'ageverif-frontend';
	const OAUTH_SHORTCODE = 'ageverif_oauth';

	private $options;

	/** @var \AgeVerif\AgeVerif_OAuth|null Lazily loaded when OAuth is enabled. */
	private $oauth = null;

	public function __construct() {
		$stored        = get_option( 'ageverif_options', array() );
		$this->options = wp_parse_args( is_array( $stored ) ? $stored : array(), \AgeVerif\AgeVerif_Helper::defaults() );
		add_action( 'wp', array( $this, 'maybe_enqueue_checker' ) );
		add_action( 'wp', array( $this, 'maybe_enqueue_oauth_popover' ) );
		add_shortcode( 'ageverif', array( $this, 'render_shortcode' ) );
		add_shortcode( self::OAUTH_SHORTCODE, array( $this, 'render_oauth_shortcode' ) );
		// OAuth auto-gate on protected pages. Marked so a downstream
		// `wp_footer` hook can render the popover placeholder inline.
		// We render the actual underlying page so full-page caches can
		// pick it up normally; the popover JS hijacks the viewport.
		add_action( 'template_redirect', array( $this, 'maybe_arm_oauth_gate' ), 5 );
	}

	/**
	 * Internal flag flipped when an OAuth popover gate is armed for
	 * the current request. Used by maybe_render_oauth_popover_inline()
	 * to decide whether to print the modal markup into wp_footer.
	 *
	 * @var bool
	 */
	private $oauth_gate_armed = false;

	/**
	 * Cached authorize URL for the armed gate — the popover inline
	 * markup needs it to set the "Verify" button href.
	 *
	 * @var string
	 */
	private $oauth_gate_authorize_url = '';

	private function oauth() {
		if ( null === $this->oauth ) {
			$this->oauth = new \AgeVerif\AgeVerif_OAuth();
		}
		return $this->oauth;
	}

	public function maybe_enqueue_checker() {
		if ( ! $this->should_load_checker() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );
	}

	/**
	 * When OAuth is active, the in-page checker script is unnecessary: the
	 * visitor is sent to AgeVerif's OAuth endpoint instead. Calling
	 * `should_load_checker()` here would also pull the checker if OAuth
	 * is configured but no cookie is present, so we short-circuit first.
	 */
	private function oauth_is_active() {
		if ( empty( $this->options['oauth_enabled'] ) ) {
			return false;
		}
		$client_id = isset( $this->options['oauth_client_id'] )
			? trim( (string) $this->options['oauth_client_id'] )
			: '';
		return '' !== $client_id;
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

		// OAuth replaces checker.js entirely: when a visitor is OAuth-verified
		// we never pull the checker. Without verification, the OAuth auto-gate
		// (rendered by `maybe_render_oauth_gate()`) handles the next step.
		if ( $this->oauth_is_active() ) {
			return false;
		}

		return true;
	}

	/**
	 * Arm the OAuth2 popover gate for the current request.
	 *
	 * Conditions (all must hold):
	 *   1. OAuth2 is enabled and configured.
	 *   2. The current request is for a protected post/page.
	 *   3. The visitor isn't already verified via the OAuth cookie.
	 * Test Mode keeps this admin-only, mirroring the checker behavior.
	 *
	 * Hooked on `template_redirect`, priority 5, so we set our internal
	 * flag BEFORE the WP template loader renders the page. The page
	 * itself is allowed to render normally (full-page cache-friendly);
	 * the popover manifest + JS are emitted via wp_footer instead.
	 *
	 * Compared to the previous full-page-redirect implementation: we no
	 * longer `exit` after rendering. Visitors see their page render with
	 * a modal overlay on top of it, and the OAuth round-trip happens in
	 * `<dialog>`-hosted UX rather than a separate browser tab/page.
	 */
	public function maybe_arm_oauth_gate() {
		if ( ! $this->oauth_is_active() ) {
			return;
		}
		// Bypass URIs always short-circuit out of gating logic.
		if ( $this->is_excluded_url() ) {
			return;
		}
		// SEO safety: search-engine / AI / social-preview bot bypass must
		// apply to OAuth mode the same way it does to the in-page checker,
		// otherwise Googlebot et al. would see a noindex gate page instead
		// of the real HTML.
		if ( $this->is_user_agent_bypassed() ) {
			return;
		}
		// Role bypass (admins, logged-in roles) trumps the OAuth gate.
		if ( $this->is_user_role_bypassed() ) {
			return;
		}
		// Test Mode — only admins see the gate so we can preview.
		if ( ! empty( $this->options['test_mode'] ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Already verified → nothing to do.
		if ( $this->oauth()->is_verified() ) {
			return;
		}
		if ( ! $this->is_post_protected() ) {
			return;
		}

		// Build the authorize URL (sets a short-lived CSRF cookie as a
		// side effect) and stash it for the wp_footer hook.
		$return_url = $this->current_request_url();
		$this->oauth_gate_authorize_url = $this->oauth()->build_authorize_url( $return_url );
		$this->oauth_gate_armed         = true;

		// Emit the inline <dialog> on wp_footer.
		add_action( 'wp_footer', array( $this, 'render_oauth_popover_inline' ), 5 );
		// Mark this response as noindex,nofollow so search engines that
		// somehow see the gated page don't index the underlying content
		// while verification is unconfirmed. (Bot bypass normally prevents
		// this, but defense-in-depth.)
		add_action( 'wp_head', array( $this, 'print_oauth_noindex_tag' ), 1 );
	}

	/**
	 * Print a noindex meta tag when the OAuth gate is armed. Belt-and-
	 * braces alongside the bot-bypass list: if a crawler slips through,
	 * we still don't want the page indexed without confirmation.
	 */
	public function print_oauth_noindex_tag() {
		if ( ! $this->oauth_gate_armed ) {
			return;
		}
		echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
	}

	/**
	 * Enqueue popover assets when the gate is armed.
	 */
	public function maybe_enqueue_oauth_popover() {
		if ( ! $this->oauth_is_active() ) {
			return;
		}
		// Enqueue unconditionally (cheap CSS+JS) — the inline <dialog>
		// only shows up on the page when the gate is actually armed, so
		// visitors who don't trigger the gate never see a flash of modal
		// markup. The JS likewise bails when window.ageverifOauthPopover
		// isn't present.
		wp_register_style(
			self::FRONTEND_CSS_HANDLE,
			AGEVERIF_PLUGIN_URL . 'assets/frontend.css',
			array(),
			AGEVERIF_VERSION
		);
		wp_enqueue_style( self::FRONTEND_CSS_HANDLE );

		wp_register_script(
			self::OAUTH_POPOVER_HANDLE,
			AGEVERIF_PLUGIN_URL . 'js/ageverif-oauth-popover.js',
			array(),
			AGEVERIF_VERSION,
			true
		);
		wp_enqueue_script( self::OAUTH_POPOVER_HANDLE );
		wp_script_add_data( self::OAUTH_POPOVER_HANDLE, 'defer', true );
	}

	private function current_request_url() {
		global $wp;
		$path = isset( $wp->request ) ? (string) $wp->request : '';
		$url  = home_url( '/' );
		if ( '' !== $path ) {
			$url = home_url( '/' . ltrim( $path, '/' ) );
		}
		if ( isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] ) {
			$url = add_query_arg( array(), $url ); // strip existing args
			// Re-add the current query string to preserve params across the OAuth round-trip.
			$current = array();
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- return URL, CSRF covered by state cookie.
			foreach ( $_GET as $k => $v ) {
				if ( self::OAUTH_SHORTCODE === $k || \AgeVerif\AgeVerif_OAuth::CALLBACK_QUERY_VAR === $k ) {
					continue;
				}
				$current[ sanitize_key( $k ) ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			if ( $current ) {
				$url = add_query_arg( $current, $url );
			}
		}
		return $url;
	}

	/**
	 * Render the inline OAuth popover placeholder into wp_footer.
	 *
	 * The actual `<dialog>` element starts closed; the popover JS opens
	 * it on DOMContentLoaded. Keeping the markup in the DOM (rather than
	 * `document.createElement` from JS) means non-JS visitors / SEO
	 * crawlers that slip past the bot bypass can still see something
	 * sensibly readable in the markup — and the JS file ships with the
	 * correct CSS hooks pre-wired.
	 *
	 * The popover classes reuse styling from frontend.css so the modal
	 * matches the previous full-page gate while now overlaying the
	 * visitor's actual page.
	 */
	public function render_oauth_popover_inline() {
		if ( ! $this->oauth_gate_armed ) {
			return;
		}
		$label = $this->oauth()->button_label();
		$color = $this->oauth()->button_color();
		$bg    = \AgeVerif\AgeVerif_OAuth::button_color_css( $color );
		$fg    = ( 'blue' === $color ) ? '#ffffff' : ( ( 'white' === $color ) ? '#000000' : '#ffffff' );
		$home  = esc_url( home_url( '/' ) );
		?>
		<dialog id="ageverif-oauth-popover" class="ageverif-oauth-popover" aria-labelledby="ageverif-oauth-popover-title" aria-describedby="ageverif-oauth-popover-desc" data-ageverif-armed="1">
			<div class="ageverif-oauth-popover-panel" role="document">
				<span class="ageverif-oauth-brand ageverif-oauth-brand--inline"><?php esc_html_e( 'AgeVerif', 'ageverif-wordpress' ); ?></span>
				<h2 id="ageverif-oauth-popover-title" class="ageverif-oauth-popover-title"><?php esc_html_e( 'Age verification required', 'ageverif-wordpress' ); ?></h2>
				<p id="ageverif-oauth-popover-desc" class="ageverif-oauth-popover-desc"><?php esc_html_e( 'This page is protected by AgeVerif. Verify your age to continue.', 'ageverif-wordpress' ); ?></p>
				<div class="ageverif-oauth-popover-actions">
					<a class="ageverif-oauth-btn ageverif-oauth-trigger"
						id="ageverif-oauth-popover-cta"
						style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $fg ); ?>;"
						href="<?php echo esc_url( $this->oauth_gate_authorize_url ); ?>"
						rel="noopener">
						<?php echo esc_html( $label ); ?>
					</a>
				</div>
				<p class="ageverif-oauth-foot ageverif-oauth-foot--inline">
					<?php esc_html_e( 'Powered by AgeVerif.', 'ageverif-wordpress' ); ?>
					<a href="<?php echo $home; ?>" rel="home"><?php esc_html_e( 'Back to homepage', 'ageverif-wordpress' ); ?></a>
				</p>
			</div>
		</dialog>
		<script>
		(function(){
			if (!window.ageverifOauthPopover) {
				window.ageverifOauthPopover = {
					armed: <?php echo $this->oauth_gate_armed ? 'true' : 'false'; ?>,
					storageKey: 'ageverif_oauth_popover_dismissed',
					// Authorize URL is set on the <a> itself; the JS only
					// needs to know whether to show the modal on load.
					autoOpen: !(window.sessionStorage && window.sessionStorage.getItem('ageverif_oauth_popover_dismissed') === '1')
				};
			}
		})();
		</script>
		<?php
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

	/**
	 * Render the `[ageverif_oauth]` shortcode.
	 *
	 * Always available (regardless of manual_start toggle) so theme designers
	 * can drop a "Verify with AgeVerif" button on any page. The button links
	 * to the OAuth2 authorize URL; clicking it triggers a full-page redirect
	 * to AgeVerif and back through the plugin's callback endpoint.
	 *
	 * Usage:
	 *   [ageverif_oauth]
	 *   [ageverif_oauth label="Verify my age"]
	 *   [ageverif_oauth class="my-button-class"]
	 */
	public function render_oauth_shortcode( $atts ) {
		if ( ! $this->oauth_is_active() ) {
			return '<!-- [ageverif_oauth]: OAuth2 is not enabled or missing Client ID in Settings → AgeVerif. -->';
		}

		$atts = shortcode_atts(
			array(
				'label' => $this->oauth()->button_label(),
				'class' => 'ageverif-oauth-trigger',
			),
			$atts,
			self::OAUTH_SHORTCODE
		);

		$return = $this->current_request_url_for_shortcode();
		$url    = $this->oauth()->build_authorize_url( $return );

		$color = $this->oauth()->button_color();
		$bg    = \AgeVerif\AgeVerif_OAuth::button_color_css( $color );
		$fg    = ( 'blue' === $color ) ? '#ffffff' : ( ( 'white' === $color ) ? '#000000' : '#ffffff' );

		$label = sanitize_text_field( $atts['label'] );
		$class = sanitize_html_class( $atts['class'] );
		$class = '' !== $class ? $class : 'ageverif-oauth-trigger';

		return sprintf(
			'<a class="%s ageverif-oauth-trigger" style="background:%s;color:%s;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;line-height:1.2;" href="%s" rel="noopener">%s</a>',
			esc_attr( $class ),
			esc_attr( $bg ),
			esc_attr( $fg ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	private function current_request_url_for_shortcode() {
		// Re-use the gated version's logic for consistency.
		return $this->current_request_url();
	}

}
