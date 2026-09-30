<?php
/**
 * school_owner/fee_action.php
 *
 * Handles 5 actions via ?action=...
 *   - set_fee           → set a fee for a class + term, auto-bills students
 *   - edit_fee          → edit an existing fee structure
 *   - delete_fee        → delete a fee structure (only if no invoices exist)
 *   - record_payment    → record a payment for an invoice
 *   - reverse_payment   → reverse a payment (partial or full)
 *
 * Plain HTML forms. No Bootstrap modals. No JS required.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

$can_set_fees         = in_array($user_role, ['Owner', 'Accountant'], true);
$can_record_payments  = in_array($user_role, ['Owner', 'Accountant'], true);
$can_reverse_payments = in_array($user_role, ['Owner', 'Accountant'], true);

$schoolStmt = $db->prepare("SELECT current_term, current_session, school_name FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch() ?: [];
$default_term    = $schoolInfo['current_term'] ?? 'Term 1';
$default_session = $schoolInfo['current_session'] ?? (date('Y') . '/' . (date('Y') + 1));

$term    = trim((string)($_GET['term']    ?? $_POST['term']    ?? $default_term));
$session = trim((string)($_GET['session'] ?? $_POST['session'] ?? $default_session));

function money(float $amount): string { return '₦' . number_format($amount, 2); }

function redirect_back(string $msg, string $type = 'success', string $tab = 'overview'): void {
    $_SESSION[$type] = $msg;
    header('Location: fees.php?tab=' . urlencode($tab));
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!in_array($action, ['set_fee', 'edit_fee', 'delete_fee', 'record_payment', 'reverse_payment'], true)) {
    http_response_code(400);
    die('Invalid action');
}

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $posted_action = $_POST['action'] ?? '';

    // ---------- SET FEE ----------
    if ($posted_action === 'set_fee') {
        if (!$can_set_fees) redirect_back('You do not have permission.', 'error', 'structures');

        $class_id = (int)($_POST['class_id'] ?? 0);
        $term_fee = (float)($_POST['term_fee'] ?? 0);
        $due_date = sanitize((string)($_POST['due_date'] ?? ''));

        if ($class_id <= 0 || $term_fee <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
            redirect_back('Please fill all fields correctly.', 'error', 'structures');
        }

        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO fee_structures (school_id, class_id, term_fee, due_date, term, session, created_at) VALUES (?,?,?,?,?,?, NOW()) ON DUPLICATE KEY UPDATE term_fee = VALUES(term_fee), due_date = VALUES(due_date)")
               ->execute([$school_id, $class_id, $term_fee, $due_date, $term, $session]);

            $students = $db->prepare("SELECT id FROM students WHERE school_id = ? AND class_id = ? AND status = 'Active'");
            $students->execute([$school_id, $class_id]);

            $insertInv = $db->prepare("INSERT INTO invoices (school_id, student_id, term, session, term_name, session_year, total_amount, paid_amount, due_date, status, created_at) VALUES (?,?,?,?,?,?,?, 0, ?, 'pending', NOW())");
            $checkInv  = $db->prepare("SELECT id FROM invoices WHERE school_id = ? AND student_id = ? AND term_name = ? AND session_year = ?");
            $created = 0;
            while ($s = $students->fetch()) {
                $sid = (int)$s['id'];
                $checkInv->execute([$school_id, $sid, $term, $session]);
                if (!$checkInv->fetch()) {
                    $insertInv->execute([$school_id, $sid, $term, $session, $term, $session, $term_fee, $due_date]);
                    $created++;
                }
            }
            $db->commit();
            logActivity('SET_FEE', "Class $class_id, $term $session, ₦$term_fee. Invoices created: $created", $school_id, $user_id);
            redirect_back("Fee saved. $created student(s) billed.", 'success', 'structures');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[fee_action set_fee] " . $e->getMessage());
            redirect_back('Failed to save fee. Try again.', 'error', 'structures');
        }
    }

    // ---------- EDIT FEE ----------
    if ($posted_action === 'edit_fee') {
        if (!$can_set_fees) redirect_back('No permission.', 'error', 'structures');

        $fs_id   = (int)($_POST['fee_structure_id'] ?? 0);
        $new_fee = (float)($_POST['new_term_fee'] ?? 0);
        $new_due = sanitize((string)($_POST['new_due_date'] ?? ''));

        if ($fs_id <= 0 || $new_fee <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_due)) {
            redirect_back('Please fill all fields correctly.', 'error', 'structures');
        }

        try {
            $db->beginTransaction();
            $fs = $db->prepare("SELECT * FROM fee_structures WHERE id = ? AND school_id = ?");
            $fs->execute([$fs_id, $school_id]);
            $structure = $fs->fetch();
            if (!$structure) { $db->rollBack(); redirect_back('Fee structure not found.', 'error', 'structures'); }

            $db->prepare("UPDATE fee_structures SET term_fee = ?, due_date = ? WHERE id = ? AND school_id = ?")
               ->execute([$new_fee, $new_due, $fs_id, $school_id]);

            $stmt = $db->prepare("UPDATE invoices i JOIN students s ON i.student_id = s.id SET i.total_amount = ?, i.due_date = ? WHERE i.school_id = ? AND s.class_id = ? AND i.term_name = ? AND i.session_year = ? AND i.paid_amount = 0");
            $stmt->execute([$new_fee, $new_due, $school_id, $structure['class_id'], $structure['term'], $structure['session']]);
            $updated = $stmt->rowCount();

            $db->commit();
            logActivity('EDIT_FEE', "Edited #$fs_id to ₦$new_fee, updated $updated invoices", $school_id, $user_id);
            redirect_back("Fee updated. $updated unbilled invoice(s) adjusted.", 'success', 'structures');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[fee_action edit_fee] " . $e->getMessage());
            redirect_back('Failed to edit fee.', 'error', 'structures');
        }
    }

    // ---------- DELETE FEE ----------
    if ($posted_action === 'delete_fee') {
        if (!$can_set_fees) redirect_back('No permission.', 'error', 'structures');

        $fs_id = (int)($_POST['fee_structure_id'] ?? 0);
        if ($fs_id <= 0) redirect_back('Invalid fee.', 'error', 'structures');

        try {
            $db->beginTransaction();
            $fs = $db->prepare("SELECT * FROM fee_structures WHERE id = ? AND school_id = ?");
            $fs->execute([$fs_id, $school_id]);
            $structure = $fs->fetch();
            if (!$structure) { $db->rollBack(); redirect_back('Not found.', 'error', 'structures'); }

            $chk = $db->prepare("SELECT COUNT(*) FROM invoices i JOIN students s ON i.student_id = s.id WHERE i.school_id = ? AND s.class_id = ? AND i.term_name = ? AND i.session_year = ?");
            $chk->execute([$school_id, $structure['class_id'], $structure['term'], $structure['session']]);
            if ((int)$chk->fetchColumn() > 0) {
                $db->rollBack();
                redirect_back('Cannot delete. Students are already billed for this class.', 'error', 'structures');
            }

            $db->prepare("DELETE FROM fee_structures WHERE id = ? AND school_id = ?")->execute([$fs_id, $school_id]);
            $db->commit();
            logActivity('DELETE_FEE', "Deleted #$fs_id", $school_id, $user_id);
            redirect_back('Fee deleted.', 'success', 'structures');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            redirect_back('Failed to delete fee.', 'error', 'structures');
        }
    }

    // ---------- RECORD PAYMENT ----------
    if ($posted_action === 'record_payment') {
        if (!$can_record_payments) redirect_back('No permission.', 'error', 'overview');

        $invoice_id     = (int)($_POST['invoice_id'] ?? 0);
        $amount_paid    = (float)($_POST['amount_paid'] ?? 0);
        $payment_method = sanitize((string)($_POST['payment_method'] ?? 'Cash'));
        $payment_date   = sanitize((string)($_POST['payment_date'] ?? date('Y-m-d H:i:s')));

        if ($invoice_id <= 0 || $amount_paid <= 0) redirect_back('Invalid invoice or amount.', 'error', 'overview');
        if (!in_array($payment_method, ['Cash','Bank Transfer','POS','Cheque'], true)) $payment_method = 'Cash';

        try {
            $db->beginTransaction();
            $inv = $db->prepare("SELECT student_id, total_amount, paid_amount FROM invoices WHERE id = ? AND school_id = ? FOR UPDATE");
            $inv->execute([$invoice_id, $school_id]);
            $invoice = $inv->fetch();
            if (!$invoice) { $db->rollBack(); redirect_back('Invoice not found.', 'error', 'overview'); }

            $balance = (float)$invoice['total_amount'] - (float)$invoice['paid_amount'];
            if ($amount_paid > $balance + 0.001) {
                $db->rollBack();
                redirect_back('Amount exceeds balance (' . money($balance) . ').', 'error', 'overview');
            }

            $new_paid = (float)$invoice['paid_amount'] + $amount_paid;
            $new_balance = (float)$invoice['total_amount'] - $new_paid;
            $status = $new_balance <= 0.001 ? 'paid' : 'partial';
            $receipt = generateReceiptNumber();

            $db->prepare("UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?")->execute([$new_paid, $status, $invoice_id]);
            $db->prepare("INSERT INTO payment_history (invoice_id, student_id, amount_paid, payment_date, payment_method, receipt_number, recorded_by, created_at) VALUES (?,?,?,?,?,?,?, NOW())")
               ->execute([$invoice_id, $invoice['student_id'], $amount_paid, $payment_date, $payment_method, $receipt, $user_id]);
            $payment_id = (int)$db->lastInsertId();

            $si = $db->prepare("SELECT first_name, last_name, parent_phone FROM students WHERE id = ? AND school_id = ?");
            $si->execute([$invoice['student_id'], $school_id]);
            $studentInfo = $si->fetch();
            if ($studentInfo && !empty($studentInfo['parent_phone'])) {
                $childName = $studentInfo['first_name'] . ' ' . $studentInfo['last_name'];
                $msg = "Dear Parent, payment of " . money($amount_paid) . " received for $childName. Receipt: $receipt. Thank you!";
                $db->prepare("INSERT INTO sms_logs (school_id, recipient, message, status, sent_by, sent_at, sms_type) VALUES (?, ?, ?, 'pending', ?, NOW(), 'payment_confirmation')")
                   ->execute([$school_id, $studentInfo['parent_phone'], $msg, $user_id]);
            }
            $db->commit();
            logActivity('RECORD_PAYMENT', "Payment #$receipt for invoice #$invoice_id: " . money($amount_paid), $school_id, $user_id);

            $_SESSION['success'] = "Payment of " . money($amount_paid) . " recorded. Receipt No: <strong>$receipt</strong> — <a href=\"print_receipt.php?payment_id=$payment_id&auto_print=1\" target=\"_blank\" style=\"color:#065f46;text-decoration:underline;font-weight:700;\">🖨 Print Receipt</a>";
            header('Location: fees.php?tab=overview');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[fee_action record_payment] " . $e->getMessage());
            redirect_back('Failed to record payment.', 'error', 'overview');
        }
    }

    // ---------- REVERSE PAYMENT (supports partial) ----------
    if ($posted_action === 'reverse_payment') {
        if (!$can_reverse_payments) redirect_back('No permission.', 'error', 'payments');

        $payment_id      = (int)($_POST['payment_id'] ?? 0);
        $amount_reversed = (float)($_POST['amount_reversed'] ?? 0);
        $reason          = sanitize((string)($_POST['reversal_reason'] ?? ''));

        if ($payment_id <= 0 || $amount_reversed <= 0 || $reason === '') {
            redirect_back('Payment ID, amount, and reason are required.', 'error', 'payments');
        }

        try {
            $db->beginTransaction();

            // Fetch original payment
            $payStmt = $db->prepare("SELECT ph.*, i.id AS invoice_id FROM payment_history ph JOIN invoices i ON ph.invoice_id = i.id WHERE ph.id = ? AND i.school_id = ? FOR UPDATE");
            $payStmt->execute([$payment_id, $school_id]);
            $payment = $payStmt->fetch();
            if (!$payment) { $db->rollBack(); redirect_back('Payment not found.', 'error', 'payments'); }

            // How much is left to reverse?
            $revSum = $db->prepare("SELECT COALESCE(SUM(amount_reversed), 0) FROM payment_reversals WHERE payment_id = ?");
            $revSum->execute([$payment_id]);
            $already_reversed = (float)$revSum->fetchColumn();
            $remaining = (float)$payment['amount_paid'] - $already_reversed;

            if ($remaining <= 0.001) {
                $db->rollBack();
                redirect_back('This payment is already fully reversed.', 'error', 'payments');
            }
            if ($amount_reversed > $remaining + 0.001) {
                $db->rollBack();
                redirect_back('Amount exceeds what can be reversed (' . money($remaining) . ').', 'error', 'payments');
            }

            // Insert reversal record
            $db->prepare("INSERT INTO payment_reversals (payment_id, amount_reversed, reason, reversed_at, reversed_by) VALUES (?,?,?, NOW(), ?)")
               ->execute([$payment_id, $amount_reversed, $reason, $user_id]);
            $reversal_id = (int)$db->lastInsertId();

            // Update invoice
            $invStmt = $db->prepare("SELECT total_amount, paid_amount FROM invoices WHERE id = ? FOR UPDATE");
            $invStmt->execute([$payment['invoice_id']]);
            $invoice = $invStmt->fetch();

            $new_paid = max(0, (float)$invoice['paid_amount'] - $amount_reversed);
            $new_balance = (float)$invoice['total_amount'] - $new_paid;
            $status = $new_balance <= 0.001 ? 'paid' : ($new_paid > 0.001 ? 'partial' : 'pending');

            $db->prepare("UPDATE invoices SET paid_amount = ?, status = ? WHERE id = ?")
               ->execute([$new_paid, $status, $payment['invoice_id']]);

            // Mark payment_history reversed=1 if fully reversed
            $check_remaining = $remaining - $amount_reversed;
            if ($check_remaining <= 0.001) {
                $db->prepare("UPDATE payment_history SET reversed = 1, reversal_reason = ?, reversed_at = NOW() WHERE id = ?")
                   ->execute([$reason, $payment_id]);
            }

            $db->commit();
            logActivity('REVERSE_PAYMENT', "Reversed " . money($amount_reversed) . " of payment #$payment_id. Reason: $reason", $school_id, $user_id);

            $_SESSION['success'] = "Reversed " . money($amount_reversed) . ". <a href=\"print_reversal.php?reversal_id=$reversal_id&auto_print=1\" target=\"_blank\" style=\"color:#065f46;text-decoration:underline;font-weight:700;\">🖨 Print Reversal Notice</a>";
            header('Location: fees.php?tab=payments');
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("[fee_action reverse_payment] " . $e->getMessage());
            redirect_back('Failed to reverse payment.', 'error', 'payments');
        }
    }
}

// ============================================================
// GET: RENDER FORM
// ============================================================
include_once __DIR__ . '/../includes/header.php';
?>

<style>
.form-card {
    background: #fff; border-radius: 16px; padding: 32px;
    max-width: 640px; margin: 20px auto; border: 1px solid #f1f5f9;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
}
.form-card h1 { font-size: 22px; margin: 0 0 8px; font-weight: 800; color: #0f172a; }
.form-card .sub { color: #64748b; font-size: 14px; margin: 0 0 24px; line-height: 1.6; }
.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 12px 14px; font-size: 15px;
    border: 1px solid #cbd5e1; border-radius: 10px;
    background: #fff; color: #0f172a;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,0.12);
}
.form-group .hint { font-size: 12px; color: #94a3b8; margin-top: 4px; font-style: italic; }
.form-readonly { background: #f1f5f9 !important; color: #64748b !important; cursor: not-allowed; }
.btn-row { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }
.btn-row .btn { flex: 1; min-width: 140px; padding: 14px 20px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; border: none; text-decoration: none; text-align: center; display: inline-block; }
.btn-primary { background: #7c3aed; color: #fff; }
.btn-primary:hover { background: #6d28d9; }
.btn-danger { background: #dc2626; color: #fff; }
.btn-danger:hover { background: #b91c1c; }
.btn-light { background: #f1f5f9; color: #334155; }
.btn-light:hover { background: #e2e8f0; }
.info-box { padding: 14px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; line-height: 1.6; }
.info-purple { background: #f5f3ff; border: 1px solid #ddd6fe; color: #5b21b6; }
.info-red { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.info-amber { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
</style>

<?php
// ---------- SET FEE ----------
if ($action === 'set_fee'):
    $class_id = (int)($_GET['class_id'] ?? 0);
    $classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY name");
    $classes->execute([$school_id]);
    $classes = $classes->fetchAll();
    $existing = null;
    if ($class_id > 0) {
        $q = $db->prepare("SELECT * FROM fee_structures WHERE school_id = ? AND class_id = ? AND term = ? AND session = ? LIMIT 1");
        $q->execute([$school_id, $class_id, $term, $session]);
        $existing = $q->fetch();
    }
?>
<div class="form-card">
    <h1>📋 Set Fee for <?php echo htmlspecialchars($term . ' ' . $session); ?></h1>
    <p class="sub">Fill this form once per class per term. All active students in the class will be billed automatically.</p>
    <?php if ($existing): ?>
        <div class="info-box info-amber"><strong>⚠️ Heads up:</strong> A fee of <strong><?php echo money((float)$existing['term_fee']); ?></strong> already exists for this class. Saving will update it.</div>
    <?php endif; ?>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="set_fee">
        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
        <input type="hidden" name="session" value="<?php echo htmlspecialchars($session); ?>">
        <div class="form-group">
            <label>Class *</label>
            <select name="class_id" required>
                <option value="">-- Select a class --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int)$c['id']; ?>" <?php echo $class_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Fee Amount (₦) *</label>
            <input type="number" name="term_fee" step="0.01" min="1" required value="<?php echo $existing ? (float)$existing['term_fee'] : ''; ?>" placeholder="e.g. 35000">
        </div>
        <div class="form-group">
            <label>Due Date *</label>
            <input type="date" name="due_date" required value="<?php echo $existing && $existing['due_date'] !== '0000-00-00' ? htmlspecialchars($existing['due_date']) : ''; ?>">
        </div>
        <div class="form-group">
            <label>Term</label>
            <input type="text" class="form-readonly" value="<?php echo htmlspecialchars($term); ?>" readonly>
        </div>
        <div class="form-group">
            <label>Session</label>
            <input type="text" class="form-readonly" value="<?php echo htmlspecialchars($session); ?>" readonly>
        </div>
        <div class="btn-row">
            <a href="fees.php?tab=structures&term=<?php echo urlencode($term); ?>&session=<?php echo urlencode($session); ?>" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">💾 Save & Bill Students</button>
        </div>
    </form>
</div>

<?php
// ---------- EDIT FEE ----------
elseif ($action === 'edit_fee'):
    $fs_id = (int)($_GET['fs_id'] ?? 0);
    $q = $db->prepare("SELECT fs.*, c.name AS class_name FROM fee_structures fs JOIN classes c ON fs.class_id = c.id WHERE fs.id = ? AND fs.school_id = ?");
    $q->execute([$fs_id, $school_id]);
    $fs = $q->fetch();
    if (!$fs) {
        echo '<div class="form-card"><h1>Fee not found</h1><a href="fees.php?tab=structures" class="btn btn-light">Go Back</a></div>';
    } else {
?>
<div class="form-card">
    <h1>✏️ Edit Fee — <?php echo htmlspecialchars($fs['class_name']); ?></h1>
    <p class="sub">Editing the fee for <strong><?php echo htmlspecialchars($fs['term'] . ' ' . $fs['session']); ?></strong>.</p>
    <div class="info-box info-purple">ℹ️ Only <strong>unpaid invoices</strong> will be updated. Invoices where a student has already paid will not change.</div>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="edit_fee">
        <input type="hidden" name="fee_structure_id" value="<?php echo (int)$fs['id']; ?>">
        <input type="hidden" name="term" value="<?php echo htmlspecialchars($fs['term']); ?>">
        <input type="hidden" name="session" value="<?php echo htmlspecialchars($fs['session']); ?>">
        <div class="form-group"><label>Class</label><input type="text" class="form-readonly" value="<?php echo htmlspecialchars($fs['class_name']); ?>" readonly></div>
        <div class="form-group"><label>Fee Amount (₦) *</label><input type="number" name="new_term_fee" step="0.01" min="1" required value="<?php echo (float)$fs['term_fee']; ?>"></div>
        <div class="form-group"><label>Due Date *</label><input type="date" name="new_due_date" required value="<?php echo htmlspecialchars($fs['due_date']); ?>"></div>
        <div class="btn-row">
            <a href="fees.php?tab=structures&term=<?php echo urlencode($fs['term']); ?>&session=<?php echo urlencode($fs['session']); ?>" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">💾 Save Changes</button>
        </div>
    </form>
</div>

<?php
    }

// ---------- DELETE FEE ----------
elseif ($action === 'delete_fee'):
    $fs_id = (int)($_GET['fs_id'] ?? 0);
    $q = $db->prepare("SELECT fs.*, c.name AS class_name FROM fee_structures fs JOIN classes c ON fs.class_id = c.id WHERE fs.id = ? AND fs.school_id = ?");
    $q->execute([$fs_id, $school_id]);
    $fs = $q->fetch();
    if (!$fs) {
        echo '<div class="form-card"><h1>Fee not found</h1><a href="fees.php?tab=structures" class="btn btn-light">Go Back</a></div>';
    } else {
        $chk = $db->prepare("SELECT COUNT(*) FROM invoices i JOIN students s ON i.student_id = s.id WHERE i.school_id = ? AND s.class_id = ? AND i.term_name = ? AND i.session_year = ?");
        $chk->execute([$school_id, $fs['class_id'], $fs['term'], $fs['session']]);
        $invoice_count = (int)$chk->fetchColumn();
?>
<div class="form-card">
    <h1>🗑️ Delete Fee — <?php echo htmlspecialchars($fs['class_name']); ?></h1>
    <?php if ($invoice_count > 0): ?>
        <div class="info-box info-red"><strong>Cannot delete.</strong> This fee has already billed <?php echo $invoice_count; ?> student(s). Edit the fee instead.</div>
        <div class="btn-row"><a href="fees.php?tab=structures" class="btn btn-light">Go Back</a></div>
    <?php else: ?>
        <div class="info-box info-red"><strong>This action cannot be undone.</strong> The fee structure will be permanently deleted.</div>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="delete_fee">
            <input type="hidden" name="fee_structure_id" value="<?php echo (int)$fs['id']; ?>">
            <div class="form-group"><label>Class</label><input type="text" class="form-readonly" value="<?php echo htmlspecialchars($fs['class_name']); ?>" readonly></div>
            <div class="form-group"><label>Amount</label><input type="text" class="form-readonly" value="<?php echo money((float)$fs['term_fee']); ?>" readonly></div>
            <div class="btn-row">
                <a href="fees.php?tab=structures" class="btn btn-light">Cancel</a>
                <button type="submit" class="btn btn-danger" onclick="return confirm('Delete this fee permanently?');">🗑️ Delete Fee</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php
    }

// ---------- RECORD PAYMENT ----------
elseif ($action === 'record_payment'):
    $invoice_id = (int)($_GET['invoice_id'] ?? 0);
    $q = $db->prepare("SELECT i.*, s.first_name, s.last_name, s.student_id AS student_code, c.name AS class_name FROM invoices i JOIN students s ON i.student_id = s.id LEFT JOIN classes c ON s.class_id = c.id WHERE i.id = ? AND i.school_id = ?");
    $q->execute([$invoice_id, $school_id]);
    $inv = $q->fetch();
    if (!$inv) {
        echo '<div class="form-card"><h1>Invoice not found</h1><a href="fees.php" class="btn btn-light">Go Back</a></div>';
    } else {
        $balance = (float)$inv['total_amount'] - (float)$inv['paid_amount'];
?>
<div class="form-card">
    <h1>💵 Record Payment</h1>
    <p class="sub"><?php echo htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']); ?> — <strong><?php echo htmlspecialchars($inv['class_name'] ?? 'N/A'); ?></strong><br>Balance: <strong style="color:#dc2626;"><?php echo money($balance); ?></strong></p>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="invoice_id" value="<?php echo (int)$inv['id']; ?>">
        <input type="hidden" name="term" value="<?php echo htmlspecialchars($inv['term_name']); ?>">
        <input type="hidden" name="session" value="<?php echo htmlspecialchars($inv['session_year']); ?>">
        <div class="form-group"><label>Student</label><input type="text" class="form-readonly" value="<?php echo htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name'] . ' (' . $inv['student_code'] . ')'); ?>" readonly></div>
        <div class="form-group">
            <label>Amount to Pay (₦) *</label>
            <input type="number" name="amount_paid" step="0.01" min="0.01" max="<?php echo $balance; ?>" required value="<?php echo $balance; ?>">
            <div class="hint">Maximum: <?php echo money($balance); ?></div>
        </div>
        <div class="form-group">
            <label>Payment Method *</label>
            <select name="payment_method" required>
                <option value="Cash">Cash</option>
                <option value="Bank Transfer">Bank Transfer</option>
                <option value="POS">POS</option>
                <option value="Cheque">Cheque</option>
            </select>
        </div>
        <div class="form-group"><label>Payment Date</label><input type="datetime-local" name="payment_date" value="<?php echo date('Y-m-d\TH:i'); ?>"></div>
        <div class="btn-row">
            <a href="fees.php" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-primary">💾 Save Payment</button>
        </div>
    </form>
</div>

<?php
    }

// ---------- REVERSE PAYMENT ----------
elseif ($action === 'reverse_payment'):
    $payment_id = (int)($_GET['payment_id'] ?? 0);
    $q = $db->prepare("SELECT ph.*, s.first_name, s.last_name, s.student_id AS student_code, c.name AS class_name FROM payment_history ph JOIN invoices i ON ph.invoice_id = i.id JOIN students s ON i.student_id = s.id LEFT JOIN classes c ON s.class_id = c.id WHERE ph.id = ? AND i.school_id = ?");
    $q->execute([$payment_id, $school_id]);
    $ph = $q->fetch();

    if (!$ph) {
        echo '<div class="form-card"><h1>Payment not found</h1><a href="fees.php?tab=payments" class="btn btn-light">Go Back</a></div>';
    } else {
        // Check existing reversals
        $revSum = $db->prepare("SELECT COALESCE(SUM(amount_reversed), 0) FROM payment_reversals WHERE payment_id = ?");
        $revSum->execute([$payment_id]);
        $already_reversed = (float)$revSum->fetchColumn();
        $remaining = (float)$ph['amount_paid'] - $already_reversed;

        if ($remaining <= 0.001) {
            echo '<div class="form-card"><h1>Already fully reversed</h1><p>This payment has been fully reversed.</p><a href="fees.php?tab=payments" class="btn btn-light">Go Back</a></div>';
        } else {
?>
<div class="form-card">
    <h1>⚠️ Reverse Payment</h1>
    <p class="sub">
        Receipt: <strong><?php echo htmlspecialchars($ph['receipt_number']); ?></strong><br>
        Student: <strong><?php echo htmlspecialchars($ph['first_name'] . ' ' . $ph['last_name']); ?></strong><br>
        Original Amount: <strong><?php echo money((float)$ph['amount_paid']); ?></strong>
        <?php if ($already_reversed > 0): ?>
            <br>Already Reversed: <strong style="color:#dc2626;"><?php echo money($already_reversed); ?></strong><br>
            Available to Reverse: <strong><?php echo money($remaining); ?></strong>
        <?php endif; ?>
    </p>
    <div class="info-box info-red">
        <strong>What happens:</strong><br>
        • You can reverse any amount up to <strong><?php echo money($remaining); ?></strong><br>
        • The invoice's paid amount will drop by that much<br>
        • A reversal notice can be printed afterward
    </div>
    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="reverse_payment">
        <input type="hidden" name="payment_id" value="<?php echo (int)$ph['id']; ?>">
        <input type="hidden" name="term" value="<?php echo htmlspecialchars($term); ?>">
        <input type="hidden" name="session" value="<?php echo htmlspecialchars($session); ?>">
        <div class="form-group">
            <label>Amount to Reverse (₦) *</label>
            <input type="number" name="amount_reversed" step="0.01" min="0.01" max="<?php echo $remaining; ?>" required value="<?php echo $remaining; ?>">
            <div class="hint">Default is full amount. Change if you want partial reversal. Maximum: <?php echo money($remaining); ?></div>
        </div>
        <div class="form-group">
            <label>Reason for Reversal *</label>
            <textarea name="reversal_reason" rows="3" required maxlength="255" placeholder="e.g. Payment recorded in error, wrong student, duplicate entry"></textarea>
        </div>
        <div class="btn-row">
            <a href="fees.php?tab=payments" class="btn btn-light">Cancel</a>
            <button type="submit" class="btn btn-danger" onclick="return confirm('Reverse this payment? This cannot be undone.');">↺ Confirm Reversal</button>
        </div>
    </form>
</div>
<?php
        }
    }
endif;

include_once __DIR__ . '/../includes/footer.php';