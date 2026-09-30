<?php
// school_owner/approve_scores.php - v6 (approval history + color coding + out-of count)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// School's current term/session
$schoolStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch();
$current_term = $schoolInfo['current_term'] ?? 'Term 1';
$current_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

// ---------- APPROVE SELECTED ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_selected'])) {
    $ids = array_map('intval', $_POST['score_ids'] ?? []);
    if (empty($ids)) {
        $_SESSION['error'] = "No scores selected.";
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$user_id, $school_id, $current_term, $current_session], $ids);
        $stmt = $db->prepare("UPDATE exam_scores SET is_approved = 1, approved_by = ?, approved_at = NOW() 
                              WHERE school_id = ? AND term_name = ? AND session_year = ? 
                              AND id IN ($placeholders) AND is_submitted = 1 AND is_approved = 0");
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        logActivity('APPROVE_SCORES', "Approved $affected scores (selected)", $school_id, $user_id);
        $_SESSION['success'] = "✅ $affected scores approved.";
    }
    header("Location: approve_scores.php?class_id=" . (int)($_POST['class_id'] ?? 0) . "&subject_id=" . (int)($_POST['subject_id'] ?? 0));
    exit;
}

// ---------- APPROVE ALL SUBMITTED ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_all'])) {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $stmt = $db->prepare("UPDATE exam_scores SET is_approved = 1, approved_by = ?, approved_at = NOW() 
                          WHERE school_id = ? AND class_id = ? AND subject_id = ? 
                          AND term_name = ? AND session_year = ? 
                          AND is_submitted = 1 AND is_approved = 0");
    $stmt->execute([$user_id, $school_id, $class_id, $subject_id, $current_term, $current_session]);
    $affected = $stmt->rowCount();
    logActivity('APPROVE_SCORES', "Approved $affected scores for class $class_id, subject $subject_id", $school_id, $user_id);
    $_SESSION['success'] = "✅ $affected scores approved.";
    header("Location: approve_scores.php?class_id=$class_id&subject_id=$subject_id");
    exit;
}

// ---------- UNAPPROVE SELECTED ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unapprove_selected'])) {
    if ($user_role !== 'Owner') {
        $_SESSION['error'] = "Only the Owner can unlock scores.";
    } else {
        $ids = array_map('intval', $_POST['score_ids'] ?? []);
        if (empty($ids)) {
            $_SESSION['error'] = "No scores selected.";
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$school_id, $current_term, $current_session], $ids);
            $stmt = $db->prepare("UPDATE exam_scores SET is_approved = 0, is_submitted = 0, approved_by = NULL, approved_at = NULL 
                                  WHERE school_id = ? AND term_name = ? AND session_year = ? 
                                  AND id IN ($placeholders) AND is_approved = 1");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            logActivity('UNAPPROVE_SCORES', "Unlocked $affected scores (selected)", $school_id, $user_id);
            $_SESSION['success'] = "✅ $affected scores unlocked. Teacher can now edit them.";
        }
    }
    header("Location: approve_scores.php?class_id=" . (int)($_POST['class_id'] ?? 0) . "&subject_id=" . (int)($_POST['subject_id'] ?? 0));
    exit;
}

