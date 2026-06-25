<?php
namespace AgeVerif\Admin;

defined( 'ABSPATH' ) || exit;

class AgeVerif_Admin {

	private $option_group = 'ageverif_settings';
	private $option_name  = 'ageverif_options';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

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
			$this->option_group,
			$this->option_name,
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->get_defaults(),
			)
		);

		add_settings_section(
			'ageverif_main',
			__( 'API Configuration', 'ageverif-wordpress' ),
			'__return_false',
			'ageverif'
		);

		add_settings_field(
			'api_key',
			__( 'Public Live Key', 'ageverif-wordpress' ),
			array( $this, 'field_api_key' ),
			'ageverif',
			'ageverif_main'
		);

		add_settings_field(
			'test_key',
			__( 'Public Test Key', 'ageverif-wordpress' ),
			array( $this, 'field_test_key' ),
			'ageverif',
			'ageverif_main'
		);

		add_settings_section(
			'ageverif_easy_mode',
			__( 'Regional Settings', 'ageverif-wordpress' ),
			array( $this, 'render_easy_mode_description' ),
			'ageverif'
		);

		add_settings_field(
			'easy_mode',
			__( 'Easy Mode', 'ageverif-wordpress' ),
			array( $this, 'field_easy_mode' ),
			'ageverif',
			'ageverif_easy_mode'
		);

		add_settings_section(
			'ageverif_content',
			__( 'Content Protection', 'ageverif-wordpress' ),
			'__return_false',
			'ageverif'
		);

		add_settings_field(
			'enabled_post_types',
			__( 'Protected Content Types', 'ageverif-wordpress' ),
			array( $this, 'field_enabled_post_types' ),
			'ageverif',
			'ageverif_content'
		);

		add_settings_field(
			'excluded_urls',
			__( 'Excluded URLs', 'ageverif-wordpress' ),
			array( $this, 'field_excluded_urls' ),
			'ageverif',
			'ageverif_content'
		);

		add_settings_section(
			'ageverif_appearance',
			__( 'Appearance', 'ageverif-wordpress' ),
			'__return_false',
			'ageverif'
		);

		add_settings_field(
			'custom_css',
			__( 'Custom CSS', 'ageverif-wordpress' ),
			array( $this, 'field_custom_css' ),
			'ageverif',
			'ageverif_appearance'
		);

		add_settings_section(
			'ageverif_mode',
			__( 'Mode', 'ageverif-wordpress' ),
			'__return_false',
			'ageverif'
		);

		add_settings_field(
			'test_mode',
			__( 'Test Mode', 'ageverif-wordpress' ),
			array( $this, 'field_test_mode' ),
			'ageverif',
			'ageverif_mode'
		);
	}

	private function get_defaults() {
		return array(
			'api_key'            => '',
			'test_key'           => '',
			'enabled_post_types' => array(),
			'excluded_urls'      => '',
			'custom_css'         => '',
			'test_mode'          => 0,
			'easy_mode'          => 0,
		);
	}

	public function sanitize_options( $input ) {
		$output = $this->get_defaults();

		if ( isset( $input['api_key'] ) ) {
			$output['api_key'] = sanitize_text_field( $input['api_key'] );
		}
		if ( isset( $input['test_key'] ) ) {
			$output['test_key'] = sanitize_text_field( $input['test_key'] );
		}
		if ( isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
			$output['enabled_post_types'] = array_map( 'sanitize_key', $input['enabled_post_types'] );
		}
		if ( isset( $input['excluded_urls'] ) ) {
			$lines = explode( "\n", $input['excluded_urls'] );
			$clean = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$clean[] = esc_url_raw( $line );
				}
			}
			$output['excluded_urls'] = implode( "\n", $clean );
		}
		if ( isset( $input['custom_css'] ) ) {
			$output['custom_css'] = wp_strip_all_tags( $input['custom_css'] );
		}
		if ( isset( $input['test_mode'] ) ) {
			$output['test_mode'] = absint( $input['test_mode'] );
		}
		if ( isset( $input['easy_mode'] ) ) {
			$output['easy_mode'] = absint( $input['easy_mode'] );
		}

		return $output;
	}

