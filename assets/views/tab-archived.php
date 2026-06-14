<?php
/**
 * Archived Orders tab — Inventory, Restore/Delete step flow, Integrity Check.
 * Populated and driven by woam-admin.js. This file is the structural shell only.
 *
 * @package HW\WOAM\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<!-- Archive Inventory — outside the step flow, always visible -->
<div class="woam-card">
    <h2><?php esc_html_e( 'Archive Inventory', 'woo-order-archive-manager' ); ?></h2>
    <div id="woam-archive-inventory" class="woam-loading">
        <?php esc_html_e( 'Loading…', 'woo-order-archive-manager' ); ?>
    </div>
</div>

<div class="woam-steps" data-mode="archived">

    <!-- Step Indicator -->
    <div class="woam-step-indicator">
        <span class="woam-step-dot woam-step-dot--active" data-step="1">1</span>
        <span class="woam-step-line"></span>
        <span class="woam-step-dot" data-step="2">2</span>
        <span class="woam-step-line"></span>
        <span class="woam-step-dot" data-step="3">3</span>
    </div>

    <!-- Step 1 — Select Action and Filters -->
    <div class="woam-step woam-step--active" data-step="1">
        <h2><?php esc_html_e( 'Step 1: Select Action', 'woo-order-archive-manager' ); ?></h2>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'What would you like to do?', 'woo-order-archive-manager' ); ?></label>
            <div class="woam-radio-group">
                <label class="woam-radio">
                    <input type="radio" name="archived_action" value="restore" checked />
                    <?php esc_html_e( 'Restore orders to WooCommerce', 'woo-order-archive-manager' ); ?>
                </label>
                <label class="woam-radio">
                    <input type="radio" name="archived_action" value="delete" />
                    <?php esc_html_e( 'Permanently delete from archive', 'woo-order-archive-manager' ); ?>
                </label>
            </div>
        </div>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'Filter by status (leave all unchecked for all statuses)', 'woo-order-archive-manager' ); ?></label>
            <!-- Populated by JS from hw_woam_get_archive_breakdown -->
            <div class="woam-checkbox-grid" id="woam-archived-statuses">
                <p class="woam-loading"><?php esc_html_e( 'Loading statuses…', 'woo-order-archive-manager' ); ?></p>
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
        <h2 id="woam-archived-step3-title"><?php esc_html_e( 'Step 3: Restore Orders', 'woo-order-archive-manager' ); ?></h2>

        <label class="woam-checkbox">
            <input type="checkbox" id="woam-archived-dry-run" checked />
            <?php esc_html_e( 'Dry run (no changes will be made)', 'woo-order-archive-manager' ); ?>
        </label>

        <!-- Confirm input — visible only for permanent delete, hidden by default -->
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
                <?php esc_html_e( 'Start Restore', 'woo-order-archive-manager' ); ?>
            </button>
        </div>
    </div>

</div>

<!-- Integrity Check — below the step flow -->
<div class="woam-card woam-integrity-check">
    <h2><?php esc_html_e( 'Archive Integrity Check', 'woo-order-archive-manager' ); ?></h2>
    <p><?php esc_html_e( 'Scans the archive tables for orphaned rows — records with no matching parent order.', 'woo-order-archive-manager' ); ?></p>
    <button type="button" class="woam-button woam-button--secondary" id="woam-run-integrity-check">
        <?php esc_html_e( 'Run Integrity Check', 'woo-order-archive-manager' ); ?>
    </button>
    <div id="woam-integrity-result"></div>
</div>