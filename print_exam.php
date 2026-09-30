<?php
// teacher/print_exam.php - Exam Paper / Answer Key (v3: paper-saving layout)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Teacher', 'Principal', 'Owner']);
$db = getDB();
$user_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['school_id'];

if ($_SESSION['user_role'] === 'Teacher' && !hasPermission(PERM_GENERATE_EXAMS)) {
    die("You do not have permission.");
}

$exam_id = (int)($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? 'exam';
if ($exam_id <= 0) die("Invalid exam ID.");

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

$schoolStmt = $db->prepare("SELECT school_name, logo_path, address, phone, email, slogan FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();

$logo_path = (!empty($school['logo_path']) && $school['logo_path'] !== 'default_logo.png')
    ? BASE_URL . 'uploads/school_logos/' . $school['logo_path']
    : null;

function getQuestionOptions($q) {
    if (!empty($q['shuffled_options'])) {
        $data = json_decode($q['shuffled_options'], true);
        if (isset($data['options']) && is_array($data['options'])) {
            return $data['options'];
        }
    }
    return [
        'A' => $q['option_a'],
        'B' => $q['option_b'],
        'C' => $q['option_c'],
        'D' => $q['option_d'],
    ];
}

function getQuestionCorrectLetter($q) {
    if (!empty($q['shuffled_options'])) {
        $data = json_decode($q['shuffled_options'], true);
        if (isset($data['correct'])) return $data['correct'];
    }
    return $q['correct_answer'] ?? '—';
}

function getCorrectOptionText($q) {
    if ($q['question_type'] !== 'mcq') return null;
    $opts = getQuestionOptions($q);
    $letter = getQuestionCorrectLetter($q);
    return $opts[$letter] ?? '—';
}

// Split by type
$mcqs = [];
$theory = [];
$tf = [];
$fill = [];
foreach ($questions as $q) {
    switch ($q['question_type']) {
        case 'mcq': $mcqs[] = $q; break;
        case 'theory': $theory[] = $q; break;
        case 'true_false': $tf[] = $q; break;
        case 'fill_blank': $fill[] = $q; break;
    }
}

// Combined "Objective" = MCQ + True/False + Fill-blank
$objective = array_merge($mcqs, $tf, $fill);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($exam['exam_title']); ?><?php echo $mode === 'answer_key' ? ' — Answer Key' : ''; ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Times New Roman', Georgia, serif;
            background: #f0f0f0;
            color: #000;
            font-size: 10.5px;
            line-height: 1.28;
        }

        .print-area {
            max-width: 210mm;
            margin: 0 auto;
            background: #fff;
            padding: 8mm 10mm;
        }

        /* ---------- HEADER (compact) ---------- */
        .header {
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1.5px solid #000;
            padding-bottom: 4px;
            margin-bottom: 4px;
        }
        .header img { max-height: 42px; }
        .header .school-info { flex: 1; text-align: center; }
        .header .school-name { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; }
        .header .school-meta { font-size: 9px; color: #333; margin-top: 1px; }

        .exam-title {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            background: #eee;
            padding: 3px;
            border: 1px solid #000;
            margin-bottom: 4px;
            letter-spacing: 0.3px;
        }

        .meta-row {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 4px 12px;
            font-size: 9.5px;
            padding: 3px 0 4px;
            border-bottom: 0.5px solid #999;
            margin-bottom: 4px;
        }

        .instructions {
            font-size: 9.5px;
            padding: 3px 6px;
            background: #f9f9f9;
            border-left: 2px solid #666;
            margin-bottom: 6px;
        }

        /* ---------- SECTIONS ---------- */
        .section-head {
            font-size: 11px;
            font-weight: bold;
            background: #ddd;
            padding: 2px 6px;
            margin: 6px 0 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }

        /* ---------- MCQ: THREE COLUMNS ---------- */
        .mcq-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            column-gap: 8px;
            row-gap: 3px;
        }
        .q {
            page-break-inside: avoid;
            break-inside: avoid;
            margin-bottom: 2px;
        }
        .q-text {
            font-size: 10px;
            margin-bottom: 1px;
        }
        .q-text .qnum { font-weight: bold; }
        .q-marks {
            float: right;
            font-size: 8.5px;
            color: #555;
            font-style: italic;
        }
        .q-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            column-gap: 4px;
            font-size: 9.5px;
            padding-left: 12px;
        }
        .opt {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .opt-letter { font-weight: bold; }

        /* ---------- FULL-WIDTH (T/F, Fill, Theory) ---------- */
        .q-full {
            page-break-inside: avoid;
            break-inside: avoid;
            margin-bottom: 4px;
            padding-left: 4px;
        }
        .q-full .q-text { font-size: 10.5px; }

        /* True/False inline */
        .tf-inline {
            font-size: 9.5px;
            padding-left: 14px;
            margin-top: 1px;
        }

        /* Fill blank */
        .fill-inline {
            font-size: 10px;
            padding-left: 14px;
            margin-top: 1px;
        }

        /* ---------- ANSWER KEY ---------- */
        .ak-section {
            margin-bottom: 4px;
        }
        .ak-row {
            display: grid;
            grid-template-columns: 30px 1fr 40px;
            gap: 4px;
            padding: 1px 0;
            font-size: 9.5px;
            border-bottom: 0.5px dotted #ccc;
        }
        .ak-qnum { font-weight: bold; }
        .ak-answer {
            font-weight: bold;
            color: #0a5;
            text-align: center;
            background: #e8f5e9;
            padding: 0 2px;
            border-radius: 2px;
        }
        .ak-correct-text {
            grid-column: 2 / 4;
            font-size: 8.5px;
            color: #444;
            font-style: italic;
            padding-left: 34px;
        }

        /* ---------- FOOTER ---------- */
        .footer {
            text-align: center;
            font-size: 8px;
            color: #666;
            margin-top: 10px;
            padding-top: 3px;
            border-top: 0.5px solid #ccc;
        }

        /* ---------- TOOLBAR (screen only) ---------- */
        .toolbar {
            background: #222;
            color: #fff;
            padding: 8px;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 99;
        }
        .toolbar a, .toolbar button {
            display: inline-block;
            padding: 6px 16px;
            margin: 0 3px;
            font-size: 12px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }
        .toolbar .btn-primary { background: #4f46e5; color: #fff; }
        .toolbar .btn-success { background: #16a34a; color: #fff; }
        .toolbar .btn-outline { background: transparent; color: #fff; border: 1px solid #666; }

        /* ---------- PRINT ---------- */
        @page { size: A4; margin: 8mm 10mm; }

        @media print {
            body { background: #fff; font-size: 10px; }
            .print-area { box-shadow: none; padding: 0; max-width: 100%; margin: 0; }
            .toolbar { display: none !important; }
            .header img { max-height: 38px; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <button onclick="window.print()" class="btn-primary">🖨️ Print</button>
    <?php if ($mode === 'exam'): ?>
        <a href="print_exam.php?id=<?php echo $exam_id; ?>&mode=answer_key" class="btn-success">🔑 Answer Key</a>
    <?php else: ?>
        <a href="print_exam.php?id=<?php echo $exam_id; ?>&mode=exam" class="btn-outline">📄 Exam Paper</a>
    <?php endif; ?>
    <a href="view_exam.php?id=<?php echo $exam_id; ?>" class="btn-outline">← Back</a>
</div>

<div class="print-area">

    <!-- HEADER -->
    <div class="header">
        <?php if ($logo_path): ?>
            <img src="<?php echo $logo_path; ?>" alt="Logo">
        <?php endif; ?>
        <div class="school-info">
            <div class="school-name"><?php echo htmlspecialchars($school['school_name']); ?></div>
            <div class="school-meta">
                <?php echo htmlspecialchars($school['address'] ?? ''); ?>
                <?php if (!empty($school['phone'])) echo ' • ' . htmlspecialchars($school['phone']); ?>
                <?php if (!empty($school['email'])) echo ' • ' . htmlspecialchars($school['email']); ?>
            </div>
        </div>
    </div>

    <!-- TITLE -->
    <div class="exam-title">
        <?php echo htmlspecialchars($exam['exam_title']); ?>
        <?php if ($mode === 'answer_key'): ?> — ANSWER KEY<?php endif; ?>
    </div>

    <!-- META -->
    <div class="meta-row">
        <span><strong>Class:</strong> <?php echo htmlspecialchars($exam['class_name']); ?></span>
        <span><strong>Subject:</strong> <?php echo htmlspecialchars($exam['subject_name']); ?></span>
        <span><strong>Term:</strong> <?php echo htmlspecialchars($exam['term']); ?></span>
        <span><strong>Session:</strong> <?php echo htmlspecialchars($exam['session_year']); ?></span>
        <span><strong>Time:</strong> <?php echo $exam['duration_minutes']; ?> min</span>
        <span><strong>Total:</strong> <?php echo $exam['total_marks']; ?> marks</span>
        <span><strong>Code:</strong> <?php echo htmlspecialchars($exam['exam_code']); ?></span>
    </div>

    <!-- INSTRUCTIONS -->
    <?php if (!empty($exam['instructions'])): ?>
        <div class="instructions">
            <strong>Instructions:</strong> <?php echo nl2br(htmlspecialchars($exam['instructions'])); ?>
        </div>
    <?php endif; ?>

    <?php if ($mode === 'answer_key'): ?>

        <!-- ============ ANSWER KEY ============ -->

        <?php if (!empty($objective)): ?>
            <div class="section-head">Section A — Objective (Answers)</div>
            <div class="ak-section">
                <?php foreach ($objective as $q): ?>
                    <div class="ak-row">
                        <div class="ak-qnum">Q<?php echo $q['question_number']; ?>.</div>
                        <div style="font-size:9px;"><?php echo htmlspecialchars(substr($q['question_text'], 0, 70)) . (strlen($q['question_text']) > 70 ? '...' : ''); ?></div>
                        <div class="ak-answer"><?php echo htmlspecialchars(getQuestionCorrectLetter($q)); ?></div>
                    </div>
                    <?php if ($q['question_type'] === 'mcq'): ?>
                        <div class="ak-correct-text">Answer: <?php echo htmlspecialchars(getCorrectOptionText($q)); ?></div>
                    <?php elseif ($q['question_type'] === 'fill_blank'): ?>
                        <div class="ak-correct-text">Answer: <?php echo htmlspecialchars($q['correct_answer'] ?? ''); ?></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($theory)): ?>
            <div class="section-head">Section B — Theory (Expected Answers)</div>
            <?php foreach ($theory as $q): ?>
                <div style="margin-bottom:6px; page-break-inside:avoid;">
                    <div class="ak-row" style="border-bottom:none;">
                        <div class="ak-qnum">Q<?php echo $q['question_number']; ?>.</div>
                        <div style="grid-column: 2/4; font-weight:600; font-size:10px;"><?php echo htmlspecialchars($q['question_text']); ?></div>
                    </div>
                    <div style="padding-left:30px;font-size:9.5px;color:#333;">
                        <strong>Expected:</strong> <?php echo nl2br(htmlspecialchars($q['expected_answer'] ?? $q['correct_answer'] ?? '—')); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php else: ?>

        <!-- ============ EXAM PAPER ============ -->

        <?php if (!empty($objective)): ?>
            <div class="section-head">Section A — Objective (Choose the correct option)</div>
            <div class="mcq-grid">
                <?php foreach ($objective as $q): ?>
                    <?php if ($q['question_type'] === 'mcq'): 
                        $opts = getQuestionOptions($q);
                    ?>
                        <div class="q">
                            <div class="q-text">
                                <span class="qnum"><?php echo $q['question_number']; ?>.</span>
                                <?php echo htmlspecialchars($q['question_text']); ?>
                                <span class="q-marks">[<?php echo $q['marks']; ?>]</span>
                            </div>
                            <div class="q-options">
                                <?php foreach ($opts as $letter => $opt): ?>
                                    <div class="opt"><span class="opt-letter"><?php echo $letter; ?>.</span><?php echo htmlspecialchars($opt); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($tf)): ?>
                <div style="margin-top:6px;">
                    <?php foreach ($tf as $q): ?>
                        <div class="q-full">
                            <div class="q-text">
                                <span class="qnum"><?php echo $q['question_number']; ?>.</span>
                                <?php echo htmlspecialchars($q['question_text']); ?>
                                <span class="q-marks">[<?php echo $q['marks']; ?>]</span>
                            </div>
                            <div class="tf-inline">( ) True &nbsp;&nbsp; ( ) False</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($fill)): ?>
                <div style="margin-top:6px;">
                    <?php foreach ($fill as $q): ?>
                        <div class="q-full">
                            <div class="q-text">
                                <span class="qnum"><?php echo $q['question_number']; ?>.</span>
                                <?php echo htmlspecialchars($q['question_text']); ?>
                                <span class="q-marks">[<?php echo $q['marks']; ?>]</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($theory)): ?>
            <div class="section-head">Section B — Theory</div>
            <?php foreach ($theory as $q): ?>
                <div class="q-full">
                    <div class="q-text">
                        <span class="qnum"><?php echo $q['question_number']; ?>.</span>
                        <?php echo htmlspecialchars($q['question_text']); ?>
                        <span class="q-marks">[<?php echo $q['marks']; ?>]</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>

    <div class="footer">
        <?php echo htmlspecialchars($school['school_name']); ?> • Exam Code: <?php echo htmlspecialchars($exam['exam_code']); ?> • <?php echo date('d M Y'); ?>
    </div>

</div>

</body>
</html>