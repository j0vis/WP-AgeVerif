<?php
/**
 * Uninstall handler.
 *
 * Removes every option and per-post override meta created by the plugin.
 * Kept best-effort: even if one delete fails the others still run.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ageverif_options' );
delete_option( 'ageverif_version' );

// Per-post protection override meta.
global $wpdb;
if ( isset( $wpdb->postmeta ) ) {
	$wpdb->delete(
		$wpdb->postmeta,
		array( 'meta_key' => AgeVerif_Meta::META_KEY ?? '_ageverif_protection' ),
		array( '%s' )
	);
}
