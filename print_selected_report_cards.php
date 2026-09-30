<?php
// school_owner/print_selected_report_cards.php - Nigerian-style report card
// v6: position uses RANK() for proper tie handling
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

// CSRF check only for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$schoolCurrentStmt = $db->prepare("SELECT current_term, current_session, school_name, logo_path, address, phone, email, slogan, next_term_date FROM schools WHERE id = ?");
$schoolCurrentStmt->execute([$school_id]);
$school = $schoolCurrentStmt->fetch();

$default_term = $school['current_term'] ?? 'Term 1';
$default_session = $school['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

// Accept from POST or GET
$class_id = (int)($_POST['class_id'] ?? $_GET['class_id'] ?? 0);

$term = trim(str_replace('+', ' ', urldecode($_POST['term'] ?? $_GET['term'] ?? $default_term)));
$session = trim(str_replace('+', ' ', urldecode($_POST['session'] ?? $_GET['session'] ?? $default_session)));

$student_ids_raw = $_POST['student_ids'] ?? $_GET['student_ids'] ?? [];
$student_ids = is_array($student_ids_raw) ? array_map('intval', $student_ids_raw) : [];
$student_ids = array_filter($student_ids);

if (empty($student_ids)) die("No students selected.");

$logo_path = (!empty($school['logo_path']) && $school['logo_path'] !== 'default_logo.png')
    ? BASE_URL . 'uploads/school_logos/' . $school['logo_path']
    : BASE_URL . 'assets/images/default_logo.png';

// Prepped statements
$studentStmt = $db->prepare("
    SELECT s.*, c.name AS class_name
    FROM students s
    JOIN classes c ON s.class_id = c.id
    WHERE s.id = ? AND s.school_id = ?
");

$scoreStmt = $db->prepare("
    SELECT sub.name AS subject_name, es.ca1, es.ca2, es.ca3, es.exam, es.total, es.grade,
           es.comment, es.principal_comment
    FROM exam_scores es
    JOIN subjects sub ON es.subject_id = sub.id
    WHERE es.student_id = ? AND es.school_id = ? AND es.term_name = ? AND es.session_year = ?
    ORDER BY sub.name
");

$attStmt = $db->prepare("
    SELECT status, COUNT(*) as count
    FROM attendance_log
    WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ?
    GROUP BY status
");

$affStmt = $db->prepare("
    SELECT punctuality, neatness, attentiveness, honesty, politeness
    FROM student_affective_ratings
    WHERE school_id = ? AND student_id = ? AND term_name = ? AND session_year = ?
    LIMIT 1
");

$classSizeStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND class_id = ? AND status='Active'");

// Position with ties — students with the same average get the same rank
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

function gradeComment($avg) {
    if ($avg >= 80) return 'Excellent';
    if ($avg >= 70) return 'Very Good';
    if ($avg >= 60) return 'Good';
    if ($avg >= 50) return 'Fair';
    if ($avg >= 40) return 'Pass';
    return 'Needs Improvement';
}

function domainRating($value) {
    $v = (int)$value;
    if ($v < 1) $v = 3;
    if ($v > 5) $v = 5;
    $stars = str_repeat('★', $v) . str_repeat('☆', 5 - $v);
    $labels = [1=>'Poor', 2=>'Fair', 3=>'Good', 4=>'Very Good', 5=>'Excellent'];
    return $stars . ' <small style="color:#666;">' . ($labels[$v] ?? '') . '</small>';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Cards — <?php echo htmlspecialchars($term); ?> · <?php echo htmlspecialchars($session); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; color: #222; }

        .report-card {
            background: #fff;
            max-width: 820px;
            margin: 20px auto;
            padding: 30px 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            page-break-after: always;
        }
        .report-card:last-child { page-break-after: auto; }

        .header { display: flex; align-items: center; gap: 20px; border-bottom: 3px double #333; padding-bottom: 15px; margin-bottom: 15px; }
        .header img { width: 90px; height: 90px; object-fit: contain; }
        .header .school-info { flex: 1; text-align: center; }
        .header .school-name { font-size: 24px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #1e293b; }
        .header .school-meta { font-size: 12px; color: #555; margin-top: 4px; }
        .header .slogan { font-style: italic; font-size: 13px; color: #7c3aed; margin-top: 4px; }

        .report-title { text-align: center; font-size: 16px; font-weight: 700; background: #1e293b; color: #fff; padding: 8px; letter-spacing: 2px; margin-bottom: 20px; }

        .bio-row { display: flex; align-items: flex-start; gap: 20px; margin-bottom: 20px; }
        .bio-row .student-photo { width: 100px; height: 100px; border-radius: 8px; object-fit: cover; border: 3px solid #ddd; }
        .bio-table { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 4px 30px; font-size: 13px; }
        .bio-table .field { display: flex; padding: 4px 0; border-bottom: 1px dotted #ccc; }
        .bio-table .field .lbl { width: 110px; color: #666; }
        .bio-table .field .val { font-weight: 600; color: #111; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 12.5px; }
        th, td { border: 1px solid #333; padding: 6px 8px; }
        th { background: #1e293b; color: #fff; font-weight: 600; text-align: left; }
        .subjects-table td { text-align: center; }
        .subjects-table td:first-child { text-align: left; }
        .subjects-table .grade-cell { font-weight: 700; background: #f8fafc; }

        .domain-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        .domain-grid h4 { font-size: 13px; background: #e5e7eb; padding: 6px 10px; margin: 0 0 8px 0; border-left: 4px solid #7c3aed; text-transform: uppercase; letter-spacing: 1px; }
        .domain-grid table { font-size: 12px; margin-bottom: 0; }
        .domain-grid th, .domain-grid td { padding: 5px 8px; }

        .attendance-table td { text-align: center; }

        .summary-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: #f1f5f9;
            border: 1px solid #333;
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 13px;
        }
        .summary-bar .item { text-align: center; flex: 1; }
        .summary-bar .item .lbl { font-size: 10px; color: #555; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; font-weight: 600; }
        .summary-bar .item .val { font-size: 15px; }

        .comment-block { border: 1px solid #333; padding: 10px 15px; margin-bottom: 15px; font-size: 13px; }
        .comment-block .lbl { font-weight: 700; font-size: 12px; color: #555; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .comment-block .val { min-height: 30px; }

        .grading-key { display: flex; justify-content: space-between; font-size: 11px; background: #f8fafc; border: 1px solid #ddd; padding: 8px 12px; margin-bottom: 15px; }

        .signatures { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ccc; font-size: 12px; }
        .signatures .sig { text-align: center; }
        .signatures .sig .line { border-top: 1px solid #333; width: 160px; margin-bottom: 4px; }

        @page { size: A4; margin: 12mm; }
        @media print {
            body { background: #fff; }
            .report-card { box-shadow: none; margin: 0; padding: 0; max-width: 100%; page-break-after: always; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align:center;padding:15px;background:#1e293b;color:#fff;">
    <button onclick="window.print()" style="padding:10px 30px;font-size:15px;font-weight:600;background:#7c3aed;color:#fff;border:none;border-radius:8px;cursor:pointer;">
        🖨️ Print / Save as PDF
    </button>
    <div style="font-size:12px;margin-top:8px;opacity:0.8;">
        Term: <strong><?php echo htmlspecialchars($term); ?></strong> · Session: <strong><?php echo htmlspecialchars($session); ?></strong>
        · <?php echo count($student_ids); ?> report card(s)
    </div>
</div>

<?php foreach ($student_ids as $sid): 
    $studentStmt->execute([$sid, $school_id]);
    $student = $studentStmt->fetch();
    if (!$student) continue;

    $scoreStmt->execute([$sid, $school_id, $term, $session]);
    $scores = $scoreStmt->fetchAll();

    $attStmt->execute([$sid, $school_id, $term, $session]);
    $attendance = ['Present'=>0,'Absent'=>0,'Late'=>0,'Excused'=>0];
    while ($row = $attStmt->fetch()) {
        if (isset($attendance[$row['status']])) $attendance[$row['status']] = (int)$row['count'];
    }

    // Affective from new table
    $affStmt->execute([$school_id, $sid, $term, $session]);
    $aff = $affStmt->fetch();
    $punctuality = $aff['punctuality'] ?? 3;
    $neatness = $aff['neatness'] ?? 3;
    $attentiveness = $aff['attentiveness'] ?? 3;
    $honesty = $aff['honesty'] ?? 3;
    $politeness = $aff['politeness'] ?? 3;

    $totalMarks = 0;
    $subjectCount = count($scores);
    foreach ($scores as $s) $totalMarks += $s['total'];
    $maxMarks = $subjectCount * 100;
    $average = $subjectCount > 0 ? round($totalMarks / $subjectCount, 2) : 0;

    $overallGrade = 'F';
    if ($average >= 80) $overallGrade = 'A';
    elseif ($average >= 70) $overallGrade = 'B';
    elseif ($average >= 60) $overallGrade = 'C';
    elseif ($average >= 50) $overallGrade = 'D';
    elseif ($average >= 40) $overallGrade = 'E';

    // Position with ties
    $positionStmt->execute([$term, $session, $school_id, $student['class_id']]);
    $ranked = $positionStmt->fetchAll();
    $totalStudents = count($ranked);
    $position = 0;
    foreach ($ranked as $row) {
        if ((int)$row['student_id'] === (int)$sid) {
            $position = (int)$row['position'];
            break;
        }
    }

    $photo = ($student['photo_path'] && $student['photo_path'] !== 'default_student.png')
        ? BASE_URL . 'uploads/student_photos/' . $student['photo_path']
        : BASE_URL . 'assets/images/default_avatar.png';

    $teacherComment = '';
    foreach ($scores as $s) {
        if (!empty($s['comment'])) { $teacherComment = $s['comment']; break; }
    }
    $principalComment = '';
    foreach ($scores as $s) {
        if (!empty($s['principal_comment'])) { $principalComment = $s['principal_comment']; break; }
    }
    if (empty($principalComment)) $principalComment = 'Keep up the good work.';

    $classSizeStmt->execute([$school_id, $student['class_id']]);
    $classSize = (int)$classSizeStmt->fetchColumn();
?>
<div class="report-card">

    <div class="header">
        <img src="<?php echo $logo_path; ?>" alt="Logo">
        <div class="school-info">
            <div class="school-name"><?php echo htmlspecialchars($school['school_name']); ?></div>
            <div class="school-meta"><?php echo htmlspecialchars($school['address'] ?? ''); ?></div>
            <div class="school-meta">
                <?php echo htmlspecialchars($school['phone'] ?? ''); ?>
                <?php if (!empty($school['email'])) echo ' | ' . htmlspecialchars($school['email']); ?>
            </div>
            <?php if (!empty($school['slogan'])): ?>
                <div class="slogan">"<?php echo htmlspecialchars($school['slogan']); ?>"</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="report-title">TERMLY REPORT CARD — <?php echo strtoupper(htmlspecialchars($term)); ?> · <?php echo htmlspecialchars($session); ?></div>

    <div class="bio-row">
        <img src="<?php echo $photo; ?>" class="student-photo" alt="Photo">
        <div class="bio-table">
            <div class="field"><span class="lbl">Student Name:</span><span class="val"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></span></div>
            <div class="field"><span class="lbl">Student ID:</span><span class="val"><?php echo htmlspecialchars($student['student_id']); ?></span></div>
            <div class="field"><span class="lbl">Class:</span><span class="val"><?php echo htmlspecialchars($student['class_name']); ?></span></div>
            <div class="field"><span class="lbl">Gender:</span><span class="val"><?php echo htmlspecialchars($student['gender'] ?? '—'); ?></span></div>
            <div class="field"><span class="lbl">Date of Birth:</span><span class="val"><?php echo !empty($student['date_of_birth']) ? date('d M Y', strtotime($student['date_of_birth'])) : '—'; ?></span></div>
            <div class="field"><span class="lbl">Class Size:</span><span class="val"><?php echo $classSize; ?></span></div>
        </div>
    </div>

    <table class="subjects-table">
        <thead>
            <tr>
                <th style="width:30%;">Subject</th>
                <th>CA1<br><small>(20)</small></th>
                <th>CA2<br><small>(20)</small></th>
                <th>CA3<br><small>(20)</small></th>
                <th>Exam<br><small>(40)</small></th>
                <th>Total<br><small>(100)</small></th>
                <th>Grade</th>
                <th>Remark</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($scores)): ?>
                <tr><td colspan="8" style="text-align:center;color:#999;">No scores recorded for this term.</td></tr>
            <?php else: foreach ($scores as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                    <td><?php echo number_format($s['ca1'], 0); ?></td>
                    <td><?php echo number_format($s['ca2'], 0); ?></td>
                    <td><?php echo number_format($s['ca3'], 0); ?></td>
                    <td><?php echo number_format($s['exam'], 0); ?></td>
                    <td><strong><?php echo number_format($s['total'], 0); ?></strong></td>
                    <td class="grade-cell"><?php echo htmlspecialchars($s['grade'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars(gradeComment((float)$s['total'])); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if (!empty($scores)): ?>
    <div class="summary-bar">
        <div class="item">
            <div class="lbl">Total Marks</div>
            <div class="val"><?php echo number_format($totalMarks, 0); ?> / <?php echo number_format($maxMarks, 0); ?></div>
        </div>
        <div class="item">
            <div class="lbl">Average</div>
            <div class="val"><?php echo $average; ?>% (<?php echo $overallGrade; ?>)</div>
        </div>
        <div class="item">
            <div class="lbl">Position in Class</div>
            <div class="val"><?php echo ordinal($position); ?> / <?php echo $totalStudents; ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="domain-grid">
        <div>
            <h4>Affective Domain</h4>
            <table>
                <tr><th>Traits</th><th>Rating</th></tr>
                <tr><td>Punctuality</td><td><?php echo domainRating($punctuality); ?></td></tr>
                <tr><td>Neatness</td><td><?php echo domainRating($neatness); ?></td></tr>
                <tr><td>Attentiveness</td><td><?php echo domainRating($attentiveness); ?></td></tr>
                <tr><td>Honesty</td><td><?php echo domainRating($honesty); ?></td></tr>
                <tr><td>Politeness</td><td><?php echo domainRating($politeness); ?></td></tr>
            </table>
        </div>
        <div>
            <h4>Attendance</h4>
            <table class="attendance-table">
                <tr><th>Status</th><th>Count</th></tr>
                <tr><td>Present</td><td><?php echo $attendance['Present']; ?></td></tr>
                <tr><td>Absent</td><td><?php echo $attendance['Absent']; ?></td></tr>
                <tr><td>Late</td><td><?php echo $attendance['Late']; ?></td></tr>
                <tr><td>Excused</td><td><?php echo $attendance['Excused']; ?></td></tr>
                <tr><td><strong>Total Days</strong></td><td><strong><?php echo array_sum($attendance); ?></strong></td></tr>
            </table>
        </div>
    </div>

    <div class="grading-key">
        <span><strong>A</strong> = 80-100</span>
        <span><strong>B</strong> = 70-79</span>
        <span><strong>C</strong> = 60-69</span>
        <span><strong>D</strong> = 50-59</span>
        <span><strong>E</strong> = 40-49</span>
        <span><strong>F</strong> = Below 40</span>
    </div>

    <div class="comment-block">
        <div class="lbl">Class Teacher's Comment</div>
        <div class="val"><?php echo htmlspecialchars($teacherComment ?: '—'); ?></div>
    </div>
    <div class="comment-block">
        <div class="lbl">Principal's Comment</div>
        <div class="val"><?php echo htmlspecialchars($principalComment); ?></div>
    </div>

    <div class="signatures">
        <div class="sig"><div class="line"></div>Class Teacher</div>
        <div class="sig"><div class="line"></div>Principal</div>
        <div class="sig"><div class="line"></div>Owner</div>
    </div>

    <div style="text-align:center;margin-top:20px;font-size:11px;color:#666;">
        Next Term Begins: 
        <?php echo !empty($school['next_term_date']) && $school['next_term_date'] !== '0000-00-00' 
                    ? date('d M Y', strtotime($school['next_term_date'])) 
                    : '—'; ?>
    </div>

</div>
<?php endforeach; ?>

<script>
    window.onload = function() { setTimeout(function(){ window.print(); }, 500); };
</script>
</body>
</html>