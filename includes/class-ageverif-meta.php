<?php
/**
 * Per-post override meta box for the age gate.
 *
 * Editors can mark a given piece of content as "always gate" or "skip gate",
 * overriding the global AgeVerif settings. The override is stored in
 * `_ageverif_protection` post meta (`''` = inherit, `on` = force, `off` = skip).
 */

namespace AgeVerif;

defined( 'ABSPATH' ) || exit;

class AgeVerif_Meta {

	const META_KEY = '_ageverif_protection';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
	}

	public function add_meta_box() {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);

		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}
			add_meta_box(
				'ageverif-protection',
				__( 'Age Gate', 'ageverif-wordpress' ),
				array( $this, 'render_meta_box' ),
				$pt,
				'side',
				'default',
				array( '__back_compat_meta_box' => true )
			);
		}
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'ageverif_meta_box', 'ageverif_meta_nonce' );
		$value = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! in_array( $value, array( '', 'on', 'off' ), true ) ) {
			$value = '';
		}
		?>
		<fieldset class="ageverif-meta-box">
			<legend class="screen-reader-text"><?php esc_html_e( 'Age Gate', 'ageverif-wordpress' ); ?></legend>
			<p>
				<label>
					<input type="radio" name="ageverif_protection" value="" <?php checked( $value, '' ); ?> />
					<?php esc_html_e( 'Use site default', 'ageverif-wordpress' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="radio" name="ageverif_protection" value="on" <?php checked( $value, 'on' ); ?> />
					<?php esc_html_e( 'Always show age gate on this content', 'ageverif-wordpress' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="radio" name="ageverif_protection" value="off" <?php checked( $value, 'off' ); ?> />
					<?php esc_html_e( 'Skip age gate on this content', 'ageverif-wordpress' ); ?>
				</label>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: %s: settings link */
					wp_kses(
						__( 'Site defaults are configured under <a href="%s">Settings → AgeVerif</a>.', 'ageverif-wordpress' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'options-general.php?page=ageverif' ) )
				);
				?>
			</p>
		</fieldset>
		<?php
	}

	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['ageverif_meta_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['ageverif_meta_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'ageverif_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw  = isset( $_POST['ageverif_protection'] ) ? sanitize_text_field( wp_unslash( $_POST['ageverif_protection'] ) ) : '';
		if ( ! in_array( $raw, array( '', 'on', 'off' ), true ) ) {
			$raw = '';
		}

		if ( '' === $raw ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $raw );
		}
	}

	public static function get_override( $post_id ) {
		if ( ! $post_id ) {
			return '';
		}
		$val = get_post_meta( $post_id, self::META_KEY, true );
		return is_string( $val ) && in_array( $val, array( '', 'on', 'off' ), true ) ? $val : '';
	}
}
