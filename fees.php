<?php
// parent/fees.php - Parent view of fees, invoice, and payment history (v2)
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
    $stmt = $db->prepare("
        SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name
        FROM parent_students ps
        JOIN students s ON ps.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE ps.parent_id = ? AND s.school_id = ? AND s.status = 'Active'
        ORDER BY s.first_name
    ");
    $stmt->execute([$parent_id, $school_id]);
    $children = $stmt->fetchAll();
}
if (empty($children)) {
    $parentStmt = $db->prepare("SELECT student_id FROM parent_portal WHERE id = ? AND school_id = ? AND is_active = 1");
    $parentStmt->execute([$parent_id, $school_id]);
    $parent = $parentStmt->fetch();
    if (!$parent) die("Parent account not found.");
    $stmt = $db->prepare("
        SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name
        FROM students s
        JOIN classes c ON s.class_id = c.id
        WHERE s.id = ? AND s.school_id = ? AND s.status = 'Active'
    ");
    $stmt->execute([(int)$parent['student_id'], $school_id]);
    $children = $stmt->fetchAll();
}
if (empty($children)) die("No child found.");

$activeChildId = $child_id ?: $children[0]['id'];
$activeChild = null;
foreach ($children as $child) {
    if ($child['id'] === $activeChildId) { $activeChild = $child; break; }
}
if (!$activeChild) $activeChild = $children[0];

// School info
$schoolStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch();
$current_term = $schoolInfo['current_term'] ?? 'Term 1';
$current_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

$selected_term = $selected_term ?: $current_term;
$selected_session = $selected_session ?: $current_session;

// Term list
$termsStmt = $db->prepare("
    SELECT DISTINCT term_name, session_year
    FROM invoices
    WHERE student_id = ? AND school_id = ?
    ORDER BY session_year DESC, term_name DESC
");
$termsStmt->execute([$activeChild['id'], $school_id]);
$availableTerms = $termsStmt->fetchAll();

// Get all invoices (for summary)
$allInvoicesStmt = $db->prepare("
    SELECT id, term_name, session_year, total_amount, paid_amount,
           (total_amount - paid_amount) AS balance, status
    FROM invoices
    WHERE student_id = ? AND school_id = ?
    ORDER BY session_year DESC, term_name DESC
");
$allInvoicesStmt->execute([$activeChild['id'], $school_id]);
$allInvoices = $allInvoicesStmt->fetchAll();

// Total across all terms
$grandTotalFee = 0;
$grandTotalPaid = 0;
$grandTotalOwing = 0;
foreach ($allInvoices as $inv) {
    $grandTotalFee += (float)$inv['total_amount'];
    $grandTotalPaid += (float)$inv['paid_amount'];
    $grandTotalOwing += (float)$inv['balance'];
}

// Current term invoice
$invoiceStmt = $db->prepare("
    SELECT id, total_amount, paid_amount, (total_amount - paid_amount) AS balance,
           status, due_date
    FROM invoices
    WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ?
    LIMIT 1
");
$invoiceStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$invoice = $invoiceStmt->fetch();

// Payments for selected term
$paymentStmt = $db->prepare("
    SELECT ph.* FROM payment_history ph
    JOIN invoices i ON ph.invoice_id = i.id
    WHERE i.student_id = ? AND i.school_id = ? AND i.term_name = ? AND i.session_year = ?
    ORDER BY ph.payment_date DESC
");
$paymentStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$payments = $paymentStmt->fetchAll();

$photo_url = ($activeChild['photo_path'] && $activeChild['photo_path'] !== 'default_student.png')
    ? BASE_URL . 'uploads/student_photos/' . $activeChild['photo_path']
    : BASE_URL . 'assets/images/default_avatar.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees | Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; min-height: 100vh; padding-bottom: 30px; }
        .container { max-width: 480px; margin: 0 auto; padding: 0 16px 30px; }

        .topbar { display: flex; align-items: center; gap: 12px; padding: 20px 16px 10px; }
        .topbar .back { background: white; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #0f172a; text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .topbar h3 { font-weight: 800; color: #0f172a; font-size: 1.1rem; flex: 1; }

        /* Chip switcher */
        .chips-scroll { display: flex; gap: 10px; overflow-x: auto; padding: 8px 0 4px; margin: 0 -16px; padding-left: 16px; padding-right: 16px; scrollbar-width: none; }
        .chips-scroll::-webkit-scrollbar { display: none; }
        .chip { display: flex; align-items: center; gap: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 50px; padding: 6px 16px 6px 6px; text-decoration: none; color: #0f172a; transition: 0.15s; flex-shrink: 0; }
        .chip:hover { border-color: #c4b5fd; }
        .chip.active { background: linear-gradient(135deg, #ea580c, #f59e0b); border-color: #ea580c; color: white; }
        .chip.active .chip-name { color: white; }
        .chip.active .chip-class { color: rgba(255,255,255,0.7); }
        .chip img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9; }
        .chip.active img { border-color: rgba(255,255,255,0.4); }
        .chip-name { font-weight: 700; font-size: 0.82rem; white-space: nowrap; }
        .chip-class { font-size: 0.68rem; color: #94a3b8; white-space: nowrap; }

        /* Term selector */
        .term-selector { background: white; border-radius: 14px; padding: 12px; margin-top: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; gap: 8px; align-items: center; }
        .term-selector select { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; font-size: 0.85rem; font-weight: 600; flex: 1; }

        /* Child card */
        .child-card { background: linear-gradient(135deg, #ea580c, #f59e0b); border-radius: 20px; padding: 16px; margin-top: 10px; display: flex; align-items: center; gap: 12px; color: white; }
        .child-card img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.5); }
        .child-card .name { font-weight: 700; font-size: 0.95rem; }
        .child-card .class { font-size: 0.75rem; opacity: 0.85; }

        /* Big owing banner */
        .owing-banner {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            border-radius: 18px; padding: 20px; margin-top: 14px;
            color: white; text-align: center;
            box-shadow: 0 8px 24px rgba(220,38,38,0.25);
        }
        .owing-banner .label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; font-weight: 700; }
        .owing-banner .amount { font-size: 2.5rem; font-weight: 800; line-height: 1; margin-top: 6px; }
        .owing-banner .sub { font-size: 0.75rem; opacity: 0.9; margin-top: 6px; }

        .paid-banner {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            border-radius: 18px; padding: 20px; margin-top: 14px;
            color: white; text-align: center;
            box-shadow: 0 8px 24px rgba(22,163,74,0.25);
        }
        .paid-banner .label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; font-weight: 700; }
        .paid-banner .amount { font-size: 1.6rem; font-weight: 800; line-height: 1; margin-top: 6px; }

        /* Summary grid */
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
        .summary-mini { background: white; border-radius: 14px; padding: 16px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .summary-mini .lbl { font-size: 0.65rem; color: #64748b; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
        .summary-mini .val { font-size: 1.15rem; font-weight: 800; color: #0f172a; margin-top: 4px; }
        .summary-mini .val.red { color: #dc2626; }
        .summary-mini .val.green { color: #16a34a; }
        .summary-mini .val.purple { color: #7c3aed; }

        /* Section titles */
        .section-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; margin: 20px 0 10px; }

        /* Invoice detail card */
        .invoice-detail { background: white; border-radius: 16px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .invoice-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f1f5f9; align-items: center; }
        .invoice-row:last-child { border-bottom: none; padding-bottom: 0; }
        .invoice-row .lbl { color: #64748b; font-size: 0.82rem; font-weight: 500; }
        .invoice-row .val { font-weight: 700; color: #0f172a; font-size: 0.92rem; }
        .val-red { color: #dc2626 !important; }
        .val-green { color: #16a34a !important; }
        .val-purple { color: #7c3aed !important; }

        .badge-status { padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-partial { background: #fef3c7; color: #92400e; }
        .badge-pending { background: #fee2e2; color: #991b1b; }

        /* Buttons */
        .btn-primary-full {
            width: 100%; border-radius: 12px; padding: 14px;
            font-weight: 700; margin-top: 12px; border: none;
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: white; font-size: 0.9rem; text-decoration: none;
            display: block; text-align: center;
            box-shadow: 0 4px 12px rgba(124,58,237,0.2);
        }
        .btn-primary-full i { margin-right: 6px; }

        /* Payment history */
        .payment-list { display: flex; flex-direction: column; gap: 8px; }
        .payment-item { background: white; border-radius: 14px; padding: 14px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .payment-icon { width: 40px; height: 40px; border-radius: 12px; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .payment-info { flex: 1; min-width: 0; }
        .payment-amount { font-weight: 800; color: #0f172a; font-size: 0.95rem; }
        .payment-detail { font-size: 0.7rem; color: #64748b; margin-top: 2px; }
        .payment-receipt { font-size: 0.65rem; color: #94a3b8; font-family: monospace; margin-top: 2px; }
        .btn-receipt { background: #ede9fe; color: #7c3aed; padding: 8px 14px; border-radius: 10px; font-size: 0.72rem; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; }

        /* Empty state */
        .empty-state { background: white; border-radius: 14px; padding: 30px 20px; text-align: center; color: #94a3b8; font-size: 0.85rem; }
    </style>
</head>
<body>
<div class="container">
    <!-- Top Bar -->
    <div class="topbar">
        <a href="index.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h3>💰 Fees</h3>
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
            <i class="fas fa-calendar" style="color:#ea580c;"></i>
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

    <!-- Child Card -->
    <div class="child-card">
        <img src="<?php echo $photo_url; ?>" alt="Child">
        <div>
            <div class="name"><?php echo htmlspecialchars($activeChild['first_name'] . ' ' . $activeChild['last_name']); ?></div>
            <div class="class"><?php echo htmlspecialchars($activeChild['class_name']); ?> • <?php echo htmlspecialchars($selected_term); ?></div>
        </div>
    </div>

    <!-- Big owing status -->
    <?php if ($grandTotalOwing > 0): ?>
        <div class="owing-banner">
            <div class="label">Total Outstanding (All Terms)</div>
            <div class="amount"><?php echo formatCurrency($grandTotalOwing); ?></div>
            <div class="sub">Please settle to unlock report cards and avoid penalties</div>
        </div>
    <?php else: ?>
        <div class="paid-banner">
            <div class="label">All Fees Cleared</div>
            <div class="amount">✓ Thank you</div>
        </div>
    <?php endif; ?>

    <!-- Summary grid -->
    <div class="summary-grid">
        <div class="summary-mini">
            <div class="lbl">Total Billed</div>
            <div class="val"><?php echo formatCurrency($grandTotalFee); ?></div>
        </div>
        <div class="summary-mini">
            <div class="lbl">Total Paid</div>
            <div class="val green"><?php echo formatCurrency($grandTotalPaid); ?></div>
        </div>
    </div>

    <!-- Selected term invoice -->
    <div class="section-title"><?php echo htmlspecialchars($selected_term . ' · ' . $selected_session); ?></div>

    <?php if ($invoice): ?>
        <div class="invoice-detail">
            <div class="invoice-row">
                <span class="lbl">Total Fee</span>
                <span class="val"><?php echo formatCurrency($invoice['total_amount']); ?></span>
            </div>
            <div class="invoice-row">
                <span class="lbl">Amount Paid</span>
                <span class="val val-green"><?php echo formatCurrency($invoice['paid_amount']); ?></span>
            </div>
            <div class="invoice-row">
                <span class="lbl">Balance</span>
                <span class="val <?php echo $invoice['balance'] > 0 ? 'val-red' : 'val-green'; ?>">
                    <?php echo formatCurrency($invoice['balance']); ?>
                </span>
            </div>
            <?php if (!empty($invoice['due_date']) && $invoice['due_date'] !== '0000-00-00'): ?>
                <div class="invoice-row">
                    <span class="lbl">Due Date</span>
                    <span class="val"><?php echo date('M d, Y', strtotime($invoice['due_date'])); ?></span>
                </div>
            <?php endif; ?>
            <div class="invoice-row">
                <span class="lbl">Status</span>
                <span class="badge-status badge-<?php echo $invoice['status']; ?>">
                    <?php echo ucfirst($invoice['status']); ?>
                </span>
            </div>

            <a href="download_invoice.php?invoice_id=<?php echo $invoice['id']; ?>&child_id=<?php echo $activeChild['id']; ?>" class="btn-primary-full">
                <i class="fas fa-download"></i> Download Invoice
            </a>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-file-invoice" style="font-size:2rem;opacity:0.4;display:block;margin-bottom:8px;"></i>
            No invoice for this term.
        </div>
    <?php endif; ?>

    <!-- Payment History -->
    <div class="section-title">Payments for <?php echo htmlspecialchars($selected_term); ?> (<?php echo count($payments); ?>)</div>
    <?php if (empty($payments)): ?>
        <div class="empty-state">
            <i class="fas fa-receipt" style="font-size:2rem;opacity:0.4;display:block;margin-bottom:8px;"></i>
            No payments recorded yet for this term.
        </div>
    <?php else: ?>
        <div class="payment-list">
            <?php foreach ($payments as $pay): ?>
                <div class="payment-item">
                    <div class="payment-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="payment-info">
                        <div class="payment-amount"><?php echo formatCurrency($pay['amount_paid']); ?></div>
                        <div class="payment-detail">
                            <?php echo date('M d, Y g:i A', strtotime($pay['payment_date'])); ?> • <?php echo htmlspecialchars($pay['payment_method']); ?>
                        </div>
                        <div class="payment-receipt"><?php echo htmlspecialchars($pay['receipt_number']); ?></div>
                    </div>
                    <a href="download_receipt.php?payment_id=<?php echo $pay['id']; ?>&child_id=<?php echo $activeChild['id']; ?>" class="btn-receipt">
                        <i class="fas fa-download"></i> Receipt
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Full invoice history -->
    <?php if (count($allInvoices) > 1): ?>
        <div class="section-title">All Terms on Record (<?php echo count($allInvoices); ?>)</div>
        <div class="payment-list">
            <?php foreach ($allInvoices as $inv): ?>
                <div class="payment-item">
                    <div class="payment-icon" style="background: <?php echo (float)$inv['balance'] > 0 ? '#fee2e2' : '#dcfce7'; ?>; color: <?php echo (float)$inv['balance'] > 0 ? '#991b1b' : '#16a34a'; ?>;">
                        <i class="fas fa-<?php echo (float)$inv['balance'] > 0 ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                    </div>
                    <div class="payment-info">
                        <div class="payment-amount"><?php echo htmlspecialchars($inv['term_name'] . ' · ' . $inv['session_year']); ?></div>
                        <div class="payment-detail">
                            Billed <?php echo formatCurrency($inv['total_amount']); ?> • Paid <?php echo formatCurrency($inv['paid_amount']); ?>
                        </div>
                        <?php if ((float)$inv['balance'] > 0): ?>
                            <div class="payment-receipt" style="color:#dc2626;font-weight:700;font-family:Inter,sans-serif;">Owing: <?php echo formatCurrency($inv['balance']); ?></div>
                        <?php else: ?>
                            <div class="payment-receipt" style="color:#16a34a;font-weight:700;font-family:Inter,sans-serif;">Fully Paid</div>
                        <?php endif; ?>
                    </div>
                    <a href="?child_id=<?php echo $activeChild['id']; ?>&term=<?php echo urlencode($inv['term_name']); ?>&session=<?php echo urlencode($inv['session_year']); ?>" class="btn-receipt">
                        View
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>