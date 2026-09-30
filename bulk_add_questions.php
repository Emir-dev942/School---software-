<?php
// teacher/bulk_add_questions.php - Bulk import questions for a single topic
// v3: robust topic + skip duplicate questions
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Teacher']);
requireCsrf();

$db = getDB();
$teacher_id = (int)$_SESSION['user_id'];
$school_id  = (int)$_SESSION['school_id'];

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
$imported_count = 0;
$duplicate_count = 0;
$failed_rows = [];

$subjects = $db->prepare("SELECT id, name FROM subjects WHERE school_id = ? AND status='active' ORDER BY name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY order_number, name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

function parseCsvLine(string $line): array {
    return array_map('trim', explode('|', $line));
}

function isBlankLine(string $line): bool {
    $t = trim($line);
    return $t === '' || $t[0] === '#';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_import'])) {
    $class_id      = (int)($_POST['class_id'] ?? 0);
    $subject_id    = (int)($_POST['subject_id'] ?? 0);
    $topic_name    = trim($_POST['topic'] ?? '');
    $term          = trim($_POST['term'] ?? 'Term 1');
    $question_type = trim($_POST['question_type'] ?? 'mcq');
    $marks         = (int)($_POST['marks'] ?? 5);
    $difficulty    = trim($_POST['difficulty'] ?? 'medium');

    $valid_types = ['mcq', 'theory', 'true_false', 'fill_blank'];
    if (!in_array($question_type, $valid_types, true)) $question_type = 'mcq';

    if ($class_id <= 0 || $subject_id <= 0 || $topic_name === '') {
        $error = "Class, Subject, and Topic are required.";
    } else {
        $raw = trim($_POST['questions_text'] ?? '');

        if (isset($_FILES['questions_file']) && $_FILES['questions_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['questions_file']['tmp_name'];
            if (is_uploaded_file($tmp)) {
                $raw = file_get_contents($tmp);
            }
        }

        if ($raw === '') {
            $error = "No questions provided. Paste text or upload a CSV file.";
        } else {
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);
            $lines = explode("\n", $raw);

            $rows = [];
            $line_number = 0;
            foreach ($lines as $line) {
                $line_number++;
                if (isBlankLine($line)) continue;
                $parts = parseCsvLine($line);
                if (count($parts) === 0 || $parts[0] === '') continue;
                $rows[] = ['line' => $line_number, 'parts' => $parts];
            }

            if (empty($rows)) {
                $error = "No valid lines found.";
            } else {
                $validated = [];
                foreach ($rows as $row) {
                    $p = $row['parts'];
                    $ln = $row['line'];

                    $p = array_map(function($v){
                        $v = trim($v);
                        if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
                            $v = substr($v, 1, -1);
                            $v = str_replace('""', '"', $v);
                        }
                        return $v;
                    }, $p);

                    if ($question_type === 'mcq') {
                        if (count($p) < 6) {
                            $failed_rows[] = "Line $ln: needs at least 6 fields (Q|A|B|C|D|Correct)";
                            continue;
                        }
                        $correct = strtoupper($p[5]);
                        if (!in_array($correct, ['A','B','C','D'], true)) {
                            $failed_rows[] = "Line $ln: correct answer must be A, B, C, or D (got '$correct')";
                            continue;
                        }
                        $validated[] = [
                            'question_text' => $p[0],
                            'option_a' => $p[1],
                            'option_b' => $p[2],
                            'option_c' => $p[3],
                            'option_d' => $p[4],
                            'correct_answer' => $correct,
                            'expected_answer' => '',
                            'answer_explanation' => $p[6] ?? '',
                        ];
                    } elseif ($question_type === 'theory') {
                        if (count($p) < 2) {
                            $failed_rows[] = "Line $ln: needs at least 2 fields (Q|Expected answer)";
                            continue;
                        }
                        $validated[] = [
                            'question_text' => $p[0],
                            'option_a' => '',
                            'option_b' => '',
                            'option_c' => '',
                            'option_d' => '',
                            'correct_answer' => '',
                            'expected_answer' => $p[1],
                            'answer_explanation' => $p[2] ?? '',
                        ];
                    } elseif ($question_type === 'true_false') {
                        if (count($p) < 2) {
                            $failed_rows[] = "Line $ln: needs 2 fields (Q|True or False)";
                            continue;
                        }
                        $ans = ucfirst(strtolower($p[1]));
                        if (!in_array($ans, ['True','False'], true)) {
                            $failed_rows[] = "Line $ln: answer must be 'True' or 'False' (got '" . $p[1] . "')";
                            continue;
                        }
                        $validated[] = [
                            'question_text' => $p[0],
                            'option_a' => 'True',
                            'option_b' => 'False',
                            'option_c' => '',
                            'option_d' => '',
                            'correct_answer' => $ans,
                            'expected_answer' => '',
                            'answer_explanation' => $p[2] ?? '',
                        ];
                    } elseif ($question_type === 'fill_blank') {
                        if (count($p) < 2) {
                            $failed_rows[] = "Line $ln: needs 2 fields (Q|Answer)";
                            continue;
                        }
                        $validated[] = [
                            'question_text' => $p[0],
                            'option_a' => '',
                            'option_b' => '',
                            'option_c' => '',
                            'option_d' => '',
                            'correct_answer' => $p[1],
                            'expected_answer' => $p[1],
                            'answer_explanation' => $p[2] ?? '',
                        ];
                    }
                }

                if (empty($validated)) {
                    $error = "All rows were invalid. See details below.";
                } else {
                    try {
                        $db->beginTransaction();

                        // Get or create topic (robust)
                        $topic_id = null;

                        $topicStmt = $db->prepare("SELECT id FROM scheme_topics 
                                                   WHERE school_id = ? AND class_id = ? AND subject_id = ? 
                                                     AND term = ? AND week_number = 0 AND topic = ?");
                        $topicStmt->execute([$school_id, $class_id, $subject_id, $term, $topic_name]);
                        $topic_id = $topicStmt->fetchColumn();

                        if (!$topic_id) {
                            $topicStmt2 = $db->prepare("SELECT id FROM scheme_topics 
                                                        WHERE school_id = ? AND class_id = ? AND subject_id = ? 
                                                          AND term = ? AND week_number = 0 
                                                          AND LOWER(TRIM(topic)) = LOWER(TRIM(?))");
                            $topicStmt2->execute([$school_id, $class_id, $subject_id, $term, $topic_name]);
                            $topic_id = $topicStmt2->fetchColumn();
                        }

                        if (!$topic_id) {
                            $insertTopic = $db->prepare("INSERT INTO scheme_topics 
                                (school_id, class_id, subject_id, term, week_number, topic, created_at) 
                                VALUES (?, ?, ?, ?, 0, ?, NOW())");
                            $insertTopic->execute([$school_id, $class_id, $subject_id, $term, $topic_name]);
                            $topic_id = (int)$db->lastInsertId();
                        }

                        // Duplicate check statement
                        $dupeStmt = $db->prepare("SELECT id FROM exam_questions 
                                                  WHERE school_id = ? AND class_id = ? AND subject_id = ? 
                                                    AND LOWER(TRIM(question_text)) = LOWER(TRIM(?))
                                                  LIMIT 1");

                        $insertQ = $db->prepare("INSERT INTO exam_questions 
                            (school_id, class_id, subject_id, topic_id, question_type, question_text, 
                             option_a, option_b, option_c, option_d, correct_answer, expected_answer, 
                             answer_explanation, marks, difficulty, created_by, created_at, 
                             is_active, is_verified, question_status, source)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), 1, 1, 'submitted', 'bulk')");

                        $insertA = $db->prepare("INSERT INTO exam_answers 
                            (question_id, correct_answer, answer_explanation, answer_notes, created_at) 
                            VALUES (?, ?, ?, '', NOW())");

                        foreach ($validated as $q) {
                            // ---- DUPLICATE CHECK ----
                            $dupeStmt->execute([$school_id, $class_id, $subject_id, $q['question_text']]);
                            if ($dupeStmt->fetchColumn()) {
                                $duplicate_count++;
                                continue; // skip
                            }

                            $insertQ->execute([
                                $school_id, $class_id, $subject_id, $topic_id, $question_type,
                                $q['question_text'],
                                $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'],
                                $q['correct_answer'], $q['expected_answer'], $q['answer_explanation'],
                                $marks, $difficulty, $teacher_id
                            ]);
                            $qid = (int)$db->lastInsertId();
                            $imported_count++;

                            if (!empty($q['correct_answer']) || !empty($q['answer_explanation'])) {
                                $insertA->execute([$qid, $q['correct_answer'], $q['answer_explanation']]);
                            }
                        }

                        $db->commit();
                        logActivity('BULK_ADD_QUESTIONS',
                            "Bulk: $imported_count imported, $duplicate_count duplicates skipped (topic: $topic_name)",
                            $school_id, $teacher_id);

                        if ($imported_count > 0) {
                            $message = "✅ Imported $imported_count question" . ($imported_count > 1 ? 's' : '');
                            if ($duplicate_count > 0) $message .= ", skipped $duplicate_count duplicate" . ($duplicate_count > 1 ? 's' : '');
                            $message .= ".";
                        } elseif ($duplicate_count > 0) {
                            $message = "⚠️ All $duplicate_count questions already exist. Nothing imported.";
                        }

                    } catch (Exception $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        $error = "Failed to import: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 style="font-weight:700; color:#0f172a;">📥 Bulk Add Questions</h1>
        <p class="text-muted">Add many questions to one topic at once — by pasting or uploading a CSV.</p>
    </div>
    <div>
        <a href="question_bank.php" class="btn btn-outline-secondary" style="border-radius:10px;">
            <i class="fas fa-arrow-left"></i> Question Bank
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

<?php if (!empty($failed_rows)): ?>
    <div class="alert alert-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:12px;padding:16px;margin-bottom:20px;">
        <strong><i class="fas fa-exclamation-triangle"></i> Skipped Rows (<?php echo count($failed_rows); ?>)</strong>
        <ul style="margin:10px 0 0 20px;">
            <?php foreach ($failed_rows as $fr): ?>
                <li><?php echo htmlspecialchars($fr); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
    <?php echo csrfField(); ?>

    <div class="card mb-3" style="border-radius:16px;">
        <div class="card-body">
            <h5 style="font-weight:600;margin-bottom:16px;">📚 Step 1 — Where do these questions go?</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Class *</label>
                    <select name="class_id" class="form-control" required>
                        <option value="">Choose...</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Subject *</label>
                    <select name="subject_id" class="form-control" required>
                        <option value="">Choose...</option>
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
                        <option>Term 1</option>
                        <option>Term 2</option>
                        <option>Term 3</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3" style="border-radius:16px;">
        <div class="card-body">
            <h5 style="font-weight:600;margin-bottom:16px;">⚙️ Step 2 — Question Settings</h5>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Question Type *</label>
                    <select name="question_type" id="question_type" class="form-control" required onchange="updateFormatHint()">
                        <option value="mcq">Multiple Choice (MCQ)</option>
                        <option value="theory">Theory / Essay</option>
                        <option value="true_false">True / False</option>
                        <option value="fill_blank">Fill in the Blank</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Marks per question</label>
                    <input type="number" name="marks" class="form-control" value="5" min="1" max="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Difficulty</label>
                    <select name="difficulty" class="form-control">
                        <option value="easy">Easy</option>
                        <option value="medium" selected>Medium</option>
                        <option value="hard">Hard</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3" style="border-radius:16px;">
        <div class="card-body">
            <h5 style="font-weight:600;margin-bottom:16px;">📋 Step 3 — Paste or Upload</h5>

            <div id="format-hint" class="alert alert-info" style="border-radius:10px;font-family:monospace;font-size:13px;"></div>

            <label class="form-label fw-semibold">Paste questions (one per line, fields separated by <code>|</code>)</label>
            <textarea name="questions_text" id="questions_text" class="form-control" rows="12"
                placeholder="Paste your questions here..." style="font-family:monospace;font-size:13px;"></textarea>

            <p class="text-muted mt-2 mb-0" style="font-size:12px;">
                <i class="fas fa-info-circle"></i>
                Blank lines and lines starting with <code>#</code> are ignored. Duplicates (same question text) are skipped.
            </p>

            <hr class="my-4">

            <label class="form-label fw-semibold">Or upload a CSV file (same format)</label>
            <input type="file" name="questions_file" accept=".csv,.txt" class="form-control">
            <small class="text-muted">If both are provided, the CSV file wins.</small>
        </div>
    </div>

    <div style="text-align:right;margin-bottom:40px;">
        <button type="submit" name="bulk_import" value="1"
                class="btn btn-primary"
                style="padding:12px 40px;border-radius:10px;font-weight:600;">
            <i class="fas fa-upload"></i> Import Questions
        </button>
    </div>
</form>

<script>
function updateFormatHint() {
    const type = document.getElementById('question_type').value;
    const el = document.getElementById('format-hint');
    let html = '';
    if (type === 'mcq') {
        html = '<strong>Format:</strong> Question | Option A | Option B | Option C | Option D | Correct (A/B/C/D) | Explanation (optional)<br><br>'
             + '<strong>Example:</strong><br>'
             + 'What is 1/2 + 1/4? | 3/4 | 1/2 | 2/4 | 1/6 | A | 1/2 = 2/4, so 2/4+1/4=3/4<br>'
             + 'What is 2/3 × 3/4? | 1/2 | 5/6 | 3/4 | 2/3 | A';
    } else if (type === 'theory') {
        html = '<strong>Format:</strong> Question | Expected Answer | Explanation (optional)<br><br>'
             + '<strong>Example:</strong><br>'
             + 'Explain the difference between a proper and improper fraction | A proper fraction has numerator smaller than denominator; improper is greater or equal | Proper &lt; 1, improper ≥ 1<br>'
             + 'Define photosynthesis | The process by which plants convert light into chemical energy';
    } else if (type === 'true_false') {
        html = '<strong>Format:</strong> Question | True or False | Explanation (optional)<br><br>'
             + '<strong>Example:</strong><br>'
             + 'The Earth is flat | False<br>'
             + 'Water boils at 100°C at sea level | True';
    } else if (type === 'fill_blank') {
        html = '<strong>Format:</strong> Question | Answer | Explanation (optional)<br><br>'
             + '<strong>Example:</strong><br>'
             + 'The capital of France is ___ | Paris<br>'
             + 'H₂O is the chemical formula for ___ | Water';
    }
    el.innerHTML = html;
}
document.addEventListener('DOMContentLoaded', updateFormatHint);
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>