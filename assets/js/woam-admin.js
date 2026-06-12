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

} )();