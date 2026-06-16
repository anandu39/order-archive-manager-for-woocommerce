/**
 * Woo Order Archive Manager — Admin JS
 *
 * Drives all three tab panels via AJAX.
 * Depends on woamData (ajaxUrl, nonce, i18n) provided by wp_localize_script.
 * 
 * Phase 2: Complete Overview Tab with Dashicons + Archive/Archived Tab functionality
 */

(function() {
    'use strict';

    /**
     * Global state management
     */
    const state = {
        dirty: false,
        totalOrders: 0,
        processedOrders: 0,
    };

    /**
     * Sends a POST request to wp-admin/admin-ajax.php
     */
    async function woamPost(action, payload = {}) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', woamData.nonce);

        for (const [key, value] of Object.entries(payload)) {
            if (Array.isArray(value)) {
                value.forEach(v => body.append(key + '[]', v));
            } else {
                body.append(key, value);
            }
        }

        const response = await fetch(woamData.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body,
        });

        const json = await response.json();

        if (!json.success) {
            throw new Error(json.data?.message ?? 'Unknown error');
        }

        return json.data;
    }

    /**
     * Activates a step within a woam-steps container
     */
    function setStep(container, stepNumber) {
        container.querySelectorAll('.woam-step').forEach(step => {
            step.classList.toggle('woam-step--active', parseInt(step.dataset.step) === stepNumber);
        });

        container.querySelectorAll('.woam-step-dot').forEach(dot => {
            const n = parseInt(dot.dataset.step);
            dot.classList.toggle('woam-step-dot--active', n === stepNumber);
            dot.classList.toggle('woam-step-dot--completed', n < stepNumber);
        });
    }

    /**
     * Load opportunity banner with impactful message
     */
    async function loadOpportunityBanner() {
        const banner = document.getElementById('woam-opportunity-banner');
        const messageEl = document.getElementById('woam-opportunity-message');
        const ctaBtn = document.getElementById('woam-opportunity-cta');
        
        if (!banner) return;
        
        try {
            // Get database stats
            const dbStats = await woamPost('hw_woam_get_db_stats');
            const totalBytes = dbStats.total_bytes || 0;
            const totalFormatted = dbStats.total_formatted || '0 B';
            
            // Get order count
            const orderData = await woamPost('hw_woam_get_archive_breakdown');
            const totalArchived = orderData.total_count || 0;
            
            // Get total orders
            const totalOrdersData = await woamPost('hw_woam_get_count', {
                mode: 'archive',
                before_date: '2099-01-01',
                statuses: ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed']
            });
            const totalOrders = totalOrdersData.count || 0;
            
            // Calculate eligible orders (completed/cancelled/refunded/failed older than 12 months)
            let eligibleOrders = 0;
            if (totalOrders > 0) {
                const twelveMonthsAgo = new Date();
                twelveMonthsAgo.setMonth(twelveMonthsAgo.getMonth() - 12);
                const dateStr = twelveMonthsAgo.toISOString().split('T')[0];
                
                const eligibleData = await woamPost('hw_woam_get_count', {
                    mode: 'archive',
                    before_date: dateStr,
                    statuses: ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed']
                });
                eligibleOrders = eligibleData.count || 0;
            }
            
            // Calculate estimated savings (eligible orders * average size)
            const avgOrderSize = totalBytes > 0 && totalOrders > 0 ? totalBytes / totalOrders : 50 * 1024;
            const estimatedSavings = eligibleOrders * avgOrderSize;
            const estimatedSavingsFormatted = formatBytes(estimatedSavings);
            
            // Build message
            let message = '';
            let showCta = false;
            
            if (totalArchived === 0 && totalBytes > 100 * 1024 * 1024) {
                message = `
                    <strong>Your WooCommerce store contains ${totalFormatted} of historical order data.</strong><br>
                    Large order tables increase backup sizes, slow order searches, and add unnecessary load on reporting and administration tasks.<br>
                    Based on current data, approximately <strong>${formatNumber(eligibleOrders)}</strong> orders may be eligible for archiving.<br>
                    <span style="color: #7f54b3; font-weight: 600;">Potential storage reduction: ${estimatedSavingsFormatted}</span>
                `;
                showCta = true;
            } else if (totalArchived === 0) {
                message = `
                    <strong>Ready to optimize your WooCommerce database?</strong><br>
                    Archiving old orders can significantly improve admin performance and reduce backup sizes.
                    ${eligibleOrders > 0 ? `You have <strong>${formatNumber(eligibleOrders)}</strong> orders that may be eligible for archiving.` : ''}
                `;
                showCta = true;
            } else if (totalArchived > 0 && totalBytes > 500 * 1024 * 1024) {
                message = `
                    <strong>Great progress! You've archived ${formatNumber(totalArchived)} orders.</strong><br>
                    Your database still contains ${totalFormatted} of order data.
                    ${eligibleOrders > 0 ? `Another <strong>${formatNumber(eligibleOrders)}</strong> orders are eligible for archiving.` : ''}
                    <span style="color: #2ea64a; font-weight: 600;">Continue optimizing to reclaim more space.</span>
                `;
                showCta = true;
            } else {
                message = `
                    <strong>Your WooCommerce database is well-maintained!</strong><br>
                    Keep monitoring your database health to ensure optimal performance.
                    ${totalArchived > 0 ? `You've already archived ${formatNumber(totalArchived)} orders.` : ''}
                `;
                showCta = false;
            }
            
            messageEl.innerHTML = message;
            
            if (showCta && eligibleOrders > 0) {
                ctaBtn.style.display = 'inline-flex';
                ctaBtn.addEventListener('click', () => {
                    document.querySelector('.woam-tab[data-tab="archive"]')?.click();
                });
            } else {
                ctaBtn.style.display = 'none';
            }
            
            banner.style.display = 'block';
            
        } catch (err) {
            console.error('Failed to load opportunity banner:', err);
            messageEl.innerHTML = 'Reduce database bloat, improve admin performance, and restore archived orders anytime with one click.';
            banner.style.display = 'block';
        }
    }

    /**
     * Format bytes helper
     */
    function formatBytes(bytes) {
        if (bytes >= 1073741824) {
            return (bytes / 1073741824).toFixed(1) + ' GB';
        }
        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        if (bytes >= 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return bytes + ' B';
    }

    /**
     * HTML escaping function
     */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Formats numbers with thousands separators
     */
    function formatNumber(n) {
        return parseInt(n).toLocaleString();
    }

    /**
     * Shows error message in container
     */
    function showError(el, message) {
        el.classList.remove('woam-loading');
        el.innerHTML = `<div class="woam-empty-state">
            <div class="woam-empty-state-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <p class="woam-error">${escHtml(message)}</p>
        </div>`;
    }

    /**
     * Gets color for health score
     */
    function getScoreColor(score) {
        if (score >= 80) return '#2ea64a';
        if (score >= 60) return '#7f54b3';
        if (score >= 40) return '#dba617';
        return '#d63638';
    }

    /**
     * Renders health factors
     */
    function renderHealthFactors(factors) {
        let html = '<div class="woam-health-factors">';

        for (const [key, factor] of Object.entries(factors)) {
            const fillColor = getScoreColor(factor.score);
            html += `
                <div class="woam-factor">
                    <span class="woam-factor-name">${escHtml(factor.label || key.replace('_', ' ').toUpperCase())}</span>
                    <div class="woam-factor-bar">
                        <div class="woam-factor-bar-fill" style="width: ${factor.score}%; background: ${fillColor};"></div>
                    </div>
                    <span class="woam-factor-score">${factor.score}%</span>
                </div>
                <div class="woam-factor-message">${escHtml(factor.message)}</div>
            `;
        }

        html += '</div>';
        return html;
    }

    /**
     * ============================================================
     * OVERVIEW TAB FUNCTIONS (Phase 2)
     * ============================================================
     */

    /**
     * Loads health score with circular gauge
     */
    async function loadHealthScore() {
        const container = document.getElementById('woam-health-score');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_health_score');
            const circumference = 2 * Math.PI * 85;
            const offset = circumference - (data.score / 100) * circumference;
            const fillColor = getScoreColor(data.score);

            const html = `
                <div class="woam-circular-gauge">
                    <svg viewBox="0 0 200 200">
                        <circle class="woam-gauge-bg" cx="100" cy="100" r="85"></circle>
                        <circle class="woam-gauge-fill" cx="100" cy="100" r="85"
                            stroke="${fillColor}"
                            stroke-dasharray="${circumference}"
                            stroke-dashoffset="${offset}">
                        </circle>
                    </svg>
                    <div class="woam-gauge-text">
                        <div class="woam-gauge-score">${data.score}</div>
                        <div class="woam-gauge-label">${escHtml(data.status_label)}</div>
                    </div>
                </div>
                ${renderHealthFactors(data.factors)}
            `;

            container.classList.remove('woam-loading');
            container.innerHTML = html;

        } catch (err) {
            showError(container, err.message);
        }
    }

    /**
     * Loads smart recommendations with one-click application
     */
    async function loadRecommendations() {
        const container = document.getElementById('woam-recommendation');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_recommendations');

            if (!data.has_recommendation) {
                container.innerHTML = `
                    <div class="woam-empty-state">
                        <div class="woam-empty-state-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <h3>${escHtml(woamData.i18n.noRecommendations || 'Your archive is in great shape!')}</h3>
                        <p>${escHtml(data.reason)}</p>
                    </div>
                `;
                container.classList.remove('woam-loading');
                return;
            }

            const confidenceClass = `woam-confidence-${data.confidence}`;

            const html = `
                <div class="woam-recommendation-highlight">
                    <span class="woam-confidence-badge ${confidenceClass}">
                        <span class="dashicons dashicons-info-outline"></span>
                        ${escHtml(data.confidence_label)}
                    </span>
                    <h3>${escHtml(data.title || 'Archive orders before ' + data.recommended_date_formatted)}</h3>
                    <p>${escHtml(data.reason)}</p>
                    <div class="woam-recommendation-savings">
                        <span class="dashicons dashicons-chart-line"></span>
                        Save ~${escHtml(data.estimated_savings_formatted)}
                    </div>
                    <div class="woam-recommendation-actions">
                        <button type="button" class="woam-button woam-button--primary" data-use-recommendation>
                            <span class="dashicons dashicons-archive"></span>
                            Apply Recommendation
                        </button>
                        <button type="button" class="woam-button woam-button--secondary" data-dismiss-recommendation>
                            <span class="dashicons dashicons-no-alt"></span>
                            Dismiss
                        </button>
                    </div>
                </div>
            `;

            container.classList.remove('woam-loading');
            container.innerHTML = html;

            // Apply recommendation button
            const applyBtn = container.querySelector('[data-use-recommendation]');
            if (applyBtn) {
                applyBtn.addEventListener('click', () => {
                    applyRecommendation(data);
                });
            }

            // Dismiss recommendation button
            const dismissBtn = container.querySelector('[data-dismiss-recommendation]');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', async () => {
                    await dismissRecommendation(data.id);
                    container.innerHTML = `
                        <div class="woam-empty-state">
                            <div class="woam-empty-state-icon">
                                <span class="dashicons dashicons-info"></span>
                            </div>
                            <p>Recommendation dismissed. We'll check back later.</p>
                        </div>
                    `;
                });
            }

        } catch (err) {
            showError(container, err.message);
        }
    }

    /**
     * Apply a recommendation by pre-filling archive tab
     *
     * @param {Object} recommendation Recommendation data
     */
    function applyRecommendation(recommendation) {
        // Store recommendation in sessionStorage for archive tab
        sessionStorage.setItem('woam_recommendation', JSON.stringify({
            date: recommendation.recommended_date,
            statuses: recommendation.recommended_statuses || ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed']
        }));
        
        // Also store that this was from a recommendation
        sessionStorage.setItem('woam_recommendation_applied', 'true');
        
        // Switch to archive tab
        const archiveTab = document.querySelector('.woam-tab[data-tab="archive"]');
        if (archiveTab) {
            archiveTab.click();
            
            // Show a success message
            setTimeout(() => {
                showNotification('Recommendation applied! Review the filters and click "Start Archive".', 'success');
            }, 500);
        }
    }

    /**
     * Dismiss a recommendation
     *
     * @param {string} recommendationId Recommendation ID
     */
    async function dismissRecommendation(recommendationId) {
        try {
            await woamPost('hw_woam_dismiss_recommendation', {
                recommendation_id: recommendationId
            });
        } catch (err) {
            console.error('Failed to dismiss recommendation:', err);
        }
    }

    /**
     * Show notification message
     *
     * @param {string} message Message to display
     * @param {string} type Type: 'success', 'warning', 'error', 'info'
     */
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `woam-notification woam-notification--${type}`;
        
        let icon = 'info';
        if (type === 'success') icon = 'yes-alt';
        if (type === 'warning') icon = 'warning';
        if (type === 'error') icon = 'warning';
        
        notification.innerHTML = `
            <span class="dashicons dashicons-${icon}"></span>
            <span>${escHtml(message)}</span>
            <button class="woam-notification-close">&times;</button>
        `;
        
        document.querySelector('.woam-wrap').prepend(notification);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            notification.classList.add('woam-notification--fadeout');
            setTimeout(() => notification.remove(), 300);
        }, 5000);
        
        // Close button
        notification.querySelector('.woam-notification-close')?.addEventListener('click', () => {
            notification.remove();
        });
    }

    /**
     * Loads lifetime statistics
     */
    async function loadLifetimeStats() {
        const container = document.getElementById('woam-lifetime-stats');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_lifetime_stats');

            const html = `
                <div class="woam-stat-card">
                    <div class="woam-stat-number">${formatNumber(data.total_archived_orders)}</div>
                    <div class="woam-stat-label">Orders Archived</div>
                </div>
                <div class="woam-stat-card">
                    <div class="woam-stat-number">${escHtml(data.total_saved_formatted)}</div>
                    <div class="woam-stat-label">Storage Saved</div>
                </div>
                <div class="woam-stat-card">
                    <div class="woam-stat-number">${data.restore_success_rate}%</div>
                    <div class="woam-stat-label">Restore Success Rate</div>
                </div>
                <div class="woam-stat-card">
                    <div class="woam-stat-number">${escHtml(data.archived_revenue_formatted)}</div>
                    <div class="woam-stat-label">Revenue in Archive</div>
                </div>
                <div class="woam-stat-card">
                    <div class="woam-stat-number">${formatNumber(data.archive_run_count)}</div>
                    <div class="woam-stat-label">Archive Jobs</div>
                </div>
                <div class="woam-stat-card">
                    <div class="woam-stat-number">${formatNumber(data.total_operations)}</div>
                    <div class="woam-stat-label">Total Operations</div>
                </div>
            `;

            container.classList.remove('woam-loading');
            container.innerHTML = html;

        } catch (err) {
            showError(container, err.message);
        }
    }

    /**
     * Loads subscription statistics for the Overview tab
     */
    async function loadSubscriptionStats() {
        const container = document.getElementById('woam-subscription-stats');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_subscription_stats');
            
            if (!data.subscriptions_active) {
                container.innerHTML = `
                    <div class="woam-empty-state" style="padding: 20px;">
                        <span class="dashicons dashicons-cart" style="font-size: 32px; color: #646970;"></span>
                        <p style="margin-top: 8px; font-size: 13px; color: #646970;">WooCommerce Subscriptions not active</p>
                    </div>
                `;
                container.classList.remove('woam-loading');
                return;
            }
            
            const html = `
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: #7f54b3;">${formatNumber(data.total_subscriptions)}</div>
                        <div style="font-size: 11px; color: #646970;">Total Subscriptions</div>
                    </div>
                    <div style="background: #e6f4ea; padding: 12px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: #2ea64a;">${formatNumber(data.active)}</div>
                        <div style="font-size: 11px; color: #1a4d2a;">Active</div>
                    </div>
                    <div style="background: #fef9e7; padding: 12px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: #dba617;">${formatNumber(data.on_hold + data.pending_cancel)}</div>
                        <div style="font-size: 11px; color: #5c4000;">On Hold / Pending</div>
                    </div>
                    <div style="background: #f0f0f1; padding: 12px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 20px; font-weight: 700; color: #646970;">${formatNumber(data.archivable_orders)}</div>
                        <div style="font-size: 11px; color: #646970;">Safe to Archive</div>
                    </div>
                </div>
                <div style="margin-top: 12px; padding: 8px 12px; background: #f8f9fa; border-radius: 4px; font-size: 12px; color: #646970; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-shield" style="color: #d63638;"></span>
                    <span><strong>${formatNumber(data.protected_orders)}</strong> orders are protected from archiving (active subscriptions)</span>
                </div>
            `;
            
            container.classList.remove('woam-loading');
            container.innerHTML = html;
            
        } catch (err) {
            container.innerHTML = `<p class="woam-error">Failed to load subscription data</p>`;
            container.classList.remove('woam-loading');
        }
    }

    /**
     * Loads archive readiness status
     */
    async function loadReadinessStatus() {
        const container = document.getElementById('woam-readiness');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_archive_readiness');

            let html = '';
            for (const check of data.checks) {
                const icon = check.passed ? 'yes-alt' : 'warning';
                const iconClass = check.passed ? 'woam-readiness-icon--ok' : 'woam-readiness-icon--warn';

                html += `
                    <div class="woam-readiness-item">
                        <div class="woam-readiness-icon ${iconClass}">
                            <span class="dashicons dashicons-${icon}"></span>
                        </div>
                        <div class="woam-readiness-text">${escHtml(check.label)}</div>
                        <div class="woam-readiness-status">${escHtml(check.message)}</div>
                    </div>
                `;
            }

            if (data.all_passed) {
                html += `<div class="woam-readiness-item">
                    <div class="woam-readiness-icon woam-readiness-icon--ok">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <div class="woam-readiness-text"><strong>${escHtml(data.summary)}</strong></div>
                </div>`;
            } else {
                html += `<div class="woam-readiness-item">
                    <div class="woam-readiness-icon woam-readiness-icon--warn">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="woam-readiness-text"><strong>${escHtml(data.summary)}</strong></div>
                </div>`;
            }

            container.classList.remove('woam-loading');
            container.innerHTML = html;

        } catch (err) {
            showError(container, err.message);
        }
    }

    /**
     * Loads growth forecast with chart
     */
    async function loadGrowthForecast() {
        const container = document.getElementById('woam-growth-forecast');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_growth_forecast');
            
            // Build historical chart bars
            let historyHtml = '';
            if (data.historical_data && data.historical_data.length > 0) {
                const maxSize = Math.max(...data.historical_data.map(d => d.size_mb), data.monthly_growth_rate_mb * 12);
                
                historyHtml = '<div class="woam-history-chart"><div class="woam-chart-bars">';
                
                data.historical_data.slice(-6).forEach(point => {
                    const height = maxSize > 0 ? (point.size_mb / maxSize) * 60 : 0;
                    historyHtml += `
                        <div class="woam-chart-bar" style="height: ${height}px">
                            <span class="woam-chart-value">${point.size_mb} MB</span>
                        </div>
                    `;
                });
                
                historyHtml += '</div><div class="woam-chart-labels">';
                data.historical_data.slice(-6).forEach(point => {
                    historyHtml += `<span>${point.date.substring(5)}</span>`;
                });
                historyHtml += '</div></div>';
            }
            
            const html = `
                <div class="woam-forecast-current">
                    <div class="woam-forecast-number">${escHtml(data.current_size_formatted)}</div>
                    <div class="woam-forecast-label">Current Database Size</div>
                </div>
                <div class="woam-forecast-trend">
                    <span class="dashicons dashicons-chart-line"></span>
                    <span>Growing at ${data.monthly_growth_rate_mb} MB per month</span>
                </div>
                ${historyHtml}
                <div class="woam-forecast-projections">
                    <div class="woam-projection">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <span>In 6 months:</span>
                        <strong>${escHtml(data.projected_6_months_formatted)}</strong>
                    </div>
                    <div class="woam-projection">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <span>In 12 months:</span>
                        <strong>${escHtml(data.projected_12_months_formatted)}</strong>
                    </div>
                </div>
                <div class="woam-forecast-action">
                    <button type="button" class="woam-button woam-button--secondary" data-archive-suggested>
                        <span class="dashicons dashicons-archive"></span>
                        Archive old orders to reduce growth
                    </button>
                </div>
            `;

            container.classList.remove('woam-loading');
            container.innerHTML = html;
            
            // Attach event listener to the archive button
            const archiveBtn = container.querySelector('[data-archive-suggested]');
            if (archiveBtn) {
                archiveBtn.addEventListener('click', () => {
                    document.querySelector('.woam-tab[data-tab="archive"]').click();
                });
            }

        } catch (err) {
            // Fallback to simple display if API fails
            container.classList.remove('woam-loading');
            container.innerHTML = `
                <div class="woam-forecast-current">
                    <div class="woam-forecast-number">Loading...</div>
                    <div class="woam-forecast-label">Current Database Size</div>
                </div>
                <div class="woam-forecast-action">
                    <p>Archive old orders now to reduce database growth.</p>
                </div>
            `;
        }
    }

    /**
     * Loads storage composition chart (replaces loadDbStats)
     */
    async function loadStorageChart() {
        const container = document.getElementById('woam-storage-chart');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_db_stats');

            const items = [];
            let total = data.total_bytes;

            for (const [key, table] of Object.entries(data.tables)) {
                if (table.bytes > 0) {
                    const percentage = total > 0 ? Math.round((table.bytes / total) * 100) : 0;
                    items.push({
                        label: key,
                        formatted: table.formatted,
                        percentage: percentage,
                        bytes: table.bytes
                    });
                }
            }

            items.sort((a, b) => b.bytes - a.bytes);

            let html = '<div class="woam-db-bars">';

            for (const item of items) {
                html += `
                    <div class="woam-bar-row">
                        <span class="woam-bar-label">${escHtml(item.label)}</span>
                        <div class="woam-bar-track">
                            <div class="woam-bar-fill" style="width: ${item.percentage}%"></div>
                        </div>
                        <span class="woam-bar-value">${escHtml(item.formatted)} (${item.percentage}%)</span>
                    </div>
                `;
            }

            html += `<p class="woam-db-total">
                        <strong>Total: ${escHtml(data.total_formatted)}</strong>
                    </p>`;
            html += '</div>';

            container.classList.remove('woam-loading');
            container.innerHTML = html;

        } catch (err) {
            showError(container, err.message);
        }
    }

    /**
     * Loads recent activity (enhanced with Dashicons)
     */
    async function loadRecentActivity() {
        const container = document.getElementById('woam-recent-activity');
        if (!container) return;

        try {
            const data = await woamPost('hw_woam_get_recent_activity');

            if (!data.activity.length) {
                container.classList.remove('woam-loading');
                container.innerHTML = `
                    <div class="woam-empty-state">
                        <div class="woam-empty-state-icon">
                            <span class="dashicons dashicons-calendar-alt"></span>
                        </div>
                        <p>No activity yet. Start by archiving some orders!</p>
                    </div>
                `;
                return;
            }

            const actionLabels = {
                archive: 'Archived',
                restore: 'Restored',
                delete: 'Deleted',
            };

            let html = '<div class="woam-timeline">';
            data.activity.forEach(entry => {
                const label = actionLabels[entry.action] ?? entry.action;
                html += `
                    <div class="woam-timeline-item woam-timeline-item--${escHtml(entry.action)}">
                        <span class="woam-timeline-date">${escHtml(entry.date_formatted)}</span>
                        <span class="woam-timeline-text">
                            ${escHtml(label)} ${formatNumber(entry.order_count)} orders
                        </span>
                    </div>`;
            });
            html += '</div>';

            container.classList.remove('woam-loading');
            container.innerHTML = html;

        } catch (err) {
            showError(container, err.message);
        }
    }

    /**
     * Loads all Overview tab components
     */
    async function loadOverviewTab() {
        await Promise.allSettled([
            loadOpportunityBanner(),
            loadHealthScore(),
            loadRecommendations(),
            loadLifetimeStats(),
            loadReadinessStatus(),
            loadGrowthForecast(),
            loadRecentActivity(),
            loadStorageChart(),
            loadSubscriptionStats(),
        ]);
    }

    /**
     * ============================================================
     * ARCHIVE TAB FUNCTIONS (Original)
     * ============================================================
     */

    /**
     * ============================================================
     * REAL-TIME SAVINGS ESTIMATION
     * ============================================================
     */

    /**
     * Debounce utility to prevent too many API calls
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Fetches real-time savings estimate based on current filters
     */
    async function fetchRealTimeEstimate() {
        const beforeDate = document.getElementById('woam-before-date').value;
        const statuses = Array.from(
            document.querySelectorAll('#woam-archive-statuses input:checked')
        ).map(cb => cb.value);

        // Don't show estimate if no date or no statuses selected
        if (!beforeDate || statuses.length === 0) {
            const estimateContainer = document.getElementById('woam-real-time-estimate');
            if (estimateContainer) {
                estimateContainer.style.display = 'none';
            }
            return;
        }

        const estimateContainer = document.getElementById('woam-real-time-estimate');
        const loadingEl = document.getElementById('woam-estimate-loading');
        const contentEl = document.querySelector('.woam-estimate-content');
        const footerEl = document.getElementById('woam-estimate-detail');

        // Show estimate container with loading state
        estimateContainer.style.display = 'block';
        loadingEl.style.display = 'flex';
        contentEl.style.opacity = '0.5';

        try {
            const data = await woamPost('hw_woam_get_savings_estimate', {
                before_date: beforeDate,
                statuses,
            });

            // Update UI with real data
            document.getElementById('woam-estimate-order-count').textContent = formatNumber(data.order_count);
            document.getElementById('woam-estimate-space-saved').textContent = data.estimated_size;

            if (data.order_count > 0) {
                footerEl.style.display = 'flex';
                document.getElementById('woam-estimate-breakdown').innerHTML = 
                    `${formatNumber(data.row_counts.order_meta)} meta rows, ${formatNumber(data.row_counts.order_items)} items, ${formatNumber(data.row_counts.order_notes)} notes`;
            } else {
                footerEl.style.display = 'none';
            }

        } catch (err) {
            console.error('Failed to fetch estimate:', err);
            document.getElementById('woam-estimate-order-count').textContent = '0';
            document.getElementById('woam-estimate-space-saved').textContent = '0 MB';
            document.getElementById('woam-estimate-detail').style.display = 'none';
        } finally {
            loadingEl.style.display = 'none';
            contentEl.style.opacity = '1';
        }
    }

    // Create debounced version for real-time updates
    const debouncedFetchEstimate = debounce(fetchRealTimeEstimate, 500);

    /**
     * Sets up real-time estimate listeners on filter changes
     */
    function initRealTimeEstimate() {
        const beforeDateInput = document.getElementById('woam-before-date');
        const statusCheckboxes = document.querySelectorAll('#woam-archive-statuses input');

        if (!beforeDateInput) return;

        // Listen to date changes
        beforeDateInput.addEventListener('change', () => {
            debouncedFetchEstimate();
        });

        // Listen to status checkbox changes
        statusCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                debouncedFetchEstimate();
            });
        });

        // Also trigger on preset button clicks (handled in existing code, but we need to also trigger estimate)
        // We'll add a mutation observer or enhance the preset button handler
    }

    /**
     * Enhanced preset button handler with estimate refresh
     */
    function enhancePresetButtons() {
        const container = document.querySelector('.woam-steps[data-mode="archive"]');
        if (!container) return;

        container.querySelectorAll('.woam-preset-btn').forEach(btn => {
            // Remove existing listeners to avoid duplicates
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            
            newBtn.addEventListener('click', () => {
                const months = parseInt(newBtn.dataset.month);
                const d = new Date();
                d.setMonth(d.getMonth() - months);

                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');

                const dateInput = document.getElementById('woam-before-date');
                dateInput.value = `${yyyy}-${mm}-${dd}`;

                container.querySelectorAll('.woam-preset-btn').forEach(b => b.classList.remove('woam-preset-btn--active'));
                newBtn.classList.add('woam-preset-btn--active');

                // Trigger real-time estimate
                debouncedFetchEstimate();
            });
        });
    }

    /**
     * Bulk select/deselect functionality
     */
    function initBulkSelectors() {
        const selectAllBtn = document.querySelector('[data-select-all-statuses]');
        const deselectAllBtn = document.querySelector('[data-deselect-all-statuses]');

        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#woam-archive-statuses input');
                checkboxes.forEach(cb => {
                    cb.checked = true;
                });
                debouncedFetchEstimate();
            });
        }

        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#woam-archive-statuses input');
                checkboxes.forEach(cb => {
                    cb.checked = false;
                });
                debouncedFetchEstimate();
            });
        }
    }

    /**
     * Apply recommendation from Overview tab (if exists)
     */
    function applyRecommendationFromStorage() {
        const savedRec = sessionStorage.getItem('woam_recommendation');
        if (savedRec) {
            try {
                const rec = JSON.parse(savedRec);
                const dateInput = document.getElementById('woam-before-date');
                if (dateInput && rec.date) {
                    dateInput.value = rec.date;
                }
                
                if (rec.statuses && rec.statuses.length) {
                    const checkboxes = document.querySelectorAll('#woam-archive-statuses input');
                    checkboxes.forEach(cb => {
                        cb.checked = rec.statuses.includes(cb.value);
                    });
                }
                
                // Clear after applying
                sessionStorage.removeItem('woam_recommendation');
                
                // Trigger estimate
                setTimeout(() => {
                    debouncedFetchEstimate();
                }, 100);
            } catch (e) {
                console.error('Failed to apply recommendation:', e);
            }
        }
    }

    /**
     * Load order breakdown by period
     */
    async function loadOrderBreakdownByPeriod() {
        const container = document.getElementById('woam-order-breakdown');
        const totalContainer = document.getElementById('woam-order-total');
        const analysisContainer = document.getElementById('woam-order-analysis');
        
        const beforeDate = document.getElementById('woam-before-date').value;
        if (!beforeDate) {
            analysisContainer.style.display = 'none';
            return;
        }
        
        analysisContainer.style.display = 'block';
        container.innerHTML = '<span class="woam-loading">Loading...</span>';
        
        try {
            const period = calculatePeriodFromDate(beforeDate);
            const data = await woamPost('hw_woam_get_order_breakdown_by_period', { period: period });
            
            let html = '';
            const statusColors = {
                'completed': { bg: '#e6f4ea', border: '#2ea64a' },
                'processing': { bg: '#e6f0fa', border: '#2271b1' },
                'on-hold': { bg: '#fef9e7', border: '#dba617' },
                'cancelled': { bg: '#fdf0f0', border: '#d63638' },
                'refunded': { bg: '#fef9e7', border: '#dba617' },
                'failed': { bg: '#fdf0f0', border: '#d63638' },
                'pending': { bg: '#f8f4ff', border: '#7f54b3' },
            };
            
            for (const [status, count] of Object.entries(data.breakdown)) {
                const color = statusColors[status] || { bg: '#f0f0f1', border: '#646970' };
                html += `
                    <div style="background: ${color.bg}; padding: 6px 12px; border-radius: 4px; font-size: 12px; border-left: 3px solid ${color.border};">
                        <strong>${formatNumber(count)}</strong>
                        <span style="color: #646970;">${status.replace('wc-', '').replace('-', ' ')}</span>
                    </div>
                `;
            }
            
            container.innerHTML = html;
            totalContainer.innerHTML = `<strong>Total eligible orders: ${formatNumber(data.total)}</strong>`;
            
        } catch (err) {
            container.innerHTML = `<p class="woam-error">${escHtml(err.message)}</p>`;
        }
    }

    /**
     * Calculate period from date
     */
    function calculatePeriodFromDate(date) {
        const selected = new Date(date);
        const now = new Date();
        const diffMonths = (now.getFullYear() - selected.getFullYear()) * 12 + (now.getMonth() - selected.getMonth());
        
        if (diffMonths <= 3) return '3 months';
        if (diffMonths <= 6) return '6 months';
        if (diffMonths <= 12) return '12 months';
        if (diffMonths <= 24) return '24 months';
        return '36 months';
    }

    /**
     * Load subscription stats for archive tab
     */
    async function loadSubscriptionStatsMini() {
        if (!document.getElementById('woam-subscription-stats-mini')) return;
        
        try {
            const data = await woamPost('hw_woam_get_subscription_analysis');
            
            document.getElementById('woam-subs-protected').textContent = formatNumber(data.protected || 0);
            document.getElementById('woam-subs-cancelled').textContent = formatNumber(data.cancelled || 0);
            document.getElementById('woam-subs-expired').textContent = formatNumber(data.expired || 0);
            document.getElementById('woam-subs-eligible').textContent = formatNumber(data.eligible || 0);
            
        } catch (err) {
            console.error('Failed to load subscription stats:', err);
        }
    }

    /**
     * Enhanced batch loop with progress
     */
    async function runBatchLoopEnhanced(opts) {
        const { action, payload, total, progressEl, fillEl, textEl, summaryEl, startBtn, confirmEl } = opts;

        let processed = 0;
        let succeeded = 0;
        let failed = 0;
        let batchCount = 0;
        const startTime = Date.now();

        startBtn.disabled = true;
        progressEl.style.display = 'block';
        summaryEl.innerHTML = '';
        
        if (confirmEl) {
            confirmEl.style.display = 'none';
        }

        const batchSize = parseInt(document.getElementById('woam-batch-size')?.value || '500');
        payload.batch_size = batchSize;

        try {
            while (true) {
                batchCount++;
                const data = await woamPost(action, payload);

                processed += data.processed;
                succeeded += data.succeeded;
                failed += data.failed;

                const pct = total > 0 ? Math.min(Math.round((processed / total) * 100), 100) : 100;
                fillEl.style.width = pct + '%';
                
                const elapsed = Math.round((Date.now() - startTime) / 1000);
                const minutes = Math.floor(elapsed / 60);
                const seconds = elapsed % 60;
                const timeStr = minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
                
                let etaStr = '--';
                if (processed > 0 && processed < total) {
                    const avgTimePerOrder = elapsed / processed;
                    const remaining = total - processed;
                    const etaSeconds = Math.round(avgTimePerOrder * remaining);
                    const etaMinutes = Math.floor(etaSeconds / 60);
                    const etaSecondsRemain = etaSeconds % 60;
                    etaStr = etaMinutes > 0 ? `${etaMinutes}m ${etaSecondsRemain}s` : `${etaSecondsRemain}s`;
                }
                
                const totalBatches = Math.ceil(total / batchSize);
                
                textEl.innerHTML = `
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <div><strong>Batch ${batchCount} of ${totalBatches}</strong></div>
                        <div>${formatNumber(processed)} / ${formatNumber(total)} orders processed</div>
                        <div style="font-size: 11px; color: #646970;">
                            ${pct}% complete · Elapsed: ${timeStr} · ETA: ${etaStr}
                        </div>
                    </div>
                `;

                if (data.processed === 0) {
                    break;
                }
            }

            const elapsedFinal = Math.round((Date.now() - startTime) / 1000);
            const minutesFinal = Math.floor(elapsedFinal / 60);
            const secondsFinal = elapsedFinal % 60;
            const finalTime = minutesFinal > 0 ? `${minutesFinal}m ${secondsFinal}s` : `${secondsFinal}s`;

            const dryNote = payload.dry_run ? ' <em>(dry run — no changes made)</em>' : '';
            summaryEl.innerHTML = `
                <div class="woam-summary woam-summary--${failed > 0 ? 'warn' : 'ok'}">
                    <div>
                        <p><strong>${formatNumber(succeeded)}</strong> succeeded &nbsp;
                        <strong>${formatNumber(failed)}</strong> failed${dryNote}</p>
                        <p style="font-size: 12px; color: #646970; margin-top: 4px;">
                            Completed in ${finalTime} · ${formatNumber(processed)} orders processed
                        </p>
                    </div>
                </div>`;

            state.dirty = true;

        } catch (err) {
            summaryEl.innerHTML = `<div class="woam-summary woam-summary--error">
                <p>${escHtml(err.message)}</p>
            </div>`;
        } finally {
            startBtn.disabled = false;
        }
    }

    /**
     * Update start archive handler to use enhanced batch loop
     */
    function initArchiveTabEnhanced() {
        // ... existing initArchiveTab code ...
        
        // Replace the start button handler with enhanced version
        document.getElementById('woam-archive-start').addEventListener('click', async () => {
            const dryRun = document.getElementById('woam-archive-dry-run').checked;
            const confirmVal = document.getElementById('woam-archive-confirm').value.trim();
            const confirmEl = document.getElementById('woam-archive-confirm-group');

            if (!dryRun && confirmVal !== 'ARCHIVE') {
                alert('Please type ARCHIVE to confirm.');
                return;
            }

            const beforeDate = document.getElementById('woam-before-date').value;
            const statuses = Array.from(
                document.querySelectorAll('#woam-archive-statuses input:checked')
            ).map(cb => cb.value);

            await runBatchLoopEnhanced({
                action: 'hw_woam_archive_batch',
                payload: { 
                    before_date: beforeDate, 
                    statuses, 
                    dry_run: dryRun ? '1' : ''
                },
                total: state.totalOrders,
                progressEl: document.getElementById('woam-archive-progress'),
                fillEl: document.getElementById('woam-archive-progress-fill'),
                textEl: document.getElementById('woam-archive-progress-text'),
                summaryEl: document.getElementById('woam-archive-summary'),
                startBtn: document.getElementById('woam-archive-start'),
                confirmEl: confirmEl,
            });
        });
    }

    /**
     * Wires up all interactivity for Tab 2 — Archive Orders
     * Phase 3: Added real-time estimation and bulk selectors
     */
    function initArchiveTab() {
        const container = document.querySelector('.woam-steps[data-mode="archive"]');
        if (!container) return;

        // Phase 3: Enhanced preset buttons with real-time estimate
        enhancePresetButtons();

        // Phase 3: Initialize real-time estimate listeners
        initRealTimeEstimate();

        // Phase 3: Initialize bulk selectors
        initBulkSelectors();

        // Phase 3: Apply recommendation from Overview tab
        applyRecommendationFromStorage();

        // Step 1 → Step 2: load savings estimate (full detailed view)
        document.getElementById('woam-archive-step1-next').addEventListener('click', async () => {
            const beforeDate = document.getElementById('woam-before-date').value;
            const statuses = Array.from(
                container.querySelectorAll('#woam-archive-statuses input:checked')
            ).map(cb => cb.value);

            if (!beforeDate) {
                alert('Please select a date before continuing.');
                return;
            }

            if (!statuses.length) {
                alert('Please select at least one order status.');
                return;
            }

            const impactEl = document.getElementById('woam-archive-impact');
            impactEl.classList.add('woam-loading');
            impactEl.innerHTML = 'Calculating…';

            setStep(container, 2);

            try {
                const data = await woamPost('hw_woam_get_savings_estimate', {
                    before_date: beforeDate,
                    statuses,
                });

                if (data.order_count === 0) {
                    impactEl.classList.remove('woam-loading');
                    impactEl.innerHTML = '<p>No orders match the selected filters.</p>';
                    return;
                }

                // Cache total for progress bar.
                state.totalOrders = data.order_count;

                impactEl.classList.remove('woam-loading');
                impactEl.innerHTML = `
                    <div class="woam-impact-table">
                        <div class="woam-impact-row">
                            <span>Orders</span>
                            <strong>${formatNumber(data.order_count)}</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order meta rows</span>
                            <strong>${formatNumber(data.row_counts.order_meta)}</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order item rows</span>
                            <strong>${formatNumber(data.row_counts.order_items)}</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order item meta rows</span>
                            <strong>${formatNumber(data.row_counts.item_meta)}</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order note rows</span>
                            <strong>${formatNumber(data.row_counts.order_notes)}</strong>
                        </div>
                        <div class="woam-impact-row woam-impact-row--total">
                            <span>Estimated space freed</span>
                            <strong>${escHtml(data.estimated_size)} <em>(approximate)</em></strong>
                        </div>
                    </div>`;

            } catch (err) {
                showError(impactEl, err.message);
            }
        });

        // Step 2 → Step 3
        document.getElementById('woam-archive-step2-next').addEventListener('click', () => {
            setStep(container, 3);
        });

        // Back buttons
        container.querySelectorAll('[data-step-back]').forEach(btn => {
            btn.addEventListener('click', () => {
                setStep(container, parseInt(btn.dataset.stepBack));
            });
        });

        // Start Archive button
        document.getElementById('woam-archive-start').addEventListener('click', async () => {
            const dryRun = document.getElementById('woam-archive-dry-run').checked;
            const confirmVal = document.getElementById('woam-archive-confirm').value.trim();

            // Confirmation gate — skip for dry runs.
            if (!dryRun && confirmVal !== 'ARCHIVE') {
                alert('Please type ARCHIVE to confirm.');
                return;
            }

            const beforeDate = document.getElementById('woam-before-date').value;
            const statuses = Array.from(
                container.querySelectorAll('#woam-archive-statuses input:checked')
            ).map(cb => cb.value);

            await runBatchLoop({
                action: 'hw_woam_archive_batch',
                payload: { before_date: beforeDate, statuses, dry_run: dryRun ? '1' : '' },
                total: state.totalOrders,
                progressEl: document.getElementById('woam-archive-progress'),
                fillEl: document.getElementById('woam-archive-progress-fill'),
                textEl: document.getElementById('woam-archive-progress-text'),
                summaryEl: document.getElementById('woam-archive-summary'),
                startBtn: document.getElementById('woam-archive-start'),
            });
        });
    }

    /**
     * ============================================================
     * ARCHIVED TAB FUNCTIONS
     * ============================================================
     */

    /**
     * Loads vault statistics for the Archive Vault banner
     */
    async function loadVaultStatistics() {
        try {
            const data = await woamPost('hw_woam_get_lifetime_stats');
            
            const totalOrdersEl = document.getElementById('woam-vault-total-orders');
            const totalSavedEl = document.getElementById('woam-vault-total-saved');
            const totalRevenueEl = document.getElementById('woam-vault-total-revenue');
            const lastArchiveEl = document.getElementById('woam-vault-last-archive');
            
            if (totalOrdersEl) {
                totalOrdersEl.textContent = formatNumber(data.total_archived_orders);
            }
            
            if (totalSavedEl) {
                totalSavedEl.textContent = data.total_saved_formatted;
            }
            
            if (totalRevenueEl) {
                totalRevenueEl.textContent = data.archived_revenue_formatted;
            }
            
            // Get last archive date from recent activity
            if (lastArchiveEl) {
                const activityData = await woamPost('hw_woam_get_recent_activity');
                const lastArchive = activityData.activity.find(a => a.action === 'archive');
                if (lastArchive) {
                    lastArchiveEl.textContent = lastArchive.date_formatted;
                } else {
                    lastArchiveEl.textContent = 'Never';
                }
            }
            
        } catch (err) {
            console.error('Failed to load vault statistics:', err);
        }
    }

    /**
     * Loads confidence section data
     */
    async function loadConfidenceSection() {
        try {
            // Get integrity data
            const integrityData = await woamPost('hw_woam_run_integrity_check');
            const lifetimeData = await woamPost('hw_woam_get_lifetime_stats');
            
            // Update integrity status
            const integrityStatus = document.getElementById('woam-confidence-integrity');
            if (integrityStatus) {
                if (integrityData.total_orphans === 0) {
                    integrityStatus.innerHTML = '<span class="woam-confidence-status--ok">✓ Healthy</span>';
                    integrityStatus.className = 'woam-confidence-status woam-confidence-status--ok';
                } else {
                    integrityStatus.innerHTML = `<span class="woam-confidence-status--warn">⚠ ${integrityData.total_orphans} orphaned records</span>`;
                    integrityStatus.className = 'woam-confidence-status woam-confidence-status--warn';
                }
            }
            
            // Update restore success rate
            const restoreRate = document.getElementById('woam-confidence-rate');
            if (restoreRate) {
                const rate = lifetimeData.restore_success_rate;
                if (rate === 100) {
                    restoreRate.innerHTML = '<span class="woam-confidence-status--ok">100% Success Rate</span>';
                    restoreRate.className = 'woam-confidence-status woam-confidence-status--ok';
                } else if (rate >= 90) {
                    restoreRate.innerHTML = `<span class="woam-confidence-status--ok">${rate}% Success Rate</span>`;
                    restoreRate.className = 'woam-confidence-status woam-confidence-status--ok';
                } else if (rate >= 70) {
                    restoreRate.innerHTML = `<span class="woam-confidence-status--warn">${rate}% Success Rate</span>`;
                    restoreRate.className = 'woam-confidence-status woam-confidence-status--warn';
                } else {
                    restoreRate.innerHTML = `<span class="woam-confidence-status--error">${rate}% Success Rate</span>`;
                    restoreRate.className = 'woam-confidence-status woam-confidence-status--error';
                }
            }
            
            // Update verification status
            const verifyStatus = document.getElementById('woam-confidence-verify');
            if (verifyStatus) {
                if (integrityData.is_healthy) {
                    verifyStatus.innerHTML = '<span class="woam-confidence-status--ok">✓ Fully Verified</span>';
                    verifyStatus.className = 'woam-confidence-status woam-confidence-status--ok';
                } else {
                    verifyStatus.innerHTML = '<span class="woam-confidence-status--warn">⚠ Needs Attention</span>';
                    verifyStatus.className = 'woam-confidence-status woam-confidence-status--warn';
                }
            }
            
            // Update last scan date
            const lastScan = document.getElementById('woam-confidence-last-scan');
            if (lastScan) {
                const scanHistory = getScanHistoryFromStorage();
                if (scanHistory && scanHistory.length > 0) {
                    const lastScanDate = new Date(scanHistory[0].date);
                    lastScan.innerHTML = lastScanDate.toLocaleDateString();
                } else {
                    lastScan.innerHTML = 'Never';
                }
            }
            
        } catch (err) {
            console.error('Failed to load confidence section:', err);
        }
    }

    /**
     * Scan history management (localStorage)
     */
    function getScanHistoryFromStorage() {
        const history = localStorage.getItem('woam_scan_history');
        return history ? JSON.parse(history) : [];
    }

    function saveScanHistory(scanResult) {
        const history = getScanHistoryFromStorage();
        history.unshift({
            date: new Date().toISOString(),
            result: scanResult
        });
        // Keep only last 10 scans
        if (history.length > 10) history.pop();
        localStorage.setItem('woam_scan_history', JSON.stringify(history));
    }

    /**
     * Renders scan history in the UI
     */
    function renderScanHistory() {
        const historyContainer = document.getElementById('woam-scan-history-list');
        const historySection = document.getElementById('woam-scan-history');
        const history = getScanHistoryFromStorage();
        
        if (!historyContainer) return;
        
        if (history.length === 0) {
            historySection.style.display = 'none';
            return;
        }
        
        historySection.style.display = 'block';
        let html = '<div class="woam-scan-history-list">';
        
        history.forEach(scan => {
            const date = new Date(scan.date);
            const isHealthy = scan.result.is_healthy;
            const statusClass = isHealthy ? 'woam-scan-history-status--healthy' : 'woam-scan-history-status--issues';
            const statusIcon = isHealthy ? '✓' : '⚠';
            const statusText = isHealthy ? 'Healthy' : `${scan.result.total_orphans} issues found`;
            
            html += `
                <div class="woam-scan-history-item">
                    <span class="woam-scan-history-date">${date.toLocaleDateString()} ${date.toLocaleTimeString()}</span>
                    <span class="woam-scan-history-status ${statusClass}">
                        ${statusIcon} ${statusText}
                    </span>
                </div>
            `;
        });
        
        html += '</div>';
        historyContainer.innerHTML = html;
    }

    /**
     * Enhanced integrity check with history tracking
     */
    async function runEnhancedIntegrityCheck(btn, resultEl) {
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> Scanning...';
        resultEl.innerHTML = '';

        try {
            const data = await woamPost('hw_woam_run_integrity_check');
            
            // Save to history
            saveScanHistory(data);
            renderScanHistory();
            
            // Update confidence section
            await loadConfidenceSection();
            
            if (data.is_healthy) {
                resultEl.innerHTML = `
                    <div class="woam-summary woam-summary--ok">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>
                            <strong>Archive Health Check Passed</strong>
                            <p>All ${formatNumber(data.total_orphans === 0 ? 'archive' : '')} records are intact. No issues found.</p>
                        </div>
                    </div>`;
                
                // Show fix button? No, but could offer optimization
                const fixBtn = document.getElementById('woam-fix-orphans');
                if (fixBtn) fixBtn.style.display = 'none';
                
            } else {
                resultEl.innerHTML = `
                    <div class="woam-summary woam-summary--warn">
                        <span class="dashicons dashicons-warning"></span>
                        <div>
                            <strong>${formatNumber(data.total_orphans)} Issues Found</strong>
                            <ul>
                                ${data.orphaned_meta ? `<li>${formatNumber(data.orphaned_meta)} orphaned order meta rows</li>` : ''}
                                ${data.orphaned_items ? `<li>${formatNumber(data.orphaned_items)} orphaned order item rows</li>` : ''}
                                ${data.orphaned_item_meta ? `<li>${formatNumber(data.orphaned_item_meta)} orphaned item meta rows</li>` : ''}
                                ${data.orphaned_notes ? `<li>${formatNumber(data.orphaned_notes)} orphaned order note rows</li>` : ''}
                                ${data.orphaned_note_meta ? `<li>${formatNumber(data.orphaned_note_meta)} orphaned note meta rows</li>` : ''}
                            </ul>
                            <button type="button" class="woam-button woam-button--small" data-fix-orphans>
                                <span class="dashicons dashicons-hammer"></span>
                                Fix Orphaned Records
                            </button>
                        </div>
                    </div>`;
                
                // Show fix button and attach handler
                const fixBtn = resultEl.querySelector('[data-fix-orphans]');
                if (fixBtn) {
                    fixBtn.addEventListener('click', () => {
                        // This would call a new endpoint to clean orphans
                        alert('Orphan fixing will be available in a future update.');
                    });
                }
            }

        } catch (err) {
            resultEl.innerHTML = `<div class="woam-summary woam-summary--error">
                <span class="dashicons dashicons-warning"></span>
                <p>${escHtml(err.message)}</p>
            </div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    /**
     * Bulk select/deselect for archived statuses
     */
    function initArchivedBulkSelectors() {
        const selectAllBtn = document.querySelector('[data-select-all-archived-statuses]');
        const deselectAllBtn = document.querySelector('[data-deselect-all-archived-statuses]');
        
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#woam-archived-statuses input');
                checkboxes.forEach(cb => {
                    cb.checked = true;
                });
            });
        }
        
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#woam-archived-statuses input');
                checkboxes.forEach(cb => {
                    cb.checked = false;
                });
            });
        }
    }

    /**
     * Loads the Archive Vault contents and statistics
     * Phase 4: Enhanced with vault statistics and confidence section
     */
    async function loadArchivedTab() {
        const inventoryEl = document.getElementById('woam-archive-inventory');
        const statusEl = document.getElementById('woam-archived-statuses');

        // Load all vault components in parallel
        await Promise.allSettled([
            loadVaultStatistics(),
            loadConfidenceSection(),
            (async () => {
                try {
                    const data = await woamPost('hw_woam_get_archive_breakdown');
                    renderArchiveInventory(inventoryEl, data);
                    renderArchivedStatusCheckboxes(statusEl, data);
                    renderScanHistory();
                } catch (err) {
                    if (inventoryEl) showError(inventoryEl, err.message);
                }
            })()
        ]);
    }

    /**
     * Renders the archive inventory table
     */
    function renderArchiveInventory(el, data) {
        if (!el) return;

        if (!data.breakdown.length) {
            el.classList.remove('woam-loading');
            el.innerHTML = '<p class="woam-empty">No orders currently in the archive.</p>';
            return;
        }

        let html = `
            <table class="woam-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Orders</th>
                    </tr>
                </thead>
                <tbody>`;

        data.breakdown.forEach(row => {
            html += `
                <tr>
                    <td>
                        <span class="woam-status-badge woam-status-badge--${escHtml(row.status)}">
                            ${escHtml(row.label)}
                        </span>
                    </span>
                    <td><strong>${formatNumber(row.order_count)}</strong></td>
                </tr>`;
        });

        html += `
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></span>
                        <td><strong>${formatNumber(data.total_count)}</strong></span>
                    </tr>
                </tfoot>
            </table>`;

        el.classList.remove('woam-loading');
        el.innerHTML = html;
    }

    /**
     * Renders status filter checkboxes for Tab 3 Step 1
     */
    function renderArchivedStatusCheckboxes(el, data) {
        if (!el) return;

        if (!data.breakdown.length) {
            el.innerHTML = '<p class="woam-empty">No archived orders to filter.</p>';
            return;
        }

        let html = '';
        data.breakdown.forEach(row => {
            html += `
                <label class="woam-checkbox">
                    <input type="checkbox"
                        name="archived_statuses[]"
                        value="${escHtml(row.status)}" />
                    ${escHtml(row.label)}
                    <span class="woam-checkbox-count">(${formatNumber(row.order_count)})</span>
                </label>`;
        });

        el.innerHTML = html;
    }

    /**
     * Wires up all interactivity for Tab 3 — Archived Orders
     */
    function initArchivedTab() {
        const container = document.querySelector('.woam-steps[data-mode="archived"]');
        if (!container) return;

        // Radio change handlers
        container.querySelectorAll('input[name="archived_action"]').forEach(radio => {
            radio.addEventListener('change', () => {
                const isDelete = radio.value === 'delete';
                const confirmGroup = document.getElementById('woam-archived-confirm-group');
                const startBtn = document.getElementById('woam-archived-start');

                confirmGroup.style.display = isDelete ? 'block' : 'none';

                if (!isDelete) {
                    document.getElementById('woam-archived-confirm').value = '';
                }

                startBtn.textContent = isDelete ? 'Start Deletion' : 'Start Restore';

                const title = document.getElementById('woam-archived-step3-title');
                if (title) {
                    title.textContent = isDelete
                        ? 'Step 3: Permanently Delete'
                        : 'Step 3: Restore Orders';
                }
            });
        });

        // Step 1 → Step 2
        document.getElementById('woam-archived-step1-next').addEventListener('click', async () => {
            const action = container.querySelector('input[name="archived_action"]:checked')?.value ?? 'restore';
            const statuses = Array.from(
                container.querySelectorAll('#woam-archived-statuses input:checked')
            ).map(cb => cb.value);

            const impactEl = document.getElementById('woam-archived-impact');
            impactEl.classList.add('woam-loading');
            impactEl.innerHTML = 'Calculating…';

            setStep(container, 2);

            try {
                const data = await woamPost('hw_woam_get_count', {
                    mode: action,
                    statuses,
                });

                state.totalOrders = data.count;

                impactEl.classList.remove('woam-loading');

                if (data.count === 0) {
                    impactEl.innerHTML = '<p>No archived orders match the selected filters.</p>';
                    return;
                }

                const actionLabel = action === 'delete' ? 'permanently deleted' : 'restored';

                impactEl.innerHTML = `
                    <div class="woam-impact-table">
                        <div class="woam-impact-row woam-impact-row--total">
                            <span>Orders to be ${escHtml(actionLabel)}</span>
                            <strong>${formatNumber(data.count)}</strong>
                        </div>
                    </div>
                    ${action === 'delete'
                        ? '<div class="woam-warning-message"><span class="dashicons dashicons-warning"></span> This action is permanent and cannot be undone.</div>'
                        : '<div class="woam-info-message"><span class="dashicons dashicons-info"></span> Orders will be moved back to live WooCommerce tables.</div>'
                    }`;

            } catch (err) {
                showError(impactEl, err.message);
            }
        });

        // Step 2 → Step 3
        document.getElementById('woam-archived-step2-next').addEventListener('click', () => {
            setStep(container, 3);
        });

        // Back buttons
        container.querySelectorAll('[data-step-back]').forEach(btn => {
            btn.addEventListener('click', () => {
                setStep(container, parseInt(btn.dataset.stepBack));
            });
        });

        // Start button
        document.getElementById('woam-archived-start').addEventListener('click', async () => {
            const action = container.querySelector('input[name="archived_action"]:checked')?.value ?? 'restore';
            const dryRun = document.getElementById('woam-archived-dry-run').checked;
            const statuses = Array.from(
                container.querySelectorAll('#woam-archived-statuses input:checked')
            ).map(cb => cb.value);

            if (action === 'delete' && !dryRun) {
                const confirmVal = document.getElementById('woam-archived-confirm').value.trim();
                if (confirmVal !== 'DELETE') {
                    alert('Please type DELETE to confirm permanent deletion.');
                    return;
                }
            }

            const ajaxAction = action === 'delete'
                ? 'hw_woam_delete_batch'
                : 'hw_woam_restore_batch';

            await runBatchLoop({
                action: ajaxAction,
                payload: { statuses, dry_run: dryRun ? '1' : '' },
                total: state.totalOrders,
                progressEl: document.getElementById('woam-archived-progress'),
                fillEl: document.getElementById('woam-archived-progress-fill'),
                textEl: document.getElementById('woam-archived-progress-text'),
                summaryEl: document.getElementById('woam-archived-summary'),
                startBtn: document.getElementById('woam-archived-start'),
            });

            await loadArchivedTab();
        });

        // Enhanced Integrity Check button (Phase 4)
        const integrityBtn = document.getElementById('woam-run-integrity-check');
        if (integrityBtn) {
            integrityBtn.addEventListener('click', async () => {
                const btn = integrityBtn;
                const resultEl = document.getElementById('woam-integrity-result');
                await runEnhancedIntegrityCheck(btn, resultEl);
            });
        }
    }

    /**
     * ============================================================
     * BATCH PROCESSING (Original)
     * ============================================================
     */

    /**
     * Runs a batched AJAX operation until the server returns processed === 0
     */
    async function runBatchLoop(opts) {
        const { action, payload, total, progressEl, fillEl, textEl, summaryEl, startBtn } = opts;

        let processed = 0;
        let succeeded = 0;
        let failed = 0;

        startBtn.disabled = true;
        progressEl.style.display = 'block';
        summaryEl.innerHTML = '';

        try {
            while (true) {
                const data = await woamPost(action, payload);

                processed += data.processed;
                succeeded += data.succeeded;
                failed += data.failed;

                const pct = total > 0 ? Math.min(Math.round((processed / total) * 100), 100) : 100;
                fillEl.style.width = pct + '%';
                textEl.textContent = `${formatNumber(processed)} of ${formatNumber(total)} processed`;

                if (data.processed === 0) {
                    break;
                }
            }

            const dryNote = payload.dry_run ? ' <em>(dry run — no changes made)</em>' : '';
            summaryEl.innerHTML = `
                <div class="woam-summary woam-summary--${failed > 0 ? 'warn' : 'ok'}">
                    <p><strong>${formatNumber(succeeded)}</strong> succeeded &nbsp;
                    <strong>${formatNumber(failed)}</strong> failed${dryNote}</p>
                </div>`;

            state.dirty = true;

        } catch (err) {
            summaryEl.innerHTML = `<div class="woam-summary woam-summary--error">
                <p>${escHtml(err.message)}</p>
            </div>`;
        } finally {
            startBtn.disabled = false;
        }
    }

    /**
     * ============================================================
     * TAB INITIALIZATION
     * ============================================================
     */

    /**
     * Initialises tab switching behaviour
     */
    function initTabs() {
        const tabs = document.querySelectorAll('.woam-tab');
        const panels = document.querySelectorAll('.woam-panel');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;

                tabs.forEach(t => {
                    t.classList.toggle('woam-tab--active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });

                panels.forEach(panel => {
                    panel.classList.toggle('woam-panel--active', panel.id === `woam-panel-${target}`);
                });

                if (target === 'overview') {
                    loadOverviewTab();
                } else if (target === 'archived') {
                    loadArchivedTab();
                }
            });
        });
    }

    /**
     * Hero button handlers
     */
    function initHeroButtons() {
        const archiveBtn = document.querySelector('[data-trigger-archive]');
        if (archiveBtn) {
            archiveBtn.addEventListener('click', () => {
                document.querySelector('.woam-tab[data-tab="archive"]').click();
            });
        }

        const scrollBtn = document.querySelector('[data-scroll-to="recommendation"]');
        if (scrollBtn) {
            scrollBtn.addEventListener('click', () => {
                document.getElementById('woam-recommendation-card')?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            });
        }
    }

    /**
     * DOM Ready - Entry Point
     */
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        initHeroButtons();
        loadOpportunityBanner();
        loadOverviewTab();
        initArchiveTab();
        initArchivedTab();
    });

})();