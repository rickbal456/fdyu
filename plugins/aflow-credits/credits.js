/**
 * AIKAFLOW Credit System Plugin
 * 
 * Provides credit balance display, top-up flow, and history view
 */

(function () {
    'use strict';

    // Wait for AIKAFLOW to be ready
    if (!window.AIKAFLOW) {
        console.warn('[Credits] AIKAFLOW not ready');
        return;
    }

    const API_URL = window.AIKAFLOW.apiUrl;
    let creditBalance = 0;
    let currencySymbol = 'Rp';
    let isLowBalance = false;

    // Initialize
    async function init() {
        // Skip in viewer mode (unauthenticated)
        const isViewerMode = document.body.classList.contains('read-only-mode') ||
            window.location.pathname.includes('view');
        if (isViewerMode) {
            return; // Don't load credits in viewer mode
        }

        await loadBalance();
        injectUI();
        setupEventListeners();

        // Auto-refresh credits after workflow completes or node generates output
        document.addEventListener('workflow:run:complete', (e) => {
            loadBalance();
            showPushNotification('Workflow Complete', 'Your workflow has finished successfully!', 'success');
        });

        document.addEventListener('workflow:run:error', (e) => {
            loadBalance(); // Still refresh - credits may have been refunded
            showPushNotification('Workflow Failed', e.detail?.error || 'An error occurred during workflow execution.', 'error');
        });

        // Listen for explicit credit update requests (e.g., after workflow starts)
        document.addEventListener('credits:update', () => {
            // Debounce to avoid too many requests
            if (window._creditsRefreshTimeout) clearTimeout(window._creditsRefreshTimeout);
            window._creditsRefreshTimeout = setTimeout(loadBalance, 500);
        });

        document.addEventListener('node:output:generated', () => {
            // Debounce to avoid too many requests
            if (window._creditsRefreshTimeout) clearTimeout(window._creditsRefreshTimeout);
            window._creditsRefreshTimeout = setTimeout(loadBalance, 1000);
        });
    }

    // Load credit balance
    async function loadBalance() {
        try {
            const response = await fetch(`${API_URL}/credits/balance.php`);
            const data = await response.json();

            if (data.success) {
                creditBalance = data.balance;
                currencySymbol = data.currency_symbol || 'Rp';
                isLowBalance = data.low_balance;
                updateBalanceDisplay();
            }
        } catch (error) {
            console.error('[Credits] Failed to load balance:', error);
        }
    }

    // Inject UI elements
    function injectUI() {
        // Add credit balance button to topbar (before user menu)
        const userMenu = document.getElementById('user-menu-container');
        if (userMenu && !document.getElementById('credit-balance-btn')) {
            const creditBtn = document.createElement('button');
            creditBtn.id = 'credit-balance-btn';
            creditBtn.className = 'flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-dark-700 hover:bg-dark-600 transition-colors text-sm';
            creditBtn.innerHTML = `
                <i data-lucide="coins" class="w-4 h-4 text-yellow-400"></i>
                <span id="credit-balance-text" class="font-medium text-dark-100">${formatNumber(creditBalance)}</span>
            `;
            userMenu.parentNode.insertBefore(creditBtn, userMenu);

            // Initialize Lucide icon
            if (window.lucide) lucide.createIcons();
        }

        // Inject modal HTML
        if (!document.getElementById('credit-modal')) {
            injectModal();
        }
    }

    // Inject credit modal
    function injectModal() {
        const modalHTML = `
        <div id="credit-modal" class="modal hidden">
            <div class="modal-overlay" onclick="window.AikaflowCredits.closeModal()"></div>
            <div class="modal-content w-full max-w-2xl max-h-[85vh] overflow-hidden">
                <div class="modal-header flex items-center justify-between">
                    <h2 class="text-xl font-bold text-dark-50 flex items-center gap-2">
                        <i data-lucide="coins" class="w-5 h-5 text-yellow-400"></i>
                        <span data-i18n="credits.title">Credits</span>
                    </h2>
                    <button onclick="window.AikaflowCredits.closeModal()" class="p-1 hover:bg-dark-700 rounded transition-colors">
                        <i data-lucide="x" class="w-5 h-5 text-dark-300"></i>
                    </button>
                </div>
                
                <!-- Tabs -->
                <div class="flex border-b border-dark-600 overflow-x-auto scrollbar-hide">
                    <button class="credit-tab active px-4 py-3 text-sm font-medium whitespace-nowrap" data-tab="balance" data-i18n="credits.balance">Balance</button>
                    <button class="credit-tab px-4 py-3 text-sm font-medium whitespace-nowrap" data-tab="topup" data-i18n="credits.topup">Top Up</button>
                    <button class="credit-tab px-4 py-3 text-sm font-medium whitespace-nowrap" data-tab="history" data-i18n="credits.history">History</button>
                </div>

                <div class="modal-body p-0 overflow-y-auto" style="max-height: calc(85vh - 140px);">
                    <!-- Balance Tab -->
                    <div id="credit-tab-balance" class="credit-tab-content p-6">
                        <div class="text-center mb-6">
                            <div class="text-4xl font-bold text-dark-50 mb-1" id="credit-display-balance">0</div>
                            <div class="text-sm text-dark-400" data-i18n="credits.available_credits">Available Credits</div>
                        </div>
                        <div id="credit-expiring-notice" class="hidden bg-yellow-500/10 border border-yellow-500/20 rounded-lg p-3 mb-4">
                            <div class="flex items-center gap-2 text-yellow-400 text-sm">
                                <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                                <span id="credit-expiring-text"></span>
                            </div>
                        </div>
                        <div id="credit-low-notice" class="hidden bg-red-500/10 border border-red-500/20 rounded-lg p-3 mb-4">
                            <div class="flex items-center gap-2 text-red-400 text-sm">
                                <i data-lucide="alert-circle" class="w-4 h-4"></i>
                                <span data-i18n="credits.low_balance_warning">Low balance! Top up to continue using API nodes.</span>
                            </div>
                        </div>
                        
                        <!-- Node Pricing List -->
                        <div id="node-pricing-section" class="mt-6">
                            <div class="flex items-center gap-2 mb-3">
                                <i data-lucide="coins" class="w-4 h-4 text-yellow-400"></i>
                                <h4 class="text-sm font-medium text-dark-200" data-i18n="credits.credit_usage_per_node">Credit Usage Per Node</h4>
                            </div>
                            <div id="node-pricing-list" class="bg-dark-800/50 rounded-lg border border-dark-600 divide-y divide-dark-600 max-h-48 overflow-y-auto">
                                <div class="p-4 text-center text-dark-500 text-sm" data-i18n="common.loading">Loading...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Up Tab -->
                    <div id="credit-tab-topup" class="credit-tab-content hidden p-6">
                        <div id="topup-packages" class="grid gap-4 mb-6"></div>
                        
                        <!-- Coupon Code -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-dark-300 mb-2">
                                <i data-lucide="ticket" class="w-4 h-4 inline mr-1"></i>
                                <span data-i18n="credits.coupon_code_optional">Coupon Code (Optional)</span>
                            </label>
                            <div class="flex gap-2">
                                <input type="text" id="topup-coupon" class="form-input flex-1" data-i18n-placeholder="credits.enter_coupon" placeholder="Enter coupon code">
                                <button id="btn-apply-coupon" class="btn-secondary px-4" data-i18n="credits.apply">Apply</button>
                            </div>
                            <div id="coupon-result" class="mt-2 text-sm hidden"></div>
                        </div>

                        <!-- Order Summary - Enhanced -->
                        <div id="topup-summary" class="hidden mb-6">
                            <div class="bg-gradient-to-br from-dark-800 to-dark-900 rounded-xl border border-dark-600 overflow-hidden">
                                <div class="bg-dark-700/50 px-4 py-3 border-b border-dark-600">
                                    <h4 class="font-semibold text-dark-100 flex items-center gap-2">
                                        <i data-lucide="shopping-cart" class="w-4 h-4 text-primary-400"></i>
                                        <span data-i18n="credits.order_summary">Order Summary</span>
                                    </h4>
                                </div>
                                <div class="p-4 space-y-3">
                                    <div class="flex justify-between items-center">
                                        <span class="text-dark-400" data-i18n="credits.package">Package</span>
                                        <span id="summary-package" class="text-dark-100 font-medium"></span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-dark-400" data-i18n="credits.credits">Credits</span>
                                        <span id="summary-credits" class="text-yellow-400 font-semibold flex items-center gap-1">
                                            <i data-lucide="coins" class="w-4 h-4"></i>
                                            <span></span>
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center">
                                        <span class="text-dark-400" data-i18n="credits.base_price">Base Price</span>
                                        <span id="summary-price" class="text-dark-200"></span>
                                    </div>
                                    <div id="summary-discount-row" class="hidden flex justify-between items-center">
                                        <span class="text-green-400 flex items-center gap-1">
                                            <i data-lucide="percent" class="w-3 h-3"></i>
                                            <span data-i18n="credits.discount">Discount</span>
                                        </span>
                                        <span id="summary-discount" class="text-green-400 font-medium"></span>
                                    </div>
                                    <div id="summary-bonus-row" class="hidden flex justify-between items-center">
                                        <span class="text-yellow-400 flex items-center gap-1">
                                            <i data-lucide="gift" class="w-3 h-3"></i>
                                            <span data-i18n="credits.bonus_credits">Bonus Credits</span>
                                        </span>
                                        <span id="summary-bonus" class="text-yellow-400 font-medium"></span>
                                    </div>
                                    <div class="border-t border-dark-600 pt-3 mt-3">
                                        <div class="flex justify-between items-center">
                                            <span class="text-dark-100 font-semibold" data-i18n="credits.total_to_pay">Total to Pay</span>
                                            <span id="summary-total" class="text-xl font-bold text-primary-400"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Method Selection -->
                        <div id="topup-payment-methods" class="hidden mb-6">
                            <label class="block text-sm font-medium text-dark-300 mb-3">
                                <i data-lucide="wallet" class="w-4 h-4 inline mr-1"></i>
                                <span data-i18n="credits.choose_payment_method">Choose Payment Method</span>
                            </label>
                            <div id="payment-methods-list" class="space-y-2"></div>
                        </div>

                        <!-- Bank Details - Enhanced -->
                        <div id="topup-bank" class="hidden mb-6">
                            <div class="bg-gradient-to-br from-blue-900/20 to-dark-800 rounded-xl border border-blue-500/20 overflow-hidden">
                                <div class="bg-blue-500/10 px-4 py-3 border-b border-blue-500/20">
                                    <h4 class="font-semibold text-blue-300 flex items-center gap-2">
                                        <i data-lucide="landmark" class="w-4 h-4"></i>
                                        <span data-i18n="credits.bank_transfer_details">Bank Transfer Details</span>
                                    </h4>
                                </div>
                                <div class="p-4">
                                    <p class="text-sm text-dark-400 mb-4" data-i18n="credits.transfer_exact_amount">Transfer the exact amount to:</p>
                                    <div class="space-y-3">
                                        <div class="bg-dark-800/50 rounded-lg p-3 flex justify-between items-center">
                                            <div>
                                                <div class="text-xs text-dark-500 mb-1" data-i18n="credits.bank_name">Bank Name</div>
                                                <div id="bank-name" class="text-dark-100 font-medium"></div>
                                            </div>
                                            <button class="btn-ghost p-2" onclick="navigator.clipboard.writeText(document.getElementById('bank-name').textContent); window.showToast?.('Copied!', 'success')">
                                                <i data-lucide="copy" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                        <div class="bg-dark-800/50 rounded-lg p-3 flex justify-between items-center">
                                            <div>
                                                <div class="text-xs text-dark-500 mb-1" data-i18n="credits.account_number">Account Number</div>
                                                <div id="bank-account" class="text-dark-100 font-mono font-bold text-lg"></div>
                                            </div>
                                            <button class="btn-ghost p-2" onclick="navigator.clipboard.writeText(document.getElementById('bank-account').textContent); window.showToast?.('Copied!', 'success')">
                                                <i data-lucide="copy" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                        <div class="bg-dark-800/50 rounded-lg p-3 flex justify-between items-center">
                                            <div>
                                                <div class="text-xs text-dark-500 mb-1" data-i18n="credits.account_name">Account Name</div>
                                                <div id="bank-holder" class="text-dark-100 font-medium"></div>
                                            </div>
                                            <button class="btn-ghost p-2" onclick="navigator.clipboard.writeText(document.getElementById('bank-holder').textContent); window.showToast?.('Copied!', 'success')">
                                                <i data-lucide="copy" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- QRIS Payment -->
                        <div id="topup-qris" class="hidden mb-6">
                            <div class="bg-gradient-to-br from-purple-900/20 to-dark-800 rounded-xl border border-purple-500/20 overflow-hidden">
                                <div class="bg-purple-500/10 px-4 py-3 border-b border-purple-500/20">
                                    <h4 class="font-semibold text-purple-300 flex items-center gap-2">
                                        <i data-lucide="qr-code" class="w-4 h-4"></i>
                                        <span data-i18n="credits.qris_payment">QRIS Payment</span>
                                    </h4>
                                </div>
                                <div class="p-4 text-center">
                                    <p class="text-sm text-dark-400 mb-4" data-i18n="credits.scan_qr_code">Scan the QR code below with any e-wallet app</p>
                                    <div id="qris-code-display" class="inline-block bg-white p-3 rounded-xl mb-4">
                                        <div class="w-64 h-64 flex items-center justify-center text-dark-400">
                                            <i data-lucide="loader-2" class="w-8 h-8 animate-spin"></i>
                                        </div>
                                    </div>
                                    <p class="text-xs text-dark-500" data-i18n="credits.supported_ewallets">Supports all QRIS-compatible e-wallets: GoPay, OVO, DANA, ShopeePay, etc.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Proof Upload - Enhanced -->
                        <div id="topup-upload" class="hidden mb-6">
                            <label class="block text-sm font-medium text-dark-300 mb-2">
                                <i data-lucide="image" class="w-4 h-4 inline mr-1"></i>
                                <span data-i18n="credits.upload_payment_proof">Upload Payment Proof</span>
                            </label>
                            <div class="border-2 border-dashed border-dark-600 rounded-xl p-8 text-center hover:border-primary-500/50 hover:bg-dark-800/30 transition-all cursor-pointer" id="proof-dropzone">
                                <i data-lucide="upload-cloud" class="w-12 h-12 mx-auto mb-3 text-dark-500"></i>
                                <p class="text-dark-300 font-medium" data-i18n="credits.click_or_drag">Click or drag to upload</p>
                                <p class="text-xs text-dark-500 mt-1" data-i18n="credits.file_limit">PNG, JPG up to 5MB</p>
                                <input type="file" id="proof-file" class="hidden" accept="image/*">
                            </div>
                            <div id="proof-preview" class="hidden mt-4">
                                <div class="relative inline-block">
                                    <img src="" alt="Payment proof" class="max-h-48 rounded-lg border border-dark-600">
                                    <button onclick="document.getElementById('proof-preview').classList.add('hidden'); document.getElementById('proof-file').value='';" class="absolute -top-2 -right-2 bg-red-500 rounded-full p-1 hover:bg-red-600">
                                        <i data-lucide="x" class="w-4 h-4 text-white"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button id="btn-submit-topup" class="btn-primary w-full hidden py-3 text-base font-semibold">
                            <i data-lucide="send" class="w-5 h-5 inline mr-2"></i>
                            <span data-i18n="credits.submit_topup">Submit Top-up Request</span>
                        </button>
                    </div>

                    <!-- History Tab -->
                    <div id="credit-tab-history" class="credit-tab-content hidden">
                        <div id="credit-history-list" class="divide-y divide-dark-700"></div>
                        <div id="credit-history-loading" class="hidden p-8 text-center">
                            <i data-lucide="loader-2" class="w-8 h-8 mx-auto mb-3 text-primary-400 animate-spin"></i>
                            <p class="text-dark-400" data-i18n="credits.loading_transactions">Loading transactions...</p>
                        </div>
                        <div id="credit-history-empty" class="p-8 text-center hidden">
                            <div class="bg-dark-800/50 rounded-2xl p-8 max-w-sm mx-auto">
                                <div class="w-16 h-16 bg-dark-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i data-lucide="scroll-text" class="w-8 h-8 text-dark-500"></i>
                                </div>
                                <h4 class="text-dark-200 font-semibold mb-2" data-i18n="credits.no_transaction_history">No Transaction History</h4>
                                <p class="text-dark-500 text-sm mb-4" data-i18n="credits.transaction_history_desc">Your credit transactions will appear here after you make a top-up or use credits for API calls.</p>
                                <button onclick="window.AikaflowCredits.switchTab?.('topup')" class="btn-primary px-4 py-2 text-sm">
                                    <i data-lucide="plus" class="w-4 h-4 inline mr-1"></i>
                                    <span data-i18n="credits.topup_now">Top Up Now</span>
                                </button>
                            </div>
                        </div>
                        <div id="credit-history-pagination" class="p-4 flex justify-center gap-2"></div>
                    </div>
                </div>
            </div>
        </div>
        `;

        document.getElementById('modals-container')?.insertAdjacentHTML('beforeend', modalHTML);
        if (window.lucide) lucide.createIcons();
        // Translate the injected modal
        if (window.I18n?.translatePage) window.I18n.translatePage();
    }

    // Update balance display
    function updateBalanceDisplay() {
        const balanceText = document.getElementById('credit-balance-text');
        const displayBalance = document.getElementById('credit-display-balance');
        const btn = document.getElementById('credit-balance-btn');

        if (balanceText) balanceText.textContent = formatNumber(creditBalance);
        if (displayBalance) displayBalance.textContent = formatNumber(creditBalance);

        // Low balance indicator
        if (btn) {
            if (isLowBalance) {
                btn.classList.add('ring-2', 'ring-red-500/50');
            } else {
                btn.classList.remove('ring-2', 'ring-red-500/50');
            }
        }

        const lowNotice = document.getElementById('credit-low-notice');
        if (lowNotice) {
            lowNotice.classList.toggle('hidden', !isLowBalance);
        }
    }

    // Format number with thousands separator
    function formatNumber(num) {
        return new Intl.NumberFormat().format(Math.floor(num));
    }

    // Format currency
    function formatCurrency(amount) {
        return currencySymbol + ' ' + formatNumber(amount);
    }

    // Setup event listeners
    function setupEventListeners() {
        // Credit button click
        document.getElementById('credit-balance-btn')?.addEventListener('click', openModal);

        // Tab switching
        document.querySelectorAll('.credit-tab').forEach(tab => {
            tab.addEventListener('click', () => switchTab(tab.dataset.tab));
        });

        // Apply coupon
        document.getElementById('btn-apply-coupon')?.addEventListener('click', applyCoupon);

        // Proof upload
        const dropzone = document.getElementById('proof-dropzone');
        const proofInput = document.getElementById('proof-file');

        dropzone?.addEventListener('click', () => proofInput?.click());
        proofInput?.addEventListener('change', handleProofUpload);

        // Submit top-up
        document.getElementById('btn-submit-topup')?.addEventListener('click', submitTopup);
    }

    /**
     * Show a browser push notification (if enabled by user)
     * @param {string} title - Notification title
     * @param {string} body - Notification body
     * @param {string} type - 'success' or 'error'
     */
    function showPushNotification(title, body, type = 'info') {
        // Check if notifications are supported and permitted
        if (!('Notification' in window)) {
            return;
        }

        // Check if page is hidden (user is in another tab/app)
        if (document.visibilityState === 'visible') {
            // Page is visible, skip push notification (user can see the toast)
            return;
        }

        if (Notification.permission === 'granted') {
            const icon = type === 'success'
                ? '/assets/images/success-icon.png'
                : type === 'error'
                    ? '/assets/images/error-icon.png'
                    : '/assets/images/logo.png';

            const notification = new Notification(title, {
                body,
                icon,
                badge: '/assets/images/logo.png',
                tag: 'aikaflow-workflow-status',
                requireInteraction: false,
                silent: false
            });

            // Focus the window when notification is clicked
            notification.onclick = () => {
                window.focus();
                notification.close();
            };

            // Auto-close after 5 seconds
            setTimeout(() => notification.close(), 5000);
        } else if (Notification.permission === 'default') {
            // Request permission for future notifications
            Notification.requestPermission();
        }
    }

    // State
    let selectedPackage = null;
    let appliedCoupon = null;
    let packagesData = [];
    let banksData = [];
    let qrisString = null;
    let selectedPaymentMethod = null; // 'bank-{id}', 'qris', or 'paypal'
    let nodeCostsData = [];
    let paypalConfig = { enabled: false, sandbox: true, client_id: null };
    let paypalSdkLoaded = false;

    // Open modal
    async function openModal() {
        document.getElementById('credit-modal')?.classList.remove('hidden');
        await loadBalance();
        await loadPackages(); // Load packages to get node costs
        updateBalanceDisplay();
        switchTab('balance');
    }

    // Close modal
    function closeModal() {
        document.getElementById('credit-modal')?.classList.add('hidden');
    }

    // Switch tab
    function switchTab(tabName) {
        document.querySelectorAll('.credit-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.credit-tab-content').forEach(c => c.classList.add('hidden'));

        document.querySelector(`.credit-tab[data-tab="${tabName}"]`)?.classList.add('active');
        document.getElementById(`credit-tab-${tabName}`)?.classList.remove('hidden');

        if (tabName === 'balance') renderNodeCosts();
        if (tabName === 'topup') loadPackages();
        if (tabName === 'history') loadHistory();
    }

    // Load packages
    async function loadPackages() {
        try {
            const response = await fetch(`${API_URL}/credits/topup.php`);
            const data = await response.json();

            if (data.success) {
                packagesData = data.packages;
                banksData = data.banks || [];
                qrisString = data.qris_string || null;
                nodeCostsData = data.node_costs || [];
                paypalConfig = data.paypal || { enabled: false };
                renderPackages(data.packages);
                renderPaymentMethods();
                renderNodeCosts();
            }
        } catch (error) {
            console.error('[Credits] Failed to load packages:', error);
        }
    }

    // Render node costs list
    function renderNodeCosts() {
        const container = document.getElementById('node-pricing-list');
        const section = document.getElementById('node-pricing-section');
        if (!container) return;

        // Hide section if no node costs
        if (!nodeCostsData || nodeCostsData.length === 0) {
            if (section) section.classList.add('hidden');
            return;
        }

        if (section) section.classList.remove('hidden');

        container.innerHTML = nodeCostsData.map(node => `
            <div class="flex items-center justify-between p-3 hover:bg-dark-700/30 transition-colors">
                <div class="flex-1">
                    <div class="text-sm font-medium text-dark-100">${node.node_name || node.node_type}</div>
                    ${node.description ? `<div class="text-xs text-dark-500 mt-0.5">${node.description}</div>` : ''}
                </div>
                <div class="text-sm font-semibold text-yellow-400 flex items-center gap-1">
                    <i data-lucide="coins" class="w-3.5 h-3.5"></i>
                    ${formatNumber(node.cost_per_call)}
                </div>
            </div>
        `).join('');

        lucide?.createIcons();
    }

    // Render payment method options
    function renderPaymentMethods() {
        const container = document.getElementById('payment-methods-list');
        if (!container) return;

        let html = '';

        // Bank options
        banksData.forEach(bank => {
            html += `
                <div class="payment-method-option p-3 border border-dark-600 rounded-lg cursor-pointer hover:border-primary-500/50 transition-all" 
                     data-method="bank-${bank.id}" onclick="window.AikaflowCredits.selectPaymentMethod('bank-${bank.id}')">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="landmark" class="w-5 h-5 text-blue-400"></i>
                        </div>
                        <div class="flex-1">
                            <div class="font-medium text-dark-100">${bank.bank_name}</div>
                            <div class="text-xs text-dark-500">${bank.account_number} • ${bank.account_holder}</div>
                        </div>
                        <div class="payment-method-check hidden">
                            <i data-lucide="check-circle" class="w-5 h-5 text-primary-400"></i>
                        </div>
                    </div>
                </div>
            `;
        });

        // QRIS option
        if (qrisString) {
            html += `
                <div class="payment-method-option p-3 border border-dark-600 rounded-lg cursor-pointer hover:border-primary-500/50 transition-all" 
                     data-method="qris" onclick="window.AikaflowCredits.selectPaymentMethod('qris')">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-purple-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="qr-code" class="w-5 h-5 text-purple-400"></i>
                        </div>
                        <div class="flex-1">
                            <div class="font-medium text-dark-100">QRIS</div>
                            <div class="text-xs text-dark-500">Scan QR code with any e-wallet</div>
                        </div>
                        <div class="payment-method-check hidden">
                            <i data-lucide="check-circle" class="w-5 h-5 text-primary-400"></i>
                        </div>
                    </div>
                </div>
            `;
        }

        // PayPal option
        if (paypalConfig.enabled && paypalConfig.client_id) {
            html += `
                <div class="payment-method-option p-3 border border-dark-600 rounded-lg cursor-pointer hover:border-primary-500/50 transition-all" 
                     data-method="paypal" onclick="window.AikaflowCredits.selectPaymentMethod('paypal')">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-500/10 rounded-lg flex items-center justify-center">
                            <i data-lucide="credit-card" class="w-5 h-5 text-blue-400"></i>
                        </div>
                        <div class="flex-1">
                            <div class="font-medium text-dark-100">PayPal</div>
                            <div class="text-xs text-dark-500">Instant credit • Credit/Debit Card</div>
                        </div>
                        <div class="payment-method-check hidden">
                            <i data-lucide="check-circle" class="w-5 h-5 text-primary-400"></i>
                        </div>
                    </div>
                </div>
            `;
        }

        if (!banksData.length && !qrisString && !(paypalConfig.enabled && paypalConfig.client_id)) {
            html = '<div class="text-center py-4 text-dark-500 text-sm">No payment methods available</div>';
        }

        container.innerHTML = html;
        lucide?.createIcons();
    }

    // Select payment method
    function selectPaymentMethod(method) {
        selectedPaymentMethod = method;

        // Update UI
        document.querySelectorAll('.payment-method-option').forEach(el => {
            el.classList.remove('border-primary-500', 'bg-primary-500/5');
            el.querySelector('.payment-method-check')?.classList.add('hidden');
        });

        const selected = document.querySelector(`.payment-method-option[data-method="${method}"]`);
        if (selected) {
            selected.classList.add('border-primary-500', 'bg-primary-500/5');
            selected.querySelector('.payment-method-check')?.classList.remove('hidden');
        }

        // Show appropriate payment details
        document.getElementById('topup-bank')?.classList.add('hidden');
        document.getElementById('topup-qris')?.classList.add('hidden');

        if (method.startsWith('bank-')) {
            const bankId = method.replace('bank-', '');
            const bank = banksData.find(b => String(b.id) === bankId);
            if (bank) {
                document.getElementById('bank-name').textContent = bank.bank_name;
                document.getElementById('bank-account').textContent = bank.account_number;
                document.getElementById('bank-holder').textContent = bank.account_holder;
                document.getElementById('topup-bank')?.classList.remove('hidden');
            }
        } else if (method === 'qris') {
            // Generate and show QRIS
            generateQRIS();
            document.getElementById('topup-qris')?.classList.remove('hidden');
        } else if (method === 'paypal') {
            // Show PayPal button container
            initPayPalPayment();
            return; // Don't show upload section for PayPal
        }

        // Show upload section (not for PayPal)
        document.getElementById('topup-upload')?.classList.remove('hidden');
        document.getElementById('btn-submit-topup')?.classList.remove('hidden');
        document.getElementById('topup-paypal')?.classList.add('hidden');
    }

    // Initialize PayPal payment
    async function initPayPalPayment() {
        if (!selectedPackage) {
            window.showToast?.('Please select a package first', 'error');
            return;
        }

        // Hide upload section, show PayPal container
        document.getElementById('topup-upload')?.classList.add('hidden');
        document.getElementById('btn-submit-topup')?.classList.add('hidden');

        // Create PayPal container if it doesn't exist
        let paypalContainer = document.getElementById('topup-paypal');
        if (!paypalContainer) {
            const topupTab = document.getElementById('credit-tab-topup');
            const uploadDiv = document.getElementById('topup-upload');
            paypalContainer = document.createElement('div');
            paypalContainer.id = 'topup-paypal';
            paypalContainer.className = 'mb-6';
            paypalContainer.innerHTML = `
                <div class="bg-gradient-to-br from-blue-900/20 to-dark-800 rounded-xl border border-blue-500/20 overflow-hidden">
                    <div class="bg-blue-500/10 px-4 py-3 border-b border-blue-500/20">
                        <h4 class="font-semibold text-blue-300 flex items-center gap-2">
                            <i data-lucide="credit-card" class="w-4 h-4"></i>
                            PayPal Checkout
                        </h4>
                    </div>
                    <div class="p-4">
                        <p class="text-sm text-dark-400 mb-4">Click below to pay with PayPal or Credit/Debit Card</p>
                        <div id="paypal-button-container" class="min-h-[50px]"></div>
                    </div>
                </div>
            `;
            uploadDiv?.parentNode?.insertBefore(paypalContainer, uploadDiv);
            if (window.lucide) lucide.createIcons();
        }
        paypalContainer.classList.remove('hidden');

        // Load PayPal SDK if not loaded
        await loadPayPalSDK();

        // Render PayPal buttons
        renderPayPalButtons();
    }

    // Load PayPal SDK dynamically
    function loadPayPalSDK() {
        return new Promise((resolve, reject) => {
            if (paypalSdkLoaded && window.paypal) {
                resolve();
                return;
            }

            // Remove existing script if any
            const existing = document.querySelector('script[src*="paypal.com/sdk"]');
            if (existing) existing.remove();

            const script = document.createElement('script');
            // PayPal SDK always uses same URL - sandbox/production is determined by Client ID
            const sdkUrl = `https://www.paypal.com/sdk/js?client-id=${paypalConfig.client_id}&currency=USD`;
            script.src = sdkUrl;
            script.onload = () => {
                paypalSdkLoaded = true;
                resolve();
            };
            script.onerror = () => reject(new Error('Failed to load PayPal SDK'));
            document.head.appendChild(script);
        });
    }

    // Render PayPal buttons
    function renderPayPalButtons() {
        const container = document.getElementById('paypal-button-container');
        if (!container || !window.paypal) {
            console.error('[Credits] PayPal not ready');
            return;
        }

        container.innerHTML = '';

        window.paypal.Buttons({
            style: {
                layout: 'vertical',
                color: 'blue',
                shape: 'rect',
                label: 'pay'
            },
            createOrder: async () => {
                try {
                    const response = await fetch(`${API_URL}/payments/paypal-create.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            package_id: selectedPackage.id,
                            coupon_code: appliedCoupon?.code || null
                        })
                    });
                    const data = await response.json();
                    if (data.success) {
                        return data.order_id;
                    } else {
                        throw new Error(data.error || 'Failed to create order');
                    }
                } catch (error) {
                    console.error('[Credits] PayPal create order error:', error);
                    window.showToast?.('Failed to create PayPal order', 'error');
                    throw error;
                }
            },
            onApprove: async (data) => {
                try {
                    const response = await fetch(`${API_URL}/payments/paypal-capture.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ order_id: data.orderID })
                    });
                    const result = await response.json();
                    if (result.success) {
                        window.showToast?.(`Payment successful! ${result.credits_added} credits added.`, 'success');
                        closeModal();
                        loadBalance(); // Refresh balance
                    } else {
                        throw new Error(result.error || 'Payment capture failed');
                    }
                } catch (error) {
                    console.error('[Credits] PayPal capture error:', error);
                    window.showToast?.(error.message || 'Payment failed', 'error');
                }
            },
            onCancel: () => {
                window.showToast?.('Payment cancelled', 'info');
            },
            onError: (err) => {
                console.error('[Credits] PayPal error:', err);
                window.showToast?.('PayPal payment error', 'error');
            }
        }).render('#paypal-button-container');
    }

    // Generate QRIS QR code
    function generateQRIS() {
        if (!qrisString) {
            console.error('[Credits] QRIS string not available');
            document.getElementById('qris-code-display').innerHTML =
                '<div class="text-red-400 text-sm p-4">QRIS not configured</div>';
            return;
        }

        if (!selectedPackage) {
            console.error('[Credits] No package selected');
            return;
        }

        // selectedPackage is already the package object
        const pkg = selectedPackage;

        // Calculate final amount
        let finalAmount = parseFloat(pkg.price);
        if (appliedCoupon) {
            if (appliedCoupon.type === 'percentage') {
                finalAmount = finalAmount * (1 - appliedCoupon.value / 100);
            } else if (appliedCoupon.type === 'fixed_discount') {
                finalAmount = Math.max(0, finalAmount - appliedCoupon.value);
            }
        }

        try {
            if (window.AIKAFLOW_QRIS) {
                const qrDataURL = window.AIKAFLOW_QRIS.generateSimple(qrisString, {
                    nominal: Math.round(finalAmount),
                    size: 256
                });
                window.AIKAFLOW_QRIS.display(qrDataURL, '#qris-code-display');
            } else {
                console.error('[Credits] AIKAFLOW_QRIS not loaded');
                document.getElementById('qris-code-display').innerHTML =
                    '<div class="text-red-400 text-sm p-4">QR generator not available. Please refresh the page.</div>';
            }
        } catch (error) {
            console.error('[Credits] Failed to generate QRIS:', error);
            document.getElementById('qris-code-display').innerHTML =
                '<div class="text-red-400 text-sm p-4">Failed to generate QR code: ' + error.message + '</div>';
        }
    }

    // Render packages
    function renderPackages(packages) {
        const container = document.getElementById('topup-packages');
        if (!container) return;

        container.innerHTML = packages.map(pkg => `
            <div class="package-card p-4 bg-dark-800 rounded-lg border-2 border-dark-600 hover:border-primary-500 cursor-pointer transition-all" 
                 data-package-id="${pkg.id}">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <h4 class="font-semibold text-dark-50">${pkg.name}</h4>
                        <p class="text-sm text-dark-400">${pkg.description || ''}</p>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-bold text-dark-50">${formatCurrency(pkg.price)}</div>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-yellow-400 font-medium">${formatNumber(pkg.credits)} credits</span>
                    ${pkg.bonus_credits > 0 ? `<span class="text-green-400">+${formatNumber(pkg.bonus_credits)} bonus</span>` : ''}
                </div>
            </div>
        `).join('');

        // Package selection
        container.querySelectorAll('.package-card').forEach(card => {
            card.addEventListener('click', () => selectPackage(parseInt(card.dataset.packageId)));
        });
    }

    // Select package
    function selectPackage(packageId) {
        // Use loose comparison or convert to same type
        selectedPackage = packagesData.find(p => String(p.id) === String(packageId));

        document.querySelectorAll('.package-card').forEach(card => {
            card.classList.toggle('border-primary-500', parseInt(card.dataset.packageId) === packageId);
            card.classList.toggle('border-dark-600', parseInt(card.dataset.packageId) !== packageId);
        });

        updateSummary();

        // Show summary and payment methods
        document.getElementById('topup-summary')?.classList.remove('hidden');
        document.getElementById('topup-payment-methods')?.classList.remove('hidden');

        // Hide payment details until a method is selected
        document.getElementById('topup-bank')?.classList.add('hidden');
        document.getElementById('topup-qris')?.classList.add('hidden');
        document.getElementById('topup-upload')?.classList.add('hidden');
        document.getElementById('btn-submit-topup')?.classList.add('hidden');

        // Reset selected payment method
        selectedPaymentMethod = null;
        document.querySelectorAll('.payment-method-option').forEach(el => {
            el.classList.remove('border-primary-500', 'bg-primary-500/5');
            el.querySelector('.payment-method-check')?.classList.add('hidden');
        });

        if (window.lucide) lucide.createIcons();
    }

    // Update order summary
    function updateSummary() {
        if (!selectedPackage) return;

        const discount = appliedCoupon?.type !== 'bonus_credits' ? (appliedCoupon?.discount || 0) : 0;
        const bonusFromCoupon = appliedCoupon?.type === 'bonus_credits' ? appliedCoupon.value : 0;
        const totalCredits = selectedPackage.credits + selectedPackage.bonus_credits + bonusFromCoupon;
        const finalPrice = Math.max(0, selectedPackage.price - discount);

        document.getElementById('summary-package').textContent = selectedPackage.name;
        document.getElementById('summary-credits').textContent = formatNumber(totalCredits);
        document.getElementById('summary-price').textContent = formatCurrency(selectedPackage.price);
        document.getElementById('summary-total').textContent = formatCurrency(finalPrice);

        const discountRow = document.getElementById('summary-discount-row');
        const bonusRow = document.getElementById('summary-bonus-row');

        if (discount > 0) {
            discountRow?.classList.remove('hidden');
            document.getElementById('summary-discount').textContent = '-' + formatCurrency(discount);
        } else {
            discountRow?.classList.add('hidden');
        }

        if (selectedPackage.bonus_credits > 0 || bonusFromCoupon > 0) {
            bonusRow?.classList.remove('hidden');
            document.getElementById('summary-bonus').textContent = '+' + formatNumber(selectedPackage.bonus_credits + bonusFromCoupon);
        } else {
            bonusRow?.classList.add('hidden');
        }
    }

    // Apply coupon
    async function applyCoupon() {
        const code = document.getElementById('topup-coupon')?.value.trim();
        const resultEl = document.getElementById('coupon-result');

        if (!code) {
            appliedCoupon = null;
            resultEl?.classList.add('hidden');
            updateSummary();
            return;
        }

        try {
            const response = await fetch(`${API_URL}/credits/coupon.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    code,
                    package_id: selectedPackage?.id
                })
            });

            const data = await response.json();

            if (data.success) {
                appliedCoupon = {
                    code,
                    type: data.type,
                    value: data.value,
                    discount: data.preview.discount,
                    bonus_credits: data.preview.bonus_credits
                };

                resultEl.innerHTML = `<span class="text-green-400">✓ ${data.description}</span>`;
                resultEl?.classList.remove('hidden');
                updateSummary();
            } else {
                resultEl.innerHTML = `<span class="text-red-400">✗ ${data.error}</span>`;
                resultEl?.classList.remove('hidden');
                appliedCoupon = null;
            }
        } catch (error) {
            console.error('[Credits] Coupon error:', error);
        }
    }

    // Handle proof upload
    function handleProofUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (e) => {
            const preview = document.getElementById('proof-preview');
            preview.querySelector('img').src = e.target.result;
            preview?.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    }

    // Submit top-up request
    async function submitTopup() {
        if (!selectedPackage) {
            window.showToast?.('Please select a package', 'error');
            return;
        }

        if (!selectedPaymentMethod) {
            window.showToast?.('Please select a payment method', 'error');
            return;
        }

        const proofFile = document.getElementById('proof-file')?.files[0];
        if (!proofFile) {
            window.showToast?.('Please upload payment proof first', 'error');
            // Highlight the upload area
            const dropzone = document.getElementById('proof-dropzone');
            if (dropzone) {
                dropzone.classList.add('border-red-500', 'animate-pulse');
                setTimeout(() => dropzone.classList.remove('border-red-500', 'animate-pulse'), 2000);
            }
            return;
        }

        const btn = document.getElementById('btn-submit-topup');
        const originalBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 inline mr-2 animate-spin"></i>Submitting...';
        if (window.lucide) lucide.createIcons();

        try {
            const formData = new FormData();
            formData.append('package_id', selectedPackage.id);
            formData.append('payment_proof', proofFile);
            if (appliedCoupon) formData.append('coupon_code', appliedCoupon.code);

            const response = await fetch(`${API_URL}/credits/topup.php`, {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Show success toast BEFORE closing modal
                window.showToast?.('Top-up request submitted successfully! Please wait for admin approval.', 'success', 5000);

                // Reset form state
                selectedPackage = null;
                appliedCoupon = null;
                selectedPaymentMethod = null;

                // Reset form UI
                document.getElementById('proof-file').value = '';
                document.getElementById('proof-preview')?.classList.add('hidden');
                document.querySelectorAll('.package-card').forEach(c => c.classList.remove('border-primary-500'));
                document.querySelectorAll('.payment-method-option').forEach(el => {
                    el.classList.remove('border-primary-500', 'bg-primary-500/5');
                    el.querySelector('.payment-method-check')?.classList.add('hidden');
                });

                // Close modal after a short delay so user sees the success message
                setTimeout(() => closeModal(), 500);
            } else {
                window.showToast?.(data.error || 'Failed to submit', 'error');
            }
        } catch (error) {
            console.error('[Credits] Submit error:', error);
            window.showToast?.('Failed to submit request', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalBtnHtml;
            if (window.lucide) lucide.createIcons();
        }
    }

    // Load history
    let historyPage = 1;

    async function loadHistory(page = 1) {
        historyPage = page;
        const listEl = document.getElementById('credit-history-list');
        const loadingEl = document.getElementById('credit-history-loading');
        const emptyEl = document.getElementById('credit-history-empty');
        const paginationEl = document.getElementById('credit-history-pagination');

        // Hide empty state, show loading
        emptyEl?.classList.add('hidden');
        paginationEl?.classList.add('hidden');
        loadingEl?.classList.remove('hidden');
        if (listEl) listEl.innerHTML = '';

        try {
            const response = await fetch(`${API_URL}/credits/history.php?page=${page}&limit=20`);
            const data = await response.json();

            loadingEl?.classList.add('hidden');

            if (data.success && data.transactions && data.transactions.length > 0) {
                // Explicitly hide empty state and show list
                if (emptyEl) emptyEl.style.display = 'none';
                if (listEl) listEl.style.display = 'block';
                renderHistory(data.transactions);
                renderPagination(data.pagination);
            } else {
                // Show empty state
                emptyEl?.classList.remove('hidden');
                // Initialize icons in empty state
                if (window.lucide && emptyEl) {
                    lucide.createIcons({ nodes: [emptyEl] });
                }
            }
        } catch (error) {
            console.error('[Credits] History error:', error);
            loadingEl?.classList.add('hidden');
            // Show empty state on error too
            emptyEl?.classList.remove('hidden');
            if (window.lucide && emptyEl) {
                lucide.createIcons({ nodes: [emptyEl] });
            }
        }
    }

    // Render history
    function renderHistory(transactions) {
        const listEl = document.getElementById('credit-history-list');

        if (!listEl) {
            console.error('[Credits] credit-history-list element not found!');
            return;
        }

        // Helper function to get node display name from slug
        const getNodeDisplayName = (description) => {
            if (!description) return '-';

            // Check if description matches "Node execution: {node-type}" pattern
            const nodeExecMatch = description.match(/^Node execution:\s*(.+)$/i);
            if (nodeExecMatch) {
                const nodeType = nodeExecMatch[1].trim();

                // Try to find name from nodeCostsData first
                const nodeCost = nodeCostsData.find(n => n.node_type === nodeType);
                if (nodeCost && nodeCost.node_name) {
                    return `Node execution: ${nodeCost.node_name}`;
                }

                // Try to find name from global NodeDefinitions
                if (window.NodeDefinitions && window.NodeDefinitions[nodeType]) {
                    return `Node execution: ${window.NodeDefinitions[nodeType].name || nodeType}`;
                }

                // Try PluginManager
                if (window.PluginManager) {
                    const def = window.PluginManager.getNodeDefinition?.(nodeType);
                    if (def && def.name) {
                        return `Node execution: ${def.name}`;
                    }
                }
            }

            return description;
        };

        const html = transactions.map(tx => {
            const isPositive = parseFloat(tx.amount) > 0;
            const typeLabels = {
                topup: 'Top Up',
                usage: 'Usage',
                bonus: 'Bonus',
                refund: 'Refund',
                adjustment: 'Adjustment',
                expired: 'Expired',
                welcome: 'Welcome'
            };
            const typeColors = {
                topup: 'text-green-400',
                usage: 'text-red-400',
                bonus: 'text-yellow-400',
                refund: 'text-blue-400',
                adjustment: 'text-purple-400',
                expired: 'text-gray-400',
                welcome: 'text-green-400'
            };

            const displayDescription = getNodeDisplayName(tx.description);

            return `
            <div class="p-4 hover:bg-dark-800/50">
                <div class="flex justify-between items-start">
                    <div>
                        <span class="text-xs px-2 py-0.5 rounded ${typeColors[tx.type] || 'text-gray-400'} bg-dark-700">${typeLabels[tx.type] || tx.type}</span>
                        <p class="text-sm text-dark-200 mt-1">${displayDescription}</p>
                        <p class="text-xs text-dark-500 mt-1">${new Date(tx.created_at).toLocaleString()}</p>
                    </div>
                    <div class="text-right">
                        <div class="font-semibold ${isPositive ? 'text-green-400' : 'text-red-400'}">
                            ${isPositive ? '+' : ''}${formatNumber(tx.amount)}
                        </div>
                        <div class="text-xs text-dark-500">Balance: ${formatNumber(tx.balance_after)}</div>
                    </div>
                </div>
            </div>
            `;
        }).join('');

        listEl.innerHTML = html;
    }

    // Render pagination
    function renderPagination(pagination) {
        const container = document.getElementById('credit-history-pagination');
        if (!container || pagination.pages <= 1) {
            container?.classList.add('hidden');
            return;
        }

        container.classList.remove('hidden');

        const currentPage = pagination.page;
        const totalPages = pagination.pages;

        let html = '';

        // Previous button
        html += `<button class="px-3 py-1.5 rounded ${currentPage === 1 ? 'bg-dark-800 text-dark-600 cursor-not-allowed' : 'bg-dark-700 text-dark-300 hover:bg-dark-600'}" 
                         ${currentPage === 1 ? 'disabled' : `onclick="window.AikaflowCredits.loadHistory(${currentPage - 1})"`}>
                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                </button>`;

        // Calculate visible page range (max 5 pages)
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, startPage + 4);
        if (endPage - startPage < 4) {
            startPage = Math.max(1, endPage - 4);
        }

        // First page + ellipsis
        if (startPage > 1) {
            html += `<button class="px-3 py-1.5 rounded bg-dark-700 text-dark-300 hover:bg-dark-600" 
                             onclick="window.AikaflowCredits.loadHistory(1)">1</button>`;
            if (startPage > 2) {
                html += `<span class="px-2 py-1.5 text-dark-500">...</span>`;
            }
        }

        // Page numbers
        for (let i = startPage; i <= endPage; i++) {
            html += `<button class="px-3 py-1.5 rounded ${i === currentPage ? 'bg-primary-600 text-white' : 'bg-dark-700 text-dark-300 hover:bg-dark-600'}" 
                             onclick="window.AikaflowCredits.loadHistory(${i})">${i}</button>`;
        }

        // Ellipsis + last page
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="px-2 py-1.5 text-dark-500">...</span>`;
            }
            html += `<button class="px-3 py-1.5 rounded bg-dark-700 text-dark-300 hover:bg-dark-600" 
                             onclick="window.AikaflowCredits.loadHistory(${totalPages})">${totalPages}</button>`;
        }

        // Next button
        html += `<button class="px-3 py-1.5 rounded ${currentPage === totalPages ? 'bg-dark-800 text-dark-600 cursor-not-allowed' : 'bg-dark-700 text-dark-300 hover:bg-dark-600'}" 
                         ${currentPage === totalPages ? 'disabled' : `onclick="window.AikaflowCredits.loadHistory(${currentPage + 1})"`}>
                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </button>`;

        // Page info
        html += `<span class="text-xs text-dark-500 ml-2">Page ${currentPage} of ${totalPages}</span>`;

        container.innerHTML = html;
        if (window.lucide) lucide.createIcons();
    }

    // Expose API
    window.AikaflowCredits = {
        init,
        openModal,
        closeModal,
        loadBalance,
        loadHistory,
        switchTab,
        selectPaymentMethod,
        getBalance: () => creditBalance
    };

    // Auto-init when DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
