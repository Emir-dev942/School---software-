<?php
// school_owner/bulk_import_students.php - SMART IMPORT (v2 — creates invoices)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];

// ---------- HELPER: Normalize Class Name ----------
function normalizeClassName($name) {
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]/', '', $name);
    return $name;
}

// ---------- HELPER: Detect Class Level ----------
function detectClassLevel($class_name) {
    $name = strtolower(trim($class_name));
    if (strpos($name, 'kg') !== false || strpos($name, 'kindergarten') !== false) return 'KG';
    if (strpos($name, 'nur') !== false) return 'Nursery';
    if (strpos($name, 'jss') !== false || strpos($name, 'junior') !== false || strpos($name, 'basic') !== false) return 'Junior Secondary';
    if (strpos($name, 'sss') !== false || strpos($name, 'ss') !== false || strpos($name, 'senior') !== false) return 'Senior Secondary';
    if (strpos($name, 'primary') !== false || strpos($name, 'pry') !== false) return 'Primary';
    return 'Primary';
}

$errors = [];
$success = '';
$imported_count = 0;
$invoices_created = 0;
$failed_rows = [];
$imported_students = [];

// ---------- SAMPLE CSV ----------
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="student_import_template.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['First Name', 'Last Name', 'Class Name', 'Parent Phone', 'Parent Name', 'Parent Email', 'Gender', 'Date of Birth', 'Admission Date']);
    fputcsv($output, ['John', 'Doe', 'JSS 1', '08012345678', 'Mr. Doe', 'john@example.com', 'Male', '2015-05-10', date('Y-m-d')]);
    fputcsv($output, ['Jane', 'Smith', 'SS 2', '08087654321', 'Mrs. Smith', 'jane@example.com', 'Female', '2013-08-15', date('Y-m-d')]);
    fclose($output);
    exit;
}

