<?php
// ============================================================
//  COURSE.PHP – everything about ONE course of the student:
//     description, progress, material notes, assignments,
//     quizzes and "Ask AI about this course".
//
//  The course is always loaded with "AND user_id = ?" so a
//  student can never open a course that is not theirs.
// ============================================================

$navActive = 'courses';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId    = (int)$_SESSION['user_id'];
$courseId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$course    = false;
$errors    = array();
$dbError   = '';
$aiAnswer  = '';
$lastQuestion = '';
$progress   = array(
    'assignments_total' => 0, 'assignments_completed' => 0, 'assignments_pending' => 0,
    'assignments_in_progress' => 0, 'quizzes_taken' => 0, 'average_score' => null,
);
$materials   = array();
$assignments = array();
$quizzes     = array();
$recentChats = array();

$askAction = (isset($_POST['action']) && is_string($_POST['action'])) ? $_POST['action'] : '';

try {
    $pdo    = getPDO();
    $course = findUserCourse($pdo, $courseId, $userId);

    if ($course === false) {
        flashSet('danger', 'That course does not exist.');
        header('Location: courses.php');
        exit;
    }

    // ---------------- "Ask AI about this course" ----------------
    // Server-side (no JavaScript needed): the same instructions and the
    // same askGemini() as the normal chat are used, and the question +
    // answer are saved in messages with this course_id.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $askAction === 'ask') {
        csrfCheck();

        $question = trim((string)(isset($_POST['question']) ? $_POST['question'] : ''));

        if ($question === '') {
            $errors[] = 'Please type a question about this course first.';
        } else {
            try {
                $prompt = studyContextInstruction($course, false) . "\n\n"
                        . systemPromptForMode('course') . "\n\n"
                        . $question;

                $aiAnswer = askGemini($prompt);

                $stmt = $pdo->prepare(
                    'INSERT INTO messages (user_id, question, answer, course_id) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$userId, $question, $aiAnswer, (int)$course['id']]);

                $lastQuestion = $question;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    // ---------------- delete one material (and its RAG chunks) ----------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $askAction === 'delete_material') {
        csrfCheck();
        $delId = isset($_POST['material_id']) ? (int)$_POST['material_id'] : 0;
        if ($delId > 0 && deleteUserMaterial($pdo, $userId, $delId)) {
            flashSet('success', 'Material deleted.');
        } else {
            flashSet('danger', 'That material does not exist.');
        }
        header('Location: course.php?id=' . (int)$courseId);
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $askAction !== '' && $askAction !== 'ask') {
        $errors[] = 'Unknown action. Please reload the page and try again.';
    }

    // ---------------- the data shown on the page ----------------
    $progress    = courseProgress($pdo, $userId, $courseId);
    $materials   = listCourseMaterials($pdo, $userId, $courseId);
    $assignments = listCourseAssignments($pdo, $userId, $courseId);
    $quizzes     = listCourseQuizzes($pdo, $userId, $courseId);

    $stmt = $pdo->prepare(
        'SELECT question, answer, created_at
           FROM messages
          WHERE user_id = ? AND course_id = ?
          ORDER BY created_at DESC
          LIMIT 3'
    );
    $stmt->execute([$userId, $courseId]);
    $recentChats = $stmt->fetchAll();
} catch (PDOException $e) {
    $dbError = friendlyError('course', $e);
}

$pageTitle = ($course !== false) ? (string)$course['name'] : 'Course';

include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrap">

    <?php if ($course === false) { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
        <a class="btn btn-ghost" href="courses.php">Back to my courses</a>
    <?php } else {
        $percent = ($progress['assignments_total'] > 0)
                 ? (int)round($progress['assignments_completed'] / $progress['assignments_total'] * 100)
                 : 0;
        ?>
        <div class="page-heading">
            <div>
                <h1 class="page-title"><?php echo htmlspecialchars($course['name']); ?></h1>

                <p class="page-subtitle mb-0">
                    <?php if ($course['code'] !== '') { ?>
                        <span class="course-code"><?php echo htmlspecialchars($course['code']); ?></span>
                    <?php } ?>
                    Added <?php echo htmlspecialchars(date('d M Y', strtotime((string)$course['created_at']))); ?>
                </p>
            </div>
            <div class="course-actions">
                <a class="btn btn-primary" href="index.php?course_id=<?php echo (int)$course['id']; ?>">Ask AI</a>
                <a class="btn btn-ghost" href="courses.php?edit=<?php echo (int)$course['id']; ?>">Edit course</a>
            </div>
        </div>

        <?php flashRender(); ?>

        <?php foreach ($errors as $error) { ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <?php if ($dbError !== '') { ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
        <?php } ?>

        <!-- ---------------- course description + progress ---------------- -->
        <div class="row g-3">
            <div class="col-12 col-lg-7">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="section-title mt-0">About this course</h2>
                        <?php if (trim((string)$course['description']) !== '') { ?>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars((string)$course['description'])); ?></p>
                        <?php } else { ?>
                            <p class="text-muted mb-0">
                                No description yet.
                                <a href="courses.php?edit=<?php echo (int)$course['id']; ?>">Add one</a>
                                so the AI knows what this course is about.
                            </p>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="section-title mt-0">Progress</h2>
                        <div class="progress progress-mini" role="progressbar" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                        <div class="progress-meta">
                            <span><?php echo (int)$progress['assignments_completed']; ?>/<?php echo (int)$progress['assignments_total']; ?> assignments completed</span>
                            <span>Pending: <?php echo (int)$progress['assignments_pending']; ?></span>
                            <span>In progress: <?php echo (int)$progress['assignments_in_progress']; ?></span>
                            <span>Quizzes taken: <?php echo (int)$progress['quizzes_taken']; ?></span>
                            <span>Average quiz score: <?php echo ($progress['average_score'] === null ? '&ndash;' : (int)$progress['average_score'] . '%'); ?></span>
                        </div>
                        <div class="course-actions mt-3">
                            <a class="btn btn-ghost btn-sm" href="assignments.php?course_id=<?php echo (int)$course['id']; ?>">+ New assignment</a>
                            <a class="btn btn-ghost btn-sm" href="quizzes.php?course_id=<?php echo (int)$course['id']; ?>">Generate quiz</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ---------------- ask the AI about this course ---------------- -->
        <h2 class="section-title">Ask AI about this course</h2>
        <div class="card">
            <div class="card-body">
                <p class="text-muted small">
                    The AI receives the course name, code and description and answers as your
                    Course Assistant. Questions and answers are saved in your
                    <a href="history.php">history</a>.
                </p>

                <form method="post" action="course.php?id=<?php echo (int)$course['id']; ?>" class="row g-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="ask">
                    <div class="col-12 col-md-9">
                        <input class="form-control" type="text" name="question" maxlength="500"
                               placeholder="e.g. Explain normalisation in this course with a simple example"
                               value="<?php echo htmlspecialchars($lastQuestion); ?>" required>
                    </div>
                    <div class="col-12 col-md-3 d-grid">
                        <button class="btn btn-primary" type="submit">Ask the AI</button>
                    </div>
                </form>

                <?php if ($aiAnswer !== '') { ?>
                    <div class="ai-answer mt-3">
                        <div class="history-tag">Answer</div>
                        <div class="history-text"><?php echo formatAiText($aiAnswer); ?></div>
                    </div>
                <?php } ?>

                <?php if (count($recentChats) > 0) { ?>
                    <details class="mt-3">
                        <summary class="text-muted small">Earlier questions about this course (<?php echo count($recentChats); ?>)</summary>
                        <?php foreach ($recentChats as $chat) { ?>
                            <div class="ai-answer mt-2">
                                <div class="history-tag">Question &middot; <?php echo htmlspecialchars((string)$chat['created_at']); ?></div>
                                <p class="history-text mb-2"><?php echo htmlspecialchars((string)$chat['question']); ?></p>
                                <div class="history-tag">Answer</div>
                                <div class="history-text"><?php echo formatAiText((string)$chat['answer']); ?></div>
                            </div>
                        <?php } ?>
                    </details>
                <?php } ?>
            </div>
        </div>

        <!-- ---------------- material notes ---------------- -->
        <h2 class="section-title">Course materials</h2>
        <div class="card">
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Files you attach in the course chat are sent straight to the AI and are never
                    saved on the server, so this list keeps their name, type and size as a note.
                </p>

                <?php if (count($materials) === 0) { ?>
                    <p class="text-muted mb-0">
                        Nothing attached yet.
                        <a href="index.php?course_id=<?php echo (int)$course['id']; ?>">Open the course chat</a>
                        and attach a file to build this list.
                    </p>
                <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>File</th>
                                    <th>Type</th>
                                    <th>Size</th>
                                    <th>Note</th>
                                    <th>Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materials as $material) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$material['file_name']); ?></td>
                                        <td><span class="badge text-bg-light"><?php echo htmlspecialchars((string)$material['file_type']); ?></span></td>
                                        <td><?php echo ((int)$material['file_size'] > 0 ? htmlspecialchars(formatFileSize($material['file_size'])) : '&ndash;'); ?></td>
                                        <td class="text-muted small">
                                            <?php echo (trim((string)$material['note']) !== '' ? htmlspecialchars((string)$material['note']) : '&ndash;'); ?>
                                        </td>
                                        <td class="text-muted small"><?php echo htmlspecialchars((string)$material['created_at']); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </div>
        </div>

        <!-- ---------------- assignments ---------------- -->
        <h2 class="section-title">
            Assignments
            <a class="btn btn-ghost btn-sm ms-2" href="assignments.php?course_id=<?php echo (int)$course['id']; ?>">Manage</a>
        </h2>
        <div class="card">
            <div class="card-body">
                <?php if (count($assignments) === 0) { ?>
                    <p class="text-muted mb-0">
                        No assignments for this course yet.
                        <a href="assignments.php?course_id=<?php echo (int)$course['id']; ?>">Add the first one</a>.
                    </p>
                <?php } else { ?>
                    <div class="list-stack">
                        <?php foreach ($assignments as $assignment) { ?>
                            <div class="list-item">
                                <div class="list-main">
                                    <a class="list-title" href="assignment.php?id=<?php echo (int)$assignment['id']; ?>"><?php echo htmlspecialchars((string)$assignment['title']); ?></a>
                                    <div class="list-meta">
                                        <span class="badge text-bg-<?php echo statusBadge((string)$assignment['status']); ?>"><?php echo htmlspecialchars(statusLabel((string)$assignment['status'])); ?></span>
                                        <?php if (trim((string)$assignment['due_date']) !== '') { ?>
                                            <span>Due <?php echo htmlspecialchars((string)$assignment['due_date']); ?></span>
                                        <?php } else { ?>
                                            <span>No due date</span>
                                        <?php } ?>
                                    </div>
                                </div>
                                <div class="list-actions">
                                    <a class="btn btn-ghost btn-sm" href="assignment.php?id=<?php echo (int)$assignment['id']; ?>#ai">AI help</a>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>

        <!-- ---------------- quizzes ---------------- -->
        <h2 class="section-title">
            Quizzes
            <a class="btn btn-ghost btn-sm ms-2" href="quizzes.php?course_id=<?php echo (int)$course['id']; ?>">Manage</a>
        </h2>
        <div class="card">
            <div class="card-body">
                <?php if (count($quizzes) === 0) { ?>
                    <p class="text-muted mb-0">
                        No quizzes yet.
                        <a href="quizzes.php?course_id=<?php echo (int)$course['id']; ?>">Let the AI generate one</a>
                        and test yourself.
                    </p>
                <?php } else { ?>
                    <div class="list-stack">
                        <?php foreach ($quizzes as $quiz) {
                            $questionCount = count(decodeQuizQuestions($quiz['questions']));
                            $bestScore = ($quiz['best_score'] === null) ? null : (int)round((float)$quiz['best_score']);
                            ?>
                            <div class="list-item">
                                <div class="list-main">
                                    <a class="list-title" href="quiz.php?id=<?php echo (int)$quiz['id']; ?>"><?php echo htmlspecialchars((string)$quiz['title']); ?></a>
                                    <div class="list-meta">
                                        <span class="badge text-bg-info"><?php echo htmlspecialchars(difficultyLabel((string)$quiz['difficulty'])); ?></span>
                                        <span><?php echo $questionCount; ?> questions</span>
                                        <span><?php echo (int)$quiz['attempts']; ?> attempt(s)</span>
                                        <?php if ($bestScore !== null) { ?>
                                            <span>Best score <?php echo $bestScore; ?>%</span>
                                        <?php } ?>
                                    </div>
                                </div>
                                <div class="list-actions">

                                    <a class="btn btn-primary btn-sm" href="quiz.php?id=<?php echo (int)$quiz['id']; ?>">Take quiz</a>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
