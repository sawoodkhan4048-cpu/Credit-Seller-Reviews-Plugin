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

	/** @var array */
	private $allowed_statuses = [ 'approved', 'pending', 'rejected', 'flagged' ];

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
		add_action( 'bp_setup_nav', [ $this, 'register_profile_tab' ], 20 );
		add_action( 'bp_after_member_header', [ $this, 'maybe_render_header_summary' ], 20 );
	}

	/**
	 * Determine if a user is a Credit Seller via BuddyBoss member/profile types.
	 */
	public function user_is_credit_seller( $user_id ) {
		return $this->is_credit_seller( $user_id );
	}

	/**
	 * Determine if a user is a Credit Seller. Accepts variations like
	 * "credit-seller", "credit_seller", "credit seller", and case variants.
	 */
	public function is_credit_seller( $user_id ) {
		if ( ! function_exists( 'bp_get_member_type' ) ) {
			return false;
		}
		$types = bp_get_member_type( $user_id, false );
		$types = is_array( $types ) ? $types : ( $types ? [ $types ] : [] );
		$normalized = [];
		foreach ( $types as $type ) {
			$slug = is_string( $type ) ? $type : '';
			$slug = strtolower( $slug );
			$slug = str_replace( [ '_', '-', ' ' ], '', $slug );
			if ( $slug ) {
				$normalized[] = $slug;
			}
		}
		return in_array( 'creditseller', $normalized, true );
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
	 * Check if a reviewer can submit a review for a seller.
	 */
	public function can_user_review( $seller_id, $reviewer_id ) {
		$seller_id   = absint( $seller_id );
		$reviewer_id = absint( $reviewer_id );
		if ( ! $seller_id || ! $reviewer_id ) {
			return new \WP_Error( 'invalid_params', __( 'Invalid seller or reviewer.', 'bb-credit-seller-reviews' ) );
		}
		if ( $seller_id === $reviewer_id ) {
			return new \WP_Error( 'self_review', __( 'You cannot review yourself.', 'bb-credit-seller-reviews' ) );
		}
		if ( ! $this->is_credit_seller( $seller_id ) ) {
			return new \WP_Error( 'not_seller', __( 'Target user is not a Credit Seller.', 'bb-credit-seller-reviews' ) );
		}
		$existing = $this->get_user_review_by_reviewer( $seller_id, $reviewer_id );
		if ( $existing ) {
			return new \WP_Error( 'duplicate', __( 'You have already submitted a review for this seller.', 'bb-credit-seller-reviews' ) );
		}
		return true;
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
		$status      = $status ? sanitize_key( $status ) : get_option( 'bbcsr_default_status', 'approved' );
		if ( ! in_array( $status, $this->allowed_statuses, true ) ) {
			$status = 'pending';
		}

		$can = $this->can_user_review( $seller_id, $reviewer_id );
		if ( is_wp_error( $can ) ) {
			return $can;
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
	 * Public API: add_review wrapper.
	 */
	public function add_review( $seller_id, $reviewer_id, $rating, $review_text ) {
		return $this->insert_review( $seller_id, $reviewer_id, $rating, $review_text );
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
	 * Get reviews for a seller with pagination.
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
	 * Advanced query for reviews with filtering and sorting.
	 * Args: status, limit, offset, rating, order_by (review_date|rating), order (DESC|ASC)
	 */
	public function query_reviews( $seller_id, $args = [] ) {
		global $wpdb;
		$defaults = [
			'status'   => 'approved',
			'limit'    => 10,
			'offset'   => 0,
			'rating'   => null,
			'order_by' => 'review_date',
			'order'    => 'DESC',
		];
		$args = wp_parse_args( $args, $defaults );
		$allowed_order_by = [ 'review_date', 'rating' ];
		$allowed_order    = [ 'ASC', 'DESC' ];
		$order_by = in_array( $args['order_by'], $allowed_order_by, true ) ? $args['order_by'] : 'review_date';
		$order    = in_array( strtoupper( $args['order'] ), $allowed_order, true ) ? strtoupper( $args['order'] ) : 'DESC';

		$where = $wpdb->prepare( 'seller_id = %d', absint( $seller_id ) );
		if ( ! empty( $args['status'] ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', sanitize_key( $args['status'] ) );
		}
		if ( $args['rating'] ) {
			$where .= $wpdb->prepare( ' AND rating = %d', max( 1, min( 5, absint( $args['rating'] ) ) ) );
		}

		$limit  = absint( $args['limit'] );
		$offset = absint( $args['offset'] );
		$sql    = "SELECT * FROM {$this->table_name} WHERE {$where} ORDER BY {$order_by} {$order} LIMIT {$limit} OFFSET {$offset}";
		return $wpdb->get_results( $sql );
	}

	/**
	 * Public API: get_seller_reviews wrapper using limit/offset.
	 */
	public function get_seller_reviews( $seller_id, $limit = 10, $offset = 0, $status = 'approved' ) {
		$page = ( $limit > 0 ) ? floor( $offset / $limit ) + 1 : 1;
		return $this->get_reviews_for_seller( $seller_id, $status, $page, $limit );
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
	 * Public API: get_average_rating
	 */
	public function get_average_rating( $seller_id ) {
		$summary = $this->get_seller_rating_summary( $seller_id );
		return (float) $summary['avg_rating'];
	}

	/**
	 * Public API: get_total_reviews_count
	 */
	public function get_total_reviews_count( $seller_id ) {
		$summary = $this->get_seller_rating_summary( $seller_id );
		return (int) $summary['total'];
	}

	/**
	 * Rating breakdown counts per star (1..5) for approved reviews.
	 */
	public function get_rating_breakdown( $seller_id ) {
		global $wpdb;
		$counts = [ 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 ];
		$sql    = $wpdb->prepare(
			"SELECT rating, COUNT(*) as cnt FROM {$this->table_name} WHERE seller_id = %d AND status = 'approved' GROUP BY rating",
			absint( $seller_id )
		);
		$rows = $wpdb->get_results( $sql );
		foreach ( (array) $rows as $row ) {
			$rating = (int) $row->rating;
			if ( isset( $counts[ $rating ] ) ) {
				$counts[ $rating ] = (int) $row->cnt;
			}
		}
		$summary = $this->get_seller_rating_summary( $seller_id );
		return [
			'breakdown' => $counts,
			'avg'       => (float) $summary['avg_rating'],
			'total'     => (int) $summary['total'],
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

	/**
	 * Update a review (rating and text). Only author or manager can update.
	 */
	public function update_review( $review_id, $rating, $review_text ) {
		global $wpdb;
		$review_id   = absint( $review_id );
		$rating      = max( 1, min( 5, absint( $rating ) ) );
		$review_text = wp_kses_post( $review_text );

		$review = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $review_id ) );
		if ( ! $review ) {
			return new \WP_Error( 'not_found', __( 'Review not found.', 'bb-credit-seller-reviews' ) );
		}
		if ( (int) $review->reviewer_id !== get_current_user_id() && ! current_user_can( 'manage_bb_seller_reviews' ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot edit this review.', 'bb-credit-seller-reviews' ) );
		}
		$updated = $wpdb->update(
			$this->table_name,
			[ 'rating' => $rating, 'review_text' => $review_text ],
			[ 'id' => $review_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
		return false === $updated ? new \WP_Error( 'db_error', __( 'Failed to update review.', 'bb-credit-seller-reviews' ) ) : (bool) $updated;
	}

	/**
	 * Delete a review. Only author or manager can delete.
	 */
	public function delete_review( $review_id ) {
		global $wpdb;
		$review_id = absint( $review_id );
		$review = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE id = %d", $review_id ) );
		if ( ! $review ) {
			return new \WP_Error( 'not_found', __( 'Review not found.', 'bb-credit-seller-reviews' ) );
		}
		if ( (int) $review->reviewer_id !== get_current_user_id() && ! current_user_can( 'manage_bb_seller_reviews' ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot delete this review.', 'bb-credit-seller-reviews' ) );
		}
		$deleted = $wpdb->delete( $this->table_name, [ 'id' => $review_id ], [ '%d' ] );
		return false === $deleted ? new \WP_Error( 'db_error', __( 'Failed to delete review.', 'bb-credit-seller-reviews' ) ) : (bool) $deleted;
	}

	/**
	 * Flag a review as inappropriate.
	 */
	public function flag_review( $review_id, $reason = '' ) {
		// Store as status change for now; fire an action with the reason for integrations.
		$updated = $this->update_review_status( $review_id, 'flagged' );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		do_action( 'bbcsr_review_flagged', $review_id, wp_kses_post( $reason ) );
		return $updated;
	}

	/**
	 * Register BuddyBoss profile tab for Seller Reviews.
	 */
	public function register_profile_tab() {
		if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
			return;
		}
		$user_id = function_exists( 'bp_displayed_user_id' ) ? bp_displayed_user_id() : 0;
		if ( ! $user_id || ! $this->is_credit_seller( $user_id ) ) {
			return;
		}
		bp_core_new_nav_item( [
			'name'                => __( 'Seller Reviews', 'bb-credit-seller-reviews' ),
			'slug'                => 'seller-reviews',
			'position'            => 80,
			'screen_function'     => [ $this, 'screen_reviews' ],
			'default_subnav_slug' => 'seller-reviews',
		] );
	}

	public function screen_reviews() {
		add_action( 'bp_template_content', [ $this, 'render_reviews_screen' ] );
		bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
	}

	public function render_reviews_screen() {
		// Defer to frontend class rendering if available.
		if ( class_exists( '\\BB\\CreditSellerReviews\\Profile_Reviews' ) ) {
			\BB\CreditSellerReviews\Profile_Reviews::instance()->render_reviews_section();
		}
	}

	public function maybe_render_header_summary() {
		if ( class_exists( '\\BB\\CreditSellerReviews\\Profile_Reviews' ) ) {
			\BB\CreditSellerReviews\Profile_Reviews::instance()->render_profile_rating_summary();
		}
	}
}

