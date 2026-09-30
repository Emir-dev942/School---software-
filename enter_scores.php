<?php
// teacher/enter_scores.php - v7 (tabs + column tab order + live totals + sticky save)
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

// School current term/session
$schoolStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch();
$current_term = $schoolInfo['current_term'] ?? 'Term 1';
$current_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

// Assigned classes/subjects
$assignStmt = $db->prepare("
    SELECT DISTINCT c.id AS class_id, c.name AS class_name,
           GROUP_CONCAT(DISTINCT s.id ORDER BY s.name SEPARATOR ',') AS subject_ids,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS subject_names
    FROM teacher_subject_assignments tsa
    JOIN classes c ON tsa.class_id = c.id
    JOIN subjects s ON tsa.subject_id = s.id
    WHERE tsa.teacher_id = ? AND tsa.school_id = ? AND c.status = 'active' AND s.status = 'active'
    GROUP BY c.id, c.name
    ORDER BY c.name
");
$assignStmt->execute([$teacher_id, $school_id]);
$assignments = $assignStmt->fetchAll();

$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

if (($selected_class_id === 0 || $selected_subject_id === 0) && !empty($assignments)) {
    $first = $assignments[0];
    $selected_class_id = (int)$first['class_id'];
    $first_subjects = explode(',', $first['subject_ids']);
    $selected_subject_id = (int)$first_subjects[0];
}

$students = [];
$has_approved = false;
$has_submitted = false;

if ($selected_class_id > 0 && $selected_subject_id > 0) {
    $studentStmt = $db->prepare("SELECT id, first_name, last_name, student_id, photo_path FROM students WHERE school_id = ? AND class_id = ? AND status = 'Active' ORDER BY last_name, first_name");
    $studentStmt->execute([$school_id, $selected_class_id]);
    $students = $studentStmt->fetchAll();

    $scoreStmt = $db->prepare("SELECT student_id, ca1, ca2, ca3, exam, is_approved, is_submitted 
                               FROM exam_scores 
                               WHERE school_id = ? AND subject_id = ? AND class_id = ? AND term_name = ? AND session_year = ?");
    $scoreStmt->execute([$school_id, $selected_subject_id, $selected_class_id, $current_term, $current_session]);
    $existing_scores = [];
    while ($row = $scoreStmt->fetch()) {
        $existing_scores[$row['student_id']] = $row;
        if ($row['is_approved']) $has_approved = true;
        if ($row['is_submitted'] && !$row['is_approved']) $has_submitted = true;
    }

    $affStmt = $db->prepare("SELECT student_id, punctuality, neatness, attentiveness, honesty, politeness 
                             FROM student_affective_ratings 
                             WHERE school_id = ? AND term_name = ? AND session_year = ?");
    $affStmt->execute([$school_id, $current_term, $current_session]);
    $existing_aff = [];
    while ($row = $affStmt->fetch()) {
        $existing_aff[$row['student_id']] = $row;
    }

    foreach ($students as &$student) {
        $sid = $student['id'];

        if (isset($existing_scores[$sid])) {
            $s = $existing_scores[$sid];
            $student['ca1'] = $s['ca1'] > 0 ? $s['ca1'] : '';
            $student['ca2'] = $s['ca2'] > 0 ? $s['ca2'] : '';
            $student['ca3'] = $s['ca3'] > 0 ? $s['ca3'] : '';
            $student['exam'] = $s['exam'] > 0 ? $s['exam'] : '';
            $student['is_approved'] = $s['is_approved'] == 1;
            $student['is_submitted'] = $s['is_submitted'] == 1;
        } else {
            $student['ca1'] = ''; $student['ca2'] = ''; $student['ca3'] = ''; $student['exam'] = '';
            $student['is_approved'] = false; $student['is_submitted'] = false;
        }

        if (isset($existing_aff[$sid])) {
            $a = $existing_aff[$sid];
            $student['punctuality'] = (int)$a['punctuality'] ?: 3;
            $student['neatness'] = (int)$a['neatness'] ?: 3;
            $student['attentiveness'] = (int)$a['attentiveness'] ?: 3;
            $student['honesty'] = (int)$a['honesty'] ?: 3;
            $student['politeness'] = (int)$a['politeness'] ?: 3;
        } else {
            $student['punctuality'] = 3; $student['neatness'] = 3; $student['attentiveness'] = 3;
            $student['honesty'] = 3; $student['politeness'] = 3;
        }
    }
    unset($student);
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scores'])) {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);

    $term = $current_term;
    $session = $current_session;

    $check = $db->prepare("SELECT id FROM teacher_subject_assignments WHERE teacher_id = ? AND school_id = ? AND class_id = ? AND subject_id = ?");
    $check->execute([$teacher_id, $school_id, $class_id, $subject_id]);
    if (!$check->fetch()) {
        $error = "You are not assigned to this class/subject.";
    } else {
        $approvedCheck = $db->prepare("SELECT COUNT(*) FROM exam_scores WHERE school_id = ? AND subject_id = ? AND class_id = ? AND term_name = ? AND session_year = ? AND is_approved = 1");
        $approvedCheck->execute([$school_id, $subject_id, $class_id, $term, $session]);
        if ($approvedCheck->fetchColumn() > 0) {
            $error = "Some scores are already approved. The owner must unlock them before you can save changes.";
        } else {
            $scores_data = $_POST['scores'] ?? [];
            if (empty($scores_data)) {
                $error = "No scores provided.";
            } else {
                try {
                    $db->beginTransaction();

                    $upsertStmt = $db->prepare("
                        INSERT INTO exam_scores 
                        (school_id, student_id, subject_id, class_id, term_name, session_year, term, session, 
                         ca1, ca2, ca3, exam, entered_by, is_submitted, is_approved, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            ca1 = VALUES(ca1), ca2 = VALUES(ca2), ca3 = VALUES(ca3), exam = VALUES(exam),
                            entered_by = VALUES(entered_by), is_submitted = 1, updated_at = NOW()
                    ");

                    $affUpsert = $db->prepare("
                        INSERT INTO student_affective_ratings 
                        (school_id, student_id, term_name, session_year, punctuality, neatness, attentiveness, honesty, politeness, entered_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            punctuality = VALUES(punctuality), neatness = VALUES(neatness),
                            attentiveness = VALUES(attentiveness), honesty = VALUES(honesty), politeness = VALUES(politeness),
                            entered_by = VALUES(entered_by), updated_at = NOW()
                    ");

                    foreach ($scores_data as $student_id => $scores) {
                        $student_id = (int)$student_id;
                        $ca1 = (float)($scores['ca1'] ?? 0);
                        $ca2 = (float)($scores['ca2'] ?? 0);
                        $ca3 = (float)($scores['ca3'] ?? 0);
                        $exam = (float)($scores['exam'] ?? 0);
                        $upsertStmt->execute([
                            $school_id, $student_id, $subject_id, $class_id, $term, $session, $term, $session,
                            $ca1, $ca2, $ca3, $exam, $teacher_id
                        ]);

                        $punctuality = max(1, min(5, (int)($scores['punctuality'] ?? 3)));
                        $neatness = max(1, min(5, (int)($scores['neatness'] ?? 3)));
                        $attentiveness = max(1, min(5, (int)($scores['attentiveness'] ?? 3)));
                        $honesty = max(1, min(5, (int)($scores['honesty'] ?? 3)));
                        $politeness = max(1, min(5, (int)($scores['politeness'] ?? 3)));
                        $affUpsert->execute([
                            $school_id, $student_id, $term, $session,
                            $punctuality, $neatness, $attentiveness, $honesty, $politeness, $teacher_id
                        ]);
                    }

                    $db->commit();
                    logActivity('SUBMIT_SCORES', "Submitted scores for class $class_id, subject $subject_id ($term)", $school_id, $teacher_id);
                    $_SESSION['success'] = "✅ Scores saved and sent to the owner for approval.";
                    header("Location: enter_scores.php?class_id=$class_id&subject_id=$subject_id");
                    exit;
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = "Failed to save scores: " . $e->getMessage();
                }
            }
        }
    }
}

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:16px;margin-bottom:20px;"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}
?>

<style>
    .tab-nav {
        display: flex; gap: 4px; border-bottom: 2px solid #f1f5f9; margin-bottom: 20px;
    }
    .tab-nav button {
        padding: 12px 24px; background: none; border: none; cursor: pointer;
        font-size: 0.9rem; font-weight: 600; color: #64748b;
        border-bottom: 2px solid transparent; margin-bottom: -2px;
    }
    .tab-nav button.active { color: #7c3aed; border-bottom-color: #7c3aed; }
    .tab-nav button:hover { color: #7c3aed; }

    .tab-pane { display: none; }
    .tab-pane.active { display: block; }

    .score-input {
        width: 70px;
        text-align: center;
        font-size: 0.9rem;
        padding: 6px;
    }
    .score-input:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124,58,237,0.1);
        outline: none;
    }

    .total-cell {
        font-weight: 700;
        font-size: 0.9rem;
    }
    .grade-cell {
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
    }
    .grade-A { background: #dcfce7; color: #166534; }
    .grade-B { background: #dbeafe; color: #1e40af; }
    .grade-C { background: #fef3c7; color: #92400e; }
    .grade-D { background: #fed7aa; color: #9a3412; }
    .grade-E { background: #fecaca; color: #991b1b; }
    .grade-F { background: #f1f5f9; color: #475569; }
    .grade-none { background: #f1f5f9; color: #94a3b8; }

    .score-row { border-bottom: 1px solid #f1f5f9; }
    .score-row:last-child { border-bottom: none; }
    .score-row:hover { background: #fafafa; }

    .student-photo {
        width: 34px; height: 34px; border-radius: 50%; object-fit: cover;
        border: 2px solid #e2e8f0;
    }

    .sticky-save {
        position: sticky;
        bottom: 0;
        background: white;
        border-top: 1px solid #e2e8f0;
        padding: 16px 24px;
        margin-top: 16px;
        border-radius: 0 0 16px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        z-index: 10;
    }

    .class-summary {
        display: flex; gap: 20px; flex-wrap: wrap;
        background: #f8fafc; padding: 12px 20px; border-radius: 12px;
        font-size: 0.85rem; margin-bottom: 16px;
    }
    .class-summary .stat strong { font-size: 1.1rem; color: #0f172a; }
    .class-summary .stat span { color: #64748b; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">✏️ Enter Scores</h1>
        <p style="color:#64748b; margin:0;">Entering scores for <strong><?php echo htmlspecialchars($current_term); ?> · <?php echo htmlspecialchars($current_session); ?></strong></p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px;">Back</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:16px;margin-bottom:20px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($has_approved): ?>
<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:16px;margin-bottom:20px;">
    <i class="fas fa-lock"></i> <strong>These scores are approved.</strong> You cannot edit them. Ask the Owner to unlock if you need changes.
</div>
<?php elseif ($has_submitted): ?>
<div class="alert alert-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:16px;margin-bottom:20px;">
    <i class="fas fa-clock"></i> <strong>Submitted — waiting for owner approval.</strong> You can still edit and re-save.
</div>
<?php else: ?>
<div class="alert alert-info" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:12px;padding:14px 20px;margin-bottom:20px;font-size:0.9rem;">
    <i class="fas fa-info-circle"></i> Fill in scores and click <strong>Save Scores</strong> when done. <strong>Tip:</strong> use the Tab key to move to the next student in the same column.
</div>
<?php endif; ?>

<!-- Class/Subject Filter -->
<div class="card mb-4" style="border-radius:16px;">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Class</label>
                <select name="class_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($assignments as $a): ?>
                        <option value="<?php echo $a['class_id']; ?>" <?php echo $selected_class_id == $a['class_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Subject</label>
                <select name="subject_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($assignments as $a): ?>
                        <?php if ($a['class_id'] == $selected_class_id): ?>
                            <?php $subjects = explode(',', $a['subject_ids']); $names = explode(',', $a['subject_names']); foreach ($subjects as $i => $sid): ?>
                                <option value="<?php echo $sid; ?>" <?php echo $selected_subject_id == $sid ? 'selected' : ''; ?>><?php echo htmlspecialchars($names[$i]); ?></option>
                            <?php endforeach; endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Search</label>
                <input type="text" id="studentSearch" class="form-control" placeholder="Filter by name or ID...">
            </div>
        </form>
    </div>
</div>

<?php if ($selected_class_id > 0 && !empty($students)): ?>

    <!-- Tabs -->
    <div class="tab-nav">
        <button type="button" class="active" data-tab="academic">
            <i class="fas fa-graduation-cap"></i> Academic Scores
        </button>
        <button type="button" data-tab="affective">
            <i class="fas fa-heart"></i> Affective Ratings
        </button>
    </div>

    <form method="POST" id="scoresForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
        <input type="hidden" name="subject_id" value="<?php echo $selected_subject_id; ?>">

        <!-- ACADEMIC TAB -->
        <div class="tab-pane active" data-pane="academic">
            <div class="class-summary">
                <div class="stat"><strong><?php echo count($students); ?></strong> <span>Students</span></div>
                <div class="stat"><strong id="avgScore">—</strong> <span>Class Average</span></div>
                <div class="stat"><strong id="enteredCount">0</strong> <span>Entered</span></div>
            </div>

            <div style="background:#fff; border-radius:16px; overflow-x:auto; border:1px solid #f1f5f9;">
                <table class="table" style="margin-bottom:0;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th style="padding:12px 16px;">Student</th>
                            <th style="text-align:center; padding:12px 8px;">CA1<br><small style="color:#94a3b8;">(20)</small></th>
                            <th style="text-align:center; padding:12px 8px;">CA2<br><small style="color:#94a3b8;">(20)</small></th>
                            <th style="text-align:center; padding:12px 8px;">CA3<br><small style="color:#94a3b8;">(20)</small></th>
                            <th style="text-align:center; padding:12px 8px;">Exam<br><small style="color:#94a3b8;">(40)</small></th>
                            <th style="text-align:center; padding:12px 8px;">Total</th>
                            <th style="text-align:center; padding:12px 8px;">Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student):
                            $locked = $student['is_approved'];
                            $search_key = strtolower($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['student_id']);
                            $photo_url = ($student['photo_path'] && $student['photo_path'] !== 'default_student.png') 
                                ? BASE_URL . 'uploads/student_photos/' . $student['photo_path'] 
                                : BASE_URL . 'assets/images/default_avatar.png';
                            $total = ($student['ca1'] ?: 0) + ($student['ca2'] ?: 0) + ($student['ca3'] ?: 0) + ($student['exam'] ?: 0);
                            $has_any = ($student['ca1'] !== '' || $student['ca2'] !== '' || $student['ca3'] !== '' || $student['exam'] !== '');
                            $grade = '';
                            if ($has_any) {
                                if ($total >= 80) $grade = 'A';
                                elseif ($total >= 70) $grade = 'B';
                                elseif ($total >= 60) $grade = 'C';
                                elseif ($total >= 50) $grade = 'D';
                                elseif ($total >= 40) $grade = 'E';
                                else $grade = 'F';
                            }
                        ?>
                        <tr class="score-row" data-search="<?php echo htmlspecialchars($search_key); ?>" style="<?php echo $locked ? 'background:#f0fdf4;' : ''; ?>">
                            <td style="padding:12px 16px;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <img src="<?php echo $photo_url; ?>" class="student-photo viewable-photo" alt="">
                                    <div>
                                        <div style="font-weight:500; font-size:0.9rem;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                                        <div style="font-size:0.75rem; color:#94a3b8;"><?php echo htmlspecialchars($student['student_id']); ?>
                                            <?php if ($student['is_approved']): ?>
                                                <span class="badge bg-success" style="margin-left:6px; font-size:0.65rem;">Approved</span>
                                            <?php elseif ($student['is_submitted']): ?>
                                                <span class="badge bg-warning text-dark" style="margin-left:6px; font-size:0.65rem;">Submitted</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td style="text-align:center; padding:8px;">
                                <input type="number" step="0.01" min="0" max="20" 
                                       name="scores[<?php echo $student['id']; ?>][ca1]" 
                                       class="form-control score-input ca-input" 
                                       data-field="ca1"
                                       value="<?php echo htmlspecialchars($student['ca1']); ?>" 
                                       <?php echo $locked ? 'readonly' : ''; ?>>
                            </td>
                            <td style="text-align:center; padding:8px;">
                                <input type="number" step="0.01" min="0" max="20" 
                                       name="scores[<?php echo $student['id']; ?>][ca2]" 
                                       class="form-control score-input ca-input" 
                                       data-field="ca2"
                                       value="<?php echo htmlspecialchars($student['ca2']); ?>" 
                                       <?php echo $locked ? 'readonly' : ''; ?>>
                            </td>
                            <td style="text-align:center; padding:8px;">
                                <input type="number" step="0.01" min="0" max="20" 
                                       name="scores[<?php echo $student['id']; ?>][ca3]" 
                                       class="form-control score-input ca-input" 
                                       data-field="ca3"
                                       value="<?php echo htmlspecialchars($student['ca3']); ?>" 
                                       <?php echo $locked ? 'readonly' : ''; ?>>
                            </td>
                            <td style="text-align:center; padding:8px;">
                                <input type="number" step="0.01" min="0" max="40" 
                                       name="scores[<?php echo $student['id']; ?>][exam]" 
                                       class="form-control score-input ca-input" 
                                       data-field="exam"
                                       value="<?php echo htmlspecialchars($student['exam']); ?>" 
                                       <?php echo $locked ? 'readonly' : ''; ?>>
                            </td>
                            <td style="text-align:center; padding:8px;">
                                <span class="total-cell" data-total="<?php echo $student['id']; ?>"><?php echo $has_any ? number_format($total, 1) : '—'; ?></span>
                            </td>
                            <td style="text-align:center; padding:8px;">
                                <span class="grade-cell grade-<?php echo $grade ?: 'none'; ?>" data-grade="<?php echo $student['id']; ?>"><?php echo $grade ?: '—'; ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- AFFECTIVE TAB -->
        <div class="tab-pane" data-pane="affective">
            <div class="alert alert-info" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:12px;padding:14px 20px;margin-bottom:16px;font-size:0.9rem;">
                <i class="fas fa-info-circle"></i> Rate each student 1–5 (1 = Poor, 5 = Excellent). Default is 3 (Good).
            </div>

            <div style="background:#fff; border-radius:16px; overflow-x:auto; border:1px solid #f1f5f9;">
                <table class="table" style="margin-bottom:0;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th style="padding:12px 16px;">Student</th>
                            <th style="text-align:center;">Punctual</th>
                            <th style="text-align:center;">Neatness</th>
                            <th style="text-align:center;">Attention</th>
                            <th style="text-align:center;">Honesty</th>
                            <th style="text-align:center;">Politeness</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student):
                            $locked = $student['is_approved'];
                            $search_key = strtolower($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['student_id']);
                        ?>
                        <tr class="score-row" data-search="<?php echo htmlspecialchars($search_key); ?>" style="<?php echo $locked ? 'background:#f0fdf4;' : ''; ?>">
                            <td style="padding:12px 16px; font-weight:500; font-size:0.9rem;">
                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                            </td>
                            <?php foreach (['punctuality','neatness','attentiveness','honesty','politeness'] as $trait): ?>
                                <td style="text-align:center; padding:8px;">
                                    <select name="scores[<?php echo $student['id']; ?>][<?php echo $trait; ?>]" 
                                            class="form-control form-control-sm affective-select" 
                                            style="width:70px; margin:0 auto; font-size:0.85rem;"
                                            <?php echo $locked ? 'disabled' : ''; ?>>
                                        <option value="1" <?php echo $student[$trait] == 1 ? 'selected' : ''; ?>>1</option>
                                        <option value="2" <?php echo $student[$trait] == 2 ? 'selected' : ''; ?>>2</option>
                                        <option value="3" <?php echo $student[$trait] == 3 ? 'selected' : ''; ?>>3</option>
                                        <option value="4" <?php echo $student[$trait] == 4 ? 'selected' : ''; ?>>4</option>
                                        <option value="5" <?php echo $student[$trait] == 5 ? 'selected' : ''; ?>>5</option>
                                    </select>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sticky Save -->
        <?php if (!$has_approved): ?>
        <div class="sticky-save">
            <div style="font-size:0.85rem; color:#64748b;">
                <i class="fas fa-info-circle"></i> Don't forget to save. Scores will be sent to the owner for approval.
            </div>
            <button type="submit" name="save_scores" value="1" class="btn btn-primary" 
                    style="background:linear-gradient(135deg,#7c3aed,#6d28d9); border:none; border-radius:10px; padding:12px 40px; font-weight:600;">
                <i class="fas fa-paper-plane"></i> Save Scores & Send for Approval
            </button>
        </div>
        <?php endif; ?>
    </form>

    <script>
    // Tab switching
    document.querySelectorAll('.tab-nav button').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.tab-nav button').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            document.querySelector(`.tab-pane[data-pane="${this.dataset.tab}"]`).classList.add('active');
        });
    });

    // Live totals and grades
    function updateStudentTotal(studentId) {
        const ca1 = parseFloat(document.querySelector(`input[name="scores[${studentId}][ca1]"]`)?.value) || 0;
        const ca2 = parseFloat(document.querySelector(`input[name="scores[${studentId}][ca2]"]`)?.value) || 0;
        const ca3 = parseFloat(document.querySelector(`input[name="scores[${studentId}][ca3]"]`)?.value) || 0;
        const exam = parseFloat(document.querySelector(`input[name="scores[${studentId}][exam]"]`)?.value) || 0;
        const total = ca1 + ca2 + ca3 + exam;
        const hasAny = ca1 || ca2 || ca3 || exam;

        let grade = '';
        if (hasAny) {
            if (total >= 80) grade = 'A';
            else if (total >= 70) grade = 'B';
            else if (total >= 60) grade = 'C';
            else if (total >= 50) grade = 'D';
            else if (total >= 40) grade = 'E';
            else grade = 'F';
        }

        const totalEl = document.querySelector(`[data-total="${studentId}"]`);
        if (totalEl) totalEl.textContent = hasAny ? total.toFixed(1) : '—';

        const gradeEl = document.querySelector(`[data-grade="${studentId}"]`);
        if (gradeEl) {
            gradeEl.textContent = grade || '—';
            gradeEl.className = 'grade-cell grade-' + (grade || 'none');
        }

        updateClassSummary();
    }

    function updateClassSummary() {
        let totalSum = 0, entered = 0;
        document.querySelectorAll('.score-row[data-search]').forEach(row => {
            const inputs = row.querySelectorAll('.ca-input');
            if (inputs.length === 0) return;
            let hasValue = false;
            inputs.forEach(inp => { if (inp.value !== '') hasValue = true; });
            if (hasValue) {
                entered++;
                const studentId = inputs[0].name.match(/\[(\d+)\]/)[1];
                const totalEl = document.querySelector(`[data-total="${studentId}"]`);
                if (totalEl && totalEl.textContent !== '—') {
                    totalSum += parseFloat(totalEl.textContent) || 0;
                }
            }
        });
        document.getElementById('enteredCount').textContent = entered;
        document.getElementById('avgScore').textContent = entered > 0 ? (totalSum / entered).toFixed(1) : '—';
    }

    // Bind input events
    document.querySelectorAll('.ca-input').forEach(input => {
        input.addEventListener('input', function() {
            const m = this.name.match(/\[(\d+)\]/);
            if (m) updateStudentTotal(m[1]);
        });
    });

    // Tab navigation between columns
    // Order: ca1, ca2, ca3, exam — Tab moves to next student in same column
    document.querySelectorAll('.ca-input').forEach(input => {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Move to the same field of the next student
                const field = this.dataset.field;
                const allSameField = Array.from(document.querySelectorAll(`.ca-input[data-field="${field}"]`));
                const idx = allSameField.indexOf(this);
                const next = allSameField[idx + 1];
                if (next) next.focus();
            }
        });
    });

    // Search filter
    document.getElementById('studentSearch').addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('.score-row[data-search]').forEach(row => {
            const key = row.getAttribute('data-search');
            row.style.display = (q === '' || key.includes(q)) ? '' : 'none';
        });
    });

    // Unsaved changes warning
    let formDirty = false;
    const form = document.getElementById('scoresForm');
    if (form) {
        form.querySelectorAll('.ca-input, .affective-select').forEach(el => {
            el.addEventListener('change', () => { formDirty = true; });
        });
        form.addEventListener('submit', () => { formDirty = false; });
        window.addEventListener('beforeunload', function(e) {
            if (formDirty) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    }

    // Initial summary
    updateClassSummary();
    document.querySelectorAll('.ca-input').forEach(input => {
        const m = input.name.match(/\[(\d+)\]/);
        if (m && input.value !== '') updateStudentTotal(m[1]);
    });
    </script>

<?php elseif ($selected_class_id > 0 && empty($students)): ?>
    <div class="alert alert-info">No active students in this class.</div>
<?php else: ?>
    <div class="alert alert-info">You have no assigned classes/subjects. Ask the Owner to assign you.</div>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>