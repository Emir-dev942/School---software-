<?php
// school_owner/student_timeline_print.php - Printable summary of all terms for a student
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/archive_functions.php';

requireRole(['Owner', 'Principal']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$student_id = (int)($_GET['student_id'] ?? 0);
if ($student_id <= 0) die("Invalid student ID.");

$stmt = $db->prepare("
    SELECT s.*, c.name AS class_name
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();
if (!$student) die("Student not found.");

$schoolStmt = $db->prepare("SELECT school_name, address, phone, email, slogan, logo_path FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();

$logo_path = (!empty($school['logo_path']) && $school['logo_path'] !== 'default_logo.png')
    ? BASE_URL . 'uploads/school_logos/' . $school['logo_path']
    : null;

$terms = getAllStudentTerms($student_id, $school_id);
$summaries = [];
foreach ($terms as $t) {
    $summaries[] = getStudentTermSummary($student_id, $school_id, $t['term_name'], $t['session_year']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Student Timeline — <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #0f172a; padding: 20px; font-size: 13px; }
    .sheet { max-width: 850px; margin: 0 auto; background: #fff; padding: 36px; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }

    .header { display: flex; align-items: center; gap: 18px; border-bottom: 3px double #1e293b; padding-bottom: 14px; margin-bottom: 20px; }
    .header img { width: 80px; height: 80px; object-fit: contain; }
    .header .school-name { font-size: 22px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
    .header .school-meta { font-size: 11.5px; color: #475569; margin-top: 3px; }
    .header .slogan { font-style: italic; font-size: 12px; color: #7c3aed; margin-top: 3px; }

    .title-bar { text-align: center; font-size: 14px; font-weight: 700; background: #1e293b; color: #fff; padding: 8px; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 20px; }

    .student-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 30px; margin-bottom: 20px; font-size: 13px; }
    .student-info .lbl { color: #64748b; }
    .student-info .val { font-weight: 600; }

    table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 20px; }
    th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
    th { background: #f8fafc; font-weight: 700; color: #334155; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; }
    td.num { text-align: right; font-family: 'Courier New', monospace; }
    tr:nth-child(even) td { background: #fafafa; }

    .footer { text-align: center; font-size: 10px; color: #94a3b8; margin-top: 20px; padding-top: 12px; border-top: 1px solid #e2e8f0; }

    .toolbar { text-align: center; margin-bottom: 16px; }
    .toolbar button {
        padding: 10px 32px; font-size: 14px; font-weight: 600;
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        color: #fff; border: none; border-radius: 8px; cursor: pointer;
    }

    @page { size: A4; margin: 12mm; }
    @media print {
        body { background: #fff; padding: 0; }
        .sheet { box-shadow: none; padding: 0; }
        .toolbar { display: none !important; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <button onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<div class="sheet">

    <div class="header">
        <?php if ($logo_path): ?>
            <img src="<?php echo $logo_path; ?>" alt="Logo">
        <?php endif; ?>
        <div style="flex:1;">
            <div class="school-name"><?php echo htmlspecialchars($school['school_name']); ?></div>
            <?php if (!empty($school['address'])): ?><div class="school-meta"><?php echo htmlspecialchars($school['address']); ?></div><?php endif; ?>
            <div class="school-meta">
                <?php if (!empty($school['phone'])) echo htmlspecialchars($school['phone']); ?>
                <?php if (!empty($school['email'])) echo ' • ' . htmlspecialchars($school['email']); ?>
            </div>
            <?php if (!empty($school['slogan'])): ?><div class="slogan">"<?php echo htmlspecialchars($school['slogan']); ?>"</div><?php endif; ?>
        </div>
    </div>

    <div class="title-bar">Student Academic History</div>

    <div class="student-info">
        <div><span class="lbl">Student Name:</span> <span class="val"><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?></span></div>
        <div><span class="lbl">Student ID:</span> <span class="val"><?php echo htmlspecialchars($student['student_id']); ?></span></div>
        <div><span class="lbl">Current Class:</span> <span class="val"><?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?></span></div>
        <div><span class="lbl">Gender:</span> <span class="val"><?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></span></div>
        <div><span class="lbl">Parent Name:</span> <span class="val"><?php echo htmlspecialchars($student['parent_name'] ?? 'N/A'); ?></span></div>
        <div><span class="lbl">Parent Phone:</span> <span class="val"><?php echo htmlspecialchars($student['parent_phone'] ?? 'N/A'); ?></span></div>
        <div><span class="lbl">Admission Date:</span> <span class="val"><?php echo !empty($student['admission_date']) ? date('d M Y', strtotime($student['admission_date'])) : 'N/A'; ?></span></div>
        <div><span class="lbl">Status:</span> <span class="val"><?php echo htmlspecialchars($student['status']); ?></span></div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:140px;">Term</th>
                <th style="width:100px;">Session</th>
                <th>Subjects</th>
                <th>Average</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Late</th>
                <th>Fee Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($summaries)): ?>
                <tr><td colspan="8" style="text-align:center; color:#94a3b8;">No academic records found.</td></tr>
            <?php else: foreach ($summaries as $s): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($s['term_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($s['session_year']); ?></td>
                    <td class="num"><?php echo $s['subject_count']; ?></td>
                    <td class="num"><?php echo $s['has_scores'] ? $s['average'] . '%' : '—'; ?></td>
                    <td class="num"><?php echo $s['present']; ?></td>
                    <td class="num"><?php echo $s['absent']; ?></td>
                    <td class="num"><?php echo $s['late']; ?></td>
                    <td class="num"><?php echo $s['balance'] > 0 ? '₦' . number_format($s['balance']) : 'Paid'; ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="footer">
        Generated on <?php echo date('d M Y g:i A'); ?> • <?php echo htmlspecialchars($school['school_name']); ?>
    </div>

</div>

<script>
    // Auto-print if ?print=1
    if (window.location.search.indexOf('print=1') !== -1) {
        window.print();
    }
</script>

</body>
</html>