<?php
// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$keep = get_option( 'bbcsr_keep_data', false );

// Remove options
delete_option( 'bbcsr_default_status' );
delete_option( 'bbcsr_enable_multiple_reviews' );
delete_option( 'bbcsr_enable_reviews' );
delete_option( 'bbcsr_min_chars' );
delete_option( 'bbcsr_allow_editing' );
delete_option( 'bbcsr_display_order' );
delete_option( 'bbcsr_profanity_list' );
delete_option( 'bbcsr_keep_data' );

if ( ! $keep ) {
	global $wpdb;
	$table = $wpdb->prefix . 'bb_seller_reviews';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

