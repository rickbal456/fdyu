/**
 * AIKAFLOW Invitation System
 * 
 * Handles the invitation modal UI and sharing functionality.
 */

(function () {
    'use strict';

    let invitationData = null;

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        // Create invitation modal
        createInvitationModal();

        // Listen for menu click
        const menuBtn = document.getElementById('menu-invitation');
        if (menuBtn) {
            menuBtn.addEventListener('click', openInvitationModal);
        }
    });

    function createInvitationModal() {
        const modal = document.createElement('div');
        modal.id = 'invitation-modal';
        modal.className = 'fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center';
        modal.innerHTML = `
            <div class="bg-dark-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 overflow-hidden">
                <div class="p-6 border-b border-dark-600">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xl font-bold text-white flex items-center gap-2">
                            <i data-lucide="gift" class="w-6 h-6 text-green-400"></i>
                            <span data-i18n="invitation.invite_friends">Invite Friends</span>
                        </h3>
                        <button id="close-invitation-modal" class="text-gray-400 hover:text-white transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div id="invitation-content" class="p-6 space-y-6">
                    <div class="text-center">
                        <div class="animate-spin w-8 h-8 border-2 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
                        <p class="text-gray-400 mt-2" data-i18n="common.loading">Loading...</p>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Close modal handlers
        document.getElementById('close-invitation-modal').addEventListener('click', closeInvitationModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeInvitationModal();
        });

        // Initialize lucide icons in modal
        if (typeof lucide !== 'undefined') lucide.createIcons();
        // Translate the injected modal
        if (window.I18n?.translatePage) window.I18n.translatePage();
    }

    async function openInvitationModal() {
        const modal = document.getElementById('invitation-modal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        // Close user dropdown if open
        const userDropdown = document.getElementById('user-dropdown');
        if (userDropdown) userDropdown.classList.add('hidden');

        // Fetch invitation data
        try {
            const response = await fetch('api/invitations/code.php');
            const result = await response.json();

            if (result.success) {
                invitationData = result;
                renderInvitationContent(result);
            } else {
                renderError(result.error || 'Failed to load invitation data');
            }
        } catch (error) {
            console.error('Invitation fetch error:', error);
            renderError('Failed to load invitation data');
        }
    }

    function closeInvitationModal() {
        const modal = document.getElementById('invitation-modal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function renderInvitationContent(data) {
        const content = document.getElementById('invitation-content');

        if (!data.enabled) {
            content.innerHTML = `
                <div class="text-center py-8">
                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <p class="text-gray-400">Invitation system is currently disabled.</p>
                </div>
            `;
            return;
        }

        content.innerHTML = `
            <!-- Your Invitation Code -->
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Your Invitation Code</label>
                <div class="flex gap-2">
                    <input type="text" id="my-invitation-code" value="${data.invitationCode}" readonly
                        class="flex-1 px-4 py-3 bg-dark-700 border border-dark-600 rounded-lg text-white font-mono text-lg text-center tracking-widest">
                    <button id="btn-copy-code" class="px-4 py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-lg transition-colors" title="Copy Code">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Share Link -->
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Share Link</label>
                <div class="flex gap-2">
                    <input type="text" id="share-link" value="${data.shareUrl}" readonly
                        class="flex-1 px-4 py-3 bg-dark-700 border border-dark-600 rounded-lg text-white text-sm truncate">
                    <button id="btn-copy-link" class="px-4 py-3 bg-dark-600 hover:bg-dark-500 text-white rounded-lg transition-colors" title="Copy Link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Rewards Info -->
            <div class="bg-gradient-to-r from-green-500/10 to-emerald-500/10 border border-green-500/20 rounded-lg p-4">
                <h4 class="text-green-400 font-medium mb-2 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    Rewards
                </h4>
                <p class="text-gray-300 text-sm">
                    You get <span class="text-green-400 font-bold">${data.rewards.referrer} credits</span> for each friend who signs up!<br>
                    Your friend gets <span class="text-green-400 font-bold">${data.rewards.referee} bonus credits</span> too!
                </p>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-dark-700 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-primary-400">${data.stats.referralCount}</div>
                    <div class="text-sm text-gray-400">Friends Invited</div>
                </div>
                <div class="bg-dark-700 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-green-400">${data.stats.creditsEarned}</div>
                    <div class="text-sm text-gray-400">Credits Earned</div>
                </div>
            </div>

            ${!data.alreadyReferred ? `
            <!-- Apply Invitation Code -->
            <div class="border-t border-dark-600 pt-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">Have an invitation code?</label>
                <div class="flex gap-2">
                    <input type="text" id="apply-code-input" placeholder="Enter code"
                        class="flex-1 px-4 py-3 bg-dark-700 border border-dark-600 rounded-lg text-white uppercase" maxlength="12">
                    <button id="btn-apply-code" class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors font-medium">
                        Apply
                    </button>
                </div>
                <p id="apply-code-message" class="mt-2 text-sm hidden"></p>
            </div>
            ` : `
            <div class="text-center text-sm text-gray-500">
                <svg class="w-5 h-5 inline text-green-400 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                You've already used an invitation code
            </div>
            `}
        `;

        // Attach event handlers
        document.getElementById('btn-copy-code').addEventListener('click', () => copyToClipboard(data.invitationCode, 'Code copied!'));
        document.getElementById('btn-copy-link').addEventListener('click', () => copyToClipboard(data.shareUrl, 'Link copied!'));

        const applyBtn = document.getElementById('btn-apply-code');
        if (applyBtn) {
            applyBtn.addEventListener('click', applyInvitationCode);
        }
    }

    function renderError(message) {
        const content = document.getElementById('invitation-content');
        content.innerHTML = `
            <div class="text-center py-8">
                <svg class="w-16 h-16 text-red-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <p class="text-red-400">${message}</p>
            </div>
        `;
    }

    async function copyToClipboard(text, successMessage) {
        try {
            await navigator.clipboard.writeText(text);
            showToast(successMessage, 'success');
        } catch (err) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showToast(successMessage, 'success');
        }
    }

    async function applyInvitationCode() {
        const input = document.getElementById('apply-code-input');
        const message = document.getElementById('apply-code-message');
        const code = input.value.trim().toUpperCase();

        if (!code) {
            message.textContent = 'Please enter an invitation code';
            message.className = 'mt-2 text-sm text-red-400';
            message.classList.remove('hidden');
            return;
        }

        try {
            const response = await fetch('api/invitations/code.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code })
            });

            const result = await response.json();

            if (result.success) {
                message.textContent = `Success! You received ${result.creditsReceived} bonus credits!`;
                message.className = 'mt-2 text-sm text-green-400';
                message.classList.remove('hidden');
                showToast('Invitation code applied!', 'success');

                // Refresh the modal after a delay
                setTimeout(() => openInvitationModal(), 1500);
            } else {
                message.textContent = result.error || 'Failed to apply code';
                message.className = 'mt-2 text-sm text-red-400';
                message.classList.remove('hidden');
            }
        } catch (error) {
            message.textContent = 'Failed to apply code';
            message.className = 'mt-2 text-sm text-red-400';
            message.classList.remove('hidden');
        }
    }

    function showToast(message, type = 'info') {
        // Use existing toast system if available
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
            return;
        }

        // Simple fallback toast
        const toast = document.createElement('div');
        toast.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg text-white z-50 transition-opacity duration-300 ${type === 'success' ? 'bg-green-600' : type === 'error' ? 'bg-red-600' : 'bg-primary-600'
            }`;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
})();
