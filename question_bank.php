<?php
// teacher/question_bank.php - Search & View Question Bank (PDO version)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Teacher']);

$db = getDB();
$teacher_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['school_id'];

// Permission check — redirect to Request Access if missing
if (!hasPermission(PERM_VIEW_QUESTION_BANK)) {
    $_SESSION['error'] = "You do not have permission to access the Question Bank. Please request access.";
    header("Location: request_permission.php");
    exit;
}

// Can this teacher add questions?
$can_add = hasPermission(PERM_ADD_QUESTIONS);

// ---------- AJAX SEARCH ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    $search = trim($_GET['search'] ?? '');
    $subject_id = (int)($_GET['subject_id'] ?? 0);
    $class_id = (int)($_GET['class_id'] ?? 0);
    $term = $_GET['term'] ?? '';
    $type = $_GET['type'] ?? '';
    $difficulty = $_GET['difficulty'] ?? '';

    $sql = "SELECT q.id, q.question_text, q.question_type, q.marks, q.difficulty,
                   s.name AS subject_name, c.name AS class_name, t.topic
            FROM exam_questions q
            LEFT JOIN subjects s ON q.subject_id = s.id
            LEFT JOIN classes c ON q.class_id = c.id
            LEFT JOIN scheme_topics t ON q.topic_id = t.id
            WHERE q.school_id = ?";
    $params = [$school_id];

    if (!empty($search)) {
        $sql .= " AND (q.question_text LIKE ? OR s.name LIKE ? OR c.name LIKE ? OR t.topic LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($subject_id > 0) {
        $sql .= " AND q.subject_id = ?";
        $params[] = $subject_id;
    }
    if ($class_id > 0) {
        $sql .= " AND q.class_id = ?";
        $params[] = $class_id;
    }
    if (!empty($term)) {
        $sql .= " AND t.term = ?";
        $params[] = $term;
    }
    if (!empty($type)) {
        $sql .= " AND q.question_type = ?";
        $params[] = $type;
    }
    if (!empty($difficulty)) {
        $sql .= " AND q.difficulty = ?";
        $params[] = $difficulty;
    }
    $sql .= " ORDER BY q.id DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $questions = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($questions);
    exit;
}

// ---------- FETCH DROPDOWN DATA ----------
$subjects = $db->prepare("SELECT id, name FROM subjects WHERE school_id = ? AND status='active' ORDER BY name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4" style="flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">🔍 Question Bank</h1>
        <p style="color:#64748b; margin: 0;">Search and view questions. Add one at a time, or many in one sitting.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap: wrap;">
        <?php if ($can_add): ?>
            <a href="add_multiple_questions.php" class="btn btn-primary" style="background: linear-gradient(135deg,#7c3aed,#6d28d9); border:none; border-radius:10px; padding: 8px 16px;">
                <i class="fas fa-layer-group"></i> Add Multiple Questions
            </a>
            <a href="add_question.php" class="btn btn-outline-primary" style="border-radius:10px; padding: 8px 14px; font-size: 0.85rem;">
                <i class="fas fa-plus"></i> Add One
            </a>
            <a href="bulk_add_questions.php" class="btn btn-outline-secondary" style="border-radius:10px; padding: 8px 14px; font-size: 0.85rem;">
                <i class="fas fa-file-import"></i> Bulk Import
            </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px; padding: 8px 14px; font-size: 0.85rem;">Back</a>
    </div>
</div>

<!-- Filters -->
<div style="background:#fff; border-radius:16px; padding:20px; border:1px solid #f1f5f9; margin-bottom:24px;">
    <div class="row g-3">
        <div class="col-md-4">
            <input type="text" id="liveSearch" class="form-control" placeholder="Search questions...">
        </div>
        <div class="col-md-2">
            <select id="filterSubject" class="form-control">
                <option value="0">All Subjects</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select id="filterClass" class="form-control">
                <option value="0">All Classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select id="filterType" class="form-control">
                <option value="">All Types</option>
                <option value="mcq">MCQ</option>
                <option value="theory">Theory</option>
                <option value="fill_blank">Fill in the Blank</option>
                <option value="true_false">True/False</option>
            </select>
        </div>
        <div class="col-md-2">
            <select id="filterDifficulty" class="form-control">
                <option value="">All Levels</option>
                <option value="easy">Easy</option>
                <option value="medium">Medium</option>
                <option value="hard">Hard</option>
            </select>
        </div>
    </div>
    <div id="resultCount" style="font-size:13px; color:#94a3b8; margin-top:10px;"></div>
</div>

<!-- Questions List -->
<div id="questionList"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const filterSubject = document.getElementById('filterSubject');
    const filterClass = document.getElementById('filterClass');
    const filterType = document.getElementById('filterType');
    const filterDifficulty = document.getElementById('filterDifficulty');
    const resultCount = document.getElementById('resultCount');
    const questionList = document.getElementById('questionList');
    let debounceTimer;

    function loadQuestions() {
        const params = new URLSearchParams({
            ajax: 'search',
            search: searchInput.value.trim(),
            subject_id: filterSubject.value,
            class_id: filterClass.value,
            type: filterType.value,
            difficulty: filterDifficulty.value,
            term: ''
        });
        fetch('?' + params.toString())
            .then(res => res.json())
            .then(data => {
                if (data.length === 0) {
                    questionList.innerHTML = '<p class="text-center text-muted" style="padding:40px;">No questions found.</p>';
                    resultCount.textContent = '0 questions';
                    return;
                }
                resultCount.textContent = data.length + ' questions found';
                let html = '<div class="list-group">';
                data.forEach(q => {
                    const typeBadge = q.question_type === 'mcq' ? 'MCQ' :
                                      q.question_type === 'true_false' ? 'True/False' :
                                      q.question_type === 'fill_blank' ? 'Fill Blank' : 'Theory';
                    const typeColor = q.question_type === 'mcq' ? '#7c3aed' :
                                      q.question_type === 'true_false' ? '#16a34a' :
                                      q.question_type === 'fill_blank' ? '#ea580c' : '#2563eb';
                    html += `
                        <div class="list-group-item" style="border-radius:12px; margin-bottom:8px; border:1px solid #f1f5f9;">
                            <div class="d-flex justify-content-between align-items-center" style="margin-bottom:8px;">
                                <span class="badge" style="background:${typeColor}; color:white; padding:5px 12px; border-radius:20px;">${typeBadge}</span>
                                <small class="text-muted">${q.subject_name || 'N/A'} • ${q.class_name || 'N/A'}${q.topic ? ' • ' + q.topic : ''}</small>
                            </div>
                            <p style="margin-bottom:6px; color:#0f172a;">${escapeHtml(q.question_text)}</p>
                            <small class="text-muted">Marks: ${q.marks} | Difficulty: ${q.difficulty}</small>
                        </div>
                    `;
                });
                html += '</div>';
                questionList.innerHTML = html;
            })
            .catch(error => {
                questionList.innerHTML = '<div class="alert alert-danger">Error loading questions.</div>';
            });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadQuestions, 300);
    });
    filterSubject.addEventListener('change', loadQuestions);
    filterClass.addEventListener('change', loadQuestions);
    filterType.addEventListener('change', loadQuestions);
    filterDifficulty.addEventListener('change', loadQuestions);

    loadQuestions();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>