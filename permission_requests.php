<?php
// school_owner/permission_requests.php - Owner/Principal approves teacher access requests
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id   = (int)$_SESSION['user_id'];

// Handle approve/deny actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action     = $_POST['action'];
    $request_id = (int)$_POST['request_id'];
    $notes      = trim($_POST['notes'] ?? '');

    if (!in_array($action, ['approve', 'deny'], true)) {
        $_SESSION['error'] = "Invalid action.";
        header("Location: permission_requests.php");
        exit;
    }

    // Fetch request and verify school
    $stmt = $db->prepare("SELECT * FROM permission_requests WHERE id = ? AND school_id = ?");
    $stmt->execute([$request_id, $school_id]);
    $req = $stmt->fetch();

    if (!$req) {
        $_SESSION['error'] = "Request not found.";
        header("Location: permission_requests.php");
        exit;
    }

    if ($req['status'] !== 'pending') {
        $_SESSION['error'] = "This request has already been handled.";
        header("Location: permission_requests.php");
        exit;
    }

    if ($action === 'approve') {
        $duration = (int)($req['duration_days'] ?? 30);
        $stmt = $db->prepare("UPDATE permission_requests 
                              SET status = 'approved', 
                                  responded_at = NOW(), 
                                  responded_by = ?, 
                                  expiry_date = DATE_ADD(CURDATE(), INTERVAL ? DAY),
                                  permission_notes = ?
                              WHERE id = ? AND school_id = ?");
        $stmt->execute([$user_id, $duration, $notes ?: $req['permission_notes'], $request_id, $school_id]);

        logActivity('APPROVE_PERMISSION',
            "Approved request #$request_id for " . $req['feature'] . " (" . $duration . " days)",
            $school_id, $user_id);

        $_SESSION['success'] = "✅ Request approved. Teacher now has access to " . htmlspecialchars(ucfirst(str_replace('_', ' ', $req['feature']))) . " until " . date('M d, Y', strtotime("+$duration days")) . ".";
    } else {
        $stmt = $db->prepare("UPDATE permission_requests 
                              SET status = 'denied', 
                                  responded_at = NOW(), 
                                  responded_by = ?, 
                                  permission_notes = ?
                              WHERE id = ? AND school_id = ?");
        $stmt->execute([$user_id, $notes ?: 'Denied by ' . $_SESSION['user_role'], $request_id, $school_id]);

        logActivity('DENY_PERMISSION',
            "Denied request #$request_id for " . $req['feature'],
            $school_id, $user_id);

        $_SESSION['success'] = "Request denied.";
    }

    header("Location: permission_requests.php");
    exit;
}

// Fetch all requests with teacher info
$stmt = $db->prepare("
    SELECT pr.*, u.full_name AS teacher_name, u.email AS teacher_email
    FROM permission_requests pr
    JOIN users u ON pr.teacher_id = u.id
    WHERE pr.school_id = ?
    ORDER BY 
        CASE pr.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        pr.requested_at DESC
");
$stmt->execute([$school_id]);
$requests = $stmt->fetchAll();

// Count pending
$pending_count = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'pending') $pending_count++;
}

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:16px;margin-bottom:20px;"><i class="fas fa-check-circle"></i> ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:16px;margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 style="font-weight:700; color:#0f172a;">🔐 Teacher Access Requests</h1>
        <p class="text-muted">Approve or deny teachers' requests for Question Bank and Exam Generator access.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary" style="border-radius:10px; padding:8px 18px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<?php if ($pending_count > 0): ?>
<div class="alert" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:14px 20px;margin-bottom:20px;">
    <strong><i class="fas fa-bell"></i> <?php echo $pending_count; ?> pending request<?php echo $pending_count > 1 ? 's' : ''; ?> waiting for review.</strong>
</div>
<?php endif; ?>

<div class="card" style="border-radius:16px;">
    <div class="card-body">
        <?php if (empty($requests)): ?>
            <p class="text-muted" style="padding:30px 0;text-align:center;">No requests yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="table" style="margin-bottom:0;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th style="padding:12px 16px;">Teacher</th>
                            <th style="padding:12px 16px;">Feature</th>
                            <th style="padding:12px 16px;">Duration</th>
                            <th style="padding:12px 16px;">Requested</th>
                            <th style="padding:12px 16px;">Status</th>
                            <th style="padding:12px 16px; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): 
                            $statusColors = [
                                'pending'  => ['bg'=>'#fef3c7','color'=>'#92400e','label'=>'Pending'],
                                'approved' => ['bg'=>'#dcfce7','color'=>'#166534','label'=>'Approved'],
                                'denied'   => ['bg'=>'#fee2e2','color'=>'#991b1b','label'=>'Denied'],
                            ];
                            $sc = $statusColors[$r['status']] ?? $statusColors['pending'];
                        ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px 16px;">
                                <div style="font-weight:600;"><?php echo htmlspecialchars($r['teacher_name']); ?></div>
                                <div style="font-size:12px;color:#94a3b8;"><?php echo htmlspecialchars($r['teacher_email']); ?></div>
                            </td>
                            <td style="padding:12px 16px;">
                                <span class="badge bg-primary" style="font-size:12px;">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $r['feature']))); ?>
                                </span>
                            </td>
                            <td style="padding:12px 16px;"><?php echo (int)$r['duration_days']; ?> days</td>
                            <td style="padding:12px 16px; font-size:13px;">
                                <?php echo date('M d, Y H:i', strtotime($r['requested_at'])); ?>
                            </td>
                            <td style="padding:12px 16px;">
                                <span class="badge" style="background:<?php echo $sc['bg']; ?>; color:<?php echo $sc['color']; ?>;"><?php echo $sc['label']; ?></span>
                                <?php if ($r['status'] === 'approved' && $r['expiry_date']): ?>
                                    <div style="font-size:11px;color:#64748b;margin-top:4px;">Expires: <?php echo date('M d, Y', strtotime($r['expiry_date'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px; text-align:right;">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="notes" value="">
                                        <button type="submit" class="btn btn-sm btn-success" 
                                                onclick="return confirm('Approve <?php echo $r['duration_days']; ?> days of access for <?php echo htmlspecialchars($r['teacher_name']); ?>?');">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="action" value="deny">
                                        <input type="hidden" name="notes" value="">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                onclick="return confirm('Deny this request?');">
                                            <i class="fas fa-times"></i> Deny
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>