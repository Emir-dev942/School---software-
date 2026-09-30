<?php
// school_owner/promote_students.php - End of Session (Promote + Archive)
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

// Fetch classes
$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY order_number, name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_session'])) {
    $from_class_id = (int)($_POST['from_class_id'] ?? 0);
    $to_class_id = (int)($_POST['to_class_id'] ?? 0);
    $session_year = sanitize($_POST['session_year'] ?? date('Y') . '/' . (date('Y') + 1));
    $promote_ids = $_POST['promote_ids'] ?? [];
    $archive_ids = $_POST['archive_ids'] ?? [];

    if ($from_class_id <= 0) {
        $error = "Please select a class.";
    } else {
        try {
            $db->beginTransaction();
            $promoted = 0;
            $archived = 0;

            // Promote checked students
            foreach ($promote_ids as $sid) {
                $sid = (int)$sid;
                if ($to_class_id <= 0) continue;

                $check = $db->prepare("SELECT id FROM students WHERE id = ? AND school_id = ? AND class_id = ? AND status = 'Active'");
                $check->execute([$sid, $school_id, $from_class_id]);
                if ($check->fetch()) {
                    $db->prepare("UPDATE students SET class_id = ? WHERE id = ? AND school_id = ?")
                       ->execute([$to_class_id, $sid, $school_id]);

                    $db->prepare("INSERT INTO promotion_log (school_id, student_id, from_class_id, to_class_id, session_year, promoted_by, promoted_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
                       ->execute([$school_id, $sid, $from_class_id, $to_class_id, $session_year, $user_id]);
                    $promoted++;
                }
            }

            // Archive unchecked students (they left / repeating / graduated)
            foreach ($archive_ids as $sid) {
                $sid = (int)$sid;
                $check = $db->prepare("SELECT id FROM students WHERE id = ? AND school_id = ? AND class_id = ? AND status = 'Active'");
                $check->execute([$sid, $school_id, $from_class_id]);
                if ($check->fetch()) {
                    $db->prepare("UPDATE students SET status = 'Archived' WHERE id = ? AND school_id = ?")
                       ->execute([$sid, $school_id]);
                    $archived++;
                }
            }

            $db->commit();
            logActivity('END_SESSION', "Class ID $from_class_id: Promoted $promoted students, Archived $archived students", $school_id, $user_id);

            $msg = [];
            if ($promoted > 0) $msg[] = "$promoted promoted";
            if ($archived > 0) $msg[] = "$archived archived";
            $_SESSION['success'] = "✅ End of session processed: " . implode(', ', $msg) . ".";
            header("Location: promote_students.php?from_class_id=$from_class_id");
            exit;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Failed: " . $e->getMessage();
        }
    }
}

// Fetch students for selected class
$selected_from = isset($_GET['from_class_id']) ? (int)$_GET['from_class_id'] : (count($classes) > 0 ? (int)$classes[0]['id'] : 0);
$students = [];
if ($selected_from > 0) {
    $stmt = $db->prepare("SELECT id, first_name, last_name, student_id, photo_path FROM students WHERE school_id = ? AND class_id = ? AND status = 'Active' ORDER BY last_name, first_name");
    $stmt->execute([$school_id, $selected_from]);
    $students = $stmt->fetchAll();
}

