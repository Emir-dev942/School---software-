<?php
// school_owner/student_print.php - Clean Printable Student Profile
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/student_functions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$student_id = (int)($_GET['id'] ?? 0);
if ($student_id <= 0) die("Invalid student ID.");

$student = getStudent($student_id, $school_id);
if (!$student) die("Student not found.");

$schoolStmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch();

$current_term = $schoolInfo['current_term'] ?? 'Term 1';
$current_session = $schoolInfo['current_session'] ?? '';

$fees = getStudentFees($student_id, $school_id, $current_term, $current_session);
$attendance = getStudentAttendance($student_id, $school_id, $current_term, $current_session);
$scores = getStudentScores($student_id, $school_id, $current_term, $current_session);

$logo = $schoolInfo['logo_path'] && $schoolInfo['logo_path'] !== 'default_logo.png'
    ? BASE_URL . 'uploads/school_logos/' . $schoolInfo['logo_path']
    : null;

$photo_url = ($student['photo_path'] && $student['photo_path'] !== 'default_student.png')
    ? BASE_URL . 'uploads/student_photos/' . $student['photo_path']
    : BASE_URL . 'assets/images/default_avatar.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Student Profile - <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; color: #0f172a; margin: 0; padding: 24px; background: #f1f5f9; }
    .sheet { max-width: 800px; margin: 0 auto; background: white; padding: 40px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .school-header { text-align: center; border-bottom: 3px solid #7c3aed; padding-bottom: 16px; margin-bottom: 24px; }
    .school-header h1 { margin: 0; font-size: 24px; color: #7c3aed; }
    .school-header p { margin: 4px 0; font-size: 13px; color: #64748b; }
    .student-row { display: flex; gap: 24px; align-items: center; padding: 16px; background: #f8fafc; border-radius: 12px; margin-bottom: 24px; }
    .student-row img { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #7c3aed; }
    .student-row .name { font-size: 22px; font-weight: 700; }
    .student-row .meta { color: #64748b; font-size: 14px; margin-top: 4px; }
    h2 { font-size: 16px; font-weight: 700; color: #7c3aed; margin: 20px 0 10px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    table th { background: #f8fafc; padding: 8px; text-align: left; font-weight: 700; border: 1px solid #e2e8f0; }
    table td { padding: 8px; border: 1px solid #e2e8f0; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; font-size: 13px; }
    .info-grid div { padding: 4px 0; }
    .info-grid strong { color: #64748b; display: inline-block; min-width: 140px; }
    .no-print { text-align: center; margin: 20px 0; }
    .no-print button { background: #7c3aed; color: white; border: none; padding: 12px 32px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #e2e8f0; text-align: center; font-size: 11px; color: #94a3b8; }
    @media print {
        body { background: white; padding: 0; }
        .sheet { box-shadow: none; padding: 20px; max-width: 100%; }
        .no-print { display: none; }
    }
</style>
</head>
<body>
<div class="sheet">

    <div class="no-print">
        <button onclick="window.print()">🖨️ Print This Profile</button>
    </div>

    <div class="school-header">
        <h1><?php echo htmlspecialchars($schoolInfo['school_name']); ?></h1>
        <p><?php echo htmlspecialchars($schoolInfo['address'] ?? ''); ?></p>
        <p><?php echo htmlspecialchars($schoolInfo['phone'] ?? ''); ?> • <?php echo htmlspecialchars($schoolInfo['email'] ?? ''); ?></p>
    </div>

    <div class="student-row">
        <img src="<?php echo $photo_url; ?>" alt="Photo">
        <div>
            <div class="name"><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?></div>
            <div class="meta">
                ID: <strong><?php echo htmlspecialchars($student['student_id']); ?></strong> • 
                Class: <strong><?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?></strong>
            </div>
            <div class="meta">Status: <?php echo htmlspecialchars($student['status']); ?> • Term: <?php echo htmlspecialchars($current_term . ' ' . $current_session); ?></div>
        </div>
    </div>

    <h2>Student Information</h2>
    <div class="info-grid">
        <div><strong>Full Name:</strong> <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?></div>
        <div><strong>Gender:</strong> <?php echo htmlspecialchars($student['gender'] ?? 'N/A'); ?></div>
        <div><strong>Date of Birth:</strong> <?php echo !empty($student['date_of_birth']) ? date('M d, Y', strtotime($student['date_of_birth'])) : 'N/A'; ?></div>
        <div><strong>Admission Date:</strong> <?php echo !empty($student['admission_date']) ? date('M d, Y', strtotime($student['admission_date'])) : 'N/A'; ?></div>
    </div>

    <h2>Parent / Guardian</h2>
    <div class="info-grid">
        <div><strong>Name:</strong> <?php echo htmlspecialchars($student['parent_name'] ?? 'N/A'); ?></div>
        <div><strong>Phone:</strong> <?php echo htmlspecialchars($student['parent_phone'] ?? 'N/A'); ?></div>
        <div><strong>Email:</strong> <?php echo htmlspecialchars($student['parent_email'] ?? 'N/A'); ?></div>
        <div><strong>Occupation:</strong> <?php echo htmlspecialchars($student['parent_occupation'] ?? 'N/A'); ?></div>
        <div style="grid-column: span 2;"><strong>Address:</strong> <?php echo htmlspecialchars($student['address'] ?? 'N/A'); ?></div>
    </div>

    <?php if (!empty($student['medical_notes'])): ?>
        <h2>Medical Notes</h2>
        <p style="font-size:13px;"><?php echo nl2br(htmlspecialchars($student['medical_notes'])); ?></p>
    <?php endif; ?>

    <h2>Fees (<?php echo $current_term . ' ' . $current_session; ?>)</h2>
    <?php if ($fees['invoice']): ?>
        <table>
            <tr><th>Total Fee</th><th>Amount Paid</th><th>Balance</th><th>Status</th></tr>
            <tr>
                <td><?php echo formatCurrency($fees['invoice']['total_amount']); ?></td>
                <td><?php echo formatCurrency($fees['invoice']['paid_amount']); ?></td>
                <td><strong><?php echo formatCurrency($fees['balance']); ?></strong></td>
                <td><?php echo ucfirst($fees['invoice']['status']); ?></td>
            </tr>
        </table>
        <?php if (!empty($fees['payments'])): ?>
            <h2>Payment History</h2>
            <table>
                <tr><th>Date</th><th>Amount</th><th>Method</th><th>Receipt</th></tr>
                <?php foreach ($fees['payments'] as $p): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($p['payment_date'])); ?></td>
                        <td><?php echo formatCurrency($p['amount_paid']); ?></td>
                        <td><?php echo htmlspecialchars($p['payment_method']); ?></td>
                        <td><?php echo htmlspecialchars($p['receipt_number']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php else: ?>
        <p style="font-size:13px;color:#64748b;">No invoice for this term.</p>
    <?php endif; ?>

    <h2>Attendance (<?php echo $current_term . ' ' . $current_session; ?>)</h2>
    <table>
        <tr><th>Present</th><th>Absent</th><th>Late</th></tr>
        <tr>
            <td><?php echo $attendance['summary']['Present']; ?></td>
            <td><?php echo $attendance['summary']['Absent']; ?></td>
            <td><?php echo $attendance['summary']['Late']; ?></td>
        </tr>
    </table>

    <?php if (!empty($scores['scores'])): ?>
        <h2>Scores (<?php echo $current_term . ' ' . $current_session; ?>)</h2>
        <table>
            <tr><th>Subject</th><th>CA1</th><th>CA2</th><th>CA3</th><th>Exam</th><th>Total</th><th>Grade</th></tr>
            <?php foreach ($scores['scores'] as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                    <td><?php echo number_format($s['ca1'], 1); ?></td>
                    <td><?php echo number_format($s['ca2'], 1); ?></td>
                    <td><?php echo number_format($s['ca3'], 1); ?></td>
                    <td><?php echo number_format($s['exam'], 1); ?></td>
                    <td><strong><?php echo number_format($s['total'], 1); ?></strong></td>
                    <td><?php echo $s['grade'] ?: '-'; ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><th colspan="5">Average</th><th colspan="2"><?php echo $scores['average']; ?>%</th></tr>
        </table>
    <?php endif; ?>

    <div class="footer">
        Printed on <?php echo date('M d, Y g:i A'); ?> • DigitalSchool
    </div>

</div>

<script>
// Auto-open print dialog if ?print=1
if (window.location.search.indexOf('print=1') !== -1) {
    window.print();
}
</script>
</body>
</html>