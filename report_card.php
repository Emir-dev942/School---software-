<?php
// parent/report_card.php - Full Nigerian-style report card view for parents
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['parent_id'])) {
    header('Location: ' . BASE_URL . 'parent/login.php');
    exit;
}

$db = getDB();
$parent_id = (int)$_SESSION['parent_id'];
$school_id = (int)$_SESSION['school_id'];
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$selected_term = $_GET['term'] ?? '';
$selected_session = $_GET['session'] ?? '';

// Fetch children
$children = [];
if ($db->query("SHOW TABLES LIKE 'parent_students'")->rowCount() > 0) {
    $stmt = $db->prepare("SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name FROM parent_students ps JOIN students s ON ps.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE ps.parent_id = ? AND s.school_id = ? ORDER BY s.first_name");
    $stmt->execute([$parent_id, $school_id]);
    $children = $stmt->fetchAll();
}
if (empty($children)) {
    $parentStmt = $db->prepare("SELECT student_id FROM parent_portal WHERE id = ? AND school_id = ? AND is_active = 1");
    $parentStmt->execute([$parent_id, $school_id]);
    $parent = $parentStmt->fetch();
    if (!$parent) die("Parent account not found.");
    $student_id = (int)$parent['student_id'];
    $stmt = $db->prepare("SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    $children = $stmt->fetchAll();
}
if (empty($children)) die("No child found.");

$activeChildId = $child_id ?: $children[0]['id'];
$activeChild = null;
foreach ($children as $child) {
    if ($child['id'] === $activeChildId) { $activeChild = $child; break; }
}
if (!$activeChild) $activeChild = $children[0];

// Fetch full student record
$studentStmt = $db->prepare("SELECT s.*, c.name AS class_name, c.id AS class_id FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.school_id = ?");
$studentStmt->execute([$activeChild['id'], $school_id]);
$student = $studentStmt->fetch();
if (!$student) die("Student not found.");

// Fetch school info
$schoolStmt = $db->prepare("SELECT current_term, current_session, school_name, address, phone, email, slogan, next_term_date, logo_path FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();
$current_term = $school['current_term'] ?? 'Term 1';
$current_session = $school['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

$selected_term = $selected_term ?: $current_term;
$selected_session = $selected_session ?: $current_session;

// Term list for selector
$termsStmt = $db->prepare("
    SELECT DISTINCT term_name, session_year
    FROM exam_scores
    WHERE student_id = ? AND school_id = ?
    UNION
    SELECT DISTINCT term_name, session_year
    FROM attendance_log
    WHERE student_id = ? AND school_id = ?
    ORDER BY session_year DESC, term_name DESC
");
$termsStmt->execute([$activeChild['id'], $school_id, $activeChild['id'], $school_id]);
$availableTerms = $termsStmt->fetchAll();

// Fee lock check
$invoiceStmt = $db->prepare("SELECT (total_amount - paid_amount) AS balance, fee_lock_override FROM invoices WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ? LIMIT 1");
$invoiceStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$invoice = $invoiceStmt->fetch();
$balanceOwing = $invoice ? (float)$invoice['balance'] : 0;

// Scores
$scoreStmt = $db->prepare("
    SELECT sub.name AS subject_name, es.ca1, es.ca2, es.ca3, es.exam, es.total, es.grade, es.principal_comment
    FROM exam_scores es
    JOIN subjects sub ON es.subject_id = sub.id
    WHERE es.student_id = ? AND es.school_id = ? AND es.term_name = ? AND es.session_year = ?
    ORDER BY sub.name
");
$scoreStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$scores = $scoreStmt->fetchAll();

// Calculate totals
$totalMarks = 0;
$subjectCount = count($scores);
foreach ($scores as $s) $totalMarks += (float)$s['total'];
$maxMarks = $subjectCount * 100;
$average = $subjectCount > 0 ? round($totalMarks / $subjectCount, 2) : 0;

$overallGrade = '—';
if ($average >= 80) $overallGrade = 'A';
elseif ($average >= 70) $overallGrade = 'B';
elseif ($average >= 60) $overallGrade = 'C';
elseif ($average >= 50) $overallGrade = 'D';
elseif ($average >= 40) $overallGrade = 'E';
elseif ($subjectCount > 0) $overallGrade = 'F';

// Position with RANK
$positionStmt = $db->prepare("
    SELECT student_id, avg_score, position FROM (
        SELECT 
            st.id AS student_id,
            AVG(es.total) AS avg_score,
            RANK() OVER (ORDER BY AVG(es.total) DESC) AS position
        FROM students st
        JOIN exam_scores es ON es.student_id = st.id 
            AND es.school_id = st.school_id 
            AND es.term_name = ? 
            AND es.session_year = ?
        WHERE st.school_id = ? AND st.class_id = ? AND st.status = 'Active'
        GROUP BY st.id
    ) AS ranked
    WHERE avg_score IS NOT NULL
    ORDER BY position ASC
");
$positionStmt->execute([$selected_term, $selected_session, $school_id, $student['class_id']]);
$ranked = $positionStmt->fetchAll();
$classRankedCount = count($ranked);
$position = 0;
foreach ($ranked as $r) {
    if ((int)$r['student_id'] === (int)$activeChild['id']) {
        $position = (int)$r['position'];
        break;
    }
}

// Attendance
$attStmt = $db->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM attendance_log
    WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ?
    GROUP BY status
");
$attStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$attendance = ['Present'=>0,'Absent'=>0,'Late'=>0,'Excused'=>0];
while ($row = $attStmt->fetch()) {
    if (isset($attendance[$row['status']])) $attendance[$row['status']] = (int)$row['cnt'];
}
$totalDays = array_sum($attendance);

// Affective
$affStmt = $db->prepare("
    SELECT punctuality, neatness, attentiveness, honesty, politeness
    FROM student_affective_ratings
    WHERE school_id = ? AND student_id = ? AND term_name = ? AND session_year = ?
    LIMIT 1
");
$affStmt->execute([$school_id, $activeChild['id'], $selected_term, $selected_session]);
$aff = $affStmt->fetch();
if (!$aff) {
    $aff = ['punctuality'=>3,'neatness'=>3,'attentiveness'=>3,'honesty'=>3,'politeness'=>3];
}

// Class size
$classSizeStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND class_id = ? AND status='Active'");
$classSizeStmt->execute([$school_id, $student['class_id']]);
$classSize = (int)$classSizeStmt->fetchColumn();

// Helpers
function gradeRemark($avg) {
    if ($avg >= 80) return 'Excellent';
    if ($avg >= 70) return 'Very Good';
    if ($avg >= 60) return 'Good';
    if ($avg >= 50) return 'Fair';
    if ($avg >= 40) return 'Pass';
    return 'Needs Improvement';
}

function domainStars($value) {
    $v = (int)$value;
    if ($v < 1) $v = 3;
    if ($v > 5) $v = 5;
    $stars = str_repeat('★', $v) . str_repeat('☆', 5 - $v);
    $labels = [1=>'Poor', 2=>'Fair', 3=>'Good', 4=>'Very Good', 5=>'Excellent'];
    return ['stars' => $stars, 'label' => $labels[$v]];
}

function ordinal($n) {
    if ($n <= 0) return '—';
    $suffix = 'th';
    if (!in_array(($n % 100), [11,12,13])) {
        switch ($n % 10) {
            case 1: $suffix = 'st'; break;
            case 2: $suffix = 'nd'; break;
            case 3: $suffix = 'rd'; break;
        }
    }
    return $n . $suffix;
}

$photo_url = ($activeChild['photo_path'] && $activeChild['photo_path'] !== 'default_student.png')
    ? BASE_URL . 'uploads/student_photos/' . $activeChild['photo_path']
    : BASE_URL . 'assets/images/default_avatar.png';

$logo_url = (!empty($school['logo_path']) && $school['logo_path'] !== 'default_logo.png')
    ? BASE_URL . 'uploads/school_logos/' . $school['logo_path']
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card | <?php echo htmlspecialchars($school['school_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; min-height: 100vh; padding-bottom: 30px; }
        .container { max-width: 480px; margin: 0 auto; padding: 0 16px 30px; }

        /* Top Bar */
        .topbar { display: flex; justify-content: space-between; align-items: center; padding: 20px 16px 10px; }
        .topbar .back { background: white; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #0f172a; text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .topbar h3 { font-weight: 800; color: #0f172a; font-size: 1.1rem; flex: 1; margin-left: 12px; }

        /* Chip switcher */
        .chips-scroll { display: flex; gap: 10px; overflow-x: auto; padding: 8px 0 4px; margin: 0 -16px; padding-left: 16px; padding-right: 16px; scrollbar-width: none; }
        .chips-scroll::-webkit-scrollbar { display: none; }
        .chip { display: flex; align-items: center; gap: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 50px; padding: 6px 16px 6px 6px; text-decoration: none; color: #0f172a; transition: 0.15s; flex-shrink: 0; }
        .chip:hover { border-color: #c4b5fd; }
        .chip.active { background: linear-gradient(135deg, #7c3aed, #4f46e5); border-color: #7c3aed; color: white; }
        .chip.active .chip-name { color: white; }
        .chip.active .chip-class { color: rgba(255,255,255,0.7); }
        .chip img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9; }
        .chip.active img { border-color: rgba(255,255,255,0.4); }
        .chip-name { font-weight: 700; font-size: 0.82rem; white-space: nowrap; }
        .chip-class { font-size: 0.68rem; color: #94a3b8; white-space: nowrap; }

        /* Term selector */
        .term-selector { background: white; border-radius: 14px; padding: 12px; margin-top: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; gap: 8px; align-items: center; }
        .term-selector select { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; font-size: 0.85rem; font-weight: 600; flex: 1; }

        /* Fee banner */
        .fee-banner { background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #dc2626; border-radius: 12px; padding: 14px 18px; margin-top: 12px; display: flex; align-items: center; gap: 12px; }
        .fee-banner i { color: #dc2626; font-size: 1.3rem; }
        .fee-banner .text { flex: 1; }
        .fee-banner .title { font-weight: 700; color: #991b1b; font-size: 0.9rem; }
        .fee-banner .desc { font-size: 0.8rem; color: #dc2626; margin-top: 2px; }

        /* Report Card Sheet */
        .report-sheet { background: white; border-radius: 20px; padding: 24px 20px; margin-top: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }

        /* Header */
        .sheet-header { display: flex; align-items: center; gap: 12px; padding-bottom: 12px; border-bottom: 3px double #1e293b; margin-bottom: 12px; }
        .sheet-header img { width: 56px; height: 56px; object-fit: contain; }
        .sheet-header .info { flex: 1; text-align: center; }
        .sheet-header .school-name { font-size: 0.95rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #1e293b; }
        .sheet-header .school-meta { font-size: 0.68rem; color: #64748b; margin-top: 2px; }

        .report-title { text-align: center; font-size: 0.85rem; font-weight: 700; background: #1e293b; color: #fff; padding: 8px; letter-spacing: 1.5px; margin-bottom: 16px; border-radius: 6px; }

        /* Student bio */
        .bio-row { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
        .bio-row img { width: 64px; height: 64px; border-radius: 8px; object-fit: cover; border: 2px solid #e2e8f0; flex-shrink: 0; }
        .bio-table { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 3px 12px; font-size: 0.72rem; }
        .bio-field { display: flex; }
        .bio-field .lbl { color: #64748b; min-width: 55px; }
        .bio-field .val { font-weight: 700; color: #0f172a; }

        /* Subject table */
        .subjects-table { width: 100%; border-collapse: collapse; font-size: 0.72rem; margin-bottom: 14px; }
        .subjects-table th { background: #1e293b; color: #fff; padding: 6px 4px; text-align: center; font-weight: 600; font-size: 0.65rem; }
        .subjects-table th:first-child { text-align: left; padding-left: 8px; }
        .subjects-table td { border: 1px solid #e2e8f0; padding: 6px 4px; text-align: center; }
        .subjects-table td:first-child { text-align: left; padding-left: 8px; font-weight: 600; }
        .subjects-table .grade-cell { font-weight: 800; }
        .subjects-table .remark-cell { font-size: 0.62rem; color: #64748b; }

        /* Summary bar */
        .summary-bar { display: flex; background: #f1f5f9; border-radius: 10px; padding: 10px; margin-bottom: 14px; }
        .summary-bar .item { flex: 1; text-align: center; }
        .summary-bar .item .lbl { font-size: 0.6rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; }
        .summary-bar .item .val { font-size: 0.95rem; font-weight: 800; color: #0f172a; margin-top: 2px; }

        /* Domains */
        .domains-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
        .domain-box { background: #f8fafc; border-radius: 10px; padding: 12px; }
        .domain-box h6 { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #7c3aed; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
        .domain-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 0.7rem; }
        .domain-row .lbl { color: #64748b; }
        .domain-row .val { font-weight: 700; color: #0f172a; }
        .domain-row .stars { color: #f59e0b; font-size: 0.68rem; }

        /* Grading key */
        .grading-key { display: flex; flex-wrap: wrap; gap: 4px 10px; font-size: 0.62rem; background: #f8fafc; padding: 8px 10px; border-radius: 8px; margin-bottom: 14px; color: #475569; }
        .grading-key strong { color: #0f172a; }

        /* Comments */
        .comment-box { background: #f8fafc; border-radius: 10px; padding: 12px; margin-bottom: 10px; }
        .comment-box h6 { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #7c3aed; margin-bottom: 6px; }
        .comment-box p { font-size: 0.78rem; color: #334155; line-height: 1.5; min-height: 20px; }
        .comment-box .blank { color: #cbd5e1; font-style: italic; }

        /* Signatures */
        .signatures { display: flex; justify-content: space-between; margin-top: 20px; padding-top: 14px; border-top: 1px solid #e2e8f0; }
        .sig { text-align: center; flex: 1; }
        .sig .line { border-top: 1px solid #334155; margin: 24px 8px 4px; }
        .sig .lbl { font-size: 0.6rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }

        /* Download button */
        .btn-download { width: 100%; border-radius: 12px; padding: 14px; font-weight: 700; margin-top: 16px; border: none; background: linear-gradient(135deg, #7c3aed, #6d28d9); color: white; font-size: 0.9rem; text-decoration: none; display: block; text-align: center; box-shadow: 0 4px 12px rgba(124,58,237,0.2); }
        .btn-download i { margin-right: 6px; }

        /* Empty state */
        .empty-state { background: white; border-radius: 16px; padding: 60px 20px; text-align: center; color: #94a3b8; margin-top: 16px; }
        .empty-state i { font-size: 2.5rem; opacity: 0.5; margin-bottom: 12px; display: block; }
    </style>
</head>
<body>
<div class="container">
    <!-- Top Bar -->
    <div class="topbar">
        <a href="index.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h3>📄 Report Card</h3>
    </div>

    <!-- Child Chips -->
    <?php if (count($children) > 1): ?>
        <div class="chips-scroll">
            <?php foreach ($children as $s):
                $chipPhoto = ($s['photo_path'] && $s['photo_path'] !== 'default_student.png')
                    ? BASE_URL . 'uploads/student_photos/' . $s['photo_path']
                    : BASE_URL . 'assets/images/default_avatar.png';
                $isActive = ($s['id'] === $activeChild['id']);
            ?>
                <a href="?child_id=<?php echo $s['id']; ?>" class="chip <?php echo $isActive ? 'active' : ''; ?>">
                    <img src="<?php echo $chipPhoto; ?>" alt="">
                    <div>
                        <div class="chip-name"><?php echo htmlspecialchars($s['first_name']); ?></div>
                        <div class="chip-class"><?php echo htmlspecialchars($s['class_name']); ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Term Selector -->
    <?php if (count($availableTerms) > 0): ?>
        <div class="term-selector">
            <i class="fas fa-calendar" style="color:#7c3aed;"></i>
            <form method="GET" style="display:flex;gap:8px;flex:1;">
                <input type="hidden" name="child_id" value="<?php echo $activeChild['id']; ?>">
                <select name="term" onchange="this.form.submit()">
                    <?php foreach ($availableTerms as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['term_name']); ?>" <?php echo $selected_term === $t['term_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['term_name'] . ' · ' . $t['session_year']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    <?php endif; ?>

    <!-- Fee Banner (soft lock) -->
    <?php if ($balanceOwing > 0): ?>
        <div class="fee-banner">
            <i class="fas fa-exclamation-triangle"></i>
            <div class="text">
                <div class="title">Outstanding Fees</div>
                <div class="desc">You owe <?php echo formatCurrency($balanceOwing); ?>. Please settle to avoid future restrictions.</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($scores)): ?>
        <div class="empty-state">
            <i class="fas fa-file-alt"></i>
            <strong style="color:#475569;">No results yet for <?php echo htmlspecialchars($selected_term); ?></strong>
            <p style="margin-top:6px;font-size:0.85rem;">Scores will appear once approved by the school.</p>
        </div>
    <?php else: ?>

    <!-- Report Sheet -->
    <div class="report-sheet">

        <!-- School Header -->
        <div class="sheet-header">
            <?php if ($logo_url): ?>
                <img src="<?php echo $logo_url; ?>" alt="Logo">
            <?php endif; ?>
            <div class="info">
                <div class="school-name"><?php echo htmlspecialchars($school['school_name']); ?></div>
                <?php if (!empty($school['address'])): ?>
                    <div class="school-meta"><?php echo htmlspecialchars($school['address']); ?></div>
                <?php endif; ?>
                <div class="school-meta">
                    <?php if (!empty($school['phone'])) echo htmlspecialchars($school['phone']); ?>
                    <?php if (!empty($school['email'])) echo ' • ' . htmlspecialchars($school['email']); ?>
                </div>
            </div>
        </div>

        <div class="report-title">
            TERMLY REPORT — <?php echo strtoupper(htmlspecialchars($selected_term)); ?> · <?php echo htmlspecialchars($selected_session); ?>
        </div>

        <!-- Student Bio -->
        <div class="bio-row">
            <img src="<?php echo $photo_url; ?>" alt="Photo">
            <div class="bio-table">
                <div class="bio-field"><span class="lbl">Name:</span><span class="val"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span></div>
                <div class="bio-field"><span class="lbl">ID:</span><span class="val"><?php echo htmlspecialchars($student['student_id']); ?></span></div>
                <div class="bio-field"><span class="lbl">Class:</span><span class="val"><?php echo htmlspecialchars($student['class_name']); ?></span></div>
                <div class="bio-field"><span class="lbl">Gender:</span><span class="val"><?php echo htmlspecialchars($student['gender'] ?? '—'); ?></span></div>
                <div class="bio-field"><span class="lbl">Class Size:</span><span class="val"><?php echo $classSize; ?></span></div>
                <div class="bio-field"><span class="lbl">Term:</span><span class="val"><?php echo htmlspecialchars($selected_term); ?></span></div>
            </div>
        </div>

        <!-- Subjects Table -->
        <table class="subjects-table">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>CA1</th>
                    <th>CA2</th>
                    <th>CA3</th>
                    <th>Exam</th>
                    <th>Total</th>
                    <th>Grade</th>
                    <th>Remark</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scores as $s): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                        <td><?php echo number_format((float)$s['ca1'], 0); ?></td>
                        <td><?php echo number_format((float)$s['ca2'], 0); ?></td>
                        <td><?php echo number_format((float)$s['ca3'], 0); ?></td>
                        <td><?php echo number_format((float)$s['exam'], 0); ?></td>
                        <td><strong><?php echo number_format((float)$s['total'], 0); ?></strong></td>
                        <td class="grade-cell" style="color:<?php 
                            $g = $s['grade']; 
                            echo $g === 'A' ? '#166534' : ($g === 'B' ? '#1e40af' : ($g === 'C' ? '#92400e' : ($g === 'D' || $g === 'E' ? '#9a3412' : '#991b1b')));
                        ?>;"><?php echo htmlspecialchars($s['grade'] ?? '—'); ?></td>
                        <td class="remark-cell"><?php echo htmlspecialchars(gradeRemark((float)$s['total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Summary -->
        <div class="summary-bar">
            <div class="item">
                <div class="lbl">Total</div>
                <div class="val"><?php echo number_format($totalMarks, 0); ?> / <?php echo number_format($maxMarks, 0); ?></div>
            </div>
            <div class="item">
                <div class="lbl">Average</div>
                <div class="val"><?php echo $average; ?>% <span style="font-size:0.75rem;color:#7c3aed;">(<?php echo $overallGrade; ?>)</span></div>
            </div>
            <div class="item">
                <div class="lbl">Position</div>
                <div class="val"><?php echo $position > 0 ? ordinal($position) . ' / ' . $classRankedCount : '—'; ?></div>
            </div>
        </div>

        <!-- Domains -->
        <div class="domains-grid">
            <div class="domain-box">
                <h6>Affective</h6>
                <?php foreach (['punctuality'=>'Punctuality','neatness'=>'Neatness','attentiveness'=>'Attention','honesty'=>'Honesty','politeness'=>'Politeness'] as $key => $label):
                    $d = domainStars($aff[$key] ?? 3);
                ?>
                    <div class="domain-row">
                        <span class="lbl"><?php echo $label; ?></span>
                        <span class="stars"><?php echo $d['stars']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="domain-box">
                <h6>Attendance</h6>
                <div class="domain-row"><span class="lbl">Present</span><span class="val" style="color:#16a34a;"><?php echo $attendance['Present']; ?></span></div>
                <div class="domain-row"><span class="lbl">Absent</span><span class="val" style="color:#dc2626;"><?php echo $attendance['Absent']; ?></span></div>
                <div class="domain-row"><span class="lbl">Late</span><span class="val" style="color:#f59e0b;"><?php echo $attendance['Late']; ?></span></div>
                <div class="domain-row"><span class="lbl">Excused</span><span class="val" style="color:#3b82f6;"><?php echo $attendance['Excused']; ?></span></div>
                <div class="domain-row" style="border-top:1px solid #e2e8f0;margin-top:4px;padding-top:6px;">
                    <span class="lbl" style="font-weight:700;">Total Days</span>
                    <span class="val"><?php echo $totalDays; ?></span>
                </div>
            </div>
        </div>

        <!-- Grading Key -->
        <div class="grading-key">
            <span><strong>A</strong> = 80-100</span>
            <span><strong>B</strong> = 70-79</span>
            <span><strong>C</strong> = 60-69</span>
            <span><strong>D</strong> = 50-59</span>
            <span><strong>E</strong> = 40-49</span>
            <span><strong>F</strong> = Below 40</span>
        </div>

        <!-- Comments -->
        <div class="comment-box">
            <h6>Class Teacher's Comment</h6>
            <p class="blank">—</p>
        </div>
        <div class="comment-box">
            <h6>Principal's Comment</h6>
            <?php 
                $principalOverallComment = '';
                foreach ($scores as $sc) {
                    if (!empty($sc['principal_comment'])) { $principalOverallComment = $sc['principal_comment']; break; }
                }
            ?>
            <?php if ($principalOverallComment): ?>
                <p><?php echo nl2br(htmlspecialchars($principalOverallComment)); ?></p>
            <?php else: ?>
                <p class="blank">—</p>
            <?php endif; ?>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="sig"><div class="line"></div><div class="lbl">Class Teacher</div></div>
            <div class="sig"><div class="line"></div><div class="lbl">Principal</div></div>
        </div>

        <!-- Next Term -->
        <?php if (!empty($school['next_term_date']) && $school['next_term_date'] !== '0000-00-00'): ?>
            <div style="text-align:center;margin-top:16px;font-size:0.7rem;color:#64748b;">
                Next Term Begins: <strong><?php echo date('d M Y', strtotime($school['next_term_date'])); ?></strong>
            </div>
        <?php endif; ?>
    </div>

    <!-- Download PDF -->
    <a href="download_report_card.php?child_id=<?php echo $activeChild['id']; ?>&term=<?php echo urlencode($selected_term); ?>&session=<?php echo urlencode($selected_session); ?>" class="btn-download">
        <i class="fas fa-download"></i> Download PDF Version
    </a>

    <?php endif; ?>
</div>
</body>
</html>