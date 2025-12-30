<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($examId > 0) {
            $stmt = db()->prepare('DELETE FROM exams WHERE id = ?');
            $stmt->execute([$examId]);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'complete') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($examId > 0) {
            $stmt = db()->prepare('UPDATE exams SET is_completed = 1, completed_at = ? WHERE id = ?');
            $stmt->execute([now_utc_string(), $examId]);
        }
        header('Location: index.php');
        exit;
    }

    if ($action === 'reopen') {
        $examId = (int) ($_POST['exam_id'] ?? 0);
        if ($examId > 0) {
            $stmt = db()->prepare('UPDATE exams SET is_completed = 0, completed_at = NULL WHERE id = ?');
            $stmt->execute([$examId]);
        }
        header('Location: index.php');
        exit;
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$submissionsFilter = (string) ($_GET['submissions'] ?? 'all');
$sort = (string) ($_GET['sort'] ?? 'start_desc');

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = '(title LIKE ? OR exam_code LIKE ? OR EXISTS (SELECT 1 FROM submissions s WHERE s.exam_id = exams.id AND (s.student_first_name LIKE ? OR s.student_last_name LIKE ? OR s.student_name LIKE ?)))';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($status === 'completed') {
    $conditions[] = 'is_completed = 1';
} elseif ($status === 'active') {
    $conditions[] = 'is_completed = 0';
    $conditions[] = 'NOW() BETWEEN DATE_SUB(start_time, INTERVAL buffer_pre_minutes MINUTE) AND DATE_ADD(end_time, INTERVAL buffer_post_minutes MINUTE)';
} elseif ($status === 'upcoming') {
    $conditions[] = 'is_completed = 0';
    $conditions[] = 'start_time > NOW()';
}

$startBoundary = null;
$endBoundary = null;

if ($startDate !== '') {
    $startDt = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
    if ($startDt) {
        $startBoundary = $startDt->format('Y-m-d 00:00:00');
        $conditions[] = 'start_time >= ?';
        $params[] = $startBoundary;
    }
}

if ($endDate !== '') {
    $endDt = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
    if ($endDt) {
        $endBoundary = $endDt->format('Y-m-d 23:59:59');
        $conditions[] = 'start_time <= ?';
        $params[] = $endBoundary;
    }
}

if ($submissionsFilter === 'has') {
    $conditions[] = 'EXISTS (SELECT 1 FROM submissions s WHERE s.exam_id = exams.id)';
} elseif ($submissionsFilter === 'none') {
    $conditions[] = 'NOT EXISTS (SELECT 1 FROM submissions s WHERE s.exam_id = exams.id)';
}

$sql = 'SELECT * FROM exams';
if (count($conditions) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$orderBy = 'start_time DESC';
if ($sort === 'start_asc') {
    $orderBy = 'start_time ASC';
} elseif ($sort === 'end_desc') {
    $orderBy = 'end_time DESC';
} elseif ($sort === 'end_asc') {
    $orderBy = 'end_time ASC';
} elseif ($sort === 'title_asc') {
    $orderBy = 'title ASC';
} elseif ($sort === 'title_desc') {
    $orderBy = 'title DESC';
} elseif ($sort === 'exam_id_asc') {
    $orderBy = 'exam_code ASC';
} elseif ($sort === 'exam_id_desc') {
    $orderBy = 'exam_code DESC';
}
$sql .= ' ORDER BY ' . $orderBy;

$stmt = db()->prepare($sql);
$stmt->execute($params);
$exams = $stmt->fetchAll();
$pageTitle = 'Staff - Exam Portal';
$brandHref = 'index.php';
$brandText = 'Staff';
$logoPath = '../logo.png';
$cssPath = '../style.css';
$navActions = '';
require __DIR__ . '/../header.php';
?>
<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Exams</h1>
        <a class="btn btn-primary btn-lg" href="create_exam.php">Create Exam</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form class="row g-3" method="get">
                <div class="col-12">
                    <label class="form-label">Search</label>
                    <input class="form-control" type="text" name="q" placeholder="Exam title or ID" value="<?php echo e($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="upcoming" <?php echo $status === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Sort by</label>
                    <select class="form-select" name="sort">
                        <option value="start_desc" <?php echo $sort === 'start_desc' ? 'selected' : ''; ?>>Start date (newest)</option>
                        <option value="start_asc" <?php echo $sort === 'start_asc' ? 'selected' : ''; ?>>Start date (oldest)</option>
                        <option value="end_desc" <?php echo $sort === 'end_desc' ? 'selected' : ''; ?>>End date (newest)</option>
                        <option value="end_asc" <?php echo $sort === 'end_asc' ? 'selected' : ''; ?>>End date (oldest)</option>
                        <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                        <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                        <option value="exam_id_asc" <?php echo $sort === 'exam_id_asc' ? 'selected' : ''; ?>>Exam ID (A-Z)</option>
                        <option value="exam_id_desc" <?php echo $sort === 'exam_id_desc' ? 'selected' : ''; ?>>Exam ID (Z-A)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Start Date</label>
                    <input class="form-control" type="date" name="start_date" value="<?php echo e($startDate); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">End Date</label>
                    <input class="form-control" type="date" name="end_date" value="<?php echo e($endDate); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Submissions</label>
                    <select class="form-select" name="submissions">
                        <option value="all" <?php echo $submissionsFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="has" <?php echo $submissionsFilter === 'has' ? 'selected' : ''; ?>>Has submissions</option>
                        <option value="none" <?php echo $submissionsFilter === 'none' ? 'selected' : ''; ?>>No submissions</option>
                    </select>
                </div>
                <div class="col-12 d-grid">
                    <button class="btn btn-primary" type="submit">Search</button>
                </div>
                <div class="col-12">
                    <a class="btn btn-link px-0" href="index.php">Reset filters</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (count($exams) === 0): ?>
                <p class="text-muted mb-0">No exams match these filters.</p>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($exams as $exam): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?php echo e($exam['title']); ?> <small class="text-muted">(<?php echo e($exam['exam_code']); ?>)</small></div>
                                    <small class="text-muted"><?php echo e(format_datetime_display($exam['start_time'])); ?> to <?php echo e(format_datetime_display($exam['end_time'])); ?></small>
                                    <?php if (!empty($exam['is_completed'])): ?>
                                        <span class="badge text-bg-success ms-2">Completed</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <a class="btn btn-outline-secondary btn-sm" href="exam.php?id=<?php echo (int) $exam['id']; ?>">View</a>
                                    <?php if (empty($exam['is_completed'])): ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="complete">
                                            <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">
                                            <button class="btn btn-outline-success btn-sm" type="submit">Mark Completed</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="reopen">
                                            <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">
                                            <button class="btn btn-outline-primary btn-sm" type="submit">Reopen</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Delete this exam and all submissions?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="exam_id" value="<?php echo (int) $exam['id']; ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../footer.php'; ?>
