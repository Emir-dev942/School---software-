<?php
// teacher/generate_exam.php - Advanced Exam Generator (v3: simplified UX)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Teacher']);
requireCsrf();

$db = getDB();
$teacher_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['school_id'];

if (!hasPermission(PERM_GENERATE_EXAMS)) {
    include_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">You do not have permission to generate exams.</div>';
    include_once __DIR__ . '/../includes/footer.php';
    exit;
}

$message = '';
$error = '';

// ------------------ AJAX: Get questions by class/subject ------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_questions') {
    $class_id   = (int)($_GET['class_id'] ?? 0);
    $subject_id = (int)($_GET['subject_id'] ?? 0);
    $type       = sanitize($_GET['type'] ?? 'all');

    if ($class_id <= 0 || $subject_id <= 0) {
        echo json_encode([]);
        exit;
    }

    $params = [$school_id, $class_id, $subject_id];
    $where  = "q.school_id = ? AND q.class_id = ? AND q.subject_id = ? AND q.is_active = 1";

    if ($type !== 'all') {
        $where .= " AND q.question_type = ?";
        $params[] = $type;
    }

    $sql = "SELECT q.id, q.question_text, q.question_type, q.marks,
                   q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer,
                   q.expected_answer, q.answer_explanation
            FROM exam_questions q
            WHERE $where
            ORDER BY q.id DESC
            LIMIT 300";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $questions = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($questions);
    exit;
}

