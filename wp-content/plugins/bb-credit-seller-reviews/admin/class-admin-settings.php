<?php
namespace BB\CreditSellerReviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Settings {
	/** @var Admin_Settings */
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_bbcsr_export_csv', [ $this, 'export_csv' ] );
	}

	public function register_menu() {
		$parent = 'buddyboss-platform';
		$cap    = 'manage_options';
		$hook   = null;
		if ( $this->menu_exists( $parent ) ) {
			$hook = add_submenu_page(
				$parent,
				__( 'Seller Reviews', 'bb-credit-seller-reviews' ),
				__( 'Seller Reviews', 'bb-credit-seller-reviews' ),
				$cap,
				'bbcsr-settings',
				[ $this, 'render_page' ]
			);
		} else {
			$hook = add_options_page(
				__( 'Seller Reviews', 'bb-credit-seller-reviews' ),
				__( 'Seller Reviews', 'bb-credit-seller-reviews' ),
				$cap,
				'bbcsr-settings',
				[ $this, 'render_page' ]
			);
		}
		add_action( "load-$hook", [ $this, 'maybe_handle_actions' ] );
	}

	private function menu_exists( $slug ) {
		global $menu, $submenu;
		return isset( $submenu[ $slug ] ) || isset( $menu );
	}

	public function register_settings() {
		register_setting( 'bbcsr_settings', 'bbcsr_enable_reviews', [ 'type' => 'boolean', 'default' => true ] );
		register_setting( 'bbcsr_settings', 'bbcsr_default_status', [ 'type' => 'string', 'default' => 'approved' ] );
		register_setting( 'bbcsr_settings', 'bbcsr_min_chars', [ 'type' => 'integer', 'default' => 20 ] );
		register_setting( 'bbcsr_settings', 'bbcsr_allow_editing', [ 'type' => 'boolean', 'default' => true ] );
		register_setting( 'bbcsr_settings', 'bbcsr_display_order', [ 'type' => 'string', 'default' => 'newest' ] );
		register_setting( 'bbcsr_settings', 'bbcsr_profanity_list', [ 'type' => 'string', 'default' => '' ] );
		register_setting( 'bbcsr_settings', 'bbcsr_keep_data', [ 'type' => 'boolean', 'default' => false ] );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BuddyBoss Seller Reviews', 'bb-credit-seller-reviews' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		$this->tab_link( 'general', $active_tab, __( 'General Settings', 'bb-credit-seller-reviews' ) );
		$this->tab_link( 'reviews', $active_tab, __( 'Manage Reviews', 'bb-credit-seller-reviews' ) );
		$this->tab_link( 'analytics', $active_tab, __( 'Analytics', 'bb-credit-seller-reviews' ) );
		echo '</h2>';

		if ( 'general' === $active_tab ) {
			$this->render_settings_tab();
		} elseif ( 'reviews' === $active_tab ) {
			$this->render_reviews_tab();
		} else {
			$this->render_analytics_tab();
		}
		echo '</div>';
	}

	private function tab_link( $slug, $active, $label ) {
		$url = add_query_arg( [ 'page' => 'bbcsr-settings', 'tab' => $slug ], admin_url( 'admin.php' ) );
		$class = 'nav-tab' . ( $slug === $active ? ' nav-tab-active' : '' );
		echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
	}

	private function render_settings_tab() {
		echo '<form method="post" action="options.php" class="bbcsr-admin-form">';
		settings_fields( 'bbcsr_settings' );
		echo '<table class="form-table">';
		$this->checkbox_row( 'bbcsr_enable_reviews', __( 'Enable reviews', 'bb-credit-seller-reviews' ) );
		$this->select_row( 'bbcsr_default_status', __( 'Review moderation', 'bb-credit-seller-reviews' ), [ 'approved' => __( 'Auto-approve', 'bb-credit-seller-reviews' ), 'pending' => __( 'Manual moderation', 'bb-credit-seller-reviews' ) ] );
		$this->number_row( 'bbcsr_min_chars', __( 'Minimum characters', 'bb-credit-seller-reviews' ), 0 );
		$this->checkbox_row( 'bbcsr_allow_editing', __( 'Allow review editing', 'bb-credit-seller-reviews' ) );
		$this->select_row( 'bbcsr_display_order', __( 'Display order', 'bb-credit-seller-reviews' ), [ 'newest' => __( 'Newest first', 'bb-credit-seller-reviews' ), 'oldest' => __( 'Oldest first', 'bb-credit-seller-reviews' ), 'highest' => __( 'Highest rating', 'bb-credit-seller-reviews' ), 'lowest' => __( 'Lowest rating', 'bb-credit-seller-reviews' ) ] );
		$this->textarea_row( 'bbcsr_profanity_list', __( 'Profanity filter (comma-separated)', 'bb-credit-seller-reviews' ) );
		$this->checkbox_row( 'bbcsr_keep_data', __( 'Keep data on uninstall', 'bb-credit-seller-reviews' ) );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	private function render_reviews_tab() {
		if ( ! current_user_can( 'manage_bb_seller_reviews' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to manage reviews.', 'bb-credit-seller-reviews' ) . '</p>';
			return;
		}
		// Filters
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$rating = isset( $_GET['rating'] ) ? absint( $_GET['rating'] ) : 0;
		$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=bbcsr_export_csv' ), 'bbcsr_export_csv' );
		echo '<p><a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Export CSV', 'bb-credit-seller-reviews' ) . '</a></p>';

		// List table simplified
		global $wpdb;
		$table = $wpdb->prefix . 'bb_seller_reviews';
		$where = '1=1';
		if ( $status ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		if ( $rating ) {
			$where .= $wpdb->prepare( ' AND rating = %d', $rating );
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY review_date DESC LIMIT 100" );
		echo '<table class="widefat striped">';
		echo '<thead><tr><th>' . esc_html__( 'ID', 'bb-credit-seller-reviews' ) . '</th><th>' . esc_html__( 'Seller', 'bb-credit-seller-reviews' ) . '</th><th>' . esc_html__( 'Reviewer', 'bb-credit-seller-reviews' ) . '</th><th>' . esc_html__( 'Rating', 'bb-credit-seller-reviews' ) . '</th><th>' . esc_html__( 'Status', 'bb-credit-seller-reviews' ) . '</th><th>' . esc_html__( 'Date', 'bb-credit-seller-reviews' ) . '</th><th>' . esc_html__( 'Actions', 'bb-credit-seller-reviews' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			$approve_url = wp_nonce_url( add_query_arg( [ 'page' => 'bbcsr-settings', 'tab' => 'reviews', 'bbcsr_action' => 'approve', 'id' => $r->id ], admin_url( 'admin.php' ) ), 'bbcsr_review_action' );
			$reject_url  = wp_nonce_url( add_query_arg( [ 'page' => 'bbcsr-settings', 'tab' => 'reviews', 'bbcsr_action' => 'reject', 'id' => $r->id ], admin_url( 'admin.php' ) ), 'bbcsr_review_action' );
			$delete_url  = wp_nonce_url( add_query_arg( [ 'page' => 'bbcsr-settings', 'tab' => 'reviews', 'bbcsr_action' => 'delete', 'id' => $r->id ], admin_url( 'admin.php' ) ), 'bbcsr_review_action' );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r->id ) . '</td>'; 
			echo '<td>' . esc_html( (string) $r->seller_id ) . '</td>';
			echo '<td>' . esc_html( (string) $r->reviewer_id ) . '</td>';
			echo '<td>' . esc_html( (string) $r->rating ) . '</td>';
			echo '<td>' . esc_html( (string) $r->status ) . '</td>';
			echo '<td>' . esc_html( $r->review_date ) . '</td>';
			echo '<td><a href="' . esc_url( $approve_url ) . '">' . esc_html__( 'Approve', 'bb-credit-seller-reviews' ) . '</a> | <a href="' . esc_url( $reject_url ) . '">' . esc_html__( 'Reject', 'bb-credit-seller-reviews' ) . '</a> | <a href="' . esc_url( $delete_url ) . '" class="submitdelete">' . esc_html__( 'Delete', 'bb-credit-seller-reviews' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_analytics_tab() {
		global $wpdb;
		$table = $wpdb->prefix . 'bb_seller_reviews';
		$top_rated = $wpdb->get_results( "SELECT seller_id, AVG(rating) avg_rating, COUNT(*) total FROM {$table} WHERE status='approved' GROUP BY seller_id HAVING total >= 3 ORDER BY avg_rating DESC, total DESC LIMIT 10" );
		$most_reviewed = $wpdb->get_results( "SELECT seller_id, COUNT(*) total FROM {$table} WHERE status='approved' GROUP BY seller_id ORDER BY total DESC LIMIT 10" );
		echo '<h3>' . esc_html__( 'Top Rated Sellers', 'bb-credit-seller-reviews' ) . '</h3>';
		echo '<ul>';
		foreach ( (array) $top_rated as $row ) {
			echo '<li>' . esc_html( sprintf( __( 'Seller #%1$s — %2$s stars (%3$s reviews)', 'bb-credit-seller-reviews' ), (string) $row->seller_id, number_format_i18n( (float) $row->avg_rating, 2 ), (string) $row->total ) ) . '</li>';
		}
		echo '</ul>';
		echo '<h3>' . esc_html__( 'Most Reviewed Sellers', 'bb-credit-seller-reviews' ) . '</h3>';
		echo '<ul>';
		foreach ( (array) $most_reviewed as $row ) {
			echo '<li>' . esc_html( sprintf( __( 'Seller #%1$s — %2$s reviews', 'bb-credit-seller-reviews' ), (string) $row->seller_id, (string) $row->total ) ) . '</li>';
		}
		echo '</ul>';
	}

	public function maybe_handle_actions() {
		if ( ! current_user_can( 'manage_bb_seller_reviews' ) ) {
			return;
		}
		if ( empty( $_GET['bbcsr_action'] ) || empty( $_GET['id'] ) ) {
			return;
		}
		check_admin_referer( 'bbcsr_review_action' );
		$action = sanitize_key( $_GET['bbcsr_action'] );
		$id     = absint( $_GET['id'] );
		$core   = BB_Seller_Reviews::instance();
		if ( 'approve' === $action ) {
			$core->update_review_status( $id, 'approved' );
		} elseif ( 'reject' === $action ) {
			$core->update_review_status( $id, 'rejected' );
		} elseif ( 'delete' === $action ) {
			$core->delete_review( $id );
		}
		wp_safe_redirect( remove_query_arg( [ 'bbcsr_action', 'id', '_wpnonce' ] ) );
		exit;
	}

	public function export_csv() {
		if ( ! current_user_can( 'manage_bb_seller_reviews' ) ) {
			wp_die( esc_html__( 'Access denied.', 'bb-credit-seller-reviews' ) );
		}
		check_admin_referer( 'bbcsr_export_csv' );
		global $wpdb;
		$table = $wpdb->prefix . 'bb_seller_reviews';
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY review_date DESC" , ARRAY_A );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=bb-seller-reviews.csv' );
		$output = fopen( 'php://output', 'w' );
		if ( ! empty( $rows ) ) {
			fputcsv( $output, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) {
				fputcsv( $output, $row );
			}
		}
		fclose( $output );
		exit;
	}
}

