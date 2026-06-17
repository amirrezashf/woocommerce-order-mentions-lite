<?php
/**
 * Plugin Name: WooCommerce Order Mentions Lite
 * Plugin URI: https://github.com/yourusername/woocommerce-order-mentions-lite
 * Description: Lightweight internal mentions system for private WooCommerce order notes.
 * Version: 1.0.0
 * Author: Amirreza Shayesteh Far
 * Author URI: https://amirrezaa.ir/
 * License: GPL v2 or later
 * Text Domain: woocommerce-order-mentions-lite
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WCOML_Order_Mentions_Lite {

	const TABLE      = 'wcoml_order_mentions';
	const VERSION    = '1.0.0';
	const MENU_SLUG  = 'wcoml-order-mentions-lite';
	const META_DONE  = '_wcoml_mentions_processed';
	const AJAX_NONCE = 'wcoml_mentions_ajax_nonce';

	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( $this, 'handle_seen_mention_request' ) );

		add_action( 'woocommerce_new_order_note', array( $this, 'capture_from_wc_new_order_note' ), 10, 2 );
		add_action( 'wp_insert_comment', array( $this, 'capture_from_wp_insert_comment' ), 10, 2 );

		add_action( 'admin_menu', array( $this, 'register_menu' ), 99 );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 100 );
		add_action( 'admin_notices', array( $this, 'render_unread_notice' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'wp_ajax_wcoml_fetch_mentions', array( $this, 'ajax_fetch_mentions' ) );
	}

	public function activate() {
		$this->create_table();
		update_option( 'wcoml_order_mentions_lite_version', self::VERSION, false );
	}

	public function maybe_upgrade() {
		$version = get_option( 'wcoml_order_mentions_lite_version' );

		if ( $version !== self::VERSION ) {
			$this->create_table();
			update_option( 'wcoml_order_mentions_lite_version', self::VERSION, false );
		}
	}

	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	private function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			mention_by_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			note_id BIGINT(20) UNSIGNED NOT NULL,
			note_text LONGTEXT NULL,
			is_read TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY mention_by_user_id (mention_by_user_id),
			KEY order_id (order_id),
			KEY note_id (note_id),
			KEY is_read (is_read)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	private function is_order_edit_screen() {
		if ( ! is_admin() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ! empty( $screen->id ) ) {
			$allowed = array(
				'shop_order',
				'woocommerce_page_wc-orders',
				'admin_page_wc-orders',
			);

			if ( in_array( $screen->id, $allowed, true ) ) {
				return true;
			}
		}

		if ( isset( $_GET['post'], $_GET['action'] ) && 'edit' === $_GET['action'] ) {
			$post_id = absint( $_GET['post'] );
			if ( $post_id && 'shop_order' === get_post_type( $post_id ) ) {
				return true;
			}
		}

		if ( isset( $_GET['page'], $_GET['action'], $_GET['id'] ) && 'wc-orders' === $_GET['page'] && 'edit' === $_GET['action'] ) {
			return true;
		}

		return false;
	}

	private function is_mentions_page() {
		return is_admin() && isset( $_GET['page'] ) && self::MENU_SLUG === $_GET['page'];
	}

	private function get_order_edit_url( $order_id, $mention_id = 0 ) {
		$order_id   = absint( $order_id );
		$mention_id = absint( $mention_id );

		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		} else {
			$url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		}

		if ( $mention_id > 0 ) {
			$url = add_query_arg(
				array(
					'wcoml_seen_mention' => 1,
					'wcoml_mention_id'   => $mention_id,
				),
				$url
			);
		}

		return $url;
	}

	private function get_mentionable_users() {
		$users = get_users(
			array(
				'fields' => array( 'ID', 'user_login', 'display_name' ),
			)
		);

		$result = array();

		foreach ( $users as $user ) {
			if ( user_can( $user->ID, 'edit_shop_orders' ) ) {
				$result[] = array(
					'id'           => (int) $user->ID,
					'login'        => (string) $user->user_login,
					'display_name' => (string) $user->display_name,
					'profile_url'  => admin_url( 'user-edit.php?user_id=' . (int) $user->ID ),
				);
			}
		}

		return $result;
	}

	private function get_users_map() {
		$users = $this->get_mentionable_users();
		$map   = array();

		foreach ( $users as $user ) {
			$map[ $user['login'] ] = $user;
		}

		return $map;
	}

	private function get_user_names_map_by_ids( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		$map = array();

		foreach ( $users as $user ) {
			$map[ (int) $user->ID ] = (string) $user->display_name;
		}

		return $map;
	}

	private function get_unread_count( $user_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name()} WHERE user_id = %d AND is_read = 0",
				$user_id
			)
		);
	}

	private function get_latest_unread_mentions( $user_id, $limit = 5 ) {
		global $wpdb;

		$limit = max( 1, absint( $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name()} WHERE user_id = %d AND is_read = 0 ORDER BY id DESC LIMIT %d",
				$user_id,
				$limit
			)
		);
	}

	private function get_last_month_threshold() {
		$dt = current_datetime();
		$dt->modify( '-1 month' );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	private function get_mentions_for_user( $user_id, $status = 'all', $period = 'all_time', $limit = 200 ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$limit   = max( 1, absint( $limit ) );

		$where = array( 'user_id = %d' );
		$args  = array( $user_id );

		if ( 'unread' === $status ) {
			$where[] = 'is_read = 0';
		} elseif ( 'read' === $status ) {
			$where[] = 'is_read = 1';
		}

		if ( 'last_month' === $period ) {
			$where[] = 'created_at >= %s';
			$args[]  = $this->get_last_month_threshold();
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$args[]    = $limit;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$this->table_name()} {$where_sql} ORDER BY id DESC LIMIT %d",
			$args
		);

		return $wpdb->get_results( $sql );
	}

	private function count_mentions( $user_id, $status = 'all', $period = 'all_time' ) {
		global $wpdb;

		$user_id = absint( $user_id );
		$where   = array( 'user_id = %d' );
		$args    = array( $user_id );

		if ( 'unread' === $status ) {
			$where[] = 'is_read = 0';
		} elseif ( 'read' === $status ) {
			$where[] = 'is_read = 1';
		}

		if ( 'last_month' === $period ) {
			$where[] = 'created_at >= %s';
			$args[]  = $this->get_last_month_threshold();
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table_name()} {$where_sql}",
			$args
		);

		return (int) $wpdb->get_var( $sql );
	}

	private function get_filter_counts( $user_id, $period = 'all_time' ) {
		return array(
			'all'    => $this->count_mentions( $user_id, 'all', $period ),
			'unread' => $this->count_mentions( $user_id, 'unread', $period ),
			'read'   => $this->count_mentions( $user_id, 'read', $period ),
		);
	}

	private function get_current_filter() {
		$filter = isset( $_GET['mention_status'] ) ? sanitize_key( wp_unslash( $_GET['mention_status'] ) ) : 'all';

		if ( ! in_array( $filter, array( 'all', 'unread', 'read' ), true ) ) {
			$filter = 'all';
		}

		return $filter;
	}

	private function get_current_period() {
		$period = isset( $_GET['mention_period'] ) ? sanitize_key( wp_unslash( $_GET['mention_period'] ) ) : 'all_time';

		if ( ! in_array( $period, array( 'all_time', 'last_month' ), true ) ) {
			$period = 'all_time';
		}

		return $period;
	}

	private function format_wp_datetime( $mysql_datetime ) {
		$mysql_datetime = (string) $mysql_datetime;

		if ( '' === $mysql_datetime ) {
			return '';
		}

		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		return mysql2date( $format, $mysql_datetime, true );
	}

	private function render_mentions_cards_html( $rows ) {
		if ( empty( $rows ) ) {
			return '<div class="wcoml-empty"><div class="wcoml-empty-icon">🔔</div><div class="wcoml-empty-title">در این فیلتر، منشنی برای شما ثبت نشده است</div><div class="wcoml-empty-text">هر زمان همکاران شما را در یادداشت خصوصی سفارش منشن کنند، در این بخش نمایش داده می‌شود.</div></div>';
		}

		$mentioner_ids = array();

		foreach ( $rows as $row ) {
			$mentioner_ids[] = (int) $row->mention_by_user_id;
		}

		$mentioner_names = $this->get_user_names_map_by_ids( $mentioner_ids );

		ob_start();

		echo '<div class="wcoml-cards">';

		foreach ( $rows as $row ) {
			$is_read   = (int) $row->is_read === 1;
			$order_id  = (int) $row->order_id;
			$view_url  = $this->get_order_edit_url( $order_id, (int) $row->id );
			$time      = $this->format_wp_datetime( $row->created_at );
			$mentioner = ! empty( $mentioner_names[ (int) $row->mention_by_user_id ] ) ? $mentioner_names[ (int) $row->mention_by_user_id ] : 'نامشخص';

			echo '<div class="wcoml-card' . ( $is_read ? ' is-read' : ' is-unread' ) . '">';
			echo '<div class="wcoml-card-head">';
			echo '<div class="wcoml-order-block">';
			echo '<div class="wcoml-order-label">شماره سفارش</div>';
			echo '<div class="wcoml-order-id">#' . esc_html( $order_id ) . '</div>';
			echo '</div>';

			echo '<div class="wcoml-status ' . ( $is_read ? 'is-read' : 'is-unread' ) . '">';
			echo $is_read ? 'خوانده‌شده' : 'خوانده‌نشده';
			echo '</div>';
			echo '</div>';

			echo '<div class="wcoml-card-body">';
			echo '<div class="wcoml-meta-row">';
			echo '<span class="wcoml-meta-label">منشن توسط</span>';
			echo '<strong class="wcoml-meta-value">' . esc_html( $mentioner ) . '</strong>';
			echo '</div>';

			echo '<div class="wcoml-date-row">';
			echo '<span class="wcoml-date-label">تاریخ و ساعت ثبت منشن</span>';
			echo '<strong class="wcoml-date-value">' . esc_html( $time ) . '</strong>';
			echo '</div>';
			echo '</div>';

			echo '<div class="wcoml-actions">';
			echo '<a class="wcoml-view-btn" href="' . esc_url( $view_url ) . '">مشاهده منشن</a>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';

		return ob_get_clean();
	}

	public function handle_seen_mention_request() {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}

		if ( empty( $_GET['wcoml_seen_mention'] ) || empty( $_GET['wcoml_mention_id'] ) ) {
			return;
		}

		$mention_id = absint( $_GET['wcoml_mention_id'] );

		if ( ! $mention_id ) {
			return;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name()} WHERE id = %d AND user_id = %d LIMIT 1",
				$mention_id,
				get_current_user_id()
			)
		);

		if ( ! $row ) {
			return;
		}

		$wpdb->update(
			$this->table_name(),
			array(
				'is_read' => 1,
			),
			array(
				'id'      => $mention_id,
				'user_id' => get_current_user_id(),
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		$clean_url = remove_query_arg( array( 'wcoml_seen_mention', 'wcoml_mention_id' ) );

		wp_safe_redirect( $clean_url );
		exit;
	}

	public function add_admin_bar_node( $wp_admin_bar ) {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}

		$count = $this->get_unread_count( get_current_user_id() );

		$title = '🔔 منشن‌ها';

		if ( $count > 0 ) {
			$title .= ' <span class="wcoml-badge">' . (int) $count . '</span>';
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => 'wcoml-mentions',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			)
		);
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			'منشن‌های من',
			'منشن‌های من',
			'read',
			self::MENU_SLUG,
			array( $this, 'render_mentions_page' )
		);
	}

	public function render_mentions_page() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'دسترسی غیرمجاز' );
		}

		$user_id = get_current_user_id();
		$status  = $this->get_current_filter();
		$period  = $this->get_current_period();
		$counts  = $this->get_filter_counts( $user_id, $period );
		$rows    = $this->get_mentions_for_user( $user_id, $status, $period, 200 );

		echo '<div class="wrap wcoml-wrap">';
		echo '<h1 class="wcoml-page-title">منشن‌های من</h1>';

		echo '<div class="wcoml-filters-wrap">';
		echo '<div class="wcoml-filters" id="wcoml-status-filters">';
		echo '<a href="#" class="wcoml-filter' . ( 'all' === $status ? ' is-active' : '' ) . '" data-filter-type="status" data-filter-value="all">همه <span>' . (int) $counts['all'] . '</span></a>';
		echo '<a href="#" class="wcoml-filter' . ( 'unread' === $status ? ' is-active' : '' ) . '" data-filter-type="status" data-filter-value="unread">خوانده‌نشده <span>' . (int) $counts['unread'] . '</span></a>';
		echo '<a href="#" class="wcoml-filter' . ( 'read' === $status ? ' is-active' : '' ) . '" data-filter-type="status" data-filter-value="read">خوانده‌شده <span>' . (int) $counts['read'] . '</span></a>';
		echo '</div>';

		echo '<div class="wcoml-filters" id="wcoml-period-filters">';
		echo '<a href="#" class="wcoml-filter' . ( 'all_time' === $period ? ' is-active' : '' ) . '" data-filter-type="period" data-filter-value="all_time">همه زمان‌ها</a>';
		echo '<a href="#" class="wcoml-filter' . ( 'last_month' === $period ? ' is-active' : '' ) . '" data-filter-type="period" data-filter-value="last_month">یک ماه اخیر</a>';
		echo '</div>';
		echo '</div>';

		echo '<div id="wcoml-ajax-cards">';
		echo $this->render_mentions_cards_html( $rows );
		echo '</div>';

		echo '</div>';
	}

	public function render_unread_notice() {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		$count   = $this->get_unread_count( $user_id );

		if ( $count < 1 ) {
			return;
		}

		$items = $this->get_latest_unread_mentions( $user_id, 3 );

		echo '<div class="notice notice-warning">';
		echo '<p><strong>شما ' . (int) $count . ' منشن خوانده‌نشده در سفارشات دارید.</strong></p>';

		if ( ! empty( $items ) ) {
			echo '<ul style="margin:0 0 8px 18px;list-style:disc;">';

			foreach ( $items as $item ) {
				$order_url = $this->get_order_edit_url( (int) $item->order_id, (int) $item->id );
				echo '<li><a href="' . esc_url( $order_url ) . '">مشاهده منشن سفارش #' . (int) $item->order_id . '</a></li>';
			}

			echo '</ul>';
		}

		echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">مشاهده همه منشن‌ها</a></p>';
		echo '</div>';
	}

	public function capture_from_wc_new_order_note( $comment_id, $args ) {
		$is_customer_note = ! empty( $args['is_customer_note'] );
		$order_id         = ! empty( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;

		$this->process_note_mentions( $comment_id, $order_id, $is_customer_note );
	}

	public function capture_from_wp_insert_comment( $comment_id, $comment_object ) {
		if ( ! $comment_object || empty( $comment_object->comment_type ) ) {
			return;
		}

		if ( 'order_note' !== $comment_object->comment_type ) {
			return;
		}

		$order_id = absint( $comment_object->comment_post_ID );

		if ( ! $order_id ) {
			return;
		}

		$is_customer_note = (bool) get_comment_meta( $comment_id, 'is_customer_note', true );

		$this->process_note_mentions( $comment_id, $order_id, $is_customer_note );
	}

	private function process_note_mentions( $comment_id, $order_id = 0, $is_customer_note = false ) {
		$comment_id = absint( $comment_id );
		$order_id   = absint( $order_id );

		if ( ! $comment_id ) {
			return;
		}

		if ( $is_customer_note ) {
			return;
		}

		if ( get_comment_meta( $comment_id, self::META_DONE, true ) ) {
			return;
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment || empty( $comment->comment_content ) ) {
			return;
		}

		if ( ! $order_id ) {
			$order_id = absint( $comment->comment_post_ID );
		}

		if ( ! $order_id ) {
			return;
		}

		$text = (string) $comment->comment_content;

		if ( ! preg_match_all( '/@([A-Za-z0-9_.-]+)/u', $text, $matches ) ) {
			update_comment_meta( $comment_id, self::META_DONE, 1 );
			return;
		}

		$usernames = array_unique( $matches[1] );

		if ( empty( $usernames ) ) {
			update_comment_meta( $comment_id, self::META_DONE, 1 );
			return;
		}

		global $wpdb;

		foreach ( $usernames as $username ) {
			$user = get_user_by( 'login', $username );

			if ( ! $user ) {
				continue;
			}

			if ( ! user_can( $user->ID, 'edit_shop_orders' ) ) {
				continue;
			}

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table_name()} WHERE user_id = %d AND note_id = %d",
					$user->ID,
					$comment_id
				)
			);

			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$this->table_name(),
				array(
					'user_id'            => (int) $user->ID,
					'mention_by_user_id' => (int) get_current_user_id(),
					'order_id'           => $order_id,
					'note_id'            => (int) $comment_id,
					'note_text'          => $text,
					'is_read'            => 0,
					'created_at'         => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
			);
		}

		update_comment_meta( $comment_id, self::META_DONE, 1 );
	}

	public function ajax_fetch_mentions() {
		check_ajax_referer( self::AJAX_NONCE, 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error();
		}

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all';
		$period = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : 'all_time';

		if ( ! in_array( $status, array( 'all', 'unread', 'read' ), true ) ) {
			$status = 'all';
		}

		if ( ! in_array( $period, array( 'all_time', 'last_month' ), true ) ) {
			$period = 'all_time';
		}

		$user_id = get_current_user_id();
		$counts  = $this->get_filter_counts( $user_id, $period );
		$rows    = $this->get_mentions_for_user( $user_id, $status, $period, 200 );
		$html    = $this->render_mentions_cards_html( $rows );

		wp_send_json_success(
			array(
				'html'   => $html,
				'counts' => $counts,
			)
		);
	}

	public function enqueue_admin_assets() {
		if ( ! $this->is_order_edit_screen() && ! $this->is_mentions_page() ) {
			return;
		}

		$users_map    = $this->get_users_map();
		$users        = array_values( $users_map );
		$current_user = wp_get_current_user();

		$css = '
		#wp-admin-bar-wcoml-mentions .wcoml-badge{background:#d63638;color:#fff;border-radius:999px;padding:0 6px;margin-right:4px;font-size:11px;line-height:1.7;display:inline-block}
		.wcoml-wrap{max-width:1200px}
		.wcoml-page-title{margin:18px 0 18px!important;font-size:28px!important;font-weight:800!important;line-height:1.4!important;color:#111827}
		.wcoml-filters-wrap{display:flex;flex-direction:column;gap:12px;margin:0 0 18px}
		.wcoml-filters{display:flex;flex-wrap:wrap;gap:10px}
		.wcoml-filter{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:#fff;border:1px solid #e5e7eb;text-decoration:none!important;color:#374151!important;font-size:13px;font-weight:700;box-shadow:0 4px 14px rgba(17,24,39,.04);transition:all .18s ease}
		.wcoml-filter span{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 7px;border-radius:999px;background:#f3f4f6;font-size:12px;font-weight:800;color:#111827}
		.wcoml-filter:hover{transform:translateY(-1px);border-color:#d8b4fe}
		.wcoml-filter.is-active{background:linear-gradient(135deg,#6d28d9,#7c3aed);border-color:#6d28d9;color:#fff!important;box-shadow:0 10px 24px rgba(109,40,217,.20)}
		.wcoml-filter.is-active span{background:rgba(255,255,255,.18);color:#fff}
		#wcoml-ajax-cards.is-loading{opacity:.55;pointer-events:none;transition:opacity .14s ease}
		.wcoml-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;margin-top:18px}
		.wcoml-card{position:relative;background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:18px;box-shadow:0 12px 30px rgba(17,24,39,.06);transition:all .18s ease;overflow:hidden}
		.wcoml-card:hover{transform:translateY(-2px);box-shadow:0 18px 36px rgba(17,24,39,.10)}
		.wcoml-card.is-unread{border-color:#d8b4fe;box-shadow:0 16px 34px rgba(109,40,217,.10)}
		.wcoml-card.is-unread::before{content:"";position:absolute;right:0;top:0;width:100%;height:4px;background:linear-gradient(90deg,#6d28d9,#7c3aed,#8b5cf6)}
		.wcoml-card.is-read{border-color:#dbeafe}
		.wcoml-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
		.wcoml-order-block{display:flex;flex-direction:column;gap:6px}
		.wcoml-order-label{font-size:12px;color:#6b7280;line-height:1.7}
		.wcoml-order-id{font-size:24px;font-weight:800;color:#111827;line-height:1.3;direction:ltr;text-align:right}
		.wcoml-status{flex-shrink:0;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.4}
		.wcoml-status.is-unread{background:#faf5ff;color:#6d28d9;border:1px solid #e9d5ff}
		.wcoml-status.is-read{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
		.wcoml-card-body{padding:14px 0 6px;border-top:1px solid #f3f4f6;display:flex;flex-direction:column;gap:12px}
		.wcoml-meta-row,.wcoml-date-row{display:flex;flex-direction:column;gap:7px}
		.wcoml-meta-label,.wcoml-date-label{font-size:12px;color:#6b7280;line-height:1.8}
		.wcoml-meta-value,.wcoml-date-value{font-size:17px;font-weight:800;color:#111827;line-height:1.8}
		.wcoml-actions{margin-top:18px;display:flex;justify-content:flex-end}
		.wcoml-view-btn{display:inline-flex;align-items:center;justify-content:center;min-width:170px;height:46px;padding:0 18px;border-radius:16px;background:linear-gradient(135deg,#6d28d9,#7c3aed);color:#fff!important;text-decoration:none!important;font-size:15px;font-weight:800;box-shadow:0 14px 26px rgba(109,40,217,.22);transition:all .18s ease}
		.wcoml-view-btn:hover{transform:translateY(-1px);box-shadow:0 18px 30px rgba(109,40,217,.28);color:#fff!important}
		.wcoml-empty{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:34px 24px;text-align:center;box-shadow:0 10px 28px rgba(17,24,39,.05);margin-top:18px}
		.wcoml-empty-icon{font-size:36px;margin-bottom:10px}
		.wcoml-empty-title{font-size:20px;font-weight:800;color:#111827;margin-bottom:8px}
		.wcoml-empty-text{font-size:14px;color:#6b7280;line-height:2;max-width:520px;margin:0 auto}
		.wcoml-suggest{display:none;margin:0 0 8px 0;background:#fff;border:1px solid #dcdcde;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.08);max-height:240px;overflow:auto}
		.wcoml-suggest ul{margin:0;padding:6px;list-style:none}
		.wcoml-suggest li{margin:0;padding:10px 12px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12px}
		.wcoml-suggest li:hover,.wcoml-suggest li.is-active{background:#f3f0ff}
		.wcoml-suggest strong{font-weight:700}
		.wcoml-suggest span{color:#6b7280}
		.wcoml-warning,.wcoml-self-alert,.wcoml-duplicate-alert{display:none;margin:0 0 8px 0;padding:10px 12px;border-radius:12px;font-size:12px;line-height:1.8}
		.wcoml-warning{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}
		.wcoml-self-alert{background:#fff7ed;color:#9a3412;border:1px solid #fdba74}
		.wcoml-duplicate-alert{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
		.wcoml-mention-link{color:#5b21b6!important;font-weight:700;text-decoration:none}
		.wcoml-mention-link:hover{text-decoration:underline}
		';

		wp_register_style( 'wcoml-style', false, array(), self::VERSION );
		wp_enqueue_style( 'wcoml-style' );
		wp_add_inline_style( 'wcoml-style', $css );

		wp_register_script( 'wcoml-script', '', array( 'jquery' ), self::VERSION, true );
		wp_enqueue_script( 'wcoml-script' );

		wp_localize_script(
			'wcoml-script',
			'wcomlData',
			array(
				'users'            => $users,
				'usersMap'         => $users_map,
				'currentUserId'    => (int) $current_user->ID,
				'currentUserLogin' => (string) $current_user->user_login,
				'isOrderScreen'    => $this->is_order_edit_screen() ? 1 : 0,
				'isMentionsPage'   => $this->is_mentions_page() ? 1 : 0,
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( self::AJAX_NONCE ),
				'initialStatus'    => $this->get_current_filter(),
				'initialPeriod'    => $this->get_current_period(),
				'menuSlug'         => self::MENU_SLUG,
			)
		);

		$js = <<<'JS'
(function($){
	'use strict';

	if (typeof wcomlData === 'undefined') return;

	function getElements() {
		return {
			textarea: $('#add_order_note'),
			type: $('#order_note_type'),
			button: $('#woocommerce-order-notes .add_note button, .wc-order-notes .add_note button, button.add_note')
		};
	}

	function isCustomerNote($type) {
		if (!$type.length) return false;
		var val = String($type.val() || '').toLowerCase();
		return val === 'customer' || val === 'customer-note' || val === 'customer_note';
	}

	function getMentionedLogins(text) {
		var matches = String(text || '').match(/@([A-Za-z0-9_.-]+)/g) || [];
		var logins = [];

		matches.forEach(function(item){
			var login = item.replace('@', '');
			if (login && logins.indexOf(login) === -1) {
				logins.push(login);
			}
		});

		return logins;
	}

	function applyMentionLinks() {
		if (!wcomlData.isOrderScreen) return;

		var usersMap = wcomlData.usersMap || {};

		$('.woocommerce-order-notes li.note, .wc-order-notes li.note').each(function(){
			var $li = $(this);

			if ($li.hasClass('customer-note')) return;

			var $content = $li.find('.note_content, .content');

			if (!$content.length || $content.data('wcomlLinked') === 1) return;

			var html = $content.html();
			if (!html) return;

			html = html.replace(/(^|[\s>])@([A-Za-z0-9_.-]+)/g, function(match, prefix, username){
				if (!usersMap[username]) return match;

				var user = usersMap[username];
				return prefix + '<a class="wcoml-mention-link" href="' + user.profile_url + '" target="_blank">@' + username + '</a>';
			});

			$content.html(html);
			$content.data('wcomlLinked', 1);
		});
	}

	function initAutocomplete() {
		if (!wcomlData.isOrderScreen) return;

		var els = getElements();
		var $textarea = els.textarea;
		var $type = els.type;
		var $button = els.button;

		if (!$textarea.length) return;

		var $warning = $('<div class="wcoml-warning" id="wcoml-warning">در یادداشت خریدار امکان منشن کردن وجود ندارد. فقط از یادداشت خصوصی استفاده کنید.</div>');
		var $selfAlert = $('<div class="wcoml-self-alert" id="wcoml-self-alert">شما در حال منشن کردن خودتان هستید.</div>');
		var $duplicateAlert = $('<div class="wcoml-duplicate-alert" id="wcoml-duplicate-alert">این کاربر قبلاً در همین پیام منشن شده و دوباره قابل افزودن نیست.</div>');
		var $suggest = $('<div class="wcoml-suggest" id="wcoml-suggest"><ul></ul></div>');
		var $list = $suggest.find('ul');

		$textarea.before($suggest);
		$textarea.before($duplicateAlert);
		$textarea.before($selfAlert);
		$textarea.before($warning);

		var selectedIndex = -1;
		var matches = [];
		var mentionStart = -1;
		var selfAlertTimer = null;
		var duplicateAlertTimer = null;

		function hideSuggest() {
			$suggest.hide();
			$list.empty();
			selectedIndex = -1;
			matches = [];
			mentionStart = -1;
		}

		function showSelfAlert() {
			if (selfAlertTimer) clearTimeout(selfAlertTimer);
			$selfAlert.stop(true, true).fadeIn(120);
			selfAlertTimer = setTimeout(function(){ $selfAlert.fadeOut(180); }, 2200);
		}

		function showDuplicateAlert() {
			if (duplicateAlertTimer) clearTimeout(duplicateAlertTimer);
			$duplicateAlert.stop(true, true).fadeIn(120);
			duplicateAlertTimer = setTimeout(function(){ $duplicateAlert.fadeOut(180); }, 2400);
		}

		function toggleWarning() {
			var hasMention = /@([A-Za-z0-9_.-]+)/.test($textarea.val() || '');
			if (isCustomerNote($type) && hasMention) {
				$warning.show();
			} else {
				$warning.hide();
			}
		}

		function render(items) {
			$list.empty();

			if (!items.length) {
				hideSuggest();
				return;
			}

			selectedIndex = 0;
			matches = items;

			items.forEach(function(user, index){
				var cls = index === 0 ? ' class="is-active"' : '';
				$list.append('<li' + cls + ' data-index="' + index + '"><strong>@' + user.login + '</strong><span>' + (user.display_name || '') + '</span></li>');
			});

			$suggest.show();
		}

		function refreshActive() {
			$list.find('li').removeClass('is-active');
			$list.find('li[data-index="' + selectedIndex + '"]').addClass('is-active');
		}

		function insertSelected(index) {
			if (!matches[index]) return;

			var user = matches[index];
			var text = $textarea.val() || '';
			var mentioned = getMentionedLogins(text);

			if (mentioned.indexOf(user.login) !== -1) {
				hideSuggest();
				showDuplicateAlert();
				return;
			}

			var caret = $textarea.prop('selectionStart');
			var before = text.slice(0, mentionStart);
			var after = text.slice(caret);
			var insertion = '@' + user.login + '\n';
			var nextText = before + insertion + after;
			var nextCaret = (before + insertion).length;

			$textarea.val(nextText).focus();
			$textarea[0].setSelectionRange(nextCaret, nextCaret);

			hideSuggest();
			toggleWarning();

			if (parseInt(user.id, 10) === parseInt(wcomlData.currentUserId || 0, 10)) {
				showSelfAlert();
			}
		}

		$textarea.on('input keyup click', function(){
			toggleWarning();

			if (isCustomerNote($type)) {
				hideSuggest();
				return;
			}

			var text = $textarea.val() || '';
			var caret = $textarea.prop('selectionStart');
			var before = text.slice(0, caret);
			var atIndex = before.lastIndexOf('@');

			if (atIndex === -1) {
				hideSuggest();
				return;
			}

			var afterAt = before.slice(atIndex + 1);

			if (/\s/.test(afterAt)) {
				hideSuggest();
				return;
			}

			mentionStart = atIndex;

			var term = String(afterAt || '').toLowerCase();
			var users = Array.isArray(wcomlData.users) ? wcomlData.users : [];
			var alreadyMentioned = getMentionedLogins(text);

			var filtered = users.filter(function(user){
				var login = String(user.login || '').toLowerCase();
				var display = String(user.display_name || '').toLowerCase();

				if (alreadyMentioned.indexOf(user.login) !== -1) return false;
				if (term === '') return true;

				return login.indexOf(term) !== -1 || display.indexOf(term) !== -1;
			}).slice(0, 10);

			render(filtered);
		});

		$textarea.on('keydown', function(e){
			if ($suggest.is(':hidden') || !matches.length) return;

			if (e.key === 'ArrowDown') {
				e.preventDefault();
				selectedIndex = (selectedIndex + 1) % matches.length;
				refreshActive();
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				selectedIndex = (selectedIndex - 1 + matches.length) % matches.length;
				refreshActive();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				insertSelected(selectedIndex);
			} else if (e.key === 'Escape') {
				hideSuggest();
			}
		});

		$list.on('mousedown', 'li', function(e){
			e.preventDefault();
			insertSelected(parseInt($(this).data('index'), 10));
		});

		$type.on('change', function(){
			toggleWarning();

			if (isCustomerNote($type)) {
				hideSuggest();
			}
		});

		$button.on('click', function(e){
			var text = $textarea.val() || '';

			if (isCustomerNote($type) && /@([A-Za-z0-9_.-]+)/.test(text)) {
				e.preventDefault();
				e.stopImmediatePropagation();
				$warning.show();
				alert('در یادداشت خریدار امکان منشن کردن وجود ندارد. لطفاً یادداشت خصوصی را انتخاب کنید.');
				return false;
			}
		});

		$(document).on('click', function(e){
			if (!$(e.target).closest('#wcoml-suggest, #add_order_note').length) {
				hideSuggest();
			}
		});

		toggleWarning();
	}

	function initMentionsAjaxFilters() {
		if (!wcomlData.isMentionsPage) return;

		var currentStatus = wcomlData.initialStatus || 'all';
		var currentPeriod = wcomlData.initialPeriod || 'all_time';
		var $wrap = $('#wcoml-ajax-cards');

		function setActiveButtons() {
			$('.wcoml-filter[data-filter-type="status"]').removeClass('is-active');
			$('.wcoml-filter[data-filter-type="period"]').removeClass('is-active');

			$('.wcoml-filter[data-filter-type="status"][data-filter-value="' + currentStatus + '"]').addClass('is-active');
			$('.wcoml-filter[data-filter-type="period"][data-filter-value="' + currentPeriod + '"]').addClass('is-active');
		}

		function updateCounts(counts) {
			if (!counts) return;

			$('.wcoml-filter[data-filter-type="status"][data-filter-value="all"] span').text(counts.all || 0);
			$('.wcoml-filter[data-filter-type="status"][data-filter-value="unread"] span').text(counts.unread || 0);
			$('.wcoml-filter[data-filter-type="status"][data-filter-value="read"] span').text(counts.read || 0);
		}

		function syncUrl() {
			if (!window.history || !window.history.replaceState) return;

			var url = new URL(window.location.href);
			url.searchParams.set('page', wcomlData.menuSlug);
			url.searchParams.set('mention_status', currentStatus);
			url.searchParams.set('mention_period', currentPeriod);
			window.history.replaceState({}, '', url.toString());
		}

		function fetchMentions() {
			$wrap.addClass('is-loading');

			$.post(wcomlData.ajaxUrl, {
				action: 'wcoml_fetch_mentions',
				nonce: wcomlData.nonce,
				status: currentStatus,
				period: currentPeriod
			}).done(function(resp){
				if (!resp || !resp.success || !resp.data) return;

				$wrap.html(resp.data.html || '');
				updateCounts(resp.data.counts || {});
				setActiveButtons();
				syncUrl();
			}).always(function(){
				$wrap.removeClass('is-loading');
			});
		}

		$(document).on('click', '.wcoml-filter', function(e){
			var $btn = $(this);
			var type = String($btn.data('filter-type') || '');
			var value = String($btn.data('filter-value') || '');

			if (!type || !value) return;

			e.preventDefault();

			if (type === 'status') {
				currentStatus = value;
			} else if (type === 'period') {
				currentPeriod = value;
			}

			fetchMentions();
		});
	}

	function observeNotes() {
		if (!wcomlData.isOrderScreen) return;

		var target = document.querySelector('#woocommerce-order-notes, .wc-order-notes');

		if (!target || !window.MutationObserver) {
			applyMentionLinks();
			return;
		}

		var observer = new MutationObserver(function(){
			applyMentionLinks();
		});

		observer.observe(target, {
			childList: true,
			subtree: true
		});

		applyMentionLinks();
	}

	$(function(){
		initAutocomplete();
		observeNotes();
		initMentionsAjaxFilters();
	});
})(jQuery);
JS;

		wp_add_inline_script( 'wcoml-script', $js );
	}
}

new WCOML_Order_Mentions_Lite();
