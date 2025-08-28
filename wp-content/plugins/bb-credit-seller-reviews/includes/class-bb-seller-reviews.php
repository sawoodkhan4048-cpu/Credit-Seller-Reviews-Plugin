<?php
namespace BB\CreditSellerReviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BB_Seller_Reviews {
	/** @var BB_Seller_Reviews */
	private static $instance;

	/** @var string */
	private $table_name;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'bb_seller_reviews';

		// Hooks for BuddyBoss integration.
		add_filter( 'bb_profile_types', [ $this, 'filter_profile_types' ], 10, 1 );
	}

	/**
	 * Determine if a user is a Credit Seller via BuddyBoss member/profile types.
	 */
	public function user_is_credit_seller( $user_id ) {
		if ( ! function_exists( 'bp_get_member_type' ) ) {
			return false;
		}
		$types = (array) bp_get_member_type( $user_id, false );
		return in_array( 'credit_seller', $types, true );
	}

	/**
	 * Optional filter placeholder if we need to ensure type registration.
	 */
	public function filter_profile_types( $types ) {
		// Ensure the string slug 'credit_seller' exists in BuddyBoss types (no-op if already present).
		// This plugin does not register the type, it relies on BuddyBoss Platform Pro configuration.
		return $types;
	}

	/**
	 * Insert a review row.
	 */
	public function insert_review( $seller_id, $reviewer_id, $rating, $review_text, $status = null ) {
		global $wpdb;

		$seller_id   = absint( $seller_id );
		$reviewer_id = absint( $reviewer_id );
		$rating      = absint( $rating );
		$rating      = max( 1, min( 5, $rating ) );
		$review_text = wp_kses_post( $review_text );
		$status      = $status ? sanitize_key( $status ) : get_option( 'bbcsr_default_status', 'pending' );

		if ( ! $seller_id || ! $reviewer_id || $seller_id === $reviewer_id ) {
			return new \WP_Error( 'invalid_params', __( 'Invalid seller or reviewer.', 'bb-credit-seller-reviews' ) );
		}

		if ( ! $this->user_is_credit_seller( $seller_id ) ) {
			return new \WP_Error( 'not_seller', __( 'Target user is not a Credit Seller.', 'bb-credit-seller-reviews' ) );
		}

		$allow_multiple = (bool) get_option( 'bbcsr_enable_multiple_reviews', false );
		if ( ! $allow_multiple ) {
			$existing = $this->get_user_review_by_reviewer( $seller_id, $reviewer_id );
			if ( $existing ) {
				return new \WP_Error( 'duplicate', __( 'You have already submitted a review for this seller.', 'bb-credit-seller-reviews' ) );
			}
		}

		$inserted = $wpdb->insert(
			$this->table_name,
			[
				'seller_id'   => $seller_id,
				'reviewer_id' => $reviewer_id,
				'rating'      => $rating,
				'review_text' => $review_text,
				'review_date' => current_time( 'mysql' ),
				'status'      => $status,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_error', __( 'Failed to insert review.', 'bb-credit-seller-reviews' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a single review submitted by a reviewer for a seller.
	 */
	public function get_user_review_by_reviewer( $seller_id, $reviewer_id ) {
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE seller_id = %d AND reviewer_id = %d LIMIT 1",
			absint( $seller_id ),
			absint( $reviewer_id )
		);
		return $wpdb->get_row( $query );
	}

	/**
	 * Get approved reviews for a seller with pagination.
	 */
	public function get_reviews_for_seller( $seller_id, $status = 'approved', $page = 1, $per_page = 10 ) {
		global $wpdb;
		$offset = max( 0, ( absint( $page ) - 1 ) * absint( $per_page ) );
		$sql    = $wpdb->prepare(
			"SELECT * FROM {$this->table_name} WHERE seller_id = %d AND status = %s ORDER BY review_date DESC LIMIT %d OFFSET %d",
			absint( $seller_id ),
			sanitize_key( $status ),
			absint( $per_page ),
			$offset
		);
		return $wpdb->get_results( $sql );
	}

	/**
	 * Get average rating and total approved reviews.
	 */
	public function get_seller_rating_summary( $seller_id ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM {$this->table_name} WHERE seller_id = %d AND status = 'approved'",
			absint( $seller_id )
		);
		$row = $wpdb->get_row( $sql );
		return [
			'avg_rating' => $row ? round( (float) $row->avg_rating, 2 ) : 0.0,
			'total'      => $row ? (int) $row->total : 0,
		];
	}

	/**
	 * Update review status (requires capability).
	 */
	public function update_review_status( $review_id, $status ) {
		if ( ! current_user_can( 'manage_bb_seller_reviews' ) ) {
			return new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'bb-credit-seller-reviews' ) );
		}
		global $wpdb;
		$updated = $wpdb->update(
			$this->table_name,
			[ 'status' => sanitize_key( $status ) ],
			[ 'id' => absint( $review_id ) ],
			[ '%s' ],
			[ '%d' ]
		);
		return false === $updated ? new \WP_Error( 'db_error', __( 'Failed to update status.', 'bb-credit-seller-reviews' ) ) : (bool) $updated;
	}
}

