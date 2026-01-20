<?php
/**
 * AIKAFLOW WhatsApp Verification Page
 * 
 * Required for Google OAuth users when WhatsApp verification is enabled.
 * User is redirected here after Google login if they don't have a verified WhatsApp number.
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

// Initialize session
Auth::initSession();

// Redirect if not logged in
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = $user['id'];

// Check if WhatsApp verification is enabled
$whatsappVerificationEnabled = false;
try {
    $setting = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'whatsapp_verification_enabled'");
    $whatsappVerificationEnabled = $setting === '1';
} catch (Exception $e) {
    error_log('WhatsApp verification setting error: ' . $e->getMessage());
}

// If not enabled, redirect to home
if (!$whatsappVerificationEnabled) {
    header('Location: ./');
    exit;
}

// Check if user already has a verified WhatsApp number
if (!empty($user['whatsapp_phone'])) {
    header('Location: ./');
    exit;
}

// Load site settings for branding
$siteTitle = 'AIKAFLOW';
$logoUrlDark = '';
$faviconUrl = '';

try {
    $brandRows = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_title', 'logo_url_dark', 'favicon_url')");
    foreach ($brandRows as $row) {
        if ($row['setting_key'] === 'site_title')
            $siteTitle = $row['setting_value'] ?? 'AIKAFLOW';
        if ($row['setting_key'] === 'logo_url_dark')
            $logoUrlDark = $row['setting_value'] ?? '';
        if ($row['setting_key'] === 'favicon_url')
            $faviconUrl = $row['setting_value'] ?? '';
    }
} catch (Exception $e) {
    error_log('Settings load error: ' . $e->getMessage());
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Use whatsapp_phone_full (hidden field) since the main input is disabled after sending code
    $phone = trim($_POST['whatsapp_phone_full'] ?? $_POST['whatsapp_phone'] ?? '');

    if (empty($phone)) {
        $error = 'WhatsApp number is required.';
    } elseif (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
        $error = 'Invalid phone number format.';
    } else {
        // Check if phone is already registered
        $existingPhone = Database::fetchOne(
            "SELECT id FROM users WHERE whatsapp_phone = ? AND id != ?",
            [$phone, $userId]
        );

        if ($existingPhone) {
            $error = 'This WhatsApp number is already registered.';
        } elseif (!isset($_SESSION['whatsapp_verified']) || $_SESSION['whatsapp_verified']['phone'] !== $phone) {
            $error = 'Please verify your WhatsApp number first.';
        } else {
            // Save phone number
            try {
                Database::update(
                    'users',
                    ['whatsapp_phone' => $phone],
                    'id = :id',
                    ['id' => $userId]
                );

                // Clear verification session
                unset($_SESSION['whatsapp_verified']);

                // Redirect to home
                header('Location: ./');
                exit;
            } catch (Exception $e) {
                error_log('Save WhatsApp phone error: ' . $e->getMessage());
                $error = 'Failed to save phone number. Please try again.';
            }
        }
    }
}

$csrfToken = Auth::getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify WhatsApp -
        <?= htmlspecialchars($siteTitle) ?>
    </title>
    <?php if (!empty($faviconUrl)): ?>
        <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        dark: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            200: '#e2e8f0',
                            300: '#cbd5e1',
                            400: '#94a3b8',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155',
                            800: '#1e293b',
                            900: '#0f172a',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- intl-tel-input -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/css/intlTelInput.css">
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/intlTelInput.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .glass {
            background: rgba(17, 24, 39, 0.8);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* intl-tel-input dark theme overrides */
        .iti {
            width: 100%;
        }

        .iti__tel-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 87px !important;
            background-color: rgb(31, 41, 55) !important;
            border: 1px solid rgb(75, 85, 99) !important;
            border-radius: 0.5rem !important;
            color: white !important;
        }

        .iti__tel-input:focus {
            outline: none !important;
            ring: 2px !important;
            ring-color: rgb(147, 51, 234) !important;
            border-color: transparent !important;
        }

        .iti__tel-input::placeholder {
            color: rgb(107, 114, 128) !important;
        }

        .iti__country-container {
            padding-left: 0 !important;
        }

        .iti__dropdown-content {
            background-color: rgb(31, 41, 55) !important;
            border: 1px solid rgb(75, 85, 99) !important;
            color: white !important;
        }

        .iti__search-input {
            background-color: rgb(55, 65, 81) !important;
            color: white !important;
            border-color: rgb(75, 85, 99) !important;
        }

        .iti__country {
            padding: 8px 10px !important;
        }

        .iti__country:hover,
        .iti__country--highlight {
            background-color: rgb(55, 65, 81) !important;
        }

        .iti__dial-code {
            color: rgb(156, 163, 175) !important;
        }

        .iti__selected-dial-code {
            color: rgb(209 213 219 / var(--tw-text-opacity, 1)) !important;
        }
    </style>
