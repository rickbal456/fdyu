<?php
/**
 * AIKAFLOW API - Invitation Code Endpoint
 * 
 * GET  /api/invitations/code.php - Get user's invitation code and stats
 * POST /api/invitations/code.php - Apply an invitation code to current user
 */

declare(strict_types=1);

define('AIKAFLOW', true);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

$user = requireAuth();

// Check if invitation feature is enabled
$invitationEnabled = false;
$referrerCredits = 0;
$refereeCredits = 0;

try {
    $settings = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('invitation_enabled', 'invitation_referrer_credits', 'invitation_referee_credits')");
    foreach ($settings as $setting) {
        if ($setting['setting_key'] === 'invitation_enabled')
            $invitationEnabled = $setting['setting_value'] === '1';
        if ($setting['setting_key'] === 'invitation_referrer_credits')
            $referrerCredits = (int) $setting['setting_value'];
        if ($setting['setting_key'] === 'invitation_referee_credits')
            $refereeCredits = (int) $setting['setting_value'];
    }
} catch (Exception $e) {
    // Ignore, use defaults
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get user's invitation code and referral stats
        try {
            // Get or generate invitation code
            $userData = Database::fetchOne("SELECT invitation_code, referred_by FROM users WHERE id = ?", [$user['id']]);
            $invitationCode = $userData['invitation_code'] ?? null;

            // Generate code if not exists
            if (!$invitationCode) {
                $invitationCode = strtoupper(substr(md5($user['id'] . uniqid() . time()), 0, 8));
                Database::query("UPDATE users SET invitation_code = ? WHERE id = ?", [$invitationCode, $user['id']]);
            }

            // Get referral stats
            $referralCount = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM users WHERE referred_by = ?",
                [$user['id']]
            );

            // Get total credits earned from referrals
            $creditsEarned = (int) Database::fetchColumn(
                "SELECT COALESCE(SUM(amount), 0) FROM credit_transactions 
                 WHERE user_id = ? AND type = 'referral_reward'",
                [$user['id']]
            );

            // Check if user has already been referred
            $alreadyReferred = !empty($userData['referred_by']);

            // Generate share URL
            $shareUrl = APP_URL . '/register?ref=' . $invitationCode;

            successResponse([
                'enabled' => $invitationEnabled,
                'invitationCode' => $invitationCode,
                'shareUrl' => $shareUrl,
                'stats' => [
                    'referralCount' => $referralCount,
                    'creditsEarned' => $creditsEarned
                ],
                'alreadyReferred' => $alreadyReferred,
                'rewards' => [
                    'referrer' => $referrerCredits,
                    'referee' => $refereeCredits
                ]
            ]);
        } catch (Exception $e) {
            error_log('Get invitation code error: ' . $e->getMessage());
            errorResponse('Failed to get invitation code', 500);
        }
        break;

    case 'POST':
        // Apply an invitation code to current user
        if (!$invitationEnabled) {
            errorResponse('Invitation system is not enabled');
        }

        $input = getJsonInput();
        $code = strtoupper(trim($input['code'] ?? ''));

        if (empty($code)) {
            errorResponse('Invitation code is required');
        }

        try {
            // Check if user already has a referrer
            $userData = Database::fetchOne("SELECT referred_by FROM users WHERE id = ?", [$user['id']]);
            if (!empty($userData['referred_by'])) {
                errorResponse('You have already used an invitation code');
            }

            // Find referrer by code
            $referrer = Database::fetchOne(
                "SELECT id, username FROM users WHERE invitation_code = ? AND id != ?",
                [$code, $user['id']]
            );

            if (!$referrer) {
                errorResponse('Invalid invitation code');
            }

            // Apply referral
            Database::query(
                "UPDATE users SET referred_by = ?, referred_at = NOW() WHERE id = ?",
                [$referrer['id'], $user['id']]
            );

            // Grant credits to referee (current user)
            if ($refereeCredits > 0) {
                grantReferralCredits($user['id'], $refereeCredits, 'referral_bonus', $referrer['id']);
            }

            // Grant credits to referrer
            if ($referrerCredits > 0) {
                grantReferralCredits($referrer['id'], $referrerCredits, 'referral_reward', $user['id']);
            }

            successResponse([
                'message' => 'Invitation code applied successfully!',
                'creditsReceived' => $refereeCredits,
                'referrerUsername' => $referrer['username']
            ]);

        } catch (Exception $e) {
            error_log('Apply invitation code error: ' . $e->getMessage());
            errorResponse('Failed to apply invitation code', 500);
        }
        break;

    default:
        errorResponse('Method not allowed', 405);
}

/**
 * Grant referral credits to a user
 */
function grantReferralCredits(int $userId, int $amount, string $type, int $relatedUserId): void
{
    // Get expiry days setting
    $expiryDays = 365;
    try {
        $expirySetting = Database::fetchColumn("SELECT setting_value FROM site_settings WHERE setting_key = 'credit_default_expiry_days'");
        if ($expirySetting)
            $expiryDays = (int) $expirySetting;
    } catch (Exception $e) {
        // Use default
    }

    $expiresAt = date('Y-m-d', strtotime("+$expiryDays days"));

    // Add to credit ledger
    Database::insert('credit_ledger', [
        'user_id' => $userId,
        'credits' => $amount,
        'remaining' => $amount,
        'source' => $type,
        'expires_at' => $expiresAt
    ]);

    // Get current balance
    $currentBalance = (int) Database::fetchColumn(
        "SELECT COALESCE(SUM(remaining), 0) FROM credit_ledger WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())",
        [$userId]
    );

    // Log transaction
    Database::insert('credit_transactions', [
        'user_id' => $userId,
        'type' => $type,
        'amount' => $amount,
        'balance_after' => $currentBalance,
        'description' => $type === 'referral_reward' ? 'Referral reward' : 'Referral bonus',
        'reference_id' => $type . '_' . $relatedUserId
    ]);
}
