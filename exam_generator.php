<?php
// school_owner/exam_generator.php - Generate Exam by Topic (PDO + CSRF)
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

// ---------- AJAX: GET TOPICS BY CLASS/SUBJECT ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_topics') {
    $class_id = (int)($_GET['class_id'] ?? 0);
    $subject_id = (int)($_GET['subject_id'] ?? 0);
    $topics = [];
    if ($class_id > 0 && $subject_id > 0) {
        $stmt = $db->prepare("SELECT id, topic FROM scheme_topics WHERE school_id = ? AND class_id = ? AND subject_id = ? ORDER BY topic");
        $stmt->execute([$school_id, $class_id, $subject_id]);
        $topics = $stmt->fetchAll();
    }
    header('Content-Type: application/json');
    echo json_encode($topics);
    exit;
}

// ---------- AJAX: GET QUESTIONS BY TOPIC ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_questions') {
    $topic_id = (int)($_GET['topic_id'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 20);
    $questions = [];
    if ($topic_id > 0) {
        $stmt = $db->prepare("SELECT q.*, 
                                (SELECT correct_answer FROM exam_answers WHERE question_id = q.id) as answer
                                FROM exam_questions q 
                                WHERE q.school_id = ? AND q.topic_id = ? AND q.is_active = 1 
                                ORDER BY q.difficulty DESC, RAND() 
                                LIMIT " . $limit);
        $stmt->execute([$school_id, $topic_id]);
        $questions = $stmt->fetchAll();
    }
    header('Content-Type: application/json');
    echo json_encode($questions);
    exit;
}

// ---------- HANDLE GENERATE EXAM ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_exam'])) {
    $topic_id = (int)($_POST['topic_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $selected_questions = $_POST['selected_questions'] ?? '';
    $exam_title = trim($_POST['exam_title'] ?? 'Exam Paper');
    $duration = (int)($_POST['duration'] ?? 60);

    $selected_ids = array_filter(array_map('intval', explode(',', $selected_questions)));

    if ($topic_id <= 0 || $class_id <= 0 || $subject_id <= 0 || empty($selected_ids)) {
        $error = "Please select class, subject, topic, and at least one question.";
    } else {
        try {
            $db->beginTransaction();

            // Calculate total marks
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            $marksStmt = $db->prepare("SELECT COALESCE(SUM(marks),0) FROM exam_questions WHERE id IN ($placeholders)");
            $marksStmt->execute($selected_ids);
            $total_marks = (int)$marksStmt->fetchColumn();

            // Get current term and session
            $schoolStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
            $schoolStmt->execute([$school_id]);
            $schoolInfo = $schoolStmt->fetch();
            $term = $schoolInfo['current_term'] ?? 'Term 1';
            $session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

            // Insert exam
            $exam_code = strtoupper(substr(uniqid(), -6));
            $stmt = $db->prepare("INSERT INTO generated_exams 
                (school_id, class_id, subject_id, teacher_id, exam_title, exam_date, term, session_year, total_marks, duration_minutes, exam_code, generated_at, is_published)
                VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, NOW(), 0)");
            $stmt->execute([$school_id, $class_id, $subject_id, $user_id, $exam_title, $term, $session, $total_marks, $duration, $exam_code]);
            $exam_sheet_id = (int)$db->lastInsertId();

            // Insert selected questions
            $insertQ = $db->prepare("INSERT INTO generated_exam_questions (exam_sheet_id, question_id, question_number, marks) VALUES (?, ?, ?, (SELECT marks FROM exam_questions WHERE id = ?))");
            $question_number = 1;
            foreach ($selected_ids as $qid) {
                $insertQ->execute([$exam_sheet_id, $qid, $question_number, $qid]);
                $question_number++;
            }

            $db->commit();
            logActivity('GENERATE_EXAM', "Generated exam: $exam_title (Code: $exam_code)", $school_id, $user_id);
            $message = "✅ Exam generated successfully! <a href='print_exam.php?id=$exam_sheet_id' class='alert-link' target='_blank'>Click here to view/print</a>";
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Failed to generate exam: " . $e->getMessage();
        }
    }
}

// ---------- FETCH CLASSES AND SUBJECTS ----------
$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

$subjects = $db->prepare("SELECT id, name FROM subjects WHERE school_id = ? AND status='active' ORDER BY name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">📝 Exam Generator</h1>
        <p style="color: #64748b; margin: 0;">Select a topic, choose questions, and generate a printable exam paper.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary" style="border-radius: 10px; padding: 8px 18px;">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:16px 20px;border-radius:12px;margin-bottom:24px;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:16px 20px;border-radius:12px;margin-bottom:24px;">
        ❌ <?php echo $error; ?>
    </div>
<?php endif; ?>

<!-- Step 1: Select Class, Subject, Topic -->
<div style="background: #ffffff; border-radius: 16px; padding: 24px; border: 1px solid #f1f5f9; margin-bottom: 24px;">
    <h5 style="font-weight: 600; color: #0f172a; margin-bottom: 16px;">📋 Step 1: Select Topic</h5>
    <div class="row">
        <div class="col-md-4">
            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Class</label>
                <select id="filterClass" class="form-control" style="border-radius: 10px; padding: 10px 14px; border-color: #e2e8f0;">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Subject</label>
                <select id="filterSubject" class="form-control" style="border-radius: 10px; padding: 10px 14px; border-color: #e2e8f0;">
                    <option value="">Select Subject</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Topic</label>
                <select id="filterTopic" class="form-control" disabled style="border-radius: 10px; padding: 10px 14px; border-color: #e2e8f0;">
                    <option value="">Select Topic</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- Step 2: Questions List -->
<div style="background: #ffffff; border-radius: 16px; padding: 24px; border: 1px solid #f1f5f9; margin-bottom: 24px;">
    <h5 style="font-weight: 600; color: #0f172a; margin-bottom: 16px;">📄 Step 2: Select Questions</h5>
    <div id="questionList">
        <p style="color: #94a3b8; text-align: center; padding: 20px;">
            Select a class, subject, and topic above to load questions.
        </p>
    </div>
    <div id="selectedCount" style="display: none; margin-top: 12px; font-weight: 600; color: #7c3aed;">
        Selected: <span id="selectedCountNum">0</span> questions
    </div>
</div>

<!-- Step 3: Generate Exam -->
<div style="background: #ffffff; border-radius: 16px; padding: 24px; border: 1px solid #f1f5f9;">
    <h5 style="font-weight: 600; color: #0f172a; margin-bottom: 16px;">🚀 Step 3: Generate Exam</h5>
    <form method="POST" action="">
        <?php echo csrfField(); ?>
        <input type="hidden" name="topic_id" id="topicId" value="">
        <input type="hidden" name="class_id" id="classId" value="">
        <input type="hidden" name="subject_id" id="subjectId" value="">
        <input type="hidden" name="selected_questions" id="selectedQuestions" value="">

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Exam Title</label>
                    <input type="text" name="exam_title" class="form-control" placeholder="e.g., JSS 1 Mathematics Exam" value="<?php echo date('Y') . ' Exam'; ?>" style="border-radius: 10px; padding: 10px 14px; border-color: #e2e8f0;">
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Duration (minutes)</label>
                    <input type="number" name="duration" class="form-control" value="60" min="15" max="180" style="border-radius: 10px; padding: 10px 14px; border-color: #e2e8f0;">
                </div>
            </div>
        </div>

        <button type="submit" name="generate_exam" value="1" class="btn btn-primary" style="background: linear-gradient(135deg, #7c3aed, #6d28d9); border: none; border-radius: 10px; padding: 12px 40px; font-weight: 600;">
            <i class="fas fa-print"></i> Generate Exam
        </button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const filterClass = document.getElementById('filterClass');
    const filterSubject = document.getElementById('filterSubject');
    const filterTopic = document.getElementById('filterTopic');
    const questionList = document.getElementById('questionList');
    const selectedCountDiv = document.getElementById('selectedCount');
    const selectedCountNum = document.getElementById('selectedCountNum');
    const topicIdInput = document.getElementById('topicId');
    const classIdInput = document.getElementById('classId');
    const subjectIdInput = document.getElementById('subjectId');
    const selectedQuestionsInput = document.getElementById('selectedQuestions');
    let selectedQuestions = [];

    function loadTopics() {
        const class_id = filterClass.value;
        const subject_id = filterSubject.value;
        if (class_id && subject_id) {
            fetch('?ajax=get_topics&class_id=' + class_id + '&subject_id=' + subject_id)
                .then(res => res.json())
                .then(data => {
                    filterTopic.innerHTML = '<option value="">Select Topic</option>';
                    data.forEach(topic => {
                        filterTopic.innerHTML += '<option value="' + topic.id + '">' + topic.topic + '</option>';
                    });
                    filterTopic.disabled = false;
                });
        } else {
            filterTopic.innerHTML = '<option value="">Select Topic</option>';
            filterTopic.disabled = true;
        }
        clearQuestions();
    }

    function loadQuestions() {
        const topic_id = filterTopic.value;
        if (topic_id) {
            fetch('?ajax=get_questions&topic_id=' + topic_id + '&limit=50')
                .then(res => res.json())
                .then(data => {
                    selectedQuestions = [];
                    updateSelectedCount();
                    if (data.length === 0) {
                        questionList.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 20px;">No questions found for this topic.</p>';
                        return;
                    }
                    let html = '<div style="max-height: 400px; overflow-y: auto;">';
                    data.forEach((q, index) => {
                        html += `
                            <div style="display: flex; align-items: center; padding: 10px 12px; border-bottom: 1px solid #f1f5f9;">
                                <input type="checkbox" id="q_${q.id}" value="${q.id}" style="margin-right: 12px; width: 18px; height: 18px;">
                                <label for="q_${q.id}" style="flex: 1; margin: 0; cursor: pointer;">
                                    <span style="font-weight: 500;">${index + 1}.</span> ${q.question_text}
                                    <span style="font-size: 12px; color: #94a3b8; margin-left: 8px;">
                                        [${q.difficulty || 'medium'} | ${q.marks || 5} marks]
                                    </span>
                                </label>
                            </div>
                        `;
                    });
                    html += '</div>';
                    html += '<div style="margin-top: 12px;"><button id="selectAllBtn" class="btn btn-sm btn-outline-primary">Select All</button> <button id="deselectAllBtn" class="btn btn-sm btn-outline-secondary">Deselect All</button></div>';
                    questionList.innerHTML = html;

                    document.querySelectorAll('#questionList input[type="checkbox"]').forEach(cb => {
                        cb.addEventListener('change', function() {
                            if (this.checked) {
                                if (!selectedQuestions.includes(this.value)) selectedQuestions.push(this.value);
                            } else {
                                selectedQuestions = selectedQuestions.filter(v => v !== this.value);
                            }
                            updateSelectedCount();
                        });
                    });

                    document.getElementById('selectAllBtn').addEventListener('click', function() {
                        document.querySelectorAll('#questionList input[type="checkbox"]').forEach(cb => {
                            cb.checked = true;
                            if (!selectedQuestions.includes(cb.value)) selectedQuestions.push(cb.value);
                        });
                        updateSelectedCount();
                    });

                    document.getElementById('deselectAllBtn').addEventListener('click', function() {
                        document.querySelectorAll('#questionList input[type="checkbox"]').forEach(cb => cb.checked = false);
                        selectedQuestions = [];
                        updateSelectedCount();
                    });
                });
        } else {
            clearQuestions();
        }
    }

    function clearQuestions() {
        questionList.innerHTML = '<p style="color: #94a3b8; text-align: center; padding: 20px;">Select a topic to load questions.</p>';
        selectedQuestions = [];
        updateSelectedCount();
    }

    function updateSelectedCount() {
        if (selectedQuestions.length > 0) {
            selectedCountDiv.style.display = 'block';
            selectedCountNum.textContent = selectedQuestions.length;
            selectedQuestionsInput.value = selectedQuestions.join(',');
            topicIdInput.value = filterTopic.value;
            classIdInput.value = filterClass.value;
            subjectIdInput.value = filterSubject.value;
        } else {
            selectedCountDiv.style.display = 'none';
            selectedQuestionsInput.value = '';
        }
    }

    filterClass.addEventListener('change', function() {
        filterSubject.value = '';
        filterTopic.innerHTML = '<option value="">Select Topic</option>';
        filterTopic.disabled = true;
        clearQuestions();
    });

    filterSubject.addEventListener('change', function() {
        if (this.value && filterClass.value) {
            loadTopics();
        } else {
            filterTopic.innerHTML = '<option value="">Select Topic</option>';
            filterTopic.disabled = true;
            clearQuestions();
        }
    });

    filterTopic.addEventListener('change', function() {
        if (this.value) {
            loadQuestions();
        } else {
            clearQuestions();
        }
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>