</head>

<body class="min-h-screen bg-dark-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8">
            <?php if (!empty($logoUrlDark)): ?>
                <img src="<?= htmlspecialchars($logoUrlDark) ?>" alt="<?= htmlspecialchars($siteTitle) ?>"
                    class="h-12 mx-auto mb-4">
            <?php else: ?>
                <h1 class="text-3xl font-bold bg-gradient-to-r from-purple-400 to-pink-500 bg-clip-text text-transparent">
                    <?= htmlspecialchars($siteTitle) ?>
                </h1>
            <?php endif; ?>
        </div>

        <!-- Card -->
        <div class="glass rounded-2xl p-8">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-green-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-green-500" fill="currentColor" viewBox="0 0 24 24">
                        <path
                            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                    </svg>
                </div>
                <h2 class="text-2xl font-bold text-white mb-2">Verify Your WhatsApp</h2>
                <p class="text-gray-400">
                    Welcome, <span class="text-purple-400 font-medium">
                        <?= htmlspecialchars($user['username']) ?>
                    </span>!<br>
                    Please verify your WhatsApp number to continue.
                </p>
            </div>

            <?php if ($error): ?>
                <div class="mb-4 p-3 bg-red-500/20 border border-red-500/30 rounded-lg text-red-400 text-sm">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form id="whatsapp-form" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div>
                    <label for="whatsapp_phone" class="block text-sm font-medium text-gray-300 mb-2">
                        WhatsApp Number <span class="text-red-400">*</span>
                    </label>
                    <div class="flex gap-2 items-start">
                        <div class="flex-1">
                            <input type="tel" id="whatsapp_phone" name="whatsapp_phone" required class="w-full">
                            <input type="hidden" id="whatsapp_phone_full" name="whatsapp_phone_full" value="">
                        </div>
                        <button type="button" id="btn-send-wa-code"
                            class="px-4 py-3 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
                            Send Code
                        </button>
                    </div>
                    <p id="phone-hint" class="mt-1 text-xs text-gray-500">Select your country and enter your phone
                        number</p>
                </div>

                <!-- OTP Section -->
                <div id="wa-otp-section" class="hidden">
                    <div class="flex gap-2">
                        <input type="text" id="wa_otp_code" maxlength="6"
                            class="flex-1 px-4 py-3 bg-dark-800 border border-dark-600 rounded-lg text-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all text-center tracking-widest font-mono text-lg"
                            placeholder="Enter 6-digit code">
                        <button type="button" id="btn-verify-wa-code"
                            class="px-4 py-3 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-lg transition-colors whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed">
                            Verify
                        </button>
                    </div>
                    <p id="wa-otp-status" class="mt-1 text-xs text-gray-500">Enter the verification code sent to your
                        WhatsApp</p>
                </div>

                <!-- Verified Badge -->
                <div id="wa-verified-badge" class="hidden">
                    <span
                        class="inline-flex items-center gap-1 px-2 py-1 bg-green-500/20 text-green-400 text-xs rounded-full">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7">
                            </path>
                        </svg>
                        Verified
                    </span>
                </div>

                <input type="hidden" id="whatsapp_verified" name="whatsapp_verified" value="0">

                <button type="submit" id="btn-continue"
                    class="w-full py-3 px-4 bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-700 hover:to-pink-700 text-white font-medium rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                    disabled>
                    Continue to App
                </button>
            </form>

            <div class="mt-6 text-center">
                <a href="api/auth/logout.php" class="text-gray-400 hover:text-white text-sm transition-colors">
                    Sign out and use a different account
                </a>
            </div>
        </div>
    </div>

    <script>
            (function () {
                const sendBtn = document.getElementById('btn-send-wa-code');
                const verifyBtn = document.getElementById('btn-verify-wa-code');
                const phoneInput = document.getElementById('whatsapp_phone');
                const phoneFullInput = document.getElementById('whatsapp_phone_full');
                const otpSection = document.getElementById('wa-otp-section');
                const otpInput = document.getElementById('wa_otp_code');
                const otpStatus = document.getElementById('wa-otp-status');
                const verifiedBadge = document.getElementById('wa-verified-badge');
                const verifiedHidden = document.getElementById('whatsapp_verified');
                const continueBtn = document.getElementById('btn-continue');
                const phoneHint = document.getElementById('phone-hint');

                // Initialize intl-tel-input
                let iti = null;
                if (typeof intlTelInput !== 'undefined') {
                    iti = intlTelInput(phoneInput, {
                        initialCountry: "auto",
                        geoIpLookup: function (callback) {
                            fetch("https://ipapi.co/json")
                                .then(res => res.json())
                                .then(data => callback(data.country_code))
                                .catch(() => callback("ID"));
                        },
                        separateDialCode: true,
                        utilsScript: "https://cdn.jsdelivr.net/npm/intl-tel-input@18.5.3/build/js/utils.js",
                        preferredCountries: ["id", "sa", "my", "sg"],
                        nationalMode: false,
                        formatOnDisplay: true
                    });
                }

                // Update hidden field before form submit
                const form = document.getElementById('whatsapp-form');
                form?.addEventListener('submit', function () {
                    if (iti) {
                        phoneFullInput.value = iti.getNumber();
                        phoneInput.value = iti.getNumber();
                    }
                });

                function getFullPhone() {
                    if (iti) {
                        return iti.getNumber();
                    }
                    return phoneInput.value.trim();
                }

                let cooldownTimer = null;
                let isVerified = false;

                function updateStatus(element, msg, type) {
                    if (!element) return;
                    element.textContent = msg;
                    if (type === 'error') {
                        element.classList.remove('text-green-400', 'text-gray-500');
                        element.classList.add('text-red-400');
                    } else if (type === 'success') {
                        element.classList.remove('text-red-400', 'text-gray-500');
                        element.classList.add('text-green-400');
                    } else {
                        element.classList.remove('text-red-400', 'text-green-400');
                        element.classList.add('text-gray-500');
                    }
                }

                function showPhoneError(msg) {
                    updateStatus(phoneHint, msg, 'error');
                }

                function showOtpStatus(msg, type = 'neutral') {
                    updateStatus(otpStatus, msg, type);
                }

                // Send verification code
                sendBtn.addEventListener('click', async function () {
                    const phone = getFullPhone();

                    if (!phone) {
                        showPhoneError('Please enter your WhatsApp number');
                        return;
                    }

                    if (iti && !iti.isValidNumber()) {
                        showPhoneError('Please enter a valid phone number');
                        return;
                    }

                    // Reset errors
                    updateStatus(phoneHint, 'Select your country and enter your phone number', 'neutral');
                    updateStatus(otpStatus, '', 'neutral');

                    sendBtn.disabled = true;
                    sendBtn.textContent = 'Sending...';

                    try {
                        const response = await fetch('api/auth/send-whatsapp-code.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ phone })
                        });

                        const data = await response.json();

                        if (data.success) {
                            if (otpSection) otpSection.classList.remove('hidden');
                            if (otpInput) {
                                otpInput.value = '';
                                otpInput.focus();
                            }

                            showOtpStatus('Verification code sent to your WhatsApp', 'success');
                            startCooldown(60);

                            if (phoneInput) phoneInput.disabled = true;

                            try {
                                if (iti) iti.setDisabled(true);
                            } catch (e) {
                                console.warn('Could not disable phone input:', e);
                            }
                        } else {
                            showPhoneError(data.error || 'Failed to send code');
                            sendBtn.disabled = false;
                            sendBtn.textContent = 'Send Code';
                        }
                    } catch (error) {
                        console.error('Send code error:', error);
                        // Determine if it was a network error or script error
                        showPhoneError('Failed to send code. Please try again.');
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send Code';
                    }
                });

                // Verify code
                verifyBtn.addEventListener('click', async function () {
                    const code = otpInput ? otpInput.value.trim() : '';
                    const phone = getFullPhone();

                    if (!code || code.length !== 6) {
                        showOtpStatus('Please enter the 6-digit code', 'error');
                        return;
                    }

                    verifyBtn.disabled = true;
                    verifyBtn.textContent = 'Verifying...';

                    try {
                        const response = await fetch('api/auth/verify-whatsapp-code.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ code, phone })
                        });

                        const data = await response.json();

                        if (data.success) {
                            isVerified = true;
                            if (otpSection) otpSection.classList.add('hidden');
                            if (verifiedBadge) verifiedBadge.classList.remove('hidden');
                            if (sendBtn) sendBtn.classList.add('hidden');
                            if (verifiedHidden) verifiedHidden.value = '1';
                            if (continueBtn) continueBtn.disabled = false;

                            showOtpStatus('WhatsApp number verified!', 'success');
                            // Clear phone hint as it's verified
                            if (phoneHint) phoneHint.textContent = '';
                        } else {
                            showOtpStatus(data.error || 'Invalid code', 'error');
                            verifyBtn.disabled = false;
                            verifyBtn.textContent = 'Verify';
                        }
                    } catch (error) {
                        console.error('Verify code error:', error);
                        showOtpStatus('Verification failed. Please try again.', 'error');
                        verifyBtn.disabled = false;
                        verifyBtn.textContent = 'Verify';
                    }
                });

                // Rest of initialization...
                if (otpInput) {
                    otpInput.addEventListener('input', function () {
                        this.value = this.value.replace(/[^0-9]/g, '');
                    });
                }

                // Reset on phone change
                phoneInput?.addEventListener('countrychange', resetVerification);
                phoneInput?.addEventListener('input', resetVerification);

                function resetVerification() {
                    if (isVerified) {
                        isVerified = false;
                        verifiedBadge?.classList.add('hidden');
                        sendBtn?.classList.remove('hidden');
                        verifiedHidden.value = '0';
                        continueBtn.disabled = true;
                        phoneInput.disabled = false;
                        if (iti) iti.setDisabled(false);
                    }
                }

                function startCooldown(seconds) {
                    let remaining = seconds;
                    sendBtn.disabled = true;

                    cooldownTimer = setInterval(() => {
                        remaining--;
                        sendBtn.textContent = `Resend (${remaining}s)`;

                        if (remaining <= 0) {
                            clearInterval(cooldownTimer);
                            sendBtn.disabled = false;
                            sendBtn.textContent = 'Resend Code';
                        }
                    }, 1000);
                }

                function showError(msg) {
                    if (otpStatus) {
                        otpStatus.textContent = msg;
                        otpStatus.className = 'mt-1 text-xs text-red-400';
                    }
                    if (phoneHint) {
                        phoneHint.textContent = msg;
                        phoneHint.className = 'mt-1 text-xs text-red-400';
                    }
                }

                function showSuccess(msg) {
                    if (otpStatus) {
                        otpStatus.textContent = msg;
                        otpStatus.className = 'mt-1 text-xs text-green-400';
                    }
                    if (phoneHint) {
                        phoneHint.textContent = msg;
                        phoneHint.className = 'mt-1 text-xs text-green-400';
                    }
                }
            })();
    </script>
</body>

</html>