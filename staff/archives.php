<?php

declare(strict_types=1);

require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

$config = require __DIR__ . '/../config.php';
$uploadsDir = rtrim($config['uploads_dir'], '/');

$examId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam) {
    http_response_code(404);
    echo 'Exam not found.';
    exit;
}

$archiveRoot = $uploadsDir . '/archive/exam_' . $examId;
$archives = [];
$students = [];

if (is_dir($archiveRoot)) {
    $items = scandir($archiveRoot);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (strpos($item, 'submission_') !== 0) {
            continue;
        }
        $path = $archiveRoot . '/' . $item;
        if (!is_dir($path)) {
            continue;
        }
        $files = glob($path . '/*');
        $count = is_array($files) ? count($files) : 0;
        $modified = filemtime($path);
        $metadata = null;
        $metaPath = $path . '/metadata.json';
        if (is_file($metaPath)) {
            $raw = file_get_contents($metaPath);
            if ($raw !== false) {
                $metadata = json_decode($raw, true);
            }
        }

        $studentFirst = $metadata['student_first_name'] ?? '';
        $studentLast = $metadata['student_last_name'] ?? '';
        $studentName = trim($studentFirst . ' ' . $studentLast);
        if ($studentName === '') {
            $studentName = $metadata['student_name'] ?? 'Unknown student';
        }
        $candidateNumber = $metadata['candidate_number'] ?? 'Unknown candidate';
        $key = strtolower(trim($studentName . '|' . $candidateNumber));

        if (!isset($students[$key])) {
            $students[$key] = [
                'name' => $studentName,
                'candidate_number' => $candidateNumber,
                'archives' => [],
            ];
        }

        $students[$key]['archives'][] = [
            'name' => $item,
            'count' => $count,
            'modified' => $modified,
            'submitted_at' => $metadata['submitted_at'] ?? null,
        ];
    }
}

foreach ($students as &$student) {
    usort($student['archives'], function (array $a, array $b): int {
        return $b['modified'] <=> $a['modified'];
    });
}
unset($student);

uasort($students, function (array $a, array $b): int {
    return strcasecmp($a['name'], $b['name']);
});
$pageTitle = 'Archived Submissions - ' . $exam['title'];
$brandHref = 'index.php';
$brandText = 'Staff';
$logoPath = '../logo.png';
$cssPath = '../style.css';
$navActions = '<a class="btn btn-outline-secondary btn-sm" href="../index.php">Student View</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="exam.php?id=' . (int) $exam['id'] . '">Back to exam</a>';
require __DIR__ . '/../header.php';
?>
<main class="container py-4">
    <div class="mb-4">
        <h1 class="h4">Archived Submissions</h1>
        <p class="text-muted">Exam: <?php echo e($exam['title']); ?></p>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (count($students) === 0): ?>
                <p class="text-muted mb-0">No archived submissions yet.</p>
            <?php else: ?>
                <div class="accordion" id="archiveAccordion">
                    <?php $index = 0; ?>
                    <?php foreach ($students as $key => $student): ?>
                        <?php $index++; ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="heading-<?php echo $index; ?>">
                                <button class="accordion-button <?php echo $index === 1 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $index; ?>" aria-expanded="<?php echo $index === 1 ? 'true' : 'false'; ?>" aria-controls="collapse-<?php echo $index; ?>">
                                    <?php echo e($student['name']); ?> — <?php echo e($student['candidate_number']); ?>
                                </button>
                            </h2>
                            <div id="collapse-<?php echo $index; ?>" class="accordion-collapse collapse <?php echo $index === 1 ? 'show' : ''; ?>" aria-labelledby="heading-<?php echo $index; ?>" data-bs-parent="#archiveAccordion">
                                <div class="accordion-body">
                                    <div class="table-responsive">
                                        <table class="table align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th scope="col">Archive</th>
                                                <th scope="col">Files</th>
                                                <th scope="col">Submitted</th>
                                                <th scope="col">Archived</th>
                                                <th scope="col"></th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($student['archives'] as $archive): ?>
                                                <tr>
                                                    <td><?php echo e($archive['name']); ?></td>
                                                    <td><?php echo (int) $archive['count']; ?></td>
                                                    <td>
                                                        <?php echo $archive['submitted_at'] ? e(format_datetime_display($archive['submitted_at'])) : '—'; ?>
                                                    </td>
                                                    <td><?php echo e(date('d/m/Y g:i A', (int) $archive['modified'])); ?></td>
                                                    <td>
                                                        <a class="btn btn-outline-primary btn-sm" href="download_archive.php?exam_id=<?php echo (int) $exam['id']; ?>&folder=<?php echo e(urlencode($archive['name'])); ?>">Download zip</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../footer.php'; ?>
