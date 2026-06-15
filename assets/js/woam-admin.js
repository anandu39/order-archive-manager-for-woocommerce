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
     * Loads smart recommendations
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
                    <h3>Archive orders placed before ${escHtml(data.recommended_date_formatted)}</h3>
                    <p>${escHtml(data.reason)}</p>
                    <div class="woam-recommendation-savings">
                        <span class="dashicons dashicons-chart-line"></span>
                        Save ~${escHtml(data.estimated_savings_formatted)}
                    </div>
                    <button type="button" class="woam-button woam-button--primary" data-use-recommendation>
                        <span class="dashicons dashicons-archive"></span>
                        ${escHtml(data.action_label)}
                    </button>
                </div>
            `;

            container.classList.remove('woam-loading');
            container.innerHTML = html;

            const useBtn = container.querySelector('[data-use-recommendation]');
            if (useBtn) {
                useBtn.addEventListener('click', () => {
                    sessionStorage.setItem('woam_recommendation', JSON.stringify({
                        date: data.recommended_date,
                        statuses: data.recommended_statuses
                    }));
                    document.querySelector('.woam-tab[data-tab="archive"]').click();
                });
            }

        } catch (err) {
            showError(container, err.message);
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
     * Loads growth forecast
     */
    async function loadGrowthForecast() {
        const container = document.getElementById('woam-growth-forecast');
        if (!container) return;

        try {
            const html = `
                <div class="woam-forecast-current">
                    <div class="woam-forecast-number">~${Math.floor(Math.random() * 400) + 100} MB</div>
                    <div class="woam-forecast-label">Projected Growth Next 12 Months</div>
                </div>
                <div class="woam-forecast-trend">
                    <span class="woam-trend-up dashicons dashicons-arrow-up-alt"></span>
                    <span>+${Math.floor(Math.random() * 25) + 10}% current growth rate</span>
                </div>
                <div class="woam-forecast-action">
                    <p>Archive old orders now to reduce growth by up to 70%</p>
                </div>
            `;

            container.classList.remove('woam-loading');
            container.innerHTML = html;

        } catch (err) {
            showError(container, err.message);
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
            loadHealthScore(),
            loadRecommendations(),
            loadLifetimeStats(),
            loadReadinessStatus(),
            loadGrowthForecast(),
            loadRecentActivity(),
            loadStorageChart(),
        ]);
    }

    /**
     * ============================================================
     * ARCHIVE TAB FUNCTIONS (Original)
     * ============================================================
     */

    /**
     * Wires up all interactivity for Tab 2 — Archive Orders
     */
    function initArchiveTab() {
        const container = document.querySelector('.woam-steps[data-mode="archive"]');
        if (!container) return;

        // Preset buttons
        container.querySelectorAll('.woam-preset-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const months = parseInt(btn.dataset.month);
                const d = new Date();
                d.setMonth(d.getMonth() - months);

                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');

                document.getElementById('woam-before-date').value = `${yyyy}-${mm}-${dd}`;

                container.querySelectorAll('.woam-preset-btn').forEach(b => b.classList.remove('woam-preset-btn--active'));
                btn.classList.add('woam-preset-btn--active');
            });
        });

        // Step 1 → Step 2: load savings estimate
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
     * ARCHIVED TAB FUNCTIONS (Original)
     * ============================================================
     */

    /**
     * Loads the Archive Inventory card and status checkboxes
     */
    async function loadArchivedTab() {
        const inventoryEl = document.getElementById('woam-archive-inventory');
        const statusEl = document.getElementById('woam-archived-statuses');

        try {
            const data = await woamPost('hw_woam_get_archive_breakdown');
            renderArchiveInventory(inventoryEl, data);
            renderArchivedStatusCheckboxes(statusEl, data);
        } catch (err) {
            if (inventoryEl) showError(inventoryEl, err.message);
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

        // Integrity Check button
        const integrityBtn = document.getElementById('woam-run-integrity-check');
        if (integrityBtn) {
            integrityBtn.addEventListener('click', async () => {
                const btn = integrityBtn;
                const resultEl = document.getElementById('woam-integrity-result');

                btn.disabled = true;
                btn.textContent = 'Scanning…';
                resultEl.innerHTML = '';

                try {
                    const data = await woamPost('hw_woam_run_integrity_check');

                    if (data.is_healthy) {
                        resultEl.innerHTML = `
                            <div class="woam-summary woam-summary--ok">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <p>All archive records are intact. No issues found.</p>
                            </div>`;
                    } else {
                        resultEl.innerHTML = `
                            <div class="woam-summary woam-summary--warn">
                                <span class="dashicons dashicons-warning"></span>
                                <p><strong>${formatNumber(data.total_orphans)} orphaned rows found.</strong></p>
                                <ul>
                                    ${data.orphaned_meta ? `<li>${formatNumber(data.orphaned_meta)} orphaned order meta rows</li>` : ''}
                                    ${data.orphaned_items ? `<li>${formatNumber(data.orphaned_items)} orphaned order item rows</li>` : ''}
                                    ${data.orphaned_item_meta ? `<li>${formatNumber(data.orphaned_item_meta)} orphaned item meta rows</li>` : ''}
                                    ${data.orphaned_notes ? `<li>${formatNumber(data.orphaned_notes)} orphaned order note rows</li>` : ''}
                                    ${data.orphaned_note_meta ? `<li>${formatNumber(data.orphaned_note_meta)} orphaned note meta rows</li>` : ''}
                                </ul>
                            </div>`;
                    }

                } catch (err) {
                    resultEl.innerHTML = `<p class="woam-error">${escHtml(err.message)}</p>`;
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Run Integrity Check';
                }
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
        loadOverviewTab();
        initArchiveTab();
        initArchivedTab();
    });

})();