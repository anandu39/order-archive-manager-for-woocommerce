<?php
/**
 * Benchmark Manager
 *
 * Measures database performance before and after archiving.
 *
 * @package HW\WOAM\Benchmark
 */

namespace HW\WOAM\Benchmark;

defined( 'ABSPATH' ) || exit;

/**
 * Class BenchmarkManager
 */
class BenchmarkManager {

    /**
     * WordPress database object.
     *
     * @var \wpdb
     */
    private \wpdb $wpdb;

    /**
     * Constructor.
     *
     * @param \wpdb $wpdb WordPress database object.
     */
    public function __construct( \wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Run full benchmark suite.
     *
     * @return array<string, mixed>
     */
    public function run_benchmarks(): array {
        return array(
            'order_lookup'     => $this->benchmark_order_lookup(),
            'order_search'     => $this->benchmark_order_search(),
            'order_meta_query' => $this->benchmark_order_meta_query(),
            'order_item_query' => $this->benchmark_order_item_query(),
            'timestamp'        => current_time( 'mysql' ),
            'order_count'      => $this->get_order_count(),
            'table_size'       => $this->get_order_table_size(),
        );
    }

    /**
     * Benchmark order lookup.
     *
     * @return float Time in milliseconds.
     */
    private function benchmark_order_lookup(): float {
        $start = microtime( true );

        $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT ID, post_status, post_date 
                FROM %i 
                WHERE post_type = 'shop_order' 
                AND post_status = 'wc-completed'
                LIMIT 100",
                $this->wpdb->posts
            )
        );

        return round( ( microtime( true ) - $start ) * 1000, 2 );
    }

    /**
     * Benchmark order search.
     *
     * @return float Time in milliseconds.
     */
    private function benchmark_order_search(): float {
        $start = microtime( true );

        $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT ID, post_title, post_date 
                FROM %i 
                WHERE post_type = 'shop_order' 
                AND post_title LIKE '%s'
                LIMIT 100",
                $this->wpdb->posts,
                '%order%'
            )
        );

        return round( ( microtime( true ) - $start ) * 1000, 2 );
    }

    /**
     * Benchmark order meta query.
     *
     * @return float Time in milliseconds.
     */
    private function benchmark_order_meta_query(): float {
        $start = microtime( true );

        $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT post_id, meta_key, meta_value 
                FROM %i 
                WHERE meta_key = '_order_total'
                LIMIT 100",
                $this->wpdb->postmeta
            )
        );

        return round( ( microtime( true ) - $start ) * 1000, 2 );
    }

    /**
     * Benchmark order item query.
     *
     * @return float Time in milliseconds.
     */
    private function benchmark_order_item_query(): float {
        $start = microtime( true );

        $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT order_item_id, order_item_name, order_item_type 
                FROM %i 
                WHERE order_item_type = 'line_item'
                LIMIT 100",
                $this->wpdb->prefix . 'woocommerce_order_items'
            )
        );

        return round( ( microtime( true ) - $start ) * 1000, 2 );
    }

    /**
     * Get total order count.
     *
     * @return int
     */
    private function get_order_count(): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order'",
                $this->wpdb->posts
            )
        );
    }

    /**
     * Get order table size in bytes.
     *
     * @return int
     */
    private function get_order_table_size(): int {
        $tables = array( $this->wpdb->posts, $this->wpdb->postmeta );
        $placeholders = implode( ', ', array_fill( 0, count( $tables ), '%s' ) );

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT SUM(DATA_LENGTH + INDEX_LENGTH) 
                FROM information_schema.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME IN ({$placeholders})",
                $tables
            )
        );
    }

    /**
     * Store benchmark results.
     *
     * @param string $type 'before' or 'after'.
     * @param array  $results Benchmark results.
     * @return void
     */
    public function store_benchmarks( string $type, array $results ): void {
        $benchmarks = get_option( 'hw_woam_benchmarks', array() );
        $benchmarks[ $type ] = $results;
        update_option( 'hw_woam_benchmarks', $benchmarks );
    }

    /**
     * Get benchmark comparison.
     *
     * @return array
     */
    public function get_comparison(): array {
        $benchmarks = get_option( 'hw_woam_benchmarks', array() );
        $before = $benchmarks['before'] ?? array();
        $after = $benchmarks['after'] ?? array();

        if ( empty( $before ) || empty( $after ) ) {
            return array(
                'has_data' => false,
                'message'  => __( 'Run benchmarks before and after archiving to see improvements.', 'woo-order-archive-manager' ),
            );
        }

        $metrics = array(
            'order_lookup'     => array(
                'label' => __( 'Order Lookup', 'woo-order-archive-manager' ),
                'before' => $before['order_lookup'] ?? 0,
                'after'  => $after['order_lookup'] ?? 0,
            ),
            'order_search'     => array(
                'label' => __( 'Order Search', 'woo-order-archive-manager' ),
                'before' => $before['order_search'] ?? 0,
                'after'  => $after['order_search'] ?? 0,
            ),
            'order_meta_query' => array(
                'label' => __( 'Order Meta Query', 'woo-order-archive-manager' ),
                'before' => $before['order_meta_query'] ?? 0,
                'after'  => $after['order_meta_query'] ?? 0,
            ),
            'order_item_query' => array(
                'label' => __( 'Order Item Query', 'woo-order-archive-manager' ),
                'before' => $before['order_item_query'] ?? 0,
                'after'  => $after['order_item_query'] ?? 0,
            ),
        );

        $comparison = array(
            'has_data' => true,
            'metrics'  => array(),
            'storage_before' => $before['table_size'] ?? 0,
            'storage_after'  => $after['table_size'] ?? 0,
        );

        foreach ( $metrics as $key => $metric ) {
            $before_val = $metric['before'];
            $after_val = $metric['after'];
            $improvement = $before_val > 0 ? round( ( ( $before_val - $after_val ) / $before_val ) * 100 ) : 0;

            $comparison['metrics'][ $key ] = array(
                'label'        => $metric['label'],
                'before'       => $before_val,
                'after'        => $after_val,
                'improvement'  => $improvement,
                'improvement_label' => $improvement > 0 ? sprintf( '%d%% %s', $improvement, __( 'faster', 'woo-order-archive-manager' ) ) : __( 'No significant change', 'woo-order-archive-manager' ),
            );
        }

        return $comparison;
    }
}