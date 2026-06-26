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

		// 1. Connection
		add_settings_section(
			'ageverif_connection',
			__( 'Connection', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro' ),
			'ageverif'
		);
		$this->register_field( 'api_key',  __( 'Public Live Key', 'ageverif-wordpress' ), 'field_api_key' );
		$this->register_field( 'test_key', __( 'Public Test Key', 'ageverif-wordpress' ), 'field_test_key' );

		// 2. Visibility
		add_settings_section(
			'ageverif_visibility',
			__( 'Visibility', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro' ),
			'ageverif'
		);
		$this->register_field( 'enabled_post_types', __( 'Protected Content Types', 'ageverif-wordpress' ), 'field_enabled_post_types' );
		$this->register_field( 'excluded_urls',      __( 'Excluded URLs', 'ageverif-wordpress' ),       'field_excluded_urls' );

		// 3. Display
		add_settings_section(
			'ageverif_display',
			__( 'Display', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro' ),
			'ageverif'
		);
		$this->register_field( 'language',     __( 'Language',           'ageverif-wordpress' ), 'field_language' );
		$this->register_field( 'challenges',   __( 'Verification Steps', 'ageverif-wordpress' ), 'field_challenges' );
		$this->register_field( 'display_mode', __( 'Display Mode',       'ageverif-wordpress' ), 'field_display_mode' );
		$this->register_field( 'closable',     __( 'Closable Gate',      'ageverif-wordpress' ), 'field_closable' );
		$this->register_field( 'manual_start', __( 'Manual Start',       'ageverif-wordpress' ), 'field_manual_start' );
		$this->register_field( 'content_blur', __( 'Content Blur',       'ageverif-wordpress' ), 'field_content_blur' );

		// 4. Bypass
		add_settings_section(
			'ageverif_bypass',
			__( 'Bypass', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro' ),
			'ageverif'
		);
		$this->register_field( 'admin_bypass',      __( 'Always Bypass Administrators', 'ageverif-wordpress' ), 'field_admin_bypass' );
		$this->register_field( 'bypass_logged_in',  __( 'Bypass Logged-in Users', 'ageverif-wordpress' ), 'field_bypass_logged_in' );
		$this->register_field( 'bypass_roles',      __( 'Roles that Bypass',      'ageverif-wordpress' ), 'field_bypass_roles' );
		$this->register_field( 'bot_bypass_presets', __( 'Bot Bypass (known crawlers)', 'ageverif-wordpress' ), 'field_bot_bypass_presets' );
		$this->register_field( 'bot_bypass_custom', __( 'Bot Bypass (custom User-Agents)', 'ageverif-wordpress' ), 'field_bot_bypass_custom' );

		// 5. After verification
		add_settings_section(
			'ageverif_after',
			__( 'After Verification', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro' ),
			'ageverif'
		);
		$this->register_field( 'underage_redirect_url', __( 'Underage Redirect URL', 'ageverif-wordpress' ), 'field_underage_redirect_url' );

		// 6. Mode
		add_settings_section(
			'ageverif_mode',
			__( 'Mode', 'ageverif-wordpress' ),
			array( $this, 'render_section_intro' ),
			'ageverif'
		);
		$this->register_field( 'test_mode', __( 'Test Mode', 'ageverif-wordpress' ), 'field_test_mode' );
	}

	private function register_field( $key, $label, $method ) {
		add_settings_field( $key, $label, array( $this, $method ), 'ageverif', 'ageverif_' . $this->field_section( $key ) );
	}

	private function field_section( $key ) {
		$map = array(
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
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : 'connection';
	}

	public function render_section_intro() {
		// Intentionally empty per-section. The page-level intro talks to
		// readers about cross-section concerns (e.g. Webmasters Portal links).
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
		<div class="ageverif-grid">
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
		?>
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
		?>
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
		?>
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
		?>
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
		?>
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
		?>
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

	public function field_underage_redirect_url() {
		?>
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
			<input type="checkbox" name="ageverif_options[test_mode]" value="1" <?php checked( $this->options['test_mode'] ); ?> />
			<?php esc_html_e( 'Enable Test Mode', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Uses the Test Key and shows the gate only to logged-in administrators. Use to preview before going live.', 'ageverif-wordpress' ); ?>
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
			<div class="ageverif-help">
				<h2><?php esc_html_e( 'Get Your AgeVerif Keys', 'ageverif-wordpress' ); ?></h2>
				<ol>
					<li>
						<?php
						echo wp_kses(
							__( 'Register at the <a href="https://webmasters.ageverif.com/sign-up" target="_blank" rel="noopener">AgeVerif Webmasters Platform</a>.', 'ageverif-wordpress' ),
							array( 'a' => array( 'href' => array(), 'target' => array( '_blank' ), 'rel' => array( 'noopener' ) ) )
						);
						?>
					</li>
					<li><?php esc_html_e( 'Add your site and copy the Public Live Key.', 'ageverif-wordpress' ); ?></li>
					<li><?php esc_html_e( 'Paste it under Connection above, pick content types, save.', 'ageverif-wordpress' ); ?></li>
				</ol>
				<p>
					<a href="https://docs.ageverif.com" target="_blank" rel="noopener"><?php esc_html_e( 'Full integration docs', 'ageverif-wordpress' ); ?></a>
					&nbsp;|&nbsp;
					<a href="https://webmasters.ageverif.com" target="_blank" rel="noopener"><?php esc_html_e( 'Webmasters Portal', 'ageverif-wordpress' ); ?></a>
				</p>
			</div>

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
			</div>
		</div>
		<?php
	}
}
