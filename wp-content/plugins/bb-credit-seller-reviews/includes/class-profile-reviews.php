<?php
namespace BB\CreditSellerReviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Profile_Reviews {
	/** @var Profile_Reviews */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'bp_profile_header_meta', [ $this, 'render_profile_rating_summary' ] );
		add_action( 'bp_after_profile_loop_content', [ $this, 'render_reviews_section' ] );

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_bbcsr_submit_review', [ $this, 'handle_submit_review' ] );
		add_action( 'wp_ajax_nopriv_bbcsr_submit_review', [ $this, 'handle_submit_review' ] );
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'bbcsr-styles', BBCSR_PLUGIN_URL . 'assets/css/bbcsr.css', [], BBCSR_VERSION );
		wp_enqueue_script( 'bbcsr-scripts', BBCSR_PLUGIN_URL . 'assets/js/bbcsr.js', [ 'jquery' ], BBCSR_VERSION, true );
		wp_localize_script( 'bbcsr-scripts', 'BBCSR', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bbcsr_nonce' ),
		] );
	}

	public function render_profile_rating_summary() {
		$user_id = bp_displayed_user_id();
		$core    = BB_Seller_Reviews::instance();
		if ( ! $core->user_is_credit_seller( $user_id ) ) {
			return;
		}
		$summary = $core->get_seller_rating_summary( $user_id );
		$stars   = $this->get_stars_html( (float) $summary['avg_rating'] );
		echo '<div class="bbcsr-summary">' . $stars . ' <span class="bbcsr-total">(' . esc_html( (string) $summary['total'] ) . ')</span></div>';
	}

	public function render_reviews_section() {
		$user_id = bp_displayed_user_id();
		$core    = BB_Seller_Reviews::instance();
		if ( ! $core->user_is_credit_seller( $user_id ) ) {
			return;
		}

		echo '<div id="bbcsr-reviews" class="bbcsr-reviews">';
		echo '<h3>' . esc_html__( 'Seller Reviews', 'bb-credit-seller-reviews' ) . '</h3>';

		$reviews = $core->get_reviews_for_seller( $user_id, 'approved', 1, 10 );
		if ( ! empty( $reviews ) ) {
			echo '<ul class="bbcsr-review-list">';
			foreach ( $reviews as $review ) {
				$stars = $this->get_stars_html( (float) $review->rating );
				echo '<li class="bbcsr-review-item">';
				echo '<div class="bbcsr-review-header">' . $stars . ' <span class="bbcsr-date">' . esc_html( mysql2date( get_option( 'date_format' ), $review->review_date ) ) . '</span></div>';
				echo '<div class="bbcsr-review-text">' . wp_kses_post( wpautop( $review->review_text ) ) . '</div>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="bbcsr-no-reviews">' . esc_html__( 'No reviews yet.', 'bb-credit-seller-reviews' ) . '</p>';
		}

		$this->render_review_form( $user_id );
		echo '</div>';
	}

	private function render_review_form( $seller_id ) {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'Log in to submit a review.', 'bb-credit-seller-reviews' ) . '</p>';
			return;
		}
		if ( get_current_user_id() === (int) $seller_id ) {
			echo '<p>' . esc_html__( 'You cannot review yourself.', 'bb-credit-seller-reviews' ) . '</p>';
			return;
		}

		echo '<form id="bbcsr-review-form" method="post">';
		echo '<div class="bbcsr-field">';
		echo '<label>' . esc_html__( 'Rating', 'bb-credit-seller-reviews' ) . '</label>';
		echo '<select name="rating" required>';
		for ( $i = 5; $i >= 1; $i-- ) {
			echo '<option value="' . esc_attr( (string) $i ) . '">' . esc_html( (string) $i ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
		echo '<div class="bbcsr-field">';
		echo '<label>' . esc_html__( 'Review', 'bb-credit-seller-reviews' ) . '</label>';
		echo '<textarea name="review_text" rows="4" required></textarea>';
		echo '</div>';
		echo '<input type="hidden" name="seller_id" value="' . esc_attr( (string) $seller_id ) . '" />';
		echo '<input type="hidden" name="action" value="bbcsr_submit_review" />';
		echo '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'bbcsr_nonce' ) ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Submit Review', 'bb-credit-seller-reviews' ) . '</button>';
		echo '</form>';
	}

	public function get_stars_html( $rating ) {
		$rating = max( 0.0, min( 5.0, (float) $rating ) );
		$full   = (int) floor( $rating );
		$half   = ( $rating - $full ) >= 0.5 ? 1 : 0;
		$empty  = 5 - $full - $half;
		$html   = '<span class="bbcsr-stars">';
		$html  .= str_repeat( '<span class="star full">★</span>', $full );
		$html  .= str_repeat( '<span class="star half">☆</span>', $half );
		$html  .= str_repeat( '<span class="star empty">☆</span>', $empty );
		$html  .= '</span>';
		return $html;
	}

	public function handle_submit_review() {
		check_ajax_referer( 'bbcsr_nonce', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'bb-credit-seller-reviews' ) ] );
		}
		$seller_id   = isset( $_POST['seller_id'] ) ? absint( $_POST['seller_id'] ) : 0;
		$rating      = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
		$review_text = isset( $_POST['review_text'] ) ? wp_unslash( $_POST['review_text'] ) : '';

		$core = BB_Seller_Reviews::instance();
		$result = $core->insert_review( $seller_id, get_current_user_id(), $rating, $review_text );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$status = get_option( 'bbcsr_default_status', 'pending' );
		$message = 'approved' === $status
			? __( 'Review submitted successfully.', 'bb-credit-seller-reviews' )
			: __( 'Review submitted and awaits approval.', 'bb-credit-seller-reviews' );

		wp_send_json_success( [ 'message' => $message ] );
	}
}

