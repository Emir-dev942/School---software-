<?php
// teacher/add_multiple_questions.php - Add multiple questions in one session
// Uses per-question type selector (MCQ / Theory / True-False / Fill-blank)
// Supports per-question image upload
// No difficulty field (removed for simplicity)

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
    echo '<div class="alert alert-warning" style="margin:24px;">
            <i class="fas fa-lock"></i> You do not have permission to add questions.
            <br><a href="request_permission.php" class="btn btn-primary mt-2">Request Access</a>
          </div>';
    include_once __DIR__ . '/../includes/footer.php';
    exit;
}

$message = '';
$error = '';
$saved_count = 0;
$duplicate_count = 0;
$failed_questions = [];

// Fetch dropdown data
$subjects = $db->prepare("SELECT id, name FROM subjects WHERE school_id = ? AND status='active' ORDER BY name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY order_number, name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

$valid_types = ['mcq', 'theory', 'true_false', 'fill_blank'];

// ---------- HANDLE SUBMIT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all'])) {
    $class_id   = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $topic_name = trim($_POST['topic'] ?? '');
    $term       = trim($_POST['term'] ?? 'Term 1');

    $types        = $_POST['q_type'] ?? [];
    $texts        = $_POST['q_text'] ?? [];
    $marks_arr    = $_POST['q_marks'] ?? [];
    $opt_a_arr    = $_POST['q_option_a'] ?? [];
    $opt_b_arr    = $_POST['q_option_b'] ?? [];
    $opt_c_arr    = $_POST['q_option_c'] ?? [];
    $opt_d_arr    = $_POST['q_option_d'] ?? [];
    $correct_arr  = $_POST['q_correct'] ?? [];
    $expected_arr = $_POST['q_expected'] ?? [];
    $explain_arr  = $_POST['q_explanation'] ?? [];

    if ($class_id <= 0 || $subject_id <= 0 || $topic_name === '') {
        $error = "Please select class, subject, and enter a topic.";
    } elseif (empty($texts)) {
        $error = "Please add at least one question.";
    } else {
        try {
            $db->beginTransaction();

            // Get or create topic
            $topicStmt = $db->prepare("SELECT id FROM scheme_topics 
                                       WHERE school_id = ? AND class_id = ? AND subject_id = ? 
                                         AND term = ? AND week_number = 0 AND LOWER(TRIM(topic)) = LOWER(TRIM(?))
                                       LIMIT 1");
            $topicStmt->execute([$school_id, $class_id, $subject_id, $term, $topic_name]);
            $topic_id = $topicStmt->fetchColumn();

            if (!$topic_id) {
                $insertTopic = $db->prepare("INSERT INTO scheme_topics 
                    (school_id, class_id, subject_id, term, week_number, topic, created_at) 
                    VALUES (?, ?, ?, ?, 0, ?, NOW())");
                $insertTopic->execute([$school_id, $class_id, $subject_id, $term, $topic_name]);
                $topic_id = (int)$db->lastInsertId();
            }

            $dupeStmt = $db->prepare("SELECT id FROM exam_questions 
                                      WHERE school_id = ? AND class_id = ? AND subject_id = ? 
                                        AND LOWER(TRIM(question_text)) = LOWER(TRIM(?))
                                      LIMIT 1");

            $insertQ = $db->prepare("INSERT INTO exam_questions 
                (school_id, class_id, subject_id, topic_id, question_type, question_text, 
                 option_a, option_b, option_c, option_d, correct_answer, expected_answer, 
                 answer_explanation, marks, difficulty, question_image, created_by, created_at, 
                 is_active, is_verified, question_status, source)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'medium', ?, ?, NOW(), 1, 1, 'submitted', 'manual')");

            $insertA = $db->prepare("INSERT INTO exam_answers 
                (question_id, correct_answer, answer_explanation, answer_notes, created_at) 
                VALUES (?, ?, ?, '', NOW())");

            // Handle image uploads
            $upload_dir = __DIR__ . '/../uploads/question_images/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

            $saved_count = 0;
            $duplicate_count = 0;
            $failed_questions = [];

            for ($i = 0; $i < count($texts); $i++) {
                $q_type = $types[$i] ?? 'mcq';
                if (!in_array($q_type, $valid_types, true)) $q_type = 'mcq';

                $q_text = trim($texts[$i] ?? '');
                if ($q_text === '') continue;

                $q_marks = (int)($marks_arr[$i] ?? 5);
                if ($q_marks <= 0) $q_marks = 5;

                $opt_a = trim($opt_a_arr[$i] ?? '');
                $opt_b = trim($opt_b_arr[$i] ?? '');
                $opt_c = trim($opt_c_arr[$i] ?? '');
                $opt_d = trim($opt_d_arr[$i] ?? '');
                $correct = trim($correct_arr[$i] ?? '');
                $expected = trim($expected_arr[$i] ?? '');
                $explanation = trim($explain_arr[$i] ?? '');

                // Duplicate check
                $dupeStmt->execute([$school_id, $class_id, $subject_id, $q_text]);
                if ($dupeStmt->fetchColumn()) {
                    $duplicate_count++;
                    $failed_questions[] = "Skipped duplicate: " . substr($q_text, 0, 60) . "...";
                    continue;
                }

                // Handle image upload for this question
                $question_image = '';
                if (isset($_FILES['q_image']) && isset($_FILES['q_image']['name'][$i]) && $_FILES['q_image']['error'][$i] === UPLOAD_ERR_OK) {
                    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    $file_type = $_FILES['q_image']['type'][$i];
                    $file_size = $_FILES['q_image']['size'][$i];

                    if (in_array($file_type, $allowed) && $file_size <= 2 * 1024 * 1024) {
                        $ext = pathinfo($_FILES['q_image']['name'][$i], PATHINFO_EXTENSION);
                        $filename = 'q_' . time() . '_' . $i . '_' . rand(100, 999) . '.' . $ext;
                        if (move_uploaded_file($_FILES['q_image']['tmp_name'][$i], $upload_dir . $filename)) {
                            $question_image = 'uploads/question_images/' . $filename;
                        }
                    }
                }

                // Sanity checks per type
                $valid = true;
                if ($q_type === 'mcq') {
                    if ($opt_a === '' || $opt_b === '' || !in_array(strtoupper($correct), ['A','B','C','D'], true)) {
                        $valid = false;
                        $failed_questions[] = "MCQ missing options/answer: " . substr($q_text, 0, 60) . "...";
                    }
                } elseif ($q_type === 'true_false') {
                    if (!in_array($correct, ['True','False'], true)) {
                        $valid = false;
                        $failed_questions[] = "True/False missing answer: " . substr($q_text, 0, 60) . "...";
                    }
                } elseif ($q_type === 'fill_blank') {
                    if ($correct === '') {
                        $valid = false;
                        $failed_questions[] = "Fill-blank missing answer: " . substr($q_text, 0, 60) . "...";
                    }
                }

                if (!$valid) continue;

                try {
                    $insertQ->execute([
                        $school_id, $class_id, $subject_id, $topic_id, $q_type, $q_text,
                        $opt_a, $opt_b, $opt_c, $opt_d,
                        $q_type === 'mcq' ? strtoupper($correct) : $correct,
                        $expected, $explanation, $q_marks, $question_image, $teacher_id
                    ]);
                    $qid = (int)$db->lastInsertId();
                    $saved_count++;

                    if (!empty($correct) || !empty($explanation)) {
                        $insertA->execute([$qid, $correct, $explanation]);
                    }
                } catch (Exception $e) {
                    $failed_questions[] = "Error saving: " . substr($q_text, 0, 60) . "... (" . $e->getMessage() . ")";
                }
            }

            $db->commit();
            logActivity('BULK_ADD_QUESTIONS',
                "Added $saved_count questions, $duplicate_count duplicates skipped (topic: $topic_name, class: $class_id, subject: $subject_id)",
                $school_id, $teacher_id);

            if ($saved_count > 0) {
                $message = "✅ Saved $saved_count question" . ($saved_count > 1 ? 's' : '') . ".";
                if ($duplicate_count > 0) {
                    $message .= " Skipped $duplicate_count duplicate" . ($duplicate_count > 1 ? 's' : '') . ".";
                }
            } elseif ($duplicate_count > 0) {
                $error = "All questions were duplicates. Nothing saved.";
            } else {
                $error = "No valid questions to save.";
            }

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Failed to save: " . $e->getMessage();
        }
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<style>
    .question-block {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 16px;
        position: relative;
    }
    .question-block .q-number {
        display: inline-block;
        background: #7c3aed;
        color: white;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        text-align: center;
        line-height: 32px;
        font-weight: 700;
        font-size: 0.9rem;
        margin-bottom: 12px;
    }
    .question-block .remove-btn {
        position: absolute;
        top: 16px;
        right: 16px;
        background: #fee2e2;
        color: #dc2626;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
        display: none; /* shown only for blocks > 1 */
    }
    .question-block .remove-btn:hover {
        background: #fecaca;
    }
    .options-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 10px;
    }
    .form-label-sm {
        font-size: 0.75rem;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
        display: block;
    }
    .add-btn {
        background: #eef2ff;
        color: #7c3aed;
        border: 2px dashed #c7d2fe;
        padding: 16px;
        border-radius: 16px;
        width: 100%;
        font-weight: 600;
        cursor: pointer;
        font-size: 1rem;
        transition: 0.2s;
    }
    .add-btn:hover {
        background: #e0e7ff;
        border-color: #a5b4fc;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">✍️ Add Multiple Questions</h1>
        <p style="color:#64748b;">Add many questions in one sitting. Mix MCQ, Theory, True/False, and Fill-blank.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <a href="question_bank.php" class="btn btn-outline-secondary" style="border-radius:10px;">
            <i class="fas fa-arrow-left"></i> Back to Bank
        </a>
    </div>
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

<?php if (!empty($failed_questions)): ?>
    <div class="alert alert-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:16px;margin-bottom:20px;">
        <strong><i class="fas fa-exclamation-triangle"></i> Skipped / Failed (<?php echo count($failed_questions); ?>)</strong>
        <ul style="margin:10px 0 0 20px; font-size:13px;">
            <?php foreach ($failed_questions as $f): ?>
                <li><?php echo htmlspecialchars($f); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="questionsForm">
    <?php echo csrfField(); ?>

    <!-- Header: Where do questions go -->
    <div class="card mb-4" style="border-radius:16px;">
        <div class="card-body">
            <h5 style="font-weight:600; margin-bottom:16px;">📚 Where do these questions go?</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Class *</label>
                    <select name="class_id" class="form-control" required>
                        <option value="">Choose...</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Subject *</label>
                    <select name="subject_id" class="form-control" required>
                        <option value="">Choose...</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Topic *</label>
                    <input type="text" name="topic" class="form-control" required placeholder="e.g., Fractions">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Term</label>
                    <select name="term" class="form-control">
                        <option value="Term 1">Term 1</option>
                        <option value="Term 2">Term 2</option>
                        <option value="Term 3">Term 3</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Questions Container -->
    <div id="questionsContainer"></div>

    <!-- Add Button -->
    <button type="button" class="add-btn" id="addQuestionBtn" style="margin-bottom:20px;">
        <i class="fas fa-plus-circle"></i> Add Another Question
    </button>

    <!-- Save Button -->
    <div style="text-align:right; margin-bottom:40px;">
        <button type="submit" name="save_all" value="1" class="btn btn-primary"
                style="padding:14px 48px;border-radius:10px;font-weight:600;font-size:1rem;background:linear-gradient(135deg,#7c3aed,#6d28d9);border:none;"
                onclick="return confirmSave();">
            <i class="fas fa-save"></i> Save All Questions
        </button>
    </div>
</form>

<script>
let questionCount = 0;

// Template for a question block
function questionBlockHtml(n) {
    return `
        <div class="question-block" data-qid="${n}">
            <button type="button" class="remove-btn" onclick="removeQuestion(${n})" title="Remove this question">
                <i class="fas fa-times"></i>
            </button>
            <div class="q-number">${n}</div>

            <div class="row g-3 mb-2">
                <div class="col-md-4">
                    <label class="form-label-sm">Question Type</label>
                    <select name="q_type[]" class="form-control" onchange="toggleFields(${n})">
                        <option value="mcq">Multiple Choice</option>
                        <option value="theory">Theory / Essay</option>
                        <option value="true_false">True / False</option>
                        <option value="fill_blank">Fill in the Blank</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label-sm">Marks</label>
                    <input type="number" name="q_marks[]" class="form-control" value="5" min="1" max="100">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label-sm">Question Text *</label>
                <textarea name="q_text[]" class="form-control" rows="2" required placeholder="Type the question here..."></textarea>
            </div>

            <div class="mb-2">
                <label class="form-label-sm">Question Image (optional)</label>
                <input type="file" name="q_image[]" class="form-control" accept="image/*">
                <small class="text-muted" style="font-size:11px;">Max 2MB. JPG, PNG, GIF, WEBP.</small>
            </div>

            <!-- MCQ fields -->
            <div class="mcq-fields" data-qid="${n}">
                <div class="options-row">
                    <div>
                        <label class="form-label-sm">Option A</label>
                        <input type="text" name="q_option_a[]" class="form-control">
                    </div>
                    <div>
                        <label class="form-label-sm">Option B</label>
                        <input type="text" name="q_option_b[]" class="form-control">
                    </div>
                    <div>
                        <label class="form-label-sm">Option C</label>
                        <input type="text" name="q_option_c[]" class="form-control">
                    </div>
                    <div>
                        <label class="form-label-sm">Option D</label>
                        <input type="text" name="q_option_d[]" class="form-control">
                    </div>
                </div>
                <div class="mt-2" style="max-width:200px;">
                    <label class="form-label-sm">Correct Answer</label>
                    <select name="q_correct[]" class="form-control">
                        <option value="">Choose...</option>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                        <option value="D">D</option>
                    </select>
                </div>
            </div>

            <!-- True/False fields -->
            <div class="tf-fields" data-qid="${n}" style="display:none;">
                <div style="max-width:200px;">
                    <label class="form-label-sm">Correct Answer</label>
                    <select name="q_correct[]" class="form-control">
                        <option value="">Choose...</option>
                        <option value="True">True</option>
                        <option value="False">False</option>
                    </select>
                </div>
            </div>

            <!-- Fill-blank fields -->
            <div class="fill-fields" data-qid="${n}" style="display:none;">
                <div>
                    <label class="form-label-sm">Answer *</label>
                    <input type="text" name="q_correct[]" class="form-control" placeholder="The correct word/phrase">
                </div>
            </div>

            <!-- Theory fields -->
            <div class="theory-fields" data-qid="${n}" style="display:none;">
                <div>
                    <label class="form-label-sm">Expected Answer *</label>
                    <textarea name="q_expected[]" class="form-control" rows="2" placeholder="The expected model answer for grading"></textarea>
                </div>
            </div>

            <div class="mt-2">
                <label class="form-label-sm">Explanation (optional)</label>
                <textarea name="q_explanation[]" class="form-control" rows="1" placeholder="Optional note about this question"></textarea>
            </div>
        </div>
    `;
}

function addQuestion() {
    questionCount++;
    const container = document.getElementById('questionsContainer');
    const div = document.createElement('div');
    div.innerHTML = questionBlockHtml(questionCount);
    container.appendChild(div.firstElementChild);
    updateRemoveButtons();
    // Scroll to the new question
    const newBlock = container.lastElementChild;
    newBlock.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function removeQuestion(n) {
    const block = document.querySelector(`[data-qid="${n}"].question-block`);
    if (block) {
        block.remove();
        updateRemoveButtons();
    }
}

function updateRemoveButtons() {
    const blocks = document.querySelectorAll('.question-block');
    blocks.forEach(block => {
        const btn = block.querySelector('.remove-btn');
        if (btn) {
            btn.style.display = blocks.length > 1 ? 'block' : 'none';
        }
    });
}

// Show/hide fields based on selected type
function toggleFields(n) {
    const block = document.querySelector(`[data-qid="${n}"].question-block`);
    if (!block) return;
    const type = block.querySelector('select[name="q_type[]"]').value;

    // Hide all type-specific field groups
    block.querySelectorAll('.mcq-fields, .tf-fields, .fill-fields, .theory-fields').forEach(el => {
        el.style.display = 'none';
    });

    // Show the matching group
    if (type === 'mcq') {
        block.querySelector('.mcq-fields').style.display = 'block';
    } else if (type === 'true_false') {
        block.querySelector('.tf-fields').style.display = 'block';
    } else if (type === 'fill_blank') {
        block.querySelector('.fill-fields').style.display = 'block';
    } else if (type === 'theory') {
        block.querySelector('.theory-fields').style.display = 'block';
    }
}

function confirmSave() {
    const count = document.querySelectorAll('.question-block').length;
    return confirm(`Save ${count} question${count > 1 ? 's' : ''}?`);
}

document.getElementById('addQuestionBtn').addEventListener('click', addQuestion);

// Start with one question
document.addEventListener('DOMContentLoaded', function() {
    addQuestion();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>