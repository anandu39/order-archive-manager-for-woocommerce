<?php

/**
 * 
 * Archive order tab - Select filters, review impact, run the archive loop. 
 * Populated and driven by woam-admin.js. This file is the structural shell only.
 * 
 * @package HW\WOAM\Admin 
*/

defined ( 'ABSPATH' ) || exit;

$order_statuses = function_exists ( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
?>

<div class="woam-steps" data-mode="archive">

    <!-- Step Indicator -->
    <div class="woam-step-indicator">
        <span class="woam-step-dot woam-step-dot--active" data-step="1">1</span>
        <span class="woam-step-line"></span>
        <span class="woam-step-dot" data-step="2">2</span>
        <span class="woam-step-line"></span>
        <span class="woam-step-dot" data-step="3">3</span>
    </div>

    <!-- Step 1 - Select Orders -->
    <div class="woam-step woam-step--active" data-step="1">
        <h2><?php esc_html_e( 'Step 1: Select Orders', 'woo-order-archive-manager' ); ?></h2>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'Archive orders placed before', 'woo-order-archive-manager' ); ?></label>

            <div class="woam-presets">
                <button type="button" class="woam-presets-btn" data-month="6"><?php esc_html_e( '6 months ago', 'woo-order-archive-manager' ); ?> </button>
                <button type="button" class="woam-presets-btn" data-month="12"><?php esc_html_e( '1 year ago', 'woo-order-archive-manager' ); ?> </button>
                <button type="button" class="woam-presets-btn" data-month="24"><?php esc_html_e( '2 years ago', 'woo-order-archive-manager' ); ?> </button>
            </div>

            <input type="date" id="woam-before-date" class="woam-input" />
        </div>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'Order statuses to include', 'woo-order-archive-manager' ); ?></label>

            <div class="woam-checkbox-grid" id="woam-archive-statuses">
                <?php foreach( $order_statuses as $status_key => $status_label) : ?>
                <label class="woam-checkbox">
                    <input type="checkbox" name="archive_statuses[]" value="<?php echo esc_attr( $status_key ); ?>"/>
                    <?php echo esc_html( $status_label ); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--primary" id="woam-archive-step1-next">
                <?php esc_html_e( 'Review Impact', 'woo-order-archive-manager' ); ?>
            </button>
        </div>
    </div>

    <!-- Step 2 — Review Impact -->
    <div class="woam-step" data-step="2">
        <h2><?php esc_html_e( 'Step 2: Review Impact', 'woo-order-archive-manager' ); ?></h2>

        <div id="woam-archive-impact" class="woam-loading">
            <?php esc_html_e( 'Calculating…', 'woo-order-archive-manager' ); ?>
        </div>

        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--secondary" data-step-back="1">
                <?php esc_html_e( 'Back', 'woo-order-archive-manager' ); ?>
            </button>
            <button type="button" class="woam-button woam-button--primary" id="woam-archive-step2-next">
                <?php esc_html_e( 'Continue', 'woo-order-archive-manager' ); ?>
            </button>
        </div>
    </div>

    <!-- Step 3 — Run Archive -->
    <div class="woam-step" data-step="3">
        <h2><?php esc_html_e( 'Step 3: Run Archive', 'woo-order-archive-manager' ); ?></h2>

        <label class="woam-checkbox">
            <input type="checkbox" id="woam-archive-dry-run" checked />
            <?php esc_html_e( 'Dry run (no changes will be made)', 'woo-order-archive-manager' ); ?>
        </label>

        <div class="woam-field-group" id="woam-archive-confirm-group">
            <label for="woam-archive-confirm">
                <?php
                /* translators: %s: the word ARCHIVE that the user must type */
                printf( esc_html__( 'Type %s to confirm', 'woo-order-archive-manager' ), '<strong>ARCHIVE</strong>' );
                ?>
            </label>
            <input type="text" id="woam-archive-confirm" class="woam-input" autocomplete="off" />
        </div>

        <div class="woam-progress" id="woam-archive-progress" style="display:none;">
            <div class="woam-progress-bar">
                <div class="woam-progress-fill" id="woam-archive-progress-fill"></div>
            </div>
            <p id="woam-archive-progress-text"></p>
        </div>

        <div id="woam-archive-summary"></div>

        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--secondary" data-step-back="2">
                <?php esc_html_e( 'Back', 'woo-order-archive-manager' ); ?>
            </button>
            <button type="button" class="woam-button woam-button--primary" id="woam-archive-start">
                <?php esc_html_e( 'Start Archive', 'woo-order-archive-manager' ); ?>
            </button>
        </div>
    </div>
</div>
