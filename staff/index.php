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
$endStartDate = trim((string) ($_GET['end_start_date'] ?? ''));
$endEndDate = trim((string) ($_GET['end_end_date'] ?? ''));
$examIdExact = trim((string) ($_GET['exam_id_exact'] ?? ''));
$activeWindow = (string) ($_GET['active_window'] ?? 'no');
$upcomingOnly = (string) ($_GET['upcoming_only'] ?? 'no');
$completedDays = trim((string) ($_GET['completed_days'] ?? ''));
$submissionsFilter = (string) ($_GET['submissions'] ?? 'all');

$conditions = [];
$params = [];

if ($search !== '') {
    $conditions[] = '(title LIKE ? OR exam_code LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($status === 'completed') {
    $conditions[] = 'is_completed = 1';
} elseif ($status === 'active') {
    $conditions[] = 'is_completed = 0';
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

if ($endStartDate !== '') {
    $endStartDt = DateTimeImmutable::createFromFormat('Y-m-d', $endStartDate);
    if ($endStartDt) {
        $endStartBoundary = $endStartDt->format('Y-m-d 00:00:00');
        $conditions[] = 'end_time >= ?';
        $params[] = $endStartBoundary;
    }
}

if ($endEndDate !== '') {
    $endEndDt = DateTimeImmutable::createFromFormat('Y-m-d', $endEndDate);
    if ($endEndDt) {
        $endEndBoundary = $endEndDt->format('Y-m-d 23:59:59');
        $conditions[] = 'end_time <= ?';
        $params[] = $endEndBoundary;
    }
}

if ($examIdExact !== '') {
    $conditions[] = 'exam_code = ?';
    $params[] = $examIdExact;
}

if ($activeWindow === 'yes') {
    $conditions[] = 'is_completed = 0';
    $conditions[] = 'NOW() BETWEEN DATE_SUB(start_time, INTERVAL buffer_pre_minutes MINUTE) AND DATE_ADD(end_time, INTERVAL buffer_post_minutes MINUTE)';
}

if ($upcomingOnly === 'yes') {
    $conditions[] = 'is_completed = 0';
    $conditions[] = 'start_time > NOW()';
}

if ($completedDays !== '') {
    $days = (int) $completedDays;
    if ($days > 0) {
        $conditions[] = 'is_completed = 1';
        $conditions[] = 'completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
        $params[] = $days;
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
$sql .= ' ORDER BY start_time DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$exams = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff - Exam Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/lumen/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="../index.php">Exam Submission Portal</a>
        <span class="navbar-text">Staff</span>
    </div>
</nav>

<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Exams</h1>
        <a class="btn btn-primary btn-sm" href="create_exam.php">Create Exam</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form class="row g-3" method="get">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input class="form-control" type="text" name="q" placeholder="Exam title or ID" value="<?php echo e($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active only</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed only</option>
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
                <div class="col-md-1 d-grid">
                    <label class="form-label invisible">Apply</label>
                    <button class="btn btn-outline-primary" type="submit">Apply</button>
                </div>
                <div class="col-md-3">
                    <label class="form-label">End date start</label>
                    <input class="form-control" type="date" name="end_start_date" value="<?php echo e($endStartDate); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End date end</label>
                    <input class="form-control" type="date" name="end_end_date" value="<?php echo e($endEndDate); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Exam ID exact</label>
                    <input class="form-control" type="text" name="exam_id_exact" value="<?php echo e($examIdExact); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Active window</label>
                    <select class="form-select" name="active_window">
                        <option value="no" <?php echo $activeWindow === 'no' ? 'selected' : ''; ?>>No filter</option>
                        <option value="yes" <?php echo $activeWindow === 'yes' ? 'selected' : ''; ?>>Only active now</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Upcoming only</label>
                    <select class="form-select" name="upcoming_only">
                        <option value="no" <?php echo $upcomingOnly === 'no' ? 'selected' : ''; ?>>No filter</option>
                        <option value="yes" <?php echo $upcomingOnly === 'yes' ? 'selected' : ''; ?>>Upcoming</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Completed within</label>
                    <div class="input-group">
                        <input class="form-control" type="number" name="completed_days" min="1" placeholder="Days" value="<?php echo e($completedDays); ?>">
                        <span class="input-group-text">days</span>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Submissions</label>
                    <select class="form-select" name="submissions">
                        <option value="all" <?php echo $submissionsFilter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="has" <?php echo $submissionsFilter === 'has' ? 'selected' : ''; ?>>Has submissions</option>
                        <option value="none" <?php echo $submissionsFilter === 'none' ? 'selected' : ''; ?>>No submissions</option>
                    </select>
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
</body>
</html>
