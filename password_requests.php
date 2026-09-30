<?php
// school_owner/password_requests.php - Manage parent password reset requests
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];

// ---------- APPROVE REQUEST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_request'])) {
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id > 0) {
        // Generate a unique 6-digit code
        $code = null;
        for ($i = 0; $i < 5; $i++) {
            $candidate = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $check = $db->prepare("SELECT 1 FROM password_reset_requests WHERE reset_code = ? AND status = 'approved'");
            $check->execute([$candidate]);
            if (!$check->fetch()) { $code = $candidate; break; }
        }

        if ($code === null) {
            $_SESSION['error'] = 'Could not generate a unique code. Try again.';
        } else {
            $stmt = $db->prepare("UPDATE password_reset_requests SET status = 'approved', reset_code = ?, approved_at = NOW(), approved_by = ? WHERE id = ? AND school_id = ? AND status = 'pending'");
            if ($stmt->execute([$code, $user_id, $request_id, $school_id])) {
                // Fetch the phone so we can display it with the code
                $p = $db->prepare("SELECT parent_phone FROM password_reset_requests WHERE id = ? AND school_id = ?");
                $p->execute([$request_id, $school_id]);
                $phone = $p->fetchColumn();

                logActivity('APPROVE_PASSWORD_RESET', "Approved password reset request #$request_id for phone $phone", $school_id, $user_id);

                // Store code in session so it survives the redirect
                $_SESSION['last_reset_code'] = $code;
                $_SESSION['last_reset_phone'] = $phone;
                $_SESSION['success'] = 'Reset code generated successfully.';
            }
        }
    }
    header("Location: password_requests.php");
    exit;
}

// ---------- REJECT REQUEST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_request'])) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
        $stmt = $db->prepare("UPDATE password_reset_requests SET status = 'rejected', approved_at = NOW(), approved_by = ? WHERE id = ? AND school_id = ? AND status = 'pending'");
        $stmt->execute([$user_id, $request_id, $school_id]);
        $_SESSION['success'] = 'Request rejected.';
    }
    header("Location: password_requests.php");
    exit;
}

// ---------- DELETE REQUEST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $request_id = (int)($_POST['request_id'] ?? 0);
    if ($request_id > 0) {
        $db->prepare("DELETE FROM password_reset_requests WHERE id = ? AND school_id = ?")
           ->execute([$request_id, $school_id]);
        $_SESSION['success'] = 'Request deleted.';
    }
    header("Location: password_requests.php");
    exit;
}

