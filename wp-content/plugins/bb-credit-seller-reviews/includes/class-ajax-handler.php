<?php
namespace BB\CreditSellerReviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax_Handler {
	/** @var Ajax_Handler */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_bbcsr_submit_review', [ $this, 'submit_review' ] );
		add_action( 'wp_ajax_nopriv_bbcsr_submit_review', [ $this, 'submit_review' ] );

		add_action( 'wp_ajax_bbcsr_delete_review', [ $this, 'delete_review' ] );
		add_action( 'wp_ajax_bbcsr_edit_review', [ $this, 'edit_review' ] );
		add_action( 'wp_ajax_bbcsr_flag_review', [ $this, 'flag_review' ] );
		add_action( 'wp_ajax_bbcsr_load_reviews', [ $this, 'load_reviews' ] );
	}

	private function check_nonce( $action ) {
		if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], $action ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'bb-credit-seller-reviews' ) ] );
		}
	}

	public function submit_review() {
		$this->check_nonce( 'bbcsr_nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'bb-credit-seller-reviews' ) ] );
		}
		$seller_id   = isset( $_POST['seller_id'] ) ? absint( $_POST['seller_id'] ) : 0;
		$rating      = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
		$review_text = isset( $_POST['review_text'] ) ? wp_unslash( $_POST['review_text'] ) : '';

		$word_count = str_word_count( wp_strip_all_tags( $review_text ) );
		if ( $word_count > 500 ) {
			wp_send_json_error( [ 'message' => __( 'Review exceeds 500 words.', 'bb-credit-seller-reviews' ) ] );
		}

		$core   = BB_Seller_Reviews::instance();
		$result = $core->insert_review( $seller_id, get_current_user_id(), $rating, $review_text );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Load the freshly inserted review row
		global $wpdb;
		$table = $wpdb->prefix . 'bb_seller_reviews';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $result ) );
		$html  = '';
		if ( $row && class_exists( '\\BB\\CreditSellerReviews\\Profile_Reviews' ) ) {
			$html = \BB\CreditSellerReviews\Profile_Reviews::instance()->get_single_review_html( $row );
		}

		$summary_html = '';
		if ( class_exists( '\\BB\\CreditSellerReviews\\Profile_Reviews' ) ) {
			$summary_html = \BB\CreditSellerReviews\Profile_Reviews::instance()->get_summary_box_html( $seller_id );
		}

		$status = get_option( 'bbcsr_default_status', 'approved' );
		$message = 'approved' === $status
			? __( 'Review submitted successfully.', 'bb-credit-seller-reviews' )
			: __( 'Review submitted and awaits approval.', 'bb-credit-seller-reviews' );

		wp_send_json_success( [ 'message' => $message, 'html' => $html, 'summary' => $summary_html ] );
	}

	public function delete_review() {
		$this->check_nonce( 'bbcsr_nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'bb-credit-seller-reviews' ) ] );
		}
		$review_id = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		$core = BB_Seller_Reviews::instance();
		$deleted = $core->delete_review( $review_id );
		if ( is_wp_error( $deleted ) ) {
			wp_send_json_error( [ 'message' => $deleted->get_error_message() ] );
		}
		wp_send_json_success( [ 'message' => __( 'Review deleted.', 'bb-credit-seller-reviews' ) ] );
	}

	public function edit_review() {
		$this->check_nonce( 'bbcsr_nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'bb-credit-seller-reviews' ) ] );
		}
		$review_id   = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		$rating      = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
		$review_text = isset( $_POST['review_text'] ) ? wp_unslash( $_POST['review_text'] ) : '';

		$word_count = str_word_count( wp_strip_all_tags( $review_text ) );
		if ( $word_count > 500 ) {
			wp_send_json_error( [ 'message' => __( 'Review exceeds 500 words.', 'bb-credit-seller-reviews' ) ] );
		}

		$core = BB_Seller_Reviews::instance();
		$updated = $core->update_review( $review_id, $rating, $review_text );
		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( [ 'message' => $updated->get_error_message() ] );
		}
		wp_send_json_success( [ 'message' => __( 'Review updated.', 'bb-credit-seller-reviews' ) ] );
	}

	public function flag_review() {
		$this->check_nonce( 'bbcsr_nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'bb-credit-seller-reviews' ) ] );
		}
		$review_id = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		$reason    = isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '';
		$core     = BB_Seller_Reviews::instance();
		$flagged  = $core->flag_review( $review_id, $reason );
		if ( is_wp_error( $flagged ) ) {
			wp_send_json_error( [ 'message' => $flagged->get_error_message() ] );
		}
		wp_send_json_success( [ 'message' => __( 'Review reported.', 'bb-credit-seller-reviews' ) ] );
	}

	public function load_reviews() {
		$this->check_nonce( 'bbcsr_nonce' );
		$seller_id = isset( $_POST['seller_id'] ) ? absint( $_POST['seller_id'] ) : 0;
		$limit     = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;
		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$rating    = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : null;
		$order_by  = isset( $_POST['order_by'] ) ? sanitize_key( $_POST['order_by'] ) : 'review_date';
		$order     = isset( $_POST['order'] ) ? strtoupper( sanitize_key( $_POST['order'] ) ) : 'DESC';

		$core = BB_Seller_Reviews::instance();
		$rows = $core->query_reviews( $seller_id, [
			'limit'    => $limit,
			'offset'   => $offset,
			'rating'   => $rating,
			'order_by' => $order_by,
			'order'    => $order,
		] );

		$html = '';
		if ( class_exists( '\\BB\\CreditSellerReviews\\Profile_Reviews' ) ) {
			$renderer = \BB\CreditSellerReviews\Profile_Reviews::instance();
			foreach ( (array) $rows as $row ) {
				$html .= $renderer->get_single_review_html( $row );
			}
		}
		wp_send_json_success( [ 'html' => $html ] );
	}
}