// ------------------ Handle exam generation ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_exam'])) {
    $class_id   = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $exam_title = trim($_POST['exam_title'] ?? 'Exam Paper');
    $duration   = (int)($_POST['duration'] ?? 60);
    $instructions = trim($_POST['instructions'] ?? 'Answer all questions. Shade your answers clearly.');
    $selected_ids = array_filter(array_map('intval', explode(',', $_POST['selected_questions'] ?? '')));

    if ($class_id <= 0 || $subject_id <= 0 || empty($selected_ids)) {
        $error = "Please select class, subject, and at least one question.";
    } else {
        $assignCheck = $db->prepare("SELECT id FROM teacher_subject_assignments WHERE teacher_id=? AND school_id=? AND class_id=? AND subject_id=?");
        $assignCheck->execute([$teacher_id, $school_id, $class_id, $subject_id]);
        if (!$assignCheck->fetch()) {
            $error = "You are not assigned to this class/subject.";
        } else {
            try {
                $db->beginTransaction();

                $schoolStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id=?");
                $schoolStmt->execute([$school_id]);
                $schoolInfo = $schoolStmt->fetch();
                $term = $schoolInfo['current_term'] ?? 'Term 1';
                $session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

                $exam_code = strtoupper(substr(uniqid(), -5)) . 'A';
                $total_marks = 0;

                $stmt = $db->prepare("INSERT INTO generated_exams 
                    (school_id, class_id, subject_id, teacher_id, exam_title, exam_date, term, session_year,
                     total_marks, duration_minutes, exam_code, generated_at, is_published, exam_version, instructions, is_draft, published_at)
                    VALUES (?,?,?,?,?, CURDATE(), ?, ?, ?, ?, ?, NOW(), 0, 'A', ?, 0, NULL)");
                $stmt->execute([
                    $school_id, $class_id, $subject_id, $teacher_id, $exam_title,
                    $term, $session, 0, $duration, $exam_code, $instructions
                ]);
                $exam_sheet_id = (int)$db->lastInsertId();

                $insertQ = $db->prepare("INSERT INTO generated_exam_questions 
                    (exam_sheet_id, question_id, question_number, marks, shuffled_options, question_type, correct_answer, explanation)
                    VALUES (?,?,?,?,?,?,?,?)");

                $q_num = 1;
                foreach ($selected_ids as $qid) {
                    $qStmt = $db->prepare("SELECT question_type, option_a, option_b, option_c, option_d, correct_answer, marks, expected_answer, answer_explanation FROM exam_questions WHERE id=? AND school_id=?");
                    $qStmt->execute([$qid, $school_id]);
                    $q = $qStmt->fetch();
                    if (!$q) continue;

                    $total_marks += $q['marks'];
                    $shuffled_json = null;
                    $correct_ans = '';
                    $explanation = $q['answer_explanation'] ?? '';

                    if ($q['question_type'] === 'mcq') {
                        $options = ['A'=>$q['option_a'],'B'=>$q['option_b'],'C'=>$q['option_c'],'D'=>$q['option_d']];
                        $keys = array_keys($options);
                        shuffle($keys);
                        $new_options = [];
                        $new_correct_letter = null;
                        foreach ($keys as $i => $old_letter) {
                            $new_letter = chr(65 + $i);
                            $new_options[$new_letter] = $options[$old_letter];
                            if ($old_letter === $q['correct_answer']) {
                                $new_correct_letter = $new_letter;
                            }
                        }
                        $shuffled_json = json_encode(['options'=>$new_options, 'correct'=>$new_correct_letter]);
                        $correct_ans = $new_correct_letter;
                    } elseif ($q['question_type'] === 'fill_blank') {
                        $correct_ans = $q['expected_answer'] ?? $q['correct_answer'] ?? '';
                    } elseif ($q['question_type'] === 'true_false') {
                        $correct_ans = $q['correct_answer'] ?? '';
                    } elseif ($q['question_type'] === 'theory') {
                        $correct_ans = $q['expected_answer'] ?? $q['correct_answer'] ?? '';
                    }

                    $insertQ->execute([
                        $exam_sheet_id, $qid, $q_num, $q['marks'],
                        $shuffled_json, $q['question_type'], $correct_ans, $explanation
                    ]);
                    $q_num++;
                }

                $db->prepare("UPDATE generated_exams SET total_marks=? WHERE id=?")
                   ->execute([$total_marks, $exam_sheet_id]);

                $hash_input = $exam_code . '|' . $exam_title . '|' . $exam_sheet_id;
                $hash = hash('sha256', $hash_input);
                $db->prepare("UPDATE generated_exams SET integrity_hash=? WHERE id=?")
                   ->execute([$hash, $exam_sheet_id]);

                $db->commit();
                logActivity('GENERATE_EXAM', "Generated exam '$exam_title' with " . count($selected_ids) . " questions", $school_id, $teacher_id);

                header("Location: view_exam.php?id=$exam_sheet_id&success=1");
                exit;

            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = "Failed to generate exam: " . $e->getMessage();
            }
        }
    }
}

$assignments = $db->prepare("
    SELECT DISTINCT c.id AS class_id, c.name AS class_name,
           GROUP_CONCAT(DISTINCT s.id ORDER BY s.name SEPARATOR ',') AS subject_ids,
           GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS subject_names
    FROM teacher_subject_assignments tsa
    JOIN classes c ON tsa.class_id = c.id
    JOIN subjects s ON tsa.subject_id = s.id
    WHERE tsa.teacher_id = ? AND tsa.school_id = ? AND c.status='active' AND s.status='active'
    GROUP BY c.id, c.name
    ORDER BY c.name
");
$assignments->execute([$teacher_id, $school_id]);
$assignments = $assignments->fetchAll();

include_once __DIR__ . '/../includes/header.php';
?>

<style>
    .step-card { background:#fff; border-radius:16px; padding:24px; border:1px solid #f1f5f9; margin-bottom:20px; }
    .step-card h5 { font-weight:700; margin-bottom:18px; display:flex; align-items:center; gap:8px; }
    .step-num {
        display:inline-block; background:#7c3aed; color:#fff; width:26px; height:26px;
        border-radius:50%; text-align:center; line-height:26px; font-size:0.85rem; font-weight:700;
    }
    .q-row {
        padding:10px 14px; border-bottom:1px solid #f1f5f9; display:flex; align-items:flex-start; gap:12px;
    }
    .q-row:last-child { border-bottom:none; }
    .q-row:hover { background:#fafafa; }
    .q-row input[type=checkbox] { width:18px; height:18px; margin-top:2px; }
    .q-row .q-text { flex:1; font-size:0.9rem; }
    .q-row .q-meta { font-size:0.75rem; color:#94a3b8; white-space:nowrap; }
    .pick-tabs { display:flex; gap:8px; margin-bottom:16px; }
    .pick-tabs button {
        flex:1; padding:12px; border:2px solid #e2e8f0; background:#fff; border-radius:12px;
        font-weight:600; cursor:pointer; transition:0.2s;
    }
    .pick-tabs button.active { border-color:#7c3aed; background:#f5f3ff; color:#7c3aed; }
    .pick-pane { display:none; }
    .pick-pane.active { display:block; }
    .summary-bar {
        background:#f0fdf4; border:1px solid #86efac; border-radius:12px; padding:16px 20px;
        display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;
    }
    .summary-bar .stat { text-align:center; }
    .summary-bar .stat .num { font-size:1.5rem; font-weight:800; color:#16a34a; }
    .summary-bar .stat .lbl { font-size:0.7rem; text-transform:uppercase; color:#166534; font-weight:700; }
    .q-list { max-height:500px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:12px; }
    .q-list::-webkit-scrollbar { width:6px; }
    .q-list::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:3px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700;">📝 Generate Exam</h1>
        <p class="text-muted" style="margin:0;">Pick a class, choose questions, generate. Done.</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px;">Back</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:16px;margin-bottom:20px;">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if (empty($assignments)): ?>
    <div class="alert alert-warning">You are not assigned to any class/subject.</div>
<?php else: ?>
    <form method="POST" id="examForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="selected_questions" id="selected_questions" value="">

        <!-- STEP 1 -->
        <div class="step-card">
            <h5><span class="step-num">1</span> Exam Setup</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Class *</label>
                    <select name="class_id" id="class_id" class="form-control" required>
                        <option value="">Select Class</option>
                        <?php foreach ($assignments as $a): ?>
                            <option value="<?php echo $a['class_id']; ?>"><?php echo htmlspecialchars($a['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Subject *</label>
                    <select name="subject_id" id="subject_id" class="form-control" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($assignments as $a): ?>
                            <?php $subs = explode(',', $a['subject_ids']); $names = explode(',', $a['subject_names']); foreach ($subs as $i=>$sid): ?>
                                <option value="<?php echo $sid; ?>" data-class="<?php echo $a['class_id']; ?>"><?php echo htmlspecialchars($names[$i]); ?></option>
                            <?php endforeach; endforeach; ?>
                        </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Exam Title *</label>
                    <input type="text" name="exam_title" class="form-control" required placeholder="e.g., First Term Examination">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Duration (min)</label>
                    <input type="number" name="duration" class="form-control" value="60" min="5">
                </div>
            </div>
            <div class="mt-3">
                <label class="form-label fw-semibold">Instructions</label>
                <textarea name="instructions" class="form-control" rows="2">Answer all questions. Shade your answers clearly.</textarea>
            </div>
        </div>

        <!-- STEP 2 -->
        <div class="step-card">
            <h5><span class="step-num">2</span> Choose Questions</h5>

            <div class="pick-tabs">
                <button type="button" class="active" data-pane="auto">⚡ Let System Pick</button>
                <button type="button" data-pane="manual">✋ I'll Pick Manually</button>
            </div>

            <!-- AUTO PICK -->
            <div class="pick-pane active" data-pane="auto">
                <div class="alert alert-info" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;border-radius:10px;font-size:0.9rem;">
                    <i class="fas fa-info-circle"></i> We'll pick random questions matching your filters. You can review and adjust after.
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Number of Questions</label>
                        <input type="number" id="auto_count" class="form-control" value="20" min="1" max="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Question Type</label>
                        <select id="auto_type" class="form-control">
                            <option value="all">All Types</option>
                            <option value="mcq">MCQ Only</option>
                            <option value="true_false">True/False Only</option>
                            <option value="fill_blank">Fill Blank Only</option>
                            <option value="theory">Theory Only</option>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="button" class="btn btn-primary" onclick="autoPick()" style="border-radius:10px;">
                            <i class="fas fa-magic"></i> Pick Questions
                        </button>
                    </div>
                </div>
            </div>

            <!-- MANUAL PICK -->
            <div class="pick-pane" data-pane="manual">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Filter by Type</label>
                        <select id="manual_type" class="form-control" onchange="loadQuestions()">
                            <option value="all">All Types</option>
                            <option value="mcq">MCQ</option>
                            <option value="true_false">True/False</option>
                            <option value="fill_blank">Fill Blank</option>
                            <option value="theory">Theory</option>
                        </select>
                    </div>
                    <div class="col-md-9 d-flex align-items-end gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="selectAll(true)">Select All</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="selectAll(false)">Select None</button>
                    </div>
                </div>
            </div>

            <!-- QUESTION LIST (shared for both) -->
            <div id="questionListWrap" style="display:none; margin-top:16px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <strong style="font-size:0.9rem;">Available Questions</strong>
                    <span id="listCount" class="text-muted" style="font-size:0.85rem;"></span>
                </div>
                <div class="q-list" id="questionList"></div>
            </div>
        </div>

        <!-- STEP 3 -->
        <div class="step-card">
            <h5><span class="step-num">3</span> Review & Generate</h5>
            <div class="summary-bar" style="margin-bottom:16px;">
                <div class="stat"><div class="num" id="sum_count">0</div><div class="lbl">Questions Selected</div></div>
                <div class="stat"><div class="num" id="sum_marks">0</div><div class="lbl">Total Marks</div></div>
                <div class="stat"><div class="num" id="sum_breakdown" style="font-size:0.9rem;color:#166534;">—</div><div class="lbl">By Type</div></div>
                <button type="button" class="btn btn-light" onclick="clearSelection()" style="border-radius:10px; font-size:0.85rem;">Clear Selection</button>
            </div>

            <div style="text-align:center; padding:16px 0;">
                <button type="submit" name="generate_exam" class="btn btn-success" style="padding:14px 48px;border-radius:10px;font-weight:600;font-size:1rem;" id="generateBtn" disabled>
                    <i class="fas fa-check-circle"></i> Generate Exam
                </button>
                <p class="text-muted" style="margin-top:12px;font-size:0.85rem;">Select at least one question to enable this button.</p>
            </div>
        </div>
    </form>
<?php endif; ?>

<script>
let allQuestions = [];
let selectedIds = new Set();

// Tab switching
document.querySelectorAll('.pick-tabs button').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.pick-tabs button').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.pick-pane').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.querySelector(`.pick-pane[data-pane="${this.dataset.pane}"]`).classList.add('active');
    });
});

// Load questions when class + subject are set
function maybeLoad() {
    const c = document.getElementById('class_id').value;
    const s = document.getElementById('subject_id').value;
    if (c && s) {
        loadQuestions();
    }
}

document.getElementById('class_id').addEventListener('change', maybeLoad);
document.getElementById('subject_id').addEventListener('change', maybeLoad);

function loadQuestions() {
    const classId = document.getElementById('class_id').value;
    const subjectId = document.getElementById('subject_id').value;
    if (!classId || !subjectId) return;

    const type = document.getElementById('manual_type')?.value || 'all';
    const url = `generate_exam.php?ajax=get_questions&class_id=${classId}&subject_id=${subjectId}&type=${type}`;

    fetch(url)
        .then(res => res.json())
        .then(data => {
            allQuestions = data;
            renderQuestions();
            document.getElementById('questionListWrap').style.display = 'block';
        })
        .catch(err => console.error(err));
}

function renderQuestions() {
    const listEl = document.getElementById('questionList');
    const countEl = document.getElementById('listCount');
    if (allQuestions.length === 0) {
        listEl.innerHTML = '<p style="padding:20px;text-align:center;color:#94a3b8;">No questions found. Try a different type filter, or add questions to the bank first.</p>';
        countEl.textContent = '';
        return;
    }
    countEl.textContent = allQuestions.length + ' questions available';
    listEl.innerHTML = allQuestions.map(q => {
        const checked = selectedIds.has(q.id) ? 'checked' : '';
        const typeLabel = q.question_type === 'mcq' ? 'MCQ' :
                          q.question_type === 'true_false' ? 'T/F' :
                          q.question_type === 'fill_blank' ? 'Fill' : 'Theory';
        return `
            <label class="q-row" style="cursor:pointer;">
                <input type="checkbox" ${checked} onchange="toggleQ(${q.id}, this.checked)">
                <div class="q-text">
                    <strong>${escapeHtml(q.question_text)}</strong>
                </div>
                <div class="q-meta">${typeLabel} • ${q.marks} marks</div>
            </label>
        `;
    }).join('');
}

function toggleQ(id, checked) {
    if (checked) selectedIds.add(id);
    else selectedIds.delete(id);
    updateSummary();
}

function selectAll(checked) {
    if (checked) {
        allQuestions.forEach(q => selectedIds.add(q.id));
    } else {
        selectedIds.clear();
    }
    renderQuestions();
    updateSummary();
}

function clearSelection() {
    selectedIds.clear();
    renderQuestions();
    updateSummary();
}

function autoPick() {
    const count = parseInt(document.getElementById('auto_count').value) || 20;
    const type = document.getElementById('auto_type').value;

    // If type is filtered, reload questions first
    if (type !== (document.getElementById('manual_type')?.value || 'all')) {
        const classId = document.getElementById('class_id').value;
        const subjectId = document.getElementById('subject_id').value;
        if (!classId || !subjectId) {
            alert('Please select class and subject first.');
            return;
        }
        document.getElementById('manual_type').value = type;
        fetch(`generate_exam.php?ajax=get_questions&class_id=${classId}&subject_id=${subjectId}&type=${type}`)
            .then(res => res.json())
            .then(data => {
                allQuestions = data;
                doAutoPick(count);
            });
    } else {
        doAutoPick(count);
    }
}

function doAutoPick(count) {
    if (allQuestions.length === 0) {
        alert('No questions available for this filter.');
        return;
    }
    // Shuffle and take first N
    const shuffled = [...allQuestions].sort(() => Math.random() - 0.5);
    const picked = shuffled.slice(0, Math.min(count, shuffled.length));

    selectedIds.clear();
    picked.forEach(q => selectedIds.add(q.id));

    // Switch to manual tab to show the picked questions
    document.querySelectorAll('.pick-tabs button').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.pick-pane').forEach(p => p.classList.remove('active'));
    document.querySelector('.pick-tabs button[data-pane="manual"]').classList.add('active');
    document.querySelector('.pick-pane[data-pane="manual"]').classList.add('active');

    renderQuestions();
    updateSummary();
}

function updateSummary() {
    const ids = Array.from(selectedIds);
    let totalMarks = 0;
    const breakdown = {};
    ids.forEach(id => {
        const q = allQuestions.find(x => x.id == id);
        if (q) {
            totalMarks += parseInt(q.marks) || 0;
            breakdown[q.question_type] = (breakdown[q.question_type] || 0) + 1;
        }
    });
    document.getElementById('sum_count').textContent = ids.length;
    document.getElementById('sum_marks').textContent = totalMarks;

    const labels = {mcq: 'MCQ', theory: 'Theory', true_false: 'T/F', fill_blank: 'Fill'};
    const parts = Object.entries(breakdown).map(([t, n]) => `${n} ${labels[t] || t}`);
    document.getElementById('sum_breakdown').textContent = parts.length ? parts.join(' • ') : '—';

    document.getElementById('selected_questions').value = ids.join(',');
    document.getElementById('generateBtn').disabled = ids.length === 0;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>