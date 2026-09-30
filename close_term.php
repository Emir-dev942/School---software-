<?php
// school_owner/archive_term.php - Archive Term Data (Permanent)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];
$message = '';
$error = '';

// Get current term/session
$stmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

// Get all terms that have data
$termsStmt = $db->prepare("
    SELECT DISTINCT term_name, session_year
    FROM (
        SELECT term_name, session_year FROM exam_scores WHERE school_id = ?
        UNION
        SELECT term_name, session_year FROM attendance_log WHERE school_id = ?
        UNION
        SELECT term_name, session_year FROM invoices WHERE school_id = ?
    ) AS t
    ORDER BY session_year DESC, term_name
");
$termsStmt->execute([$school_id, $school_id, $school_id]);
$all_terms = $termsStmt->fetchAll();

// Selected term
$selected_term = $_POST['term'] ?? ($_GET['term'] ?? ($school['current_term'] ?? 'Term 1'));
$selected_session = $_POST['session'] ?? ($_GET['session'] ?? ($school['current_session'] ?? date('Y') . '/' . (date('Y') + 1)));

// Preview counts
$previewStmt = $db->prepare("
    SELECT 
        (SELECT COUNT(*) FROM exam_scores WHERE school_id = ? AND term_name = ? AND session_year = ?) AS scores,
        (SELECT COUNT(*) FROM attendance_log WHERE school_id = ? AND term_name = ? AND session_year = ?) AS attendance,
        (SELECT COUNT(*) FROM invoices WHERE school_id = ? AND term_name = ? AND session_year = ?) AS invoices,
        (SELECT COUNT(*) FROM payment_history ph JOIN invoices i ON ph.invoice_id = i.id WHERE i.school_id = ? AND i.term_name = ? AND i.session_year = ?) AS payments,
        (SELECT COUNT(DISTINCT student_id) FROM invoices WHERE school_id = ? AND term_name = ? AND session_year = ?) AS students
");
$previewStmt->execute([
    $school_id, $selected_term, $selected_session,
    $school_id, $selected_term, $selected_session,
    $school_id, $selected_term, $selected_session,
    $school_id, $selected_term, $selected_session,
    $school_id, $selected_term, $selected_session
]);
$preview = $previewStmt->fetch();

// ---------- HANDLE ARCHIVE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_action'])) {
    $action = $_POST['archive_action'];
    $confirm = $_POST['confirm'] ?? '';

    $expected_confirm = ($action === 'clear') ? 'DELETE' : 'ARCHIVE';

    if ($confirm !== $expected_confirm) {
        $error = "Please type '$expected_confirm' to confirm.";
    } else {
        try {
            $db->beginTransaction();

            // 1. Archive exam_scores
            $stmt = $db->prepare("SELECT * FROM exam_scores WHERE school_id = ? AND term_name = ? AND session_year = ?");
            $stmt->execute([$school_id, $selected_term, $selected_session]);
            while ($row = $stmt->fetch()) {
                $db->prepare("INSERT INTO seasonal_archive (school_id, archive_date, session_year, term, table_name, record_id, record_data, archived_by, created_at) VALUES (?, CURDATE(), ?, ?, 'exam_scores', ?, ?, ?, NOW())")
                   ->execute([$school_id, $selected_session, $selected_term, $row['id'], json_encode($row), $user_id]);
            }

            // 2. Archive attendance
            $stmt = $db->prepare("SELECT * FROM attendance_log WHERE school_id = ? AND term_name = ? AND session_year = ?");
            $stmt->execute([$school_id, $selected_term, $selected_session]);
            while ($row = $stmt->fetch()) {
                $db->prepare("INSERT INTO seasonal_archive (school_id, archive_date, session_year, term, table_name, record_id, record_data, archived_by, created_at) VALUES (?, CURDATE(), ?, ?, 'attendance_log', ?, ?, ?, NOW())")
                   ->execute([$school_id, $selected_session, $selected_term, $row['id'], json_encode($row), $user_id]);
            }

            // 3. Archive invoices
            $stmt = $db->prepare("SELECT * FROM invoices WHERE school_id = ? AND term_name = ? AND session_year = ?");
            $stmt->execute([$school_id, $selected_term, $selected_session]);
            while ($row = $stmt->fetch()) {
                $db->prepare("INSERT INTO seasonal_archive (school_id, archive_date, session_year, term, table_name, record_id, record_data, archived_by, created_at) VALUES (?, CURDATE(), ?, ?, 'invoices', ?, ?, ?, NOW())")
                   ->execute([$school_id, $selected_session, $selected_term, $row['id'], json_encode($row), $user_id]);
            }

            // 4. Archive payment_history
            $stmt = $db->prepare("SELECT ph.* FROM payment_history ph JOIN invoices i ON ph.invoice_id = i.id WHERE i.school_id = ? AND i.term_name = ? AND i.session_year = ?");
            $stmt->execute([$school_id, $selected_term, $selected_session]);
            while ($row = $stmt->fetch()) {
                $db->prepare("INSERT INTO seasonal_archive (school_id, archive_date, session_year, term, table_name, record_id, record_data, archived_by, created_at) VALUES (?, CURDATE(), ?, ?, 'payment_history', ?, ?, ?, NOW())")
                   ->execute([$school_id, $selected_session, $selected_term, $row['id'], json_encode($row), $user_id]);
            }

            // IF CLEAR: delete from main tables (in correct order)
            if ($action === 'clear') {
                // Delete payment_history first (foreign key)
                $db->prepare("DELETE ph FROM payment_history ph JOIN invoices i ON ph.invoice_id = i.id WHERE i.school_id = ? AND i.term_name = ? AND i.session_year = ?")
                   ->execute([$school_id, $selected_term, $selected_session]);
                
                // Delete invoices
                $db->prepare("DELETE FROM invoices WHERE school_id = ? AND term_name = ? AND session_year = ?")
                   ->execute([$school_id, $selected_term, $selected_session]);
                
                // Delete exam_scores
                $db->prepare("DELETE FROM exam_scores WHERE school_id = ? AND term_name = ? AND session_year = ?")
                   ->execute([$school_id, $selected_term, $selected_session]);
                
                // Delete attendance_log
                $db->prepare("DELETE FROM attendance_log WHERE school_id = ? AND term_name = ? AND session_year = ?")
                   ->execute([$school_id, $selected_term, $selected_session]);
            }

            $db->commit();
            logActivity('ARCHIVE_TERM', "Term: $selected_term - $selected_session. Action: $action", $school_id, $user_id);
            
            if ($action === 'clear') {
                $_SESSION['success'] = "✅ Term data archived and cleared. Students, classes, and users untouched.";
            } else {
                $_SESSION['success'] = "✅ Term data archived (snapshot saved). Main tables unchanged.";
            }
            header("Location: archive_term.php");
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Failed to archive: " . $e->getMessage();
        }
    }
}

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:16px;margin-bottom:20px;"><i class="fas fa-check-circle"></i> ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if ($error) echo '<div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:16px;margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> ' . $error . '</div>';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700;">📦 Archive Term Data</h1>
        <p class="text-muted">Save a permanent snapshot of a term's data. Optionally clear from main tables.</p>
    </div>
    <a href="advanced.php" class="btn btn-outline-secondary">Back</a>
</div>

<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:16px 20px;margin-bottom:20px;">
    <strong style="color:#92400e;"><i class="fas fa-info-circle"></i> What this does:</strong>
    <ul style="color:#78350f;padding-left:20px;margin:8px 0 0;">
        <li><strong>Archive Only</strong> — saves a snapshot to the archive table. Main data stays visible.</li>
        <li><strong>Archive + Clear</strong> — saves a snapshot AND deletes from main tables. Fresh start.</li>
        <li>Students, classes, subjects, and users are <strong>never touched</strong>.</li>
        <li>This does <strong>NOT</strong> change your current term. Change that in Settings.</li>
        <li>This action is <strong>permanent</strong>. No undo.</li>
    </ul>
</div>

<!-- Select Term -->
<div style="background:#fff;border-radius:16px;padding:24px;border:1px solid #f1f5f9;margin-bottom:20px;">
    <h5 style="font-weight:700;margin-bottom:16px;">Step 1: Select Term to Archive</h5>
    <form method="POST">
        <?php echo csrfField(); ?>
        <div class="row">
            <div class="col-md-5">
                <label>Term</label>
                <select name="term" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($all_terms as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['term_name']); ?>" <?php echo $selected_term === $t['term_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['term_name']); ?>
                        </option>
                    <?php endforeach; ?>
                    <?php if (empty($all_terms)): ?>
                        <option value="Term 1">Term 1</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label>Session</label>
                <select name="session" class="form-control" onchange="this.form.submit()">
                    <?php 
                    $unique_sessions = array_unique(array_column($all_terms, 'session_year'));
                    if (empty($unique_sessions)) $unique_sessions = [$school['current_session']];
                    foreach ($unique_sessions as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $selected_session === $s ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
</div>

<!-- Preview -->
<div style="background:#fff;border-radius:16px;padding:24px;border:1px solid #f1f5f9;margin-bottom:20px;">
    <h5 style="font-weight:700;margin-bottom:16px;">Step 2: Preview</h5>
    <p>You are about to archive: <strong><?php echo htmlspecialchars($selected_term); ?> - <?php echo htmlspecialchars($selected_session); ?></strong></p>
    <div class="row">
        <div class="col-md-3"><div style="background:#f8fafc;border-radius:10px;padding:14px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#0f172a;"><?php echo $preview['students']; ?></div><div style="font-size:12px;color:#64748b;">Students</div></div></div>
        <div class="col-md-3"><div style="background:#f8fafc;border-radius:10px;padding:14px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#0f172a;"><?php echo $preview['invoices']; ?></div><div style="font-size:12px;color:#64748b;">Invoices</div></div></div>
        <div class="col-md-3"><div style="background:#f8fafc;border-radius:10px;padding:14px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#0f172a;"><?php echo $preview['scores']; ?></div><div style="font-size:12px;color:#64748b;">Scores</div></div></div>
        <div class="col-md-3"><div style="background:#f8fafc;border-radius:10px;padding:14px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#0f172a;"><?php echo $preview['attendance']; ?></div><div style="font-size:12px;color:#64748b;">Attendance</div></div></div>
    </div>
    <div style="margin-top:12px;"><strong>Payments:</strong> <?php echo $preview['payments']; ?> records</div>
</div>

<!-- Action -->
<div class="row">
    <div class="col-md-6 mb-3">
        <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:16px;padding:24px;">
            <h5 style="font-weight:700;color:#166534;margin-bottom:8px;">🟢 Archive Only</h5>
            <p style="color:#166534;font-size:14px;margin-bottom:16px;">Save a snapshot. Main tables stay untouched. Data continues to be visible.</p>
            <form method="POST" onsubmit="return confirm('Archive (snapshot only) this term?');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="term" value="<?php echo htmlspecialchars($selected_term); ?>">
                <input type="hidden" name="session" value="<?php echo htmlspecialchars($selected_session); ?>">
                <input type="hidden" name="archive_action" value="archive_only">
                <div class="mb-2">
                    <label style="font-size:13px;font-weight:600;">Type <strong>ARCHIVE</strong> to confirm:</label>
                    <input type="text" name="confirm" class="form-control" placeholder="ARCHIVE" required>
                </div>
                <button type="submit" class="btn btn-success" style="font-weight:600;width:100%;">Archive Only</button>
            </form>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div style="background:#fef2f2;border:2px solid #fecaca;border-radius:16px;padding:24px;">
            <h5 style="font-weight:700;color:#991b1b;margin-bottom:8px;">🔴 Archive + Clear</h5>
            <p style="color:#991b1b;font-size:14px;margin-bottom:16px;"><strong>Permanent.</strong> Snapshot saved AND main tables cleared. Fresh start.</p>
            <form method="POST" onsubmit="return confirm('⚠️ PERMANENTLY clear this term data? This cannot be undone.');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="term" value="<?php echo htmlspecialchars($selected_term); ?>">
                <input type="hidden" name="session" value="<?php echo htmlspecialchars($selected_session); ?>">
                <input type="hidden" name="archive_action" value="clear">
                <div class="mb-2">
                    <label style="font-size:13px;font-weight:600;">Type <strong>DELETE</strong> to confirm:</label>
                    <input type="text" name="confirm" class="form-control" placeholder="DELETE" required>
                </div>
                <button type="submit" class="btn btn-danger" style="font-weight:600;width:100%;">Archive + Clear</button>
            </form>
        </div>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>