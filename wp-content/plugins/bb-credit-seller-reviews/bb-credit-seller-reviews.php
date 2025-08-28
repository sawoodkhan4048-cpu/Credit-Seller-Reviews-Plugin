<?php
/**
 * Plugin Name: BuddyBoss Credit Seller Reviews
 * Plugin URI: https://example.com/
 * Description: Adds a review and rating system for BuddyBoss users with the "Credit Seller" profile type.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: bb-credit-seller-reviews
 * Domain Path: /languages
 */

// Security: Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants.
define( 'BBCSR_VERSION', '1.0.0' );
define( 'BBCSR_PLUGIN_FILE', __FILE__ );
define( 'BBCSR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BBCSR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check BuddyBoss Platform/Pro availability.
 * We check for BuddyBoss Platform core (required) and rely on Pro for profile types.
 * If missing, we show an admin notice and deactivate on activation.
 */
function bbcsr_is_buddyboss_active() {
	// BuddyBoss Platform defines BP constants/classes. Fallback to BuddyPress if present in some environments.
	if ( function_exists( 'bp_is_active' ) ) {
		return true;
	}
	// BuddyBoss platform loader class check as an additional guard.
	if ( class_exists( 'BuddyBoss_Platform' ) || class_exists( 'BuddyBoss_Platform_Pro' ) ) {
		return true;
	}
	return false;
}

/**
 * Activation hook: create DB table, set options, add capabilities, and verify dependencies.
 */
function bbcsr_activate() {
	// Dependency check.
	if ( ! bbcsr_is_buddyboss_active() ) {
		// Deactivate self and show error.
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'BuddyBoss Platform (and Pro for profile types) must be active to use BuddyBoss Credit Seller Reviews.', 'bb-credit-seller-reviews' ) );
	}

	// Create database table.
	global $wpdb;
	$table_name      = $wpdb->prefix . 'bb_seller_reviews';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		seller_id BIGINT(20) UNSIGNED NOT NULL,
		reviewer_id BIGINT(20) UNSIGNED NOT NULL,
		rating TINYINT(1) UNSIGNED NOT NULL,
		review_text TEXT NULL,
		review_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		PRIMARY KEY  (id),
		KEY seller_id (seller_id),
		KEY reviewer_id (reviewer_id),
		KEY status (status)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Default options.
	add_option( 'bbcsr_default_status', 'approved' ); // approved by default; can be changed to pending/rejected
	add_option( 'bbcsr_enable_multiple_reviews', false );

	// Capabilities.
	$role = get_role( 'administrator' );
	if ( $role && ! $role->has_cap( 'manage_bb_seller_reviews' ) ) {
		$role->add_cap( 'manage_bb_seller_reviews' );
	}
}
register_activation_hook( __FILE__, 'bbcsr_activate' );

/**
 * Deactivation: no-op for now (keep data). Could later remove caps/options.
 */
function bbcsr_deactivate() {
	// Intentionally left blank to preserve data.
}
register_deactivation_hook( __FILE__, 'bbcsr_deactivate' );

// Load textdomain.
function bbcsr_load_textdomain() {
	load_plugin_textdomain( 'bb-credit-seller-reviews', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'bbcsr_load_textdomain' );

// Block loading if BuddyBoss is not active (runtime guard).
function bbcsr_admin_dependency_notice() {
	if ( ! bbcsr_is_buddyboss_active() ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'BuddyBoss Platform must remain active for BuddyBoss Credit Seller Reviews to function.', 'bb-credit-seller-reviews' )
		);
	}
}
add_action( 'admin_notices', 'bbcsr_admin_dependency_notice' );

// Autoload includes.
require_once BBCSR_PLUGIN_DIR . 'includes/class-bb-seller-reviews.php';
require_once BBCSR_PLUGIN_DIR . 'includes/class-profile-reviews.php';
require_once BBCSR_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once BBCSR_PLUGIN_DIR . 'admin/class-admin-settings.php';

// Bootstrap plugin after BuddyBoss/BuddyPress is loaded.
function bbcsr_bootstrap() {
	if ( ! bbcsr_is_buddyboss_active() ) {
		return;
	}

	\BB\CreditSellerReviews\BB_Seller_Reviews::instance();
	\BB\CreditSellerReviews\Profile_Reviews::instance();
\t\BB\CreditSellerReviews\Ajax_Handler::instance();
\t\BB\CreditSellerReviews\Admin_Settings::instance();
}
add_action( 'bp_include', 'bbcsr_bootstrap' );

