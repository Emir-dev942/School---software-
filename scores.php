<?php
// parent/scores.php - Live results view for parents (v2)
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

// Fetch full student record (for class_id)
$studentStmt = $db->prepare("SELECT s.*, c.name AS class_name, c.id AS class_id FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.school_id = ?");
$studentStmt->execute([$activeChild['id'], $school_id]);
$student = $studentStmt->fetch();

// School info
$schoolStmt = $db->prepare("SELECT current_term, current_session, school_name FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();
$current_term = $school['current_term'] ?? 'Term 1';
$current_session = $school['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

$selected_term = $selected_term ?: $current_term;
$selected_session = $selected_session ?: $current_session;

// Term list
$termsStmt = $db->prepare("
    SELECT DISTINCT term_name, session_year
    FROM exam_scores
    WHERE student_id = ? AND school_id = ?
    ORDER BY session_year DESC, term_name DESC
");
$termsStmt->execute([$activeChild['id'], $school_id]);
$availableTerms = $termsStmt->fetchAll();

// Scores
$scoreStmt = $db->prepare("
    SELECT sub.name AS subject_name, es.ca1, es.ca2, es.ca3, es.exam, es.total, es.grade,
           es.is_submitted, es.is_approved
    FROM exam_scores es
    JOIN subjects sub ON es.subject_id = sub.id
    WHERE es.student_id = ? AND es.school_id = ? AND es.term_name = ? AND es.session_year = ?
    ORDER BY sub.name
");
$scoreStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$scores = $scoreStmt->fetchAll();

// Calculate totals
$totalMarks = 0;
$subjectCount = count($scores);
foreach ($scores as $s) $totalMarks += (float)$s['total'];
$average = $subjectCount > 0 ? round($totalMarks / $subjectCount, 2) : 0;

$overallGrade = '—';
if ($average >= 80) $overallGrade = 'A';
elseif ($average >= 70) $overallGrade = 'B';
elseif ($average >= 60) $overallGrade = 'C';
elseif ($average >= 50) $overallGrade = 'D';
elseif ($average >= 40) $overallGrade = 'E';
elseif ($subjectCount > 0) $overallGrade = 'F';

// Position with RANK
$positionStmt = $db->prepare("
    SELECT student_id, avg_score, position FROM (
        SELECT 
            st.id AS student_id,
            AVG(es.total) AS avg_score,
            RANK() OVER (ORDER BY AVG(es.total) DESC) AS position
        FROM students st
        JOIN exam_scores es ON es.student_id = st.id 
            AND es.school_id = st.school_id 
            AND es.term_name = ? 
            AND es.session_year = ?
        WHERE st.school_id = ? AND st.class_id = ? AND st.status = 'Active'
        GROUP BY st.id
    ) AS ranked
    WHERE avg_score IS NOT NULL
    ORDER BY position ASC
");
$positionStmt->execute([$selected_term, $selected_session, $school_id, $student['class_id']]);
$ranked = $positionStmt->fetchAll();
$classRankedCount = count($ranked);
$position = 0;
$classAverage = 0;
if ($classRankedCount > 0) {
    $sumAvg = 0;
    foreach ($ranked as $r) $sumAvg += (float)$r['avg_score'];
    $classAverage = round($sumAvg / $classRankedCount, 2);
}
foreach ($ranked as $r) {
    if ((int)$r['student_id'] === (int)$activeChild['id']) {
        $position = (int)$r['position'];
        break;
    }
}

// Did they beat the class average?
$vsClass = '—';
if ($average > 0 && $classAverage > 0) {
    $diff = $average - $classAverage;
    if ($diff > 0.5) $vsClass = '+' . round($diff, 1) . '% above';
    elseif ($diff < -0.5) $vsClass = round($diff, 1) . '% below';
    else $vsClass = 'At class avg';
}

function ordinal($n) {
    if ($n <= 0) return '—';
    $suffix = 'th';
    if (!in_array(($n % 100), [11,12,13])) {
        switch ($n % 10) {
            case 1: $suffix = 'st'; break;
            case 2: $suffix = 'nd'; break;
            case 3: $suffix = 'rd'; break;
        }
    }
    return $n . $suffix;
}

function scoreColor($total) {
    if ($total >= 70) return '#16a34a';
    if ($total >= 50) return '#f59e0b';
    return '#dc2626';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results | Parent Portal</title>
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
        .chip.active { background: linear-gradient(135deg, #7c3aed, #4f46e5); border-color: #7c3aed; color: white; }
        .chip.active .chip-name { color: white; }
        .chip.active .chip-class { color: rgba(255,255,255,0.7); }
        .chip img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9; }
        .chip.active img { border-color: rgba(255,255,255,0.4); }
        .chip-name { font-weight: 700; font-size: 0.82rem; white-space: nowrap; }
        .chip-class { font-size: 0.68rem; color: #94a3b8; white-space: nowrap; }

        /* Term selector */
        .term-selector { background: white; border-radius: 14px; padding: 12px; margin-top: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; gap: 8px; align-items: center; }
        .term-selector select { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; font-size: 0.85rem; font-weight: 600; flex: 1; }

        /* Big summary card */
        .summary-hero {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            border-radius: 20px; padding: 24px; margin-top: 16px;
            color: white; text-align: center;
            box-shadow: 0 8px 24px rgba(124,58,237,0.25);
        }
        .summary-hero .big-number { font-size: 3rem; font-weight: 800; line-height: 1; }
        .summary-hero .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; font-weight: 700; margin-top: 4px; }
        .summary-hero .grade { display: inline-block; background: rgba(255,255,255,0.2); padding: 4px 14px; border-radius: 20px; font-size: 0.85rem; font-weight: 700; margin-top: 8px; }
        .summary-hero .meta-row { display: flex; gap: 16px; justify-content: center; margin-top: 16px; font-size: 0.75rem; }
        .summary-hero .meta-item { text-align: center; opacity: 0.9; }
        .summary-hero .meta-item strong { display: block; font-size: 1rem; font-weight: 800; margin-top: 2px; }

        /* Subject list */
        .section-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; margin: 20px 0 10px; }
        .score-list { display: flex; flex-direction: column; gap: 8px; }

        .score-card { background: white; border-radius: 14px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .score-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .subject-name { font-weight: 700; color: #0f172a; font-size: 0.95rem; }
        .badge-status { padding: 3px 10px; border-radius: 20px; font-size: 0.65rem; font-weight: 700; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-submitted { background: #fef3c7; color: #92400e; }
        .badge-draft { background: #f1f5f9; color: #64748b; }

        .marks-row { display: flex; gap: 6px; }
        .mark-chip { background: #f8fafc; border-radius: 8px; padding: 8px 6px; text-align: center; flex: 1; }
        .mark-chip .val { font-weight: 700; font-size: 0.85rem; color: #0f172a; }
        .mark-chip .lbl { font-size: 0.6rem; color: #94a3b8; text-transform: uppercase; font-weight: 600; margin-top: 2px; }
        .mark-chip.total { background: linear-gradient(135deg, #ede9fe, #dbeafe); }
        .mark-chip.total .val { color: #6d28d9; font-size: 0.95rem; }

        /* Grade badge */
        .grade-badge { display: inline-block; padding: 4px 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 800; margin-left: 8px; }
        .grade-A { background: #dcfce7; color: #166534; }
        .grade-B { background: #dbeafe; color: #1e40af; }
        .grade-C { background: #fef3c7; color: #92400e; }
        .grade-D { background: #fed7aa; color: #9a3412; }
        .grade-E { background: #fecaca; color: #991b1b; }
        .grade-F { background: #fee2e2; color: #991b1b; }

        .empty-state { background: white; border-radius: 16px; padding: 60px 20px; text-align: center; color: #94a3b8; margin-top: 16px; }
        .empty-state i { font-size: 2.5rem; opacity: 0.5; margin-bottom: 12px; display: block; }

        /* Progress bar per subject */
        .progress-thin { height: 4px; background: #f1f5f9; border-radius: 4px; margin-top: 10px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.4s; }
    </style>
</head>
<body>
<div class="container">
    <!-- Top Bar -->
    <div class="topbar">
        <a href="index.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h3>📊 Results</h3>
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
            <i class="fas fa-calendar" style="color:#7c3aed;"></i>
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

    <?php if (empty($scores)): ?>
        <div class="empty-state">
            <i class="fas fa-chart-line"></i>
            <strong style="color:#475569;">No scores yet for <?php echo htmlspecialchars($selected_term); ?></strong>
            <p style="margin-top:6px;font-size:0.85rem;">Results appear as soon as teachers submit them.</p>
        </div>
    <?php else: ?>

        <!-- Summary Hero -->
        <div class="summary-hero">
            <div class="big-number"><?php echo $average; ?>%</div>
            <div class="label">Overall Average</div>
            <div class="grade">Grade <?php echo $overallGrade; ?></div>

            <div class="meta-row">
                <div class="meta-item">
                    Position
                    <strong><?php echo $position > 0 ? ordinal($position) . ' / ' . $classRankedCount : '—'; ?></strong>
                </div>
                <div class="meta-item">
                    Subjects
                    <strong><?php echo $subjectCount; ?></strong>
                </div>
                <div class="meta-item">
                    vs Class
                    <strong style="font-size:0.8rem;"><?php echo htmlspecialchars($vsClass); ?></strong>
                </div>
            </div>
        </div>

        <!-- Subject List -->
        <div class="section-title">Subject Performance</div>
        <div class="score-list">
            <?php foreach ($scores as $s):
                $total = (float)$s['total'];
                $color = scoreColor($total);
                $percent = min(100, max(0, $total));
            ?>
                <div class="score-card">
                    <div class="score-head">
                        <div class="subject-name"><?php echo htmlspecialchars($s['subject_name']); ?></div>
                        <div>
                            <?php if ($s['is_approved']): ?>
                                <span class="badge-status badge-approved">✓ Approved</span>
                            <?php elseif ($s['is_submitted']): ?>
                                <span class="badge-status badge-submitted">⏳ Pending</span>
                            <?php else: ?>
                                <span class="badge-status badge-draft">Draft</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="marks-row">
                        <div class="mark-chip">
                            <div class="val"><?php echo number_format((float)$s['ca1'], 0); ?></div>
                            <div class="lbl">CA1</div>
                        </div>
                        <div class="mark-chip">
                            <div class="val"><?php echo number_format((float)$s['ca2'], 0); ?></div>
                            <div class="lbl">CA2</div>
                        </div>
                        <div class="mark-chip">
                            <div class="val"><?php echo number_format((float)$s['ca3'], 0); ?></div>
                            <div class="lbl">CA3</div>
                        </div>
                        <div class="mark-chip">
                            <div class="val"><?php echo number_format((float)$s['exam'], 0); ?></div>
                            <div class="lbl">Exam</div>
                        </div>
                        <div class="mark-chip total">
                            <div class="val"><?php echo number_format($total, 0); ?></div>
                            <div class="lbl">Total</div>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">
                        <div style="font-size:0.75rem;color:#64748b;">
                            Score: <strong style="color:<?php echo $color; ?>;"><?php echo number_format($total, 0); ?> / 100</strong>
                        </div>
                        <div class="grade-badge grade-<?php echo $s['grade']; ?>">
                            <?php echo htmlspecialchars($s['grade'] ?? '—'); ?>
                        </div>
                    </div>

                    <div class="progress-thin">
                        <div class="progress-fill" style="width: <?php echo $percent; ?>%; background: <?php echo $color; ?>;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>
</body>
</html>