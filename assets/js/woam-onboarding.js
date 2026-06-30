/**
 * Woo Order Archive Manager - Onboarding Wizard
 * Phase 5: First-time user setup and guided tour
 */

(function() {
    'use strict';

    let currentStep = 1;
    let scanData = null;
    let tourActive = false;
    let tourStep = 0;

    const tourSteps = [
        {
            target: '.woam-tab[data-tab="overview"]',
            title: 'Database Health Dashboard',
            content: 'See your store\'s health at a glance. The circular gauge shows your overall database health score.',
            position: 'bottom'
        },
        {
            target: '#woam-recommendation-card',
            title: 'Smart Recommendations',
            content: 'We analyze your store and suggest optimal orders to archive for maximum benefit.',
            position: 'top'
        },
        {
            target: '.woam-tab[data-tab="archive"]',
            title: 'Archive Orders',
            content: 'Move old orders to dedicated archive tables to speed up your admin panel.',
            position: 'bottom'
        },
        {
            target: '.woam-tab[data-tab="archived"]',
            title: 'Archive Vault',
            content: 'Restore or permanently delete archived orders from here. All operations are safe and reversible.',
            position: 'bottom'
        }
    ];

    /**
     * Format numbers with thousands separators
     */
    function formatNumber(n) {
        return parseInt(n).toLocaleString();
    }

    /**
     * Delay helper function
     */
    function delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * Initialize onboarding
     */
    function initOnboarding() {
        // Check if onboarding should be shown
        const shouldShow = document.body.classList.contains('woam-onboarding-ready');
        if (!shouldShow) return;

        createOnboardingModal();
    }

    /**
     * Create the onboarding modal
     */
    function createOnboardingModal() {
        const modal = document.createElement('div');
        modal.className = 'woam-onboarding-overlay';
        modal.id = 'woam-onboarding-modal';
        modal.innerHTML = `
            <div class="woam-onboarding-modal">
                <div class="woam-onboarding-header">
                    <span class="dashicons dashicons-archive"></span>
                    <h2>${hwWoamOnboarding.i18n.welcomeTitle}</h2>
                    <p>${hwWoamOnboarding.i18n.welcomeDesc}</p>
                </div>
                <div class="woam-onboarding-body">
                    <div class="woam-onboarding-steps">
                        <div class="woam-onboarding-step">
                            <div class="woam-step-number" data-step="1">1</div>
                            <div class="woam-step-label">Scan Store</div>
                        </div>
                        <div class="woam-step-connector"></div>
                        <div class="woam-onboarding-step">
                            <div class="woam-step-number" data-step="2">2</div>
                            <div class="woam-step-label">Review Impact</div>
                        </div>
                        <div class="woam-step-connector"></div>
                        <div class="woam-onboarding-step">
                            <div class="woam-step-number" data-step="3">3</div>
                            <div class="woam-step-label">First Archive</div>
                        </div>
                    </div>
                    <div id="woam-onboarding-step1" class="woam-onboarding-step-content active">
                        <div class="woam-scan-animation">
                            <span class="dashicons dashicons-update woam-scan-icon spin"></span>
                            <div class="woam-scan-text">${hwWoamOnboarding.i18n.scanning}</div>
                            <div class="woam-scan-subtext">Analyzing your WooCommerce store...</div>
                            <div class="woam-scan-progress">
                                <div class="woam-scan-progress-bar">
                                    <div class="woam-scan-progress-fill" style="width: 0%"></div>
                                </div>
                                <div class="woam-scan-progress-text">0%</div>
                            </div>
                        </div>
                    </div>
                    <div id="woam-onboarding-step2" class="woam-onboarding-step-content">
                        <!-- Results will be populated here -->
                    </div>
                    <div id="woam-onboarding-step3" class="woam-onboarding-step-content">
                        <!-- Final step content -->
                    </div>
                </div>
                <div class="woam-onboarding-footer">
                    <a href="#" class="woam-skip-link" data-skip>${hwWoamOnboarding.i18n.skip}</a>
                    <button type="button" class="woam-button woam-button--primary" id="woam-onboarding-next" disabled>
                        ${hwWoamOnboarding.i18n.next}
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        
        // Attach event listeners
        const nextBtn = document.getElementById('woam-onboarding-next');
        if (nextBtn) {
            nextBtn.addEventListener('click', nextStep);
        }
        
        const skipLink = document.querySelector('.woam-skip-link');
        if (skipLink) {
            skipLink.addEventListener('click', skipOnboarding);
        }
        
        // Start the scan
        simulateScan();
    }

    /**
     * Simulate scan with progress updates
     */
    async function simulateScan() {
        const progressFill = document.querySelector('.woam-scan-progress-fill');
        const progressText = document.querySelector('.woam-scan-progress-text');
        const scanText = document.querySelector('.woam-scan-text');
        const scanSubtext = document.querySelector('.woam-scan-subtext');
        
        const steps = [
            { progress: 20, text: hwWoamOnboarding.i18n.scanning, subtext: 'Checking database tables...' },
            { progress: 40, text: hwWoamOnboarding.i18n.scanning, subtext: 'Analyzing order data...' },
            { progress: 60, text: hwWoamOnboarding.i18n.analyzing, subtext: 'Calculating potential savings...' },
            { progress: 80, text: hwWoamOnboarding.i18n.analyzing, subtext: 'Generating recommendations...' },
            { progress: 100, text: hwWoamOnboarding.i18n.preparing, subtext: 'Almost ready...' }
        ];
        
        for (const step of steps) {
            await delay(800);
            if (progressFill) {
                progressFill.style.width = step.progress + '%';
            }
            if (progressText) {
                progressText.textContent = step.progress + '%';
            }
            if (scanText) {
                scanText.textContent = step.text;
            }
            if (scanSubtext) {
                scanSubtext.textContent = step.subtext;
            }
        }
        
        // Actual API call for real data
        await fetchScanData();
    }

    /**
     * Fetch real scan data from server
     */
    async function fetchScanData() {
        try {
            const response = await fetch(hwWoamOnboarding.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'hw_woam_run_initial_scan',
                    nonce: hwWoamOnboarding.nonce,
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                scanData = result.data;
                displayScanResults();
                enableNextButton();
            } else {
                throw new Error(result.data?.message || 'Scan failed');
            }
        } catch (err) {
            console.error('Scan failed:', err);
            showScanError();
        }
    }

    /**
     * Display scan results in step 2
     */
    function displayScanResults() {
        const step2Container = document.getElementById('woam-onboarding-step2');
        if (!step2Container || !scanData) return;
        
        const hasOldOrders = scanData.has_old_orders;
        
        step2Container.innerHTML = `
            <div class="woam-scan-stats">
                <div class="woam-scan-stat-row">
                    <span class="woam-scan-stat-label">Total Orders</span>
                    <span class="woam-scan-stat-value">${formatNumber(scanData.total_orders)}</span>
                </div>
                <div class="woam-scan-stat-row">
                    <span class="woam-scan-stat-label">Oldest Order</span>
                    <span class="woam-scan-stat-value">${scanData.oldest_order_formatted}</span>
                </div>
                <div class="woam-scan-stat-row">
                    <span class="woam-scan-stat-label">Database Size</span>
                    <span class="woam-scan-stat-value">${scanData.db_size_formatted}</span>
                </div>
            </div>
            ${hasOldOrders ? `
                <div class="woam-scan-highlight">
                    <span class="dashicons dashicons-chart-line"></span>
                    <span class="value">${formatNumber(scanData.archive_candidates)}</span>
                    <span>orders ready for archiving</span>
                    <div style="margin-top: 12px; font-size: 14px;">
                        Estimated savings: <strong>${scanData.estimated_savings_formatted}</strong>
                    </div>
                </div>
                <p style="margin-top: 16px; font-size: 13px; text-align: center;">
                    Archiving these orders will speed up your admin panel and reduce backup sizes.
                </p>
            ` : `
                <div class="woam-scan-highlight">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <span class="value">Great News!</span>
                    <span>Your store doesn't have old orders to archive</span>
                    <div style="margin-top: 12px; font-size: 14px;">
                        Your database is already well-maintained!
                    </div>
                </div>
            `}
        `;
        
        // Update step 3 content
        updateStep3Content();
    }

    /**
     * Update step 3 content based on scan results
     */
    function updateStep3Content() {
        const step3Container = document.getElementById('woam-onboarding-step3');
        if (!step3Container) return;
        
        if (scanData && scanData.has_old_orders) {
            step3Container.innerHTML = `
                <div class="woam-scan-stats" style="text-align: center;">
                    <span class="dashicons dashicons-archive" style="font-size: 48px; width: 48px; height: 48px; color: #7f54b3;"></span>
                    <h3 style="margin: 16px 0 8px;">Ready to Archive?</h3>
                    <p style="color: #646970; margin-bottom: 20px;">
                        We'll archive completed orders older than 12 months.
                        This is completely safe and reversible.
                    </p>
                    <div class="woam-scan-stat-row" style="background: #e8f0fe; border-radius: 8px; padding: 12px;">
                        <span class="woam-scan-stat-label">Orders to archive</span>
                        <span class="woam-scan-stat-value">${formatNumber(scanData.archive_candidates)}</span>
                    </div>
                    <div class="woam-scan-stat-row">
                        <span class="woam-scan-stat-label">Estimated space freed</span>
                        <span class="woam-scan-stat-value">${scanData.estimated_savings_formatted}</span>
                    </div>
                </div>
            `;
        } else {
            step3Container.innerHTML = `
                <div class="woam-scan-stats" style="text-align: center;">
                    <span class="dashicons dashicons-yes-alt" style="font-size: 48px; width: 48px; height: 48px; color: #2ea64a;"></span>
                    <h3 style="margin: 16px 0 8px;">Your Store is Optimized!</h3>
                    <p style="color: #646970;">
                        No action needed right now. We'll notify you when orders become eligible for archiving.
                    </p>
                    <div style="margin-top: 20px;">
                        <span class="dashicons dashicons-chart-line"></span>
                        Keep monitoring your database health from the Overview tab.
                    </div>
                </div>
            `;
        }
    }

    /**
     * Show scan error message
     */
    function showScanError() {
        const step1Container = document.getElementById('woam-onboarding-step1');
        if (step1Container) {
            step1Container.innerHTML = `
                <div class="woam-scan-animation" style="color: #d63638;">
                    <span class="dashicons dashicons-warning" style="font-size: 64px; width: 64px; height: 64px;"></span>
                    <div class="woam-scan-text">Scan Failed</div>
                    <div class="woam-scan-subtext">Please refresh the page and try again.</div>
                </div>
            `;
        }
        
        // Enable next button to allow skipping
        enableNextButton();
    }

    /**
     * Enable the next button
     */
    function enableNextButton() {
        const nextBtn = document.getElementById('woam-onboarding-next');
        if (nextBtn) {
            nextBtn.disabled = false;
        }
    }

    /**
     * Go to next step
     */
    function nextStep() {
        const stepNumbers = document.querySelectorAll('.woam-step-number');
        const stepContents = document.querySelectorAll('.woam-onboarding-step-content');
        const nextBtn = document.getElementById('woam-onboarding-next');
        
        if (currentStep < 3) {
            // Update step indicator - mark current as completed
            stepNumbers.forEach(step => {
                const stepNum = parseInt(step.dataset.step);
                if (stepNum === currentStep) {
                    step.classList.add('completed');
                }
            });
            
            // Hide current content, show next
            stepContents.forEach(content => {
                content.classList.remove('active');
            });
            
            currentStep++;
            const nextContent = document.getElementById(`woam-onboarding-step${currentStep}`);
            if (nextContent) {
                nextContent.classList.add('active');
            }
            
            // Update step labels active state
            stepNumbers.forEach(step => {
                const stepNum = parseInt(step.dataset.step);
                step.classList.remove('active');
                if (stepNum === currentStep) {
                    step.classList.add('active');
                }
            });
            
            // Update button text for last step
            if (currentStep === 3) {
                const hasOldOrders = scanData && scanData.has_old_orders;
                nextBtn.textContent = hasOldOrders ? hwWoamOnboarding.i18n.startArchiving : hwWoamOnboarding.i18n.gotIt;
            }
        } else {
            // Complete onboarding
            completeOnboarding();
        }
    }

    /**
     * Complete onboarding and start guided tour or finish
     */
    async function completeOnboarding() {
        // Mark onboarding as completed
        try {
            await fetch(hwWoamOnboarding.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'hw_woam_dismiss_onboarding',
                    nonce: hwWoamOnboarding.nonce,
                    skip: '0'
                })
            });
        } catch (err) {
            console.error('Failed to save onboarding status:', err);
        }
        
        // Remove modal
        const modal = document.getElementById('woam-onboarding-modal');
        if (modal) {
            modal.remove();
        }
        
        // If there are orders to archive, start guided tour
        if (scanData && scanData.has_old_orders) {
            startGuidedTour();
        } else {
            // Just refresh the page to load data
            location.reload();
        }
    }

    /**
     * Skip onboarding
     */
    async function skipOnboarding(event) {
        event.preventDefault();
        
        try {
            await fetch(hwWoamOnboarding.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'hw_woam_dismiss_onboarding',
                    nonce: hwWoamOnboarding.nonce,
                    skip: '1'
                })
            });
        } catch (err) {
            console.error('Failed to save skip status:', err);
        }
        
        // Remove modal
        const modal = document.getElementById('woam-onboarding-modal');
        if (modal) {
            modal.remove();
        }
        
        // Refresh the page to load normal UI
        location.reload();
    }

    /**
     * Start guided tour
     */
    function startGuidedTour() {
        tourActive = true;
        tourStep = 0;
        showTourStep();
    }

    /**
     * Show current tour step
     */
    function showTourStep() {
        if (!tourActive || tourStep >= tourSteps.length) {
            endGuidedTour();
            return;
        }
        
        const step = tourSteps[tourStep];
        const targetElement = document.querySelector(step.target);
        
        if (!targetElement) {
            // Skip this step if target not found
            tourStep++;
            showTourStep();
            return;
        }
        
        // Highlight the target element
        targetElement.classList.add('woam-tour-highlight');
        
        // Scroll to element
        targetElement.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        
        // Create tour tooltip
        const tooltip = document.createElement('div');
        tooltip.className = 'woam-guided-tour';
        tooltip.setAttribute('data-position', step.position);
        tooltip.innerHTML = `
            <h4>${step.title}</h4>
            <p>${step.content}</p>
            <div class="woam-guided-tour-buttons">
                <button class="woam-guided-tour-btn woam-guided-tour-btn-dismiss" data-dismiss>Skip Tour</button>
                <button class="woam-guided-tour-btn woam-guided-tour-btn-next" data-next>
                    ${tourStep === tourSteps.length - 1 ? 'Finish' : 'Next'}
                </button>
            </div>
        `;
        
        document.body.appendChild(tooltip);
        
        // Position tooltip relative to target
        positionTooltip(tooltip, targetElement, step.position);
        
        // Attach event listeners
        const nextBtn = tooltip.querySelector('[data-next]');
        const dismissBtn = tooltip.querySelector('[data-dismiss]');
        
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                removeTooltipAndHighlight(tooltip, targetElement);
                tourStep++;
                showTourStep();
            });
        }
        
        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                endGuidedTour();
            });
        }
    }

    /**
     * Position tooltip relative to target element
     */
    function positionTooltip(tooltip, target, position) {
        const targetRect = target.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        
        let top, left;
        
        switch (position) {
            case 'top':
                top = targetRect.top - tooltipRect.height - 10;
                left = targetRect.left + (targetRect.width / 2) - (tooltipRect.width / 2);
                break;
            case 'bottom':
                top = targetRect.bottom + 10;
                left = targetRect.left + (targetRect.width / 2) - (tooltipRect.width / 2);
                break;
            case 'left':
                top = targetRect.top + (targetRect.height / 2) - (tooltipRect.height / 2);
                left = targetRect.left - tooltipRect.width - 10;
                break;
            case 'right':
                top = targetRect.top + (targetRect.height / 2) - (tooltipRect.height / 2);
                left = targetRect.right + 10;
                break;
            default:
                top = targetRect.bottom + 10;
                left = targetRect.left + (targetRect.width / 2) - (tooltipRect.width / 2);
        }
        
        // Ensure tooltip stays within viewport
        top = Math.max(10, Math.min(top, window.innerHeight - tooltipRect.height - 10));
        left = Math.max(10, Math.min(left, window.innerWidth - tooltipRect.width - 10));
        
        tooltip.style.top = top + 'px';
        tooltip.style.left = left + 'px';
    }

    /**
     * Remove tooltip and highlight
     */
    function removeTooltipAndHighlight(tooltip, target) {
        if (tooltip && tooltip.remove) {
            tooltip.remove();
        }
        if (target) {
            target.classList.remove('woam-tour-highlight');
        }
    }

    /**
     * End guided tour
     */
    function endGuidedTour() {
        tourActive = false;
        
        // Remove all tooltips
        document.querySelectorAll('.woam-guided-tour').forEach(tooltip => {
            tooltip.remove();
        });
        
        // Remove all highlights
        document.querySelectorAll('.woam-tour-highlight').forEach(el => {
            el.classList.remove('woam-tour-highlight');
        });
        
        // Refresh the page to show final state
        location.reload();
    }

    /**
     * DOM Ready - Initialize
     */
    document.addEventListener('DOMContentLoaded', () => {
        initOnboarding();
    });

})();