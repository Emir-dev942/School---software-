<?php
// school_owner/student_profile.php - Full Student History
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$student_id = (int)($_GET['id'] ?? 0);
if ($student_id <= 0) die("Invalid student ID.");

// Fetch student basic info
$stmt = $db->prepare("
    SELECT s.*, c.name AS current_class
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();
if (!$student) die("Student not found.");

// Fetch distinct term/session combinations where this student has data
$termsSessions = $db->prepare("
    SELECT DISTINCT term_name, session_year
    FROM exam_scores
    WHERE student_id = ? AND school_id = ?
    UNION
    SELECT DISTINCT term_name, session_year
    FROM attendance_log
    WHERE student_id = ? AND school_id = ?
    UNION
    SELECT DISTINCT term_name, session_year
    FROM invoices
    WHERE student_id = ? AND school_id = ?
    ORDER BY session_year DESC, term_name
");
$termsSessions->execute([$student_id, $school_id, $student_id, $school_id, $student_id, $school_id]);
$termsSessions = $termsSessions->fetchAll();

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">👤 Student Profile</h1>
        <p class="text-muted">Full history for <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></p>
    </div>
    <a href="manage_students.php" class="btn btn-outline-secondary">Back</a>
</div>

<!-- Student Basic Info Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center">
            <?php 
                $photo = ($student['photo_path'] && $student['photo_path'] !== 'default_student.png') 
                    ? BASE_URL . 'uploads/student_photos/' . $student['photo_path'] 
                    : BASE_URL . 'assets/images/default_avatar.png';
            ?>
            <img src="<?php echo $photo; ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;margin-right:20px;">
            <div>
                <h4><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                <p class="mb-0"><strong>ID:</strong> <?php echo htmlspecialchars($student['student_id']); ?></p>
                <p class="mb-0"><strong>Current Class:</strong> <?php echo htmlspecialchars($student['current_class'] ?? 'N/A'); ?></p>
                <p class="mb-0"><strong>Parent Phone:</strong> <?php echo htmlspecialchars($student['parent_phone'] ?? ''); ?></p>
                <p class="mb-0"><strong>Status:</strong> <?php echo htmlspecialchars($student['status']); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- History per Term/Session -->
<?php if (empty($termsSessions)): ?>
    <div class="alert alert-info">No historical data found for this student.</div>
<?php else: ?>
    <?php foreach ($termsSessions as $ts): 
        $term = $ts['term_name'];
        $session = $ts['session_year'];
    ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5><?php echo htmlspecialchars($term . ' - ' . $session); ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6>Attendance</h6>
                        <?php
                        $attStmt = $db->prepare("SELECT status, COUNT(*) as count FROM attendance_log WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ? GROUP BY status");
                        $attStmt->execute([$student_id, $school_id, $term, $session]);
                        $attendance = ['Present'=>0,'Absent'=>0,'Late'=>0,'Excused'=>0];
                        while ($row = $attStmt->fetch()) {
                            if (isset($attendance[$row['status']])) $attendance[$row['status']] = (int)$row['count'];
                        }
                        echo '<p>Present: ' . $attendance['Present'] . '</p>';
                        echo '<p>Absent: ' . $attendance['Absent'] . '</p>';
                        echo '<p>Late: ' . $attendance['Late'] . '</p>';
                        ?>
                    </div>
                    <div class="col-md-4">
                        <h6>Fees</h6>
                        <?php
                        $feeStmt = $db->prepare("SELECT total_amount, paid_amount, balance, status FROM invoices WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ? LIMIT 1");
                        $feeStmt->execute([$student_id, $school_id, $term, $session]);
                        $fee = $feeStmt->fetch();
                        if ($fee) {
                            echo '<p>Total: ' . formatCurrency($fee['total_amount']) . '</p>';
                            echo '<p>Paid: ' . formatCurrency($fee['paid_amount']) . '</p>';
                            echo '<p>Balance: ' . formatCurrency($fee['balance']) . '</p>';
                            echo '<p>Status: ' . ucfirst($fee['status']) . '</p>';
                        } else {
                            echo '<p>No invoice found.</p>';
                        }
                        ?>
                    </div>
                    <div class="col-md-4">
                        <h6>Report Card</h6>
                        <?php
                        $scoreCount = $db->prepare("SELECT COUNT(*) FROM exam_scores WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ?");
                        $scoreCount->execute([$student_id, $school_id, $term, $session]);
                        $numScores = (int)$scoreCount->fetchColumn();
                        if ($numScores > 0) {
                            echo '<a href="generate_report_cards.php?class_id=' . $student['class_id'] . '&student_id=' . $student_id . '&term=' . urlencode($term) . '&session=' . urlencode($session) . '" class="btn btn-primary btn-sm" target="_blank">View Report Card</a>';
                        } else {
                            echo '<p>No scores recorded.</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>