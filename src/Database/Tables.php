<?php

/**
 * Archive table name definitions.
 * 
 * Provide a single of truth for all archive table names.
 * Every class that needs a table name recieve an instance of this class via
 * constructor injection. and no class constructs table names on its own.
 * 
 * @package HW\WOAM\Database
 * 
*/

namespace HW\WOAM\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tables
 *
 */

class Tables{

    /**
     * Archived orders table.
     * Mirrors data from wp_posts (order rows only).
     *
     * @var string
    */
    public readonly string $orders;

    /**
     * Archived order meta table.
     * Mirrors data from wp_postmeta (order meta rows only).
     *
     * @var string
    */
    public readonly string $orders_meta;

    /**
     * Archived order items table.
     * Mirrors data from woocommerce_order_items.
     *
     * @var string
    */
    public readonly string $order_items;

    /**
     * Archived order item meta table.
     * Mirrors data from woocommerce_order_itemmeta.
     *
     * @var string
    */
    public readonly string $order_items_meta;

    /**
     * Activity log table.
     * Records all archive, restore and delete actions.
     *
     * @var string
    */
    
    public readonly string $logs;

    /**
     * 
     * Archived order notes table.
     * Mirrors data from wp_comments (order notes only).
     * 
     * @var string
     */

    public readonly string $order_notes;

    /**
     * 
     * Archived order note meta table.
     * Mirrors data from wp_commentmeta (order note meta only).
     * 
     * @var string
     */

    public readonly string $order_notes_meta;

    /**
     * Constructor.
     * Builds table names using the correct WordPress table prefix.
     * 
     * @param \wpdb $wpdb WordPress database object, injected for testability.
    */

    public function __construct(\wpdb $wpdb) {

        $prefix = $wpdb->prefix . 'woam_';

        $this->orders = $prefix . 'orders';
        $this->orders_meta = $prefix . 'orders_meta';
        $this->order_items = $prefix . 'order_items';
        $this->order_items_meta = $prefix . 'order_items_meta';

        $this->logs = $prefix . 'logs';
        $this->order_notes      = $prefix . 'order_notes';
        $this->order_notes_meta = $prefix . 'order_notes_meta';

    }

}