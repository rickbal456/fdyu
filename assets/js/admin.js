/**
 * AIKAFLOW Admin & Profile Management
 * 
 * Handles profile updates, admin panel, and user management.
 */

(function () {
    'use strict';

    // ===== Profile Management =====

    document.getElementById('btn-save-profile')?.addEventListener('click', async () => {
        const username = document.getElementById('profile-username')?.value?.trim();
        const email = document.getElementById('profile-email')?.value?.trim();
        const currentPassword = document.getElementById('profile-current-password')?.value;
        const newPassword = document.getElementById('profile-new-password')?.value;

        const btn = document.getElementById('btn-save-profile');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        try {
            // Update profile (username/email)
            if (username || email) {
                const profileData = {};
                if (username) profileData.username = username;
                if (email) profileData.email = email;

                const response = await fetch(`${window.AIKAFLOW.apiUrl}/user/settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(profileData)
                });

                const result = await response.json();
                if (!result.success) {
                    throw new Error(result.error || 'Failed to update profile');
                }
            }

            // Change password if provided
            if (currentPassword && newPassword) {
                const pwResponse = await fetch(`${window.AIKAFLOW.apiUrl}/user/change-password.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        current_password: currentPassword,
                        new_password: newPassword
                    })
                });

                const pwResult = await pwResponse.json();
                if (!pwResult.success) {
                    throw new Error(pwResult.error || 'Failed to change password');
                }

                // Clear password fields
                document.getElementById('profile-current-password').value = '';
                document.getElementById('profile-new-password').value = '';
            }

            window.showToast?.('Profile updated successfully', 'success');

        } catch (error) {
            console.error('Profile update error:', error);
            window.showToast?.(error.message || 'Failed to update profile', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save Profile';
        }
    });

    // ===== Return to Admin (if impersonating) =====

    document.getElementById('btn-return-to-admin')?.addEventListener('click', async () => {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/login-as.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ return_to_admin: true })
            });

            const result = await response.json();
            if (!result.success) throw new Error(result.error);

            window.showToast?.('Returning to admin account...', 'success');
            setTimeout(() => location.reload(), 500);

        } catch (error) {
            console.error('Return to admin error:', error);
            window.showToast?.(error.message, 'error');
        }
    });

    // ===== Admin Panel (if admin) =====

    if (!window.AIKAFLOW?.user?.isAdmin) return;

    // Open admin modal
    document.getElementById('menu-admin')?.addEventListener('click', () => {
        document.getElementById('user-dropdown')?.classList.add('hidden');
        window.Modals.open('modal-admin');
        loadAdminUsers();
        loadSiteSettings();
    });

    // Admin tabs
    document.querySelectorAll('.admin-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.admin-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            document.querySelectorAll('.admin-content').forEach(c => c.classList.add('hidden'));
            document.getElementById(`admin-${tab.dataset.tab}`)?.classList.remove('hidden');

            // Load data for credits tab
            if (tab.dataset.tab === 'credits') {
                loadCreditSettings();
                loadAvailableNodes();
                window.loadAdminPendingRequests?.();
                window.loadNodeCosts?.();
                window.loadPackages?.();
                window.loadCoupons?.();
            }

            // Load data for integrations tab
            if (tab.dataset.tab === 'integrations') {
                window.loadIntegrationKeys?.();
            }
        });
    });

    // Credit sub-tabs
    document.querySelectorAll('.credit-subtab').forEach(subtab => {
        subtab.addEventListener('click', () => {
            document.querySelectorAll('.credit-subtab').forEach(t => t.classList.remove('active', 'bg-primary-600', 'text-white'));
            document.querySelectorAll('.credit-subtab').forEach(t => t.classList.add('text-dark-300', 'hover:bg-dark-700'));
            subtab.classList.add('active', 'bg-primary-600', 'text-white');
            subtab.classList.remove('text-dark-300', 'hover:bg-dark-700');

            document.querySelectorAll('.credit-subtab-content').forEach(c => c.classList.add('hidden'));
            document.getElementById(`credit-subtab-${subtab.dataset.subtab}`)?.classList.remove('hidden');

            // Load data for specific subtabs
            if (subtab.dataset.subtab === 'banks') {
                window.loadBanks?.();
            }

            // Refresh icons
            lucide?.createIcons();
        });
    });

    // Load available node types for dropdown
    async function loadAvailableNodes() {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/plugins/nodes.php`);
            const result = await response.json();

            const select = document.getElementById('new-node-type-select');
            if (!select) return;

            select.innerHTML = '<option value="">-- Select a node --</option>';

            if (result.success && result.nodes) {
                result.nodes.forEach(node => {
                    const option = document.createElement('option');
                    option.value = node.type;
                    option.textContent = `${node.name} (${node.type})`;
                    select.appendChild(option);
                });
            }
        } catch (error) {
            console.error('Failed to load nodes:', error);
        }
    }

    // User pagination state
    let userCurrentPage = 1;
    let userSearchQuery = '';
    let userTotalPages = 1;

    // Load users list with pagination and search
    async function loadAdminUsers(page = 1, search = userSearchQuery) {
        const container = document.getElementById('admin-users-list');
        if (!container) return;

        userCurrentPage = page;
        userSearchQuery = search;

        container.innerHTML = '<div class="text-center py-8 text-dark-400"><i data-lucide="loader" class="w-6 h-6 mx-auto animate-spin"></i></div>';
        lucide?.createIcons();

        try {
            const params = new URLSearchParams({ page, limit: 20 });
            if (search) params.set('search', search);

            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/users.php?${params}`);
            const result = await response.json();

            if (!result.success) throw new Error(result.error);

            userTotalPages = result.pagination?.totalPages || 1;

            if (result.users.length === 0) {
                container.innerHTML = '<p class="text-dark-400 text-center py-4">No users found</p>';
                renderUserPagination(container.parentElement);
                return;
            }

            container.innerHTML = result.users.map(user => `
                <div class="flex items-center justify-between p-3 bg-dark-700 rounded-lg">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-white font-medium flex-shrink-0">
                            ${user.username.charAt(0).toUpperCase()}
                        </div>
                        <div class="min-w-0">
                            <div class="font-medium text-dark-100">${user.username}</div>
                            <div class="text-xs text-dark-400 admin-user-email">${user.email}</div>
                        </div>
                        ${user.id == 1 ? '<span class="text-xs bg-primary-500/20 text-primary-400 px-2 py-0.5 rounded">Super Admin</span>' :
                    user.role === 'admin' ? '<span class="text-xs bg-blue-500/20 text-blue-400 px-2 py-0.5 rounded">Admin</span>' : ''}
                    </div>
                    <div class="flex items-center gap-2">
                        ${user.id != window.AIKAFLOW.user.id ? `
                            <button class="btn-secondary text-xs px-2 py-1" onclick="loginAsUser(${user.id})" title="Login as user">
                                <i data-lucide="log-in" class="w-3 h-3"></i>
                            </button>
                        ` : ''}
                        <button class="btn-secondary text-xs px-2 py-1" onclick="editUser(${user.id}, '${user.username}', '${user.email}', '${user.role}')">
                            <i data-lucide="edit" class="w-3 h-3"></i>
                        </button>
                        ${user.id != 1 && user.id != window.AIKAFLOW.user.id ? `
                            <button class="btn-secondary text-xs px-2 py-1 text-red-400 hover:bg-red-500/20" onclick="deleteUser(${user.id}, '${user.username}')">
                                <i data-lucide="trash-2" class="w-3 h-3"></i>
                            </button>
                        ` : ''}
                    </div>
                </div>
            `).join('');

            renderUserPagination(container.parentElement);
            lucide?.createIcons();

        } catch (error) {
            console.error('Load users error:', error);
            container.innerHTML = `<p class="text-red-400 text-center py-4">${error.message}</p>`;
        }
    }

    // Render pagination controls for users
    function renderUserPagination(parent) {
        let paginationEl = parent?.querySelector('.user-pagination');
        if (!paginationEl) {
            paginationEl = document.createElement('div');
            paginationEl.className = 'user-pagination flex items-center justify-between mt-4 pt-4 border-t border-dark-700';
            parent?.appendChild(paginationEl);
        }

        paginationEl.innerHTML = `
            <button class="btn-secondary text-xs px-3 py-1 ${userCurrentPage <= 1 ? 'opacity-50 cursor-not-allowed' : ''}" 
                    onclick="loadAdminUsers(${userCurrentPage - 1})" ${userCurrentPage <= 1 ? 'disabled' : ''}>
                <i data-lucide="chevron-left" class="w-3 h-3 inline"></i> Prev
            </button>
            <span class="text-xs text-dark-400">Page ${userCurrentPage} of ${userTotalPages}</span>
            <button class="btn-secondary text-xs px-3 py-1 ${userCurrentPage >= userTotalPages ? 'opacity-50 cursor-not-allowed' : ''}" 
                    onclick="loadAdminUsers(${userCurrentPage + 1})" ${userCurrentPage >= userTotalPages ? 'disabled' : ''}>
                Next <i data-lucide="chevron-right" class="w-3 h-3 inline"></i>
            </button>
        `;
        lucide?.createIcons({ nodes: [paginationEl] });
    }

    // Expose loadAdminUsers globally for pagination buttons
    window.loadAdminUsers = loadAdminUsers;

    // Debounced search for users
    let userSearchTimeout = null;
    document.getElementById('admin-user-search')?.addEventListener('input', (e) => {
        clearTimeout(userSearchTimeout);
        userSearchTimeout = setTimeout(() => {
            loadAdminUsers(1, e.target.value.trim());
        }, 300);
    });

    // Load site settings
    async function loadSiteSettings() {
        // Helper to safely set element value
        const setFieldValue = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.value = value;
        };

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/settings.php`);
            const result = await response.json();

            if (result.success && result.settings) {
                // General
                setFieldValue('admin-site-title', result.settings.site_title || 'AIKAFLOW');
                setFieldValue('admin-logo-url-dark', result.settings.logo_url_dark || '');
                setFieldValue('admin-logo-url-light', result.settings.logo_url_light || '');
                setFieldValue('admin-favicon-url', result.settings.favicon_url || '');
                setFieldValue('admin-default-theme', result.settings.default_theme || 'dark');

                // Show dark mode logo preview if exists
                const logoUrlDark = result.settings.logo_url_dark;
                if (logoUrlDark) {
                    const preview = document.getElementById('logo-dark-preview');
                    if (preview) {
                        preview.querySelector('img').src = logoUrlDark;
                        preview.classList.remove('hidden');
                    }
                }

                // Show light mode logo preview if exists
                const logoUrlLight = result.settings.logo_url_light;
                if (logoUrlLight) {
                    const preview = document.getElementById('logo-light-preview');
                    if (preview) {
                        preview.querySelector('img').src = logoUrlLight;
                        preview.classList.remove('hidden');
                    }
                }

                // Show favicon preview if exists
                const faviconUrl = result.settings.favicon_url;
                if (faviconUrl) {
                    const preview = document.getElementById('favicon-preview');
                    if (preview) {
                        preview.querySelector('img').src = faviconUrl;
                        preview.classList.remove('hidden');
                    }
                }

                // Registration & Security
                const regToggle = document.getElementById('admin-registration-enabled');
                if (regToggle) regToggle.checked = result.settings.registration_enabled === '1';

                const hcaptchaToggle = document.getElementById('admin-hcaptcha-enabled');
                if (hcaptchaToggle) hcaptchaToggle.checked = result.settings.hcaptcha_enabled === '1';

                setFieldValue('admin-hcaptcha-site-key', result.settings.hcaptcha_site_key || '');
                setFieldValue('admin-hcaptcha-secret-key', result.settings.hcaptcha_secret_key || '');

                // Toggle hCaptcha fields visibility
                toggleFieldVisibility('hcaptcha-fields', result.settings.hcaptcha_enabled === '1');

                // SMTP Settings
                const smtpToggle = document.getElementById('admin-smtp-enabled');
                if (smtpToggle) smtpToggle.checked = result.settings.smtp_enabled === '1';

                setFieldValue('admin-smtp-host', result.settings.smtp_host || '');
                setFieldValue('admin-smtp-port', result.settings.smtp_port || '587');
                setFieldValue('admin-smtp-username', result.settings.smtp_username || '');
                setFieldValue('admin-smtp-password', result.settings.smtp_password || '');
                setFieldValue('admin-smtp-encryption', result.settings.smtp_encryption || 'tls');
                setFieldValue('admin-smtp-from-email', result.settings.smtp_from_email || '');
                setFieldValue('admin-smtp-from-name', result.settings.smtp_from_name || '');

                // Toggle SMTP fields visibility
                toggleFieldVisibility('smtp-fields', result.settings.smtp_enabled === '1');

                // Google OAuth Settings
                const googleToggle = document.getElementById('admin-google-auth-enabled');
                if (googleToggle) googleToggle.checked = result.settings.google_auth_enabled === '1';

                setFieldValue('admin-google-client-id', result.settings.google_client_id || '');
                setFieldValue('admin-google-client-secret', result.settings.google_client_secret || '');

                // Toggle Google OAuth fields visibility
                toggleFieldVisibility('google-auth-fields', result.settings.google_auth_enabled === '1');

                // Email Templates
                setFieldValue('admin-email-welcome-subject', result.settings.email_welcome_subject || '');
                const welcomeBody = document.getElementById('admin-email-welcome-body');
                if (welcomeBody) welcomeBody.value = (result.settings.email_welcome_body || '').replace(/\\n/g, '\n');

                setFieldValue('admin-email-forgot-subject', result.settings.email_forgot_password_subject || '');
                const forgotBody = document.getElementById('admin-email-forgot-body');
                if (forgotBody) forgotBody.value = (result.settings.email_forgot_password_body || '').replace(/\\n/g, '\n');

                // Legal Pages
                const termsEl = document.getElementById('admin-terms-of-service');
                if (termsEl) termsEl.value = (result.settings.terms_of_service || '').replace(/\\n/g, '\n');

                const privacyEl = document.getElementById('admin-privacy-policy');
                if (privacyEl) privacyEl.value = (result.settings.privacy_policy || '').replace(/\\n/g, '\n');

                // Headway
                setFieldValue('admin-headway-widget-id', result.settings.headway_widget_id || '');

                // Custom Scripts
                const customFooterJs = document.getElementById('admin-custom-footer-js');
                if (customFooterJs) customFooterJs.value = (result.settings.custom_footer_js || '').replace(/\\n/g, '\n');

                // Credit Settings
                setFieldValue('admin-credit-currency', result.settings.credit_currency || 'IDR');
                setFieldValue('admin-credit-symbol', result.settings.credit_currency_symbol || 'Rp');
                setFieldValue('admin-credit-welcome', result.settings.credit_welcome_amount || '100');
                setFieldValue('admin-credit-threshold', result.settings.credit_low_threshold || '100');
                setFieldValue('admin-credit-bank-name', result.settings.credit_bank_name || '');
                setFieldValue('admin-credit-bank-account', result.settings.credit_bank_account || '');
                setFieldValue('admin-credit-bank-holder', result.settings.credit_bank_holder || '');

                // Workflow Settings
                setFieldValue('admin-max-repeat-count', result.settings.max_repeat_count || '100');

                // Invitation System Settings
                const invitationToggle = document.getElementById('admin-invitation-enabled');
                if (invitationToggle) invitationToggle.checked = result.settings.invitation_enabled === '1';

                setFieldValue('admin-invitation-referrer-credits', result.settings.invitation_referrer_credits || '50');
                setFieldValue('admin-invitation-referee-credits', result.settings.invitation_referee_credits || '50');

                // Toggle invitation fields visibility
                toggleFieldVisibility('invitation-fields', result.settings.invitation_enabled === '1');

                // PayPal Settings
                const paypalToggle = document.getElementById('admin-paypal-enabled');
                if (paypalToggle) paypalToggle.checked = result.settings.paypal_enabled === '1';

                const paypalSandbox = document.getElementById('admin-paypal-sandbox');
                if (paypalSandbox) paypalSandbox.checked = result.settings.paypal_sandbox === '1';

                setFieldValue('admin-paypal-client-id', result.settings.paypal_client_id || '');
                setFieldValue('admin-paypal-secret-key', result.settings.paypal_secret_key || '');

                toggleFieldVisibility('paypal-fields', result.settings.paypal_enabled === '1');

                // WhatsApp Verification Settings
                const whatsappToggle = document.getElementById('admin-whatsapp-verification-enabled');
                if (whatsappToggle) whatsappToggle.checked = result.settings.whatsapp_verification_enabled === '1';

                setFieldValue('admin-whatsapp-api-url', result.settings.whatsapp_api_url || '');
                setFieldValue('admin-whatsapp-api-method', result.settings.whatsapp_api_method || 'GET');

                const waMessage = document.getElementById('admin-whatsapp-verification-message');
                if (waMessage) waMessage.value = (result.settings.whatsapp_verification_message || '').replace(/\\n/g, '\n');

                toggleFieldVisibility('whatsapp-verification-fields', result.settings.whatsapp_verification_enabled === '1');
            }
        } catch (error) {
            console.error('Load site settings error:', error);
        }
    }

    // Helper function to toggle field visibility
    function toggleFieldVisibility(fieldId, show) {
        const field = document.getElementById(fieldId);
        if (field) {
            field.classList.toggle('hidden', !show);
        }
    }

    // hCaptcha toggle
    document.getElementById('admin-hcaptcha-enabled')?.addEventListener('change', (e) => {
        toggleFieldVisibility('hcaptcha-fields', e.target.checked);
    });

    // SMTP toggle
    document.getElementById('admin-smtp-enabled')?.addEventListener('change', (e) => {
        toggleFieldVisibility('smtp-fields', e.target.checked);
    });

    // Google OAuth toggle
    document.getElementById('admin-google-auth-enabled')?.addEventListener('change', (e) => {
        toggleFieldVisibility('google-auth-fields', e.target.checked);
    });

    // Invitation System toggle
    document.getElementById('admin-invitation-enabled')?.addEventListener('change', (e) => {
        toggleFieldVisibility('invitation-fields', e.target.checked);
    });

    // PayPal toggle
    document.getElementById('admin-paypal-enabled')?.addEventListener('change', (e) => {
        toggleFieldVisibility('paypal-fields', e.target.checked);
    });

    // WhatsApp Verification toggle
    document.getElementById('admin-whatsapp-verification-enabled')?.addEventListener('change', (e) => {
        toggleFieldVisibility('whatsapp-verification-fields', e.target.checked);
    });

    // Send Test Email button
    document.getElementById('btn-smtp-test-email')?.addEventListener('click', async () => {
        const testEmail = document.getElementById('admin-smtp-test-email')?.value?.trim();

        if (!testEmail) {
            window.showToast?.('Please enter an email address to send test', 'warning');
            return;
        }

        const btn = document.getElementById('btn-smtp-test-email');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Sending...';

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/test-email.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: testEmail })
            });

            const result = await response.json();

            if (result.success) {
                window.showToast?.(result.message || 'Test email sent successfully', 'success');
            } else {
                throw new Error(result.error || 'Failed to send test email');
            }
        } catch (error) {
            console.error('Test email error:', error);
            window.showToast?.(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
        }
    });

    // Logo (Dark Mode) upload button
    document.getElementById('btn-upload-logo-dark')?.addEventListener('click', () => {
        document.getElementById('logo-dark-file-input')?.click();
    });

    // Logo (Dark Mode) file input change
    document.getElementById('logo-dark-file-input')?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', 'logo_dark');

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/upload.php`, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success && result.url) {
                document.getElementById('admin-logo-url-dark').value = result.url;
                const preview = document.getElementById('logo-dark-preview');
                preview.querySelector('img').src = result.url;
                preview.classList.remove('hidden');
                window.showToast?.('Dark mode logo uploaded successfully', 'success');
            } else {
                throw new Error(result.error || 'Upload failed');
            }
        } catch (error) {
            console.error('Logo upload error:', error);
            window.showToast?.(error.message, 'error');
        }
    });

    // Logo (Light Mode) upload button
    document.getElementById('btn-upload-logo-light')?.addEventListener('click', () => {
        document.getElementById('logo-light-file-input')?.click();
    });

    // Logo (Light Mode) file input change
    document.getElementById('logo-light-file-input')?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', 'logo_light');

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/upload.php`, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success && result.url) {
                document.getElementById('admin-logo-url-light').value = result.url;
                const preview = document.getElementById('logo-light-preview');
                preview.querySelector('img').src = result.url;
                preview.classList.remove('hidden');
                window.showToast?.('Light mode logo uploaded successfully', 'success');
            } else {
                throw new Error(result.error || 'Upload failed');
            }
        } catch (error) {
            console.error('Logo upload error:', error);
            window.showToast?.(error.message, 'error');
        }
    });

    // Favicon upload button
    document.getElementById('btn-upload-favicon')?.addEventListener('click', () => {
        document.getElementById('favicon-file-input')?.click();
    });

    // Favicon file input change
    document.getElementById('favicon-file-input')?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', 'favicon');

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/upload.php`, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success && result.url) {
                document.getElementById('admin-favicon-url').value = result.url;
                const preview = document.getElementById('favicon-preview');
                if (preview) {
                    preview.querySelector('img').src = result.url;
                    preview.classList.remove('hidden');
                }
                window.showToast?.('Favicon uploaded successfully', 'success');
            } else {
                throw new Error(result.error || 'Upload failed');
            }
        } catch (error) {
            console.error('Favicon upload error:', error);
            window.showToast?.(error.message, 'error');
        }
    });

    // Save site settings
    document.getElementById('btn-admin-save-site')?.addEventListener('click', async () => {
        const btn = document.getElementById('btn-admin-save-site');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/settings.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    // General
                    site_title: document.getElementById('admin-site-title')?.value,
                    logo_url_dark: document.getElementById('admin-logo-url-dark')?.value,
                    logo_url_light: document.getElementById('admin-logo-url-light')?.value,
                    favicon_url: document.getElementById('admin-favicon-url')?.value,
                    default_theme: document.getElementById('admin-default-theme')?.value,
                    // hCaptcha
                    hcaptcha_enabled: document.getElementById('admin-hcaptcha-enabled')?.checked ? '1' : '0',
                    hcaptcha_site_key: document.getElementById('admin-hcaptcha-site-key')?.value,
                    hcaptcha_secret_key: document.getElementById('admin-hcaptcha-secret-key')?.value,
                    // SMTP
                    smtp_enabled: document.getElementById('admin-smtp-enabled')?.checked ? '1' : '0',
                    smtp_host: document.getElementById('admin-smtp-host')?.value,
                    smtp_port: document.getElementById('admin-smtp-port')?.value,
                    smtp_username: document.getElementById('admin-smtp-username')?.value,
                    smtp_password: document.getElementById('admin-smtp-password')?.value,
                    smtp_from_email: document.getElementById('admin-smtp-from-email')?.value,
                    smtp_from_name: document.getElementById('admin-smtp-from-name')?.value,
                    smtp_encryption: document.getElementById('admin-smtp-encryption')?.value,
                    // Google OAuth
                    google_auth_enabled: document.getElementById('admin-google-auth-enabled')?.checked ? '1' : '0',
                    google_client_id: document.getElementById('admin-google-client-id')?.value,
                    google_client_secret: document.getElementById('admin-google-client-secret')?.value,
                    // Email Templates
                    email_welcome_subject: document.getElementById('admin-email-welcome-subject')?.value,
                    email_welcome_body: document.getElementById('admin-email-welcome-body')?.value?.replace(/\n/g, '\\n'),
                    email_forgot_password_subject: document.getElementById('admin-email-forgot-subject')?.value,
                    email_forgot_password_body: document.getElementById('admin-email-forgot-body')?.value?.replace(/\n/g, '\\n'),
                    // Legal
                    terms_of_service: document.getElementById('admin-terms-of-service')?.value?.replace(/\n/g, '\\n'),
                    privacy_policy: document.getElementById('admin-privacy-policy')?.value?.replace(/\n/g, '\\n'),
                    // Headway
                    headway_widget_id: document.getElementById('admin-headway-widget-id')?.value,
                    // Custom Scripts
                    custom_footer_js: document.getElementById('admin-custom-footer-js')?.value?.replace(/\n/g, '\\n'),
                    // Credits
                    credit_currency: document.getElementById('admin-credit-currency')?.value,
                    credit_currency_symbol: document.getElementById('admin-credit-symbol')?.value,
                    credit_welcome_amount: document.getElementById('admin-credit-welcome')?.value,
                    credit_low_threshold: document.getElementById('admin-credit-threshold')?.value,
                    credit_bank_name: document.getElementById('admin-credit-bank-name')?.value,
                    credit_bank_account: document.getElementById('admin-credit-bank-account')?.value,
                    credit_bank_holder: document.getElementById('admin-credit-bank-holder')?.value,

                    // Workflow Settings
                    max_repeat_count: document.getElementById('admin-max-repeat-count')?.value,
                    // Invitation System
                    invitation_enabled: document.getElementById('admin-invitation-enabled')?.checked ? '1' : '0',
                    invitation_referrer_credits: document.getElementById('admin-invitation-referrer-credits')?.value,
                    invitation_referee_credits: document.getElementById('admin-invitation-referee-credits')?.value,
                    // PayPal
                    paypal_enabled: document.getElementById('admin-paypal-enabled')?.checked ? '1' : '0',
                    paypal_sandbox: document.getElementById('admin-paypal-sandbox')?.checked ? '1' : '0',
                    paypal_client_id: document.getElementById('admin-paypal-client-id')?.value,
                    paypal_secret_key: document.getElementById('admin-paypal-secret-key')?.value,
                    // WhatsApp Verification
                    whatsapp_verification_enabled: document.getElementById('admin-whatsapp-verification-enabled')?.checked ? '1' : '0',
                    whatsapp_api_url: document.getElementById('admin-whatsapp-api-url')?.value,
                    whatsapp_api_method: document.getElementById('admin-whatsapp-api-method')?.value,
                    whatsapp_verification_message: document.getElementById('admin-whatsapp-verification-message')?.value?.replace(/\n/g, '\\n')
                })
            });

            const result = await response.json();
            if (!result.success) throw new Error(result.error);

            window.showToast?.('Site settings saved', 'success');

            // Show saved state on button
            btn.textContent = 'Saved!';
            btn.style.backgroundImage = 'none'; // Overwrite gradient
            btn.classList.add('bg-green-600', 'hover:bg-green-700');

            setTimeout(() => {
                btn.textContent = 'Save Site Settings';
                btn.style.backgroundImage = ''; // Restore gradient
                btn.classList.remove('bg-green-600', 'hover:bg-green-700');
                btn.disabled = false;
            }, 2000);

        } catch (error) {
            console.error('Save site settings error:', error);
            window.showToast?.(error.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Save Site Settings';
        }
    });

    // Add user button
    document.getElementById('btn-admin-add-user')?.addEventListener('click', () => {
        document.getElementById('admin-user-title').textContent = 'Add User';
        document.getElementById('admin-user-id').value = '';
        document.getElementById('admin-user-username').value = '';
        document.getElementById('admin-user-email').value = '';
        document.getElementById('admin-user-whatsapp').value = '';
        document.getElementById('admin-user-password').value = '';
        document.getElementById('admin-user-password').placeholder = 'Required for new users';
        document.getElementById('admin-user-role').value = 'user';
        document.getElementById('admin-user-credits-container')?.classList.add('hidden');
        document.getElementById('admin-user-credits').value = '';

        window.Modals.open('modal-admin-user', {
            onClose: () => window.Modals.open('modal-admin')
        });
    });

    // Edit user (global function for inline onclick)
    window.editUser = async function (id, username, email, role) {
        document.getElementById('admin-user-title').textContent = 'Edit User';
        document.getElementById('admin-user-id').value = id;
        document.getElementById('admin-user-username').value = username;
        document.getElementById('admin-user-email').value = email;
        document.getElementById('admin-user-password').value = '';
        document.getElementById('admin-user-password').placeholder = 'Leave blank to keep current';
        document.getElementById('admin-user-role').value = role;
        document.getElementById('admin-user-credits').value = '';

        // Fetch and populate WhatsApp number
        try {
            const userResponse = await fetch(`${window.AIKAFLOW.apiUrl}/admin/users.php?action=get&id=${id}`);
            const userData = await userResponse.json();
            if (userData.success && userData.user) {
                document.getElementById('admin-user-whatsapp').value = userData.user.whatsapp_phone || '';
            }
        } catch (error) {
            console.error('Failed to fetch user WhatsApp:', error);
            document.getElementById('admin-user-whatsapp').value = '';
        }

        // Show credits container and fetch current balance
        document.getElementById('admin-user-credits-container')?.classList.remove('hidden');
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=user-balance&user_id=${id}`);
            const data = await response.json();
            document.getElementById('admin-user-current-credits').textContent = data.success ? new Intl.NumberFormat().format(data.balance) : '0';
        } catch (e) {
            document.getElementById('admin-user-current-credits').textContent = '0';
        }

        window.Modals.open('modal-admin-user', {
            onClose: () => window.Modals.open('modal-admin')
        });
    };

    // Delete user (global function for inline onclick)
    window.deleteUser = async function (id, username) {
        if (!confirm(`Are you sure you want to delete user "${username}"?`)) return;

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/users.php`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });

            const result = await response.json();
            if (!result.success) throw new Error(result.error);

            window.showToast?.('User deleted', 'success');
            loadAdminUsers();

        } catch (error) {
            console.error('Delete user error:', error);
            window.showToast?.(error.message, 'error');
        }
    };

    // Login as user (global function for inline onclick)
    window.loginAsUser = async function (userId) {
        if (!confirm('Login as this user? You can return to your admin session later.')) return;

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/login-as.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userId })
            });

            const result = await response.json();
            if (!result.success) throw new Error(result.error);

            window.showToast?.(`Now logged in as ${result.username}`, 'success');

            // Reload page to reflect new session
            setTimeout(() => location.reload(), 1000);

        } catch (error) {
            console.error('Login as user error:', error);
            window.showToast?.(error.message, 'error');
        }
    };

    // Save user (create or update)
    document.getElementById('btn-admin-save-user')?.addEventListener('click', async () => {
        const userId = document.getElementById('admin-user-id')?.value;
        const isEdit = !!userId;

        const data = {
            username: document.getElementById('admin-user-username')?.value?.trim(),
            email: document.getElementById('admin-user-email')?.value?.trim(),
            whatsapp_phone: document.getElementById('admin-user-whatsapp')?.value?.trim(),
            role: document.getElementById('admin-user-role')?.value,
            password: document.getElementById('admin-user-password')?.value
        };

        if (isEdit) {
            data.id = parseInt(userId);
            if (!data.password) delete data.password; // Don't send empty password
        }

        if (!data.username || !data.email) {
            window.showToast?.('Username and email are required', 'error');
            return;
        }

        if (!isEdit && !data.password) {
            window.showToast?.('Password is required for new users', 'error');
            return;
        }

        const btn = document.getElementById('btn-admin-save-user');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/users.php`, {
                method: isEdit ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();
            if (!result.success) throw new Error(result.error);

            // Adjust credits if specified (for edit only)
            if (isEdit) {
                const creditAdjust = parseFloat(document.getElementById('admin-user-credits')?.value || 0);
                if (creditAdjust !== 0) {
                    await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=adjust`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            user_id: parseInt(userId),
                            amount: creditAdjust,
                            reason: 'Admin adjustment'
                        })
                    });
                }
            }

            window.showToast?.(isEdit ? 'User updated' : 'User created', 'success');

            // Close current modal (which will trigger onClose to re-open admin modal)
            window.Modals.close('modal-admin-user');

            // Refresh list
            loadAdminUsers();

        } catch (error) {
            console.error('Save user error:', error);
            window.showToast?.(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Save User';
        }
    });


    // ===== Credit System Management =====

    // Topup pagination state
    let topupCurrentPage = 1;
    let topupSearchQuery = '';
    let topupTotalPages = 1;
    let topupCurrentStatus = 'all';

    // Load Topup Requests with server-side pagination
    window.loadAdminPendingRequests = async function (page = 1, search = topupSearchQuery) {
        const listEl = document.getElementById('admin-pending-requests');
        if (!listEl) return;

        topupCurrentPage = page;
        topupSearchQuery = search;
        topupCurrentStatus = document.getElementById('topup-request-filter')?.value || 'all';

        listEl.innerHTML = '<div class="text-center py-8 text-dark-400"><i data-lucide="loader" class="w-6 h-6 mx-auto animate-spin"></i></div>';
        lucide?.createIcons();

        try {
            let allRequests = [];
            let totalCount = 0;
            const limit = 20;

            if (topupCurrentStatus === 'all') {
                // Fetch first page of each status (limited results for 'all' view)
                const [pendingRes, approvedRes, rejectedRes] = await Promise.all([
                    fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=pending&status=pending&page=1&limit=10${search ? '&search=' + encodeURIComponent(search) : ''}`),
                    fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=pending&status=approved&page=1&limit=10${search ? '&search=' + encodeURIComponent(search) : ''}`),
                    fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=pending&status=rejected&page=1&limit=10${search ? '&search=' + encodeURIComponent(search) : ''}`)
                ]);

                const [pendingData, approvedData, rejectedData] = await Promise.all([
                    pendingRes.json(), approvedRes.json(), rejectedRes.json()
                ]);

                allRequests = [
                    ...(pendingData.success ? pendingData.requests.map(r => ({ ...r, status: 'pending' })) : []),
                    ...(approvedData.success ? approvedData.requests.map(r => ({ ...r, status: 'approved' })) : []),
                    ...(rejectedData.success ? rejectedData.requests.map(r => ({ ...r, status: 'rejected' })) : [])
                ].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).slice(0, 30);

                topupTotalPages = 1; // All view shows combined first pages only
            } else {
                // Fetch specific status with pagination
                const params = new URLSearchParams({ action: 'pending', status: topupCurrentStatus, page, limit });
                if (search) params.set('search', search);

                const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?${params}`);
                const data = await response.json();

                if (data.success) {
                    allRequests = data.requests.map(r => ({ ...r, status: topupCurrentStatus }));
                    topupTotalPages = data.pagination?.totalPages || 1;
                }
            }

            renderTopupRequests(allRequests);
        } catch (error) {
            console.error('Failed to load pending requests:', error);
            window.showToast?.('Failed to load requests', 'error');
            listEl.innerHTML = '<div class="text-center py-4 text-red-400 text-sm">Failed to load requests</div>';
        }
    };

    // Render topup requests with pagination
    function renderTopupRequests(requests) {
        const listEl = document.getElementById('admin-pending-requests');
        if (!listEl) return;

        if (requests.length > 0) {
            const statusBadges = {
                pending: '<span class="px-2 py-0.5 text-xs rounded bg-yellow-500/20 text-yellow-400">Pending</span>',
                approved: '<span class="px-2 py-0.5 text-xs rounded bg-green-500/20 text-green-400">Approved</span>',
                rejected: '<span class="px-2 py-0.5 text-xs rounded bg-red-500/20 text-red-400">Rejected</span>'
            };

            listEl.innerHTML = requests.map(req => `
                <div class="p-4 rounded-lg bg-dark-800 border border-dark-600 flex justify-between items-center text-sm topup-request-item" data-status="${req.status}">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <p class="font-medium text-dark-50">Top-up from <span class="text-primary-400">${req.username}</span></p>
                            ${statusBadges[req.status] || ''}
                        </div>
                        <p class="text-xs text-dark-300">Amount: ${new Intl.NumberFormat().format(req.amount)} | Credits: ${req.credits_requested} (+${req.bonus_credits} bonus)</p>
                        <p class="text-xs text-dark-500 mt-1">${new Date(req.created_at).toLocaleString()}</p>
                        <a href="#" onclick="window.AIKAFLOW.viewPaymentProof('${req.payment_proof}'); return false;" class="text-xs text-blue-400 underline mt-1 block">View Proof</a>
                        ${req.admin_notes ? `<p class="text-xs text-dark-400 mt-1 italic">Note: ${req.admin_notes}</p>` : ''}
                    </div>
                    <div class="flex gap-2">
                        ${req.status === 'pending' ? `
                            <button onclick="window.approveTopup(${req.id})" class="p-2 text-green-400 hover:bg-dark-700 rounded transition-colors" title="Approve">
                                <i data-lucide="check" class="w-4 h-4"></i>
                            </button>
                            <button onclick="window.rejectTopup(${req.id})" class="p-2 text-red-400 hover:bg-dark-700 rounded transition-colors" title="Reject">
                                <i data-lucide="x" class="w-4 h-4"></i>
                            </button>
                        ` : ''}
                    </div>
                </div>
            `).join('');
        } else {
            const filterLabel = topupCurrentStatus === 'all' ? '' : topupCurrentStatus + ' ';
            listEl.innerHTML = `<div class="text-center py-4 text-dark-400 text-sm">No ${filterLabel}requests found</div>`;
        }

        // Add pagination if not 'all'
        renderTopupPagination(listEl.parentElement);
        lucide?.createIcons();
    }

    // Render pagination for topup requests
    function renderTopupPagination(parent) {
        let paginationEl = parent?.querySelector('.topup-pagination');

        // Only show pagination for specific status filters (not 'all')
        if (topupCurrentStatus === 'all') {
            paginationEl?.remove();
            return;
        }

        if (!paginationEl) {
            paginationEl = document.createElement('div');
            paginationEl.className = 'topup-pagination flex items-center justify-between mt-4 pt-4 border-t border-dark-700';
            parent?.appendChild(paginationEl);
        }

        paginationEl.innerHTML = `
            <button class="btn-secondary text-xs px-3 py-1 ${topupCurrentPage <= 1 ? 'opacity-50 cursor-not-allowed' : ''}" 
                    onclick="loadAdminPendingRequests(${topupCurrentPage - 1})" ${topupCurrentPage <= 1 ? 'disabled' : ''}>
                <i data-lucide="chevron-left" class="w-3 h-3 inline"></i> Prev
            </button>
            <span class="text-xs text-dark-400">Page ${topupCurrentPage} of ${topupTotalPages}</span>
            <button class="btn-secondary text-xs px-3 py-1 ${topupCurrentPage >= topupTotalPages ? 'opacity-50 cursor-not-allowed' : ''}" 
                    onclick="loadAdminPendingRequests(${topupCurrentPage + 1})" ${topupCurrentPage >= topupTotalPages ? 'disabled' : ''}>
                Next <i data-lucide="chevron-right" class="w-3 h-3 inline"></i>
            </button>
        `;
        lucide?.createIcons({ nodes: [paginationEl] });
    }

    // Filter change handler (now reloads from server)
    window.filterTopupRequests = function () {
        loadAdminPendingRequests(1, topupSearchQuery);
    };

    window.AIKAFLOW = window.AIKAFLOW || {};
    window.AIKAFLOW.viewPaymentProof = function (url) {
        if (!url) return;
        window.open(url, '_blank');
    };

    window.approveTopup = async function (requestId) {
        if (!confirm('Are you sure you want to approve this top-up?')) return;
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=approve`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ request_id: requestId })
            });
            const data = await response.json();
            if (data.success) {
                window.showToast?.('Top-up approved', 'success');
                window.loadAdminPendingRequests();
            } else {
                throw new Error(data.error);
            }
        } catch (error) {
            window.showToast?.(error.message, 'error');
        }
    };

    window.rejectTopup = async function (requestId) {
        const reason = prompt('Reason for rejection:');
        if (reason === null) return;

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=reject`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ request_id: requestId, reason })
            });
            const data = await response.json();
            if (data.success) {
                window.showToast?.('Top-up rejected', 'success');
                window.loadAdminPendingRequests();
            } else {
                throw new Error(data.error);
            }
        } catch (error) {
            window.showToast?.(error.message, 'error');
        }
    };

    // Load Node Costs
    window.loadNodeCosts = async function () {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=node-costs`);
            const data = await response.json();

            const listEl = document.getElementById('admin-node-costs');
            if (listEl && data.success) {
                if (data.node_costs.length === 0) {
                    listEl.innerHTML = '<div class="text-center py-6 text-dark-500 text-sm">No node costs configured yet</div>';
                    return;
                }
                listEl.innerHTML = data.node_costs.map(nc => {
                    const nodeName = window.NodeDefinitions?.[nc.node_type]?.name || nc.node_type;
                    return `
                    <div class="flex gap-3 items-center p-3 bg-dark-800/50 rounded-lg border border-dark-700 group">
                        <div class="flex-1">
                            <div class="text-sm font-medium text-dark-100">${nodeName}</div>
                            <div class="text-xs text-dark-500">${nc.description || 'API Node'}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="number" class="form-input w-24 text-sm text-center" value="${nc.cost_per_call}" step="0.01"
                                   onchange="window.updateNodeCost('${nc.node_type}', this.value, '${nc.description || ''}')">
                            <span class="text-xs text-dark-500">credits</span>
                            <button onclick="window.deleteNodeCost('${nc.node_type}')" 
                                    class="p-1.5 text-dark-500 hover:text-red-400 hover:bg-red-500/10 rounded opacity-0 group-hover:opacity-100 transition-all">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                    `;
                }).join('');
                lucide?.createIcons();
            }
        } catch (error) {
            console.error('Failed to load node costs:', error);
        }
    };

    window.updateNodeCost = async function (nodeType, cost, description) {
        try {
            await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=node-costs`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ node_type: nodeType, cost: parseFloat(cost), description })
            });
            window.showToast?.('Node cost updated', 'success');
        } catch (error) {
            window.showToast?.('Failed to update cost', 'error');
        }
    };

    window.deleteNodeCost = async function (nodeType) {
        if (!confirm(`Delete cost for "${nodeType}"?`)) return;
        try {
            await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=node-costs`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ node_type: nodeType })
            });
            window.showToast?.('Node cost deleted', 'success');
            window.loadNodeCosts();
        } catch (error) {
            window.showToast?.('Failed to delete', 'error');
        }
    };

    document.getElementById('btn-add-node-cost')?.addEventListener('click', async () => {
        const type = document.getElementById('new-node-type-select')?.value;
        const cost = document.getElementById('new-node-cost')?.value;
        if (!type || !cost) {
            window.showToast?.('Please select a node and enter cost', 'error');
            return;
        }

        await window.updateNodeCost(type, cost, '');
        document.getElementById('new-node-type-select').value = '';
        document.getElementById('new-node-cost').value = '';
        window.loadNodeCosts();
    });

    // Load Packages
    window.loadPackages = async function () {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=packages`);
            const data = await response.json();

            const listEl = document.getElementById('admin-packages-list');
            if (listEl && data.success) {
                if (data.packages.length === 0) {
                    listEl.innerHTML = '<div class="text-center py-6 text-dark-500 text-sm">No packages created yet</div>';
                    return;
                }

                // Cache packages for edit functionality
                window._packagesCache = {};
                data.packages.forEach(pkg => {
                    window._packagesCache[String(pkg.id)] = pkg;
                });

                listEl.innerHTML = data.packages.map(pkg => `
                    <div class="p-4 bg-dark-800/50 border border-dark-700 rounded-lg group package-item" data-package-id="${pkg.id}" draggable="true">
                        <div class="flex justify-between items-start">
                            <div class="flex items-center gap-3 flex-1">
                                <div class="drag-handle cursor-grab active:cursor-grabbing text-dark-500 hover:text-dark-300">
                                    <i data-lucide="grip-vertical" class="w-4 h-4"></i>
                                </div>
                                <div class="flex-1 cursor-pointer action-edit-package" data-id="${pkg.id}">
                                    <h5 class="text-base font-semibold text-dark-100">${pkg.name}</h5>
                                    <p class="text-xs text-dark-500 mt-1">${pkg.description || 'No description'}</p>
                                </div>
                            </div>
                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button type="button" class="p-1.5 text-dark-500 hover:text-primary-400 hover:bg-primary-500/10 rounded action-edit-package-btn" data-id="${pkg.id}">
                                    <i data-lucide="edit-2" class="w-4 h-4"></i>
                                </button>
                                <button type="button" onclick="event.stopPropagation(); window.deletePackage(${pkg.id})" class="p-1.5 text-dark-500 hover:text-red-400 hover:bg-red-500/10 rounded">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                        <div class="flex gap-4 mt-3 text-sm">
                            <div class="px-3 py-1.5 bg-yellow-500/10 text-yellow-400 rounded-lg">
                                <i data-lucide="coins" class="w-3 h-3 inline mr-1"></i>${new Intl.NumberFormat().format(pkg.credits)} credits
                            </div>
                            ${pkg.bonus_credits > 0 ? `
                            <div class="px-3 py-1.5 bg-green-500/10 text-green-400 rounded-lg">
                                <i data-lucide="gift" class="w-3 h-3 inline mr-1"></i>+${pkg.bonus_credits} bonus
                            </div>` : ''}
                            <div class="px-3 py-1.5 bg-primary-500/10 text-primary-400 rounded-lg ml-auto">
                                ${new Intl.NumberFormat().format(pkg.price)}
                            </div>
                        </div>
                    </div>
                `).join('');

                // Add event listeners for edit actions
                listEl.querySelectorAll('.action-edit-package, .action-edit-package-btn').forEach(el => {
                    el.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const id = el.dataset.id;
                        console.info('Edit package clicked for ID:', id);
                        window.editPackage(id);
                    });
                });

                // Add drag and drop functionality
                let draggedItem = null;

                listEl.querySelectorAll('.package-item').forEach(item => {
                    item.addEventListener('dragstart', (e) => {
                        draggedItem = item;
                        item.classList.add('opacity-50');
                        e.dataTransfer.effectAllowed = 'move';
                    });

                    item.addEventListener('dragend', () => {
                        item.classList.remove('opacity-50');
                        draggedItem = null;
                        // Save new order
                        savePackageOrder();
                    });

                    item.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                    });

                    item.addEventListener('dragenter', (e) => {
                        e.preventDefault();
                        if (item !== draggedItem) {
                            item.classList.add('border-primary-500');
                        }
                    });

                    item.addEventListener('dragleave', () => {
                        item.classList.remove('border-primary-500');
                    });

                    item.addEventListener('drop', (e) => {
                        e.preventDefault();
                        item.classList.remove('border-primary-500');
                        if (item !== draggedItem && draggedItem) {
                            const allItems = [...listEl.querySelectorAll('.package-item')];
                            const draggedIdx = allItems.indexOf(draggedItem);
                            const targetIdx = allItems.indexOf(item);

                            if (draggedIdx < targetIdx) {
                                item.parentNode.insertBefore(draggedItem, item.nextSibling);
                            } else {
                                item.parentNode.insertBefore(draggedItem, item);
                            }
                        }
                    });
                });

                lucide?.createIcons();
            }
        } catch (error) {
            console.error('Failed to load packages:', error);
        }
    };

    // Save package order after drag and drop
    async function savePackageOrder() {
        const listEl = document.getElementById('admin-packages-list');
        if (!listEl) return;

        const order = [...listEl.querySelectorAll('.package-item')].map(el => el.dataset.packageId);

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=reorder-packages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order })
            });
            const result = await response.json();
            if (result.success) {
                window.showToast?.('Package order saved', 'success');
            }
        } catch (error) {
            console.error('Failed to save package order:', error);
            window.showToast?.('Failed to save order', 'error');
        }
    }

    // Edit Package - populate form with existing data
    let editingPackageId = null;

    window.editPackage = function (id) {
        try {
            const pkg = window._packagesCache?.[String(id)];
            if (!pkg) {
                console.error('Package not found in cache:', id);
                return;
            }
            editingPackageId = id;

            // Open the accordion
            const accordion = document.querySelector('#credit-subtab-packages details');
            if (accordion) {
                accordion.open = true;
            }

            // Populate form fields
            const nameEl = document.getElementById('new-package-name');
            if (nameEl) nameEl.value = pkg.name || '';

            const creditsEl = document.getElementById('new-package-credits');
            if (creditsEl) creditsEl.value = pkg.credits || '';

            const priceEl = document.getElementById('new-package-price');
            if (priceEl) priceEl.value = pkg.price || '';

            const bonusEl = document.getElementById('new-package-bonus');
            if (bonusEl) bonusEl.value = pkg.bonus_credits || 0;

            const descEl = document.getElementById('new-package-description');
            if (descEl) descEl.value = pkg.description || '';

            // Change button text
            const btn = document.getElementById('btn-create-package');
            if (btn) {
                btn.innerHTML = '<i data-lucide="save" class="w-4 h-4 inline mr-1"></i>Update Package';
                lucide?.createIcons();
            }

            // Scroll to form
            accordion?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            console.error('Error in editPackage:', error);
        }
    };

    window.cancelEditPackage = function () {
        editingPackageId = null;
        document.getElementById('new-package-name').value = '';
        document.getElementById('new-package-credits').value = '';
        document.getElementById('new-package-price').value = '';
        document.getElementById('new-package-bonus').value = '0';
        document.getElementById('new-package-description').value = '';

        const btn = document.getElementById('btn-create-package');
        if (btn) {
            btn.innerHTML = '<i data-lucide="plus" class="w-4 h-4 inline mr-1"></i>Create Package';
            lucide?.createIcons();
        }
    };

    window.deletePackage = async function (id) {
        if (!confirm('Delete this package?')) return;
        try {
            await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=packages`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            window.showToast?.('Package deleted', 'success');
            window.loadPackages();
        } catch (error) {
            window.showToast?.('Failed to delete', 'error');
        }
    };

    // Create/Update Package from form
    document.getElementById('btn-create-package')?.addEventListener('click', async () => {
        const name = document.getElementById('new-package-name')?.value.trim();
        const credits = document.getElementById('new-package-credits')?.value;
        const price = document.getElementById('new-package-price')?.value;
        const bonus = document.getElementById('new-package-bonus')?.value || 0;
        const description = document.getElementById('new-package-description')?.value.trim();

        if (!name || !credits || !price) {
            window.showToast?.('Please fill in name, credits, and price', 'error');
            return;
        }

        const isEditing = editingPackageId !== null;

        try {
            const payload = {
                name,
                credits: parseInt(credits),
                price: parseFloat(price),
                bonus_credits: parseInt(bonus),
                description
            };

            // Include id if editing
            if (isEditing) {
                payload.id = editingPackageId;
            }

            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=packages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                window.showToast?.(isEditing ? 'Package updated' : 'Package created', 'success');
                window.cancelEditPackage(); // Reset form
                window.loadPackages();
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            window.showToast?.(error.message, 'error');
        }
    });

    // Load Coupons
    window.loadCoupons = async function () {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=coupons`);
            const data = await response.json();

            const listEl = document.getElementById('admin-coupons-list');
            if (listEl && data.success) {
                if (data.coupons.length === 0) {
                    listEl.innerHTML = '<div class="text-center py-6 text-dark-500 text-sm">No coupons created yet</div>';
                    return;
                }
                const typeLabels = {
                    percentage: 'Percentage',
                    fixed_discount: 'Fixed Discount',
                    bonus_credits: 'Bonus Credits'
                };
                const typeColors = {
                    percentage: 'bg-purple-500/10 text-purple-400',
                    fixed_discount: 'bg-green-500/10 text-green-400',
                    bonus_credits: 'bg-yellow-500/10 text-yellow-400'
                };
                listEl.innerHTML = data.coupons.map(cp => `
                    <div class="p-4 bg-dark-800/50 border border-dark-700 rounded-lg group">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center gap-2">
                                    <code class="text-base font-bold text-dark-100 bg-dark-700 px-2 py-0.5 rounded">${cp.code}</code>
                                    <span class="text-xs px-2 py-0.5 rounded ${typeColors[cp.type] || 'bg-dark-700 text-dark-400'}">${typeLabels[cp.type] || cp.type}</span>
                                </div>
                                <p class="text-sm text-dark-400 mt-2">
                                    Value: <span class="text-dark-200 font-medium">${cp.value}${cp.type === 'percentage' ? '%' : ''}</span>
                                    ${cp.max_uses ? `• Max Uses: ${cp.current_uses || 0}/${cp.max_uses}` : ''}
                                </p>
                            </div>
                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick="window.deleteCoupon(${cp.id})" class="p-1.5 text-dark-500 hover:text-red-400 hover:bg-red-500/10 rounded">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `).join('');
                lucide?.createIcons();
            }
        } catch (error) {
            console.error('Failed to load coupons:', error);
        }
    };

    // Create Coupon from form
    document.getElementById('btn-create-coupon')?.addEventListener('click', async () => {
        const code = document.getElementById('new-coupon-code')?.value.trim().toUpperCase();
        const type = document.getElementById('new-coupon-type')?.value;
        const value = document.getElementById('new-coupon-value')?.value;
        const maxUses = document.getElementById('new-coupon-max-uses')?.value || null;
        const validFrom = document.getElementById('new-coupon-valid-from')?.value || null;
        const validUntil = document.getElementById('new-coupon-valid-until')?.value || null;
        const minPurchase = document.getElementById('new-coupon-min-purchase')?.value || 0;

        if (!code || !type || !value) {
            window.showToast?.('Please fill in code, type, and value', 'error');
            return;
        }

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=coupons`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    code,
                    type,
                    value: parseFloat(value),
                    max_uses: maxUses ? parseInt(maxUses) : null,
                    valid_from: validFrom,
                    valid_until: validUntil,
                    min_purchase: parseFloat(minPurchase)
                })
            });
            const result = await response.json();
            if (result.success) {
                window.showToast?.('Coupon created', 'success');
                document.getElementById('new-coupon-code').value = '';
                document.getElementById('new-coupon-value').value = '';
                document.getElementById('new-coupon-max-uses').value = '';
                document.getElementById('new-coupon-valid-from').value = '';
                document.getElementById('new-coupon-valid-until').value = '';
                document.getElementById('new-coupon-min-purchase').value = '0';
                window.loadCoupons();
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            window.showToast?.(error.message, 'error');
        }
    });

    window.openCouponModal = function () {
        const code = prompt('Enter coupon code:');
        if (!code) return;
        const type = prompt('Type (percentage/fixed_discount/bonus_credits):', 'percentage');
        const value = prompt('Value:');

        if (code && type && value) {
            createCoupon({ code, type, value: parseFloat(value) });
        }
    };

    async function createCoupon(data) {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=coupons`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const res = await response.json();
            if (res.success) {
                window.showToast?.('Coupon created', 'success');
                window.loadCoupons();
            } else {
                throw new Error(res.error);
            }
        } catch (error) {
            window.showToast?.(error.message, 'error');
        }
    }

    window.deleteCoupon = async function (id) {
        if (!confirm('Delete this coupon?')) return;
        try {
            await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=coupons`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            window.loadCoupons();
        } catch (error) {
            window.showToast?.('Failed to delete coupon', 'error');
        }
    };

    // Alias for HTML onclick
    window.showCouponModal = window.openCouponModal;

    // ================================
    // Bank Account Management
    // ================================
    let editingBankId = null;
    window._banksCache = {};

    window.loadBanks = async function () {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=bank-accounts`);
            const data = await response.json();

            const listEl = document.getElementById('admin-banks-list');
            if (listEl && data.success) {
                if (data.banks.length === 0) {
                    listEl.innerHTML = '<div class="text-center py-6 text-dark-500 text-sm">No bank accounts added yet</div>';
                    return;
                }

                // Cache banks
                window._banksCache = {};
                data.banks.forEach(bank => {
                    window._banksCache[String(bank.id)] = bank;
                });

                listEl.innerHTML = data.banks.map(bank => `
                    <div class="p-4 bg-dark-800/50 border border-dark-700 rounded-lg group bank-item" data-bank-id="${bank.id}" draggable="true">
                        <div class="flex justify-between items-start">
                            <div class="flex items-center gap-3 flex-1">
                                <div class="drag-handle cursor-grab active:cursor-grabbing text-dark-500 hover:text-dark-300">
                                    <i data-lucide="grip-vertical" class="w-4 h-4"></i>
                                </div>
                                <div class="flex-1">
                                    <h5 class="text-base font-semibold text-dark-100">${bank.bank_name}</h5>
                                    <p class="text-sm text-dark-400 mt-1">
                                        <span class="font-mono">${bank.account_number}</span>
                                        <span class="mx-2">•</span>
                                        ${bank.account_holder}
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button type="button" class="p-1.5 text-dark-500 hover:text-primary-400 hover:bg-primary-500/10 rounded action-edit-bank-btn" data-id="${bank.id}">
                                    <i data-lucide="edit-2" class="w-4 h-4"></i>
                                </button>
                                <button type="button" onclick="event.stopPropagation(); window.deleteBank(${bank.id})" class="p-1.5 text-dark-500 hover:text-red-400 hover:bg-red-500/10 rounded">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `).join('');

                // Add event listeners for edit
                listEl.querySelectorAll('.action-edit-bank-btn').forEach(el => {
                    el.addEventListener('click', (e) => {
                        e.stopPropagation();
                        window.editBank(el.dataset.id);
                    });
                });

                // Add drag and drop
                let draggedItem = null;
                listEl.querySelectorAll('.bank-item').forEach(item => {
                    item.addEventListener('dragstart', (e) => {
                        draggedItem = item;
                        item.classList.add('opacity-50');
                        e.dataTransfer.effectAllowed = 'move';
                    });

                    item.addEventListener('dragend', () => {
                        item.classList.remove('opacity-50');
                        draggedItem = null;
                        saveBankOrder();
                    });

                    item.addEventListener('dragover', (e) => {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                    });

                    item.addEventListener('dragenter', (e) => {
                        e.preventDefault();
                        if (item !== draggedItem) {
                            item.classList.add('border-primary-500');
                        }
                    });

                    item.addEventListener('dragleave', () => {
                        item.classList.remove('border-primary-500');
                    });

                    item.addEventListener('drop', (e) => {
                        e.preventDefault();
                        item.classList.remove('border-primary-500');
                        if (item !== draggedItem && draggedItem) {
                            const allItems = [...listEl.querySelectorAll('.bank-item')];
                            const draggedIdx = allItems.indexOf(draggedItem);
                            const targetIdx = allItems.indexOf(item);

                            if (draggedIdx < targetIdx) {
                                item.parentNode.insertBefore(draggedItem, item.nextSibling);
                            } else {
                                item.parentNode.insertBefore(draggedItem, item);
                            }
                        }
                    });
                });

                lucide?.createIcons();
            }
        } catch (error) {
            console.error('Failed to load banks:', error);
        }
    };

    async function saveBankOrder() {
        const listEl = document.getElementById('admin-banks-list');
        if (!listEl) return;

        const order = [...listEl.querySelectorAll('.bank-item')].map(el => el.dataset.bankId);

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=reorder-banks`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order })
            });
            const result = await response.json();
            if (result.success) {
                window.showToast?.('Bank order saved', 'success');
            }
        } catch (error) {
            console.error('Failed to save bank order:', error);
            window.showToast?.('Failed to save order', 'error');
        }
    }

    window.editBank = function (id) {
        const bank = window._banksCache?.[String(id)];
        if (!bank) {
            console.error('Bank not found in cache:', id);
            return;
        }

        editingBankId = id;

        // Open accordion
        const accordion = document.querySelector('#credit-subtab-banks details');
        if (accordion) accordion.open = true;

        // Populate form
        document.getElementById('new-bank-name').value = bank.bank_name || '';
        document.getElementById('new-bank-account').value = bank.account_number || '';
        document.getElementById('new-bank-holder').value = bank.account_holder || '';

        // Change button text
        const btn = document.getElementById('btn-create-bank');
        if (btn) {
            btn.innerHTML = '<i data-lucide="save" class="w-4 h-4 inline mr-1"></i>Update Bank';
            lucide?.createIcons();
        }

        accordion?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    window.cancelEditBank = function () {
        editingBankId = null;
        document.getElementById('new-bank-name').value = '';
        document.getElementById('new-bank-account').value = '';
        document.getElementById('new-bank-holder').value = '';

        const btn = document.getElementById('btn-create-bank');
        if (btn) {
            btn.innerHTML = '<i data-lucide="plus" class="w-4 h-4 inline mr-1"></i>Add Bank';
            lucide?.createIcons();
        }
    };

    window.deleteBank = async function (id) {
        if (!confirm('Delete this bank account?')) return;
        try {
            await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=bank-accounts`, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            window.showToast?.('Bank account deleted', 'success');
            window.loadBanks();
        } catch (error) {
            window.showToast?.('Failed to delete', 'error');
        }
    };

    // Create/Update Bank from form
    document.getElementById('btn-create-bank')?.addEventListener('click', async () => {
        const bankName = document.getElementById('new-bank-name')?.value.trim();
        const accountNumber = document.getElementById('new-bank-account')?.value.trim();
        const accountHolder = document.getElementById('new-bank-holder')?.value.trim();

        if (!bankName || !accountNumber || !accountHolder) {
            window.showToast?.('Please fill in all fields', 'error');
            return;
        }

        const isEditing = editingBankId !== null;

        try {
            const payload = {
                bank_name: bankName,
                account_number: accountNumber,
                account_holder: accountHolder
            };

            if (isEditing) {
                payload.id = editingBankId;
            }

            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=bank-accounts`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                window.showToast?.(isEditing ? 'Bank account updated' : 'Bank account added', 'success');
                window.cancelEditBank();
                window.loadBanks();
            } else {
                throw new Error(result.error);
            }
        } catch (error) {
            window.showToast?.(error.message, 'error');
        }
    });

    // Load Credit Settings
    async function loadCreditSettings() {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/settings.php`);
            const result = await response.json();

            if (result.success) {
                document.getElementById('admin-credit-currency').value = result.settings.credit_currency || 'USD';
                document.getElementById('admin-credit-symbol').value = result.settings.credit_currency_symbol || '$';
                document.getElementById('admin-credit-welcome').value = result.settings.credit_welcome_amount || '100';
                document.getElementById('admin-credit-threshold').value = result.settings.credit_low_threshold || '100';
                document.getElementById('admin-credit-expiry-days').value = result.settings.credit_default_expiry_days || '365';
                document.getElementById('admin-qris-string').value = result.settings.qris_string || '';

                // PayPal settings
                const paypalEnabled = document.getElementById('admin-paypal-enabled');
                if (paypalEnabled) paypalEnabled.checked = result.settings.paypal_enabled === '1';

                const paypalSandbox = document.getElementById('admin-paypal-sandbox');
                if (paypalSandbox) paypalSandbox.checked = result.settings.paypal_sandbox === '1';

                document.getElementById('admin-paypal-client-id').value = result.settings.paypal_client_id || '';
                document.getElementById('admin-paypal-secret-key').value = result.settings.paypal_secret_key || '';
                document.getElementById('admin-paypal-usd-rate').value = result.settings.paypal_usd_rate || '';

                // Toggle paypal fields visibility
                const paypalFields = document.getElementById('paypal-fields');
                if (paypalFields) paypalFields.classList.toggle('hidden', !paypalEnabled?.checked);

                // Show/hide USD rate based on currency
                updateUsdRateVisibility();
            }
        } catch (error) {
            console.error('Failed to load credit settings:', error);
        }
    }

    // Update USD rate field visibility based on selected currency
    function updateUsdRateVisibility() {
        const currency = document.getElementById('admin-credit-currency')?.value;
        const usdRateField = document.getElementById('paypal-usd-rate-field');
        const currencyLabel = document.getElementById('usd-rate-currency-label');
        if (usdRateField) {
            usdRateField.classList.toggle('hidden', currency === 'USD');
        }
        if (currencyLabel) {
            currencyLabel.textContent = currency || 'IDR';
        }
    }

    // Update currency symbol when dropdown changes
    window.updateCurrencySymbol = function () {
        const select = document.getElementById('admin-credit-currency');
        const symbolInput = document.getElementById('admin-credit-symbol');
        if (select && symbolInput) {
            const selectedOption = select.options[select.selectedIndex];
            symbolInput.value = selectedOption?.dataset.symbol || '$';
        }
        updateUsdRateVisibility();
    };

    // PayPal enable toggle handler in Credits tab
    document.getElementById('admin-paypal-enabled')?.addEventListener('change', (e) => {
        const paypalFields = document.getElementById('paypal-fields');
        if (paypalFields) paypalFields.classList.toggle('hidden', !e.target.checked);
    });

    // Save Credit Settings
    document.getElementById('btn-save-credit-settings')?.addEventListener('click', async () => {
        const btn = document.getElementById('btn-save-credit-settings');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/admin/settings.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    credit_currency: document.getElementById('admin-credit-currency')?.value,
                    credit_currency_symbol: document.getElementById('admin-credit-symbol')?.value,
                    credit_welcome_amount: document.getElementById('admin-credit-welcome')?.value,
                    credit_low_threshold: document.getElementById('admin-credit-threshold')?.value,
                    credit_default_expiry_days: document.getElementById('admin-credit-expiry-days')?.value,
                    qris_string: document.getElementById('admin-qris-string')?.value,
                    // Workflow Settings
                    max_repeat_count: document.getElementById('admin-max-repeat-count')?.value,
                    // PayPal Settings
                    paypal_enabled: document.getElementById('admin-paypal-enabled')?.checked ? '1' : '0',
                    paypal_sandbox: document.getElementById('admin-paypal-sandbox')?.checked ? '1' : '0',
                    paypal_client_id: document.getElementById('admin-paypal-client-id')?.value,
                    paypal_secret_key: document.getElementById('admin-paypal-secret-key')?.value,
                    paypal_usd_rate: document.getElementById('admin-paypal-usd-rate')?.value
                })
            });

            const result = await response.json();
            if (!result.success) throw new Error(result.error);

            window.showToast?.('Credit settings saved', 'success');
        } catch (error) {
            window.showToast?.(error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="save" class="w-4 h-4 inline mr-1"></i>Save Settings';
            lucide?.createIcons();
        }
    });

    // Show Package Modal (simple prompt-based for now)
    window.showPackageModal = function () {
        const name = prompt('Package Name:');
        if (!name) return;
        const credits = prompt('Credits:', '500');
        const price = prompt('Price:', '50000');
        const bonus = prompt('Bonus Credits:', '0');
        const description = prompt('Description:', '');

        if (name && credits && price) {
            createPackage({
                name,
                credits: parseInt(credits),
                price: parseFloat(price),
                bonus_credits: parseInt(bonus || 0),
                description: description || ''
            });
        }
    };

    async function createPackage(data) {
        try {
            const response = await fetch(`${window.AIKAFLOW.apiUrl}/credits/admin.php?action=packages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const res = await response.json();
            if (res.success) {
                window.showToast?.('Package created', 'success');
                window.loadPackages?.();
            } else {
                throw new Error(res.error);
            }
        } catch (error) {
            window.showToast?.(error.message, 'error');
        }
    }

})()

