<?php
// ============================================================
//  ASSIGNMENT.PHP â€“ ONE assignment of the logged-in student:
//  the details, a quick status change and "Ask AI for help"
//  (the AI is told which course + which assignment this is).
//
//  The assignment is always loaded with "AND user_id = ?" so a
//  student can never open an assignment that is not theirs.
// ============================================================

$navActive = 'assignments';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId       = (int)$_SESSION['user_id'];
$assignmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$assignment   = false;
$course       = false;
$attempts     = array();
$errors       = array();
$dbError      = '';
$aiAnswer     = '';
$lastQuestion = '';

$action = (isset($_POST['action']) && is_string($_POST['action'])) ? $_POST['action'] : '';

try {
    $pdo        = getPDO();
    $assignment = findUserAssignment($pdo, $assignmentId, $userId);

    if ($assignment === false) {
        flashSet('danger', 'That assignment does not exist.');
        header('Location: assignments.php');
        exit;
    }

    $course = findUserCourse($pdo, (int)$assignment['course_id'], $userId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfCheck();                                  // wrong token = stop

        // ---------------- change the status ----------------
        if ($action === 'status') {
            $newStatus = (string)(isset($_POST['status']) ? $_POST['status'] : '');

            if (!isset(assignmentStatuses()[$newStatus])) {
                $errors[] = 'Please choose a valid status.';
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE assignments SET status = ? WHERE id = ? AND user_id = ?'
                );
                $stmt->execute([$newStatus, $assignmentId, $userId]);

                flashSet('success', 'Status changed to "' . statusLabel($newStatus) . '".');
                header('Location: assignment.php?id=' . $assignmentId);
                exit;
            }
        }

        // ---------------- ask the AI for help ----------------
        if ($action === 'ask') {
            $question = trim((string)(isset($_POST['question']) ? $_POST['question'] : ''));

            if ($question === '') {
                $errors[] = 'Please type your question first.';
            } elseif (mb_strlen($question) > 500) {
                $errors[] = 'Your question may not be longer than 500 characters.';
            } else {
                try {
                    // Same instructions + same askGemini() as the normal chat.
                    $prompt = studyContextInstruction($course, $assignment) . "\n\n"
                            . systemPromptForMode('study') . "\n\n"
                            . $question;

                    $aiAnswer = askGemini($prompt);

                    // Saved in the EXISTING messages table, connected to the course
                    // so it also shows up on the course page and in the history.
                    $stmt = $pdo->prepare(
                        'INSERT INTO messages (user_id, question, answer, course_id) VALUES (?, ?, ?, ?)'
                    );
                    $stmt->execute([$userId, $question, $aiAnswer, (int)$assignment['course_id']]);

                    $lastQuestion = $question;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
} catch (PDOException $e) {
    $dbError = friendlyError('assignment', $e);
}

$pageTitle = ($assignment !== false) ? (string)$assignment['title'] : 'Assignment';

include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrap">

<?php if ($dbError !== '') { ?>

    <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>

<?php } else { ?>

    <div class="page-heading">
        <div>
            <p class="crumbs mb-1">
                <a href="assignments.php">My assignments</a> &rsaquo;
                <a href="course.php?id=<?php echo (int)$assignment['course_id']; ?>"><?php echo htmlspecialchars((string)$assignment['course_name']); ?></a>
                &rsaquo; <?php echo htmlspecialchars((string)$assignment['title']); ?>
            </p>
            <h1 class="page-title"><?php echo htmlspecialchars((string)$assignment['title']); ?></h1>
            <p class="page-subtitle mb-0">
                <span class="badge text-bg-<?php echo statusBadge((string)$assignment['status']); ?>"><?php echo htmlspecialchars(statusLabel((string)$assignment['status'])); ?></span>
                &nbsp;
                <?php if (trim((string)$assignment['due_date']) !== '') { ?>
                    Due <strong><?php echo htmlspecialchars((string)$assignment['due_date']); ?></strong>
                <?php } else { ?>
                    No due date
                <?php } ?>
            </p>
        </div>
        <a class="btn btn-ghost" href="assignments.php?edit=<?php echo (int)$assignment['id']; ?>#assignment-form">Edit</a>
    </div>

    <?php flashRender(); ?>

    <?php foreach ($errors as $error) { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <div class="row g-3">
        <!-- ---------------- the assignment ---------------- -->
        <div class="col-12 col-lg-7">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="section-title mt-0">What to do</h2>
                    <?php if (trim((string)$assignment['description']) === '') { ?>
                        <p class="text-muted mb-0">No description was written yet. Use <strong>Edit</strong> to add one.</p>
                    <?php } else { ?>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars((string)$assignment['description'])); ?></p>
                    <?php } ?>

                    <hr>

                    <form method="post" action="assignment.php?id=<?php echo (int)$assignment['id']; ?>" class="row g-2 align-items-end">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="status">
                        <div class="col-12 col-sm-7">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach (assignmentStatuses() as $statusValue => $statusText) { ?>
                                    <option value="<?php echo htmlspecialchars($statusValue, ENT_QUOTES); ?>"<?php echo ($statusValue === (string)$assignment['status'] ? ' selected' : ''); ?>><?php echo htmlspecialchars($statusText); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-5 d-grid">
                            <button class="btn btn-primary" type="submit">Save status</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ---------------- AI help ---------------- -->
        <div class="col-12 col-lg-5" id="ai">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="section-title mt-0">Ask AI for help</h2>
                    <p class="text-muted small">
                        The AI already knows this course and this assignment. Ask about the task,
                        ask for a hint, or paste your own attempt and ask for feedback.
                    </p>

                    <form method="post" action="assignment.php?id=<?php echo (int)$assignment['id']; ?>#ai">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="ask">
                        <textarea class="form-control mb-2" id="question" name="question" rows="3" maxlength="500"
                                  placeholder="e.g. I do not understand step 2 - can you explain it?"><?php echo htmlspecialchars($lastQuestion); ?></textarea>
                        <button class="btn btn-primary w-100" type="submit">Ask the AI</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($aiAnswer !== '') { ?>
        <div class="card mt-3">
            <div class="card-body">
                <h2 class="section-title mt-0">AI answer</h2>
                <div class="ai-answer">
                    <span class="history-tag">Answer</span>
                    <div class="history-text"><?php echo formatAiText($aiAnswer); ?></div>
                </div>
                <?php if ($lastQuestion !== '') { ?>
                    <p class="text-muted small mb-0 mt-3">
                        Your question was saved in the <a href="history.php">history</a> and on the
                        <a href="course.php?id=<?php echo (int)$assignment['course_id']; ?>">course page</a>.
                    </p>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <?php $siblings = listCourseAssignments($pdo, $userId, (int)$assignment['course_id']); ?>
    <?php if (count($siblings) > 1) { ?>
        <h2 class="section-title">Other assignments in <?php echo htmlspecialchars((string)$assignment['course_name']); ?></h2>
        <div class="card">
            <div class="card-body">
                <div class="list-stack">
                    <?php foreach ($siblings as $sibling) { ?>
                        <?php if ((int)$sibling['id'] === (int)$assignment['id']) { continue; } ?>
                        <div class="list-item">
                            <div class="list-main">
                                <a class="list-title" href="assignment.php?id=<?php echo (int)$sibling['id']; ?>"><?php echo htmlspecialchars((string)$sibling['title']); ?></a>
                                <div class="list-meta">
                                    <span class="badge text-bg-<?php echo statusBadge((string)$sibling['status']); ?>"><?php echo htmlspecialchars(statusLabel((string)$sibling['status'])); ?></span>
                                    <span><?php echo (trim((string)$sibling['due_date']) !== '' ? 'Due ' . htmlspecialchars((string)$sibling['due_date']) : 'No due date'); ?></span>
                                </div>
                            </div>
                            <div class="list-actions">
                                <a class="btn btn-ghost btn-sm" href="assignment.php?id=<?php echo (int)$sibling['id']; ?>#ai">AI help</a>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>

<?php } ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
