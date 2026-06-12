<?php

/**
 * 
 * Overview tab - Storage Impact, Database visualizer, Archive Health, Recent Activity.
 * Populated entirely by woam-admin.js via AJAX. This file is Empty skeleton only.
 * 
 * @package HW\WOAM\Admin
*/

defined ( 'ABSPATH' ) || exit;

?>

<div class="woam=grid">

    <!-- Storage Impact Card -->
    
    <div class="woam-card woam-card--full">
        <h2><?php esc_html_e( 'Storage Impact', 'woo-order-archive-manager' ); ?></h2>
        <div id="woam-storage-impact" class="woam-loading">
            <?php esc_html_e( 'Loading...', 'woo-order-archive-manager' ); ?>
        </div>
    </div>
    
    <!-- Database Visualizer -->
     <div class="woam-card">
        <h2><?php esc_html_e( 'Database Breakdown', 'woo-order-archive-manager' ); ?></h2>
        <div id ="woam-db-visualizer" class="woam-loading">
            <?php esc_html_e( 'Loading...', 'woo-order-archive-manager' ); ?>
        </div>
    </div>

    <!-- Archive Health -->
     <div class="woam-card">
        <h2><?php esc_html_e( 'Archive Health', 'woo-order-archive-manager' ); ?></h2>
        <ul id ="woam-archive-health" class="woam-checklist woam-loading">
            <li><?php esc_html_e( 'Loading...', 'woo-order-archive-manager' ); ?></li>
        </ul>
    </div>

    <!-- Recent Activity -->
     <div class="woam-card">
        <h2><?php esc_html_e( 'Recent Activity', 'woo-order-archive-manager' ); ?></h2>
        <ul id ="woam-recent-activity" class="woam-timeline woam-loading">
            <li><?php esc_html_e( 'Loading...', 'woo-order-archive-manager' ); ?></li>
        </ul>
    </div>
</div>

