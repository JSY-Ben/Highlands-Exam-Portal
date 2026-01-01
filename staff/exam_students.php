<?php

declare(strict_types=1);

require __DIR__ . '/../auth/require_auth.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../helpers.php';

function generate_student_password(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

$examId = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM exams WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam) {
    http_response_code(404);
    echo 'Exam not found.';
    exit;
}

$errors = [];
$success = false;

$rosterEnabled = !empty($exam['student_roster_enabled']);
$rosterMode = ($exam['student_roster_mode'] ?? '') === 'password' ? 'password' : 'menu';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rosterEnabled = isset($_POST['roster_enabled']);
    $rosterMode = ($_POST['roster_mode'] ?? '') === 'password' ? 'password' : 'menu';
    $existing = (array) ($_POST['students'] ?? []);
    $newFirstNames = (array) ($_POST['new_students_first_name'] ?? []);
    $newLastNames = (array) ($_POST['new_students_last_name'] ?? []);
    $newCandidateNumbers = (array) ($_POST['new_students_candidate_number'] ?? []);
    $csvRows = [];
    $csvFile = $_FILES['student_csv'] ?? null;

    if ($csvFile && ($csvFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($csvFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'Failed to upload CSV file.';
        } else {
            $handle = fopen($csvFile['tmp_name'], 'r');
            if ($handle === false) {
                $errors[] = 'Unable to read the CSV file.';
            } else {
                $header = fgetcsv($handle);
                if (!is_array($header)) {
                    $errors[] = 'CSV file is missing a header row.';
                } else {
                    $normalized = [];
                    foreach ($header as $index => $name) {
                        $key = strtolower(trim((string) $name));
                        $key = preg_replace('/\\s+/', '_', $key);
                        $key = preg_replace('/_+/', '_', $key);
                        $normalized[$key] = $index;
                    }

                    $expected = [
                        'first_name' => null,
                        'last_name' => null,
                        'candidate_number' => null,
                    ];
                    $aliases = [
                        'first_name' => ['first_name', 'firstname', 'first'],
                        'last_name' => ['last_name', 'lastname', 'surname', 'last'],
                        'candidate_number' => ['candidate_number', 'candidate_no', 'candidate', 'candidatenumber'],
                    ];

                    foreach ($aliases as $field => $keys) {
                        foreach ($keys as $key) {
                            if (array_key_exists($key, $normalized)) {
                                $expected[$field] = $normalized[$key];
                                break;
                            }
                        }
                    }

                    if (in_array(null, $expected, true)) {
                        $errors[] = 'CSV headers must include first_name, last_name, and candidate_number.';
                    } else {
                        while (($row = fgetcsv($handle)) !== false) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $firstName = trim((string) ($row[$expected['first_name']] ?? ''));
                            $lastName = trim((string) ($row[$expected['last_name']] ?? ''));
                            $candidate = trim((string) ($row[$expected['candidate_number']] ?? ''));

                            if ($firstName === '' && $lastName === '' && $candidate === '') {
                                continue;
                            }

                            if ($firstName === '' || $lastName === '' || $candidate === '') {
                                $errors[] = 'Each CSV row must include first name, last name, and candidate number.';
                                break;
                            }

                            $csvRows[] = [
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'candidate_number' => $candidate,
                            ];
                        }
                    }
                }
                fclose($handle);
            }
        }
    }

    $existingRows = [];
    foreach ($existing as $studentId => $studentData) {
        $studentId = (int) $studentId;
        if ($studentId <= 0 || !is_array($studentData)) {
            continue;
        }
        $firstName = trim((string) ($studentData['first_name'] ?? ''));
        $lastName = trim((string) ($studentData['last_name'] ?? ''));
        $candidate = trim((string) ($studentData['candidate_number'] ?? ''));
        $password = trim((string) ($studentData['password'] ?? ''));
        $regenerate = isset($studentData['regenerate']);

        if ($firstName === '' && $lastName === '' && $candidate === '') {
            continue;
        }

        if ($firstName === '' || $lastName === '' || $candidate === '') {
            $errors[] = 'Each student must have a first name, last name, and candidate number.';
            break;
        }

        if ($rosterMode === 'password') {
            if ($password === '' || $regenerate) {
                $password = generate_student_password();
            }
        } else {
            $password = '';
        }

        $existingRows[] = [
            'id' => $studentId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'candidate_number' => $candidate,
            'password' => $password !== '' ? $password : null,
        ];
    }

    $newRows = [];
    foreach ($newFirstNames as $index => $firstNameRaw) {
        $firstName = trim((string) $firstNameRaw);
        $lastName = trim((string) ($newLastNames[$index] ?? ''));
        $candidate = trim((string) ($newCandidateNumbers[$index] ?? ''));

        if ($firstName === '' && $lastName === '' && $candidate === '') {
            continue;
        }

        if ($firstName === '' || $lastName === '' || $candidate === '') {
            $errors[] = 'Each student must have a first name, last name, and candidate number.';
            break;
        }

        $password = null;
        if ($rosterMode === 'password') {
            $password = generate_student_password();
        }

        $newRows[] = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'candidate_number' => $candidate,
            'password' => $password,
        ];
    }

    foreach ($csvRows as $row) {
        $password = null;
        if ($rosterMode === 'password') {
            $password = generate_student_password();
        }
        $newRows[] = [
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'candidate_number' => $row['candidate_number'],
            'password' => $password,
        ];
    }

    if ($rosterEnabled && count($existingRows) + count($newRows) === 0) {
        $errors[] = 'Add at least one student or disable the roster.';
    }

    if (count($errors) === 0) {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE exams SET student_roster_enabled = ?, student_roster_mode = ? WHERE id = ?');
            $stmt->execute([
                $rosterEnabled ? 1 : 0,
                $rosterMode,
                $examId,
            ]);

            $order = 1;
            $keptIds = [];
            $updateStudent = $pdo->prepare(
                'UPDATE exam_students
                 SET student_first_name = ?, student_last_name = ?, candidate_number = ?, access_password = ?, sort_order = ?
                 WHERE id = ? AND exam_id = ?'
            );
            foreach ($existingRows as $row) {
                $updateStudent->execute([
                    $row['first_name'],
                    $row['last_name'],
                    $row['candidate_number'],
                    $row['password'],
                    $order,
                    $row['id'],
                    $examId,
                ]);
                $keptIds[] = $row['id'];
                $order++;
            }

            if (count($keptIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($keptIds), '?'));
                $deleteStmt = $pdo->prepare("DELETE FROM exam_students WHERE exam_id = ? AND id NOT IN ($placeholders)");
                $deleteStmt->execute(array_merge([$examId], $keptIds));
            } else {
                $deleteStmt = $pdo->prepare('DELETE FROM exam_students WHERE exam_id = ?');
                $deleteStmt->execute([$examId]);
            }

            if (count($newRows) > 0) {
                $insertStudent = $pdo->prepare(
                    'INSERT INTO exam_students (exam_id, student_first_name, student_last_name, candidate_number, access_password, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($newRows as $row) {
                    $insertStudent->execute([
                        $examId,
                        $row['first_name'],
                        $row['last_name'],
                        $row['candidate_number'],
                        $row['password'],
                        $order,
                    ]);
                    $order++;
                }
            }

            $pdo->commit();
            $success = true;

            $stmt = $pdo->prepare('SELECT * FROM exams WHERE id = ?');
            $stmt->execute([$examId]);
            $exam = $stmt->fetch();
            $rosterEnabled = !empty($exam['student_roster_enabled']);
            $rosterMode = ($exam['student_roster_mode'] ?? '') === 'password' ? 'password' : 'menu';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Failed to update the student roster.';
        }
    }
}

