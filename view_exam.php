<?php
// teacher/view_exam.php - View Generated Exam (v2 - fixed duplicate & toggle)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Teacher', 'Principal', 'Owner']);
$db = getDB();
$user_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['school_id'];
$role = $_SESSION['user_role'] ?? '';

if ($role === 'Teacher' && !hasPermission(PERM_GENERATE_EXAMS)) {
    die("You do not have permission to view exams.");
}

$exam_id = (int)($_GET['id'] ?? 0);
if ($exam_id <= 0) die("Invalid exam ID.");

// Fetch exam details FIRST (needed by POST handlers)
$stmt = $db->prepare("
    SELECT e.*, c.name AS class_name, s.name AS subject_name
    FROM generated_exams e
    LEFT JOIN classes c ON e.class_id = c.id
    LEFT JOIN subjects s ON e.subject_id = s.id
    WHERE e.id = ? AND e.school_id = ?
");
$stmt->execute([$exam_id, $school_id]);
$exam = $stmt->fetch();
if (!$exam) die("Exam not found.");

// Handle actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_exam') {
        $delete = $db->prepare("DELETE FROM generated_exams WHERE id=? AND school_id=?");
        $delete->execute([$exam_id, $school_id]);
        logActivity('DELETE_EXAM', "Deleted exam #$exam_id", $school_id, $user_id);
        header("Location: generate_exam.php?msg=deleted");
        exit;
    }

    if ($action === 'duplicate_exam') {
        $newCode = strtoupper(substr(uniqid(), -5)) . 'A';
        // FIX: use exam_version (not version)
        $dup = $db->prepare("INSERT INTO generated_exams 
            (school_id, class_id, subject_id, teacher_id, exam_title, exam_date, term, session_year,
             total_marks, duration_minutes, instructions, generated_at, is_published, exam_version, exam_code)
            VALUES (?,?,?,?,?, CURDATE(), ?, ?, ?, ?, ?, NOW(), ?, 'A', ?)");
        $dup->execute([
            $school_id, $exam['class_id'], $exam['subject_id'], $exam['teacher_id'],
            $exam['exam_title'] . ' (Copy)', $exam['term'], $exam['session_year'],
            $exam['total_marks'], $exam['duration_minutes'], $exam['instructions'],
            $exam['is_published'], $newCode
        ]);
        $newExamId = (int)$db->lastInsertId();

        // FIX: include question_type and correct_answer in the copy
        $questions = $db->prepare("SELECT * FROM generated_exam_questions WHERE exam_sheet_id=?");
        $questions->execute([$exam_id]);
        $qRows = $questions->fetchAll();
        $insertQ = $db->prepare("INSERT INTO generated_exam_questions 
            (exam_sheet_id, question_id, question_number, marks, shuffled_options, question_type, correct_answer, explanation)
            VALUES (?,?,?,?,?,?,?,?)");
        foreach ($qRows as $q) {
            $insertQ->execute([
                $newExamId, 
                $q['question_id'], 
                $q['question_number'], 
                $q['marks'], 
                $q['shuffled_options'],
                $q['question_type'] ?? 'mcq',
                $q['correct_answer'] ?? null,
                $q['explanation'] ?? null
            ]);
        }
        logActivity('DUPLICATE_EXAM', "Duplicated exam #$exam_id to #$newExamId", $school_id, $user_id);
        header("Location: view_exam.php?id=$newExamId&msg=duplicated");
        exit;
    }

    if ($action === 'toggle_publish') {
        // FIX: $exam is now defined before this point
        $newStatus = $exam['is_published'] ? 0 : 1;
        $update = $db->prepare("UPDATE generated_exams SET is_published=? WHERE id=? AND school_id=?");
        $update->execute([$newStatus, $exam_id, $school_id]);
        logActivity('TOGGLE_EXAM_PUBLISH', "Toggled publish status for exam #$exam_id to " . ($newStatus ? 'published' : 'unpublished'), $school_id, $user_id);
        header("Location: view_exam.php?id=$exam_id&msg=published");
        exit;
    }
}

// Fetch questions with stored shuffled options
$qStmt = $db->prepare("
    SELECT gq.*, q.question_text, q.question_type, q.option_a, q.option_b, q.option_c, q.option_d,
           q.expected_answer, q.answer_explanation
    FROM generated_exam_questions gq
    JOIN exam_questions q ON gq.question_id = q.id
    WHERE gq.exam_sheet_id = ?
    ORDER BY gq.question_number
");
$qStmt->execute([$exam_id]);
$questions = $qStmt->fetchAll();

// Count question types
$mcqCount = 0; $theoryCount = 0; $tfCount = 0; $fillCount = 0;
foreach ($questions as $q) {
    switch ($q['question_type']) {
        case 'mcq': $mcqCount++; break;
        case 'theory': $theoryCount++; break;
        case 'true_false': $tfCount++; break;
        case 'fill_blank': $fillCount++; break;
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4" style="max-width: 1000px; margin: 0 auto;">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4>📄 Exam Details</h4>
            <h5><?php echo htmlspecialchars($exam['exam_title']); ?></h5>
            <p class="text-muted"><?php echo htmlspecialchars($exam['class_name']); ?> | <?php echo htmlspecialchars($exam['subject_name']); ?></p>
        </div>
        <div>
            <a href="generate_exam.php" class="btn btn-outline-secondary">Back</a>
            <form method="POST" style="display:inline;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="toggle_publish">
                <button type="submit" class="btn btn-outline-warning">
                    <?php echo ($exam['is_published'] ? 'Unpublish' : 'Publish'); ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Success messages -->
    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'deleted'): ?>
            <div class="alert alert-success">Exam deleted successfully.</div>
        <?php elseif ($_GET['msg'] == 'duplicated'): ?>
            <div class="alert alert-success">Exam duplicated successfully. You are now viewing the copy.</div>
        <?php elseif ($_GET['msg'] == 'published'): ?>
            <div class="alert alert-success">Publish status updated.</div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Exam Overview Card -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Exam Code:</strong> <?php echo htmlspecialchars($exam['exam_code']); ?></p>
                    <p><strong>Total Marks:</strong> <?php echo $exam['total_marks']; ?></p>
                    <p><strong>Duration:</strong> <?php echo $exam['duration_minutes']; ?> mins</p>
                    <p><strong>Term/Session:</strong> <?php echo htmlspecialchars($exam['term']); ?> <?php echo htmlspecialchars($exam['session_year']); ?></p>
                    <p><strong>Created:</strong> <?php echo date('d M Y, h:i A', strtotime($exam['generated_at'])); ?></p>
                    <p><strong>Status:</strong> 
                        <span class="badge bg-<?php echo $exam['is_published'] ? 'success' : 'secondary'; ?>">
                            <?php echo $exam['is_published'] ? 'Published' : 'Draft'; ?>
                        </span>
                    </p>
                </div>
                <div class="col-md-6">
                    <p><strong>Instructions:</strong></p>
                    <div class="alert alert-secondary"><?php echo nl2br(htmlspecialchars($exam['instructions'] ?? '')); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Question Type Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center p-3"><strong><?php echo count($questions); ?></strong><br><small>Total Questions</small></div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3"><strong><?php echo $mcqCount; ?></strong><br><small>MCQ</small></div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3"><strong><?php echo $theoryCount + $tfCount + $fillCount; ?></strong><br><small>Other Types</small></div>
        </div>
        <div class="col-md-3">
            <div class="card text-center p-3"><strong><?php echo $exam['total_marks']; ?></strong><br><small>Total Marks</small></div>
        </div>
    </div>

    <!-- Questions List -->
    <div class="card mb-4">
        <div class="card-body">
            <h5>Questions (<?php echo count($questions); ?>)</h5>
            <?php if (empty($questions)): ?>
                <p>No questions found.</p>
            <?php else: ?>
                <ol>
                    <?php foreach ($questions as $q): ?>
                        <li>
                            <?php echo htmlspecialchars($q['question_text']); ?> (<?php echo $q['marks']; ?> marks)
                            <?php if ($q['question_type'] === 'mcq'): ?>
                                <?php 
                                $options = [];
                                $correctLetter = $q['correct_answer'];
                                if (!empty($q['shuffled_options'])) {
                                    $shuffled = json_decode($q['shuffled_options'], true);
                                    if (isset($shuffled['options'])) {
                                        $options = $shuffled['options'];
                                    }
                                    if (isset($shuffled['correct'])) {
                                        $correctLetter = $shuffled['correct'];
                                    }
                                } else {
                                    $options = [
                                        'A' => $q['option_a'],
                                        'B' => $q['option_b'],
                                        'C' => $q['option_c'],
                                        'D' => $q['option_d'],
                                    ];
                                }
                                ?>
                                <ul>
                                    <?php foreach ($options as $letter => $option): ?>
                                        <li><strong><?php echo $letter; ?>.</strong> <?php echo htmlspecialchars($option); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <p><strong>Correct:</strong> <?php echo htmlspecialchars($correctLetter); ?></p>
                            <?php elseif ($q['question_type'] === 'theory'): ?>
                                <p><em>Expected answer:</em> <?php echo htmlspecialchars($q['expected_answer'] ?? 'N/A'); ?></p>
                            <?php elseif ($q['question_type'] === 'fill_blank'): ?>
                                <p><strong>Answer:</strong> <?php echo htmlspecialchars($q['correct_answer']); ?></p>
                            <?php elseif ($q['question_type'] === 'true_false'): ?>
                                <p><strong>Answer:</strong> <?php echo htmlspecialchars($q['correct_answer']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($q['explanation'])): ?>
                                <p><small>Explanation: <?php echo htmlspecialchars($q['explanation']); ?></small></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="d-flex gap-2 flex-wrap">
        <a href="print_exam.php?id=<?php echo $exam_id; ?>&mode=exam" class="btn btn-primary" target="_blank">🖨️ Print Paper</a>
        <a href="print_exam.php?id=<?php echo $exam_id; ?>&mode=answer_key" class="btn btn-success" target="_blank">🔑 Print Answer Key</a>

        <form method="POST" style="display:inline;" onsubmit="return confirm('Duplicate this exam?')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="duplicate_exam">
            <button type="submit" class="btn btn-outline-primary">📋 Duplicate</button>
        </form>

        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this exam? This cannot be undone.')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_exam">
            <button type="submit" class="btn btn-outline-danger">🗑️ Delete</button>
        </form>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>