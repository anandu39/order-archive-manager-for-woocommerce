<?php
/**
 * Health Score Cache Manager
 *
 * Handles caching of health score calculations with WP Cron refresh.
 *
 * @package HW\WOAM\Health
 */

namespace HW\WOAM\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class HealthScoreCache
 */
class HealthScoreCache {

	/**
	 * Cache key for health score.
	 */
	private const CACHE_KEY = 'hw_woam_health_score_cache';

	/**
	 * Cache expiration in seconds (6 hours).
	 */
	private const CACHE_TTL = 21600;

	/**
	 * Option key for last cron run.
	 */
	private const LAST_CRUN_KEY = 'hw_woam_last_health_cron';

	/**
	 * Get cached health score.
	 *
	 * @return array|null Cached data or null if not found.
	 */
	public function get_cached_score(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Cache health score data.
	 *
	 * @param array $data Health score data to cache.
	 * @return void
	 */
	public function cache_score( array $data ): void {
		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
	}

	/**
	 * Invalidate cached health score.
	 *
	 * @return void
	 */
	public function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Check if cache is stale and needs refresh.
	 *
	 * @return bool
	 */
	public function is_stale(): bool {
		$cached = get_transient( self::CACHE_KEY );
		return false === $cached;
	}

	/**
	 * Schedule WP Cron event for health score refresh.
	 *
	 * @return void
	 */
	public function schedule_cron(): void {
		if ( ! wp_next_scheduled( 'hw_woam_refresh_health_score' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'hw_woam_refresh_health_score' );
		}
	}

	/**
	 * Clear scheduled WP Cron event.
	 *
	 * @return void
	 */
	public function clear_cron(): void {
		$timestamp = wp_next_scheduled( 'hw_woam_refresh_health_score' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'hw_woam_refresh_health_score' );
		}
	}

	/**
	 * Get last cron run timestamp.
	 *
	 * @return int
	 */
	public function get_last_cron_run(): int {
		return (int) get_option( self::LAST_CRUN_KEY, 0 );
	}

	/**
	 * Update last cron run timestamp.
	 *
	 * @return void
	 */
	public function update_last_cron_run(): void {
		update_option( self::LAST_CRUN_KEY, time() );
	}
}
