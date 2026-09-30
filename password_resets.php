<?php
/**
 * parent/request_reset.php
 * Parent submits phone number → request queued for school admin.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

$db = getDB();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh.';
    } else {
        $phone = preg_replace('/[^0-9]/', '', sanitize($_POST['phone'] ?? ''));

        if (empty($phone)) {
            $error = 'Please enter your phone number.';
        } else {
            // Check if parent exists with this phone
            $stmt = $db->prepare("SELECT id, school_id FROM parent_portal WHERE parent_phone = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$phone]);
            $parent = $stmt->fetch();

            if (!$parent) {
                // Don't reveal if phone exists — always show success for security
                $message = 'If that phone number is registered, the school will contact you with a reset code.';
            } else {
                // Check if there's already a pending request
                $stmt = $db->prepare("SELECT id FROM password_reset_requests WHERE parent_id = ? AND status = 'pending' LIMIT 1");
                $stmt->execute([$parent['id']]);
                if ($stmt->fetch()) {
                    $message = 'You already have a pending request. Please call the school to get your code.';
                } else {
                    // Create the request
                    try {
                        $db->prepare("
                            INSERT INTO password_reset_requests (school_id, parent_id, parent_phone, status, requested_at)
                            VALUES (?, ?, ?, 'pending', NOW())
                        ")->execute([$parent['school_id'], $parent['id'], $phone]);

                        $message = '✅ Request received. Please call the school office and ask for your reset code.';
                    } catch (Exception $e) {
                        $error = 'Something went wrong. Please try again.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Password Reset | Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #7c3aed, #4f46e5); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card-box { background: white; border-radius: 20px; padding: 36px 30px; width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        .icon-circle { width: 64px; height: 64px; border-radius: 18px; background: linear-gradient(135deg, #f59e0b, #d97706); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: 0 auto 20px; box-shadow: 0 8px 24px rgba(217,119,6,0.3); }
        .card-box h3 { font-weight: 800; color: #0f172a; text-align: center; margin-bottom: 8px; }
        .card-box .subtitle { text-align: center; color: #64748b; margin-bottom: 24px; font-size: 14px; line-height: 1.5; }
        .form-label { font-weight: 600; color: #334155; font-size: 14px; }
        .form-control { border-radius: 10px; padding: 12px 14px; border: 1px solid #e2e8f0; }
        .form-control:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,0.1); }
        .btn-primary { background: #7c3aed; border: none; border-radius: 10px; padding: 12px; font-weight: 600; width: 100%; }
        .msg-success { background: #ecfdf5; border: 1px solid #86efac; color: #065f46; border-radius: 10px; padding: 14px; font-size: 14px; margin-bottom: 16px; line-height: 1.5; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 14px; font-size: 14px; margin-bottom: 16px; }
        .back-link { text-align: center; margin-top: 20px; font-size: 14px; }
        .back-link a { color: #7c3aed; text-decoration: none; font-weight: 600; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card-box">
        <div class="icon-circle"><i class="fas fa-key"></i></div>
        <h3>Forgot Password?</h3>
        <p class="subtitle">Enter your phone number. The school will give you a 6-digit code to reset your password.</p>

        <?php if ($message): ?>
            <div class="msg-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <br><br>
                <a href="reset_with_code.php" style="color:#065f46;font-weight:700;">Enter my code →</a>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="msg-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!$message): ?>
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="mb-3">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-control" placeholder="08012345678" required autofocus>
                <div style="font-size:12px;color:#94a3b8;margin-top:6px;">The number you used to log in.</div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Request Reset Code
            </button>
        </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>
</body>
</html>