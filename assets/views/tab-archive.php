<?php
/**
 * Archive order tab - Select filters, review impact, run the archive loop.
 * 
 * @package HW\WOAM\Admin 
 */

defined( 'ABSPATH' ) || exit;

$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
$subscriptions_active = class_exists( 'WC_Subscriptions' );
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

        <!-- Date Selection -->
        <div class="woam-field-group">
            <label><?php esc_html_e( 'Archive orders placed before', 'woo-order-archive-manager' ); ?></label>

            <div class="woam-presets">
                <button type="button" class="woam-preset-btn" data-month="3"><?php esc_html_e( '3 months ago', 'woo-order-archive-manager' ); ?></button>
                <button type="button" class="woam-preset-btn" data-month="6"><?php esc_html_e( '6 months ago', 'woo-order-archive-manager' ); ?></button>
                <button type="button" class="woam-preset-btn" data-month="12"><?php esc_html_e( '1 year ago', 'woo-order-archive-manager' ); ?></button>
                <button type="button" class="woam-preset-btn" data-month="24"><?php esc_html_e( '2 years ago', 'woo-order-archive-manager' ); ?></button>
            </div>

            <input type="date" id="woam-before-date" class="woam-input" />
        </div>

        <!-- Order Analysis Preview -->
        <div id="woam-order-analysis" style="display: none; margin: 16px 0;">
            <h4 style="font-size: 14px; margin-bottom: 12px;"><?php esc_html_e( 'Order Analysis', 'woo-order-archive-manager' ); ?></h4>
            <div id="woam-order-breakdown" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;"></div>
            <div id="woam-order-total" style="font-size: 13px; color: #646970;"></div>
        </div>

        <!-- ============================================================ -->
        <!-- SECTION A - GENERAL ORDER ARCHIVE                             -->
        <!-- ============================================================ -->
        <div class="woam-section woam-section--general" style="margin-top: 24px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                <span class="dashicons dashicons-archive" style="color: #7f54b3; font-size: 20px;"></span>
                <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1d2327;">
                    <?php esc_html_e( 'General Order Archive', 'woo-order-archive-manager' ); ?>
                </h3>
                <span style="margin-left: auto; font-size: 11px; color: #646970; background: #f0f0f1; padding: 2px 10px; border-radius: 4px;">
                    <?php esc_html_e( 'Standard WooCommerce orders', 'woo-order-archive-manager' ); ?>
                </span>
            </div>

            <div class="woam-field-group">
                <label><?php esc_html_e( 'Order statuses to include', 'woo-order-archive-manager' ); ?></label>

                <div class="woam-checkbox-grid" id="woam-archive-statuses">
                    <?php foreach ( $order_statuses as $status_key => $status_label ) : ?>
                    <label class="woam-checkbox">
                        <input type="checkbox" name="archive_statuses[]" value="<?php echo esc_attr( $status_key ); ?>" />
                        <?php echo esc_html( $status_label ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="font-size: 12px; color: #646970; margin-top: -8px;">
                <span class="dashicons dashicons-info" style="font-size: 14px;"></span>
                <?php esc_html_e( 'Archiving General Orders does not affect active subscriptions or subscription renewals.', 'woo-order-archive-manager' ); ?>
            </p>

            <div class="woam-step-actions" style="margin-top: 16px;">
                <button type="button" class="woam-button woam-button--primary" id="woam-archive-general-preview">
                    <span class="dashicons dashicons-chart-line"></span>
                    <?php esc_html_e( 'Preview General Orders', 'woo-order-archive-manager' ); ?>
                </button>
            </div>
        </div>

        <?php if ( $subscriptions_active ) : ?>
        <!-- ============================================================ -->
        <!-- SECTION B - SUBSCRIPTION ORDER ARCHIVE                       -->
        <!-- ============================================================ -->
        <div class="woam-section woam-section--subscription" style="margin-top: 24px; padding-top: 20px; border-top: 2px solid #e0e0e0;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px;">
                <span class="dashicons dashicons-cart" style="color: #7f54b3; font-size: 20px;"></span>
                <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1d2327;">
                    <?php esc_html_e( 'Subscription Order Archive', 'woo-order-archive-manager' ); ?>
                </h3>
                <span style="margin-left: auto; font-size: 11px; color: #646970; background: #f0f0f1; padding: 2px 10px; border-radius: 4px;">
                    <?php esc_html_e( 'Subscription orders only', 'woo-order-archive-manager' ); ?>
                </span>
            </div>

            <!-- Subscription Stats -->
            <div id="woam-subscription-stats-mini" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;">
                <div style="background: #fdf0f0; padding: 8px 12px; border-radius: 4px; text-align: center;">
                    <span style="font-size: 18px; font-weight: 700; color: #d63638;" id="woam-subs-protected">0</span>
                    <span style="font-size: 11px; color: #5c0000; display: block;"><?php esc_html_e( 'Protected', 'woo-order-archive-manager' ); ?></span>
                </div>
                <div style="background: #e6f4ea; padding: 8px 12px; border-radius: 4px; text-align: center;">
                    <span style="font-size: 18px; font-weight: 700; color: #2ea64a;" id="woam-subs-cancelled">0</span>
                    <span style="font-size: 11px; color: #1a4d2a; display: block;"><?php esc_html_e( 'Cancelled', 'woo-order-archive-manager' ); ?></span>
                </div>
                <div style="background: #e6f4ea; padding: 8px 12px; border-radius: 4px; text-align: center;">
                    <span style="font-size: 18px; font-weight: 700; color: #2ea64a;" id="woam-subs-expired">0</span>
                    <span style="font-size: 11px; color: #1a4d2a; display: block;"><?php esc_html_e( 'Expired', 'woo-order-archive-manager' ); ?></span>
                </div>
                <div style="background: #e6f4ea; padding: 8px 12px; border-radius: 4px; text-align: center;">
                    <span style="font-size: 18px; font-weight: 700; color: #2ea64a;" id="woam-subs-eligible">0</span>
                    <span style="font-size: 11px; color: #1a4d2a; display: block;"><?php esc_html_e( 'Eligible to Archive', 'woo-order-archive-manager' ); ?></span>
                </div>
            </div>

            <!-- Protected vs Safe Legend -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div style="background: #fdf0f0; padding: 10px 14px; border-radius: 4px; border-left: 3px solid #d63638;">
                    <strong style="color: #5c0000; font-size: 12px;"><?php esc_html_e( 'Protected - Never Archive', 'woo-order-archive-manager' ); ?></strong>
                    <ul style="margin: 4px 0 0 16px; font-size: 12px; color: #5c0000;">
                        <li><?php esc_html_e( 'Active subscriptions', 'woo-order-archive-manager' ); ?></li>
                        <li><?php esc_html_e( 'Pending cancel', 'woo-order-archive-manager' ); ?></li>
                        <li><?php esc_html_e( 'On hold subscriptions', 'woo-order-archive-manager' ); ?></li>
                        <li><?php esc_html_e( 'Renewal orders for active subscriptions', 'woo-order-archive-manager' ); ?></li>
                    </ul>
                </div>
                <div style="background: #e6f4ea; padding: 10px 14px; border-radius: 4px; border-left: 3px solid #2ea64a;">
                    <strong style="color: #1a4d2a; font-size: 12px;"><?php esc_html_e( 'Safe to Archive', 'woo-order-archive-manager' ); ?></strong>
                    <ul style="margin: 4px 0 0 16px; font-size: 12px; color: #1a4d2a;">
                        <li><?php esc_html_e( 'Cancelled subscriptions', 'woo-order-archive-manager' ); ?></li>
                        <li><?php esc_html_e( 'Expired subscriptions', 'woo-order-archive-manager' ); ?></li>
                        <li><?php esc_html_e( 'Failed subscriptions', 'woo-order-archive-manager' ); ?></li>
                    </ul>
                </div>
            </div>

            <!-- Subscription Filters -->
            <div class="woam-field-group" style="margin-bottom: 0;">
                <label style="font-size: 13px; font-weight: 600;">
                    <?php esc_html_e( 'Subscription statuses to include', 'woo-order-archive-manager' ); ?>
                </label>
                <div class="woam-checkbox-grid" id="woam-subscription-statuses">
                    <label class="woam-checkbox" style="opacity: 0.5; cursor: not-allowed;">
                        <input type="checkbox" disabled checked>
                        <?php esc_html_e( 'Active', 'woo-order-archive-manager' ); ?>
                        <span class="woam-checkbox-count">(<?php esc_html_e( 'protected', 'woo-order-archive-manager' ); ?>)</span>
                    </label>
                    <label class="woam-checkbox" style="opacity: 0.5; cursor: not-allowed;">
                        <input type="checkbox" disabled checked>
                        <?php esc_html_e( 'Pending Cancel', 'woo-order-archive-manager' ); ?>
                        <span class="woam-checkbox-count">(<?php esc_html_e( 'protected', 'woo-order-archive-manager' ); ?>)</span>
                    </label>
                    <label class="woam-checkbox" style="opacity: 0.5; cursor: not-allowed;">
                        <input type="checkbox" disabled checked>
                        <?php esc_html_e( 'On Hold', 'woo-order-archive-manager' ); ?>
                        <span class="woam-checkbox-count">(<?php esc_html_e( 'protected', 'woo-order-archive-manager' ); ?>)</span>
                    </label>
                    <label class="woam-checkbox">
                        <input type="checkbox" name="subscription_statuses[]" value="wc-cancelled" checked>
                        <?php esc_html_e( 'Cancelled', 'woo-order-archive-manager' ); ?>
                        <span class="woam-checkbox-count">(<?php esc_html_e( 'safe', 'woo-order-archive-manager' ); ?>)</span>
                    </label>
                    <label class="woam-checkbox">
                        <input type="checkbox" name="subscription_statuses[]" value="wc-expired" checked>
                        <?php esc_html_e( 'Expired', 'woo-order-archive-manager' ); ?>
                        <span class="woam-checkbox-count">(<?php esc_html_e( 'safe', 'woo-order-archive-manager' ); ?>)</span>
                    </label>
                    <label class="woam-checkbox">
                        <input type="checkbox" name="subscription_statuses[]" value="wc-failed" checked>
                        <?php esc_html_e( 'Failed', 'woo-order-archive-manager' ); ?>
                        <span class="woam-checkbox-count">(<?php esc_html_e( 'safe', 'woo-order-archive-manager' ); ?>)</span>
                    </label>
                </div>
                <p style="font-size: 11px; color: #646970; margin-top: 8px;">
                    <span class="dashicons dashicons-shield" style="color: #7f54b3; font-size: 14px;"></span>
                    <?php esc_html_e( 'Protected subscription orders are automatically skipped. Only cancelled, expired, and failed subscriptions can be archived.', 'woo-order-archive-manager' ); ?>
                </p>
            </div>

            <div class="woam-step-actions" style="margin-top: 16px;">
                <button type="button" class="woam-button woam-button--primary" id="woam-archive-subscription-preview">
                    <span class="dashicons dashicons-chart-line"></span>
                    <?php esc_html_e( 'Preview Subscription Orders', 'woo-order-archive-manager' ); ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Select/Deselect All -->
        <div class="woam-bulk-actions" style="margin-top: 24px;">
            <button type="button" class="woam-bulk-btn" data-select-all-statuses>
                <span class="dashicons dashicons-yes-alt"></span>
                <?php esc_html_e( 'Select All', 'woo-order-archive-manager' ); ?>
            </button>
            <button type="button" class="woam-bulk-btn" data-deselect-all-statuses>
                <span class="dashicons dashicons-no-alt"></span>
                <?php esc_html_e( 'Deselect All', 'woo-order-archive-manager' ); ?>
            </button>
        </div>

        <!-- Batch Size -->
        <div class="woam-field-group" style="margin-top: 16px;">
            <label for="woam-batch-size">
                <?php esc_html_e( 'Batch Size', 'woo-order-archive-manager' ); ?>
                <span class="dashicons dashicons-info" style="font-size: 14px; color: #646970; cursor: help;" 
                      title="<?php esc_attr_e( 'Controls how many orders are processed per batch. Higher values complete faster but use more server resources.', 'woo-order-archive-manager' ); ?>">
                </span>
            </label>
            <select id="woam-batch-size" class="woam-input" style="width: auto; min-width: 120px;">
                <option value="50">50 <?php esc_html_e( 'orders', 'woo-order-archive-manager' ); ?></option>
                <option value="100">100 <?php esc_html_e( 'orders', 'woo-order-archive-manager' ); ?></option>
                <option value="250">250 <?php esc_html_e( 'orders', 'woo-order-archive-manager' ); ?></option>
                <option value="500" selected>500 <?php esc_html_e( 'orders', 'woo-order-archive-manager' ); ?></option>
                <option value="1000">1000 <?php esc_html_e( 'orders', 'woo-order-archive-manager' ); ?></option>
            </select>
            <p style="font-size: 11px; color: #646970; margin-top: 4px;">
                <?php esc_html_e( 'Recommended: Small stores: 250 | Medium: 500 | Large: 1000', 'woo-order-archive-manager' ); ?>
            </p>
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
                <?php esc_html_e(
                    'Continue',
                    'woo-order-archive-manager'
                ); ?>
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
            <div id="woam-archive-progress-text"></div>
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