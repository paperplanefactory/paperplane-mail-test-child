<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	$sites = get_sites( array( 'number' => 0 ) );
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		delete_option( 'pp_mt_secret_key' );
		restore_current_blog();
	}
} else {
	delete_option( 'pp_mt_secret_key' );
}
