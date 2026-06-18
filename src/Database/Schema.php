<?php
/**
 * Database Schema management
 *
 * Handles the creation and upgrades of all the archive tables.
 * Called on plugin activation and on version upgrades.
 *
 * @package HW\WOAM\Database
 */

namespace HW\WOAM\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Schema
 */
class Schema {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */

	private \wpdb $wpdb;

	/**
	 * Table name definitions.
	 *
	 * @var Tables
	 */

	private Tables $tables;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb WordPress database object, injected for testability.
	 * @param Tables $tables Table name definitions, injected for testability.
	 */
	public function __construct( \wpdb $wpdb, Tables $tables ) {
		$this->wpdb   = $wpdb;
		$this->tables = $tables;
	}

	/**
	 * Creates all the archive tables if they don't exists.
	 *
	 * Uses dbDelta() so it is safe to run on both activation and version upgrades.
	 * it will only create missing tables and add missing columns,
	 * it will not delete or modify existing columns.
	 *
	 * @return void
	 */
	public function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $this->wpdb->get_charset_collate();

		dbDelta( $this->get_orders_table_sql( $charset ) );
		dbDelta( $this->get_orders_meta_table_sql( $charset ) );
		dbDelta( $this->get_order_items_table_sql( $charset ) );
		dbDelta( $this->get_order_items_meta_table_sql( $charset ) );

		dbDelta( $this->get_logs_table_sql( $charset ) );
		dbDelta( $this->get_order_notes_table_sql( $charset ) );
		dbDelta( $this->get_order_notes_meta_table_sql( $charset ) );
		dbDelta( $this->get_order_refunds_table_sql( $charset ) );
		dbDelta( $this->get_order_refunds_meta_table_sql( $charset ) );

