<?php
// school_owner/view_student.php - Complete Student Profile with Tabs
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/student_functions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($student_id <= 0) {
    header("Location: manage_students.php");
    exit;
}

$student = getStudent($student_id, $school_id);
if (!$student) {
    header("Location: manage_students.php");
    exit;
}

$schoolStmt = $db->prepare("SELECT current_term, current_session, school_name FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch();
$current_term = $schoolInfo['current_term'] ?? 'Term 1';
$current_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

$selected_term = $_GET['term'] ?? $current_term;
$selected_session = $_GET['session'] ?? $current_session;

// ---------- HANDLE: Reset Parent Password ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_parent_password'])) {
    $stmt = $db->prepare("SELECT id, parent_phone FROM parent_portal WHERE school_id = ? AND student_id = ?");
    $stmt->execute([$school_id, $student_id]);
    $pp = $stmt->fetch();

    if ($pp) {
        $new_password = bin2hex(random_bytes(4));
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        $db->prepare("UPDATE parent_portal SET password = ?, login_attempts = 0, locked_until = NULL WHERE id = ?")
           ->execute([$hashed, $pp['id']]);

        logActivity('RESET_PARENT_PASSWORD', "Reset parent portal password for student ID: $student_id", $school_id, $user_id);

        $_SESSION['success'] = "✅ Parent password reset. New password: <strong>$new_password</strong> — give this to the parent.";
    } else {
        $_SESSION['error'] = "No parent portal account exists for this student.";
    }
    header("Location: view_student.php?id=" . $student_id);
    exit;
}

// ---------- HANDLE: Send SMS to Parent ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    $message = trim($_POST['sms_message'] ?? '');
    if (empty($message)) {
        $_SESSION['error'] = "Message is required.";
    } elseif (empty($student['parent_phone'])) {
        $_SESSION['error'] = "No parent phone on file.";
    } else {
        try {
            $db->prepare("INSERT INTO sms_logs (school_id, recipient, message, status, sent_by, sent_at, sms_type) VALUES (?, ?, ?, 'pending', ?, NOW(), 'manual')")
               ->execute([$school_id, $student['parent_phone'], $message, $user_id]);
            logActivity('SEND_SMS', "Sent SMS to parent of student ID: $student_id", $school_id, $user_id);
            $_SESSION['success'] = "✅ SMS queued for delivery to {$student['parent_phone']}.";
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to queue SMS: " . $e->getMessage();
        }
    }
    header("Location: view_student.php?id=" . $student_id);
    exit;
}

$fees = getStudentFees($student_id, $school_id, $selected_term, $selected_session);
$attendance = getStudentAttendance($student_id, $school_id, $selected_term, $selected_session);
$scores = getStudentScores($student_id, $school_id, $selected_term, $selected_session);
$reports = getStudentReports($student_id, $school_id);
$terms = getStudentTerms($student_id, $school_id);

// Fetch parent portal info
$ppStmt = $db->prepare("SELECT parent_phone, parent_email, last_login, is_active FROM parent_portal WHERE school_id = ? AND student_id = ?");
$ppStmt->execute([$school_id, $student_id]);
$parentPortal = $ppStmt->fetch();

// Compute alerts
$alerts = [];
$balance = (float)$fees['balance'];
$attendancePct = 0;
$attTotal = array_sum($attendance['summary']);
if ($attTotal > 0) {
    $attendancePct = round(($attendance['summary']['Present'] / $attTotal) * 100, 1);
}
if ($balance > 0) {
    $alerts[] = ['type' => 'danger', 'text' => "Owing " . formatCurrency($balance) . " for {$selected_term} {$selected_session}"];
}
if ($attTotal > 0 && $attendancePct < 75) {
    $alerts[] = ['type' => 'warning', 'text' => "Attendance is {$attendancePct}% (below 75%)"];
}
if (!empty($reports)) {
    $alerts[] = ['type' => 'success', 'text' => count($reports) . " report card(s) available"];
}

$photo_url = ($student['photo_path'] && $student['photo_path'] !== 'default_student.png')
    ? BASE_URL . 'uploads/student_photos/' . $student['photo_path']
    : BASE_URL . 'assets/images/default_avatar.png';

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

