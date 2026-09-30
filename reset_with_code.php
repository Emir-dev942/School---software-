<?php
// parent/reset_with_code.php - Reset password using code from admin
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
        $code = sanitize($_POST['code'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($phone) || empty($code) || empty($new_password)) {
            $error = 'All fields are required.';
        } elseif (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($new_password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $stmt = $db->prepare("
                SELECT * FROM password_reset_requests 
                WHERE parent_phone = ? AND reset_code = ? AND status = 'approved'
                LIMIT 1
            ");
            $stmt->execute([$phone, $code]);
            $request = $stmt->fetch();

            if (!$request) {
                $error = 'Invalid code. Please check the 6-digit code the school gave you.';
            } elseif (strtotime($request['approved_at']) < time() - 86400) {
                $error = 'This code has expired. Please ask the school for a new one.';
            } else {
                try {
                    $db->beginTransaction();
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE parent_portal SET password = ?, login_attempts = 0, locked_until = NULL WHERE id = ?")
                       ->execute([$hashed, $request['parent_id']]);
                    $db->prepare("UPDATE password_reset_requests SET status = 'used', used_at = NOW() WHERE id = ?")
                       ->execute([$request['id']]);
                    $db->commit();
                    $message = '✅ Password reset successfully! You can now log in.';
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Something went wrong. Please try again.';
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
    <title>Set New Password | Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #7c3aed, #4f46e5); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card-box { background: white; border-radius: 20px; padding: 36px 30px; width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        .icon-circle { width: 64px; height: 64px; border-radius: 18px; background: linear-gradient(135deg, #7c3aed, #4f46e5); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: 0 auto 20px; box-shadow: 0 8px 24px rgba(124,58,237,0.3); }
        .card-box h3 { font-weight: 800; color: #0f172a; text-align: center; margin-bottom: 8px; }
        .card-box .subtitle { text-align: center; color: #64748b; margin-bottom: 24px; font-size: 14px; line-height: 1.5; }
        .form-label { font-weight: 600; color: #334155; font-size: 14px; }
        .form-control { border-radius: 10px; padding: 12px 14px; border: 1px solid #e2e8f0; }
        .form-control:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,0.1); }
        .code-input { text-align: center; font-size: 24px; font-weight: 800; letter-spacing: 8px; font-family: 'Courier New', monospace; }
        .btn-primary { background: #7c3aed; border: none; border-radius: 10px; padding: 12px; font-weight: 600; width: 100%; }
        .msg-success { background: #ecfdf5; border: 1px solid #86efac; color: #065f46; border-radius: 10px; padding: 14px; font-size: 14px; margin-bottom: 16px; line-height: 1.5; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 10px; padding: 14px; font-size: 14px; margin-bottom: 16px; }
        .back-link { text-align: center; margin-top: 20px; font-size: 14px; }
        .back-link a { color: #7c3aed; text-decoration: none; font-weight: 600; }
        .back-link a:hover { text-decoration: underline; }
        .field-hint { font-size: 12px; color: #94a3b8; margin-top: 6px; display: block; line-height: 1.4; }
        .no-code { text-align: center; padding: 12px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 10px; margin-bottom: 16px; font-size: 13px; color: #92400e; }
        .no-code a { color: #92400e; font-weight: 700; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card-box">
        <div class="icon-circle"><i class="fas fa-lock-open"></i></div>
        <h3>Set New Password</h3>
        <p class="subtitle">Enter the 6-digit code the school gave you, then choose a new password.</p>

        <?php if ($message): ?>
            <div class="msg-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($message); ?>
                <br><br>
                <a href="login.php" style="color:#065f46;font-weight:700;">Log in now →</a>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="msg-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!$message): ?>
            <div class="no-code">
                Don't have a code yet? <a href="request_reset.php">Request one first →</a>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>

                <div class="mb-3">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-control" placeholder="08012345678" required autofocus>
                    <span class="field-hint">The phone number you used to log in.</span>
                </div>

                <div class="mb-3">
                    <label class="form-label">Reset Code</label>
                    <input type="text" name="code" class="form-control code-input" placeholder="000000" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" required>
                    <span class="field-hint">6-digit code from the school. Expires after 24 hours.</span>
                </div>

                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="Minimum 6 characters" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Reset Password
                </button>
            </form>
        <?php endif; ?>

        <div class="back-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>
</body>
</html>