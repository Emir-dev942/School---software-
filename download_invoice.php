<?php
/**
 * parent/download_invoice.php
 * Fixes: SSRF (isRemoteEnabled=false + base64 logo), unified auth, format consistency.
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

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$child_id   = (int)($_GET['child_id'] ?? 0);

if ($invoice_id <= 0 || $child_id <= 0) parentAbort(400, 'Invalid request.');
if (!parentOwnsChild($parent_id, $school_id, $child_id)) parentAbort(403, 'You do not have access to this invoice.');

// Fetch invoice
$stmt = $db->prepare("
    SELECT i.*, s.first_name, s.last_name, s.student_id, s.parent_name, s.parent_phone,
           c.name AS class_name
    FROM invoices i
    JOIN students s ON i.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE i.id = ? AND i.school_id = ? AND i.student_id = ?
");
$stmt->execute([$invoice_id, $school_id, $child_id]);
$invoice = $stmt->fetch();
if (!$invoice) parentAbort(404, 'Invoice not found.');

// Fetch school
$schoolStmt = $db->prepare("SELECT school_name, logo_path, address, phone, email, slogan, primary_color FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch() ?: [];

// Prepare logo as base64 (avoids SSRF)
$logo_html = '';
if (!empty($school['logo_path']) && $school['logo_path'] !== 'default_logo.png') {
    $logo_file = __DIR__ . '/../uploads/school_logos/' . $school['logo_path'];
    if (file_exists($logo_file)) {
        $ext = strtolower(pathinfo($logo_file, PATHINFO_EXTENSION));
        $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
        $logo_html = '<img src="data:' . $mime . ';base64,' . base64_encode(file_get_contents($logo_file)) . '" style="max-height:70px;display:block;margin:0 auto 12px;">';
    }
}

$balance = (float)$invoice['total_amount'] - (float)$invoice['paid_amount'];
$primary = $school['primary_color'] ?? '#7c3aed';

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; margin: 30px; color: #0f172a; }
    .header { text-align: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid ' . htmlspecialchars($primary) . '; }
    .header h2 { margin: 0; font-size: 22px; color: ' . htmlspecialchars($primary) . '; }
    .header .sub { color: #64748b; font-size: 12px; margin-top: 4px; }
    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
    th, td { border: 1px solid #e2e8f0; padding: 10px 12px; text-align: left; font-size: 13px; }
    th { background: #f8fafc; color: #475569; font-weight: 700; width: 40%; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .badge-paid { background: #dcfce7; color: #166534; }
    .badge-partial { background: #fef3c7; color: #92400e; }
    .badge-pending { background: #fee2e2; color: #991b1b; }
    .footer { margin-top: 30px; text-align: center; font-size: 11px; color: #94a3b8; padding-top: 16px; border-top: 1px dashed #cbd5e1; }
</style></head><body>';

$html .= '<div class="header">' . $logo_html . '<h2>' . htmlspecialchars($school['school_name'] ?? 'School') . '</h2>';
if (!empty($school['address'])) $html .= '<div class="sub">' . htmlspecialchars($school['address']) . '</div>';
if (!empty($school['phone'])) $html .= '<div class="sub">Tel: ' . htmlspecialchars($school['phone']) . '</div>';
$html .= '</div>';

$html .= '<h3 style="text-align:center;margin:16px 0;">School Fee Invoice</h3>';
$html .= '<table>';
$html .= '<tr><th>Invoice Number</th><td>INV-' . str_pad((string)$invoice['id'], 6, '0', STR_PAD_LEFT) . '</td></tr>';
$html .= '<tr><th>Student</th><td>' . htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) . ' (' . htmlspecialchars($invoice['student_id']) . ')</td></tr>';
$html .= '<tr><th>Class</th><td>' . htmlspecialchars($invoice['class_name']) . '</td></tr>';
$html .= '<tr><th>Term / Session</th><td>' . htmlspecialchars($invoice['term_name'] . ' · ' . $invoice['session_year']) . '</td></tr>';
$html .= '<tr><th>Total Amount</th><td><strong>' . formatCurrency((float)$invoice['total_amount']) . '</strong></td></tr>';
$html .= '<tr><th>Amount Paid</th><td>' . formatCurrency((float)$invoice['paid_amount']) . '</td></tr>';
$html .= '<tr><th>Balance</th><td><strong style="color:#dc2626;">' . formatCurrency($balance) . '</strong></td></tr>';
$html .= '<tr><th>Due Date</th><td>' . (!empty($invoice['due_date']) && $invoice['due_date'] !== '0000-00-00' ? parentDate($invoice['due_date']) : '—') . '</td></tr>';
$status = $invoice['status'];
$statusClass = $status === 'paid' ? 'badge-paid' : ($status === 'partial' ? 'badge-partial' : 'badge-pending');
$html .= '<tr><th>Status</th><td><span class="badge ' . $statusClass . '">' . ucfirst(htmlspecialchars($status)) . '</span></td></tr>';
$html .= '</table>';

$html .= '<div class="footer">Please make payment on or before the due date.<br>Thank you.</div>';
$html .= '</body></html>';

// Log the download
logDownload($school_id, (string)$parent_id, 'invoice', $invoice_id);

// Render PDF or fallback to HTML print
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);   // ← SSRF fix
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $filename = 'Invoice_' . $invoice_id . '_' . $invoice['first_name'] . '_' . $invoice['last_name'] . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

echo $html . '<script>window.print();</script>';