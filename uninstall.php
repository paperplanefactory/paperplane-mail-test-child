<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function pp_mt_uninstall_cleanup() {
	global $wpdb;
	delete_option( 'pp_mt_secret_key' );
	delete_option( 'pp_mt_key_copied' );
	// Transient: chiave suggerita (in chiaro, flusso legacy) e contatori rate limit (chiave per IP).
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '\_transient\_pp\_mt\_%'
		OR option_name LIKE '\_transient\_timeout\_pp\_mt\_%'"
	);
	wp_cache_flush();
}

if ( is_multisite() ) {
	$sites = get_sites( array( 'number' => 0 ) );
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		pp_mt_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	pp_mt_uninstall_cleanup();
}
