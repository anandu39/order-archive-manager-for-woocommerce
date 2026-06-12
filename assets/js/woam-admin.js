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

} )();