// ---------- SUMMARY: only SUBMITTED or APPROVED, with class total ----------
$summaryStmt = $db->prepare("
    SELECT es.class_id, es.subject_id, c.name AS class_name, s.name AS subject_name,
           COUNT(DISTINCT CASE WHEN es.is_approved = 1 OR es.is_submitted = 1 THEN es.student_id END) AS submitted_count,
           SUM(CASE WHEN es.is_approved = 1 THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN es.is_submitted = 1 AND es.is_approved = 0 THEN 1 ELSE 0 END) AS pending,
           (SELECT COUNT(*) FROM students WHERE school_id = ? AND class_id = es.class_id AND status = 'Active') AS class_total
    FROM exam_scores es
    JOIN classes c ON es.class_id = c.id
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.school_id = ? AND es.term_name = ? AND es.session_year = ?
      AND (es.is_submitted = 1 OR es.is_approved = 1)
    GROUP BY es.class_id, es.subject_id, c.name, s.name
    ORDER BY c.name, s.name
");
$summaryStmt->execute([$school_id, $school_id, $current_term, $current_session]);
$summary = $summaryStmt->fetchAll();

// ---------- DETAIL ----------
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$scores = [];

if ($selected_class_id > 0 && $selected_subject_id > 0) {
    $scoreStmt = $db->prepare("
        SELECT es.id, es.student_id, es.ca1, es.ca2, es.ca3, es.exam, es.total,
               st.first_name, st.last_name, st.student_id AS student_code,
               es.is_submitted, es.is_approved, es.approved_at, es.approved_by,
               approver.full_name AS approver_name
        FROM exam_scores es
        JOIN students st ON es.student_id = st.id
        LEFT JOIN users approver ON es.approved_by = approver.id
        WHERE es.school_id = ? AND es.class_id = ? AND es.subject_id = ?
          AND es.term_name = ? AND es.session_year = ?
          AND (es.is_submitted = 1 OR es.is_approved = 1)
        ORDER BY st.last_name, st.first_name
    ");
    $scoreStmt->execute([$school_id, $selected_class_id, $selected_subject_id, $current_term, $current_session]);
    $scores = $scoreStmt->fetchAll();
}

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="border-radius:10px;">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" style="border-radius:10px;">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 style="font-weight:700; color:#0f172a;">✅ Approve Scores</h1>
        <p class="text-muted">Showing <strong><?php echo htmlspecialchars($current_term); ?> · <?php echo htmlspecialchars($current_session); ?></strong></p>
    </div>
</div>

<!-- Summary Table -->
<div style="background:#fff; border-radius:16px; padding:20px; border:1px solid #f1f5f9; margin-bottom:20px;">
    <h5 style="font-weight:700; margin-bottom:16px;">📊 Submitted Scores This Term</h5>
    <?php if (empty($summary)): ?>
        <p class="text-center text-muted py-3">No scores have been submitted for this term yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr><th>Class</th><th>Subject</th><th>Submitted</th><th>Pending</th><th>Approved</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($summary as $s): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['class_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                        <td>
                            <strong><?php echo (int)$s['submitted_count']; ?></strong>
                            <span class="text-muted">/ <?php echo (int)$s['class_total']; ?> students</span>
                        </td>
                        <td>
                            <?php if ($s['pending'] > 0): ?>
                                <span class="badge bg-warning text-dark"><?php echo (int)$s['pending']; ?> pending</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['approved'] > 0): ?>
                                <span class="badge bg-success"><?php echo (int)$s['approved']; ?> approved</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?class_id=<?php echo $s['class_id']; ?>&subject_id=<?php echo $s['subject_id']; ?>" class="btn btn-sm btn-primary">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Detail -->
<?php if ($selected_class_id > 0 && $selected_subject_id > 0 && !empty($scores)): ?>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
    <input type="hidden" name="subject_id" value="<?php echo $selected_subject_id; ?>">

    <div style="background:#fff; border-radius:16px; overflow:hidden; border:1px solid #f1f5f9;">
        <div class="table-responsive">
            <table class="table" style="margin-bottom:0;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="checkAll" style="width:18px;height:18px;"></th>
                        <th>Student</th><th>CA1</th><th>CA2</th><th>CA3</th><th>Exam</th><th>Total</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scores as $s): 
                        $total = (float)$s['total'];
                        $row_bg = '';
                        if ($total < 40) $row_bg = 'background:#fef2f2;';       // F — light red
                        elseif ($total >= 80) $row_bg = 'background:#f0fdf4;';  // A — light green
                    ?>
                    <tr style="<?php echo $row_bg; ?>">
                        <td>
                            <input type="checkbox" name="score_ids[]" value="<?php echo (int)$s['id']; ?>" class="row-check" style="width:18px;height:18px;">
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></strong>
                            <?php if ($s['is_approved'] && !empty($s['approver_name'])): ?>
                                <br><small class="text-muted" style="font-size:11px;">
                                    <i class="fas fa-check-circle" style="color:#16a34a;"></i>
                                    Approved by <?php echo htmlspecialchars($s['approver_name']); ?>
                                    on <?php echo $s['approved_at'] ? date('M d, Y H:i', strtotime($s['approved_at'])) : '—'; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo number_format($s['ca1'], 1); ?></td>
                        <td><?php echo number_format($s['ca2'], 1); ?></td>
                        <td><?php echo number_format($s['ca3'], 1); ?></td>
                        <td><?php echo number_format($s['exam'], 1); ?></td>
                        <td><strong><?php echo number_format($total, 1); ?></strong></td>
                        <td>
                            <?php if ($s['is_approved']): ?>
                                <span class="badge bg-success">Approved</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="padding:16px; border-top:1px solid #f1f5f9; display:flex; gap:12px; flex-wrap:wrap;">
            <button type="submit" name="approve_selected" value="1" class="btn btn-success" style="border-radius:10px; padding:10px 24px; font-weight:600;">
                <i class="fas fa-check"></i> Approve Selected
            </button>
            <button type="submit" name="approve_all" value="1" class="btn btn-primary" style="border-radius:10px; padding:10px 24px; font-weight:600;"
                    onclick="return confirm('Approve all pending scores for this class/subject?')">
                <i class="fas fa-check-double"></i> Approve All Pending
            </button>
            <?php if ($user_role === 'Owner'): ?>
            <button type="submit" name="unapprove_selected" value="1" class="btn btn-outline-warning" style="border-radius:10px; padding:10px 24px; font-weight:600;"
                    onclick="return confirm('Unlock selected approved scores so the teacher can edit them?')">
                <i class="fas fa-unlock"></i> Unlock Selected
            </button>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});
</script>

<?php elseif ($selected_class_id > 0 && $selected_subject_id > 0): ?>
<p class="text-center text-muted py-4">No submitted scores found for this class and subject in <?php echo htmlspecialchars($current_term); ?>.</p>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>