// Promotion history
$historyStmt = $db->prepare("
    SELECT pl.*, s.first_name, s.last_name, c1.name AS from_class, c2.name AS to_class 
    FROM promotion_log pl
    JOIN students s ON pl.student_id = s.id
    JOIN classes c1 ON pl.from_class_id = c1.id
    JOIN classes c2 ON pl.to_class_id = c2.id
    WHERE pl.school_id = ?
    ORDER BY pl.promoted_at DESC LIMIT 10
");
$historyStmt->execute([$school_id]);
$history = $historyStmt->fetchAll();

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:16px;margin-bottom:20px;"><i class="fas fa-check-circle"></i> ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if ($error) echo '<div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:16px;margin-bottom:20px;">' . $error . '</div>';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700;">🎓 End of Session</h1>
        <p class="text-muted">Promote students to the next class. Uncheck those who left, failed, or graduated.</p>
    </div>
    <a href="advanced.php" class="btn btn-outline-secondary">Back</a>
</div>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px 20px;margin-bottom:20px;">
    <strong style="color:#1e40af;"><i class="fas fa-info-circle"></i> How this works:</strong>
    <ul style="color:#1e3a8a;padding-left:20px;margin:8px 0 0;">
        <li>All students are <strong>checked by default</strong> (they will be promoted).</li>
        <li><strong>Uncheck</strong> students who:
            <ul>
                <li>Left the school</li>
                <li>Graduated (e.g., JSS 3 or SSS 3)</li>
                <li>Failed and must repeat the class</li>
                <li>Transferred to another school</li>
            </ul>
        </li>
        <li>Unchecked students will be <strong>marked as Archived</strong> (kept in records, hidden from active list).</li>
        <li>This is used <strong>once per school year</strong> — at the end of Term 3.</li>
    </ul>
</div>

<!-- Step 1: Select Class -->
<div style="background:#fff;border-radius:16px;padding:24px;border:1px solid #f1f5f9;margin-bottom:20px;">
    <h5 style="font-weight:700;margin-bottom:16px;">Step 1: Select Class</h5>
    <form method="GET">
        <div class="row">
            <div class="col-md-6">
                <label class="form-label fw-semibold">From Class</label>
                <select name="from_class_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $selected_from === $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
</div>

<?php if ($selected_from > 0 && !empty($students)): ?>
<form method="POST" id="promoteForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="from_class_id" value="<?php echo $selected_from; ?>">
    
    <div style="background:#fff;border-radius:16px;padding:24px;border:1px solid #f1f5f9;margin-bottom:20px;">
        <h5 style="font-weight:700;margin-bottom:16px;">Step 2: Destination Class</h5>
        <div class="row">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Promote Checked Students To</label>
                <select name="to_class_id" class="form-control" required>
                    <option value="">-- Select Destination Class --</option>
                    <?php foreach ($classes as $c): ?>
                        <?php if ($c['id'] !== $selected_from): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Session Year</label>
                <input type="text" name="session_year" class="form-control" value="<?php echo date('Y') . '/' . (date('Y') + 1); ?>" readonly>
            </div>
        </div>
    </div>

    <div style="background:#fff;border-radius:16px;padding:24px;border:1px solid #f1f5f9;margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h5 style="font-weight:700;margin:0;">Step 3: Select Students (<?php echo count($students); ?>)</h5>
            <div>
                <button type="button" id="checkAllBtn" class="btn btn-sm btn-outline-primary">Check All</button>
                <button type="button" id="uncheckAllBtn" class="btn btn-sm btn-outline-secondary">Uncheck All</button>
            </div>
        </div>

        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#166534;">
            <i class="fas fa-check-circle"></i> <strong>Checked</strong> = will be promoted to destination class
        </div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:#991b1b;">
            <i class="fas fa-times-circle"></i> <strong>Unchecked</strong> = will be marked as Archived (left/failed/graduated)
        </div>

        <div style="max-height:500px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:12px;">
            <table class="table" style="margin-bottom:0;">
                <thead style="background:#f8fafc;position:sticky;top:0;z-index:1;">
                    <tr>
                        <th style="width:60px;">Promote</th>
                        <th>Photo</th>
                        <th>Student ID</th>
                        <th>Name</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): 
                        $photo_url = ($s['photo_path'] && $s['photo_path'] !== 'default_student.png') 
                            ? BASE_URL . 'uploads/student_photos/' . $s['photo_path'] 
                            : BASE_URL . 'assets/images/default_avatar.png';
                    ?>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td>
                                <input type="checkbox" name="promote_ids[]" value="<?php echo $s['id']; ?>" 
                                       class="promote-check" checked 
                                       style="width:20px;height:20px;cursor:pointer;">
                            </td>
                            <td><img src="<?php echo $photo_url; ?>" style="width:35px;height:35px;border-radius:50%;object-fit:cover;"></td>
                            <td><small><?php echo htmlspecialchars($s['student_id']); ?></small></td>
                            <td><strong><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="background:#fff;border-radius:16px;padding:24px;border:1px solid #f1f5f9;margin-bottom:20px;">
        <h5 style="font-weight:700;margin-bottom:12px;">Step 4: Confirm</h5>
        <div id="summary" style="font-size:14px;color:#475569;margin-bottom:16px;">
            <!-- Filled by JS -->
        </div>
        <button type="submit" name="end_session" value="1" class="btn btn-primary" 
                style="border-radius:10px;padding:12px 40px;font-weight:600;"
                onclick="return confirm('Proceed with end of session processing? This action cannot be undone.')">
            <i class="fas fa-check-circle"></i> Process End of Session
        </button>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.promote-check');
    const summary = document.getElementById('summary');

    function updateSummary() {
        let checked = 0;
        let unchecked = 0;
        checkboxes.forEach(cb => {
            if (cb.checked) checked++; else unchecked++;
        });
        summary.innerHTML = `
            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;flex:1;min-width:180px;">
                    <div style="font-size:11px;color:#166534;text-transform:uppercase;font-weight:700;">To Promote</div>
                    <div style="font-size:24px;font-weight:800;color:#16a34a;">${checked}</div>
                </div>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;flex:1;min-width:180px;">
                    <div style="font-size:11px;color:#991b1b;text-transform:uppercase;font-weight:700;">To Archive</div>
                    <div style="font-size:24px;font-weight:800;color:#dc2626;">${unchecked}</div>
                </div>
            </div>
        `;
    }

    checkboxes.forEach(cb => cb.addEventListener('change', updateSummary));
    updateSummary();

    document.getElementById('checkAllBtn').addEventListener('click', function() {
        checkboxes.forEach(cb => cb.checked = true);
        updateSummary();
    });
    document.getElementById('uncheckAllBtn').addEventListener('click', function() {
        checkboxes.forEach(cb => cb.checked = false);
        updateSummary();
    });
});
</script>

<?php elseif ($selected_from > 0 && empty($students)): ?>
    <div style="background:#fff;border-radius:16px;padding:40px;border:1px solid #f1f5f9;text-align:center;color:#94a3b8;">
        No active students in this class.
    </div>
<?php endif; ?>

<!-- Recent History -->
<?php if (!empty($history)): ?>
<div style="background:#fff;border-radius:16px;padding:24px;border:1px solid #f1f5f9;margin-top:20px;">
    <h5 style="font-weight:700;margin-bottom:16px;">📋 Recent Promotions</h5>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Student</th><th>From</th><th>To</th><th>Session</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($h['first_name'] . ' ' . $h['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($h['from_class']); ?></td>
                        <td><?php echo htmlspecialchars($h['to_class']); ?></td>
                        <td><?php echo htmlspecialchars($h['session_year']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($h['promoted_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>