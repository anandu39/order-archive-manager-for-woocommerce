<?php
/**
 * Archived Orders tab - Archive Vault with statistics, restore/delete operations, and integrity monitoring.
 * Populated and driven by woam-admin.js. This file is the structural shell only.
 *
 * Phase 4: Complete redesign with Archive Vault concept, statistics banner, and confidence section
 * 
 * @package HW\WOAM\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<!-- Archive Vault Statistics Banner (NEW - Phase 4) -->
<div class="woam-vault-stats" id="woam-vault-stats">
    <div class="woam-vault-stat-card">
        <span class="dashicons dashicons-archive"></span>
        <div class="woam-vault-stat-content">
            <span class="woam-vault-stat-number" id="woam-vault-total-orders">0</span>
            <span class="woam-vault-stat-label"><?php esc_html_e( 'Archived Orders', 'order-archive-manager-for-woocommerce' ); ?></span>
        </div>
    </div>
    <div class="woam-vault-stat-card">
        <span class="dashicons dashicons-chart-line"></span>
        <div class="woam-vault-stat-content">
            <span class="woam-vault-stat-number" id="woam-vault-total-saved">0 MB</span>
            <span class="woam-vault-stat-label"><?php esc_html_e( 'Storage Saved', 'order-archive-manager-for-woocommerce' ); ?></span>
        </div>
    </div>
    <div class="woam-vault-stat-card">
        <span class="dashicons dashicons-money-alt"></span>
        <div class="woam-vault-stat-content">
            <span class="woam-vault-stat-number" id="woam-vault-total-revenue">$0</span>
            <span class="woam-vault-stat-label"><?php esc_html_e( 'Revenue in Archive', 'order-archive-manager-for-woocommerce' ); ?></span>
        </div>
    </div>
    <div class="woam-vault-stat-card">
        <span class="dashicons dashicons-calendar-alt"></span>
        <div class="woam-vault-stat-content">
            <span class="woam-vault-stat-number" id="woam-vault-last-archive"><?php esc_html_e( 'Never', 'order-archive-manager-for-woocommerce' ); ?></span>
            <span class="woam-vault-stat-label"><?php esc_html_e( 'Last Archive', 'order-archive-manager-for-woocommerce' ); ?></span>
        </div>
    </div>
</div>

<!-- Restore Confidence Section (NEW - Phase 4) -->
<div class="woam-confidence-section" id="woam-confidence-section">
    <div class="woam-confidence-header">
        <span class="dashicons dashicons-shield-alt"></span>
        <h3><?php esc_html_e( 'Restore Confidence', 'order-archive-manager-for-woocommerce' ); ?></h3>
    </div>
    <div class="woam-confidence-grid">
        <div class="woam-confidence-item" data-confidence="integrity">
            <span class="woam-confidence-icon dashicons dashicons-database"></span>
            <div class="woam-confidence-info">
                <span class="woam-confidence-label"><?php esc_html_e( 'Data Integrity', 'order-archive-manager-for-woocommerce' ); ?></span>
                <span class="woam-confidence-status" id="woam-confidence-integrity"><?php esc_html_e( 'Checking...', 'order-archive-manager-for-woocommerce' ); ?></span>
            </div>
        </div>
        <div class="woam-confidence-item" data-confidence="restore">
            <span class="woam-confidence-icon dashicons dashicons-update"></span>
            <div class="woam-confidence-info">
                <span class="woam-confidence-label"><?php esc_html_e( 'Restore Success Rate', 'order-archive-manager-for-woocommerce' ); ?></span>
                <span class="woam-confidence-status" id="woam-confidence-rate">--%</span>
            </div>
        </div>
        <div class="woam-confidence-item" data-confidence="verify">
            <span class="woam-confidence-icon dashicons dashicons-yes-alt"></span>
            <div class="woam-confidence-info">
                <span class="woam-confidence-label"><?php esc_html_e( 'Verification Status', 'order-archive-manager-for-woocommerce' ); ?></span>
                <span class="woam-confidence-status" id="woam-confidence-verify"><?php esc_html_e( 'Awaiting Scan', 'order-archive-manager-for-woocommerce' ); ?></span>
            </div>
        </div>
        <div class="woam-confidence-item" data-confidence="backup">
            <span class="woam-confidence-icon dashicons dashicons-backup"></span>
            <div class="woam-confidence-info">
                <span class="woam-confidence-label"><?php esc_html_e( 'Last Integrity Scan', 'order-archive-manager-for-woocommerce' ); ?></span>
                <span class="woam-confidence-status" id="woam-confidence-last-scan"><?php esc_html_e( 'Never', 'order-archive-manager-for-woocommerce' ); ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Archive Inventory Card (renamed for Phase 4) -->
<div class="woam-card woam-card--vault">
    <h2>
        <span class="dashicons dashicons-archive"></span>
        <?php esc_html_e( 'Archive Vault', 'order-archive-manager-for-woocommerce' ); ?>
    </h2>
    <div id="woam-archive-inventory" class="woam-loading">
        <?php esc_html_e( 'Loading archive contents...', 'order-archive-manager-for-woocommerce' ); ?>
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
        <h2><?php esc_html_e( 'Step 1: Select Action', 'order-archive-manager-for-woocommerce' ); ?></h2>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'What would you like to do?', 'order-archive-manager-for-woocommerce' ); ?></label>
            <div class="woam-radio-group">
                <label class="woam-radio">
                    <input type="radio" name="archived_action" value="restore" checked />
                    <span class="dashicons dashicons-restore"></span>
                    <?php esc_html_e( 'Restore orders to WooCommerce', 'order-archive-manager-for-woocommerce' ); ?>
                </label>
                <label class="woam-radio">
                    <input type="radio" name="archived_action" value="delete" />
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e( 'Permanently delete from archive', 'order-archive-manager-for-woocommerce' ); ?>
                </label>
            </div>
        </div>

        <div class="woam-field-group">
            <label><?php esc_html_e( 'Filter by status (leave all unchecked for all statuses)', 'order-archive-manager-for-woocommerce' ); ?></label>
            <div class="woam-checkbox-grid" id="woam-archived-statuses">
                <p class="woam-loading"><?php esc_html_e( 'Loading statuses…', 'order-archive-manager-for-woocommerce' ); ?></p>
            </div>
        </div>

        <!-- Bulk select/deselect for archived statuses (NEW) -->
        <div class="woam-bulk-actions">
            <button type="button" class="woam-bulk-btn" data-select-all-archived-statuses>
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e( 'Select All', 'order-archive-manager-for-woocommerce' ); ?>
            </button>
            <button type="button" class="woam-bulk-btn" data-deselect-all-archived-statuses>
                <span class="dashicons dashicons-no-alt"></span>
                <?php esc_html_e( 'Deselect All', 'order-archive-manager-for-woocommerce' ); ?>
            </button>
        </div>

        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--primary" id="woam-archived-step1-next">
                <?php esc_html_e( 'Review Impact', 'order-archive-manager-for-woocommerce' ); ?>
            </button>
        </div>
    </div>

    <!-- Step 2 — Review Impact -->
    <div class="woam-step" data-step="2">
        <h2><?php esc_html_e( 'Step 2: Review Impact', 'order-archive-manager-for-woocommerce' ); ?></h2>

        <div id="woam-archived-impact" class="woam-loading">
            <?php esc_html_e( 'Calculating…', 'order-archive-manager-for-woocommerce' ); ?>
        </div>

        <div class="woam-step-actions">
            <button type="button" class="woam-button woam-button--secondary" data-step-back="1">
                <?php esc_html_e( 'Back', 'order-archive-manager-for-woocommerce' ); ?>
            </button>
            <button type="button" class="woam-button woam-button--primary" id="woam-archived-step2-next">
                <?php esc_html_e( 'Continue', 'order-archive-manager-for-woocommerce' ); ?>
            </button>
        </div>
    </div>

    <!-- Step 3 — Run Operation -->
    <div class="woam-step" data-step="3">
        <h2 id="woam-archived-step3-title"><?php esc_html_e( 'Step 3: Restore Orders', 'order-archive-manager-for-woocommerce' ); ?></h2>

        <label class="woam-checkbox">
            <input type="checkbox" id="woam-archived-dry-run" checked />
            <?php esc_html_e( 'Dry run (no changes will be made)', 'order-archive-manager-for-woocommerce' ); ?>
        </label>

        <div class="woam-field-group" id="woam-archived-confirm-group" style="display:none;">
            <label for="woam-archived-confirm">
                <?php
                /* translators: %s: the word DELETE that the user must type */
                printf( esc_html__( 'Type %s to confirm permanent deletion', 'order-archive-manager-for-woocommerce' ), '<strong>DELETE</strong>' );
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
                <?php esc_html_e( 'Back', 'order-archive-manager-for-woocommerce' ); ?>
            </button>
            <button type="button" class="woam-button woam-button--primary" id="woam-archived-start">
                <?php esc_html_e( 'Start Restore', 'order-archive-manager-for-woocommerce' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Enhanced Integrity Check Section (Phase 4) -->
<div class="woam-card woam-card--integrity">
    <h2>
        <span class="dashicons dashicons-health"></span>
        <?php esc_html_e( 'Archive Health Monitor', 'order-archive-manager-for-woocommerce' ); ?>
    </h2>
    <p><?php esc_html_e( 'Scans the archive tables for orphaned rows, missing records, and data integrity issues.', 'order-archive-manager-for-woocommerce' ); ?></p>
    
    <div class="woam-integrity-actions">
        <button type="button" class="woam-button woam-button--secondary" id="woam-run-integrity-check">
            <span class="dashicons dashicons-search"></span>
            <?php esc_html_e( 'Run Full Health Scan', 'order-archive-manager-for-woocommerce' ); ?>
        </button>
        <button type="button" class="woam-button woam-button--secondary" id="woam-fix-orphans" style="display:none;">
            <span class="dashicons dashicons-hammer"></span>
            <?php esc_html_e( 'Fix Orphaned Records', 'order-archive-manager-for-woocommerce' ); ?>
        </button>
    </div>
    
    <div id="woam-integrity-result"></div>
    
    <!-- Scan History (NEW - Phase 4) -->
    <div class="woam-scan-history" id="woam-scan-history" style="display:none;">
        <h4><?php esc_html_e( 'Scan History', 'order-archive-manager-for-woocommerce' ); ?></h4>
        <div id="woam-scan-history-list"></div>
    </div>
</div>