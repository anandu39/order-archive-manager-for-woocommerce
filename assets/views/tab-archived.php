<?php

/**
 * 
 * Archived Order Tab - Status breakdown inventory, restore and delete controls.
 * Populated and driven by woam-admin.js. This file is the structural skeleton only.
 * 
 * @package HW\WOAM\Admin
*/

defined( 'ABSPATH' ) || exit;
?>

<div class="woam-steps" data-mode="archived">
    
    <!-- Archive inventory - loaded on tab activation, Outside the step flow -->
    <div class="woam-card woam-card--full" id="woam-archive-inventory-card">
        <h2><?php esc_html_e( 'Archive Inventory', 'woo-order-archive-manager' ); ?></h2>
        <div id="woam-archive-inventory" class="woam-loading">
            <?php esc_html_e( 'Loading...', 'woo-order-archive-manager' ); ?>
        </div>
    </div>

    <!-- Step Indicator -->
    <div class="woam-step-indicator">
        <span class="woam-step-dot woam-step-dot--active" data-step="1">1</span>
        <span class="woam-step-line"></span>
        <span class="woam-step-dot" data-step="2">2</span>
        <span class="woam-step-line"></span>
        <span class="woam-step-dot" data-step="3">3</span>
    </div>

     <!-- Step 1 — Select Action -->
    <div class="woam-step woam-step--active" data-step="1">
        <h2><?php esc_html_e( 'Step 1: Select Action', 'woo-order-archive-manager' ); ?></h2>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'What would you like to do with archived orders?', 'woo-order-archive-manager' ); ?></label>

            <div class="woam-radio-group">
                <label class="woam-radio">
                    <input type="radio" name="archived_action" value="restore" checked />
                    <span><?php esc_html_e( 'Restore to live WooCommerce tables', 'woo-order-archive-manager' ); ?></span>
                </label>
                <label class="woam-radio">
                    <input type="radio" name="archived_action" value="delete" />
                    <span><?php esc_html_e( 'Permanently delete from archive', 'woo-order-archive-manager' ); ?></span>
                </label>
            </div>
        </div>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'Filter by order status (optional)', 'woo-order-archive-manager' ); ?></label>
            <div class="woam-checkbox-grid" id="woam-archived-statuses">
                <!-- Populated by JS from archive breakdown data -->
            </div>
        </div>

        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--primary" id="woam-archived-step1-next">
                <?php esc_html_e( 'Review Impact', 'woo-order-archive-manager' ); ?>
            </button>
        </div>
    </div>

    <!-- Step 2 — Review Impact -->
    <div class="woam-step" data-step="2">
        <h2><?php esc_html_e( 'Step 2: Review Impact', 'woo-order-archive-manager' ); ?></h2>

        <div id="woam-archived-impact" class="woam-loading">
            <?php esc_html_e( 'Calculating…', 'woo-order-archive-manager' ); ?>
        </div>

        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--secondary" data-step-back="1">
                <?php esc_html_e( 'Back', 'woo-order-archive-manager' ); ?>
            </button>
            <button type="button" class="woam-button woam-button--primary" id="woam-archived-step2-next">
                <?php esc_html_e( 'Continue', 'woo-order-archive-manager' ); ?>
            </button>
        </div>
    </div>

    <!-- Step 3 — Run Operation -->
    <div class="woam-step" data-step="3">
        <h2 id="woam-archived-step3-title">
            <?php esc_html_e( 'Step 3: Run Operation', 'woo-order-archive-manager' ); ?>
        </h2>

        <label class="woam-checkbox">
            <input type="checkbox" id="woam-archived-dry-run" checked />
            <?php esc_html_e( 'Dry run (no changes will be made)', 'woo-order-archive-manager' ); ?>
        </label>

        <!-- Confirm input — JS shows/hides based on action (delete requires it, restore does not) -->
        <div class="woam-field-group" id="woam-archived-confirm-group" style="display:none;">
            <label for="woam-archived-confirm">
                <?php
                /* translators: %s: the word DELETE that the user must type */
                printf( esc_html__( 'Type %s to confirm permanent deletion', 'woo-order-archive-manager' ), '<strong>DELETE</strong>' );
                ?>
            </label>
            <input type="text" id="woam-archived-confirm" class="woam-input" autocomplete="off" />
        </div>

        <div class="woam-progress" id="woam-archived-progress" style="display:none;">
            <div class="woam-progress-bar">
                <div class="woam-progress-fill" id="woam-archived-progress-fill"></div>
            </div>
            <p id="woam-archived-progress-text"></p>
        </div>

        <div id="woam-archived-summary"></div>

        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--secondary" data-step-back="2">
                <?php esc_html_e( 'Back', 'woo-order-archive-manager' ); ?>
            </button>
            <button type="button" class="woam-button woam-button--primary" id="woam-archived-start">
                <?php esc_html_e( 'Start', 'woo-order-archive-manager' ); ?>
            </button>
        </div>

        <!-- Integrity Check — separate from the batch flow -->
        <div class="woam-integrity-check">
            <hr />
            <h3><?php esc_html_e( 'Archive Integrity Check', 'woo-order-archive-manager' ); ?></h3>
            <p><?php esc_html_e( 'Scans archive tables for orphaned rows. Should always pass — provided as a diagnostic tool.', 'woo-order-archive-manager' ); ?></p>
            <button type="button" class="woam-button woam-button--secondary" id="woam-run-integrity-check">
                <?php esc_html_e( 'Run Integrity Check', 'woo-order-archive-manager' ); ?>
            </button>
            <div id="woam-integrity-result"></div>
        </div>

    </div>
</div>