<style>
    .student-hero {
        background: linear-gradient(135deg, #7c3aed, #4f46e5);
        border-radius: 20px;
        padding: 24px;
        color: white;
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 16px;
    }
    .student-hero img {
        width: 96px;
        height: 96px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid rgba(255,255,255,0.4);
        cursor: pointer;
    }
    .student-hero .name { font-size: 1.5rem; font-weight: 800; }
    .student-hero .meta { font-size: 0.9rem; opacity: 0.9; margin-top: 4px; }
    .hero-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
    .hero-badge {
        background: rgba(255,255,255,0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .quick-actions {
        display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;
    }
    .quick-actions a, .quick-actions button {
        border-radius: 10px; padding: 8px 16px; font-size: 0.85rem; font-weight: 600;
        text-decoration: none; border: 1px solid #e2e8f0; background: white; color: #334155;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .quick-actions a:hover, .quick-actions button:hover {
        background: #f8fafc; border-color: #cbd5e1;
    }

    .alert-strip {
        border-radius: 12px; padding: 12px 18px; margin-bottom: 20px; font-size: 0.9rem;
        display: flex; flex-direction: column; gap: 6px;
    }
    .alert-strip-item { display: flex; align-items: center; gap: 8px; }

    .tab-nav {
        display: flex; gap: 4px; border-bottom: 2px solid #f1f5f9; margin-bottom: 20px;
        overflow-x: auto;
    }
    .tab-nav button {
        padding: 12px 20px; background: none; border: none; cursor: pointer;
        font-size: 0.9rem; font-weight: 600; color: #64748b;
        border-bottom: 2px solid transparent; margin-bottom: -2px; white-space: nowrap;
    }
    .tab-nav button.active {
        color: #7c3aed; border-bottom-color: #7c3aed;
    }
    .tab-nav button:hover { color: #7c3aed; }

    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    .section-box {
        background: white; border-radius: 16px; padding: 24px;
        margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .section-box h5 {
        font-weight: 700; color: #0f172a; margin-bottom: 18px; font-size: 1rem;
        display: flex; align-items: center; gap: 8px;
    }
    .info-row {
        display: flex; padding: 10px 0; border-bottom: 1px solid #f1f5f9;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .lbl { flex: 0 0 160px; color: #64748b; font-size: 0.85rem; font-weight: 600; }
    .info-row .val { color: #0f172a; font-size: 0.9rem; font-weight: 500; flex: 1; }

    .badge-status { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
    .badge-paid, .badge-approved, .badge-present, .badge-active { background: #dcfce7; color: #166534; }
    .badge-partial, .badge-submitted, .badge-late { background: #fef3c7; color: #92400e; }
    .badge-pending, .badge-absent, .badge-archived { background: #fee2e2; color: #991b1b; }
    .badge-draft { background: #f1f5f9; color: #64748b; }
    .badge-excused { background: #dbeafe; color: #1e40af; }

    .quick-stats {
        display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px;
    }
    .stat-card {
        background: white; border-radius: 14px; padding: 16px; text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .stat-card .num { font-size: 1.5rem; font-weight: 800; }
    .stat-card .lbl { font-size: 0.7rem; color: #64748b; font-weight: 700; text-transform: uppercase; margin-top: 4px; }

    .term-selector {
        background: white; border-radius: 14px; padding: 12px 16px; margin-bottom: 16px;
        display: flex; gap: 12px; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    }
    .term-selector label { font-weight: 600; font-size: 0.8rem; color: #64748b; margin: 0; }
    .term-selector select { border-radius: 10px; border-color: #e2e8f0; font-weight: 600; font-size: 0.85rem; }

    .action-panel {
        background: #f8fafc; border-radius: 12px; padding: 16px; margin-top: 12px;
    }
    .action-panel h6 { font-weight: 700; margin-bottom: 12px; color: #334155; font-size: 0.9rem; }
    .action-panel input, .action-panel textarea { border-radius: 8px; border-color: #e2e8f0; }
</style>

<!-- Back / Top Actions -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="manage_students.php" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left"></i> Back to Students
    </a>
    <div style="display:flex; gap:8px;">
        <a href="student_records.php?student_id=<?php echo $student_id; ?>" class="btn btn-outline-primary" style="border-radius:10px;">
            <i class="fas fa-history"></i> Full History
        </a>
        <a href="student_print.php?id=<?php echo $student_id; ?>" target="_blank" class="btn btn-outline-dark" style="border-radius:10px;">
            <i class="fas fa-print"></i> Print Profile
        </a>
    </div>
</div>

<!-- Hero Header -->
<div class="student-hero">
    <img src="<?php echo $photo_url; ?>" alt="Photo" class="viewable-photo">
    <div style="flex:1;">
        <div class="name"><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?></div>
        <div class="meta">
            <?php echo htmlspecialchars($student['student_id']); ?> • 
            <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> • 
            <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?>
        </div>
        <div class="hero-badges">
            <span class="hero-badge"><?php echo htmlspecialchars($student['status']); ?></span>
            <?php if ($parentPortal): ?>
                <span class="hero-badge"><i class="fas fa-user-check"></i> Parent Portal Active</span>
            <?php else: ?>
                <span class="hero-badge" style="background: rgba(220,38,38,0.5);"><i class="fas fa-user-slash"></i> No Parent Portal</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="quick-actions">
    <a href="manage_students.php?edit=<?php echo $student_id; ?>"><i class="fas fa-edit"></i> Edit Student</a>
    <button onclick="document.getElementById('smsPanel').style.display='block'; this.style.display='none';"><i class="fas fa-sms"></i> Send SMS</button>
    <?php if ($balance > 0): ?>
        <a href="fees.php?class_id=<?php echo (int)$student['class_id']; ?>&search=<?php echo urlencode($student['first_name'] . ' ' . $student['last_name']); ?>"><i class="fas fa-money-bill"></i> Record Payment</a>
    <?php endif; ?>
    <?php if ($student['status'] === 'Active'): ?>
        <a href="manage_students.php?archive=<?php echo $student_id; ?>" onclick="return confirm('Archive this student?')" style="color:#dc2626;"><i class="fas fa-archive"></i> Archive</a>
    <?php else: ?>
        <a href="manage_students.php?restore=<?php echo $student_id; ?>" onclick="return confirm('Restore this student?')" style="color:#16a34a;"><i class="fas fa-undo"></i> Restore</a>
    <?php endif; ?>
</div>

<!-- SMS Panel (hidden by default) -->
<div id="smsPanel" style="display:none; margin-bottom: 20px;">
    <div class="section-box">
        <h5><i class="fas fa-sms"></i> Send SMS to Parent</h5>
        <?php if (empty($student['parent_phone'])): ?>
            <p class="text-danger">No parent phone on file. Update the student first.</p>
        <?php else: ?>
            <p style="color:#64748b; font-size:0.85rem;">To: <strong><?php echo htmlspecialchars($student['parent_phone']); ?></strong> (<?php echo htmlspecialchars($student['parent_name'] ?? 'Parent'); ?>)</p>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="send_sms" value="1">
                <textarea name="sms_message" class="form-control" rows="3" placeholder="Type your message..." required maxlength="480"></textarea>
                <div style="margin-top: 12px; display:flex; gap:8px;">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Queue SMS</button>
                    <button type="button" class="btn btn-light" onclick="document.getElementById('smsPanel').style.display='none';">Cancel</button>
                </div>
                <small class="text-muted" style="display:block; margin-top:8px;">SMS will be queued. It sends when Termii integration is live.</small>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Alert Strip -->
<?php if (!empty($alerts)): ?>
<div class="alert-strip" style="background:#fefce8; border:1px solid #fde047;">
    <?php foreach ($alerts as $a): 
        $color = $a['type'] === 'danger' ? '#dc2626' : ($a['type'] === 'warning' ? '#ca8a04' : '#16a34a');
        $icon = $a['type'] === 'danger' ? 'fa-exclamation-circle' : ($a['type'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle');
    ?>
        <div class="alert-strip-item" style="color: <?php echo $color; ?>;">
            <i class="fas <?php echo $icon; ?>"></i>
            <span><?php echo htmlspecialchars($a['text']); ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Term Selector -->
<?php if (!empty($terms)): ?>
<div class="term-selector">
    <label>Viewing:</label>
    <form method="GET" style="display:flex; gap:10px; flex:1;">
        <input type="hidden" name="id" value="<?php echo $student_id; ?>">
        <select name="term" class="form-control" onchange="this.form.submit()">
            <?php foreach ($terms as $t): ?>
                <option value="<?php echo htmlspecialchars($t['term_name']); ?>" <?php echo $selected_term === $t['term_name'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($t['term_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="session" class="form-control" onchange="this.form.submit()">
            <?php 
            $unique_sessions = array_unique(array_column($terms, 'session_year'));
            foreach ($unique_sessions as $sess): ?>
                <option value="<?php echo htmlspecialchars($sess); ?>" <?php echo $selected_session === $sess ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sess); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php endif; ?>

<!-- Quick Stats -->
<div class="quick-stats">
    <div class="stat-card">
        <div class="num" style="color:<?php echo $balance > 0 ? '#dc2626' : '#16a34a'; ?>;">
            <?php echo $balance > 0 ? '₦' . number_format($balance) : '✓'; ?>
        </div>
        <div class="lbl">Fee Balance</div>
    </div>
    <div class="stat-card">
        <div class="num" style="color:#16a34a;"><?php echo $attendance['summary']['Present']; ?></div>
        <div class="lbl">Present</div>
    </div>
    <div class="stat-card">
        <div class="num" style="color:#7c3aed;"><?php echo $scores['average']; ?>%</div>
        <div class="lbl">Average</div>
    </div>
</div>

<!-- Tab Navigation -->
<div class="tab-nav">
    <button class="tab-btn active" data-tab="overview"><i class="fas fa-user"></i> Overview</button>
    <button class="tab-btn" data-tab="fees"><i class="fas fa-money-bill"></i> Fees</button>
    <button class="tab-btn" data-tab="attendance"><i class="fas fa-calendar-check"></i> Attendance</button>
    <button class="tab-btn" data-tab="scores"><i class="fas fa-chart-line"></i> Scores</button>
    <button class="tab-btn" data-tab="reports"><i class="fas fa-file-alt"></i> Reports</button>
    <button class="tab-btn" data-tab="portal"><i class="fas fa-user-lock"></i> Parent Portal</button>
</div>

<!-- Tab: Overview -->
<div class="tab-pane active" data-tab-pane="overview">
    <div class="section-box">
        <h5><i class="fas fa-user"></i> Student Information</h5>
        <div class="info-row"><div class="lbl">Full Name</div><div class="val"><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?></div></div>
        <div class="info-row"><div class="lbl">Student ID</div><div class="val"><?php echo htmlspecialchars($student['student_id']); ?></div></div>
        <div class="info-row"><div class="lbl">Class</div><div class="val"><?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?></div></div>
        <div class="info-row"><div class="lbl">Gender</div><div class="val"><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></div></div>
        <div class="info-row"><div class="lbl">Date of Birth</div><div class="val"><?php echo !empty($student['date_of_birth']) ? date('M d, Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></div></div>
        <div class="info-row"><div class="lbl">Admission Date</div><div class="val"><?php echo !empty($student['admission_date']) ? date('M d, Y', strtotime($student['admission_date'])) : 'N/A'; ?></div></div>
        <div class="info-row"><div class="lbl">Status</div><div class="val"><span class="badge-status badge-<?php echo strtolower($student['status']); ?>"><?php echo $student['status']; ?></span></div></div>
    </div>

    <div class="section-box">
        <h5><i class="fas fa-users"></i> Parent / Guardian</h5>
        <div class="info-row"><div class="lbl">Name</div><div class="val"><?php echo htmlspecialchars($student['parent_name'] ?? 'N/A'); ?></div></div>
        <div class="info-row"><div class="lbl">Phone</div><div class="val"><?php echo htmlspecialchars($student['parent_phone'] ?? 'N/A'); ?></div></div>
        <div class="info-row"><div class="lbl">Email</div><div class="val"><?php echo htmlspecialchars($student['parent_email'] ?? 'N/A'); ?></div></div>
        <div class="info-row"><div class="lbl">Occupation</div><div class="val"><?php echo htmlspecialchars($student['parent_occupation'] ?? 'N/A'); ?></div></div>
        <div class="info-row"><div class="lbl">Address</div><div class="val"><?php echo htmlspecialchars($student['address'] ?? 'N/A'); ?></div></div>
    </div>

    <?php if (!empty($student['medical_notes'])): ?>
    <div class="section-box">
        <h5><i class="fas fa-notes-medical"></i> Medical Notes</h5>
        <p><?php echo nl2br(htmlspecialchars($student['medical_notes'])); ?></p>
    </div>
    <?php endif; ?>
</div>

<!-- Tab: Fees -->
<div class="tab-pane" data-tab-pane="fees">
    <div class="section-box">
        <h5><i class="fas fa-money-bill"></i> Fees (<?php echo $selected_term; ?> • <?php echo $selected_session; ?>)</h5>
        <?php if ($fees['invoice']): ?>
            <div class="info-row"><div class="lbl">Total Fee</div><div class="val"><?php echo formatCurrency($fees['invoice']['total_amount']); ?></div></div>
            <div class="info-row"><div class="lbl">Amount Paid</div><div class="val" style="color:#16a34a;"><?php echo formatCurrency($fees['invoice']['paid_amount']); ?></div></div>
            <div class="info-row"><div class="lbl">Balance</div><div class="val" style="color:<?php echo $balance > 0 ? '#dc2626' : '#16a34a'; ?>; font-weight: 700;"><?php echo formatCurrency($balance); ?></div></div>
            <div class="info-row"><div class="lbl">Status</div><div class="val"><span class="badge-status badge-<?php echo $fees['invoice']['status']; ?>"><?php echo ucfirst($fees['invoice']['status']); ?></span></div></div>
            
            <?php if (!empty($fees['payments'])): ?>
                <div style="margin-top:20px;">
                    <strong style="font-size:0.9rem;">Payment History</strong>
                    <table class="table table-sm mt-2">
                        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Receipt</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($fees['payments'] as $p): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                                <td><?php echo formatCurrency($p['amount_paid']); ?></td>
                                <td><?php echo htmlspecialchars($p['payment_method']); ?></td>
                                <td><small><?php echo htmlspecialchars($p['receipt_number']); ?></small></td>
                                <td><?php echo !empty($p['reversed']) ? '<span class="badge-status badge-pending">Reversed</span>' : '<span class="badge-status badge-paid">Active</span>'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted">No invoice for this term.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Tab: Attendance -->
<div class="tab-pane" data-tab-pane="attendance">
    <div class="section-box">
        <h5><i class="fas fa-calendar-check"></i> Attendance (<?php echo $selected_term; ?> • <?php echo $selected_session; ?>)</h5>
        <div class="quick-stats" style="margin-bottom:16px;">
            <div class="stat-card" style="padding:12px;"><div class="num" style="color:#16a34a;font-size:1.2rem;"><?php echo $attendance['summary']['Present']; ?></div><div class="lbl">Present</div></div>
            <div class="stat-card" style="padding:12px;"><div class="num" style="color:#dc2626;font-size:1.2rem;"><?php echo $attendance['summary']['Absent']; ?></div><div class="lbl">Absent</div></div>
            <div class="stat-card" style="padding:12px;"><div class="num" style="color:#f59e0b;font-size:1.2rem;"><?php echo $attendance['summary']['Late']; ?></div><div class="lbl">Late</div></div>
        </div>
        <?php if (!empty($attendance['recent'])): ?>
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Status</th><th>Time</th></tr></thead>
                <tbody>
                    <?php foreach ($attendance['recent'] as $rec): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($rec['attendance_date'])); ?></td>
                        <td><span class="badge-status badge-<?php echo strtolower($rec['status']); ?>"><?php echo $rec['status']; ?></span></td>
                        <td><small><?php echo $rec['time_marked'] ? date('h:i A', strtotime($rec['time_marked'])) : ''; ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted">No attendance records for this term.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Tab: Scores -->
<div class="tab-pane" data-tab-pane="scores">
    <div class="section-box">
        <h5><i class="fas fa-chart-line"></i> Scores (<?php echo $selected_term; ?> • <?php echo $selected_session; ?>)</h5>
        <?php if (!empty($scores['scores'])): ?>
            <table class="table table-sm">
                <thead><tr><th>Subject</th><th>CA1</th><th>CA2</th><th>CA3</th><th>Exam</th><th>Total</th><th>Grade</th></tr></thead>
                <tbody>
                    <?php foreach ($scores['scores'] as $s): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['subject_name']); ?></strong></td>
                        <td><?php echo number_format($s['ca1'], 1); ?></td>
                        <td><?php echo number_format($s['ca2'], 1); ?></td>
                        <td><?php echo number_format($s['ca3'], 1); ?></td>
                        <td><?php echo number_format($s['exam'], 1); ?></td>
                        <td><strong><?php echo number_format($s['total'], 1); ?></strong></td>
                        <td><span class="badge-status badge-<?php echo $s['is_approved'] ? 'approved' : ($s['is_submitted'] ? 'submitted' : 'draft'); ?>"><?php echo $s['grade'] ?: '-'; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;">
                        <td colspan="5"><strong>Average</strong></td>
                        <td colspan="2"><strong style="color:#7c3aed;"><?php echo $scores['average']; ?>%</strong></td>
                    </tr>
                </tfoot>
            </table>
        <?php else: ?>
            <p class="text-muted">No scores for this term.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Tab: Reports -->
<div class="tab-pane" data-tab-pane="reports">
    <div class="section-box">
        <h5><i class="fas fa-file-alt"></i> Report Cards</h5>
        <?php if (!empty($reports)): ?>
            <table class="table table-sm">
                <thead><tr><th>Term</th><th>Session</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($reports as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['term_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['session_year']); ?></td>
                        <td>
                            <a href="print_selected_report_cards.php?student_ids[]=<?php echo $student_id; ?>&term=<?php echo urlencode($r['term_name']); ?>&session=<?php echo urlencode($r['session_year']); ?>" 
                               class="btn btn-sm btn-primary" target="_blank"
                               onclick="event.preventDefault(); openReportCard(<?php echo $student_id; ?>, '<?php echo urlencode($r['term_name']); ?>', '<?php echo urlencode($r['session_year']); ?>');">
                                <i class="fas fa-print"></i> Print Report Card
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted">No report cards available yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Tab: Parent Portal -->
<div class="tab-pane" data-tab-pane="portal">
    <div class="section-box">
        <h5><i class="fas fa-user-lock"></i> Parent Portal Access</h5>
        <?php if ($parentPortal): ?>
            <div class="info-row"><div class="lbl">Login Phone</div><div class="val"><?php echo htmlspecialchars($parentPortal['parent_phone']); ?></div></div>
            <div class="info-row"><div class="lbl">Email</div><div class="val"><?php echo htmlspecialchars($parentPortal['parent_email'] ?? 'Not set'); ?></div></div>
            <div class="info-row"><div class="lbl">Last Login</div><div class="val"><?php echo $parentPortal['last_login'] ? date('M d, Y g:i A', strtotime($parentPortal['last_login'])) : 'Never logged in'; ?></div></div>
            <div class="info-row"><div class="lbl">Account Status</div><div class="val"><span class="badge-status badge-<?php echo $parentPortal['is_active'] ? 'paid' : 'pending'; ?>"><?php echo $parentPortal['is_active'] ? 'Active' : 'Disabled'; ?></span></div></div>

            <div class="action-panel">
                <h6>Reset Parent Password</h6>
                <p style="font-size:0.85rem;color:#64748b;margin-bottom:12px;">A new random password will be generated. Give it to the parent in person or via SMS.</p>
                <form method="POST" onsubmit="return confirm('Reset parent password? A new random password will be generated.');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="reset_parent_password" value="1">
                    <button type="submit" class="btn btn-warning"><i class="fas fa-key"></i> Reset Password</button>
                </form>
            </div>
        <?php else: ?>
            <div class="alert alert-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;">
                <i class="fas fa-exclamation-triangle"></i> No parent portal account exists for this student. 
                Go to <a href="manage_students.php">Manage Students</a> → Edit → set a parent phone and password.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden form for report card printing -->
<form id="reportCardForm" method="POST" action="print_selected_report_cards.php" target="_blank" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <input type="hidden" name="class_id" id="rc_class_id" value="<?php echo (int)$student['class_id']; ?>">
    <input type="hidden" name="term" id="rc_term">
    <input type="hidden" name="session" id="rc_session">
    <input type="hidden" name="student_ids[]" value="<?php echo $student_id; ?>">
</form>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.querySelector('[data-tab-pane="' + this.dataset.tab + '"]').classList.add('active');
    });
});

// Report card opener
function openReportCard(studentId, term, session) {
    document.getElementById('rc_term').value = decodeURIComponent(term);
    document.getElementById('rc_session').value = decodeURIComponent(session);
    document.getElementById('reportCardForm').submit();
}
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>