<?php
// school_owner/student_records.php - Student 10-year history viewer (live + archived)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/archive_functions.php';

requireRole(['Owner', 'Principal']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$search = trim($_GET['search'] ?? '');
$student_id = (int)($_GET['student_id'] ?? 0);

// Fetch selected student (if any)
$student = null;
if ($student_id > 0) {
    $stmt = $db->prepare("
        SELECT s.*, c.name AS class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.id = ? AND s.school_id = ?
    ");
    $stmt->execute([$student_id, $school_id]);
    $student = $stmt->fetch();
}

include_once __DIR__ . '/../includes/header.php';
?>

<style>
    .search-bar {
        background: #fff; border-radius: 16px; padding: 20px;
        border: 1px solid #f1f5f9; margin-bottom: 20px;
    }

    .search-results {
        background: #fff; border-radius: 16px; overflow: hidden;
        border: 1px solid #f1f5f9; margin-bottom: 20px;
    }
    .result-row {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 20px; border-bottom: 1px solid #f1f5f9;
        text-decoration: none; color: inherit; transition: 0.15s;
    }
    .result-row:last-child { border-bottom: none; }
    .result-row:hover { background: #fafafa; }
    .result-photo {
        width: 42px; height: 42px; border-radius: 50%; object-fit: cover;
        border: 2px solid #e2e8f0;
    }

    .student-header {
        background: linear-gradient(135deg, #7c3aed, #4f46e5);
        color: #fff; border-radius: 16px; padding: 24px;
        display: flex; align-items: center; gap: 20px; margin-bottom: 20px;
    }
    .student-header img {
        width: 80px; height: 80px; border-radius: 50%; object-fit: cover;
        border: 4px solid rgba(255,255,255,0.4);
    }
    .student-header .name { font-size: 1.5rem; font-weight: 800; }
    .student-header .meta { font-size: 0.9rem; opacity: 0.9; margin-top: 4px; }

    .timeline-card {
        background: #fff; border-radius: 16px; border: 1px solid #f1f5f9;
        overflow: hidden; margin-bottom: 20px;
    }
    .timeline-row {
        display: grid;
        grid-template-columns: 200px 100px 200px 150px 130px;
        gap: 16px;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
        font-size: 0.9rem;
    }
    .timeline-row:last-child { border-bottom: none; }
    .timeline-row:hover { background: #fafafa; }

    .term-cell strong { font-weight: 700; color: #0f172a; display: block; }
    .term-cell small { color: #64748b; }

    .badge-source {
        display: inline-block;
        padding: 2px 8px; border-radius: 12px; font-size: 0.7rem;
        font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .badge-live { background: #dbeafe; color: #1e40af; }
    .badge-archive { background: #fed7aa; color: #9a3412; }
    .badge-none { background: #f1f5f9; color: #64748b; }

    .balance-owing { color: #dc2626; font-weight: 700; }
    .balance-clear { color: #16a34a; font-weight: 700; }

    .action-btns { display: flex; gap: 6px; }

    /* Modal-style expanded term */
    .term-detail {
        background: #f8fafc;
        padding: 24px;
        border-bottom: 2px solid #e2e8f0;
    }

    .detail-section { margin-bottom: 20px; }
    .detail-section h5 {
        font-size: 0.9rem; font-weight: 700; color: #334155;
        text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;
    }
    .detail-table {
        width: 100%; background: #fff; border-radius: 10px;
        border-collapse: collapse; font-size: 0.85rem;
    }
    .detail-table th,
    .detail-table td {
        padding: 8px 12px; text-align: left; border-bottom: 1px solid #f1f5f9;
    }
    .detail-table th {
        background: #f8fafc; font-weight: 700; color: #475569;
        font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;
    }
    .detail-table tr:last-child td { border-bottom: none; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">📚 Student Records</h1>
        <p style="color:#64748b; margin:0;">Access full history for any student — including archived terms.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary" style="border-radius:10px;">Back</a>
</div>

<?php if (!$student): ?>

    <!-- Search -->
    <div class="search-bar">
        <form method="GET" action="">
            <div style="display:flex; gap:12px;">
                <input type="text" name="search" class="form-control" 
                       placeholder="Search by student name, ID, or parent phone..." 
                       value="<?php echo htmlspecialchars($search); ?>" autofocus
                       style="border-radius:10px; font-size:1rem; padding:12px 16px;">
                <button type="submit" class="btn btn-primary" style="border-radius:10px; padding:12px 32px; font-weight:600;">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </div>

    <?php if (!empty($search)): 
        $sStmt = $db->prepare("
            SELECT s.id, s.first_name, s.last_name, s.student_id, s.photo_path, s.status, s.parent_phone,
                   c.name AS class_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.school_id = ? 
              AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_id LIKE ? OR s.parent_phone LIKE ?)
            ORDER BY s.last_name, s.first_name
            LIMIT 50
        ");
        $like = '%' . $search . '%';
        $sStmt->execute([$school_id, $like, $like, $like, $like]);
        $results = $sStmt->fetchAll();
    ?>
        <?php if (empty($results)): ?>
            <div class="alert alert-info">No students found for "<?php echo htmlspecialchars($search); ?>".</div>
        <?php else: ?>
            <div class="search-results">
                <?php foreach ($results as $r): 
                    $photo = ($r['photo_path'] && $r['photo_path'] !== 'default_student.png') 
                        ? BASE_URL . 'uploads/student_photos/' . $r['photo_path'] 
                        : BASE_URL . 'assets/images/default_avatar.png';
                ?>
                    <a href="?student_id=<?php echo $r['id']; ?>" class="result-row">
                        <img src="<?php echo $photo; ?>" class="result-photo" alt="">
                        <div style="flex:1;">
                            <div style="font-weight:600; color:#0f172a;">
                                <?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?>
                            </div>
                            <div style="font-size:0.8rem; color:#94a3b8;">
                                <?php echo htmlspecialchars($r['student_id']); ?>
                                • <?php echo htmlspecialchars($r['class_name'] ?? 'N/A'); ?>
                                • <?php echo htmlspecialchars($r['parent_phone'] ?? ''); ?>
                            </div>
                        </div>
                        <div>
                            <span class="badge bg-<?php echo $r['status'] === 'Active' ? 'success' : 'secondary'; ?>">
                                <?php echo $r['status']; ?>
                            </span>
                        </div>
                        <div style="color:#7c3aed; font-weight:600;">
                            View History <i class="fas fa-arrow-right"></i>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

<?php else: 

    // Fetch full history
    $terms = getAllStudentTerms($student['id'], $school_id);
    $summaries = [];
    foreach ($terms as $t) {
        $summaries[] = getStudentTermSummary($student['id'], $school_id, $t['term_name'], $t['session_year']);
    }

    $photo_url = ($student['photo_path'] && $student['photo_path'] !== 'default_student.png')
        ? BASE_URL . 'uploads/student_photos/' . $student['photo_path']
        : BASE_URL . 'assets/images/default_avatar.png';

    $expanded = (int)($_GET['expand'] ?? -1);
?>

    <!-- Back + Actions -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="student_records.php" class="btn btn-outline-secondary" style="border-radius:10px;">
            <i class="fas fa-arrow-left"></i> Back to Search
        </a>
        <div style="display:flex; gap:8px;">
            <a href="student_timeline_print.php?student_id=<?php echo $student['id']; ?>" target="_blank" class="btn btn-primary" style="border-radius:10px;">
                <i class="fas fa-print"></i> Print Full Timeline
            </a>
            <a href="view_student.php?id=<?php echo $student['id']; ?>" class="btn btn-outline-primary" style="border-radius:10px;">
                <i class="fas fa-user"></i> Current Profile
            </a>
        </div>
    </div>

    <!-- Student Header -->
    <div class="student-header">
        <img src="<?php echo $photo_url; ?>" alt="">
        <div style="flex:1;">
            <div class="name"><?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name']); ?></div>
            <div class="meta">
                <?php echo htmlspecialchars($student['student_id']); ?>
                • <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?>
                • <?php echo htmlspecialchars($student['gender'] ?? ''); ?>
            </div>
            <div class="meta">
                Parent: <?php echo htmlspecialchars($student['parent_name'] ?? 'N/A'); ?>
                • <?php echo htmlspecialchars($student['parent_phone'] ?? ''); ?>
            </div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:2rem; font-weight:800;"><?php echo count($terms); ?></div>
            <div style="font-size:0.8rem; opacity:0.9;">Terms on record</div>
        </div>
    </div>

    <?php if (empty($terms)): ?>
        <div class="alert alert-info">No term records found for this student yet.</div>
    <?php else: ?>
        <div class="timeline-card">
            <div style="background:#f8fafc; padding:14px 20px; border-bottom:2px solid #e2e8f0; font-weight:700; color:#334155; font-size:0.85rem; text-transform:uppercase; letter-spacing:0.5px;">
                Academic History
            </div>

            <?php foreach ($summaries as $i => $s): 
                $isExpanded = ($expanded === $i);
                $src = $s['source'];
            ?>
                <div class="timeline-row" style="<?php echo $i % 2 === 1 ? 'background:#fafafa;' : ''; ?>">
                    <div class="term-cell">
                        <strong><?php echo htmlspecialchars($s['term_name']); ?></strong>
                        <small><?php echo htmlspecialchars($s['session_year']); ?></small>
                    </div>
                    <div>
                        <span class="badge-source badge-<?php echo $src; ?>">
                            <?php echo $src === 'live' ? 'Current' : ($src === 'archive' ? 'Archived' : 'N/A'); ?>
                        </span>
                    </div>
                    <div style="font-size:0.85rem;">
                        <?php if ($s['has_scores']): ?>
                            <span style="color:#16a34a;"><i class="fas fa-check"></i> <?php echo $s['subject_count']; ?> subjects</span>
                            · Avg: <strong><?php echo $s['average']; ?>%</strong>
                        <?php else: ?>
                            <span style="color:#94a3b8;">No scores</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:0.85rem;">
                        P: <?php echo $s['present']; ?> · A: <?php echo $s['absent']; ?> · L: <?php echo $s['late']; ?>
                    </div>
                    <div style="text-align:right;">
                        <?php if ($s['balance'] > 0): ?>
                            <span class="balance-owing">₦<?php echo number_format($s['balance']); ?> owing</span>
                        <?php else: ?>
                            <span class="balance-clear">✓ Paid</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="timeline-row" style="grid-template-columns:1fr; padding:0;">
                    <div style="padding:0 20px 12px;">
                        <a href="?student_id=<?php echo $student['id']; ?>&expand=<?php echo $isExpanded ? '-1' : $i; ?>" 
                           class="btn btn-sm <?php echo $isExpanded ? 'btn-secondary' : 'btn-outline-primary'; ?>" 
                           style="border-radius:8px; font-size:0.8rem;">
                            <?php echo $isExpanded ? '▲ Hide Details' : '▼ View Details'; ?>
                        </a>
                    </div>
                </div>

                <?php if ($isExpanded): 
                    $fullScores = getStudentScoresForTerm($student['id'], $school_id, $s['term_name'], $s['session_year']);
                    $fullAttendance = getStudentAttendanceForTerm($student['id'], $school_id, $s['term_name'], $s['session_year']);
                    $fullFees = getStudentFeesForTerm($student['id'], $school_id, $s['term_name'], $s['session_year']);
                ?>
                    <div class="term-detail">
                        <!-- Scores -->
                        <div class="detail-section">
                            <h5><i class="fas fa-graduation-cap"></i> Academic Scores</h5>
                            <?php if (empty($fullScores['scores'])): ?>
                                <p style="color:#94a3b8; font-size:0.85rem;">No scores recorded.</p>
                            <?php else: ?>
                                <table class="detail-table">
                                    <thead>
                                        <tr>
                                            <th>Subject</th>
                                            <th>CA1</th>
                                            <th>CA2</th>
                                            <th>CA3</th>
                                            <th>Exam</th>
                                            <th>Total</th>
                                            <th>Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fullScores['scores'] as $sc): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($sc['subject_name'] ?? 'Unknown'); ?></strong></td>
                                                <td><?php echo number_format((float)($sc['ca1'] ?? 0), 1); ?></td>
                                                <td><?php echo number_format((float)($sc['ca2'] ?? 0), 1); ?></td>
                                                <td><?php echo number_format((float)($sc['ca3'] ?? 0), 1); ?></td>
                                                <td><?php echo number_format((float)($sc['exam'] ?? 0), 1); ?></td>
                                                <td><strong><?php echo number_format((float)($sc['total'] ?? 0), 1); ?></strong></td>
                                                <td><strong><?php echo htmlspecialchars($sc['grade'] ?? '—'); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>

                        <!-- Attendance -->
                        <div class="detail-section">
                            <h5><i class="fas fa-calendar-check"></i> Attendance</h5>
                            <div style="display:flex; gap:20px; font-size:0.9rem;">
                                <span><strong style="color:#16a34a;"><?php echo $fullAttendance['summary']['Present']; ?></strong> Present</span>
                                <span><strong style="color:#dc2626;"><?php echo $fullAttendance['summary']['Absent']; ?></strong> Absent</span>
                                <span><strong style="color:#f59e0b;"><?php echo $fullAttendance['summary']['Late']; ?></strong> Late</span>
                                <span><strong style="color:#3b82f6;"><?php echo $fullAttendance['summary']['Excused']; ?></strong> Excused</span>
                            </div>
                        </div>

                        <!-- Fees -->
                        <div class="detail-section">
                            <h5><i class="fas fa-money-bill"></i> Fees</h5>
                            <?php if (!empty($fullFees['invoice'])): ?>
                                <table class="detail-table" style="margin-bottom:12px;">
                                    <tbody>
                                        <tr><td style="width:200px; color:#64748b;">Total Fee</td><td><strong>₦<?php echo number_format((float)$fullFees['invoice']['total_amount']); ?></strong></td></tr>
                                        <tr><td style="color:#64748b;">Amount Paid</td><td><strong style="color:#16a34a;">₦<?php echo number_format((float)$fullFees['invoice']['paid_amount']); ?></strong></td></tr>
                                        <tr><td style="color:#64748b;">Balance</td><td><strong style="color:<?php echo (float)$fullFees['invoice']['balance'] > 0 ? '#dc2626' : '#16a34a'; ?>;">₦<?php echo number_format((float)$fullFees['invoice']['balance']); ?></strong></td></tr>
                                    </tbody>
                                </table>
                                <?php if (!empty($fullFees['payments'])): ?>
                                    <p style="font-size:0.85rem; color:#475569; font-weight:600; margin-bottom:8px;">Payment History</p>
                                    <table class="detail-table">
                                        <thead>
                                            <tr><th>Date</th><th>Amount</th><th>Method</th><th>Receipt</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fullFees['payments'] as $p): ?>
                                                <tr>
                                                    <td><?php echo date('M d, Y', strtotime($p['payment_date'] ?? '')); ?></td>
                                                    <td>₦<?php echo number_format((float)($p['amount_paid'] ?? 0)); ?></td>
                                                    <td><?php echo htmlspecialchars($p['payment_method'] ?? ''); ?></td>
                                                    <td><small><?php echo htmlspecialchars($p['receipt_number'] ?? ''); ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            <?php else: ?>
                                <p style="color:#94a3b8; font-size:0.85rem;">No fee record for this term.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Report card -->
                        <div class="detail-section" style="margin-bottom:0;">
                            <h5><i class="fas fa-file-lines"></i> Report Card</h5>
                            <?php if ($src === 'live' && $s['has_scores']): ?>
                                <a href="print_selected_report_cards.php?student_ids[]=<?php echo $student['id']; ?>&term=<?php echo urlencode($s['term_name']); ?>&session=<?php echo urlencode($s['session_year']); ?>&class_id=<?php echo (int)$student['class_id']; ?>" 
                                   target="_blank" 
                                   class="btn btn-sm btn-primary" style="border-radius:8px;">
                                    <i class="fas fa-print"></i> Print Report Card
                                </a>
                            <?php elseif ($src === 'archive'): ?>
                                <a href="print_archived_report_card.php?student_id=<?php echo $student['id']; ?>&term=<?php echo urlencode($s['term_name']); ?>&session=<?php echo urlencode($s['session_year']); ?>" 
                                   target="_blank" 
                                   class="btn btn-sm btn-warning" style="border-radius:8px;">
                                    <i class="fas fa-print"></i> Print Archived Report Card
                                </a>
                            <?php else: ?>
                                <p style="color:#94a3b8; font-size:0.85rem; margin:0;">No report card available for this term.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>