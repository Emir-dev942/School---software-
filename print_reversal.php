<?php
/**
 * school_owner/print_reversal.php — OPay/thermal style reversal notice
 * Usage: print_reversal.php?reversal_id=1    ← NEW: uses reversal_id
 *        print_reversal.php?payment_id=42    ← OLD: shows latest reversal for this payment
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$reversal_id = (int)($_GET['reversal_id'] ?? 0);
$payment_id  = (int)($_GET['payment_id'] ?? 0);

if ($reversal_id <= 0 && $payment_id <= 0) { http_response_code(400); die('Invalid ID'); }

// Fetch reversal record
if ($reversal_id > 0) {
    $stmt = $db->prepare("
        SELECT pr.*, ph.amount_paid AS original_amount, ph.receipt_number, ph.payment_method, ph.payment_date,
               i.total_amount AS invoice_total, i.paid_amount AS invoice_paid,
               i.term_name, i.session_year,
               s.first_name, s.last_name, s.student_id AS student_code,
               s.parent_name, s.parent_phone,
               c.name AS class_name
        FROM payment_reversals pr
        JOIN payment_history ph ON pr.payment_id = ph.id
        JOIN invoices i ON ph.invoice_id = i.id
        JOIN students s ON i.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE pr.id = ? AND i.school_id = ?
    ");
    $stmt->execute([$reversal_id, $school_id]);
} else {
    $stmt = $db->prepare("
        SELECT pr.*, ph.amount_paid AS original_amount, ph.receipt_number, ph.payment_method, ph.payment_date,
               i.total_amount AS invoice_total, i.paid_amount AS invoice_paid,
               i.term_name, i.session_year,
               s.first_name, s.last_name, s.student_id AS student_code,
               s.parent_name, s.parent_phone,
               c.name AS class_name
        FROM payment_reversals pr
        JOIN payment_history ph ON pr.payment_id = ph.id
        JOIN invoices i ON ph.invoice_id = i.id
        JOIN students s ON i.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE pr.payment_id = ? AND i.school_id = ?
        ORDER BY pr.id DESC
        LIMIT 1
    ");
    $stmt->execute([$payment_id, $school_id]);
}
$r = $stmt->fetch();
if (!$r) { http_response_code(404); die('Reversal record not found'); }

$schoolStmt = $db->prepare("SELECT school_name, address, phone, email, logo_path, primary_color, slogan FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch() ?: [];
$school_name = $school['school_name'] ?? 'School';
$school_address = $school['address'] ?? '';
$school_phone = $school['phone'] ?? '';
$school_logo = $school['logo_path'] ?? '';
$school_slogan = $school['slogan'] ?? '';
$primary = $school['primary_color'] ?? '#7c3aed';

$logo_url = '';
if ($school_logo && $school_logo !== 'default_logo.png') {
    $logo_url = BASE_URL . 'uploads/school_logos/' . $school_logo;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reversal <?php echo htmlspecialchars($r['receipt_number']); ?></title>
<style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: 'Courier New', Consolas, monospace; background: #f0f0f0; margin: 0; padding: 20px; color: #000; }
    .no-print { text-align: center; margin-bottom: 20px; }
    .no-print button { padding: 12px 24px; font-size: 15px; font-weight: 700; border: none; border-radius: 8px; background: #dc2626; color: #fff; cursor: pointer; margin: 0 6px; }
    .no-print a { padding: 12px 24px; font-size: 15px; font-weight: 600; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #334155; text-decoration: none; margin: 0 6px; display: inline-block; }

    .receipt { width: 300px; margin: 0 auto; background: #fff; padding: 20px 16px; font-size: 12px; line-height: 1.6; color: #000; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 2px solid #dc2626; }
    .receipt * { color: #000 !important; }

    .logo { display: block; margin: 0 auto 8px; max-width: 60px; max-height: 60px; object-fit: contain; }
    .school-name { text-align: center; font-size: 15px; font-weight: 900; letter-spacing: 0.5px; text-transform: uppercase; margin: 0 0 4px; color: #dc2626 !important; }
    .school-info { text-align: center; font-size: 10px; color: #333; line-height: 1.4; }

    .divider { text-align: center; font-size: 10px; letter-spacing: 1px; margin: 10px 0; overflow: hidden; }
    .divider::before { content: '--------------------------------'; }

    .title { text-align: center; font-size: 13px; font-weight: 900; letter-spacing: 1px; padding: 8px 0; margin: 8px 0; text-transform: uppercase; color: #dc2626 !important; background: #fee2e2; }

    .stamp { position: absolute; top: 30%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); border: 3px solid #c00; color: #c00 !important; font-size: 20px; font-weight: 900; letter-spacing: 2px; padding: 8px 16px; border-radius: 6px; opacity: 0.85; z-index: 10; }

    .row { display: flex; justify-content: space-between; font-size: 11px; padding: 2px 0; gap: 8px; }
    .row .label { color: #333; }
    .row .value { font-weight: 700; text-align: right; word-break: break-word; }

    .amount-block { text-align: center; padding: 12px 0; background: #fef2f2; margin: 8px 0; border-radius: 4px; }
    .amount-block .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #dc2626 !important; margin-bottom: 4px; font-weight: 800; }
    .amount-block .amt { font-size: 24px; font-weight: 900; color: #dc2626 !important; letter-spacing: 0.5px; line-height: 1.1; }

    .reason-box { background: #fffbeb; border-left: 3px solid #f59e0b; padding: 8px 10px; font-size: 10px; line-height: 1.4; margin: 8px 0; border-radius: 3px; }
    .reason-box strong { display: block; margin-bottom: 3px; }

    .signature { margin-top: 30px; padding-top: 8px; font-size: 10px; text-align: center; }
    .signature .line { border-top: 1px solid #000; width: 120px; margin: 0 auto 3px; padding-top: 4px; }

    .footer { text-align: center; font-size: 9px; color: #333; margin-top: 16px; line-height: 1.5; }

    @page { size: 80mm auto; margin: 0; }
    @media print {
        body { background: #fff; padding: 0; margin: 0; }
        .no-print { display: none !important; }
        .receipt { width: 80mm; padding: 5mm 3mm; box-shadow: none; font-size: 11px; }
    }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">🖨️ Print Reversal</button>
    <a href="javascript:window.close()">Close</a>
</div>

<div class="receipt">

    <div class="stamp">REVERSED</div>

    <?php if ($logo_url): ?>
        <img class="logo" src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo">
    <?php endif; ?>
    <div class="school-name" style="color:#dc2626;"><?php echo htmlspecialchars($school_name); ?></div>
    <?php if ($school_address): ?><div class="school-info"><?php echo htmlspecialchars($school_address); ?></div><?php endif; ?>
    <?php if ($school_phone): ?><div class="school-info">Tel: <?php echo htmlspecialchars($school_phone); ?></div><?php endif; ?>

    <div class="divider"></div>
    <div class="title">Payment Reversal</div>
    <div class="divider"></div>

    <div class="row"><span class="label">Original Receipt:</span><span class="value"><?php echo htmlspecialchars($r['receipt_number']); ?></span></div>
    <div class="row"><span class="label">Original Date:</span><span class="value"><?php echo date('d/m/Y H:i', strtotime($r['payment_date'])); ?></span></div>
    <div class="row"><span class="label">Reversed On:</span><span class="value"><?php echo date('d/m/Y H:i', strtotime($r['reversed_at'])); ?></span></div>

    <div class="divider"></div>

    <div class="row"><span class="label">Student:</span><span class="value"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></span></div>
    <div class="row"><span class="label">Class:</span><span class="value"><?php echo htmlspecialchars($r['class_name'] ?? 'N/A'); ?></span></div>
    <div class="row"><span class="label">Term:</span><span class="value"><?php echo htmlspecialchars($r['term_name'] . ' ' . $r['session_year']); ?></span></div>

    <div class="divider"></div>

    <div class="amount-block">
        <div class="lbl">Amount Reversed</div>
        <div class="amt">&#8358;<?php echo number_format((float)$r['amount_reversed'], 2); ?></div>
    </div>

    <div class="row"><span class="label">Original Amount:</span><span class="value">&#8358;<?php echo number_format((float)$r['original_amount'], 2); ?></span></div>

    <div class="divider"></div>

    <div class="reason-box">
        <strong>Reason:</strong>
        <?php echo htmlspecialchars($r['reason'] ?: 'Not specified'); ?>
    </div>

    <div class="divider"></div>

    <div class="signature">
        <div class="line">Authorized by</div>
    </div>

    <div class="footer">
        This payment has been reversed.<br>
        Original receipt <?php echo htmlspecialchars($r['receipt_number']); ?> is now void for this amount.<br>
        Keep this notice for your records.
    </div>

</div>

<script>
    if (window.location.search.indexOf('auto_print=1') !== -1) {
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
    }
</script>
</body>
</html>