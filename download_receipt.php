<?php
/**
 * parent/download_receipt.php
 * Fixes: removed 'reversed=0' filter, shows REVERSED watermark, unified auth.
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

$payment_id = (int)($_GET['payment_id'] ?? 0);
$child_id   = (int)($_GET['child_id'] ?? 0);

if ($payment_id <= 0 || $child_id <= 0) parentAbort(400, 'Invalid request.');
if (!parentOwnsChild($parent_id, $school_id, $child_id)) parentAbort(403, 'You do not have access to this receipt.');

$stmt = $db->prepare("
    SELECT ph.*, i.term_name, i.session_year, i.total_amount AS invoice_total, i.paid_amount AS invoice_paid,
           s.first_name, s.last_name, s.student_id, s.parent_name, s.parent_phone,
           c.name AS class_name
    FROM payment_history ph
    JOIN invoices i ON ph.invoice_id = i.id
    JOIN students s ON i.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE ph.id = ? AND i.school_id = ? AND i.student_id = ?
");
$stmt->execute([$payment_id, $school_id, $child_id]);
$payment = $stmt->fetch();
if (!$payment) parentAbort(404, 'Receipt not found.');

$schoolStmt = $db->prepare("SELECT school_name, logo_path, address, phone, slogan, primary_color FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch() ?: [];

$logo_html = '';
if (!empty($school['logo_path']) && $school['logo_path'] !== 'default_logo.png') {
    $logo_file = __DIR__ . '/../uploads/school_logos/' . $school['logo_path'];
    if (file_exists($logo_file)) {
        $ext = strtolower(pathinfo($logo_file, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
        $logo_html = '<img src="data:' . $mime . ';base64,' . base64_encode(file_get_contents($logo_file)) . '" style="max-height:70px;display:block;margin:0 auto 12px;">';
    }
}

$primary = $school['primary_color'] ?? '#7c3aed';
$is_reversed = !empty($payment['reversed']);

// Reversals (partial or full)
$revStmt = $db->prepare("SELECT COALESCE(SUM(amount_reversed), 0) AS total_reversed FROM payment_reversals WHERE payment_id = ?");
$revStmt->execute([$payment_id]);
$already_reversed = (float)$revStmt->fetchColumn();

$amount_words = nairaInWords((float)$payment['amount_paid']);
$balance = (float)$payment['invoice_total'] - (float)$payment['invoice_paid'];

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    @page { size: 80mm auto; margin: 0; }
    body { font-family: "Courier New", monospace; margin: 0; padding: 20px; background: #f0f0f0; }
    .receipt { width: 300px; margin: 0 auto; background: white; padding: 20px 16px; font-size: 12px; line-height: 1.6; color: #000; position: relative; border: 1px solid #ccc; }
    .logo { display: block; margin: 0 auto 8px; max-width: 60px; max-height: 60px; }
    .school-name { text-align: center; font-size: 15px; font-weight: 900; text-transform: uppercase; margin: 0 0 4px; }
    .school-info { text-align: center; font-size: 10px; color: #333; line-height: 1.4; }
    .divider { text-align: center; font-size: 10px; letter-spacing: 1px; margin: 10px 0; overflow: hidden; }
    .divider::before { content: "--------------------------------"; }
    .title { text-align: center; font-size: 12px; font-weight: 900; letter-spacing: 1px; padding: 6px 0; margin: 6px 0; text-transform: uppercase; }
    .row { display: flex; justify-content: space-between; font-size: 11px; padding: 2px 0; gap: 8px; }
    .row .label { color: #333; }
    .row .value { font-weight: 700; text-align: right; }
    .amount-block { text-align: center; padding: 12px 0; }
    .amount-block .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #333; margin-bottom: 4px; }
    .amount-block .amt { font-size: 26px; font-weight: 900; line-height: 1.1; }
    .amount-block .words { font-size: 9px; font-style: italic; color: #444; margin-top: 6px; }
    .balance-row { display: flex; justify-content: space-between; font-size: 11px; padding: 3px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; margin: 8px 0; }
    .stamp { position: absolute; top: 30%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); border: 3px solid #c00; color: #c00; font-size: 22px; font-weight: 900; letter-spacing: 2px; padding: 8px 16px; border-radius: 6px; opacity: 0.85; z-index: 10; }
    .footer { text-align: center; font-size: 9px; color: #333; margin-top: 16px; line-height: 1.5; }
    .thank-you { text-align: center; font-size: 13px; font-weight: 800; margin: 12px 0 4px; letter-spacing: 1px; }
</style></head><body>';

$html .= '<div class="receipt">';
if ($is_reversed) $html .= '<div class="stamp">REVERSED</div>';
elseif ($already_reversed > 0) $html .= '<div class="stamp" style="border-color:#d97706;color:#d97706;font-size:16px;">PARTIAL</div>';

$html .= $logo_html;
$html .= '<div class="school-name">' . htmlspecialchars($school['school_name'] ?? 'School') . '</div>';
if (!empty($school['address'])) $html .= '<div class="school-info">' . htmlspecialchars($school['address']) . '</div>';
if (!empty($school['phone'])) $html .= '<div class="school-info">Tel: ' . htmlspecialchars($school['phone']) . '</div>';

$html .= '<div class="divider"></div><div class="title">FEE PAYMENT RECEIPT</div><div class="divider"></div>';

$html .= '<div class="row"><span class="label">Receipt No:</span><span class="value">' . htmlspecialchars($payment['receipt_number']) . '</span></div>';
$html .= '<div class="row"><span class="label">Date:</span><span class="value">' . date('d/m/Y H:i', strtotime($payment['payment_date'])) . '</span></div>';
$html .= '<div class="row"><span class="label">Method:</span><span class="value">' . htmlspecialchars($payment['payment_method']) . '</span></div>';

$html .= '<div class="divider"></div>';
$html .= '<div class="row"><span class="label">Student:</span><span class="value">' . htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']) . '</span></div>';
$html .= '<div class="row"><span class="label">Class:</span><span class="value">' . htmlspecialchars($payment['class_name']) . '</span></div>';
$html .= '<div class="row"><span class="label">Term:</span><span class="value">' . htmlspecialchars($payment['term_name'] . ' ' . $payment['session_year']) . '</span></div>';

$html .= '<div class="divider"></div>';
$html .= '<div class="amount-block"><div class="lbl">Amount Paid</div><div class="amt">&#8358;' . number_format((float)$payment['amount_paid'], 2) . '</div>';
$html .= '<div class="words">' . htmlspecialchars($amount_words) . '</div></div>';

$html .= '<div class="divider"></div>';
$html .= '<div class="balance-row"><span class="label">Total Fee:</span><span class="value">&#8358;' . number_format((float)$payment['invoice_total'], 2) . '</span></div>';
$html .= '<div class="balance-row"><span class="label">Paid to Date:</span><span class="value">&#8358;' . number_format((float)$payment['invoice_paid'], 2) . '</span></div>';
$html .= '<div class="balance-row"><span class="label">Balance:</span><span class="value">&#8358;' . number_format($balance, 2) . '</span></div>';

if ($already_reversed > 0) {
    $html .= '<div class="divider"></div>';
    $html .= '<div class="row"><span class="label">Reversed:</span><span class="value">&#8358;' . number_format($already_reversed, 2) . '</span></div>';
}

$html .= '<div class="divider"></div>';
$html .= '<div class="thank-you">THANK YOU!</div>';
$html .= '<div class="footer">Please keep this receipt for your records.<br>Computer-generated receipt.</div>';
$html .= '</div></body></html>';

logDownload($school_id, (string)$parent_id, 'receipt', $payment_id);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper([0, 0, 226.77, 800], 'portrait'); // 80mm width
    $dompdf->render();
    $filename = 'Receipt_' . $payment['receipt_number'] . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

echo $html . '<script>window.print();</script>';