$stmt = db()->prepare('SELECT * FROM exam_students WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
$stmt->execute([$examId]);
$students = $stmt->fetchAll();

$pageTitle = 'Student Roster - ' . $exam['title'];
$brandHref = 'index.php';
$brandText = 'Exams Administration Portal';
$logoPath = '../logo.png';
$cssPath = '../style.css';
$navActions = '<a class="btn btn-outline-secondary btn-sm" href="../index.php">Student View</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="exam.php?id=' . (int) $exam['id'] . '">Back to exam</a>'
    . '<a class="btn btn-outline-secondary btn-sm" href="../auth/logout.php">Logout</a>';
require __DIR__ . '/../header.php';
?>
<main class="container py-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-2">Student Roster</h1>
            <p class="text-muted mb-3">Configure the student list and how students identify themselves on the submission page.</p>

            <?php if ($success): ?>
                <div class="alert alert-success">Student roster updated.</div>
            <?php endif; ?>

            <?php if (count($errors) > 0): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo e($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="roster_enabled" id="roster-enabled" value="1" <?php echo $rosterEnabled ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="roster-enabled">Enable roster for this exam</label>
                </div>

                <div class="mt-3">
                    <label class="form-label">Student identification</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="roster_mode" id="roster-menu" value="menu" <?php echo $rosterMode === 'menu' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="roster-menu">Students choose their name from a menu</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="roster_mode" id="roster-password" value="password" <?php echo $rosterMode === 'password' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="roster-password">Students enter a unique password</label>
                    </div>
                </div>

                <div class="mt-4">
                    <h2 class="h6 text-uppercase fw-bold mb-2">Students</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>First name</th>
                                    <th>Last name</th>
                                    <th>Candidate #</th>
                                    <th class="password-col">Password</th>
                                    <th class="password-col">Regenerate</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="student-rows">
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <input class="form-control form-control-sm" type="text" name="students[<?php echo (int) $student['id']; ?>][first_name]" value="<?php echo e($student['student_first_name']); ?>" required>
                                        </td>
                                        <td>
                                            <input class="form-control form-control-sm" type="text" name="students[<?php echo (int) $student['id']; ?>][last_name]" value="<?php echo e($student['student_last_name']); ?>" required>
                                        </td>
                                        <td>
                                            <input class="form-control form-control-sm" type="text" name="students[<?php echo (int) $student['id']; ?>][candidate_number]" value="<?php echo e($student['candidate_number']); ?>" required>
                                        </td>
                                        <td class="password-col">
                                            <input class="form-control form-control-sm" type="text" name="students[<?php echo (int) $student['id']; ?>][password]" value="<?php echo e((string) ($student['access_password'] ?? '')); ?>" readonly>
                                        </td>
                                        <td class="password-col text-center">
                                            <input class="form-check-input" type="checkbox" name="students[<?php echo (int) $student['id']; ?>][regenerate]" value="1">
                                        </td>
                                        <td class="text-end">
                                            <button class="btn btn-outline-danger btn-sm remove-student" type="button">Remove</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="add-student">Add student</button>
                </div>

                <div class="mt-4">
                    <label class="form-label">Import from CSV</label>
                    <input class="form-control" type="file" name="student_csv" accept=".csv">
                    <div class="form-text">Required headers: <code>first_name</code>, <code>last_name</code>, <code>candidate_number</code>.</div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">Save roster</button>
                    <a class="btn btn-outline-secondary" href="exam.php?id=<?php echo (int) $exam['id']; ?>">Back to exam</a>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
    const addButton = document.getElementById('add-student');
    const studentRows = document.getElementById('student-rows');
    const rosterMenu = document.getElementById('roster-menu');
    const rosterPassword = document.getElementById('roster-password');
    const passwordCols = document.querySelectorAll('.password-col');

    const updatePasswordColumns = () => {
        const show = rosterPassword.checked;
        passwordCols.forEach((col) => {
            col.classList.toggle('d-none', !show);
        });
    };

    const bindRemoveButtons = () => {
        studentRows.querySelectorAll('.remove-student').forEach((button) => {
            button.addEventListener('click', () => {
                button.closest('tr')?.remove();
            });
        });
    };

    const buildRow = () => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><input class="form-control form-control-sm" type="text" name="new_students_first_name[]" required></td>
            <td><input class="form-control form-control-sm" type="text" name="new_students_last_name[]" required></td>
            <td><input class="form-control form-control-sm" type="text" name="new_students_candidate_number[]" required></td>
            <td class="password-col text-muted">Generated on save</td>
            <td class="password-col"></td>
            <td class="text-end"><button class="btn btn-outline-danger btn-sm remove-student" type="button">Remove</button></td>
        `;
        return row;
    };

    addButton.addEventListener('click', () => {
        const row = buildRow();
        studentRows.appendChild(row);
        bindRemoveButtons();
        updatePasswordColumns();
    });

    rosterMenu.addEventListener('change', updatePasswordColumns);
    rosterPassword.addEventListener('change', updatePasswordColumns);

    bindRemoveButtons();
    updatePasswordColumns();
</script>
<?php require __DIR__ . '/../footer.php'; ?>
