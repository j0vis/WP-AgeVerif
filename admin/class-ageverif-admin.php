<?php
/**
 * Settings UI for the AgeVerif plugin.
 *
 * Sections (in render order):
 *   1. Connection                  – public keys
 *   2. Visibility                  – protected content types, excluded URLs, per-post meta hint
 *   3. Display                     – gate UI options (language, challenges, target, blur, ...)
 *   4. Bypass                      – role bypass, bot bypass list
 *   5. After verification          – underage redirect URL
 *   6. Mode                        – Test Mode
 *
 * Plus a Tools panel with a health-check button below the settings form
 * (rendered outside the form so we don't end up with nested <form> tags).
 */

namespace AgeVerif\Admin;

defined( 'ABSPATH' ) || exit;

class AgeVerif_Admin {

	const OPTION_GROUP     = 'ageverif_settings';
	const OPTION_NAME      = 'ageverif_options';
	const NOTICE_TRANSIENT = 'ageverif_admin_notice';

	private $had_v10_selection = false;

	public function __construct() {
		// Merge stored options with defaults so field renderers never read
		// an undefined index after an upgrade adds new keys.
		$stored              = get_option( self::OPTION_NAME, array() );
		$this->options       = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->get_defaults() );

		// Detect the v1.0 → v1.1 behavior change (empty selection no longer
		// means "protect the entire site"). Stored on the instance so that
		// render_settings_page() can read it across method scopes.
		$this->had_v10_selection = is_array( $stored )
			&& isset( $stored['enabled_post_types'] )
			&& array() === (array) $stored['enabled_post_types']
			&& ( ! empty( $stored['api_key'] ) || ! empty( $stored['test_key'] ) );

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_ageverif_health_check', array( $this, 'handle_health_check' ) );
		add_action( 'admin_post_ageverif_oauth_health_check', array( $this, 'handle_oauth_health_check' ) );
		add_action( 'admin_notices', array( $this, 'maybe_print_admin_notice' ) );
		add_action( 'update_option_' . self::OPTION_NAME, array( $this, 'clear_per_post_cache_hint' ), 10, 2 );
	}

	private $options;

	public function add_admin_menu() {
		add_options_page(
			__( 'AgeVerif Settings', 'ageverif-wordpress' ),
			__( 'AgeVerif', 'ageverif-wordpress' ),
			'manage_options',
			'ageverif',
			array( $this, 'render_settings_page' )
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_ageverif' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'ageverif-admin',
			AGEVERIF_PLUGIN_URL . 'assets/admin.css',
			array(),
			AGEVERIF_VERSION
		);
		wp_enqueue_script(
			'ageverif-admin-quickfix',
			AGEVERIF_PLUGIN_URL . 'js/ageverif-admin-quickfix.js',
			array(),
			AGEVERIF_VERSION,
			true
		);
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->get_defaults(),
			)
		);

		// 1. Onboarding — Quick Start screencast URL (admin-facing UX).
		add_settings_section(
			'ageverif_onboarding',
			__( 'Onboarding', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_onboarding' ),
			'ageverif'
		);
		$this->register_field( 'quickstart_video_url', __( 'Quick Start Video URL', 'ageverif-wordpress' ), 'field_quickstart_video_url' );

		// 2. Connection
		add_settings_section(
			'ageverif_connection',
			__( 'Connection', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_connection' ),
			'ageverif'
		);
		$this->register_field( 'api_key',  __( 'Public Live Key', 'ageverif-wordpress' ), 'field_api_key' );
		$this->register_field( 'test_key', __( 'Public Test Key', 'ageverif-wordpress' ), 'field_test_key' );

		// 3. Visibility
		add_settings_section(
			'ageverif_visibility',
			__( 'Visibility', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_visibility' ),
			'ageverif'
		);
		$this->register_field( 'enabled_post_types', __( 'Protected Content Types', 'ageverif-wordpress' ), 'field_enabled_post_types' );
		$this->register_field( 'excluded_urls',      __( 'Excluded URLs', 'ageverif-wordpress' ),       'field_excluded_urls' );

		// 4. Display
		add_settings_section(
			'ageverif_display',
			__( 'Display', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_display' ),
			'ageverif'
		);
		$this->register_field( 'language',     __( 'Language',           'ageverif-wordpress' ), 'field_language' );
		$this->register_field( 'challenges',   __( 'Verification Steps', 'ageverif-wordpress' ), 'field_challenges' );
		$this->register_field( 'display_mode', __( 'Display Mode',       'ageverif-wordpress' ), 'field_display_mode' );
		$this->register_field( 'closable',     __( 'Closable Gate',      'ageverif-wordpress' ), 'field_closable' );
		$this->register_field( 'manual_start', __( 'Manual Start',       'ageverif-wordpress' ), 'field_manual_start' );
		$this->register_field( 'content_blur', __( 'Content Blur',       'ageverif-wordpress' ), 'field_content_blur' );

		// 5. Bypass
		add_settings_section(
			'ageverif_bypass',
			__( 'Bypass', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_bypass' ),
			'ageverif'
		);
		$this->register_field( 'admin_bypass',      __( 'Always Bypass Administrators', 'ageverif-wordpress' ), 'field_admin_bypass' );
		$this->register_field( 'bypass_logged_in',  __( 'Bypass Logged-in Users', 'ageverif-wordpress' ), 'field_bypass_logged_in' );
		$this->register_field( 'bypass_roles',      __( 'Roles that Bypass',      'ageverif-wordpress' ), 'field_bypass_roles' );
		$this->register_field( 'bot_bypass_presets', __( 'Bot Bypass (known crawlers)', 'ageverif-wordpress' ), 'field_bot_bypass_presets' );
		$this->register_field( 'bot_bypass_custom', __( 'Bot Bypass (custom User-Agents)', 'ageverif-wordpress' ), 'field_bot_bypass_custom' );

		// 6. After verification
		add_settings_section(
			'ageverif_after',
			__( 'After Verification', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_after' ),
			'ageverif'
		);
		$this->register_field( 'underage_redirect_url', __( 'Underage Redirect URL', 'ageverif-wordpress' ), 'field_underage_redirect_url' );

		// 7. Mode
		add_settings_section(
			'ageverif_mode',
			__( 'Mode', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_mode' ),
			'ageverif'
		);
		$this->register_field( 'test_mode', __( 'Test Mode', 'ageverif-wordpress' ), 'field_test_mode' );

		// 8. OAuth2 (https://docs.ageverif.com/oauth2.html)
		add_settings_section(
			'ageverif_oauth',
			__( 'OAuth2', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro_oauth' ),
			'ageverif'
		);
		$this->register_field( 'oauth_enabled',       __( 'Enable OAuth2',                       'ageverif-wordpress' ), 'field_oauth_enabled' );
		$this->register_field( 'oauth_client_id',     __( 'OAuth2 Client ID',                    'ageverif-wordpress' ), 'field_oauth_client_id' );
		$this->register_field( 'oauth_client_secret', __( 'OAuth2 Client Secret',                'ageverif-wordpress' ), 'field_oauth_client_secret' );
		$this->register_field( 'oauth_flow',          __( 'Flow',                                'ageverif-wordpress' ), 'field_oauth_flow' );
		$this->register_field( 'oauth_button_label',  __( 'Button Label',                        'ageverif-wordpress' ), 'field_oauth_button_label' );
		$this->register_field( 'oauth_button_color',  __( 'Button Color',                        'ageverif-wordpress' ), 'field_oauth_button_color' );
		$this->register_field( 'oauth_language',      __( 'Language',                            'ageverif-wordpress' ), 'field_oauth_language' );
		$this->register_field( 'oauth_challenges',    __( 'Verification Steps (OAuth)',          'ageverif-wordpress' ), 'field_oauth_challenges' );
	}

	public function render_section_intro_oauth() {
		echo '<p>';
		esc_html_e( 'When OAuth2 is enabled, the standard in-page checker is bypassed and protected pages render normally with an in-page popover overlay on top. Visitors click "Verify with AgeVerif" in the modal and are sent to AgeVerif’s OAuth2 authorization endpoint. After verifying, AgeVerif redirects back through the REST callback URL below, the plugin exchanges the code for an access_token server-to-server, sets a signed verification cookie, and returns the visitor to the page they started on — the modal closes because the cookie is now present.', 'ageverif-wordpress' );
		echo '</p>';
		echo '<p class="description">';
		echo esc_html__( 'Your callback URL (also register it in the AgeVerif Webmasters Portal):', 'ageverif-wordpress' ) . '<br />';
		$callback_url = esc_html( \AgeVerif\AgeVerif_OAuth::callback_url() );
		echo '<span class="ageverif-callback">'
			. '<code id="ageverif-oauth-callback-url">' . $callback_url . '</code>'
			. '<button type="button" class="ageverif-copy-btn" id="ageverif-copy-callback" data-target="ageverif-oauth-callback-url" aria-describedby="ageverif-copy-callback-status">'
			. esc_html__( 'Copy', 'ageverif-wordpress' )
			. '</button>'
			. '<span id="ageverif-copy-callback-status" class="screen-reader-text" aria-live="polite" aria-atomic="true"></span>'
			. '</span>';
		echo '</p>';
		// Tiny inline script: copy callback URL to clipboard with feedback.
		// Vanilla JS, no jQuery, no extra deps. Falls back gracefully if the
		// Clipboard API is unavailable (very old browsers / http origin).
		?>
		<script>
		(function(){
			var btn = document.getElementById('ageverif-copy-callback');
			var code = document.getElementById('ageverif-oauth-callback-url');
			if (!btn || !code) { return; }
			var original = btn.textContent;
			var copyLabel = <?php echo wp_json_encode( __( 'Copy', 'ageverif-wordpress' ) ); ?>;
			var copiedLabel = <?php echo wp_json_encode( __( 'Copied!', 'ageverif-wordpress' ) ); ?>;
		var failedLabel = <?php echo wp_json_encode( __( 'Copy failed', 'ageverif-wordpress' ) ); ?>;
		var status = document.getElementById('ageverif-copy-callback-status');
			btn.addEventListener('click', function(){
				var value = code.textContent || code.innerText || '';
			function flash(cls, label){
				btn.classList.add(cls);
				btn.textContent = label;
				if (status) { status.textContent = label; }
				setTimeout(function(){
					btn.classList.remove(cls);
					btn.textContent = original || copyLabel;
					if (status) { status.textContent = ''; }
				}, 1400);
			}
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(value).then(
						function(){ flash('is-copied', copiedLabel); },
						function(){ fallback(); }
					);
				} else {
					fallback();
				}
				function fallback(){
					try {
						var range = document.createRange();
						range.selectNode(code);
						var sel = window.getSelection();
						sel.removeAllRanges();
						sel.addRange(range);
						var ok = document.execCommand && document.execCommand('copy');
						sel.removeAllRanges();
						flash(ok ? 'is-copied' : 'is-copy-failed', ok ? copiedLabel : failedLabel);
					} catch (e) { flash('is-copied', copyLabel); }
				}
			});
		})();
		</script>
		<?php
	}

	private function register_field( $key, $label, $method ) {
		add_settings_field( $key, $label, array( $this, $method ), 'ageverif', 'ageverif_' . $this->field_section( $key ) );
	}

	private function field_section( $key ) {
		$map = array(
			'quickstart_video_url'   => 'onboarding',
			'api_key'                => 'connection',
			'test_key'               => 'connection',
			'enabled_post_types'     => 'visibility',
			'excluded_urls'          => 'visibility',
			'language'               => 'display',
			'challenges'             => 'display',
			'display_mode'           => 'display',
			'closable'               => 'display',
			'manual_start'           => 'display',
			'content_blur'           => 'display',
			'bypass_logged_in'       => 'bypass',
			'admin_bypass'           => 'bypass',
			'bypass_roles'           => 'bypass',
			'bot_bypass_presets'     => 'bypass',
			'bot_bypass_custom'      => 'bypass',
			'underage_redirect_url'  => 'after',
			'test_mode'              => 'mode',
			'oauth_enabled'          => 'oauth',
			'oauth_client_id'        => 'oauth',
			'oauth_client_secret'    => 'oauth',
			'oauth_flow'             => 'oauth',
			'oauth_button_label'     => 'oauth',
			'oauth_button_color'     => 'oauth',
			'oauth_language'         => 'oauth',
			'oauth_challenges'       => 'oauth',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : 'connection';
	}

	public function render_section_intro() {
		// Fallback for any section that didn't get a custom intro.
	}

	public function render_section_intro_onboarding() {
		echo '<p class="ageverif-section-intro">';
		esc_html_e( 'Customize what new admins see first. The Quick Start panel above accepts a video URL — leave empty to hide the player and rely on the step-by-step text only.', 'ageverif-wordpress' );
		echo '</p>';
	}

	public function render_section_intro_connection() {
		echo '<p class="ageverif-section-intro">';
		esc_html_e( 'These are the credentials that tell AgeVerif which site is asking. Get them from the Webmasters Platform — paste the key here once and you don’t need to touch this section again.', 'ageverif-wordpress' );
		echo '</p>';
	}

	public function render_section_intro_visibility() {
		echo '<p class="ageverif-section-intro">';
		esc_html_e( 'Decide which content lives behind the age gate, and which URLs always stay public (for example your login page).', 'ageverif-wordpress' );
		echo '</p>';
	}

	public function render_section_intro_display() {
		echo '<p class="ageverif-section-intro">';
		esc_html_e( 'Fine-tune what visitors see when the gate appears — language, verification steps, and how the gate is presented.', 'ageverif-wordpress' );
		echo '</p>';
	}

	public function render_section_intro_bypass() {
		echo '<p class="ageverif-section-intro">';
		esc_html_e( 'Skip the gate for visitors you trust — your own team (admins / logged-in roles) and search-engine / AI crawlers that need to index your full content for SEO.', 'ageverif-wordpress' );
		echo '</p>';
	}

	public function render_section_intro_after() {
		echo '<p class="ageverif-section-intro">';
		esc_html_e( 'What happens after a visitor passes or fails the gate — for example, you can send failed visitors to a friendly “you must be 18+” page.', 'ageverif-wordpress' );
		echo '</p>';
	}

	public function render_section_intro_mode() {
		echo '<p class="ageverif-section-intro">';
		esc_html_e( 'Test Mode previews the gate for admins only — real visitors keep seeing your pages normally while you set things up. Turn it off only when you’re ready to go live.', 'ageverif-wordpress' );
		echo '</p>';
	}

/* ===== Defaults & sanitization ===== */

	private function get_defaults() {
		// Single source of truth — shared with \AgeVerif\AgeVerif_Frontend::__construct().
		return \AgeVerif\AgeVerif_Helper::defaults();
	}

	public function sanitize_options( $input ) {
		$out = $this->get_defaults();
		if ( ! is_array( $input ) ) {
			return $out;
		}

		if ( isset( $input['api_key'] ) )  { $out['api_key']  = sanitize_text_field( $input['api_key'] ); }
		if ( isset( $input['test_key'] ) ) { $out['test_key'] = sanitize_text_field( $input['test_key'] ); }

		if ( isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
			$out['enabled_post_types'] = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', $input['enabled_post_types'] ),
						static function ( $v ) { return '' !== $v; }
					)
				)
			);
		}

		if ( isset( $input['excluded_urls'] ) ) {
			$lines = preg_split( '/\r?\n/', (string) $input['excluded_urls'] );
			$clean = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$clean[] = esc_url_raw( $line );
				}
			}
			$out['excluded_urls'] = implode( "\n", $clean );
		}

		$languages = array( 'auto', 'en', 'de', 'es', 'fr', 'it', 'pt' );
		if ( isset( $input['language'] ) ) {
			$lang = sanitize_text_field( $input['language'] );
			$out['language'] = in_array( $lang, $languages, true ) ? $lang : 'auto';
		}

		// Only challenges confirmed in the AgeVerif docs (https://docs.ageverif.com).
		// Other values may be silently rejected by the verifier, so we keep this list tight.
		$challenges_allowed = array( 'selfie', 'ticket' );
		if ( isset( $input['challenges'] ) && is_array( $input['challenges'] ) ) {
			$clean = array();
			foreach ( $input['challenges'] as $c ) {
				$c = sanitize_key( $c );
				if ( in_array( $c, $challenges_allowed, true ) ) {
					$clean[] = $c;
				}
			}
			$out['challenges'] = array_values( array_unique( $clean ) );
		}

		$display_allowed = array( 'popup', 'tab', 'redirect' );
		if ( isset( $input['display_mode'] ) ) {
			$d = sanitize_key( $input['display_mode'] );
			$out['display_mode'] = in_array( $d, $display_allowed, true ) ? $d : 'popup';
		}

		foreach ( array( 'closable', 'manual_start', 'content_blur', 'admin_bypass', 'bypass_logged_in', 'test_mode' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] ) ? 1 : 0;
		}

		if ( isset( $input['bypass_roles'] ) && is_array( $input['bypass_roles'] ) ) {
			$roles = array();
			foreach ( $input['bypass_roles'] as $r ) {
				$key = sanitize_key( $r );
				if ( '' !== $key ) {
					$roles[] = $key;
				}
			}
			$out['bypass_roles'] = array_values( array_unique( $roles ) );
		}

		$presets_available = array_keys( $this->bot_preset_signatures() );
		if ( isset( $input['bot_bypass_presets'] ) && is_array( $input['bot_bypass_presets'] ) ) {
			$clean = array();
			foreach ( $input['bot_bypass_presets'] as $slug ) {
				$slug = sanitize_key( $slug );
				if ( in_array( $slug, $presets_available, true ) ) {
					$clean[] = $slug;
				}
			}
			$out['bot_bypass_presets'] = array_values( array_unique( $clean ) );
		}

		if ( isset( $input['bot_bypass_custom'] ) ) {
			$lines = preg_split( '/\r?\n/', (string) $input['bot_bypass_custom'] );
			$clean = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$clean[] = sanitize_text_field( $line );
				}
			}
			$out['bot_bypass_custom'] = implode( "\n", $clean );
		}

		if ( isset( $input['underage_redirect_url'] ) ) {
			$url = trim( (string) $input['underage_redirect_url'] );
			$out['underage_redirect_url'] = ( '' === $url ) ? '' : esc_url_raw( $url );
		}

		if ( isset( $input['quickstart_video_url'] ) ) {
			$url = trim( (string) $input['quickstart_video_url'] );
			// Validate as an actual URL before accepting. Empty = disabled.
			$out['quickstart_video_url'] = ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) ? '' : esc_url_raw( $url );
		}

		// ----- OAuth2 -----
		$out['oauth_enabled'] = ! empty( $input['oauth_enabled'] ) ? 1 : 0;
		if ( isset( $input['oauth_client_id'] ) ) {
			$out['oauth_client_id'] = sanitize_text_field( $input['oauth_client_id'] );
		}
		// Client Secret — write-only. Critical: browsers submit an empty string
		// for `<input type=password>` whenever the admin saves the form without
		// re-pasting the secret, so we MUST treat empty input as "no change"
		// and keep the previously stored secret. An explicit "Clear" checkbox
		// in the form is required to actually erase it (NOT exposed in v1.2.0 —
		// this admin UI is intentionally write-only; reset the secret by
		// regenerating it in the Webmasters Portal and pasting the new value).
		if ( isset( $input['oauth_client_secret'] ) ) {
			$submitted = trim( (string) $input['oauth_client_secret'] );
			if ( '' === $submitted ) {
				$existing = get_option( self::OPTION_NAME, array() );
				$out['oauth_client_secret'] = is_array( $existing ) && isset( $existing['oauth_client_secret'] )
					? (string) $existing['oauth_client_secret']
					: '';
			} else {
				$out['oauth_client_secret'] = $submitted;
			}
		}

		$oauth_flows = array( 'checker', 'login' );
		if ( isset( $input['oauth_flow'] ) ) {
			$flow = sanitize_key( $input['oauth_flow'] );
			$out['oauth_flow'] = in_array( $flow, $oauth_flows, true ) ? $flow : 'checker';
		}

		if ( isset( $input['oauth_button_label'] ) ) {
			$out['oauth_button_label'] = sanitize_text_field( (string) $input['oauth_button_label'] );
		}

		$oauth_colors = array( 'blue', 'white', 'black' );
		if ( isset( $input['oauth_button_color'] ) ) {
			$color = sanitize_key( $input['oauth_button_color'] );
			$out['oauth_button_color'] = in_array( $color, $oauth_colors, true ) ? $color : 'blue';
		}

		if ( isset( $input['oauth_language'] ) ) {
			$lang = sanitize_text_field( $input['oauth_language'] );
			$out['oauth_language'] = in_array( $lang, $languages, true ) ? $lang : 'auto';
		}

		// OAuth challenges — same per-validator allow-list as the checker flow
		// (`selfie`, `ticket`). The AgeVerif docs list more, but the validator
		// only accepts these by default; expand here when the validator accepts new ones.
		$oauth_challenges_allowed = array( 'selfie', 'ticket' );
		if ( isset( $input['oauth_challenges'] ) && is_array( $input['oauth_challenges'] ) ) {
			$clean = array();
			foreach ( $input['oauth_challenges'] as $c ) {
				$c = sanitize_key( $c );
				if ( in_array( $c, $oauth_challenges_allowed, true ) ) {
					$clean[] = $c;
				}
			}
			$out['oauth_challenges'] = array_values( array_unique( $clean ) );
		}

		return $out;
	}

	public function clear_per_post_cache_hint( $old, $new ) {
		// Settings changed – nothing to flush right now, but a future hook
		// for cache plugins could live here.
	}

	/* ===== Field renderers ===== */

	public function field_api_key() {
		?>
		<input type="text" name="ageverif_options[api_key]" id="ageverif-api-key"
			value="<?php echo esc_attr( $this->options['api_key'] ); ?>"
			class="regular-text" autocomplete="off" spellcheck="false"
			placeholder="<?php esc_attr_e( 'e.g. DtD0ad9ZMcKJBd9Ojh8D8Q0ELh2eSKwb0f1SzN7E', 'ageverif-wordpress' ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Your site’s Public Live Key from the Webmasters Portal. This key only identifies your site to AgeVerif.', 'ageverif-wordpress' ); ?>
			<a href="https://webmasters.ageverif.com" target="_blank" rel="noopener">
				<?php esc_html_e( 'Open Webmasters Portal →', 'ageverif-wordpress' ); ?>
			</a>
		</p>
		<?php
	}

	public function field_test_key() {
		?>
		<input type="text" name="ageverif_options[test_key]" id="ageverif-test-key"
			value="<?php echo esc_attr( $this->options['test_key'] ); ?>"
			class="regular-text" autocomplete="off" spellcheck="false"
			placeholder="<?php esc_attr_e( 'Test key from the Webmasters Portal', 'ageverif-wordpress' ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Used when Test Mode is enabled. Visitors see the gate as a sandbox preview.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_enabled_post_types() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="ageverif-grid" id="ageverif-protection-types">
			<?php foreach ( $post_types as $pt ) :
				if ( 'attachment' === $pt->name ) {
					continue;
				}
				$checked = in_array( $pt->name, (array) $this->options['enabled_post_types'], true );
				?>
				<label>
					<input type="checkbox"
						name="ageverif_options[enabled_post_types][]"
						value="<?php echo esc_attr( $pt->name ); ?>"
						<?php checked( $checked ); ?> />
					<?php echo esc_html( $pt->label ); ?>
					<code><?php echo esc_html( $pt->name ); ?></code>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'Select which content types trigger the age gate. Leave all unchecked to leave nothing gated.', 'ageverif-wordpress' ); ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: post editor URL placeholder */
				wp_kses(
					__( 'Need a finer override per piece of content? Tick the “Age Gate” meta box in the post editor.', 'ageverif-wordpress' ),
					array()
				)
			);
			?>
		</p>
		<?php
	}

	public function field_excluded_urls() {
		$tip = __( 'Use this to SKIP the gate on specific URLs — for example /login/ or /contact/. Add one URL or path per line, with a trailing * for wildcards (e.g. /shop/category/*).', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<textarea name="ageverif_options[excluded_urls]" rows="6" class="large-text code"><?php
			echo esc_textarea( $this->options['excluded_urls'] ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One URL (or path) per line. Paths like /sample-page/ match that URL only. Use a trailing * for glob matches, e.g. /shop/category/* matches any product.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_language() {
		$options = array(
			'auto' => __( 'Auto (browser)', 'ageverif-wordpress' ),
			'en'   => __( 'English',      'ageverif-wordpress' ),
			'de'   => __( 'Deutsch',      'ageverif-wordpress' ),
			'es'   => __( 'Español',      'ageverif-wordpress' ),
			'fr'   => __( 'Français',     'ageverif-wordpress' ),
			'it'   => __( 'Italiano',     'ageverif-wordpress' ),
			'pt'   => __( 'Português',    'ageverif-wordpress' ),
		);
		?>
		<select name="ageverif_options[language]">
			<?php foreach ( $options as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $this->options['language'], $code ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Forces the gate UI to a specific language. Affects UI text only – does not change your site’s visitor language.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_challenges() {
		$options = array(
			'selfie' => __( 'Selfie / face match',  'ageverif-wordpress' ),
			'ticket' => __( 'Verification ticket',  'ageverif-wordpress' ),
		);
		$tip = __( 'Optional: limit which steps the visitor sees. Leave empty to let AgeVerif pick automatically based on visitor region. If you tick just one, the verifier lands directly on that step.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<div class="ageverif-grid">
			<?php foreach ( $options as $key => $label ) :
				$checked = in_array( $key, (array) $this->options['challenges'], true );
				?>
				<label>
					<input type="checkbox"
						name="ageverif_options[challenges][]"
						value="<?php echo esc_attr( $key ); ?>"
						<?php checked( $checked ); ?> />
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'Optional: restrict the verifier to only the selected steps. Leave empty to let AgeVerif pick based on the visitor region.', 'ageverif-wordpress' ); ?>
			<a href="https://docs.ageverif.com" target="_blank" rel="noopener">
				<?php esc_html_e( 'See supported challenges →', 'ageverif-wordpress' ); ?>
			</a>
		</p>
		<?php
	}

	public function field_display_mode() {
		$options = array(
			'popup'    => __( 'Popup on the same page', 'ageverif-wordpress' ),
			'tab'      => __( 'New browser tab',       'ageverif-wordpress' ),
			'redirect' => __( 'Full redirect to AgeVerif', 'ageverif-wordpress' ),
		);
		$tip = __( 'Popup is the most common — a small modal that pops up over your content. Tab sends visitors to AgeVerif in a new tab (they come back when verified). Redirect does a full-page bounce to AgeVerif then back.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<select name="ageverif_options[display_mode]">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $this->options['display_mode'], $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Pick the experience for the visitor.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_closable() {
		?>
		<label>
			<input type="checkbox" name="ageverif_options[closable]" value="1" <?php checked( $this->options['closable'] ); ?> />
			<?php esc_html_e( 'Allow visitors to dismiss the gate without verifying', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Useful for soft-warning contexts. Pair with an Underage Redirect URL to keep the visitor from reaching protected content.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_manual_start() {
		$tip = __( 'Tick this if you don’t want the gate to immediately appear. Instead, add an [ageverif] shortcode or a button with the .ageverif-trigger class anywhere in your page or template. Clicking it opens the gate on demand.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<label>
			<input type="checkbox" name="ageverif_options[manual_start]" value="1" <?php checked( $this->options['manual_start'] ); ?> />
			<?php esc_html_e( 'Don’t auto-open the gate on page load', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description">
			<?php
			echo wp_kses(
				__( 'Use the <code>[ageverif]</code> shortcode or the <code>.ageverif-trigger</code> CSS class to trigger the gate on demand (e.g. on a button click).', 'ageverif-wordpress' ),
				array( 'code' => array() )
			);
			?>
		</p>
		<?php
	}

	public function field_content_blur() {
		$tip = __( 'Fades page content behind a blur until AgeVerif confirms the visitor’s age. Great for adult sites where the gate must not appear over sharp imagery. Cleared the moment verification succeeds.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<label>
			<input type="checkbox" name="ageverif_options[content_blur]" value="1" <?php checked( $this->options['content_blur'] ); ?> />
			<?php esc_html_e( 'Blur page content until the visitor is verified', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Applies a CSS blur to the page; cleared on the ageverif:success event.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_admin_bypass() {
		?>
		<label>
			<input type="checkbox" name="ageverif_options[admin_bypass]" value="1" <?php checked( $this->options['admin_bypass'] ); ?> />
			<?php esc_html_e( 'Always skip the gate for logged-in administrators', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description">
			<?php
			echo wp_kses(
				__( 'Recommended for live sites so you aren’t gated on your own pages. Suppressed automatically by <strong>Test Mode</strong> so you can still preview the gate as an admin before going live.', 'ageverif-wordpress' ),
				array( 'strong' => array() )
			);
			?>
		</p>
		<?php
	}

	public function field_bypass_logged_in() {
		?>
		<label>
			<input type="checkbox" name="ageverif_options[bypass_logged_in]" value="1" <?php checked( $this->options['bypass_logged_in'] ); ?> />
			<?php esc_html_e( 'Skip the gate entirely for logged-in users matching the roles below', 'ageverif-wordpress' ); ?>
		</label>
		<?php
	}

	public function field_bypass_roles() {
		if ( ! function_exists( 'wp_roles' ) ) {
			return;
		}
		$all_roles = wp_roles()->role_objects;
		?>
		<div class="ageverif-grid">
			<?php foreach ( $all_roles as $role_key => $role_obj ) :
				$checked = in_array( $role_key, (array) $this->options['bypass_roles'], true );
				?>
				<label>
					<input type="checkbox"
						name="ageverif_options[bypass_roles][]"
						value="<?php echo esc_attr( $role_key ); ?>"
						<?php checked( $checked ); ?> />
					<?php echo esc_html( translate_user_role( $role_obj->name ) ); ?>
					<code><?php echo esc_html( $role_key ); ?></code>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<strong><?php esc_html_e( 'Heads up:', 'ageverif-wordpress' ); ?></strong>
			<?php esc_html_e( 'if you leave the list empty while “Bypass Logged-in Users” is on, no logged-in user is bypassed. Pick at least one role, or uncheck the toggle above.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_bot_bypass_presets() {
		$signatures = $this->bot_preset_signatures();
		$tip = __( 'These are search-engine and AI crawlers. Ticking them means these bots see your full HTML — important for SEO. Leave all ticked unless you want a specific bot to see the gate too.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<div class="ageverif-grid">
			<?php foreach ( $signatures as $slug => $info ) :
				$checked = in_array( $slug, (array) $this->options['bot_bypass_presets'], true );
				?>
				<label>
					<input type="checkbox"
						name="ageverif_options[bot_bypass_presets][]"
						value="<?php echo esc_attr( $slug ); ?>"
						<?php checked( $checked ); ?> />
					<?php echo esc_html( $info['label'] ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<?php
			echo wp_kses(
				__( 'All major search engines (Google, Bing, Yahoo, DuckDuckGo, <strong>Baidu, Yandex, Applebot</strong>) and AI crawlers (GPTBot, ClaudeBot, PerplexityBot) plus social-preview bots (Facebook, Twitter, LinkedIn, Discord, Telegram) are enabled by default. They are detected by User-Agent substring and bypass the gate server-side so they always see the full HTML. Untick any you don’t want.', 'ageverif-wordpress' ),
				array( 'strong' => array() )
			);
			?>
		</p>
		<?php
	}

	public function field_bot_bypass_custom() {
		?>
		<textarea name="ageverif_options[bot_bypass_custom]" rows="5" class="large-text code"><?php
			echo esc_textarea( $this->options['bot_bypass_custom'] ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One User-Agent match per line. Plain lines match as case-insensitive substrings; lines wrapped in /slashes/ are treated as case-insensitive regex.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_quickstart_video_url() {
		$tip = __( 'Paste a YouTube, Vimeo, Loom, or self-hosted MP4 URL here. Accepts any embeddable video URL. Leave empty to hide the player and rely on the step-by-step text only. The iframe is sandboxed and lazy-loaded so the admin page itself stays fast.', 'ageverif-wordpress' );
		$value = isset( $this->options['quickstart_video_url'] ) ? (string) $this->options['quickstart_video_url'] : '';
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<input type="url" name="ageverif_options[quickstart_video_url]" id="ageverif-quickstart-video-url"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text" placeholder="https://www.youtube.com/watch?v=…"
			pattern="https?://.*" autocomplete="off" spellcheck="false" />
		<p class="description">
			<?php esc_html_e( 'Optional. Embeds in the Quick Start panel (top of this page) so visual learners can follow along. Leave blank to hide the player.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_underage_redirect_url() {
		$tip = __( 'Where to send visitors who fail or close the gate — a friendly “you must be 18+ to enter” page is better than a dead end. Works alongside “Closable Gate” above.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<input type="url" name="ageverif_options[underage_redirect_url]"
			value="<?php echo esc_attr( $this->options['underage_redirect_url'] ); ?>"
			class="regular-text" placeholder="https://example.com/blocked/"
			pattern="https?://.*" />
		<p class="description">
			<?php esc_html_e( 'Where visitors go when they fail / close the gate. Requires “Allow visitors to dismiss the gate” or the verifier reporting an age-verification failure.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_test_mode() {
		?>
		<label>
			<input id="ageverif-test-mode" type="checkbox" name="ageverif_options[test_mode]" value="1" <?php checked( $this->options['test_mode'] ); ?> />
			<?php esc_html_e( 'Enable Test Mode', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Uses the Test Key and shows the gate only to logged-in administrators. Use to preview before going live.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	/* ===== OAuth2 field renderers ===== */

	public function field_oauth_enabled() {
		?>
		<label>				<input id="ageverif-oauth-enabled" type="checkbox" name="ageverif_options[oauth_enabled]" value="1" <?php checked( $this->options['oauth_enabled'] ); ?> />
			<?php esc_html_e( 'Use AgeVerif OAuth2 instead of the in-page checker', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, the in-page AgeVerif checker script is disabled and visitors verify via the OAuth2 Authorization Code flow instead.', 'ageverif-wordpress' ); ?>
			<?php
			echo wp_kses(
				__( 'See <a href="https://docs.ageverif.com/oauth2.html" target="_blank" rel="noopener">docs.ageverif.com/oauth2.html</a>.', 'ageverif-wordpress' ),
				array( 'a' => array( 'href' => array(), 'target' => array( '_blank' ), 'rel' => array( 'noopener' ) ) )
			);
			?>
		</p>
		<?php
	}

	public function field_oauth_client_id() {
		$tip = __( 'Look in the AgeVerif Webmasters Platform → your website → “Enable OAuth2”. It looks like V61LHMuuwahgYDcGnagso. Anyone can see this ID — it only identifies your site.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<input type="text" name="ageverif_options[oauth_client_id]" id="ageverif-oauth-client-id"
			value="<?php echo esc_attr( $this->options['oauth_client_id'] ); ?>"
			class="regular-text" autocomplete="off" spellcheck="false"
			placeholder="<?php esc_attr_e( 'e.g. V61LHMuuwahgYDcGnagso', 'ageverif-wordpress' ); ?>" />
		<p class="description">
			<?php esc_html_e( 'OAuth2 Live Client ID from the AgeVerif Webmasters Platform (must have the OAuth2 feature enabled on your site).', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_oauth_client_secret() {
		// Write-only field: we never echo the saved value back. The control
		// placeholder is the only hint — and admins are expected to know
		// their own secret.
		$has_secret = ! empty( $this->options['oauth_client_secret'] );
		$tip = __( 'A long random string (think: a 50+ character password). Find it next to your Client ID in the Webmasters Platform. We never echo the saved value back — leave blank to keep the existing secret.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<input type="password" name="ageverif_options[oauth_client_secret]" id="ageverif-oauth-client-secret"
			value="" autocomplete="new-password" spellcheck="false" class="regular-text"
			placeholder="<?php echo esc_attr( $has_secret ? '•••••••• (saved)' : __( 'Paste your Client Secret', 'ageverif-wordpress' ) ); ?>" />
		<button type="button" class="button-link" id="ageverif-oauth-secret-toggle">
			<?php esc_html_e( 'Show', 'ageverif-wordpress' ); ?>
		</button>
		<p class="description">
			<?php
			if ( $has_secret ) {
				esc_html_e( 'A Client Secret is saved. Leave blank to keep it, or replace it by typing a new value.', 'ageverif-wordpress' );
			} else {
				esc_html_e( 'Paste your OAuth2 Client Secret. Stored as plain text in the option table — keep your database backups private.', 'ageverif-wordpress' );
			}
			?>
		</p>
		<script>
		(function(){
			var btn = document.getElementById('ageverif-oauth-secret-toggle');
			var inp = document.getElementById('ageverif-oauth-client-secret');
			if (!btn || !inp) { return; }
			btn.addEventListener('click', function(e){
				e.preventDefault();
				inp.type = (inp.type === 'password') ? 'text' : 'password';
				btn.textContent = (inp.type === 'password')
					? <?php echo wp_json_encode( __( 'Show', 'ageverif-wordpress' ) ); ?>
					: <?php echo wp_json_encode( __( 'Hide', 'ageverif-wordpress' ) ); ?>;
			});
		})();
		</script>
		<?php
	}

	public function field_oauth_flow() {
		$options = array(
			'checker' => __( 'Age verification (/oauth2/checker)', 'ageverif-wordpress' ),
			'login'   => __( 'Sign in (/oauth2/login)',           'ageverif-wordpress' ),
		);
		$tip = __( '“Age verification” sends visitors to a page that runs the age check directly. “Sign in” sends them to a login page that can also verify. Both work the same way for protecting content — only the landing page is different.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<select name="ageverif_options[oauth_flow]" id="ageverif-oauth-flow">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $this->options['oauth_flow'], $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'AgeVerif supports two interchangeable endpoints: the age-verification landing page and the sign-in landing page. Both can verify ages; the only difference is the landing page.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_oauth_button_label() {
		?>			<input id="ageverif-oauth-button-label" type="text" name="ageverif_options[oauth_button_label]"
			value="<?php echo esc_attr( $this->options['oauth_button_label'] ); ?>"
			class="regular-text"
			placeholder="<?php echo esc_attr( \AgeVerif\AgeVerif_OAuth::default_button_label_from_options( $this->options ) ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Custom button text. Leave empty for AgeVerif’s recommended default (“Verify with AgeVerif” or “Continue with AgeVerif” depending on the selected flow).', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_oauth_button_color() {
		$options = array(
			'blue'  => __( 'AgeVerif Blue (#004db3)', 'ageverif-wordpress' ),
			'white' => __( 'White (#ffffff)',           'ageverif-wordpress' ),
			'black' => __( 'Black (#000000)',           'ageverif-wordpress' ),
		);
		$tip = __( 'Pick a button color from the AgeVerif Brand Guidelines. Pair the color with the matching AgeVerif logo (download from the Brand guidelines page). Neutral grays like #e0e0e0 are also fine.', 'ageverif-wordpress' );
		?>
		<?php $this->render_tooltip( $tip ); ?>
		<select name="ageverif_options[oauth_button_color]" id="ageverif-oauth-button-color">
			<?php foreach ( $options as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $this->options['oauth_button_color'], $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Pick a background from the AgeVerif Brand Guidelines. Neutral grays are also acceptable when paired with the matching AgeVerif logo.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_oauth_language() {
		$options = array(
			'auto' => __( 'Auto (browser)',  'ageverif-wordpress' ),
			'en'   => __( 'English',          'ageverif-wordpress' ),
			'de'   => __( 'Deutsch',          'ageverif-wordpress' ),
			'es'   => __( 'Español',          'ageverif-wordpress' ),
			'fr'   => __( 'Français',         'ageverif-wordpress' ),
			'it'   => __( 'Italiano',         'ageverif-wordpress' ),
			'pt'   => __( 'Português',        'ageverif-wordpress' ),
		);
		?>
		<select name="ageverif_options[oauth_language]" id="ageverif-oauth-language">
			<?php foreach ( $options as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $this->options['oauth_language'], $code ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Forces the AgeVerif landing page to a specific language, regardless of the visitor’s browser preference.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_oauth_challenges() {
		$options = array(
			'selfie' => __( 'Selfie / face match',  'ageverif-wordpress' ),
			'ticket' => __( 'Verification ticket',  'ageverif-wordpress' ),
		);
		?>
		<div class="ageverif-grid" id="ageverif-oauth-challenges">
			<?php foreach ( $options as $key => $label ) :
				$checked = in_array( $key, (array) $this->options['oauth_challenges'], true );
				?>
				<label>
					<input type="checkbox"
						name="ageverif_options[oauth_challenges][]"
						value="<?php echo esc_attr( $key ); ?>"
						<?php checked( $checked ); ?> />
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="description">
			<?php esc_html_e( 'Optional: restrict the OAuth2 verification to only the selected steps. Leave empty to let AgeVerif pick based on visitor region.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	/* ===== Admin notice + health check ===== */

	public function maybe_print_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( self::NOTICE_TRANSIENT );
		$type    = isset( $notice['type'] ) ? sanitize_html_class( $notice['type'] ) : 'info';
		$class   = 'notice notice-' . $type . ' is-dismissible';
		$message = wp_kses_post( $notice['message'] );
		printf( '<div class="%s"><p>%s</p></div>', esc_attr( $class ), $message );
	}

	private function add_admin_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT,
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Render an inline tooltip ? icon next to a field. CSS-only, no JS.
	 *
	 * Usage: `<?php $this->render_tooltip( 'explanation…' ); ?>`
	 *
	 * Renders a small dotted-underline "?" the admin can hover or focus to
	 * reveal a plain-language explanation of what the field does. We don't
	 * gate on a settings toggle, so this works for every admin on every load.
	 */
	private function render_tooltip( $text ) {
		if ( '' === $text ) {
			return;
		}
		?>
		<span class="ageverif-tip" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'More information', 'ageverif-wordpress' ); ?>">
			<span class="ageverif-tip-icon" aria-hidden="true">?</span>
			<span class="ageverif-tip-body" role="tooltip"><?php echo esc_html( $text ); ?></span>
		</span>
		<?php
	}

	/**
	 * Render a span that turns a jargon word inside body copy into an
	 * inline tooltip with the same plain-language explanation the
	 * Glossary uses. Saves the admin from context-switching to the
	 * Glossary for every term.
	 *
	 * @param string $term  Visible word (e.g. "OAuth2").
	 * @param string $tip   Tooltip body (matches the Glossary entry).
	 */
	private function render_jargon_tip( $term, $tip ) {
		$allowed = array(
			'span'   => array( 'class' => array(), 'tabindex' => array(), 'role' => array(), 'aria-label' => array() ),
		);
		return wp_kses(
			'<span class="ageverif-tip" tabindex="0" role="button" aria-label="' . esc_attr__( 'More information', 'ageverif-wordpress' ) . '">' .
				esc_html( $term ) .
				'<span class="ageverif-tip-body" role="tooltip">' . esc_html( $tip ) . '</span>' .
			'</span>',
			$allowed
		);
	}

	/**
	 * Build the Quick Start intro paragraph with jargon hover tooltips
	 * embedded inline. Same terms used in the Glossary, so a first-time
	 * admin can read the Quick Start without leaving the page.
	 */
	private function quick_start_paragraph() {
		$tipline = '<span class="ageverif-tip-inline" aria-hidden="true">?</span>';
		$oauth   = $this->render_jargon_tip(
			__( 'OAuth2', 'ageverif-wordpress' ),
			__( 'OAuth2: a standard way for your site and AgeVerif to talk to each other. With OAuth2, visitors click a button to verify directly on AgeVerif, then come back to your site. The plugin stores a small cookie in their browser so they don’t have to verify again for the next hour.', 'ageverif-wordpress' )
		);
		$secret  = $this->render_jargon_tip(
			__( 'Client Secret', 'ageverif-wordpress' ),
			__( 'Client Secret: a long random string like a password for this site to talk to AgeVerif. Stored in the WordPress options table. Keep your database backups private.', 'ageverif-wordpress' )
		);

		$text = sprintf(
			/* translators: 1: jargon tip word OAuth2, 2: jargon tip word Client Secret, 3: inline ? glyph */
			__( 'New here? Follow the steps below in order. The signs %1$s and %2$s are jargon — hover or tap them for a one-line explanation. Every field on this page has its own %3$s tooltip for deeper details. You do not need API experience to set this up.', 'ageverif-wordpress' ),
			$oauth,
			$secret,
			$tipline
		);

		$allowed = array(
			'span' => array(
				'class'      => array(),
				'tabindex'   => array(),
				'role'       => array(),
				'aria-label' => array(),
				'aria-hidden' => array(),
			),
		);
		return wp_kses( $text, $allowed );
	}

	/**
	 * Live status widget — auto-detects common misconfiguration patterns
	 * and surfaces them as actionable callouts near the top of the page.
	 * The whole point is to cut support tickets for "I saved but nothing
	 * happened" tickets by telling the admin exactly what's wrong before
	 * they reach for the form.
	 *
	 * Rules fired today:
	 *  - No Public Live Key and OAuth not configured       → red
	 *  - Protected Content Types is empty                  → red
	 *  - OAuth enabled but no Client ID or no Client Secret → red
	 *  - OAuth enabled but Test Mode is ON                → yellow (info)
	 *  - All keys + types present, no warnings            → green (ready)
	 */
	private function render_status_widget() {
		$has_live_key       = '' !== (string) $this->options['api_key'];
		$has_oauth          = ! empty( $this->options['oauth_enabled'] );
		$has_oauth_id       = '' !== trim( (string) $this->options['oauth_client_id'] );
		$has_oauth_secret   = '' !== (string) $this->options['oauth_client_secret'];
		$has_protected      = ! empty( $this->options['enabled_post_types'] );
		$test_mode_on       = ! empty( $this->options['test_mode'] );

		$items = array();

		// Critical: no credentials at all on either path.
		if ( ! $has_live_key && ( ! $has_oauth || ! $has_oauth_id ) ) {
			$items[] = array(
				'type'    => 'error',
				'message' => __( 'No credentials configured. Add your Public Live Key under Connection, or enable OAuth2 with a Client ID below.', 'ageverif-wordpress' ),
				'anchor'  => '#ageverif-api-key',
			);
		}

		// Critical: protected types empty (gate never appears).
		if ( ! $has_protected ) {
			$items[] = array(
				'type'    => 'error',
				'message' => __( 'No Protected Content Types are ticked. The gate will not appear anywhere until you tick at least one type under Visibility.', 'ageverif-wordpress' ),
				'anchor'  => '#ageverif-protection-types',
			);
		}

		// OAuth half-configured: ID but no secret, or marked enabled but no ID.
		if ( $has_oauth && ( ! $has_oauth_id || ! $has_oauth_secret ) ) {
			$items[] = array(
				'type'    => 'error',
				'message' => __( 'OAuth2 is enabled but incomplete. Make sure Client ID and Client Secret are both filled in.', 'ageverif-wordpress' ),
				'anchor'  => '#ageverif-oauth-enabled',
			);
		}
		if ( ! $has_oauth && $has_oauth_id && ! $has_oauth_secret ) {
			$items[] = array(
				'type'    => 'warning',
				'message' => __( 'You pasted a Client ID but OAuth is not enabled, so it isn’t doing anything yet. Either tick Enable OAuth2 below, or remove the Client ID if you don’t need OAuth.', 'ageverif-wordpress' ),
				'anchor'  => '#ageverif-oauth-enabled',
			);
		}

		// Friendly reminder: Test Mode while OAuth is the live flow.
		if ( $test_mode_on ) {
			$items[] = array(
				'type'    => 'info',
				'message' => __( 'Test Mode is on — the gate only appears for logged-in administrators. Remember to turn this off before going live.', 'ageverif-wordpress' ),
				'anchor'  => '#ageverif-test-mode',
			);
		}

		if ( empty( $items ) ) {
			printf(
				'<div class="ageverif-status ageverif-status--success"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Ready to go.', 'ageverif-wordpress' ),
				esc_html__( 'Credentials and Protected Content Types are configured. Disable Test Mode to make the gate visible to real visitors.', 'ageverif-wordpress' )
			);
			return;
		}

		echo '<div class="ageverif-status">';
		foreach ( $items as $item ) {
			$type   = isset( $item['type'] ) ? sanitize_html_class( $item['type'] ) : 'info';
			$anchor = isset( $item['anchor'] ) ? (string) $item['anchor'] : '';
			$link   = '' !== $anchor
				? sprintf( '<a class="ageverif-status-link" href="%s">%s</a>', esc_url( $anchor ), esc_html__( 'Fix →', 'ageverif-wordpress' ) )
				: '';
			printf(
				'<div class="ageverif-status-item ageverif-status-item--%1$s"><p><strong>%2$s</strong> %3$s %4$s</p></div>',
				esc_attr( $type ),
				esc_html( $this->status_label( $type ) ),
				esc_html( $item['message'] ),
				$link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_url/esc_html above.
			);
		}
		echo '</div>';
	}

	private function status_label( $type ) {
		switch ( $type ) {
			case 'error':
				return __( 'Action needed:', 'ageverif-wordpress' );
			case 'warning':
				return __( 'Heads up:', 'ageverif-wordpress' );
			default:
				return __( 'Note:', 'ageverif-wordpress' );
		}
	}

	public function handle_health_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this check.', 'ageverif-wordpress' ), 403 );
		}
		check_admin_referer( 'ageverif_health_check' );

		$use    = isset( $_POST['ageverif_health_use'] )
			? sanitize_key( wp_unslash( $_POST['ageverif_health_use'] ) )
			: 'live';
		$custom = isset( $_POST['ageverif_health_key'] )
			? sanitize_text_field( wp_unslash( $_POST['ageverif_health_key'] ) )
			: '';

		switch ( $use ) {
			case 'test':
				$key = $this->options['test_key'];
				break;
			case 'custom':
				$key = $custom;
				break;
			case 'live':
			default:
				$key = $this->options['api_key'];
				break;
		}

		if ( '' === $key ) {
			$this->add_admin_notice( 'error', __( 'Health check skipped: no key selected. Add or pick a key first.', 'ageverif-wordpress' ) );
			$this->redirect_back();
		}

		$url      = add_query_arg( array( 'key' => $key, 'ping' => '1' ), \AgeVerif\AgeVerif_Frontend::CHECKER_URL );
		$response = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 5 ) );
		if ( is_wp_error( $response ) ) {
			$this->add_admin_notice(
				'error',
				sprintf(
					/* translators: %s: error message */
					esc_html__( 'Health check failed: %s', 'ageverif-wordpress' ),
					esc_html( $response->get_error_message() )
				)
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( 200 === $code && '' !== $body ) {
				$this->add_admin_notice(
					'success',
					sprintf(
						/* translators: %d: HTTP status code */
						esc_html__( 'Health check passed (HTTP %d). AgeVerif can reach your key.', 'ageverif-wordpress' ),
						$code
					)
				);
			} else {
				$this->add_admin_notice(
					'error',
					sprintf(
						/* translators: %d: HTTP status code */
						esc_html__( 'Health check failed (HTTP %d). Confirm the key and that your domain is added in the Webmasters Portal.', 'ageverif-wordpress' ),
						$code
					)
				);
			}
		}

		$this->redirect_back();
	}

	private function redirect_back() {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'ageverif' ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * OAuth2 Token Endpoint Health Check.
	 *
	 * POSTs to https://api.ageverif.com/v1/oauth2/token with a deliberately
	 * invalid `code`. The endpoint will reply with one of:
	 *
	 *   - 400 `{"error":"invalid_grant"}`  → credentials are accepted (PASS)
	 *   - 400 `{"error":"invalid_request"}` → endpoint reachable, creds OK (PASS-lite)
	 *   - 401 `{"error":"invalid_client"}`  → Client ID / Secret mismatch (FAIL)
	 *   - 200 / other                      → unexpected (FAIL with detail)
	 *   - network failure                  → couldn't reach AgeVerif (FAIL)
	 *
	 * No real `access_token` is requested, no `code` is consumed. The plug-in
	 * never logs or echoes the secret back — only describing the result.
	 */
	public function handle_oauth_health_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this check.', 'ageverif-wordpress' ), 403 );
		}
		check_admin_referer( 'ageverif_oauth_health_check' );

		$use = isset( $_POST['ageverif_oauth_health_use'] )
			? sanitize_key( wp_unslash( $_POST['ageverif_oauth_health_use'] ) )
			: 'configured';
		$custom_id     = isset( $_POST['ageverif_oauth_health_id'] )
			? sanitize_text_field( wp_unslash( $_POST['ageverif_oauth_health_id'] ) )
			: '';
		$custom_secret = isset( $_POST['ageverif_oauth_health_secret'] )
			? sanitize_text_field( wp_unslash( $_POST['ageverif_oauth_health_secret'] ) )
			: '';

		if ( 'custom' === $use ) {
			$client_id     = $custom_id;
			$client_secret = $custom_secret;
		} else {
			$client_id     = isset( $this->options['oauth_client_id'] )
				? (string) $this->options['oauth_client_id']
				: '';
			$client_secret = isset( $this->options['oauth_client_secret'] )
				? (string) $this->options['oauth_client_secret']
				: '';
		}

		if ( '' === $client_id || '' === $client_secret ) {
			$this->add_admin_notice(
				'error',
				__( 'OAuth health check skipped: Client ID or Client Secret is missing. Configure them under Settings → AgeVerif → OAuth2 first.', 'ageverif-wordpress' )
			);
			$this->redirect_back();
		}

		$response = wp_remote_post(
			'https://api.ageverif.com/v1/oauth2/token',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth Basic auth.
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => array(
					'grant_type'   => 'authorization_code',
					'code'         => 'ageverif_healthcheck_invalid_code',
					'redirect_uri' => \AgeVerif\AgeVerif_OAuth::callback_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->add_admin_notice(
				'error',
				sprintf(
					/* translators: %s: error message */
					esc_html__( 'OAuth health check failed: could not reach api.ageverif.com — %s', 'ageverif-wordpress' ),
					esc_html( $response->get_error_message() )
				)
			);
		} else {
			$code      = (int) wp_remote_retrieve_response_code( $response );
			$body      = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$error     = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : '';
			$err_desc  = is_array( $body ) && isset( $body['error_description'] ) ? (string) $body['error_description'] : '';

			if ( 400 === $code && ( 'invalid_grant' === $error || 'invalid_request' === $error ) ) {
				$this->add_admin_notice(
					'success',
					__( 'OAuth health check passed. api.ageverif.com accepted the request format and credentials. The OAuth flow should work end-to-end once a real visitor authorizes.', 'ageverif-wordpress' )
				);
			} elseif ( 401 === $code && 'invalid_client' === $error ) {
				$msg = __( 'OAuth health check failed: api.ageverif.com rejected the Client ID or Client Secret.', 'ageverif-wordpress' );
				if ( '' !== $err_desc ) {
					$msg .= ' ' . sprintf(
						/* translators: %s: error description from API */
						esc_html__( 'Server said: %s', 'ageverif-wordpress' ),
						esc_html( $err_desc )
					);
				}
				$msg .= ' ' . esc_html__( 'Re-copy both values from the Webmasters Portal and re-save here.', 'ageverif-wordpress' );
				$this->add_admin_notice( 'error', $msg );
			} else {
				$this->add_admin_notice(
					'error',
					sprintf(
						/* translators: 1: HTTP status code, 2: OAuth error code, 3: error description */
						esc_html__( 'OAuth health check got an unexpected response (HTTP %1$s, error: %2$s, description: %3$s). Check the Webmasters Portal and try again.', 'ageverif-wordpress' ),
						(int) $code,
						'' !== $error ? esc_html( $error ) : '(none)',
						'' !== $err_desc ? esc_html( $err_desc ) : '(none)'
					)
				);
			}
		}

		$this->redirect_back();
	}

	/* ===== Shared helpers ===== */

	private function bot_preset_signatures() {
		// Single source of truth for the bot UA fingerprint list — also used
		// from \AgeVerif\AgeVerif_Frontend::is_user_agent_bypassed().
		return \AgeVerif\AgeVerif_Frontend::bot_preset_signatures();
	}

	/* ===== Page ===== */

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap ageverif-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p class="ageverif-intro">
				<?php esc_html_e( 'AgeVerif adds a privacy-first age verification gate to your content. You don’t need API experience — this page walks you through the whole setup. Pick a content type, paste a key, save.', 'ageverif-wordpress' ); ?>
			</p>

			<?php $this->render_status_widget(); ?>

			<?php $this->render_quick_start_panel(); ?>

			<?php if ( $this->had_v10_selection ) : ?>
				<div class="notice notice-warning is-dismissible" id="ageverif-v11-notice">
					<p>
						<strong><?php esc_html_e( 'AgeVerif 1.1 update:', 'ageverif-wordpress' ); ?></strong>
						<?php
						echo wp_kses_post( __(
							'in v1.0 leaving every post-type checkbox empty silently protected the whole site (and contradicted the help text). In v1.1 an empty selection means “nothing is gated” — matching the UI text. Pick the content types you want to protect below and save.',
							'ageverif-wordpress'
						) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'ageverif' );
				submit_button();
				?>
			</form>

			<div class="ageverif-tool">
				<h2><?php esc_html_e( 'Health Check', 'ageverif-wordpress' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Verifies that AgeVerif can be reached from this server using one of your keys.', 'ageverif-wordpress' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ageverif_health_check" />
					<?php wp_nonce_field( 'ageverif_health_check' ); ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Health Check Key Selection', 'ageverif-wordpress' ); ?></legend>
						<p>
							<label>
								<input type="radio" name="ageverif_health_use" value="live" checked />
								<?php esc_html_e( 'Use Live Key', 'ageverif-wordpress' ); ?>
								<code><?php echo '' !== $this->options['api_key'] ? esc_html( substr( $this->options['api_key'], 0, min( 6, strlen( $this->options['api_key'] ) ) ) ) . '…' : '—'; ?></code>
							</label>
						</p>
						<p>
							<label>
								<input type="radio" name="ageverif_health_use" value="test" />
								<?php esc_html_e( 'Use Test Key', 'ageverif-wordpress' ); ?>
								<code><?php echo '' !== $this->options['test_key'] ? esc_html( substr( $this->options['test_key'], 0, min( 6, strlen( $this->options['test_key'] ) ) ) ) . '…' : '—'; ?></code>
							</label>
						</p>
						<p>
							<label>
								<input type="radio" name="ageverif_health_use" value="custom" />
								<?php esc_html_e( 'Custom Key', 'ageverif-wordpress' ); ?>
								<input type="text" name="ageverif_health_key" placeholder="<?php esc_attr_e( 'Paste a key to test', 'ageverif-wordpress' ); ?>" class="regular-text" />
							</label>
						</p>
						<?php submit_button( __( 'Run Health Check', 'ageverif-wordpress' ), 'secondary', 'ageverif_health_submit', false ); ?>
					</fieldset>
				</form>

				<hr class="ageverif-tool-sep" />
				<h3><?php esc_html_e( 'OAuth2 Token Endpoint', 'ageverif-wordpress' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Posts a tiny request to api.ageverif.com/v1/oauth2/token with a deliberately invalid code. If the credentials are accepted the endpoint returns 400 invalid_grant — that’s our pass condition. If it returns 401 invalid_client, the Client ID and/or Client Secret are wrong.', 'ageverif-wordpress' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ageverif_oauth_health_check" />
					<?php wp_nonce_field( 'ageverif_oauth_health_check' ); ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'OAuth Health Check Selection', 'ageverif-wordpress' ); ?></legend>
						<p>
							<label>
								<input type="radio" name="ageverif_oauth_health_use" value="configured" checked />
								<?php esc_html_e( 'Use configured Client ID + Secret', 'ageverif-wordpress' ); ?>
								<code><?php echo '' !== $this->options['oauth_client_id']
									? esc_html( substr( $this->options['oauth_client_id'], 0, min( 6, strlen( $this->options['oauth_client_id'] ) ) ) ) . '…'
									: '—'; ?></code>
							</label>
						</p>
						<p>
							<label>
								<input type="radio" name="ageverif_oauth_health_use" value="custom" />
								<?php esc_html_e( 'Custom Client ID + Secret (paste new ones to test)', 'ageverif-wordpress' ); ?>
							</label>
						</p>
						<p class="ageverif-custom-credentials">
							<input type="text" name="ageverif_oauth_health_id" placeholder="<?php esc_attr_e( 'Client ID', 'ageverif-wordpress' ); ?>" class="regular-text" autocomplete="off" spellcheck="false" />
							<input type="password" name="ageverif_oauth_health_secret" placeholder="<?php esc_attr_e( 'Client Secret', 'ageverif-wordpress' ); ?>" class="regular-text" autocomplete="new-password" />
						</p>
						<?php submit_button( __( 'Run OAuth Health Check', 'ageverif-wordpress' ), 'secondary', 'ageverif_oauth_health_submit', false ); ?>
					</fieldset>
				</form>
			</div>

			<?php $this->render_glossary_panel(); ?>
			<?php $this->render_troubleshooting_panel(); ?>
			<?php $this->render_pre_launch_checklist(); ?>
		</div>
		<?php
	}

	/**
	 * Render the optional Quick Start video embed.
	 *
	 * Defaults to a <details> collapsed by default — the admin clicks
	 * "Watch the screencast" to expand, which is what keeps the admin
	 * page itself fast even if the video host is slow.
	 *
	 * URL handling:
	 *  - empty → small hint pointing to the Onboarding section.
	 *  - .mp4 / .webm → render a <video controls> element.
	 *  - everything else → sandboxed <iframe>.
	 *
	 * The iframe is sandboxed (no top-level navigation, no form
	 * submission, no same-origin access) and gets `referrerpolicy="no-referrer"`
	 * so we never leak the admin URL to a third-party video host.
	 */
	private function render_quick_start_video() {
		$raw = isset( $this->options['quickstart_video_url'] )
			? (string) $this->options['quickstart_video_url']
			: '';
		$path = wp_parse_url( $raw, PHP_URL_PATH );
		$is_self_hosted = is_string( $path ) && preg_match( '/\.(mp4|webm|ogv)(\?.*)?$/i', $path );
		?>
		<details class="ageverif-quickstart-video">
			<summary>
				<?php
				echo '' === $raw
					? esc_html__( 'Prefer video? Watch the screencast →', 'ageverif-wordpress' )
					: esc_html__( 'Watch the screencast →', 'ageverif-wordpress' );
				?>
			</summary>
			<?php if ( '' === $raw ) : ?>
				<p class="description">
					<?php
					echo wp_kses(
						__( 'No video URL configured. Add one under <a href="#ageverif-quickstart-video-url">Onboarding → Quick Start Video URL</a> to enable this embed. Use the official AgeVerif screencast link, your own MP4 URL, or a YouTube/Vimeo/Loom link.', 'ageverif-wordpress' ),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
			<?php elseif ( $is_self_hosted ) : ?>
				<div class="ageverif-quickstart-video-frame">
					<video controls preload="none" playsinline>
						<source src="<?php echo esc_url( $raw ); ?>" />
						<?php esc_html_e( 'Your browser does not support embedded video. Download it directly:', 'ageverif-wordpress' ); ?>
						<a href="<?php echo esc_url( $raw ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $raw ); ?></a>
					</video>
				</div>
				<p class="description">
					<?php esc_html_e( 'Self-hosted MP4/WebM video. Falls back gracefully if your browser blocks the codec.', 'ageverif-wordpress' ); ?>
				</p>
			<?php else : ?>
				<div class="ageverif-quickstart-video-frame">
					<iframe
						src="<?php echo esc_url( $raw ); ?>"
						title="<?php esc_attr_e( 'AgeVerif Quick Start screencast', 'ageverif-wordpress' ); ?>"
						loading="lazy"
						referrerpolicy="no-referrer"
						sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
						allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
						allowfullscreen></iframe>
				</div>
				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: the video URL (escaped externally below) */
							__( 'Sandboxed embed. Trouble loading? <a href="%s" target="_blank" rel="noopener">Open the video in a new tab →</a>', 'ageverif-wordpress' ),
							esc_url( $raw )
						),
						array( 'a' => array( 'href' => array(), 'target' => array( '_blank' ), 'rel' => array( 'noopener' ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Quick Start walkthrough panel — the first thing a new admin sees.
	 *
	 * Pure documentation, kept as a single block so future copy edits
	 * happen in one place. The OAuth subsection uses a <details> element
	 * so admins who don't need OAuth don't have to read it.
	 */
	private function render_quick_start_panel() {
		?>
		<div class="ageverif-help ageverif-quickstart">
			<h2><?php esc_html_e( 'Quick Start (≈5 minutes)', 'ageverif-wordpress' ); ?></h2>
			<?php $this->render_quick_start_video(); ?>
			<p>
				<?php echo $this->quick_start_paragraph(); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — uses wp_kses internally. */ ?>
			</p>
			<ol class="ageverif-steps">
				<li class="ageverif-step">
					<span class="ageverif-step-num">1</span>
					<div class="ageverif-step-body">
						<h3><?php esc_html_e( 'Create a free account', 'ageverif-wordpress' ); ?></h3>
						<p>
							<?php
							echo wp_kses(
								__( 'Open the <a href="https://webmasters.ageverif.com/sign-up" target="_blank" rel="noopener">AgeVerif Webmasters Platform</a> and sign up with your email. No credit card required.', 'ageverif-wordpress' ),
								array( 'a' => array( 'href' => array(), 'target' => array( '_blank' ), 'rel' => array( 'noopener' ) ) )
							);
							?>
						</p>
					</div>
				</li>
				<li class="ageverif-step">
					<span class="ageverif-step-num">2</span>
					<div class="ageverif-step-body">
						<h3><?php esc_html_e( 'Add your site', 'ageverif-wordpress' ); ?></h3>
						<p>
							<?php
							echo wp_kses(
								__( 'Click <strong>Add Website</strong>. Enter your domain exactly as it appears in the address bar (e.g. <code>example.com</code>) — do not include <code>https://</code> or a trailing slash.', 'ageverif-wordpress' ),
								array( 'strong' => array(), 'code' => array() )
							);
							?>
						</p>
					</div>
				</li>
				<li class="ageverif-step">
					<span class="ageverif-step-num">3</span>
					<div class="ageverif-step-body">
						<h3><?php esc_html_e( 'Copy the Public Live Key', 'ageverif-wordpress' ); ?></h3>
						<p>
							<?php esc_html_e( 'After adding your site, the platform shows a long string of random characters under “Public Live Key”. Copy it.', 'ageverif-wordpress' ); ?>
						</p>
					</div>
				</li>
				<li class="ageverif-step">
					<span class="ageverif-step-num">4</span>
					<div class="ageverif-step-body">
						<h3><?php esc_html_e( 'Paste the key here', 'ageverif-wordpress' ); ?></h3>
						<p>
							<?php esc_html_e( 'Scroll down to Connection → Public Live Key and paste. Click Save Changes.', 'ageverif-wordpress' ); ?>
						</p>
					</div>
				</li>
				<li class="ageverif-step">
					<span class="ageverif-step-num">5</span>
					<div class="ageverif-step-body">
						<h3><?php esc_html_e( 'Tick at least one Protected Content Type', 'ageverif-wordpress' ); ?></h3>
						<p>
							<?php esc_html_e( 'Under Visibility, tick the content types you want the gate to appear on (Posts, Pages, products, etc.). Nothing is gated unless you tick at least one type. Click Save Changes.', 'ageverif-wordpress' ); ?>
						</p>
					</div>
				</li>
				<li class="ageverif-step">
					<span class="ageverif-step-num">6</span>
					<div class="ageverif-step-body">
						<h3><?php esc_html_e( 'Preview, then go live', 'ageverif-wordpress' ); ?></h3>
						<p>
							<?php esc_html_e( 'Test Mode means only logged-in administrators see the gate (real visitors keep seeing your page normally, so you can preview without affecting them). Tick Test Mode and Save, then visit a protected page while still logged in as admin in your regular browser — you should see the gate. Once everything looks right, untick Test Mode and Save. To verify the gate fires for real visitors too, open a private/incognito window after disabling Test Mode.', 'ageverif-wordpress' ); ?>
						</p>
					</div>
				</li>
			</ol>

			<details class="ageverif-substeps">
				<summary>
					<strong><?php esc_html_e( 'Optional: also enable OAuth2', 'ageverif-wordpress' ); ?></strong>
					<span class="description"><?php esc_html_e( 'Use OAuth2 if you want a custom button instead of the standard popup. Requires advanced setup steps in the Webmasters Platform.', 'ageverif-wordpress' ); ?></span>
				</summary>
				<ol class="ageverif-steps">
					<li class="ageverif-step">
						<span class="ageverif-step-num">A</span>
						<div class="ageverif-step-body">
							<h3><?php esc_html_e( 'Enable OAuth2 in the Webmasters Platform', 'ageverif-wordpress' ); ?></h3>
							<p><?php esc_html_e( 'Open your site’s page in the Webmasters Platform and click “Enable OAuth2”. You will receive a Client ID and a Client Secret.', 'ageverif-wordpress' ); ?></p>
						</div>
					</li>
					<li class="ageverif-step">
						<span class="ageverif-step-num">B</span>
						<div class="ageverif-step-body">
							<h3><?php esc_html_e( 'Paste Client ID and Secret below', 'ageverif-wordpress' ); ?></h3>
							<p><?php esc_html_e( 'Scroll to the OAuth2 section, paste them in, tick “Enable OAuth2”, and Save.', 'ageverif-wordpress' ); ?></p>
						</div>
					</li>
					<li class="ageverif-step">
						<span class="ageverif-step-num">C</span>
						<div class="ageverif-step-body">
							<h3><?php esc_html_e( 'Register the Callback URL', 'ageverif-wordpress' ); ?></h3>
							<p><?php esc_html_e( 'Copy the Callback URL shown at the top of the OAuth2 section and paste it into your site’s allowed redirect URIs in the Webmasters Platform. The two URLs must match EXACTLY (including any trailing slash).', 'ageverif-wordpress' ); ?></p>
						</div>
					</li>
				</ol>
			</details>

			<p class="ageverif-help-foot">
				<?php
				echo wp_kses(
					__( 'Need more detail? Read the <a href="https://docs.ageverif.com" target="_blank" rel="noopener">full integration docs</a> or sign in to the <a href="https://webmasters.ageverif.com" target="_blank" rel="noopener">Webmasters Portal</a>.', 'ageverif-wordpress' ),
					array( 'a' => array( 'href' => array(), 'target' => array( '_blank' ), 'rel' => array( 'noopener' ) ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Glossary panel — collapsible <details> per term.
	 */
	private function render_glossary_panel() {
		$terms = array(
			array(
				'q' => __( 'What is OAuth2?', 'ageverif-wordpress' ),
				'a' => __( 'A standard way for two websites to talk to each other on behalf of a visitor. With AgeVerif it means: instead of an old-style full-page redirect away to AgeVerif, the protected page renders normally with an in-page popover overlay that visitors click to verify directly on AgeVerif. After verification they land back on the same page with a small cookie set in their browser so they don’t have to verify again for the next hour.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'What is a Client ID?', 'ageverif-wordpress' ),
				'a' => __( 'A public identifier for this site when it talks to AgeVerif. Think of it like a username — anyone can see it, and it only tells AgeVerif which site is making the request.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'What is a Client Secret?', 'ageverif-wordpress' ),
				'a' => __( 'A long random string that acts like a password between this site and AgeVerif. Keep your database backups private. To rotate it: generate a new one in the Webmasters Platform and paste it here — old values stop working immediately.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'What is the Callback URL?', 'ageverif-wordpress' ),
				'a' => __( 'The URL AgeVerif redirects visitors to AFTER they verify. v1.3.0+ uses the REST form (something like https://example.com/wp-json/ageverif/v1/oauth/callback) which most full-page cache plugins (Cloudflare APO, Nginx Helper) exempt automatically. The legacy query-string form (?ageverif_oauth=callback) is still honored as a fallback for older Webmasters Platform registrations. Register whichever form the Webmasters Platform still expects — character-for-character.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'What is the Verification Cookie?', 'ageverif-wordpress' ),
				'a' => __( 'A signed cookie set in the visitor’s browser when OAuth verification succeeds. It expires after about one hour, after which the visitor will be asked to verify again. The cookie contains no personal data.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'What is the Webmasters Portal?', 'ageverif-wordpress' ),
				'a' => __( 'The AgeVerif dashboard at webmasters.ageverif.com where you create your account, add websites, generate keys, and configure OAuth. You need an account there before this plugin can do anything.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'What is Test Mode?', 'ageverif-wordpress' ),
				'a' => __( 'A safety switch that shows the gate ONLY to logged-in administrators. Use this to preview changes before your visitors see them. Always leave it on during setup; turn it off only when you’re ready to go live.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'What is Bot Bypass?', 'ageverif-wordpress' ),
				'a' => __( 'A list of well-known crawlers (Google, Bing, OpenAI, etc.) that the plugin automatically lets past the gate, server-side. This keeps your site visible in search results and AI training data. Don’t untick these unless you have a specific reason.', 'ageverif-wordpress' ),
			),
		);
		?>
		<div class="ageverif-help ageverif-glossary">
			<h2><?php esc_html_e( 'Glossary', 'ageverif-wordpress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Terms you’ll see in this page and in the AgeVerif docs.', 'ageverif-wordpress' ); ?></p>
			<?php foreach ( $terms as $t ) : ?>
				<details class="ageverif-glossary-term">
					<summary><?php echo esc_html( $t['q'] ); ?></summary>
					<p><?php echo esc_html( $t['a'] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Troubleshooting panel — common newbie pitfalls with concrete fixes.
	 */
	private function render_troubleshooting_panel() {
		$issues = array(
			array(
				'q' => __( 'I clicked Save but the gate never appears for visitors', 'ageverif-wordpress' ),
				'a' => __( 'Most likely “Protected Content Types” is empty. Scroll up to Visibility and tick at least one type (Posts, Pages, or your custom post types).', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'I am gated on my own pages', 'ageverif-wordpress' ),
				'a' => __( 'Tick “Always Bypass Administrators” under Bypass. If Test Mode is on, that toggle is suppressed — log out to see the live behavior.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'Google says my pages are noindex / unreachable', 'ageverif-wordpress' ),
				'a' => __( 'Make sure all major bots are ticked under “Bot Bypass (known crawlers)”. The plugin blocks bots from the gate by default, so any unticked crawler would see the gate.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'OAuth callback error: state invalid / CSRF', 'ageverif-wordpress' ),
				'a' => __( 'Usually caused by caching plugins or browsers that block third-party cookies. Make sure your caching plugin does not strip cookies on the callback URL. Try the flow in a private/incognito window.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'OAuth token exchange failed: invalid_client', 'ageverif-wordpress' ),
				'a' => __( 'The Client ID and Client Secret do not match what the Webmasters Platform has. Re-copy both values from the Webmasters Platform and re-save here.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'OAuth token exchange failed: redirect_uri mismatch', 'ageverif-wordpress' ),
				'a' => __( 'The Callback URL on this page must EXACTLY match what you registered in the Webmasters Platform. Watch for trailing slashes, http vs https, www vs non-www.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'I overwrote my Client Secret and now the plugin logs me out', 'ageverif-wordpress' ),
				'a' => __( 'A blank Client Secret field preserves the existing value. To actually rotate it, paste a new value from the Webmasters Platform and re-save.', 'ageverif-wordpress' ),
			),
			array(
				'q' => __( 'Visitors report the gate is stuck or re-asking for verification', 'ageverif-wordpress' ),
				'a' => __( 'The verification cookie expires after about one hour (or when the visitor clears their browser cookies). This is normal and required by the verifier. Ask the visitor to enable cookies for your domain.', 'ageverif-wordpress' ),
			),
		);
		?>
		<div class="ageverif-help ageverif-troubleshooting">
			<h2><?php esc_html_e( 'Common Issues & Fixes', 'ageverif-wordpress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Click an issue to see the fix.', 'ageverif-wordpress' ); ?></p>
			<?php foreach ( $issues as $t ) : ?>
				<details class="ageverif-troubleshooting-issue">
					<summary><?php echo esc_html( $t['q'] ); ?></summary>
					<p><?php echo esc_html( $t['a'] ); ?></p>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Pre-launch checklist — a static list of items to confirm before
	 * disabling Test Mode.
	 */
	private function render_pre_launch_checklist() {
		$items = array(
			__( 'You have a Public Live Key or OAuth Client ID + Secret saved on this page.', 'ageverif-wordpress' ),
			__( 'At least one content type is ticked under “Protected Content Types”.', 'ageverif-wordpress' ),
			__( 'With Test Mode ON, you visited a protected page while logged in as admin in your regular browser and saw the gate appear.', 'ageverif-wordpress' ),
			__( 'You opened a private/incognito window (logged out) and confirmed your unprotected pages still render normally.', 'ageverif-wordpress' ),
			__( 'If OAuth: the Callback URL on this page matches what is registered in the Webmasters Portal.', 'ageverif-wordpress' ),
			__( 'If OAuth: you went through the full flow end-to-end (click button → verify on AgeVerif → return to your page) while logged in as admin.', 'ageverif-wordpress' ),
			__( 'You tested from a mobile device or a different browser.', 'ageverif-wordpress' ),
			__( '“Underage Redirect URL” is set to a friendly page (only required if you also ticked “Closable Gate”).', 'ageverif-wordpress' ),
			__( '“Test Mode” is OFF.', 'ageverif-wordpress' ),
		);
		?>
		<div class="ageverif-help ageverif-checklist-wrap">
			<h2><?php esc_html_e( 'Before You Go Live', 'ageverif-wordpress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Run through each item before turning off Test Mode.', 'ageverif-wordpress' ); ?></p>
			<ul class="ageverif-checklist">
				<?php foreach ( $items as $text ) : ?>
					<li><span class="ageverif-checklist-bullet" aria-hidden="true"></span><?php echo esc_html( $text ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