// ---------- FETCH ----------
$pendingStmt = $db->prepare("
    SELECT pr.*, 
           s.first_name AS child_first, s.last_name AS child_last, s.student_id AS child_code
    FROM password_reset_requests pr
    LEFT JOIN parent_portal pp ON pr.parent_id = pp.id
    LEFT JOIN students s ON pp.student_id = s.id
    WHERE pr.school_id = ? AND pr.status = 'pending'
    ORDER BY pr.requested_at DESC
");
$pendingStmt->execute([$school_id]);
$pending = $pendingStmt->fetchAll();

$approvedStmt = $db->prepare("
    SELECT pr.*, 
           s.first_name AS child_first, s.last_name AS child_last
    FROM password_reset_requests pr
    LEFT JOIN parent_portal pp ON pr.parent_id = pp.id
    LEFT JOIN students s ON pp.student_id = s.id
    WHERE pr.school_id = ? 
      AND pr.status = 'approved' 
      AND pr.approved_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY pr.approved_at DESC
");
$approvedStmt->execute([$school_id]);
$approved = $approvedStmt->fetchAll();

$historyStmt = $db->prepare("
    SELECT pr.*,
           s.first_name AS child_first, s.last_name AS child_last
    FROM password_reset_requests pr
    LEFT JOIN parent_portal pp ON pr.parent_id = pp.id
    LEFT JOIN students s ON pp.student_id = s.id
    WHERE pr.school_id = ? AND pr.status IN ('used','rejected') 
    ORDER BY pr.requested_at DESC LIMIT 20
");
$historyStmt->execute([$school_id]);
$history = $historyStmt->fetchAll();

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:16px;border-radius:12px;margin-bottom:20px;"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:16px;border-radius:12px;margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4" style="flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">🔑 Password Reset Requests</h1>
        <p class="text-muted" style="margin:0;">Approve parent requests and read out the reset codes.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary">Back</a>
</div>

<?php if (isset($_SESSION['last_reset_code'])): ?>
    <div style="background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 2px solid #86efac; border-radius: 16px; padding: 24px; margin-bottom: 24px; text-align: center;">
        <div style="font-size: 13px; color: #166534; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">Code Generated</div>
        <div style="font-size: 42px; font-weight: 900; color: #15803d; font-family: 'Courier New', monospace; letter-spacing: 10px; margin: 12px 0;">
            <?php echo htmlspecialchars($_SESSION['last_reset_code']); ?>
        </div>
        <div style="font-size: 14px; color: #166534; margin-bottom: 16px;">
            For phone: <strong><?php echo htmlspecialchars($_SESSION['last_reset_phone']); ?></strong>
        </div>
        <button onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($_SESSION['last_reset_code']); ?>'); this.textContent='✓ Copied';" style="background:#16a34a;color:white;border:none;padding:10px 24px;border-radius:8px;font-weight:700;cursor:pointer;">
            📋 Copy Code
        </button>
        <div style="font-size: 12px; color: #166534; margin-top: 12px;">
            ⚠️ Tell this code to the parent. It expires in 24 hours.
        </div>
    </div>
    <?php unset($_SESSION['last_reset_code'], $_SESSION['last_reset_phone']); ?>
<?php endif; ?>

<!-- Pending Requests -->
<div style="background:#fff;border-radius:16px;padding:20px;border:1px solid #f1f5f9;margin-bottom:20px;">
    <h5 style="font-weight:700;margin-bottom:16px;">⏳ Pending Requests (<?php echo count($pending); ?>)</h5>
    <?php if (empty($pending)): ?>
        <p class="text-muted text-center py-3" style="margin:0;">No pending requests.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="table">
                <thead><tr><th>Parent Phone</th><th>Child</th><th>Requested</th><th style="text-align:right;">Action</th></tr></thead>
                <tbody>
                    <?php foreach ($pending as $r): ?>
                    <tr>
                        <td><strong style="font-family:monospace;"><?php echo htmlspecialchars($r['parent_phone']); ?></strong></td>
                        <td>
                            <?php if ($r['child_first']): ?>
                                <?php echo htmlspecialchars($r['child_first'] . ' ' . $r['child_last']); ?>
                                <?php if ($r['child_code']): ?><br><small class="text-muted"><?php echo htmlspecialchars($r['child_code']); ?></small><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M d, Y H:i', strtotime($r['requested_at'])); ?></td>
                        <td style="text-align:right;white-space:nowrap;">
                            <form method="POST" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" name="approve_request" value="1" class="btn btn-sm btn-success" style="border-radius:8px;font-weight:700;">
                                    <i class="fas fa-key"></i> Generate Code
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Reject this request?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                <button type="submit" name="reject_request" value="1" class="btn btn-sm btn-outline-danger" style="border-radius:8px;">Reject</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Approved - Show Codes -->
<?php if (!empty($approved)): ?>
<div style="background:#fffbeb;border-radius:16px;padding:20px;border:1px solid #fde68a;margin-bottom:20px;">
    <h5 style="font-weight:700;margin-bottom:16px;">✅ Active Codes (last 24 hours)</h5>
    <div style="overflow-x:auto;">
        <table class="table">
            <thead><tr><th>Parent Phone</th><th>Child</th><th>Code</th><th>Approved</th></tr></thead>
            <tbody>
                <?php foreach ($approved as $r): ?>
                <tr>
                    <td><strong style="font-family:monospace;"><?php echo htmlspecialchars($r['parent_phone']); ?></strong></td>
                    <td>
                        <?php if ($r['child_first']): ?>
                            <?php echo htmlspecialchars($r['child_first'] . ' ' . $r['child_last']); ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-family:'Courier New',monospace;font-size:18px;font-weight:900;color:#15803d;background:#f0fdf4;padding:6px 14px;border-radius:8px;letter-spacing:4px;">
                            <?php echo htmlspecialchars($r['reset_code']); ?>
                        </span>
                    </td>
                    <td><?php echo date('M d, H:i', strtotime($r['approved_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="font-size:13px;color:#92400e;margin:10px 0 0;">Codes expire after 24 hours.</p>
</div>
<?php endif; ?>

<!-- History -->
<?php if (!empty($history)): ?>
<div style="background:#fff;border-radius:16px;padding:20px;border:1px solid #f1f5f9;">
    <h5 style="font-weight:700;margin-bottom:16px;">📋 History</h5>
    <div style="overflow-x:auto;">
        <table class="table">
            <thead><tr><th>Phone</th><th>Child</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($history as $r): ?>
                <tr>
                    <td style="font-family:monospace;"><?php echo htmlspecialchars($r['parent_phone']); ?></td>
                    <td>
                        <?php if ($r['child_first']): ?>
                            <?php echo htmlspecialchars($r['child_first'] . ' ' . $r['child_last']); ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?php echo $r['status'] === 'used' ? 'success' : 'secondary'; ?>"><?php echo ucfirst($r['status']); ?></span></td>
                    <td><?php echo date('M d, Y', strtotime($r['requested_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>