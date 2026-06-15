<?php
/**
 * Overview tab - Database Health Dashboard
 * 
 * Displays health scores, recommendations, storage impact, and activity timeline.
 * Populated entirely by woam-admin.js via AJAX.
 * 
 * @package HW\WOAM\Admin
 */

defined( 'ABSPATH' ) || exit;
?>

<!-- Hero Section -->
<div class="woam-hero">
	<div class="woam-hero-text">
		<h2><?php esc_html_e( 'Optimize Your WooCommerce Database', 'woo-order-archive-manager' ); ?></h2>
		<p><?php esc_html_e( 'Archive inactive orders to reclaim storage, speed up admin panels, and keep your store running smoothly.', 'woo-order-archive-manager' ); ?></p>
		<div class="woam-hero-buttons">
			<button type="button" class="woam-button woam-button--primary" data-trigger-archive>
				<span class="dashicons dashicons-archive"></span>
				<?php esc_html_e( 'Archive Orders Now', 'woo-order-archive-manager' ); ?>
			</button>
			<button type="button" class="woam-button woam-button--secondary" data-scroll-to="recommendation">
				<span class="dashicons dashicons-lightbulb"></span>
				<?php esc_html_e( 'View Recommendations', 'woo-order-archive-manager' ); ?>
			</button>
		</div>
	</div>
</div>

<!-- Health Score & Smart Recommendation Row -->
<div class="woam-grid woam-grid--2col">
	<div class="woam-card woam-card--health">
		<h2>
			<span class="dashicons dashicons-chart-area"></span>
			<?php esc_html_e( 'Database Health Score', 'woo-order-archive-manager' ); ?>
		</h2>
		<div id="woam-health-score" class="woam-health-container woam-loading">
			<?php esc_html_e( 'Loading health score...', 'woo-order-archive-manager' ); ?>
		</div>
	</div>

	<div class="woam-card woam-card--recommendation" id="woam-recommendation-card">
		<h2>
			<span class="dashicons dashicons-star-filled"></span>
			<?php esc_html_e( 'Smart Recommendation', 'woo-order-archive-manager' ); ?>
		</h2>
		<div id="woam-recommendation" class="woam-recommendation-container woam-loading">
			<?php esc_html_e( 'Analyzing your store...', 'woo-order-archive-manager' ); ?>
		</div>
	</div>
</div>

<!-- Storage Composition & Lifetime Statistics Row -->
<div class="woam-grid woam-grid--2col">
	<div class="woam-card woam-card--storage">
		<h2>
			<span class="dashicons dashicons-database"></span>
			<?php esc_html_e( 'Storage Composition', 'woo-order-archive-manager' ); ?>
		</h2>
		<div id="woam-storage-chart" class="woam-chart-container woam-loading">
			<?php esc_html_e( 'Loading storage data...', 'woo-order-archive-manager' ); ?>
		</div>
	</div>

	<div class="woam-card woam-card--stats">
		<h2>
			<span class="dashicons dashicons-chart-line"></span>
			<?php esc_html_e( 'Lifetime Achievements', 'woo-order-archive-manager' ); ?>
		</h2>
		<div id="woam-lifetime-stats" class="woam-stats-container woam-loading">
			<?php esc_html_e( 'Loading statistics...', 'woo-order-archive-manager' ); ?>
		</div>
	</div>
</div>

<!-- Archive Readiness & Growth Forecast Row -->
<div class="woam-grid woam-grid--2col">
	<div class="woam-card woam-card--readiness">
		<h2>
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'Archive Readiness', 'woo-order-archive-manager' ); ?>
		</h2>
		<div id="woam-readiness" class="woam-readiness-container woam-loading">
			<?php esc_html_e( 'Checking system status...', 'woo-order-archive-manager' ); ?>
		</div>
	</div>

	<div class="woam-card woam-card--growth">
		<h2>
			<span class="dashicons dashicons-chart-line"></span>
			<?php esc_html_e( 'Growth Forecast', 'woo-order-archive-manager' ); ?>
		</h2>
		<div id="woam-growth-forecast" class="woam-forecast-container woam-loading">
			<?php esc_html_e( 'Calculating growth trends...', 'woo-order-archive-manager' ); ?>
		</div>
	</div>
</div>

<!-- Educational Section -->
<div class="woam-card woam-card--education">
	<h2>
		<span class="dashicons dashicons-welcome-learn-more"></span>
		<?php esc_html_e( 'Why Archive Orders?', 'woo-order-archive-manager' ); ?>
	</h2>
	<div class="woam-education-grid">
		<div class="woam-education-item">
			<span class="woam-education-icon dashicons dashicons-dashboard"></span>
			<div>
				<h4><?php esc_html_e( 'Faster Admin Panels', 'woo-order-archive-manager' ); ?></h4>
				<p><?php esc_html_e( 'Reduce wp_postmeta queries for snappier order management.', 'woo-order-archive-manager' ); ?></p>
			</div>
		</div>
		<div class="woam-education-item">
			<span class="woam-education-icon dashicons dashicons-backup"></span>
			<div>
				<h4><?php esc_html_e( 'Smaller Backups', 'woo-order-archive-manager' ); ?></h4>
				<p><?php esc_html_e( 'Exclude archived orders from daily backups to save space.', 'woo-order-archive-manager' ); ?></p>
			</div>
		</div>
		<div class="woam-education-item">
			<span class="woam-education-icon dashicons dashicons-chart-bar"></span>
			<div>
				<h4><?php esc_html_e( 'Better Analytics', 'woo-order-archive-manager' ); ?></h4>
				<p><?php esc_html_e( 'Focus reports on active orders without historical noise.', 'woo-order-archive-manager' ); ?></p>
			</div>
		</div>
		<div class="woam-education-item">
			<span class="woam-education-icon dashicons dashicons-shield"></span>
			<div>
				<h4><?php esc_html_e( 'GDPR Compliance', 'woo-order-archive-manager' ); ?></h4>
				<p><?php esc_html_e( 'Securely archive old customer data while maintaining access.', 'woo-order-archive-manager' ); ?></p>
			</div>
		</div>
	</div>
</div>

<!-- Recent Activity Timeline -->
<div class="woam-card woam-card--activity">
	<h2>
		<span class="dashicons dashicons-clock"></span>
		<?php esc_html_e( 'Recent Activity', 'woo-order-archive-manager' ); ?>
	</h2>
	<div id="woam-recent-activity" class="woam-timeline-container woam-loading">
		<?php esc_html_e( 'Loading activity...', 'woo-order-archive-manager' ); ?>
	</div>
</div>