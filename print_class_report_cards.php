<?php
// school_owner/print_class_report_cards.php - Select a class + term and print all (or selected) report cards
// v2 - urldecode term/session to prevent "Term+1" bug
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

// Fetch school info for the term/session defaults
$schoolStmt = $db->prepare("SELECT current_term, current_session, school_name FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch();
$current_term = $schoolInfo['current_term'] ?? 'Term 1';
$current_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

// Fetch classes
$classesStmt = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY order_number, name");
$classesStmt->execute([$school_id]);
$classes = $classesStmt->fetchAll();

// Selected values — normalize with urldecode and replace "+" with space
$selected_class = (int)($_GET['class_id'] ?? 0);
if ($selected_class === 0 && !empty($classes)) $selected_class = (int)$classes[0]['id'];

$selected_term = trim(str_replace('+', ' ', urldecode($_GET['term'] ?? $current_term)));
$selected_session = trim(str_replace('+', ' ', urldecode($_GET['session'] ?? $current_session)));

// Load students for selected class
$students = [];
if ($selected_class > 0) {
    $st = $db->prepare("
        SELECT s.id, s.first_name, s.last_name, s.student_id, s.photo_path, c.name AS class_name
        FROM students s
        JOIN classes c ON s.class_id = c.id
        WHERE s.school_id = ? AND s.class_id = ? AND s.status = 'Active'
        ORDER BY s.last_name, s.first_name
    ");
    $st->execute([$school_id, $selected_class]);
    $students = $st->fetchAll();
}

include_once __DIR__ . '/../includes/header.php';
?>

<style>
    .student-check-row { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; }
    .student-check-row:last-child { border-bottom: none; }
    .student-check-row img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; }
    .student-check-row input[type=checkbox] { width: 18px; height: 18px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">🖨️ Print Report Cards</h1>
        <p class="text-muted">Print report cards for a whole class, or select individual students.</p>
    </div>
    <a href="advanced.php" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<!-- Step 1: Choose class/term/session -->
<div class="card mb-4" style="border-radius:16px;">
    <div class="card-body">
        <h5 style="font-weight:700;margin-bottom:16px;">Step 1: Select Class & Term</h5>
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Class</label>
                <select name="class_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $selected_class == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Term</label>
                <select name="term" class="form-control" onchange="this.form.submit()">
                    <option value="Term 1" <?php echo $selected_term == 'Term 1' ? 'selected' : ''; ?>>Term 1</option>
                    <option value="Term 2" <?php echo $selected_term == 'Term 2' ? 'selected' : ''; ?>>Term 2</option>
                    <option value="Term 3" <?php echo $selected_term == 'Term 3' ? 'selected' : ''; ?>>Term 3</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Session</label>
                <input type="text" name="session" class="form-control" value="<?php echo htmlspecialchars($selected_session); ?>" readonly>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                    <i class="fas fa-sync"></i> Load Students
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($selected_class > 0): ?>
    <?php if (empty($students)): ?>
        <div class="alert alert-info">No active students in this class.</div>
    <?php else: ?>
        <!-- Step 2: Select students -->
        <form method="POST" action="print_selected_report_cards.php" target="_blank" id="printForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
            <input type="hidden" name="term" value="<?php echo htmlspecialchars($selected_term); ?>">
            <input type="hidden" name="session" value="<?php echo htmlspecialchars($selected_session); ?>">

            <div class="card mb-4" style="border-radius:16px;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 style="font-weight:700;margin:0;">Step 2: Select Students (<?php echo count($students); ?>)</h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="checkAll(true)">Check All</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="checkAll(false)">Uncheck All</button>
                        </div>
                    </div>

                    <div style="max-height:500px;overflow-y:auto;border:1px solid #f1f5f9;border-radius:12px;">
                        <?php foreach ($students as $s): 
                            $photo = ($s['photo_path'] && $s['photo_path'] !== 'default_student.png')
                                ? BASE_URL . 'uploads/student_photos/' . $s['photo_path']
                                : BASE_URL . 'assets/images/default_avatar.png';
                        ?>
                            <label class="student-check-row" style="cursor:pointer;">
                                <input type="checkbox" name="student_ids[]" value="<?php echo $s['id']; ?>" class="student-check" checked>
                                <img src="<?php echo $photo; ?>" alt="">
                                <div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></div>
                                    <div style="font-size:12px;color:#94a3b8;"><?php echo htmlspecialchars($s['student_id']); ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div id="selectedCount" style="margin-top:12px;font-size:13px;color:#64748b;"></div>

                    <div style="text-align:right;margin-top:20px;">
                        <button type="submit" class="btn btn-primary" style="border-radius:10px;padding:12px 40px;font-weight:600;background:linear-gradient(135deg,#7c3aed,#6d28d9);border:none;">
                            <i class="fas fa-print"></i> Print Selected Report Cards
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <script>
        function checkAll(checked) {
            document.querySelectorAll('.student-check').forEach(cb => cb.checked = checked);
            updateCount();
        }
        function updateCount() {
            const n = document.querySelectorAll('.student-check:checked').length;
            document.getElementById('selectedCount').textContent = n + ' student(s) selected';
        }
        document.querySelectorAll('.student-check').forEach(cb => cb.addEventListener('change', updateCount));
        updateCount();
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>