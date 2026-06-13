/**
 * Woo Order Archive Manager — Admin JS
 *
 * Drives all three tab panels via AJAX.
 * Depends on woamData (ajaxUrl, nonce, i18n) provided by wp_localize_script.
*/

(function () {
    'use strict';
    
    /**
     * Global state.
     * dirty: true after any batch operation completes — triggers data reload on next tab activation.
     * totalOrders: cached from get_count, used to calculate progress bar percentage.
     * processedOrders: running total across all batches in the current operation.
    */
    const state = {
        dirty:              false,
        totalOrders:        0,
        processedOrders:    0,
    };

     /**
     * Sends a POST request to wp-admin/admin-ajax.php.
     * Always includes the nonce. Returns the parsed response data on success,
     * throws an Error with the server's message on wp_send_json_error responses.
     *
     * @param {string} action  The wp_ajax_ action name.
     * @param {Object} payload Additional POST fields.
     * @returns {Promise<Object>} The `data` property from wp_send_json_success.
    */
    
    async function woamPost( action, payload = {} ) {
        const body = new FormData();
        body.append( 'action', action );
        body.append( 'nonce', woamData.nonce );
        
        for ( const [ key, value ] of Object.entries( payload ) ) {
            if (Array.isArray( value ) ){
                value.forEach ( v => body.append( key + '[]', v ) );
            }else{
                body.append( key, value );
            }
        }

        const response = await fetch( woamData.ajaxUrl, {
            method:      'POST',
            credentials: 'same-origin',
            body,
        } );

        const json = await response.json();

        if ( ! json.success ) {
            throw new Error( json.data?.message ?? 'Unknown error' );
        }

        return json.data;
    }

    /**
     * Activates a step within a woam-steps container.
     * Hides all steps, shows the target, updates the step dots.
     *
     * @param {HTMLElement} container  The .woam-steps element.
     * @param {number}      stepNumber The 1-based step to activate.
     */
    function setStep( container, stepNumber ) {
        container.querySelectorAll( '.woam-step' ).forEach( step => {
            step.classList.toggle( 'woam-step--active', parseInt( step.dataset.step ) === stepNumber );
        } );

        container.querySelectorAll( '.woam-step-dot' ).forEach( dot => {
            const n = parseInt( dot.dataset.step );
            dot.classList.toggle( 'woam-step-dot--active',    n === stepNumber );
            dot.classList.toggle( 'woam-step-dot--completed', n < stepNumber );
        } );
    }

    /**
     * Renders an error message into a container element.
     *
     * @param {HTMLElement} el      Target container.
     * @param {string}      message Error message to display.
     */
    function showError( el, message ) {
        el.classList.remove( 'woam-loading' );
        el.innerHTML = `<p class="woam-error">${ escHtml( message ) }</p>`;
    }

    /**
     * Escapes a string for safe insertion into innerHTML.
     * Prevents XSS from server-returned strings used in dynamic rendering.
     *
     * @param {string} str Raw string.
     * @returns {string}   HTML-escaped string.
     */
    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    /**
     * Formats an integer with thousands separators for display.
     *
     * @param {number} n
     * @returns {string}
     */
    function formatNumber( n ) {
        return parseInt( n ).toLocaleString();
    }

    /**
     * Initialises tab switching behaviour.
     * On click: updates aria-selected, shows/hides panels,
     * and fires the loader for the newly active tab if needed.
     */
    function initTabs() {
        const tabs   = document.querySelectorAll( '.woam-tab' );
        const panels = document.querySelectorAll( '.woam-panel' );

        tabs.forEach( tab => {
            tab.addEventListener( 'click', () => {
                const target = tab.dataset.tab;

                // Update tab button states.
                tabs.forEach( t => {
                    t.classList.toggle( 'woam-tab--active', t === tab );
                    t.setAttribute( 'aria-selected', t === tab ? 'true' : 'false' );
                } );

                // Show/hide panels.
                panels.forEach( panel => {
                    panel.classList.toggle( 'woam-panel--active', panel.id === `woam-panel-${ target }` );
                } );

                // Fire the tab's data loader on activation.
                // Re-fires if dirty (a batch operation completed since last load).
                if ( target === 'overview' ) {
                    loadOverviewTab();
                }else if ( target === 'archived' ) {
                    loadArchivedTab();
                }
            } );
        } );
    }

    /**
     * Entry point — runs after the DOM is ready.
     */
    document.addEventListener( 'DOMContentLoaded', () => {
        initTabs();
        loadOverviewTab();   // Load overview data immediately on page open.
        initArchiveTab();
        initArchivedTab();
    } );

    /**
     * Loads all four Overview tab cards in parallel.
     * Uses Promise.allSettled so one failed request doesn't block the others.
    */
    async function loadOverviewTab() {

        await Promise.allSettled( [
            loadDbStats(),
            loadArchiveHealth(),
            loadRecentActivity(),
            loadStorageImpact(),
        ] );
    }

    /**
     * Fetches table size data and renders the database bar chart.
     */
    async function loadDbStats() {
        const el = document.getElementById( 'woam-db-visualizer' );
        if ( ! el ) return;

        try {
            const data = await woamPost( 'hw_woam_get_db_stats' );
            const max  = data.total_bytes || 1; // Avoid divide-by-zero on empty DB.

            const labels = {
                posts:        'wp_posts',
                postmeta:     'wp_postmeta',
                order_items:  'woocommerce_order_items',
                order_itemmeta: 'woocommerce_order_itemmeta',
                comments:     'wp_comments',
                commentmeta:  'wp_commentmeta',
            };

            let html = '<div class="woam-db-bars">';

            for ( const [ key, info ] of Object.entries( data.tables ) ) {
                const pct   = Math.round( ( info.bytes / max ) * 100 );
                const label = labels[ key ] ?? key;

                html += `
                    <div class="woam-bar-row">
                        <span class="woam-bar-label">${ escHtml( label ) }</span>
                        <div class="woam-bar-track">
                            <div class="woam-bar-fill" style="width:${ pct }%"></div>
                        </div>
                        <span class="woam-bar-value">${ escHtml( info.formatted ) }</span>
                    </div>`;
            }

            html += `<p class="woam-db-total">
                        <strong>Total: ${ escHtml( data.total_formatted ) }</strong>
                    </p>`;
            html += '</div>';

            el.classList.remove( 'woam-loading' );
            el.innerHTML = html;

        } catch ( err ) {
            showError( el, err.message );
        }
    }

    /**
     * Fetches archive health data and renders the checklist.
     */
    async function loadArchiveHealth() {
        const el = document.getElementById( 'woam-archive-health' );
        if ( ! el ) return;

        try {
            const data = await woamPost( 'hw_woam_get_archive_health' );

            const items = [
                {
                    ok:    data.tables_ok,
                    label: data.tables_ok
                        ? 'Archive tables installed'
                        : `Missing tables: ${ data.missing_tables.join( ', ' ) }`,
                },
                {
                    ok:    data.version_ok,
                    label: data.version_ok
                        ? `Database version ${ data.installed_version } (current)`
                        : `Database version ${ data.installed_version } — upgrade needed`,
                },
                {
                    ok:    !! data.last_archive,
                    label: data.last_archive
                        ? `Last archive: ${ data.last_archive }`
                        : 'No archive run yet',
                },
                {
                    ok:    !! data.last_restore,
                    label: data.last_restore
                        ? `Last restore: ${ data.last_restore }`
                        : 'No restore run yet',
                },
                {
                    ok:    ! data.job_running,
                    label: data.job_running
                        ? 'A job is currently running'
                        : 'No job currently running',
                },
            ];

            let html = '';
            items.forEach( item => {
                const icon = item.ok
                    ? '<span class="dashicons dashicons-yes-alt woam-health-icon woam-health-icon--ok"></span>'
                    : '<span class="dashicons dashicons-warning woam-health-icon woam-health-icon--warn"></span>';

                html += `<li class="woam-checklist-item woam-checklist-item--${ item.ok ? 'ok' : 'warn' }">
                            ${ icon }
                            <span class="woam-checklist-label">${ escHtml( item.label ) }</span>
                        </li>`;
            } );

            el.classList.remove( 'woam-loading' );
            el.innerHTML = html;

        } catch ( err ) {
            showError( el, err.message );
        }
    }

    /**
     * Fetches recent activity and renders the timeline list.
    */
    async function loadRecentActivity() {
        const el = document.getElementById( 'woam-recent-activity' );
        if ( ! el ) return;

        try {
            const data = await woamPost( 'hw_woam_get_recent_activity' );

            if ( ! data.activity.length ) {
                el.classList.remove( 'woam-loading' );
                el.innerHTML = '<li class="woam-timeline-empty">No activity yet.</li>';
                return;
            }

            const actionLabels = {
                archive: 'Archived',
                restore: 'Restored',
                delete:  'Deleted',
            };

            let html = '';
            data.activity.forEach( entry => {
                const label = actionLabels[ entry.action ] ?? entry.action;
                html += `<li class="woam-timeline-item woam-timeline-item--${ escHtml( entry.action ) }">
                            <span class="woam-timeline-date">${ escHtml( entry.date_formatted ) }</span>
                            <span class="woam-timeline-text">
                                ${ escHtml( label ) } ${ formatNumber( entry.order_count ) } orders
                            </span>
                        </li>`;
            } );

            el.classList.remove( 'woam-loading' );
            el.innerHTML = html;

        } catch ( err ) {
            showError( el, err.message );
        }
    }

    /**
     * Fetches order counts and renders the Storage Impact card.
     * Fires two parallel requests — archived count and live order count.
     */
    async function loadStorageImpact() {
        const el = document.getElementById( 'woam-storage-impact' );
        if ( ! el ) return;

        try {
            const [ breakdownData, statsData ] = await Promise.all( [
                woamPost( 'hw_woam_get_archive_breakdown' ),
                woamPost( 'hw_woam_get_db_stats' ),
            ] );

            const archivedCount  = breakdownData.total_count;
            const totalDbSize    = statsData.total_formatted;
            const postmetaBytes  = statsData.tables?.postmeta?.bytes  ?? 0;
            const postsBytes     = statsData.tables?.posts?.bytes     ?? 0;
            const orderBytes     = postmetaBytes + postsBytes;
            const orderPct       = statsData.total_bytes
                ? Math.round( ( orderBytes / statsData.total_bytes ) * 100 )
                : 0;

            el.classList.remove( 'woam-loading' );
            el.innerHTML = `
                <div class="woam-impact-grid">
                    <div class="woam-impact-stat">
                        <span class="woam-impact-number">${ formatNumber( archivedCount ) }</span>
                        <span class="woam-impact-label">Orders in archive</span>
                    </div>
                    <div class="woam-impact-stat">
                        <span class="woam-impact-number">${ escHtml( totalDbSize ) }</span>
                        <span class="woam-impact-label">Total order-related DB size</span>
                    </div>
                    <div class="woam-impact-stat">
                        <span class="woam-impact-number">${ orderPct }%</span>
                        <span class="woam-impact-label">Of DB used by order data</span>
                    </div>
                </div>`;

        } catch ( err ) {
            showError( el, err.message );
        }
    }

    /**
     * Runs a batched AJAX operation until the server returns processed === 0.
     * Updates the progress bar and summary text after each batch.
     *
     * @param {Object}      opts
     * @param {string}      opts.action        wp_ajax_ action name.
     * @param {Object}      opts.payload       POST fields sent with every batch request.
     * @param {number}      opts.total         Total order count (for progress %).
     * @param {HTMLElement} opts.progressEl    The .woam-progress wrapper element.
     * @param {HTMLElement} opts.fillEl        The .woam-progress-fill bar element.
     * @param {HTMLElement} opts.textEl        The <p> progress text element.
     * @param {HTMLElement} opts.summaryEl     Container for the final result summary.
     * @param {HTMLElement} opts.startBtn      The Start button — disabled during run.
     * @returns {Promise<void>}
     */
    async function runBatchLoop( opts ) {
        const { action, payload, total, progressEl, fillEl, textEl, summaryEl, startBtn } = opts;

        let processed  = 0;
        let succeeded  = 0;
        let failed     = 0;

        startBtn.disabled = true;
        progressEl.style.display = 'block';
        summaryEl.innerHTML = '';

        try {
            while ( true ) {
                const data = await woamPost( action, payload );

                processed += data.processed;
                succeeded += data.succeeded;
                failed    += data.failed;

                // Update progress bar.
                const pct = total > 0 ? Math.min( Math.round( ( processed / total ) * 100 ), 100 ) : 100;
                fillEl.style.width = pct + '%';
                textEl.textContent = `${ formatNumber( processed ) } of ${ formatNumber( total ) } processed`;

                // Stop when the server returns an empty batch.
                if ( data.processed === 0 ) {
                    break;
                }
            }

            // Final summary.
            const dryNote = payload.dry_run ? ' <em>(dry run — no changes made)</em>' : '';
            summaryEl.innerHTML = `
                <div class="woam-summary woam-summary--${ failed > 0 ? 'warn' : 'ok' }">
                    <p><strong>${ formatNumber( succeeded ) }</strong> succeeded &nbsp;
                    <strong>${ formatNumber( failed ) }</strong> failed${ dryNote }</p>
                </div>`;

            // Mark other tabs as needing a reload.
            state.dirty = true;

        } catch ( err ) {
            summaryEl.innerHTML = `<div class="woam-summary woam-summary--error">
                <p>${ escHtml( err.message ) }</p>
            </div>`;
        } finally {
            startBtn.disabled = false;
        }
    }
    /**
     * Wires up all interactivity for Tab 2 — Archive Orders.
     * Preset buttons, step navigation, impact calculation, batch loop.
     */
    function initArchiveTab() {
        const container = document.querySelector( '.woam-steps[data-mode="archive"]' );
        if ( ! container ) return;

        // --- Preset buttons ---
        // Each button sets the date input to N months ago from today.
        container.querySelectorAll( '.woam-preset-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                const months = parseInt( btn.dataset.month );
                const d      = new Date();
                d.setMonth( d.getMonth() - months );

                // Format as YYYY-MM-DD for the date input.
                const yyyy = d.getFullYear();
                const mm   = String( d.getMonth() + 1 ).padStart( 2, '0' );
                const dd   = String( d.getDate() ).padStart( 2, '0' );

                document.getElementById( 'woam-before-date' ).value = `${ yyyy }-${ mm }-${ dd }`;

                // Highlight active preset.
                container.querySelectorAll( '.woam-preset-btn' ).forEach( b => b.classList.remove( 'woam-preset-btn--active' ) );
                btn.classList.add( 'woam-preset-btn--active' );
            } );
        } );

        // --- Step 1 → Step 2: load savings estimate ---
        document.getElementById( 'woam-archive-step1-next' ).addEventListener( 'click', async () => {
            const beforeDate = document.getElementById( 'woam-before-date' ).value;
            const statuses   = Array.from(
                container.querySelectorAll( '#woam-archive-statuses input:checked' )
            ).map( cb => cb.value );

            if ( ! beforeDate ) {
                alert( 'Please select a date before continuing.' );
                return;
            }

            if ( ! statuses.length ) {
                alert( 'Please select at least one order status.' );
                return;
            }

            const impactEl = document.getElementById( 'woam-archive-impact' );
            impactEl.classList.add( 'woam-loading' );
            impactEl.innerHTML = 'Calculating…';

            setStep( container, 2 );

            try {
                const data = await woamPost( 'hw_woam_get_savings_estimate', {
                    before_date: beforeDate,
                    statuses,
                } );

                if ( data.order_count === 0 ) {
                    impactEl.classList.remove( 'woam-loading' );
                    impactEl.innerHTML = '<p>No orders match the selected filters.</p>';
                    return;
                }

                // Cache total for progress bar.
                state.totalOrders = data.order_count;

                impactEl.classList.remove( 'woam-loading' );
                impactEl.innerHTML = `
                    <div class="woam-impact-table">
                        <div class="woam-impact-row">
                            <span>Orders</span>
                            <strong>${ formatNumber( data.order_count ) }</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order meta rows</span>
                            <strong>${ formatNumber( data.row_counts.order_meta ) }</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order item rows</span>
                            <strong>${ formatNumber( data.row_counts.order_items ) }</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order item meta rows</span>
                            <strong>${ formatNumber( data.row_counts.item_meta ) }</strong>
                        </div>
                        <div class="woam-impact-row">
                            <span>Order note rows</span>
                            <strong>${ formatNumber( data.row_counts.order_notes ) }</strong>
                        </div>
                        <div class="woam-impact-row woam-impact-row--total">
                            <span>Estimated space freed</span>
                            <strong>${ escHtml( data.estimated_size ) } <em>(approximate)</em></strong>
                        </div>
                    </div>`;

            } catch ( err ) {
                showError( impactEl, err.message );
            }
        } );

        // --- Step 2 → Step 3 ---
        document.getElementById( 'woam-archive-step2-next' ).addEventListener( 'click', () => {
            setStep( container, 3 );
        } );

        // --- Back buttons ---
        container.querySelectorAll( '[data-step-back]' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                setStep( container, parseInt( btn.dataset.stepBack ) );
            } );
        } );

        // --- Start Archive button ---
        document.getElementById( 'woam-archive-start' ).addEventListener( 'click', async () => {
            const dryRun     = document.getElementById( 'woam-archive-dry-run' ).checked;
            const confirmVal = document.getElementById( 'woam-archive-confirm' ).value.trim();

            // Confirmation gate — skip for dry runs.
            if ( ! dryRun && confirmVal !== 'ARCHIVE' ) {
                alert( 'Please type ARCHIVE to confirm.' );
                return;
            }

            const beforeDate = document.getElementById( 'woam-before-date' ).value;
            const statuses   = Array.from(
                container.querySelectorAll( '#woam-archive-statuses input:checked' )
            ).map( cb => cb.value );

            await runBatchLoop( {
                action:     'hw_woam_archive_batch',
                payload:    { before_date: beforeDate, statuses, dry_run: dryRun ? '1' : '' },
                total:      state.totalOrders,
                progressEl: document.getElementById( 'woam-archive-progress' ),
                fillEl:     document.getElementById( 'woam-archive-progress-fill' ),
                textEl:     document.getElementById( 'woam-archive-progress-text' ),
                summaryEl:  document.getElementById( 'woam-archive-summary' ),
                startBtn:   document.getElementById( 'woam-archive-start' ),
            } );
        } );
    }

    /**
     * Loads the Archive Inventory card and status checkboxes with a single AJAX call.
     * Fires on Tab 3 activation.
     */
    async function loadArchivedTab() {
        const inventoryEl = document.getElementById( 'woam-archive-inventory' );
        const statusEl    = document.getElementById( 'woam-archived-statuses' );

        try {
            const data = await woamPost( 'hw_woam_get_archive_breakdown' );
            renderArchiveInventory( inventoryEl, data );
            renderArchivedStatusCheckboxes( statusEl, data );
        } catch ( err ) {
            if ( inventoryEl ) showError( inventoryEl, err.message );
        }
    }

    /**
     * Renders the archive inventory table from breakdown data.
     *
     * @param {HTMLElement} el   The inventory container.
     * @param {Object}      data Response from hw_woam_get_archive_breakdown.
     */
    function renderArchiveInventory( el, data ) {
        if ( ! el ) return;

        if ( ! data.breakdown.length ) {
            el.classList.remove( 'woam-loading' );
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

        data.breakdown.forEach( row => {
            html += `
                <tr>
                    <td>
                        <span class="woam-status-badge woam-status-badge--${ escHtml( row.status ) }">
                            ${ escHtml( row.label ) }
                        </span>
                    </td>
                    <td><strong>${ formatNumber( row.order_count ) }</strong></td>
                </tr>`;
        } );

        html += `
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td><strong>${ formatNumber( data.total_count ) }</strong></td>
                    </tr>
                </tfoot>
            </table>`;

        el.classList.remove( 'woam-loading' );
        el.innerHTML = html;
    }

    /**
     * Renders status filter checkboxes for Tab 3 Step 1.
     * Only shows statuses actually present in the archive.
     *
     * @param {HTMLElement} el   The checkbox container.
     * @param {Object}      data Response from hw_woam_get_archive_breakdown.
     */
    function renderArchivedStatusCheckboxes( el, data ) {
        if ( ! el ) return;

        if ( ! data.breakdown.length ) {
            el.innerHTML = '<p class="woam-empty">No archived orders to filter.</p>';
            return;
        }

        let html = '';
        data.breakdown.forEach( row => {
            html += `
                <label class="woam-checkbox">
                    <input type="checkbox"
                        name="archived_statuses[]"
                        value="${ escHtml( row.status ) }" />
                    ${ escHtml( row.label ) }
                    <span class="woam-checkbox-count">(${ formatNumber( row.order_count ) })</span>
                </label>`;
        } );

        el.innerHTML = html;
    }

    /**
     * Wires up all interactivity for Tab 3 — Archived Orders.
     * Action radio, step navigation, batch loop, integrity check.
     */
    function initArchivedTab() {
        const container = document.querySelector( '.woam-steps[data-mode="archived"]' );
        if ( ! container ) return;

        // --- Radio change: show/hide confirm input and update Start button label ---
        container.querySelectorAll( 'input[name="archived_action"]' ).forEach( radio => {
            radio.addEventListener( 'change', () => {
                const isDelete     = radio.value === 'delete';
                const confirmGroup = document.getElementById( 'woam-archived-confirm-group' );
                const startBtn     = document.getElementById( 'woam-archived-start' );

                // Confirm input only required for permanent delete.
                confirmGroup.style.display = isDelete ? 'block' : 'none';

                // Clear confirm field when switching away from delete.
                if ( ! isDelete ) {
                    document.getElementById( 'woam-archived-confirm' ).value = '';
                }

                // Update button label to match action.
                startBtn.textContent = isDelete ? 'Start Deletion' : 'Start Restore';

                // Update Step 3 heading.
                const title = document.getElementById( 'woam-archived-step3-title' );
                if ( title ) {
                    title.textContent = isDelete
                        ? 'Step 3: Permanently Delete'
                        : 'Step 3: Restore Orders';
                }
            } );
        } );

        // --- Step 1 → Step 2: load count ---
        document.getElementById( 'woam-archived-step1-next' ).addEventListener( 'click', async () => {
            const action   = container.querySelector( 'input[name="archived_action"]:checked' )?.value ?? 'restore';
            const statuses = Array.from(
                container.querySelectorAll( '#woam-archived-statuses input:checked' )
            ).map( cb => cb.value );

            // Empty statuses = all archived orders.
            const impactEl = document.getElementById( 'woam-archived-impact' );
            impactEl.classList.add( 'woam-loading' );
            impactEl.innerHTML = 'Calculating…';

            setStep( container, 2 );

            try {
                // get_count works for both restore and delete — both query woam_orders.
                const data = await woamPost( 'hw_woam_get_count', {
                    mode: action,
                    statuses,
                } );

                state.totalOrders = data.count;

                impactEl.classList.remove( 'woam-loading' );

                if ( data.count === 0 ) {
                    impactEl.innerHTML = '<p>No archived orders match the selected filters.</p>';
                    return;
                }

                const actionLabel = action === 'delete' ? 'permanently deleted' : 'restored';

                impactEl.innerHTML = `
                    <div class="woam-impact-table">
                        <div class="woam-impact-row woam-impact-row--total">
                            <span>Orders to be ${ escHtml( actionLabel ) }</span>
                            <strong>${ formatNumber( data.count ) }</strong>
                        </div>
                    </div>
                    ${ action === 'delete'
                        ? '<p class="woam-warn">⚠️ This action is permanent and cannot be undone.</p>'
                        : '<p class="woam-info">Orders will be moved back to live WooCommerce tables.</p>'
                    }`;

            } catch ( err ) {
                showError( impactEl, err.message );
            }
        } );

        // --- Step 2 → Step 3 ---
        document.getElementById( 'woam-archived-step2-next' ).addEventListener( 'click', () => {
            setStep( container, 3 );
        } );

        // --- Back buttons ---
        container.querySelectorAll( '[data-step-back]' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                setStep( container, parseInt( btn.dataset.stepBack ) );
            } );
        } );

        // --- Start button ---
        document.getElementById( 'woam-archived-start' ).addEventListener( 'click', async () => {
            const action   = container.querySelector( 'input[name="archived_action"]:checked' )?.value ?? 'restore';
            const dryRun   = document.getElementById( 'woam-archived-dry-run' ).checked;
            const statuses = Array.from(
                container.querySelectorAll( '#woam-archived-statuses input:checked' )
            ).map( cb => cb.value );

            // Confirmation gate for permanent delete only.
            if ( action === 'delete' && ! dryRun ) {
                const confirmVal = document.getElementById( 'woam-archived-confirm' ).value.trim();
                if ( confirmVal !== 'DELETE' ) {
                    alert( 'Please type DELETE to confirm permanent deletion.' );
                    return;
                }
            }

            const ajaxAction = action === 'delete'
                ? 'hw_woam_delete_batch'
                : 'hw_woam_restore_batch';

            await runBatchLoop( {
                action:     ajaxAction,
                payload:    { statuses, dry_run: dryRun ? '1' : '' },
                total:      state.totalOrders,
                progressEl: document.getElementById( 'woam-archived-progress' ),
                fillEl:     document.getElementById( 'woam-archived-progress-fill' ),
                textEl:     document.getElementById( 'woam-archived-progress-text' ),
                summaryEl:  document.getElementById( 'woam-archived-summary' ),
                startBtn:   document.getElementById( 'woam-archived-start' ),
            } );

            // Reload the inventory card after batch completes.
            await loadArchivedTab();
        } );

        // --- Integrity Check button ---
        document.getElementById( 'woam-run-integrity-check' ).addEventListener( 'click', async () => {
            const btn      = document.getElementById( 'woam-run-integrity-check' );
            const resultEl = document.getElementById( 'woam-integrity-result' );

            btn.disabled = true;
            btn.textContent = 'Scanning…';
            resultEl.innerHTML = '';

            try {
                const data = await woamPost( 'hw_woam_run_integrity_check' );

                if ( data.is_healthy ) {
                    resultEl.innerHTML = `
                        <div class="woam-summary woam-summary--ok">
                            <span class="dashicons dashicons-yes-alt woam-health-icon--ok"></span>
                            <p>All ${ formatNumber( data.total_orphans === 0 ? 'archive' : '' ) } records are intact. No issues found.</p>
                        </div>`;
                } else {
                    resultEl.innerHTML = `
                        <div class="woam-summary woam-summary--warn">
                            <span class="dashicons dashicons-warning woam-health-icon--warn"></span>
                            <p><strong>${ formatNumber( data.total_orphans ) } orphaned rows found.</strong></p>
                            <ul>
                                ${ data.orphaned_meta      ? `<li>${ formatNumber( data.orphaned_meta ) } orphaned order meta rows</li>`      : '' }
                                ${ data.orphaned_items     ? `<li>${ formatNumber( data.orphaned_items ) } orphaned order item rows</li>`     : '' }
                                ${ data.orphaned_item_meta ? `<li>${ formatNumber( data.orphaned_item_meta ) } orphaned item meta rows</li>`  : '' }
                                ${ data.orphaned_notes     ? `<li>${ formatNumber( data.orphaned_notes ) } orphaned order note rows</li>`    : '' }
                                ${ data.orphaned_note_meta ? `<li>${ formatNumber( data.orphaned_note_meta ) } orphaned note meta rows</li>` : '' }
                            </ul>
                        </div>`;
                }

            } catch ( err ) {
                resultEl.innerHTML = `<p class="woam-error">${ escHtml( err.message ) }</p>`;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Run Integrity Check';
            }
        } );
    }
} )();