// ---------- CSV UPLOAD ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['csv_file']['tmp_name'];
    $fileName = $_FILES['csv_file']['name'];
    $fileType = pathinfo($fileName, PATHINFO_EXTENSION);

    if (strtolower($fileType) !== 'csv') {
        $errors[] = 'Please upload a valid CSV file.';
    } else {
        $rows = array_map('str_getcsv', file($file));

        if (count($rows) < 2) {
            $errors[] = 'The CSV file is empty or has no data rows.';
        } else {
            $headers = array_shift($rows);
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/[\x{FEFF}]/u', '', $headers[0]);
            }

            $requiredHeaders = ['First Name', 'Last Name', 'Class Name', 'Parent Phone'];
            $headerValid = true;
            foreach ($requiredHeaders as $i => $header) {
                if (!isset($headers[$i]) || trim(strtolower($headers[$i])) !== strtolower($header)) {
                    $headerValid = false;
                    break;
                }
            }

            if (!$headerValid) {
                $errors[] = 'CSV headers do not match. Please download the sample template.';
            } else {
                $allClasses = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active'");
                $allClasses->execute([$school_id]);
                $existing_classes = $allClasses->fetchAll();

                // Fetch current term/session + all fee structures in advance
                $schoolInfoStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
                $schoolInfoStmt->execute([$school_id]);
                $schoolInfo = $schoolInfoStmt->fetch();
                $current_term = $schoolInfo['current_term'] ?? 'Term 1';
                $current_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

                // Pre-load all fee structures for this school for the current term
                $feesStmt = $db->prepare("SELECT class_id, term_fee, due_date FROM fee_structures WHERE school_id = ? AND term = ? AND session = ?");
                $feesStmt->execute([$school_id, $current_term, $current_session]);
                $feesByClass = [];
                while ($f = $feesStmt->fetch()) {
                    $feesByClass[(int)$f['class_id']] = $f;
                }

                $db->beginTransaction();

                try {
                    $rowNumber = 1;
                    foreach ($rows as $row) {
                        $rowNumber++;
                        if (count($row) < 3 || empty(trim($row[0]))) continue;

                        $first_name   = trim($row[0] ?? '');
                        $last_name    = trim($row[1] ?? '');
                        $class_name   = trim($row[2] ?? '');
                        $parent_phone = trim($row[3] ?? '');
                        $parent_name  = trim($row[4] ?? '');
                        $parent_email = trim($row[5] ?? '');
                        $gender       = trim($row[6] ?? 'Male');
                        $dob          = trim($row[7] ?? '');
                        $admission    = trim($row[8] ?? date('Y-m-d'));

                        if (empty($first_name) || empty($last_name) || empty($class_name) || empty($parent_phone)) {
                            $failed_rows[] = "Row $rowNumber: Missing required fields.";
                            continue;
                        }

                        $parent_phone = preg_replace('/[^0-9]/', '', $parent_phone);
                        if (strlen($parent_phone) < 10 || strlen($parent_phone) > 15) {
                            $failed_rows[] = "Row $rowNumber: Invalid phone '$parent_phone'.";
                            continue;
                        }

                        $gender = in_array($gender, ['Male', 'Female', 'Other']) ? $gender : 'Male';
                        $dob = !empty($dob) && strtotime($dob) ? date('Y-m-d', strtotime($dob)) : null;
                        $admission = !empty($admission) && strtotime($admission) ? date('Y-m-d', strtotime($admission)) : date('Y-m-d');

                        // SMART CLASS MATCHING
                        $normalized_input = normalizeClassName($class_name);
                        $class_id = null;

                        foreach ($existing_classes as $existing) {
                            if (normalizeClassName($existing['name']) === $normalized_input) {
                                $class_id = (int)$existing['id'];
                                break;
                            }
                        }

                        if (!$class_id) {
                            $level = detectClassLevel($class_name);
                            $stmt = $db->prepare("INSERT INTO classes (school_id, name, level, status, created_at) VALUES (?, ?, ?, 'active', NOW())");
                            $stmt->execute([$school_id, $class_name, $level]);
                            $class_id = (int)$db->lastInsertId();
                            $existing_classes[] = ['id' => $class_id, 'name' => $class_name];
                        }

                        // DUPLICATE CHECK
                        $checkDup = $db->prepare("SELECT id FROM students WHERE school_id = ? AND first_name = ? AND last_name = ? AND class_id = ? AND parent_phone = ? LIMIT 1");
                        $checkDup->execute([$school_id, $first_name, $last_name, $class_id, $parent_phone]);
                        if ($checkDup->fetch()) {
                            $failed_rows[] = "Row $rowNumber: Duplicate - $first_name $last_name already exists in $class_name.";
                            continue;
                        }

                        // GENERATE STUDENT ID
                        $base = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $class_name), 0, 3));
                        if (empty($base)) $base = 'STU';
                        $student_id_code = $base . '-' . rand(100, 999);
                        $stmt = $db->prepare("SELECT id FROM students WHERE student_id = ? AND school_id = ?");
                        $attempts = 0;
                        while ($attempts < 20) {
                            $stmt->execute([$student_id_code, $school_id]);
                            if (!$stmt->fetch()) break;
                            $student_id_code = $base . '-' . rand(100, 999);
                            $attempts++;
                        }

                        // INSERT STUDENT
                        $stmt = $db->prepare("INSERT INTO students
                            (school_id, class_id, student_id, first_name, last_name, gender, date_of_birth,
                             parent_phone, parent_name, parent_email, status, admission_date, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, NOW())");
                        $stmt->execute([
                            $school_id, $class_id, $student_id_code, $first_name, $last_name, $gender, $dob,
                            $parent_phone, $parent_name, $parent_email, $admission
                        ]);
                        $new_student_id = (int)$db->lastInsertId();

                        // CREATE PARENT PORTAL ACCOUNT
                        $hashed = password_hash('password123', PASSWORD_DEFAULT);

                        $checkParent = $db->prepare("SELECT id FROM parent_portal WHERE school_id = ? AND parent_phone = ? LIMIT 1");
                        $checkParent->execute([$school_id, $parent_phone]);
                        $existingParent = $checkParent->fetch();

                        if ($existingParent) {
                            $stmt = $db->prepare("UPDATE parent_portal SET student_id = ?, parent_email = ?, is_active = 1 WHERE id = ?");
                            $stmt->execute([$new_student_id, $parent_email, $existingParent['id']]);
                        } else {
                            $stmt = $db->prepare("INSERT INTO parent_portal (school_id, student_id, parent_phone, parent_email, password, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
                            $stmt->execute([$school_id, $new_student_id, $parent_phone, $parent_email, $hashed]);
                        }

                        // ✅ NEW: AUTO-CREATE INVOICE if fee structure exists for this class + current term
                        if (isset($feesByClass[$class_id])) {
                            $fee = $feesByClass[$class_id];
                            // Check if an invoice already exists (shouldn't, but safety)
                            $invCheck = $db->prepare("SELECT id FROM invoices WHERE school_id = ? AND student_id = ? AND term = ? AND session = ? LIMIT 1");
                            $invCheck->execute([$school_id, $new_student_id, $current_term, $current_session]);

                            if (!$invCheck->fetch()) {
                                $db->prepare("INSERT INTO invoices
                                    (school_id, student_id, term, session, term_name, session_year,
                                     total_amount, paid_amount, due_date, status, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'pending', NOW())"
                                )->execute([
                                    $school_id, $new_student_id,
                                    $current_term, $current_session,
                                    $current_term, $current_session,
                                    $fee['term_fee'], $fee['due_date']
                                ]);
                                $invoices_created++;
                            }
                        }

                        $imported_count++;
                        $imported_students[] = [
                            'name' => "$first_name $last_name",
                            'student_id' => $student_id_code,
                            'class' => $class_name,
                            'parent_phone' => $parent_phone
                        ];
                    }

                    if ($imported_count > 0) {
                        $db->commit();
                        logActivity('BULK_IMPORT_STUDENTS', "Imported $imported_count students via CSV. Invoices: $invoices_created. Failed: " . count($failed_rows), $school_id, $user_id);
                        $success = "✅ Successfully imported <strong>$imported_count</strong> students with parent accounts.";
                        if ($invoices_created > 0) {
                            $success .= " <span style='color:#16a34a;'><strong>$invoices_created</strong> invoices created.</span>";
                        }
                        if (count($failed_rows) > 0) {
                            $success .= " <span style='color:#ea580c;'>⚠️ " . count($failed_rows) . " rows skipped.</span>";
                        }
                    } else {
                        $db->rollBack();
                        $errors[] = 'No students were imported. Please check your CSV data.';
                    }

                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $errors[] = 'An error occurred: ' . $e->getMessage();
                    error_log("Bulk Import Error: " . $e->getMessage());
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors[] = 'Please select a CSV file to upload.';
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">📤 Bulk Import Students</h1>
        <p style="color:#64748b; margin:0;">Upload CSV to add hundreds of students at once.</p>
    </div>
    <a href="manage_students.php" class="btn btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:16px 20px;border-radius:12px;margin-bottom:24px;"><?php echo $success; ?></div>
    <?php if (!empty($imported_students)): ?>
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
            <p style="font-weight:600;color:#166534;margin-bottom:8px;">📋 Imported Students (first 20):</p>
            <div style="max-height:250px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead style="background:#dcfce7;">
                        <tr><th style="padding:6px 10px;text-align:left;">Name</th><th style="padding:6px 10px;text-align:left;">Student ID</th><th style="padding:6px 10px;text-align:left;">Class</th><th style="padding:6px 10px;text-align:left;">Parent Phone</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($imported_students, 0, 20) as $s): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:6px 10px;"><?php echo htmlspecialchars($s['name']); ?></td>
                                <td style="padding:6px 10px;font-family:monospace;"><?php echo htmlspecialchars($s['student_id']); ?></td>
                                <td style="padding:6px 10px;"><?php echo htmlspecialchars($s['class']); ?></td>
                                <td style="padding:6px 10px;"><?php echo htmlspecialchars($s['parent_phone']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size:12px;color:#166534;margin-top:10px;">All parent accounts created with password: <strong>password123</strong></p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:16px 20px;border-radius:12px;margin-bottom:24px;"><?php echo implode('<br>', $errors); ?></div>
<?php endif; ?>

<?php if (!empty($failed_rows) && empty($errors)): ?>
    <div class="alert alert-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:16px 20px;border-radius:12px;margin-bottom:24px;max-height:250px;overflow-y:auto;">
        <strong>⚠️ Skipped Rows (<?php echo count($failed_rows); ?>):</strong><br>
        <?php echo implode('<br>', array_slice($failed_rows, 0, 30)); ?>
    </div>
<?php endif; ?>

<div style="background:#fff;border-radius:20px;padding:36px;box-shadow:0 4px 24px rgba(0,0,0,0.04);">
    <div style="background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:24px;border-left:4px solid #7c3aed;">
        <h5 style="font-weight:600;color:#0f172a;margin-bottom:8px;">📋 Instructions</h5>
        <ul style="color:#475569;padding-left:20px;margin:0;">
            <li>Download the sample template below.</li>
            <li>Required columns: <strong>First Name, Last Name, Class Name, Parent Phone</strong>.</li>
            <li>Class names are matched intelligently (JSS 1 = jss1).</li>
            <li>Parent accounts are created automatically.</li>
            <li><strong>NEW:</strong> Invoices are created automatically if a fee exists for the class.</li>
        </ul>
    </div>

    <div style="margin-bottom:24px;">
        <a href="?sample=1" class="btn btn-outline-primary" style="border-radius:10px;padding:10px 24px;">
            <i class="fas fa-download"></i> Download Sample CSV Template
        </a>
    </div>

    <form method="POST" enctype="multipart/form-data" action="">
        <?php echo csrfField(); ?>

        <div class="mb-3">
            <label style="font-weight:600;color:#0f172a;display:block;margin-bottom:8px;">Select CSV File:</label>
            <input type="file" name="csv_file" accept=".csv" required style="display:block;width:100%;padding:12px;border:2px dashed #cbd5e1;border-radius:12px;background:#f8fafc;font-size:14px;">
        </div>

        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);border:none;border-radius:12px;padding:14px 40px;font-weight:600;font-size:16px;width:100%;">
            <i class="fas fa-upload"></i> Upload & Import Students
        </button>
    </form>
</div>

<div style="background:#fff;border-radius:16px;padding:20px;margin-top:24px;">
    <h5 style="font-weight:600;color:#0f172a;margin-bottom:12px;">📊 Current Student Count</h5>
    <?php
        $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND status='Active'");
        $stmt->execute([$school_id]);
        $total = (int)$stmt->fetchColumn();
        $stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND status='Archived'");
        $stmt->execute([$school_id]);
        $archived = (int)$stmt->fetchColumn();
    ?>
    <div style="display:flex;gap:30px;flex-wrap:wrap;">
        <div><span style="font-size:28px;font-weight:700;color:#0f172a;"><?php echo number_format($total); ?></span><span style="font-size:14px;color:#64748b;margin-left:6px;">Active</span></div>
        <div><span style="font-size:28px;font-weight:700;color:#94a3b8;"><?php echo number_format($archived); ?></span><span style="font-size:14px;color:#64748b;margin-left:6px;">Archived</span></div>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>