		update_option( 'hw_woam_db_version', HW_WOAM_VERSION );
	}

	/**
	 * SQL for creating the orders archive table.
	 * Mirrors the full wp_posts column structure so that any order
	 * can be restored with zero data loss, plus one extra column
	 * to record when it was archived.
	 *
	 * @param string $charset Database charset and collation string.
	 * @return string
	 */
	private function get_orders_table_sql( string $charset ): string {

		return "CREATE TABLE {$this->tables->orders} (
            ID bigint(20) unsigned NOT NULL,
            post_author bigint(20) unsigned NOT NULL DEFAULT 0,
            post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content longtext NOT NULL,
            post_title text NOT NULL,
            post_excerpt text NOT NULL,
            post_status varchar(20) NOT NULL DEFAULT 'publish',
            comment_status varchar(20) NOT NULL DEFAULT 'open',
            ping_status varchar(20) NOT NULL DEFAULT 'open',
            post_password varchar(255) NOT NULL DEFAULT '',
            post_name varchar(200) NOT NULL DEFAULT '',
            to_ping text NOT NULL,
            pinged text NOT NULL,
            post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_modified_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content_filtered longtext NOT NULL,
            post_parent bigint(20) unsigned NOT NULL DEFAULT 0,
            guid varchar(255) NOT NULL DEFAULT '',
            menu_order int(11) NOT NULL DEFAULT 0,
            post_type varchar(20) NOT NULL DEFAULT 'shop_order',
            post_mime_type varchar(100) NOT NULL DEFAULT '',
            comment_count bigint(20) NOT NULL DEFAULT 0,
            archived_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (ID),
            KEY post_status (post_status),
            KEY post_date (post_date)
        ) {$charset};";
	}

	/**
	 * SQL for creating the orders meta archive tables.
	 *
	 * @param string $charset Database charset and collation string.
	 * @return string
	 */
	private function get_orders_meta_table_sql( string $charset ): string {

		return "CREATE TABLE {$this->tables->orders_meta} (
            meta_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL Default 0,
            meta_key varchar(255) DEFAULT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY  (meta_id),
            KEY post_id (post_id),
            KEY meta_key (meta_key)
        ) {$charset};";
	}

	/**
	 * SQL for the order items archive table.
	 *
	 * @param string $charset Character set and collation.
	 * @return string
	 */
	private function get_order_items_table_sql( string $charset ): string {
		return "CREATE TABLE {$this->tables->order_items} (
            order_item_id bigint(20) unsigned NOT NULL,
            order_item_name text NOT NULL,
            order_item_type varchar(200) NOT NULL DEFAULT '',
            order_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY (order_item_id),
            KEY order_id (order_id)
        ) {$charset};";
	}

	/**
	 * SQL for the order item meta archive table.
	 *
	 * @param string $charset Character set and collation.
	 * @return string
	 */
	private function get_order_items_meta_table_sql( string $charset ): string {
		return "CREATE TABLE {$this->tables->order_items_meta} (
            meta_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
            meta_key varchar(255) DEFAULT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY (meta_id),
            KEY order_item_id (order_item_id),
            KEY meta_key (meta_key(191))
        ) {$charset};";
	}

	/**
	 * SQL for the activity log table.
	 *
	 * @param string $charset Character set and collation.
	 * @return string
	 */
	private function get_logs_table_sql( string $charset ): string {
		return "CREATE TABLE {$this->tables->logs} (
            log_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(50) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT '',
            message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (log_id),
            KEY order_id (order_id),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset};";
	}

	/**
	 * SQL for the order notes archive table.
	 * Mirrors wp_comments structure for rows where comment_type
	 * is an order note ('order_note' or 'order_note_private').
	 *
	 * @param string $charset Character set and collation.
	 * @return string
	 */
	private function get_order_notes_table_sql( string $charset ): string {
		return "CREATE TABLE {$this->tables->order_notes} (
            comment_ID bigint(20) unsigned NOT NULL,
            comment_post_ID bigint(20) unsigned NOT NULL DEFAULT 0,
            comment_author tinytext NOT NULL,
            comment_author_email varchar(100) NOT NULL DEFAULT '',
            comment_author_url varchar(200) NOT NULL DEFAULT '',
            comment_author_IP varchar(100) NOT NULL DEFAULT '',
            comment_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            comment_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            comment_content text NOT NULL,
            comment_karma int(11) NOT NULL DEFAULT 0,
            comment_approved varchar(20) NOT NULL DEFAULT '1',
            comment_agent varchar(255) NOT NULL DEFAULT '',
            comment_type varchar(20) NOT NULL DEFAULT '',
            comment_parent bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (comment_ID),
            KEY comment_post_ID (comment_post_ID)
        ) {$charset};";
	}

	/**
	 * SQL for the order notes meta archive table.
	 * Mirrors wp_commentmeta structure.
	 *
	 * @param string $charset Character set and collation.
	 * @return string
	 */
	private function get_order_notes_meta_table_sql( string $charset ): string {
		return "CREATE TABLE {$this->tables->order_notes_meta} (
            meta_id bigint(20) unsigned NOT NULL,
            comment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            meta_key varchar(255) DEFAULT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY (meta_id),
            KEY comment_id (comment_id),
            KEY meta_key (meta_key(191))
        ) {$charset};";
	}

	/**
	 *
	 * SQL for the refunds archive table.
	 * Mirror wp_posts for shop_order_refund post type.
	 *
	 * @param string $charset Charachter set and collations.
	 * @return string
	 */
	private function get_order_refunds_table_sql( string $charset ): string {
		return "CREATE TABLE {$this->tables->order_refunds} (
            ID bigint(20) unsigned NOT NULL,
            post_author bigint(20) unsigned NOT NULL DEFAULT 0,
            post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content longtext NOT NULL,
            post_title text NOT NULL,
            post_excerpt text NOT NULL,
            post_status varchar(20) NOT NULL DEFAULT 'publish',
            post_password varchar(255) NOT NULL DEFAULT '',
            post_name varchar(200) NOT NULL DEFAULT '',
            post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_modified_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content_filtered longtext NOT NULL,
            post_parent bigint(20) unsigned NOT NULL DEFAULT 0,
            guid varchar(255) NOT NULL DEFAULT '',
            menu_order int(11) NOT NULL DEFAULT 0,
            post_type varchar(20) NOT NULL DEFAULT 'shop_order_refund',
            post_mime_type varchar(100) NOT NULL DEFAULT '',
            comment_count bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY (ID),
            KEY post_parent (post_parent)
        ){$charset};";
	}

	/**
	 * SQL for the order refund meta table archive.
	 *
	 * @param string $charset Charchter set and collation.
	 * @return string
	 */
	private function get_order_refunds_meta_table_sql( string $charset ): string {
		return "CREATE TABLE {$this->tables->order_refunds_meta} (
            meta_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            meta_key varchar(255) DEFAULT NULL,
            meta_value longtext DEFAULT NULL,
            PRIMARY KEY (meta_id),
            KEY post_id (post_id),
            KEY meta_key (meta_key(191))
        ){$charset};";
	}

	/**
	 * Runs table upgrades when the plugin version changes.
	 * Called on every page load — compares stored version against
	 * current version and runs create_tables() only when needed.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {

		$installed_version = get_option( 'hw_woam_db_version', '0.0.0' );

		if ( version_compare( $installed_version, HW_WOAM_VERSION, '<' ) ) {
			$this->create_tables();
		}
	}
}
