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

		// AJAX handled by dedicated Ajax_Handler class.
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'bbcsr-styles', BBCSR_PLUGIN_URL . 'assets/css/bbcsr.css', [], BBCSR_VERSION );
		wp_enqueue_style( 'bbcsr-seller-styles', BBCSR_PLUGIN_URL . 'assets/css/seller-reviews.css', [ 'bbcsr-styles' ], BBCSR_VERSION );
		wp_enqueue_script( 'bbcsr-scripts', BBCSR_PLUGIN_URL . 'assets/js/bbcsr.js', [ 'jquery' ], BBCSR_VERSION, true );
		wp_enqueue_script( 'bbcsr-seller-reviews', BBCSR_PLUGIN_URL . 'assets/js/seller-reviews.js', [ 'jquery' ], BBCSR_VERSION, true );
		wp_localize_script( 'bbcsr-scripts', 'BBCSR', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bbcsr_nonce' ),
		] );
		wp_localize_script( 'bbcsr-seller-reviews', 'BBCSR', [
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
		echo $this->get_summary_box_html( $user_id );
	}

	public function get_summary_box_html( $user_id ) {
		$core      = BB_Seller_Reviews::instance();
		$summary   = $core->get_seller_rating_summary( $user_id );
		$breakdown = $core->get_rating_breakdown( $user_id );
		$stars     = $this->get_stars_html( (float) $summary['avg_rating'] );
		ob_start();
		echo '<div class="bbcsr-summary-box bb-grid">';
		echo '<div class="bbcsr-avg">';
		echo '<div class="bbcsr-avg-stars" aria-label="' . esc_attr__( 'Average rating', 'bb-credit-seller-reviews' ) . '">' . $stars . '</div>';
		echo '<div class="bbcsr-avg-number">' . esc_html( number_format_i18n( (float) $summary['avg_rating'], 1 ) ) . '</div>';
		echo '<div class="bbcsr-total">' . esc_html( sprintf( _n( '%s review', '%s reviews', (int) $summary['total'], 'bb-credit-seller-reviews' ), number_format_i18n( (int) $summary['total'] ) ) ) . '</div>';
		echo '</div>';
		echo '<div class="bbcsr-breakdown" aria-label="' . esc_attr__( 'Rating breakdown', 'bb-credit-seller-reviews' ) . '">';
		for ( $r = 5; $r >= 1; $r-- ) {
			$count = isset( $breakdown['breakdown'][ $r ] ) ? (int) $breakdown['breakdown'][ $r ] : 0;
			$total = max( 1, (int) $breakdown['total'] );
			$percent = min( 100, round( ( $count / $total ) * 100 ) );
			echo '<div class="bbcsr-breakdown-row">';
			echo '<span class="bbcsr-row-label">' . esc_html( (string) $r ) . '★</span>';
			echo '<span class="bbcsr-row-bar"><span style="width:' . esc_attr( (string) $percent ) . '%"></span></span>';
			echo '<span class="bbcsr-row-count">' . esc_html( number_format_i18n( $count ) ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
		return (string) ob_get_clean();
	}

	public function render_reviews_section() {
		$user_id = bp_displayed_user_id();
		$core    = BB_Seller_Reviews::instance();
		if ( ! $core->user_is_credit_seller( $user_id ) ) {
			return;
		}

		echo '<div id="bbcsr-reviews" class="bbcsr-reviews bb-grid">';
		echo '<h3 class="section-title">' . esc_html__( 'Seller Reviews', 'bb-credit-seller-reviews' ) . '</h3>';

		$reviews = $core->get_seller_reviews( $user_id, 10, 0 );
		if ( ! empty( $reviews ) ) {
			echo '<ul class="bbcsr-review-list">';
			foreach ( $reviews as $review ) {
				echo $this->get_single_review_html( $review );
			}
			echo '</ul>';
		} else {
			echo '<p class="bbcsr-no-reviews">' . esc_html__( 'No reviews yet.', 'bb-credit-seller-reviews' ) . '</p>';
		}

		$this->render_review_form( $user_id );
		echo '</div>';
	}

	public function get_single_review_html( $review ) {
		$reviewer_id = (int) $review->reviewer_id;
		$avatar      = function_exists( 'bp_core_fetch_avatar' ) ? bp_core_fetch_avatar( [ 'item_id' => $reviewer_id, 'type' => 'thumb', 'width' => 40, 'height' => 40, 'html' => true ] ) : get_avatar( $reviewer_id, 40 );
		$name        = function_exists( 'bp_core_get_user_displayname' ) ? bp_core_get_user_displayname( $reviewer_id ) : get_the_author_meta( 'display_name', $reviewer_id );
		$stars       = $this->get_stars_html( (float) $review->rating );
		$can_manage  = current_user_can( 'manage_bb_seller_reviews' );
		$can_edit    = is_user_logged_in() && ( get_current_user_id() === $reviewer_id || $can_manage );
		ob_start();
		echo '<li class="bbcsr-review-item bb-card" data-review-id="' . esc_attr( (string) $review->id ) . '">';
		echo '<div class="bbcsr-review-meta">';
		echo '<span class="bbcsr-avatar">' . $avatar . '</span>';
		echo '<span class="bbcsr-name">' . esc_html( $name ) . '</span>';
		echo '<span class="bbcsr-stars">' . $stars . '</span>';
		echo '<span class="bbcsr-date">' . esc_html( mysql2date( get_option( 'date_format' ), $review->review_date ) ) . '</span>';
		echo '</div>';
		echo '<div class="bbcsr-review-text">' . wp_kses_post( wpautop( $review->review_text ) ) . '</div>';
		if ( $can_edit ) {
			echo '<div class="bbcsr-review-actions">';
			echo '<button class="button is-small bbcsr-edit-review">' . esc_html__( 'Edit', 'bb-credit-seller-reviews' ) . '</button> ';
			echo '<button class="button is-small bbcsr-delete-review">' . esc_html__( 'Delete', 'bb-credit-seller-reviews' ) . '</button> ';
			echo '<button class="button is-small bbcsr-flag-review">' . esc_html__( 'Report', 'bb-credit-seller-reviews' ) . '</button>';
			echo '</div>';
		}
		echo '</li>';
		return (string) ob_get_clean();
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
		$core = BB_Seller_Reviews::instance();
		$existing = $core->get_user_review_by_reviewer( $seller_id, get_current_user_id() );
		if ( $existing ) {
			echo '<p>' . esc_html__( 'You have already submitted a review for this seller.', 'bb-credit-seller-reviews' ) . '</p>';
			return;
		}

		echo '<form id="bbcsr-review-form" method="post" class="bb-form" aria-label="' . esc_attr__( 'Submit a review', 'bb-credit-seller-reviews' ) . '">';
		echo '<div class="bbcsr-field">';
		echo '<label class="bb-label">' . esc_html__( 'Your Rating', 'bb-credit-seller-reviews' ) . '</label>';
		echo '<div class="bbcsr-stars-input" role="radiogroup" aria-label="' . esc_attr__( 'Select a rating', 'bb-credit-seller-reviews' ) . '">';
		for ( $i = 1; $i <= 5; $i++ ) {
			$val = (string) $i;
			echo '<input type="radio" id="bbcsr-star-' . esc_attr( $val ) . '" name="rating" value="' . esc_attr( $val ) . '" aria-label="' . esc_attr( sprintf( __( '%s star', 'bb-credit-seller-reviews' ), $val ) ) . '" />';
			echo '<label for="bbcsr-star-' . esc_attr( $val ) . '" class="bbcsr-star">★</label>';
		}
		echo '</div>';
		echo '</div>';
		echo '<div class="bbcsr-field">';
		echo '<label class="bb-label" for="bbcsr-review-text">' . esc_html__( 'Your Review', 'bb-credit-seller-reviews' ) . '</label>';
		echo '<textarea id="bbcsr-review-text" name="review_text" rows="5" maxlength="4000" required aria-required="true"></textarea>';
		echo '<small class="bbcsr-help">' . esc_html__( 'Max 500 words.', 'bb-credit-seller-reviews' ) . '</small>';
		echo '</div>';
		echo '<input type="hidden" name="seller_id" value="' . esc_attr( (string) $seller_id ) . '" />';
		echo '<input type="hidden" name="action" value="bbcsr_submit_review" />';
		echo '<input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'bbcsr_nonce' ) ) . '" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Submit Review', 'bb-credit-seller-reviews' ) . '</button>';
		echo '</form>';
	}

	public function get_stars_html( $rating ) {
		$rating = max( 0.0, min( 5.0, (float) $rating ) );
		$full   = (int) floor( $rating );
		$half   = ( $rating - $full ) >= 0.5 ? 1 : 0;
		$empty  = 5 - $full - $half;
		$html   = '<span class="bbcsr-stars" aria-hidden="true">';
		$html  .= str_repeat( '<span class="star full">★</span>', $full );
		$html  .= str_repeat( '<span class="star half">☆</span>', $half );
		$html  .= str_repeat( '<span class="star empty">☆</span>', $empty );
		$html  .= '</span>';
		return $html;
	}

    // AJAX moved to Ajax_Handler class.
}