public function field_api_key() {
		$options = get_option( $this->option_name, $this->get_defaults() );
		?>
		<input type="text" name="ageverif_options[api_key]" value="<?php echo esc_attr( $options['api_key'] ); ?>" class="regular-text" placeholder="e.g. DtD0ad9ZMcKJBd9Ojh8D8Q0ELh2eSKwb0f1SzN7E" />
		<p class="description">
			<?php esc_html_e( 'Your website\'s Public Live Key (from Webmasters Portal).', 'ageverif-wordpress' ); ?>
			<br>
			<strong><?php esc_html_e( 'Note:', 'ageverif-wordpress' ); ?></strong>
			<?php esc_html_e( 'This key only identifies your site to AgeVerif. It does NOT grant API access. Regions are configured in the Webmasters Portal.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_test_key() {
		$options = get_option( $this->option_name, $this->get_defaults() );
		?>
		<input type="text" name="ageverif_options[test_key]" value="<?php echo esc_attr( $options['test_key'] ); ?>" class="regular-text" placeholder="e.g. TestKey123" />
		<p class="description"><?php esc_html_e( 'Your Public Test Key for local development and testing. Used when Test Mode is enabled.', 'ageverif-wordpress' ); ?></p>
		<?php
	}

	public function field_enabled_post_types() {
		$options  = get_option( $this->option_name, $this->get_defaults() );
		$selected = isset( $options['enabled_post_types'] ) ? $options['enabled_post_types'] : array();

		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			printf(
				'<label><input type="checkbox" name="ageverif_options[enabled_post_types][]" value="%s" %s /> %s</label><br>',
				esc_attr( $pt->name ),
				checked( in_array( $pt->name, $selected, true ), true, false ),
				esc_html( $pt->label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Select which content types should display the age verification gate. Leave all unchecked to protect nothing (gate will not appear).', 'ageverif-wordpress' ) . '</p>';
	}

	public function field_excluded_urls() {
		$options = get_option( $this->option_name, $this->get_defaults() );
		$value   = isset( $options['excluded_urls'] ) ? $options['excluded_urls'] : '';
		?>
		<textarea name="ageverif_options[excluded_urls]" rows="5" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'One URL per line. Visitors on these URLs will bypass the age verification gate. You can use absolute URLs or relative paths (e.g. /sample-page/).', 'ageverif-wordpress' ); ?></p>
		<?php
	}

	public function field_custom_css() {
		$options = get_option( $this->option_name, $this->get_defaults() );
		$value   = isset( $options['custom_css'] ) ? $options['custom_css'] : '';
		?>
		<textarea name="ageverif_options[custom_css]" rows="8" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Optional custom CSS to adjust the appearance of the age verification gate. These styles will be applied on top of the default styling.', 'ageverif-wordpress' ); ?></p>
		<?php
	}

	public function field_test_mode() {
		$options = get_option( $this->option_name, $this->get_defaults() );
		$checked = ! empty( $options['test_mode'] );
		?>
		<label>
			<input type="checkbox" name="ageverif_options[test_mode]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Enable Test Mode', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, the plugin uses the Test Key instead of the Live Key, and the gate is only shown to logged-in administrators. Use this to preview the verification gate before going live.', 'ageverif-wordpress' ); ?></p>
		<?php
	}

	public function render_easy_mode_description() {
		?>
		<p class="description">
			<?php esc_html_e( 'Easy Mode automatically adapts the age verification gate to your host website\'s active theme (light/dark mode) by using:', 'ageverif-wordpress' ); ?><br>
			- <?php esc_html_e( 'The site\'s default fonts', 'ageverif-wordpress' ); ?><br>
			- <?php esc_html_e( 'Native color palette (inherits site colors)', 'ageverif-wordpress' ); ?><br>
			- <?php esc_html_e( 'Built-in spacing and layout values', 'ageverif-wordpress' ); ?><br>
			<?php esc_html_e( 'This ensures complete design consistency with your website\'s active theme without any manual configuration.', 'ageverif-wordpress' ); ?>
		</p>
		<?php
	}

	public function field_easy_mode() {
		$options = get_option( $this->option_name, $this->get_defaults() );
		$checked = ! empty( $options['easy_mode'] );
		?>
		<label>
			<input type="checkbox" name="ageverif_options[easy_mode]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Enable Easy Mode (Recommended)', 'ageverif-wordpress' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When enabled, the verification gate will automatically inherit your theme\'s colors, fonts, and design system. This is the recommended setting for seamless integration.', 'ageverif-wordpress' ); ?></p>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap ageverif-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( $this->option_group );
				do_settings_sections( 'ageverif' );
				submit_button();
				?>
			</form>
			<hr>
			<div class="ageverif-help">
				<h2><?php esc_html_e( 'How to Get Your API Key', 'ageverif-wordpress' ); ?></h2>
				<ol>
					<li><?php echo wp_kses_post( __( 'Register at <a href="https://webmasters.ageverif.com/sign-up" target="_blank" rel="noopener">AgeVerif Webmasters Platform</a>.', 'ageverif-wordpress' ) ); ?></li>
					<li><?php esc_html_e( 'Add your website in the Webmasters Platform.', 'ageverif-wordpress' ); ?></li>
					<li><?php esc_html_e( 'Copy the Public Live Key from your website settings.', 'ageverif-wordpress' ); ?></li>
					<li><?php esc_html_e( 'Paste it in the field above and select which content types to protect.', 'ageverif-wordpress' ); ?></li>
				</ol>
				<p><a href="https://docs.ageverif.com" target="_blank" rel="noopener"><?php esc_html_e( 'View Full Documentation', 'ageverif-wordpress' ); ?></a></p>
			</div>
		</div>
		<?php
	}
}
