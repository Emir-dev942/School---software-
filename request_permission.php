<?php
// teacher/request_permission.php - Request Access to Question Bank / Exam Generator (v2)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Teacher']);
requireCsrf();

$db = getDB();
$teacher_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['school_id'];

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $feature = sanitize($_POST['feature'] ?? '');
    $duration_days = (int)($_POST['duration_days'] ?? 30);
    $notes = sanitize($_POST['notes'] ?? '');

    $valid_features = ['question_bank', 'exam_generator'];
    if (!in_array($feature, $valid_features)) {
        $error = "Invalid feature selected.";
    } elseif ($duration_days <= 0 || $duration_days > 365) {
        $error = "Duration must be between 1 and 365 days.";
    } else {
        $check = $db->prepare("SELECT id FROM permission_requests 
                               WHERE teacher_id = ? AND school_id = ? AND feature = ? AND status = 'pending'");
        $check->execute([$teacher_id, $school_id, $feature]);
        if ($check->fetch()) {
            $error = "You already have a pending request for this feature.";
        } else {
            // Check if already approved and not expired
            $existing = $db->prepare("SELECT id, expiry_date FROM permission_requests 
                                      WHERE teacher_id = ? AND school_id = ? AND feature = ? 
                                        AND status = 'approved' 
                                        AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                                      ORDER BY id DESC LIMIT 1");
            $existing->execute([$teacher_id, $school_id, $feature]);
            if ($existing->fetch()) {
                $error = "You already have active access to this feature.";
            } else {
                $stmt = $db->prepare("INSERT INTO permission_requests 
                    (school_id, teacher_id, feature, status, requested_at, duration_days, permission_notes) 
                    VALUES (?, ?, ?, 'pending', NOW(), ?, ?)");
                if ($stmt->execute([$school_id, $teacher_id, $feature, $duration_days, $notes])) {
                    logActivity('REQUEST_PERMISSION', "Requested $feature access for $duration_days days.", $school_id, $teacher_id);
                    $message = "✅ Request submitted. The school owner will review it.";
                } else {
                    $error = "Failed to submit request.";
                }
            }
        }
    }
}

// Fetch existing requests
$requestsStmt = $db->prepare("
    SELECT feature, status, requested_at, responded_at, duration_days, expiry_date, permission_notes
    FROM permission_requests
    WHERE teacher_id = ? AND school_id = ?
    ORDER BY requested_at DESC
    LIMIT 20
");
$requestsStmt->execute([$teacher_id, $school_id]);
$requests = $requestsStmt->fetchAll();

// Check current access status
$hasQuestionBank = hasPermission(PERM_VIEW_QUESTION_BANK);
$hasExamGen = hasPermission(PERM_GENERATE_EXAMS);

include_once __DIR__ . '/../includes/header.php';
?>

<style>
    .access-card {
        background: #fff; border-radius: 16px; padding: 24px; border: 1px solid #f1f5f9;
        margin-bottom: 20px;
    }
    .access-status {
        display: flex; align-items: center; gap: 12px; padding: 12px 16px;
        border-radius: 12px; margin-bottom: 12px;
    }
    .access-active { background: #dcfce7; color: #166534; }
    .access-inactive { background: #f1f5f9; color: #475569; }

    .request-form {
        background: #f8fafc; border-radius: 12px; padding: 20px; margin-top: 16px;
    }

    .history-row {
        display: grid; grid-template-columns: 1fr auto auto auto; gap: 16px;
        padding: 14px 16px; border-bottom: 1px solid #f1f5f9;
        align-items: center; font-size: 0.9rem;
    }
    .history-row:last-child { border-bottom: none; }
    .status-pill {
        display: inline-block; padding: 4px 12px; border-radius: 20px;
        font-size: 0.75rem; font-weight: 700;
    }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-approved { background: #dcfce7; color: #166534; }
    .status-denied { background: #fee2e2; color: #991b1b; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">🔑 Request Access</h1>
        <p style="color:#64748b; margin:0;">Ask the school owner for access to the Question Bank or Exam Generator.</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:16px;margin-bottom:20px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:16px;margin-bottom:20px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<!-- Current Access Status -->
<div class="access-card">
    <h5 style="font-weight:700; margin-bottom:16px;">Your Current Access</h5>

    <div class="access-status <?php echo $hasQuestionBank ? 'access-active' : 'access-inactive'; ?>">
        <?php if ($hasQuestionBank): ?>
            <i class="fas fa-check-circle" style="font-size:1.5rem;"></i>
            <div>
                <strong>Question Bank</strong>
                <div style="font-size:0.85rem; opacity:0.85;">You have access</div>
            </div>
        <?php else: ?>
            <i class="fas fa-lock" style="font-size:1.5rem;"></i>
            <div>
                <strong>Question Bank</strong>
                <div style="font-size:0.85rem; opacity:0.85;">You do not have access</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="access-status <?php echo $hasExamGen ? 'access-active' : 'access-inactive'; ?>">
        <?php if ($hasExamGen): ?>
            <i class="fas fa-check-circle" style="font-size:1.5rem;"></i>
            <div>
                <strong>Exam Generator</strong>
                <div style="font-size:0.85rem; opacity:0.85;">You have access</div>
            </div>
        <?php else: ?>
            <i class="fas fa-lock" style="font-size:1.5rem;"></i>
            <div>
                <strong>Exam Generator</strong>
                <div style="font-size:0.85rem; opacity:0.85;">You do not have access</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Request Form (only show if at least one feature is locked) -->
    <?php if (!$hasQuestionBank || !$hasExamGen): ?>
    <div class="request-form">
        <h6 style="font-weight:700; margin-bottom:14px;">Submit a New Request</h6>
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Feature</label>
                    <select name="feature" class="form-control" required>
                        <?php if (!$hasQuestionBank): ?>
                            <option value="question_bank">📚 Question Bank</option>
                        <?php endif; ?>
                        <?php if (!$hasExamGen): ?>
                            <option value="exam_generator">📝 Exam Generator</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Duration (days)</label>
                    <input type="number" name="duration_days" class="form-control" value="30" min="1" max="365" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold" style="font-size:0.85rem;">Reason (optional)</label>
                    <input type="text" name="notes" class="form-control" placeholder="e.g., preparing first term exams">
                </div>
            </div>
            <button type="submit" name="submit_request" value="1" class="btn btn-primary mt-3" 
                    style="background:linear-gradient(135deg,#7c3aed,#6d28d9); border:none; border-radius:10px; padding:10px 32px; font-weight:600;">
                <i class="fas fa-paper-plane"></i> Submit Request
            </button>
        </form>
    </div>
    <?php else: ?>
    <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; border-radius:12px; padding:14px 18px; margin-top:12px;">
        <i class="fas fa-check-circle"></i> You have access to all features. Nothing to request.
    </div>
    <?php endif; ?>
</div>

<!-- Request History -->
<div class="access-card">
    <h5 style="font-weight:700; margin-bottom:16px;">Your Request History</h5>
    <?php if (empty($requests)): ?>
        <p style="color:#94a3b8; text-align:center; padding:20px;">You have not made any requests yet.</p>
    <?php else: ?>
        <div>
            <?php foreach ($requests as $req): 
                $label = ucfirst(str_replace('_', ' ', $req['feature']));
                $statusLabel = ucfirst($req['status']);
                $statusClass = 'status-' . $req['status'];
            ?>
                <div class="history-row">
                    <div>
                        <strong><?php echo htmlspecialchars($label); ?></strong>
                        <?php if (!empty($req['permission_notes'])): ?>
                            <div style="font-size:0.8rem; color:#94a3b8;">"<?php echo htmlspecialchars($req['permission_notes']); ?>"</div>
                        <?php endif; ?>
                    </div>
                    <div><span class="status-pill <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></div>
                    <div style="color:#64748b; font-size:0.85rem;"><?php echo $req['duration_days']; ?> days</div>
                    <div style="color:#94a3b8; font-size:0.8rem;"><?php echo date('M d, Y', strtotime($req['requested_at'])); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>