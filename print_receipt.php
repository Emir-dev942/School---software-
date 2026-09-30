<?php
/**
 * school_owner/print_receipt.php — OPay/thermal style
 *
 * Narrow column receipt (~80mm) that prints on:
 *   - Thermal POS printer (perfect fit)
 *   - Regular A4 (centered column with whitespace on sides)
 *
 * Usage: print_receipt.php?payment_id=123
 *        print_receipt.php?payment_id=123&auto_print=1
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$payment_id = (int)($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) { http_response_code(400); die('Invalid payment ID'); }

$stmt = $db->prepare("
    SELECT ph.*,
           i.total_amount AS invoice_total,
           i.paid_amount  AS invoice_paid,
           i.term_name, i.session_year,
           s.first_name, s.last_name, s.student_id AS student_code,
           s.parent_name, s.parent_phone,
           c.name AS class_name
    FROM payment_history ph
    JOIN invoices i ON ph.invoice_id = i.id
    JOIN students s ON i.student_id = s.id
    LEFT JOIN classes c ON s.class_id = c.id
    WHERE ph.id = ? AND i.school_id = ?
");
$stmt->execute([$payment_id, $school_id]);
$p = $stmt->fetch();
if (!$p) { http_response_code(404); die('Payment not found'); }

// Partial reversal handling — check if any reversal exists for this payment
$revStmt = $db->prepare("SELECT COALESCE(SUM(amount_reversed), 0) AS total_reversed FROM payment_reversals WHERE payment_id = ?");
$revStmt->execute([$payment_id]);
$already_reversed = (float)$revStmt->fetchColumn();

$net_amount = (float)$p['amount_paid'] - $already_reversed;

// School info
$schoolStmt = $db->prepare("SELECT school_name, address, phone, email, logo_path, primary_color, slogan FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch() ?: [];
$school_name = $school['school_name'] ?? 'School';
$school_address = $school['address'] ?? '';
$school_phone = $school['phone'] ?? '';
$school_email = $school['email'] ?? '';
$school_logo = $school['logo_path'] ?? '';
$school_slogan = $school['slogan'] ?? '';
$primary = $school['primary_color'] ?? '#7c3aed';

$logo_url = '';
if ($school_logo && $school_logo !== 'default_logo.png') {
    $logo_url = BASE_URL . 'uploads/school_logos/' . $school_logo;
}

$balance = (float)$p['invoice_total'] - (float)$p['invoice_paid'];

// Amount in words
function number_to_words($n) {
    if ($n < 0) return 'minus ' . number_to_words(-$n);
    $ones = ['','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
    $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    if ($n < 20) return $ones[$n];
    if ($n < 100) return $tens[(int)($n/10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
    if ($n < 1000) return $ones[(int)($n/100)] . ' hundred' . ($n % 100 ? ' and ' . number_to_words($n % 100) : '');
    if ($n < 1000000) return number_to_words((int)($n/1000)) . ' thousand' . ($n % 1000 ? ' ' . number_to_words($n % 1000) : '');
    if ($n < 1000000000) return number_to_words((int)($n/1000000)) . ' million' . ($n % 1000000 ? ' ' . number_to_words($n % 1000000) : '');
    return number_to_words((int)($n/1000000000)) . ' billion' . ($n % 1000000000 ? ' ' . number_to_words($n % 1000000000) : '');
}
function amount_in_words($amount) {
    $amount = (float)$amount;
    $naira = (int)floor($amount);
    $kobo = (int)round(($amount - $naira) * 100);
    $words = ucfirst(number_to_words($naira)) . ' Naira';
    if ($kobo > 0) $words .= ' and ' . number_to_words($kobo) . ' Kobo';
    return $words . ' Only';
}

$amount_words = amount_in_words((float)$p['amount_paid']);
$is_fully_reversed = !empty($p['reversed']);
$is_partially_reversed = ($already_reversed > 0 && !$is_fully_reversed);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt <?php echo htmlspecialchars($p['receipt_number']); ?></title>
<style>
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body {
        font-family: 'Courier New', Consolas, monospace;
        background: #f0f0f0;
        margin: 0;
        padding: 20px;
        color: #000;
    }

    /* On-screen controls */
    .no-print {
        text-align: center;
        margin-bottom: 20px;
    }
    .no-print button {
        padding: 12px 24px;
        font-size: 15px;
        font-weight: 700;
        border: none;
        border-radius: 8px;
        background: <?php echo htmlspecialchars($primary); ?>;
        color: #fff;
        cursor: pointer;
        margin: 0 6px;
    }
    .no-print a {
        padding: 12px 24px;
        font-size: 15px;
        font-weight: 600;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #fff;
        color: #334155;
        text-decoration: none;
        margin: 0 6px;
        display: inline-block;
    }

    /* Receipt — OPay thermal column */
    .receipt {
        width: 300px;             /* ~80mm at 96dpi */
        margin: 0 auto;
        background: #fff;
        padding: 20px 16px;
        font-size: 12px;
        line-height: 1.6;
        color: #000;
        position: relative;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }

    .receipt * {
        color: #000 !important;
    }

    /* Header */
    .logo {
        display: block;
        margin: 0 auto 8px;
        max-width: 60px;
        max-height: 60px;
        object-fit: contain;
    }
    .school-name {
        text-align: center;
        font-size: 15px;
        font-weight: 900;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        margin: 0 0 4px;
        line-height: 1.2;
    }
    .school-info {
        text-align: center;
        font-size: 10px;
        color: #333;
        line-height: 1.4;
        margin-bottom: 4px;
    }
    .school-slogan {
        text-align: center;
        font-size: 9px;
        font-style: italic;
        color: #666;
        margin-bottom: 12px;
    }

    /* Dividers */
    .divider {
        text-align: center;
        font-size: 10px;
        letter-spacing: 1px;
        color: #000;
        margin: 10px 0;
        overflow: hidden;
    }
    .divider::before { content: '--------------------------------'; }

    /* Title */
    .title {
        text-align: center;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: 1px;
        padding: 6px 0;
        margin: 6px 0;
        text-transform: uppercase;
    }

    /* Meta */
    .row {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        padding: 2px 0;
        gap: 8px;
    }
    .row .label { color: #333; }
    .row .value { font-weight: 700; text-align: right; word-break: break-word; }

    /* Big amount */
    .amount-block {
        text-align: center;
        padding: 12px 0;
    }
    .amount-block .lbl {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #333;
        margin-bottom: 4px;
    }
    .amount-block .amt {
        font-size: 26px;
        font-weight: 900;
        letter-spacing: 0.5px;
        line-height: 1.1;
    }
    .amount-block .words {
        font-size: 9px;
        font-style: italic;
        color: #444;
        margin-top: 6px;
        line-height: 1.3;
    }

    /* Balance box */
    .balance-row {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        padding: 3px 0;
        border-top: 1px dashed #000;
        border-bottom: 1px dashed #000;
        margin: 8px 0;
    }
    .balance-row .label { color: #333; }
    .balance-row .value { font-weight: 700; }

    /* Signatures */
    .signature {
        margin-top: 30px;
        padding-top: 8px;
        font-size: 10px;
        text-align: center;
    }
    .signature .line {
        border-top: 1px solid #000;
        width: 120px;
        margin: 0 auto 3px;
        padding-top: 4px;
    }

    /* Reversed stamp */
    .stamp {
        position: absolute;
        top: 30%;
        left: 50%;
        transform: translate(-50%, -50%) rotate(-18deg);
        border: 3px solid #c00;
        color: #c00 !important;
        font-size: 22px;
        font-weight: 900;
        letter-spacing: 2px;
        padding: 8px 16px;
        border-radius: 6px;
        opacity: 0.85;
        z-index: 10;
    }
    .stamp.partial {
        border-color: #d97706;
        color: #d97706 !important;
        font-size: 16px;
    }

    /* Footer */
    .footer {
        text-align: center;
        font-size: 9px;
        color: #333;
        margin-top: 16px;
        line-height: 1.5;
    }
    .thank-you {
        text-align: center;
        font-size: 13px;
        font-weight: 800;
        margin: 12px 0 4px;
        letter-spacing: 1px;
    }

    /* Print rules */
    @page {
        size: 80mm auto;
        margin: 0;
    }
    @media print {
        body { background: #fff; padding: 0; margin: 0; }
        .no-print { display: none !important; }
        .receipt {
            width: 80mm;
            padding: 5mm 3mm;
            box-shadow: none;
            font-size: 11px;
        }
        .amount-block .amt { font-size: 22px; }
        .school-name { font-size: 14px; }
    }
</style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">🖨️ Print Receipt</button>
    <a href="javascript:window.close()">Close</a>
</div>

<div class="receipt">

    <?php if ($is_fully_reversed): ?>
        <div class="stamp">REVERSED</div>
    <?php elseif ($is_partially_reversed): ?>
        <div class="stamp partial">PARTIAL REVERSAL</div>
    <?php endif; ?>

    <!-- Header -->
    <?php if ($logo_url): ?>
        <img class="logo" src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo">
    <?php endif; ?>
    <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
    <?php if ($school_address): ?><div class="school-info"><?php echo htmlspecialchars($school_address); ?></div><?php endif; ?>
    <?php if ($school_phone): ?><div class="school-info">Tel: <?php echo htmlspecialchars($school_phone); ?></div><?php endif; ?>
    <?php if ($school_slogan): ?><div class="school-slogan">&ldquo;<?php echo htmlspecialchars($school_slogan); ?>&rdquo;</div><?php endif; ?>

    <div class="divider"></div>
    <div class="title">FEE PAYMENT RECEIPT</div>
    <div class="divider"></div>

    <!-- Meta -->
    <div class="row"><span class="label">Receipt No:</span><span class="value"><?php echo htmlspecialchars($p['receipt_number']); ?></span></div>
    <div class="row"><span class="label">Date:</span><span class="value"><?php echo date('d/m/Y H:i', strtotime($p['payment_date'])); ?></span></div>
    <div class="row"><span class="label">Method:</span><span class="value"><?php echo htmlspecialchars($p['payment_method']); ?></span></div>

    <div class="divider"></div>

    <!-- Student -->
    <div class="row"><span class="label">Student:</span><span class="value"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></span></div>
    <div class="row"><span class="label">Student ID:</span><span class="value"><?php echo htmlspecialchars($p['student_code']); ?></span></div>
    <div class="row"><span class="label">Class:</span><span class="value"><?php echo htmlspecialchars($p['class_name'] ?? 'N/A'); ?></span></div>
    <div class="row"><span class="label">Term:</span><span class="value"><?php echo htmlspecialchars($p['term_name'] . ' ' . $p['session_year']); ?></span></div>

    <div class="divider"></div>

    <!-- Amount -->
    <div class="amount-block">
        <div class="lbl">Amount Paid</div>
        <div class="amt">&#8358;<?php echo number_format((float)$p['amount_paid'], 2); ?></div>
        <div class="words"><?php echo htmlspecialchars($amount_words); ?></div>
    </div>

    <div class="divider"></div>

    <!-- Balance -->
    <div class="balance-row">
        <span class="label">Total Fee:</span>
        <span class="value">&#8358;<?php echo number_format((float)$p['invoice_total'], 2); ?></span>
    </div>
    <div class="balance-row">
        <span class="label">Paid to Date:</span>
        <span class="value">&#8358;<?php echo number_format((float)$p['invoice_paid'], 2); ?></span>
    </div>
    <div class="balance-row">
        <span class="label">Balance:</span>
        <span class="value">&#8358;<?php echo number_format($balance, 2); ?></span>
    </div>

    <?php if ($is_partially_reversed): ?>
        <div class="divider"></div>
        <div class="row"><span class="label">Amount Reversed:</span><span class="value">&#8358;<?php echo number_format($already_reversed, 2); ?></span></div>
        <div class="row"><span class="label">Net Amount:</span><span class="value">&#8358;<?php echo number_format($net_amount, 2); ?></span></div>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Signature -->
    <div class="signature">
        <div class="line">Received by</div>
    </div>

    <div class="thank-you">THANK YOU!</div>
    <div class="footer">
        Please keep this receipt for your records.<br>
        Computer-generated · No signature required.
    </div>

</div>

<script>
    if (window.location.search.indexOf('auto_print=1') !== -1) {
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 250);
        });
    }
</script>
</body>
</html>