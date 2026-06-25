<?php
namespace AgeVerif;

defined( 'ABSPATH' ) || exit;

class AgeVerif_Frontend {

	private $options;

	public function __construct() {
		$this->options = get_option( 'ageverif_options', array() );
		add_action( 'wp', array( $this, 'maybe_enqueue_checker' ) );
	}

	public function maybe_enqueue_checker() {
		if ( $this->should_load_checker() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'wp_head', array( $this, 'output_custom_css' ), 999 );
		}
	}

	private function should_load_checker() {
		$test_mode = ! empty( $this->options['test_mode'] );

		if ( $test_mode && ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$key = $this->get_active_key();
		if ( empty( $key ) ) {
			return false;
		}

		if ( $this->is_excluded_url() ) {
			return false;
		}

		if ( $this->is_protected_post_type() ) {
			return true;
		}

		return false;
	}

	private function get_active_key() {
		$test_mode = ! empty( $this->options['test_mode'] );
		if ( $test_mode && ! empty( $this->options['test_key'] ) ) {
			return $this->options['test_key'];
		}
		if ( ! empty( $this->options['api_key'] ) ) {
			return $this->options['api_key'];
		}
		return '';
	}

	private function is_excluded_url() {
		if ( empty( $this->options['excluded_urls'] ) ) {
			return false;
		}

		$current_url = home_url( add_query_arg( array() ) );
		$lines       = explode( "\n", $this->options['excluded_urls'] );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( 0 === strpos( $line, '/' ) ) {
				$line = home_url( $line );
			}

			if ( trailingslashit( $current_url ) === trailingslashit( $line ) ) {
				return true;
			}
		}

		return false;
	}

	private function is_protected_post_type() {
		if ( is_admin() ) {
			return false;
		}

		$enabled = isset( $this->options['enabled_post_types'] ) ? $this->options['enabled_post_types'] : array();

		if ( empty( $enabled ) ) {
			return false;
		}

		if ( is_singular() ) {
			$post_type = get_post_type();
			return in_array( $post_type, $enabled, true );
		}

		if ( is_home() || is_front_page() ) {
			return in_array( 'page', $enabled, true ) || in_array( 'post', $enabled, true );
		}

		if ( is_archive() || is_tax() ) {
			$queried = get_queried_object();
			if ( $queried && isset( $queried->name ) ) {
				return in_array( $queried->name, $enabled, true );
			}
		}

		return false;
	}

	public function enqueue_scripts() {
		$key = $this->get_active_key();
		if ( empty( $key ) ) {
			return;
		}

		$checker_url = add_query_arg(
			array(
				'key' => $key,
			),
			'https://www.ageverif.com/checker.js'
		);

		wp_enqueue_script(
			'ageverif-checker',
			$checker_url,
			array(),
			null,
			true // load in footer for non‑blocking load
		);
		// Ensure async and defer attributes for best PageSpeed score
		wp_script_add_data( 'ageverif-checker', 'async', true );
		wp_script_add_data( 'ageverif-checker', 'defer', true );

		// Load theme adapter only when Easy Mode is enabled
		if ( ! empty( $this->options['easy_mode'] ) ) {
			wp_enqueue_script(
				'ageverif-theme-adapter',
				AGEVERIF_PLUGIN_URL . 'js/theme-adapter.js',
				array(),
				null,
				true
			);
			// No inline script needed – the adapter runs on load automatically
		}
	}

	public function output_custom_css() {
		$css = isset( $this->options['custom_css'] ) ? $this->options['custom_css'] : '';
		if ( empty( $css ) ) {
			return;
		}
		echo '<style id="ageverif-custom-css" type="text/css">' . "\n" . wp_strip_all_tags( $css ) . "\n" . '</style>' . "\n";
	}
}
