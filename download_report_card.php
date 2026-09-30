<?php
/**
 * parent/download_report_card.php
 * Fixes: soft fee lock (shows blurred card, not redirect), SSRF fix, no term switching.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/parent_auth.php';
require_once __DIR__ . '/../includes/parent_helpers.php';

$parent = requireParent();
$parent_id = (int)$parent['id'];
$school_id = (int)$parent['school_id'];
$db = getDB();

$child_id = (int)($_GET['child_id'] ?? 0);
if ($child_id <= 0) parentAbort(400, 'Invalid child.');
if (!parentOwnsChild($parent_id, $school_id, $child_id)) parentAbort(403, 'You do not have access to this report card.');

// Current term (parent can't override)
$termInfo = getSchoolTerm($school_id);
$term    = $termInfo['term'];
$session = $termInfo['session'];

// Fee lock check
$invStmt = $db->prepare("SELECT (total_amount - paid_amount) AS balance, fee_lock_override FROM invoices WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ? LIMIT 1");
$invStmt->execute([$child_id, $school_id, $term, $session]);
$invoice = $invStmt->fetch();

$fee_locked = $invoice && (float)$invoice['balance'] > 0 && (int)$invoice['fee_lock_override'] !== 1;
$fee_owed   = $fee_locked ? (float)$invoice['balance'] : 0;

// Fetch school
$schoolStmt = $db->prepare("SELECT school_name, logo_path, slogan, primary_color FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch() ?: [];

// Fetch student
$studentStmt = $db->prepare("SELECT s.first_name, s.last_name, s.photo_path, c.name AS class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.school_id = ?");
$studentStmt->execute([$child_id, $school_id]);
$student = $studentStmt->fetch();
if (!$student) parentAbort(404, 'Student not found.');

// Fetch scores
$scoreStmt = $db->prepare("
    SELECT sub.name AS subject_name, es.ca1, es.ca2, es.ca3, es.exam, es.total, es.grade
    FROM exam_scores es
    JOIN subjects sub ON es.subject_id = sub.id
    WHERE es.student_id = ? AND es.school_id = ? AND es.term_name = ? AND es.session_year = ?
    ORDER BY sub.name
");
$scoreStmt->execute([$child_id, $school_id, $term, $session]);
$scores = $scoreStmt->fetchAll();

$totalMarks = 0;
foreach ($scores as $s) $totalMarks += (float)$s['total'];
$average = count($scores) > 0 ? round($totalMarks / count($scores), 1) : 0;

// Logo
$logo_html = '';
if (!empty($school['logo_path']) && $school['logo_path'] !== 'default_logo.png') {
    $logo_file = __DIR__ . '/../uploads/school_logos/' . $school['logo_path'];
    if (file_exists($logo_file)) {
        $ext = strtolower(pathinfo($logo_file, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
        $logo_html = '<img src="data:' . $mime . ';base64,' . base64_encode(file_get_contents($logo_file)) . '" style="max-height:60px;display:block;margin:0 auto 8px;">';
    }
}

$photo_html = '';
if (!empty($student['photo_path']) && $student['photo_path'] !== 'default_student.png') {
    $photo_file = __DIR__ . '/../uploads/student_photos/' . $student['photo_path'];
    if (file_exists($photo_file)) {
        $ext = strtolower(pathinfo($photo_file, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
        $photo_html = '<img src="data:' . $mime . ';base64,' . base64_encode(file_get_contents($photo_file)) . '" style="width:70px;height:70px;border-radius:50%;object-fit:cover;border:3px solid ' . htmlspecialchars($school['primary_color'] ?? '#7c3aed') . ';">';
    }
}

$primary = $school['primary_color'] ?? '#7c3aed';

// Build HTML
$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; margin: 25px; color: #0f172a; position: relative; }
    .header { text-align: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid ' . htmlspecialchars($primary) . '; }
    .header h2 { margin: 0; font-size: 22px; color: ' . htmlspecialchars($primary) . '; }
    .header .slogan { color: #94a3b8; font-size: 11px; font-style: italic; margin-top: 4px; }
    .student-info { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; padding: 15px; background: #f8fafc; border-radius: 10px; }
    .student-info .details h3 { margin: 0 0 4px; font-size: 16px; }
    .student-info .details p { margin: 2px 0; color: #64748b; font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid #e2e8f0; padding: 8px; text-align: left; font-size: 12px; }
    th { background: #f8fafc; color: #475569; }
    .grade { display: inline-block; padding: 3px 8px; border-radius: 12px; font-weight: 700; font-size: 11px; }
    .grade-A { background: #dcfce7; color: #166534; }
    .grade-B { background: #dbeafe; color: #1e40af; }
    .grade-C { background: #fef3c7; color: #92400e; }
    .grade-D { background: #fee2e2; color: #991b1b; }
    .grade-E, .grade-F { background: #fecaca; color: #7f1d1d; }
    .average-box { margin-top: 20px; padding: 15px; background: #f0fdf4; border: 2px solid #86efac; border-radius: 10px; text-align: center; }
    .average-box .lbl { font-size: 11px; text-transform: uppercase; color: #166534; font-weight: 700; letter-spacing: 1px; }
    .average-box .value { font-size: 32px; font-weight: 900; color: #15803d; margin-top: 4px; }
    ' . ($fee_locked ? '
    .lock-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.85); z-index: 100; display: flex; align-items: center; justify-content: center; text-align: center; padding: 40px; }
    .lock-box { background: #fff; padding: 30px; border-radius: 16px; border: 2px solid #f59e0b; max-width: 400px; }
    .lock-box .icon { font-size: 60px; margin-bottom: 12px; }
    .lock-box h3 { color: #92400e; margin: 0 0 8px; font-size: 18px; }
    .lock-box p { color: #78350f; font-size: 13px; line-height: 1.5; margin: 0; }
    ' : '') . '
</style></head><body>';

if ($fee_locked) {
    $html .= '<div class="lock-overlay"><div class="lock-box">';
    $html .= '<div class="icon">🔒</div>';
    $html .= '<h3>Report Card Locked</h3>';
    $html .= '<p>This report card is locked because there is an outstanding fee balance of <strong>' . formatCurrency($fee_owed) . '</strong> for ' . htmlspecialchars($term . ' ' . $session) . '.</p>';
    $html .= '<p style="margin-top:12px;font-size:12px;color:#92400e;">Please contact the school office to clear the balance and unlock this report card.</p>';
    $html .= '</div></div>';
}

$html .= '<div class="header">' . $logo_html . '<h2>' . htmlspecialchars($school['school_name'] ?? 'School') . '</h2>';
if (!empty($school['slogan'])) $html .= '<div class="slogan">&ldquo;' . htmlspecialchars($school['slogan']) . '&rdquo;</div>';
$html .= '</div>';

$html .= '<h3 style="text-align:center;margin:10px 0;">Term Report Card</h3>';

$html .= '<div class="student-info">' . $photo_html . '<div class="details">';
$html .= '<h3>' . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . '</h3>';
$html .= '<p>Class: ' . htmlspecialchars($student['class_name']) . '</p>';
$html .= '<p>' . htmlspecialchars($term . ' · ' . $session) . '</p>';
$html .= '</div></div>';

$html .= '<table><thead><tr><th>Subject</th><th>CA1</th><th>CA2</th><th>CA3</th><th>Exam</th><th>Total</th><th>Grade</th></tr></thead><tbody>';
if (empty($scores)) {
    $html .= '<tr><td colspan="7" style="text-align:center;padding:20px;color:#94a3b8;">No scores recorded for this term yet.</td></tr>';
} else {
    foreach ($scores as $score) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($score['subject_name']) . '</td>';
        $html .= '<td>' . number_format((float)$score['ca1'], 1) . '</td>';
        $html .= '<td>' . number_format((float)$score['ca2'], 1) . '</td>';
        $html .= '<td>' . number_format((float)$score['ca3'], 1) . '</td>';
        $html .= '<td>' . number_format((float)$score['exam'], 1) . '</td>';
        $html .= '<td><strong>' . number_format((float)$score['total'], 1) . '</strong></td>';
        $html .= '<td><span class="grade grade-' . htmlspecialchars($score['grade']) . '">' . htmlspecialchars($score['grade']) . '</span></td>';
        $html .= '</tr>';
    }
}
$html .= '</tbody></table>';

$html .= '<div class="average-box"><div class="lbl">Term Average</div><div class="value">' . $average . '%</div></div>';
$html .= '</body></html>';

logDownload($school_id, (string)$parent_id, 'report_card', $child_id);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $filename = $student['first_name'] . '_' . $student['last_name'] . '_Report_Card.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

echo $html . '<script>window.print();</script>';