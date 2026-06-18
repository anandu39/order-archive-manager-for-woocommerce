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
        excludeIds: [],
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

        // Scroll the step wizard into view so the user always sees the new step
        // without having to manually scroll up from Step 1's tall content.
        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
     * Show notification message
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
        
        setTimeout(() => {
            notification.classList.add('woam-notification--fadeout');
            setTimeout(() => notification.remove(), 300);
        }, 5000);
        
        notification.querySelector('.woam-notification-close')?.addEventListener('click', () => {
            notification.remove();
        });
    }

    /**
     * ============================================================
     * OVERVIEW TAB FUNCTIONS
     * ============================================================
     */

    /**
     * Load opportunity banner with impactful message
     */
    async function loadOpportunityBanner() {
        const banner = document.getElementById('woam-opportunity-banner');
        const messageEl = document.getElementById('woam-opportunity-message');
        const ctaBtn = document.getElementById('woam-opportunity-cta');
        
        if (!banner) return;
        
        try {
            const twelveMonthsAgo = new Date();
            twelveMonthsAgo.setMonth(twelveMonthsAgo.getMonth() - 12);
            const dateStr = twelveMonthsAgo.toISOString().split('T')[0];

            const [dbStats, orderData, totalOrdersData, eligibleData] = await Promise.all([
                woamPost('hw_woam_get_db_stats'),
                woamPost('hw_woam_get_archive_breakdown'),
                woamPost('hw_woam_get_count', {
                    mode: 'archive',
                    before_date: '2099-01-01',
                    statuses: ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed']
                }),
                woamPost('hw_woam_get_count', {
                    mode: 'archive',
                    before_date: dateStr,
                    statuses: ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed']
                }),
            ]);

            const totalBytes = dbStats.total_bytes || 0;
            const totalFormatted = dbStats.total_formatted || '0 B';
            const totalArchived = orderData.total_count || 0;
            const totalOrders = totalOrdersData.count || 0;
            const eligibleOrders = totalOrders > 0 ? (eligibleData.count || 0) : 0;
            
            const avgOrderSize = totalBytes > 0 && totalOrders > 0 ? totalBytes / totalOrders : 50 * 1024;
            const estimatedSavings = eligibleOrders * avgOrderSize;
            const estimatedSavingsFormatted = formatBytes(estimatedSavings);
            
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
                ctaBtn.onclick = () => {
                    document.querySelector('.woam-tab[data-tab="archive"]')?.click();
                };
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

            const applyBtn = container.querySelector('[data-use-recommendation]');
            if (applyBtn) {
                applyBtn.addEventListener('click', () => {
                    applyRecommendation(data);
                });
            }

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
     */
    function applyRecommendation(recommendation) {
        sessionStorage.setItem('woam_recommendation', JSON.stringify({
            date: recommendation.recommended_date,
            statuses: recommendation.recommended_statuses || ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed']
        }));
        
        sessionStorage.setItem('woam_recommendation_applied', 'true');
        
        const archiveTab = document.querySelector('.woam-tab[data-tab="archive"]');
        if (archiveTab) {
            archiveTab.click();
            setTimeout(() => {
                showNotification('Recommendation applied! Review the filters and click "Start Archive".', 'success');
            }, 500);
        }
    }

    /**
     * Dismiss a recommendation
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
            
            const archiveBtn = container.querySelector('[data-archive-suggested]');
            if (archiveBtn) {
                archiveBtn.addEventListener('click', () => {
                    document.querySelector('.woam-tab[data-tab="archive"]').click();
                });
            }

        } catch (err) {
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
     * Loads storage composition chart
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
     * Loads recent activity
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
     * DATE RANGE PICKER FUNCTIONS
     * ============================================================
     */

    /**
     * Initialize enhanced date range picker with min/max limits
     */
    function initDateRangePicker() {
        const fromInput = document.getElementById('woam-date-from');
        const toInput = document.getElementById('woam-date-to');
        
        if (!fromInput || !toInput) return;
        
        // Use WordPress's built-in datepicker
        if (typeof jQuery !== 'undefined' && jQuery.datepicker) {
            // Get oldest order date for min limit
            fetchOldestOrderDate().then(oldestDate => {
                const dateFormat = 'yy-mm-dd';
                
                jQuery(fromInput).datepicker({
                    dateFormat: dateFormat,
                    changeMonth: true,
                    changeYear: true,
                    yearRange: '2000:' + new Date().getFullYear(),
                    maxDate: 0,
                    onSelect: function(selectedDate) {
                        const minDate = jQuery.datepicker.parseDate(dateFormat, selectedDate);
                        jQuery(toInput).datepicker('option', 'minDate', minDate);
                        // Trigger change event
                        fromInput.dispatchEvent(new Event('change'));
                    }
                });
                
                jQuery(toInput).datepicker({
                    dateFormat: dateFormat,
                    changeMonth: true,
                    changeYear: true,
                    yearRange: '2000:' + new Date().getFullYear(),
                    maxDate: 0,
                    onSelect: function() {
                        toInput.dispatchEvent(new Event('change'));
                    }
                });
                
                if (oldestDate) {
                    const oldest = jQuery.datepicker.parseDate('yy-mm-dd', oldestDate);
                    jQuery(fromInput).datepicker('option', 'minDate', oldest);
                }
            });
        }
                
        // When 'From' date changes, update 'To' min
        fromInput.addEventListener('change', function() {
            if (this.value) {
                toInput.min = this.value;
                if (toInput.value && toInput.value < this.value) {
                    toInput.value = this.value;
                }
                loadArchiveAnalysisRange();
            }
        });
        
        // When 'To' date changes
        toInput.addEventListener('change', function() {
            if (this.value) {
                loadArchiveAnalysisRange();
            }
        });

        // Initial load if dates are already set
        if (fromInput.value && toInput.value) {
            loadArchiveAnalysisRange();
        }
    }

    /**
     * Fetch oldest order date from the database
     */
    async function fetchOldestOrderDate() {
        try {
            const data = await woamPost('hw_woam_get_oldest_order_date');
            return data.oldest_date || null;
        } catch (err) {
            console.error('Failed to fetch oldest order date:', err);
            return null;
        }
    }

    /**
     * Enhanced preset button handler with range selection
     */
    function enhancePresetButtonsRange() {
        const container = document.querySelector('.woam-steps[data-mode="archive"]');
        if (!container) return;

        // Hide date inputs by default — only shown when "Custom Range" is active.
        const dateRange = document.querySelector('.woam-date-range');
        if (dateRange) dateRange.style.display = 'none';

        container.querySelectorAll('.woam-preset-btn').forEach(btn => {
            const newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);

            newBtn.addEventListener('click', function() {
                const preset = this.dataset.preset;
                const fromInput = document.getElementById('woam-date-from');
                const toInput = document.getElementById('woam-date-to');
                const rangeLabelEl = document.getElementById('woam-general-range-label');
                const today = new Date();
                const todayStr = today.toISOString().split('T')[0];

                container.querySelectorAll('.woam-preset-btn').forEach(b => {
                    b.classList.remove('woam-preset-btn--active');
                });

                if (preset === 'custom') {
                    if (dateRange) dateRange.style.display = 'flex';
                    fromInput.disabled = false;
                    toInput.disabled = false;
                    fromInput.focus();
                    this.classList.add('woam-preset-btn--active');

                    // Dates aren't chosen yet for a custom range — clear any
                    // stale label from a previously-selected preset so it
                    // doesn't claim a range that's no longer active.
                    if (rangeLabelEl) rangeLabelEl.textContent = '';
                    return;
                }

                let months = 0;
                switch (preset) {
                    case '3months': months = 3; break;
                    case '6months': months = 6; break;
                    case '12months': months = 12; break;
                    case '24months': months = 24; break;
                    default: return;
                }

                const fromDate = new Date();
                fromDate.setMonth(fromDate.getMonth() - months);

                const yyyy = fromDate.getFullYear();
                const mm = String(fromDate.getMonth() + 1).padStart(2, '0');
                const dd = String(fromDate.getDate()).padStart(2, '0');
                const fromStr = `${yyyy}-${mm}-${dd}`;

                fromInput.value = fromStr;
                toInput.value = todayStr;

                fromInput.disabled = false;
                toInput.disabled = false;

                // Update datepicker values if available
                if (typeof jQuery !== 'undefined' && jQuery.datepicker) {
                    jQuery(fromInput).datepicker('setDate', fromStr);
                    jQuery(toInput).datepicker('setDate', todayStr);
                }

                this.classList.add('woam-preset-btn--active');

                // Hide custom date inputs when a preset is selected.
                if (dateRange) dateRange.style.display = 'none';

                // Show the explicit range immediately (don't wait for the
                // AJAX analysis call) so "2 years ago" reads unambiguously
                // as "from <date> to today", not "only orders from that year".
                if (rangeLabelEl) {
                    rangeLabelEl.textContent = formatDateRangeLabel(fromStr, todayStr);
                }

                loadArchiveAnalysisRange();
            });
        });
    }

    /**
     * Load both general and subscription analysis with date range
     */
    async function loadArchiveAnalysisRange() {
        const fromDate = document.getElementById('woam-date-from')?.value;
        const toDate = document.getElementById('woam-date-to')?.value;
        
        if (!fromDate || !toDate) {
            document.getElementById('woam-general-analysis').style.display = 'none';
            document.getElementById('woam-subscription-analysis').style.display = 'none';
            return;
        }
        
        await loadGeneralAnalysisRange(fromDate, toDate);
        
        if (document.querySelector('.woam-section--subscription')) {
            await loadSubscriptionAnalysisRange(fromDate, toDate);
        }
    }

    /**
     * Builds a human-readable "From X to Y" string for the currently
     * selected archive range, so preset buttons like "2 years ago" are
     * unambiguous about what they actually select (rolling window ending
     * today, not a fixed calendar year).
     */
    function formatDateRangeLabel(fromDate, toDate) {
        const fmt = (d) => {
            const parsed = new Date(d + 'T00:00:00');
            return parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
        };
        return `Archiving orders placed from ${fmt(fromDate)} to ${fmt(toDate)}`;
    }

    /**
     * Load General Orders Analysis with date range
     */
    async function loadGeneralAnalysisRange(fromDate, toDate) {
        const container = document.getElementById('woam-general-analysis');
        const breakdownContainer = document.getElementById('woam-general-breakdown');
        const totalContainer = document.getElementById('woam-general-total');
        const orderCountEl = document.getElementById('woam-general-order-count');
        const rangeLabelEl = document.getElementById('woam-general-range-label');

        // For analysis display: always use ALL available statuses so user sees full picture.
        // The checkboxes control what gets archived, not what gets displayed here.
        const allStatuses = Array.from(
            document.querySelectorAll('#woam-archive-statuses input')
        ).map(cb => cb.value);

        if (allStatuses.length === 0) {
            container.style.display = 'none';
            return;
        }

        // Statuses the user has actually checked — used only for the "(selected)" badge.
        // This is separate from allStatuses, which is what gets displayed.
        const checkedStatuses = Array.from(
            document.querySelectorAll('#woam-archive-statuses input:checked')
        ).map(cb => cb.value);

        const statuses = allStatuses;

        container.style.display = 'block';
        breakdownContainer.innerHTML = '<span class="woam-loading">Loading...</span>';

        // Explicit, human-readable range so "2 years ago" etc. is unambiguous.
        if (rangeLabelEl) {
            rangeLabelEl.textContent = formatDateRangeLabel(fromDate, toDate);
        }

        try {
            const data = await woamPost('hw_woam_preview_general_orders_range', {
                from_date: fromDate,
                to_date: toDate,
                statuses: statuses
            });

            const statusColors = {
                'wc-completed': { bg: '#e6f4ea', border: '#2ea64a', label: 'Completed' },
                'wc-processing': { bg: '#e6f0fa', border: '#2271b1', label: 'Processing' },
                'wc-on-hold': { bg: '#fef9e7', border: '#dba617', label: 'On Hold' },
                'wc-cancelled': { bg: '#fdf0f0', border: '#d63638', label: 'Cancelled' },
                'wc-refunded': { bg: '#fef9e7', border: '#dba617', label: 'Refunded' },
                'wc-failed': { bg: '#fdf0f0', border: '#d63638', label: 'Failed' },
                'wc-pending': { bg: '#f8f4ff', border: '#7f54b3', label: 'Pending' },
            };

            let html = '';
            let totalEligible = 0;
            const eligibleStatuses = ['wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed'];

            // Show total count first
            if (orderCountEl) {
                orderCountEl.textContent = `${formatNumber(data.total || 0)} orders`;
            }

            // Build breakdown pills
            if (data.breakdown && Object.keys(data.breakdown).length > 0) {
                for (const [status, count] of Object.entries(data.breakdown)) {
                    if (count > 0) {
                        const color = statusColors[status] || { bg: '#f0f0f1', border: '#646970', label: status.replace('wc-', '') };
                        const isEligible = eligibleStatuses.includes(status);
                        const isSelected = checkedStatuses.includes(status);

                        html += `
                            <div style="background: ${color.bg}; padding: 6px 12px; border-radius: 4px; font-size: 12px; border-left: 3px solid ${color.border}; display: inline-flex; align-items: center; gap: 6px;">
                                <strong>${formatNumber(count)}</strong>
                                <span style="color: #646970;">${color.label}</span>
                                ${isEligible ? '<span style="color: #2ea64a; font-size: 10px;">✓ Eligible</span>' : '<span style="color: #dba617; font-size: 10px;">⨯ Not eligible</span>'}
                                ${isSelected ? '<span style="color: #7f54b3; font-size: 10px;">(selected)</span>' : ''}
                            </div>
                        `;
                        if (isEligible) {
                            totalEligible += count;
                        }
                    }
                }
            } else {
                html = '<p style="color: #646970; font-size: 12px;">No orders found for the selected period</p>';
            }

            breakdownContainer.innerHTML = html;

            // Show total eligible with savings estimate
            if (data.estimated_savings_formatted && data.estimated_savings_formatted !== '0 B') {
                totalContainer.innerHTML = `
                    <strong>Total eligible for archiving: ${formatNumber(totalEligible)}</strong>
                    <span style="color: #7f54b3; margin-left: 16px;">
                        Estimated savings: ${data.estimated_savings_formatted}
                    </span>
                    <span style="color: #646970; margin-left: 12px; font-size: 11px;">
                        (based on ${formatNumber(data.total || 0)} total orders in range)
                    </span>
                `;
            } else {
                totalContainer.innerHTML = `
                    <strong>Total eligible for archiving: ${formatNumber(totalEligible)}</strong>
                    <span style="color: #646970; margin-left: 12px; font-size: 11px;">
                        (${formatNumber(data.total || 0)} total orders in range)
                    </span>
                `;
            }

        } catch (err) {
            breakdownContainer.innerHTML = `<p class="woam-error">${escHtml(err.message)}</p>`;
        }
    }

    /**
     * Load Subscription Orders Analysis with date range
     */
    async function loadSubscriptionAnalysisRange(fromDate, toDate) {
        const container = document.getElementById('woam-subscription-analysis');
        const breakdownContainer = document.getElementById('woam-subscription-breakdown');
        const totalContainer = document.getElementById('woam-subscription-total');
        const orderCountEl = document.getElementById('woam-subscription-order-count');

        // Show container even if loading
        container.style.display = 'block';
        breakdownContainer.innerHTML = '<span class="woam-loading">Loading...</span>';

        try {
            const data = await woamPost('hw_woam_preview_subscription_orders_range', {
                from_date: fromDate,
                to_date: toDate
            });

            if (!data.subscriptions_active) {
                container.style.display = 'none';
                return;
            }

            const subLabels = {
                'active': 'Active',
                'cancelled': 'Cancelled',
                'expired': 'Expired',
                'failed': 'Failed',
                'on-hold': 'On Hold',
                'pending-cancel': 'Pending Cancel'
            };

            const protectedStatuses = ['active', 'on-hold', 'pending-cancel'];
            const eligibleStatuses = ['cancelled', 'expired', 'failed'];

            // Single source of truth for the mini-stat tallies — built once,
            // from the breakdown, and written to the DOM exactly once below.
            let protectedCount = 0;
            let cancelledCount = 0;
            let expiredCount = 0;
            let totalEligible = 0;
            let totalProtected = 0;

            let html = '';

            // Get selected subscription statuses (for the "(selected)" badge)
            const selectedSubStatuses = Array.from(
                document.querySelectorAll('#woam-subscription-statuses input:checked:not(:disabled)')
            ).map(cb => cb.value);

            // Show total count
            if (orderCountEl) {
                orderCountEl.textContent = `${formatNumber(data.total || 0)} orders`;
            }

            // Build breakdown pills + tally mini stats in one pass
            if (data.breakdown && Object.keys(data.breakdown).length > 0) {
                for (const [status, count] of Object.entries(data.breakdown)) {
                    if (count > 0) {
                        const isProtected = protectedStatuses.includes(status);
                        const isEligible = eligibleStatuses.includes(status);
                        const label = subLabels[status] || status;
                        const isSelected = selectedSubStatuses.includes(status);

                        if (isProtected) {
                            protectedCount += count;
                            totalProtected += count;
                        } else if (isEligible) {
                            totalEligible += count;
                            if (status === 'cancelled') cancelledCount += count;
                            if (status === 'expired') expiredCount += count;
                        }

                        html += `
                            <div style="background: ${isProtected ? '#fdf0f0' : '#e6f4ea'}; padding: 6px 12px; border-radius: 4px; font-size: 12px; border-left: 3px solid ${isProtected ? '#d63638' : '#2ea64a'}; display: inline-flex; align-items: center; gap: 6px;">
                                <strong>${formatNumber(count)}</strong>
                                <span style="color: #646970;">${label}</span>
                                ${isProtected ? '<span style="color: #d63638; font-size: 10px;">🔒 Protected</span>' : ''}
                                ${isEligible ? '<span style="color: #2ea64a; font-size: 10px;">✓ Safe</span>' : ''}
                                ${isSelected && isEligible ? '<span style="color: #7f54b3; font-size: 10px;">(selected)</span>' : ''}
                            </div>
                        `;
                    }
                }
            } else {
                html = '<p style="color: #646970; font-size: 12px;">No subscription orders found</p>';
            }

            // Single render — no earlier placeholder write to clobber.
            breakdownContainer.innerHTML = html;

            totalContainer.innerHTML = `
                <strong>Eligible for archiving: ${formatNumber(totalEligible)}</strong>
                <span style="color: #d63638; margin-left: 16px;">
                    Protected: ${formatNumber(totalProtected)}
                </span>
            `;

            // Single writer for the four mini-stat boxes, derived from the
            // same breakdown tally used for the pills above — no duplicate
            // writes from data.protected/data.eligible competing with this.
            updateSubscriptionMiniStats(protectedCount, cancelledCount, expiredCount, totalEligible);

            // Update checkbox counts
            updateSubscriptionCheckboxCounts(data.breakdown);

        } catch (err) {
            breakdownContainer.innerHTML = `<p class="woam-error">${escHtml(err.message)}</p>`;
        }
    }

    /**
     * Update subscription mini stats
     */
    function updateSubscriptionMiniStats(protectedCount, cancelledCount, expiredCount, eligibleCount) {
        const protectedEl = document.getElementById('woam-subs-protected');
        const cancelledEl = document.getElementById('woam-subs-cancelled');
        const expiredEl = document.getElementById('woam-subs-expired');
        const eligibleEl = document.getElementById('woam-subs-eligible');
        
        if (protectedEl) protectedEl.textContent = formatNumber(protectedCount);
        if (cancelledEl) cancelledEl.textContent = formatNumber(cancelledCount);
        if (expiredEl) expiredEl.textContent = formatNumber(expiredCount);
        if (eligibleEl) eligibleEl.textContent = formatNumber(eligibleCount);
    }

    /**
     * Update subscription checkbox counts
     */
    function updateSubscriptionCheckboxCounts(breakdown) {
        const statusMap = {
            'active': 'woam-subs-active-count',
            'pending-cancel': 'woam-subs-pending-cancel-count',
            'on-hold': 'woam-subs-on-hold-count',
            'cancelled': 'woam-subs-cancelled-count',
            'expired': 'woam-subs-expired-count',
            'failed': 'woam-subs-failed-count'
        };
        
        for (const [status, elementId] of Object.entries(statusMap)) {
            const el = document.getElementById(elementId);
            if (el) {
                const count = breakdown[status] || 0;
                el.textContent = `(${formatNumber(count)})`;
            }
        }
    }

    /**
     * ============================================================
     * ARCHIVE TAB FUNCTIONS
     * ============================================================
     */

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
                loadArchiveAnalysisRange();
            });
        }

        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#woam-archive-statuses input');
                checkboxes.forEach(cb => {
                    cb.checked = false;
                });
                loadArchiveAnalysisRange();
            });
        }
    }

    /**
     * Apply recommendation from Overview tab
     */
    function applyRecommendationFromStorage() {
        const savedRec = sessionStorage.getItem('woam_recommendation');
        if (!savedRec) return;

        try {
            const rec = JSON.parse(savedRec);

            const toInput = document.getElementById('woam-date-to');
            const fromInput = document.getElementById('woam-date-from');
            const dateRange = document.querySelector('.woam-date-range');
            const rangeLabelEl = document.getElementById('woam-general-range-label');

            // The recommendation only carries a single cutoff date
            // ("archive orders before this date"), so it maps to the
            // "To" field. We don't guess a "From" date — the user picks
            // how far back to go, via Custom Range, to avoid silently
            // sweeping in more orders than the recommendation intended.
            if (toInput && rec.date) {
                toInput.value = rec.date;
                toInput.disabled = false;

                if (typeof jQuery !== 'undefined' && jQuery.datepicker) {
                    jQuery(toInput).datepicker('setDate', rec.date);
                }
            }

            if (fromInput) {
                fromInput.disabled = false;
            }

            // Switch to Custom Range mode and reveal the date inputs, since
            // we've just set "To" directly rather than via a preset button.
            const container = document.querySelector('.woam-steps[data-mode="archive"]');
            if (container) {
                container.querySelectorAll('.woam-preset-btn').forEach(b => {
                    b.classList.toggle('woam-preset-btn--active', b.dataset.preset === 'custom');
                });
            }
            if (dateRange) dateRange.style.display = 'flex';

            if (rec.statuses && rec.statuses.length) {
                const checkboxes = document.querySelectorAll('#woam-archive-statuses input');
                checkboxes.forEach(cb => {
                    cb.checked = rec.statuses.includes(cb.value);
                });
            }

            sessionStorage.removeItem('woam_recommendation');

            // Prompt the user to fill in "From" rather than guessing it,
            // and only load the analysis once both dates are present.
            if (fromInput && !fromInput.value) {
                fromInput.focus();
            } else {
                setTimeout(() => {
                    loadArchiveAnalysisRange();
                }, 100);
            }

        } catch (e) {
            console.error('Failed to apply recommendation:', e);
        }
    }

    /**
     * Enhanced progress display with ETA and batch info
     */
    async function runBatchLoopEnhanced(opts) {
        const { action, payload, total, progressEl, fillEl, textEl, summaryEl, startBtn, confirmEl } = opts;

        let processed = 0;
        let succeeded = 0;
        let skipped = 0;
        let failed = 0;
        let batchCount = 0;
        const skipReasonSamples = [];
        const startTime = Date.now();

        // ─── RESET SYSTEM EXCLUSIONS FOR A NEW START RUN ───
        state.excludeIds = [];

        startBtn.disabled = true;
        progressEl.style.display = 'block';
        summaryEl.innerHTML = '';

        if (confirmEl) {
            confirmEl.style.display = 'none';
        }

        const batchSize = parseInt(document.getElementById('woam-batch-size')?.value || '500');
        payload.batch_size = batchSize;
        const totalBatches = Math.ceil(total / batchSize);

        try {
            while (true) {
                batchCount++;
                
                // ─── ATTACH ACCUMULATED EXCLUSIONS TO THE PAYLOAD ───
                if (state.excludeIds.length > 0) {
                    payload.exclude_ids = state.excludeIds;
                } else {
                    delete payload.exclude_ids;
                }

                const data = await woamPost(action, payload);

                processed += data.processed;
                succeeded += data.succeeded;
                skipped += data.skipped || 0;
                failed += data.failed;

                // ─── ACCUMULATE FAILED AND SKIPPED IDS FROM BACKEND ───
                if (data.failed_ids && Array.isArray(data.failed_ids)) {
                    state.excludeIds = state.excludeIds.concat(data.failed_ids);
                }

                if (Array.isArray(data.skip_reasons)) {
                    for (const entry of data.skip_reasons) {
                        if (skipReasonSamples.length < 5) {
                            skipReasonSamples.push(entry);
                        }
                    }
                }

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

                textEl.innerHTML = `
                    <div style="display: flex; flex-direction: column; gap: 6px; padding: 8px 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span><strong>Batch ${batchCount} of ${totalBatches}</strong></span>
                            <span style="font-size: 12px; color: #646970;">${pct}% complete</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px;">
                            <span>${formatNumber(processed)} / ${formatNumber(total)} orders</span>
                            <span style="font-size: 12px; color: #646970;">
                                ${timeStr} · ETA: ${etaStr}
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #646970;">
                            <span>${formatNumber(succeeded)} succeeded</span>
                            <span style="color: #7f54b3;">${formatNumber(skipped)} skipped</span>
                            <span style="color: #d63638;">${formatNumber(failed)} failed</span>
                        </div>
                    </div>
                `;

                // ─── PRIMARY TERMINATION: server signals nothing remains ───
                // has_more=false on dry_run (one pass always enough, nothing moved).
                // has_more=false on real run when remaining eligible count hits zero.
                if (data.has_more === false) {
                    break;
                }

                // Fallback: nothing came back this batch
                if (data.processed === 0) {
                    break;
                }

                // ─── ANTI-DEADLOCK TRAP ───
                // If a batch evaluates elements but moves nothing out of the live view,
                // and reports no new exclusion IDs, break to prevent an infinite loop.
                if (data.processed > 0 && data.succeeded === 0 && (!data.failed_ids || data.failed_ids.length === 0)) {
                    throw new Error('Batch processing paused: Items are failing or being skipped without clearing from memory.');
                }
            }

            const elapsedFinal = Math.round((Date.now() - startTime) / 1000);
            const minutesFinal = Math.floor(elapsedFinal / 60);
            const secondsFinal = elapsedFinal % 60;
            const finalTime = minutesFinal > 0 ? `${minutesFinal}m ${secondsFinal}s` : `${secondsFinal}s`;

            const dryNote = payload.dry_run ? ' <em>(dry run — no changes made)</em>' : '';

            let skipReasonsHtml = '';
            if (skipReasonSamples.length > 0) {
                const items = skipReasonSamples
                    .map(s => `<li>Order #${formatNumber(s.order_id)} — ${escHtml(s.reason)}</li>`)
                    .join('');
                const moreNote = skipped > skipReasonSamples.length
                    ? `<p style="font-size: 11px; color: #646970; margin-top: 4px;">…and ${formatNumber(skipped - skipReasonSamples.length)} more, for similar reasons.</p>`
                    : '';
                skipReasonsHtml = `
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e0e0e0;">
                        <p style="font-size: 12px; color: #7f54b3; margin: 0 0 4px;"><strong>Why orders were skipped:</strong></p>
                        <ul style="margin: 0 0 0 16px; font-size: 12px; color: #646970;">${items}</ul>
                        ${moreNote}
                    </div>`;
            }

            summaryEl.innerHTML = `
                <div class="woam-summary woam-summary--${failed > 0 ? 'warn' : 'ok'}">
                    <div>
                        <p><strong>${formatNumber(succeeded)}</strong> succeeded &nbsp;
                        <strong>${formatNumber(skipped)}</strong> skipped (subscription-protected) &nbsp;
                        <strong>${formatNumber(failed)}</strong> failed${dryNote}</p>
                        <p style="font-size: 12px; color: #646970; margin-top: 4px;">
                            Completed in ${finalTime} · ${formatNumber(processed)} orders processed · ${batchCount} batches
                        </p>
                        ${skipReasonsHtml}
                    </div>
                </div>`;

            state.dirty = true;

            // ── POST-RUN UI STATE ──────────────────────────────────────────
            if (payload.dry_run) {
                // Dry run finished: uncheck the checkbox so clicking Start Archive
                // again will run the real archive. Leave button label as-is.
                const dryRunCb = document.getElementById('woam-archive-dry-run');
                if (dryRunCb) {
                    dryRunCb.checked = false;
                    // Trigger a small visual cue so the user notices the change.
                    dryRunCb.closest('label, .woam-dry-run-row, p')?.classList.add('woam-dry-run-unchecked');
                }
            } else if (succeeded > 0) {
                // Real archive finished successfully: swap button to green shortcut.
                startBtn.disabled = false;
                startBtn.style.cssText = 'background:#2ea64a;color:#fff;border-color:#2ea64a;';
                startBtn.innerHTML = '<span class="dashicons dashicons-archive" style="margin-right:6px;vertical-align:middle;font-size:14px;width:14px;height:14px;"></span> View Archived Orders';
                startBtn.dataset.archiveDone = '1';
            }

        } catch (err) {
            summaryEl.innerHTML = `<div class="woam-summary woam-summary--error">
                <p>${escHtml(err.message)}</p>
            </div>`;
        } finally {
            startBtn.disabled = false;
        }
    }

    /**
     * Wires up all interactivity for Tab 2 — Archive Orders
     */
    function initArchiveTab() {
        const container = document.querySelector('.woam-steps[data-mode="archive"]');
        if (!container) return;

        // Enhanced preset buttons with range support
        enhancePresetButtonsRange();

        // Initialize bulk selectors
        initBulkSelectors();

        // Apply recommendation from Overview tab
        applyRecommendationFromStorage();

        // Auto-load analysis on date/status change
        const fromDateInput = document.getElementById('woam-date-from');
        const toDateInput = document.getElementById('woam-date-to');
        
        if (fromDateInput && toDateInput) {
            fromDateInput.addEventListener('change', loadArchiveAnalysisRange);
            toDateInput.addEventListener('change', loadArchiveAnalysisRange);
        }
        
        document.querySelectorAll('#woam-archive-statuses input').forEach(cb => {
            cb.addEventListener('change', function() {
                const fromDate = document.getElementById('woam-date-from');
                const toDate = document.getElementById('woam-date-to');
                if (fromDate && fromDate.value && toDate && toDate.value) {
                    loadArchiveAnalysisRange();
                }
            });
        });

        document.querySelectorAll('#woam-subscription-statuses input').forEach(cb => {
            cb.addEventListener('change', function() {
                const fromDate = document.getElementById('woam-date-from');
                const toDate = document.getElementById('woam-date-to');
                if (fromDate && fromDate.value && toDate && toDate.value) {
                    loadArchiveAnalysisRange();
                }
            });
        });

        // Step 1 → Step 2: load savings estimate
        document.getElementById('woam-archive-step1-next').addEventListener('click', async () => {
            const fromDate = document.getElementById('woam-date-from')?.value;
            const toDate = document.getElementById('woam-date-to')?.value;
            
            const statuses = Array.from(
                container.querySelectorAll('#woam-archive-statuses input:checked')
            ).map(cb => cb.value);

            const subscriptionStatuses = document.querySelector('.woam-section--subscription') 
                ? Array.from(
                    document.querySelectorAll('#woam-subscription-statuses input:checked')
                ).map(cb => cb.value)
                : [];

            const allStatuses = [...statuses, ...subscriptionStatuses];

            if (!fromDate || !toDate) {
                alert('Please select a date range before continuing.');
                return;
            }

            if (!allStatuses.length) {
                alert('Please select at least one order status.');
                return;
            }

            const impactEl = document.getElementById('woam-archive-impact');
            impactEl.classList.add('woam-loading');
            impactEl.innerHTML = 'Calculating…';

            setStep(container, 2);

            try {
                const data = await woamPost('hw_woam_get_savings_estimate', {
                    from_date: fromDate,
                    before_date: toDate,
                    statuses: allStatuses,
                });

                if (data.order_count === 0) {
                    impactEl.classList.remove('woam-loading');
                    impactEl.innerHTML = '<p>No orders match the selected filters.</p>';
                    return;
                }

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
                const targetStep = parseInt(btn.dataset.stepBack);
                setStep(container, targetStep);

                // Reset Step 3 UI when navigating away from it
                if (targetStep < 3) {
                    const progressEl = document.getElementById('woam-archive-progress');
                    const fillEl     = document.getElementById('woam-archive-progress-fill');
                    const summaryEl  = document.getElementById('woam-archive-summary');
                    const startBtn   = document.getElementById('woam-archive-start');

                    if (progressEl) progressEl.style.display = 'none';
                    if (fillEl)     fillEl.style.width = '0%';
                    if (summaryEl)  summaryEl.innerHTML = '';
                    if (startBtn) {
                        startBtn.disabled = false;
                        startBtn.style.cssText = '';
                        startBtn.innerHTML = 'Start Archive';
                        delete startBtn.dataset.archiveDone;
                    }

                    // Restore dry-run checkbox to checked (safe default) when going back
                    const dryRunCb = document.getElementById('woam-archive-dry-run');
                    if (dryRunCb) {
                        dryRunCb.checked = true;
                        dryRunCb.closest('label, .woam-dry-run-row, p')?.classList.remove('woam-dry-run-unchecked');
                    }
                }
            });
        });

        // Start Archive button
        document.getElementById('woam-archive-start').addEventListener('click', async () => {
            // If a real archive just completed, act as "View Archived Orders"
            const startBtn = document.getElementById('woam-archive-start');
            if (startBtn.dataset.archiveDone === '1') {
                document.querySelector('.woam-tab[data-tab="archived"]')?.click();
                return;
            }

            const dryRun = document.getElementById('woam-archive-dry-run').checked;
            const confirmVal = document.getElementById('woam-archive-confirm').value.trim();
            const confirmEl = document.getElementById('woam-archive-confirm-group');

            if (!dryRun && confirmVal !== 'ARCHIVE') {
                alert('Please type ARCHIVE to confirm.');
                return;
            }

            const fromDate = document.getElementById('woam-date-from')?.value;
            const toDate = document.getElementById('woam-date-to')?.value;
            
            const statuses = Array.from(
                container.querySelectorAll('#woam-archive-statuses input:checked')
            ).map(cb => cb.value);

            const subscriptionStatuses = document.querySelector('.woam-section--subscription') 
                ? Array.from(
                    document.querySelectorAll('#woam-subscription-statuses input:checked')
                ).map(cb => cb.value)
                : [];

            const allStatuses = [...statuses, ...subscriptionStatuses];

            await runBatchLoopEnhanced({
                action: 'hw_woam_archive_batch',
                payload: { 
                    from_date: fromDate,
                    before_date: toDate, 
                    statuses: allStatuses, 
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
            const integrityData = await woamPost('hw_woam_run_integrity_check');
            const lifetimeData = await woamPost('hw_woam_get_lifetime_stats');
            
            const integrityStatus = document.getElementById('woam-confidence-integrity');
            if (integrityStatus) {
                if (integrityData.total_orphans === 0) {
                    integrityStatus.innerHTML = '<span class="woam-confidence-status--ok">Healthy</span>';
                    integrityStatus.className = 'woam-confidence-status woam-confidence-status--ok';
                } else {
                    integrityStatus.innerHTML = `<span class="woam-confidence-status--warn">${integrityData.total_orphans} orphaned records</span>`;
                    integrityStatus.className = 'woam-confidence-status woam-confidence-status--warn';
                }
            }
            
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
            
            const verifyStatus = document.getElementById('woam-confidence-verify');
            if (verifyStatus) {
                if (integrityData.is_healthy) {
                    verifyStatus.innerHTML = '<span class="woam-confidence-status--ok">Fully Verified</span>';
                    verifyStatus.className = 'woam-confidence-status woam-confidence-status--ok';
                } else {
                    verifyStatus.innerHTML = '<span class="woam-confidence-status--warn">Needs Attention</span>';
                    verifyStatus.className = 'woam-confidence-status woam-confidence-status--warn';
                }
            }
            
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
            
            saveScanHistory(data);
            renderScanHistory();
            await loadConfidenceSection();
            
            if (data.is_healthy) {
                resultEl.innerHTML = `
                    <div class="woam-summary woam-summary--ok">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>
                            <strong>Archive Health Check Passed</strong>
                            <p>All archive records are intact. No issues found.</p>
                        </div>
                    </div>`;
                
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
                
                const fixBtn = resultEl.querySelector('[data-fix-orphans]');
                if (fixBtn) {
                    fixBtn.addEventListener('click', () => {
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
                    </td>
                    <td><strong>${formatNumber(row.order_count)}</strong></td>
                </tr>`;
        });

        html += `
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td><strong>${formatNumber(data.total_count)}</strong></td>
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
     * Loads the Archive Vault contents and statistics
     */
    async function loadArchivedTab() {
        const inventoryEl = document.getElementById('woam-archive-inventory');
        const statusEl = document.getElementById('woam-archived-statuses');

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
     * Wires up all interactivity for Tab 3 — Archived Orders
     */
    function initArchivedTab() {
        const container = document.querySelector('.woam-steps[data-mode="archived"]');
        if (!container) return;

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

        document.getElementById('woam-archived-step2-next').addEventListener('click', () => {
            setStep(container, 3);
        });

        container.querySelectorAll('[data-step-back]').forEach(btn => {
            btn.addEventListener('click', () => {
                setStep(container, parseInt(btn.dataset.stepBack));
            });
        });

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

            await runBatchLoopEnhanced({
                action: ajaxAction,
                payload: { statuses, dry_run: dryRun ? '1' : '' },
                total: state.totalOrders,
                progressEl: document.getElementById('woam-archived-progress'),
                fillEl: document.getElementById('woam-archived-progress-fill'),
                textEl: document.getElementById('woam-archived-progress-text'),
                summaryEl: document.getElementById('woam-archived-summary'),
                startBtn: document.getElementById('woam-archived-start'),
                confirmEl: null,
            });

            await loadArchivedTab();
        });

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
     * BATCH PROCESSING
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
        initDateRangePicker();
        loadOverviewTab();
        initArchiveTab();
        initArchivedTab();
    });

})();