<?php
// teacher/add_question.php - Add single question manually (v2: duplicate check)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Teacher']);
requireCsrf();

$db = getDB();
$teacher_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['school_id'];

if (!hasPermission(PERM_ADD_QUESTIONS)) {
    include_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning">You do not have permission to add questions.</div>';
    include_once __DIR__ . '/../includes/footer.php';
    exit;
}

$message = '';
$error = '';

$subjects = $db->prepare("SELECT id, name FROM subjects WHERE school_id = ? AND status='active' ORDER BY name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $topic_name = trim($_POST['topic'] ?? '');
    $question_type = trim($_POST['question_type'] ?? 'mcq');
    $question_text = trim($_POST['question_text'] ?? '');
    $option_a = trim($_POST['option_a'] ?? '');
    $option_b = trim($_POST['option_b'] ?? '');
    $option_c = trim($_POST['option_c'] ?? '');
    $option_d = trim($_POST['option_d'] ?? '');
    $correct_answer = trim($_POST['correct_answer'] ?? '');
    $expected_answer = trim($_POST['expected_answer'] ?? '');
    $answer_explanation = trim($_POST['answer_explanation'] ?? '');
    $marks = (int)($_POST['marks'] ?? 5);
    $difficulty = trim($_POST['difficulty'] ?? 'medium');
    $term = trim($_POST['term'] ?? 'Term 1');

    if ($class_id <= 0 || $subject_id <= 0 || empty($topic_name) || empty($question_text)) {
        $error = "Class, subject, topic, and question text are required.";
    } elseif (!in_array($question_type, ['mcq', 'theory', 'fill_blank', 'true_false'])) {
        $error = "Invalid question type.";
    } else {
        // ---- DUPLICATE CHECK ----
        $dupeStmt = $db->prepare("SELECT id FROM exam_questions 
                                  WHERE school_id = ? AND class_id = ? AND subject_id = ? 
                                    AND LOWER(TRIM(question_text)) = LOWER(TRIM(?))
                                  LIMIT 1");
        $dupeStmt->execute([$school_id, $class_id, $subject_id, $question_text]);
        if ($dupeStmt->fetchColumn()) {
            $error = "⚠️ A question with this exact text already exists for this class and subject. It was not added.";
        } else {
            $image_path = '';
            if (isset($_FILES['question_image']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $file_type = $_FILES['question_image']['type'];
                $file_size = $_FILES['question_image']['size'];
                $file_tmp = $_FILES['question_image']['tmp_name'];
                if (!in_array($file_type, $allowed)) {
                    $error = "Only JPG, PNG, GIF, WEBP images allowed.";
                } elseif ($file_size > 2 * 1024 * 1024) {
                    $error = "Image must be less than 2MB.";
                } else {
                    $ext = pathinfo($_FILES['question_image']['name'], PATHINFO_EXTENSION);
                    $filename = 'q_' . time() . '_' . rand(100,999) . '.' . $ext;
                    $upload_dir = __DIR__ . '/../uploads/question_images/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                    if (move_uploaded_file($file_tmp, $upload_dir . $filename)) {
                        $image_path = 'uploads/question_images/' . $filename;
                    } else {
                        $error = "Failed to upload image.";
                    }
                }
            }

            if (empty($error)) {
                try {
                    $db->beginTransaction();

                    $topicStmt = $db->prepare("SELECT id FROM scheme_topics WHERE school_id = ? AND class_id = ? AND subject_id = ? AND topic = ? AND term = ?");
                    $topicStmt->execute([$school_id, $class_id, $subject_id, $topic_name, $term]);
                    $topic_id = $topicStmt->fetchColumn();
                    if (!$topic_id) {
                        $insertTopic = $db->prepare("INSERT INTO scheme_topics (school_id, class_id, subject_id, term, week_number, topic, created_at) VALUES (?, ?, ?, ?, 0, ?, NOW())");
                        $insertTopic->execute([$school_id, $class_id, $subject_id, $term, $topic_name]);
                        $topic_id = (int)$db->lastInsertId();
                    }

                    $stmt = $db->prepare("INSERT INTO exam_questions 
                        (school_id, class_id, subject_id, topic_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_answer, expected_answer, answer_explanation, marks, difficulty, question_image, created_by, created_at, is_active, is_verified)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, 1)");
                    $stmt->execute([
                        $school_id, $class_id, $subject_id, $topic_id, $question_type, $question_text,
                        $option_a, $option_b, $option_c, $option_d, $correct_answer, $expected_answer, $answer_explanation,
                        $marks, $difficulty, $image_path, $teacher_id
                    ]);
                    $question_id = (int)$db->lastInsertId();

                    if (!empty($correct_answer) || !empty($answer_explanation)) {
                        $ansStmt = $db->prepare("INSERT INTO exam_answers (question_id, correct_answer, answer_explanation, answer_notes, created_at) VALUES (?, ?, ?, '', NOW())");
                        $ansStmt->execute([$question_id, $correct_answer, $answer_explanation]);
                    }

                    $db->commit();
                    logActivity('ADD_QUESTION', "Added question ID: $question_id for topic: $topic_name", $school_id, $teacher_id);
                    $message = "✅ Question added successfully!";
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = "Failed to add question: " . $e->getMessage();
                }
            }
        }
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700;">➕ Add Question</h1>
        <p style="color:#64748b;">Add a new question to the question bank.</p>
    </div>
    <a href="question_bank.php" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left"></i> Back to Question Bank
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

<form method="POST" enctype="multipart/form-data">
    <?php echo csrfField(); ?>
    <div class="card mb-4" style="border-radius:16px;">
        <div class="card-body">
            <h5 style="font-weight:600;margin-bottom:16px;">📚 Question Placement</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Class *</label>
                    <select name="class_id" class="form-control" required>
                        <option value="">Choose class...</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Subject *</label>
                    <select name="subject_id" class="form-control" required>
                        <option value="">Choose subject...</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Topic *</label>
                    <input type="text" name="topic" class="form-control" required placeholder="e.g., Fractions">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Term</label>
                    <select name="term" class="form-control">
                        <option value="Term 1">Term 1</option>
                        <option value="Term 2">Term 2</option>
                        <option value="Term 3">Term 3</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" style="border-radius:16px;">
        <div class="card-body">
            <h5 style="font-weight:600;margin-bottom:16px;">❓ Question</h5>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Question Type *</label>
                    <select name="question_type" id="question_type" class="form-control" required onchange="updateFormFields()">
                        <option value="mcq">Multiple Choice (MCQ)</option>
                        <option value="true_false">True / False</option>
                        <option value="fill_blank">Fill in the Blank</option>
                        <option value="theory">Theory / Essay</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Marks</label>
                    <input type="number" name="marks" class="form-control" value="5" min="1" max="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Difficulty</label>
                    <select name="difficulty" class="form-control">
                        <option value="easy">Easy</option>
                        <option value="medium" selected>Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Question Text *</label>
                    <textarea name="question_text" class="form-control" rows="3" required></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Question Image (optional)</label>
                    <input type="file" name="question_image" class="form-control" accept="image/*">
                    <small class="text-muted">Max 2MB. JPG, PNG, GIF, WEBP.</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" style="border-radius:16px;" id="mcq_section">
        <div class="card-body">
            <h5 style="font-weight:600;margin-bottom:16px;">🔘 Options (for MCQ)</h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Option A</label>
                    <input type="text" name="option_a" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Option B</label>
                    <input type="text" name="option_b" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Option C</label>
                    <input type="text" name="option_c" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Option D</label>
                    <input type="text" name="option_d" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Correct Answer</label>
                    <select name="correct_answer" class="form-control">
                        <option value="">Choose...</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4" style="border-radius:16px;" id="theory_section">
        <div class="card-body">
            <h5 style="font-weight:600;margin-bottom:16px;">📝 Expected Answer / Explanation</h5>
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Expected Answer (for theory / fill-blank)</label>
                    <textarea name="expected_answer" class="form-control" rows="3" placeholder="The expected answer for grading reference"></textarea>
                </div>
                <div class="col-md-12">
                    <label class="form-label">Answer Explanation (optional)</label>
                    <textarea name="answer_explanation" class="form-control" rows="2" placeholder="Why is this the correct answer?"></textarea>
                </div>
            </div>
        </div>
    </div>

    <div style="text-align:right;margin-bottom:40px;">
        <button type="submit" name="add_question" value="1" class="btn btn-primary" style="padding:12px 40px;border-radius:10px;font-weight:600;">
            <i class="fas fa-save"></i> Save Question
        </button>
    </div>
</form>

<script>
function updateFormFields() {
    const type = document.getElementById('question_type').value;
    const mcqSection = document.getElementById('mcq_section');
    if (type === 'mcq') {
        mcqSection.style.display = 'block';
    } else {
        mcqSection.style.display = 'none';
    }
}
document.addEventListener('DOMContentLoaded', updateFormFields);
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>