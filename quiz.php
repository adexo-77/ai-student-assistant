<?php
// ============================================================
//  QUIZ.PHP – TAKE one quiz of the logged-in student.
//
//  ?id=7 loads the quiz (only when it belongs to this user),
//  shows every question with radio options and, on submit,
//  grades the answers, saves the attempt and redirects to
//  quiz_result.php?id=<attempt id> for the score + review.
// ============================================================

$navActive = 'quizzes';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId   = (int)$_SESSION['user_id'];
$quizId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$quiz     = false;
$questions = array();
$attempts  = array();
$errors    = array();
$dbError   = '';

try {
    $pdo = getPDO();
    $quiz = findUserQuiz($pdo, $quizId, $userId);

    if ($quiz === false) {
        flashSet('danger', 'That quiz does not exist.');
        header('Location: quizzes.php');
        exit;
    }

    $questions = decodeQuizQuestions($quiz['questions']);

    // ---------------- submit: grade + save the attempt ----------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfCheck();                                        // wrong token = stop
        $action = (string)(isset($_POST['action']) ? $_POST['action'] : '');

        if ($action === 'submit') {
            $answers = array();
            $score   = 0;

            foreach ($questions as $i => $question) {
                $name   = 'q' . $i;
                $picked = isset($_POST[$name]) ? (int)$_POST[$name] : -1;

                if ($picked >= 0 && $picked < count($question['options'])) {
                    $answers[] = $picked;
                    if ($picked === (int)$question['answer']) {
                        $score++;                           // exact match with the stored index
                    }
                } else {
                    $answers[] = null;                      // question left blank
                }
            }

            $attemptId = saveQuizAttempt($pdo, $userId, $quizId, $score, count($questions), $answers);

            flashSet('success', 'Quiz submitted – here is your result!');
            header('Location: quiz_result.php?id=' . $attemptId);
            exit;
        }
    }

    $attempts = listQuizAttempts($pdo, $userId, $quizId);   // newest first
} catch (PDOException $e) {
    $dbError = friendlyError('quiz', $e);
}

$pageTitle = ($quiz !== false) ? (string)$quiz['title'] : 'Quiz';

include __DIR__ . '/includes/header.php';
?>
<div class="container page-wrap">

    <p class="crumbs">
        <a href="quizzes.php">Quizzes</a> &rsaquo;
        <a href="course.php?id=<?php echo (int)$quiz['course_id']; ?>"><?php echo htmlspecialchars((string)$quiz['course_name']); ?></a>
    </p>

    <div class="page-heading">
        <div>
            <h1 class="page-title"><?php echo htmlspecialchars((string)$quiz['title']); ?></h1>
            <p class="page-subtitle mb-0">
                <span class="badge text-bg-info"><?php echo htmlspecialchars(difficultyLabel((string)$quiz['difficulty'])); ?></span>
                <span class="ms-2"><?php echo count($questions); ?> question(s)</span>
                <span class="ms-2">No time limit</span>
            </p>
        </div>
        <div class="course-actions">
            <a class="btn btn-ghost" href="quizzes.php">All quizzes</a>
        </div>
    </div>

    <?php flashRender(); ?>

    <?php foreach ($errors as $error) { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } elseif (count($questions) === 0) { ?>
        <div class="alert alert-warning">
            This quiz has no usable questions (the saved JSON is broken).
            Delete it on the <a href="quizzes.php">Quizzes page</a> and generate a new one.
        </div>
    <?php } else { ?>

        <!-- ---------------- the quiz form ---------------- -->
        <form method="post" action="quiz.php?id=<?php echo (int)$quiz['id']; ?>">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="submit">

            <?php foreach ($questions as $i => $question) { ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <h2 class="section-title mt-0">
                            Question <?php echo $i + 1; ?>
                            <span class="badge text-bg-secondary ms-2"><?php echo ($question['type'] === 'tf' ? 'True / False' : 'Multiple choice'); ?></span>
                        </h2>
                        <p class="quiz-question mb-3"><?php echo htmlspecialchars((string)$question['question']); ?></p>

                        <?php foreach ($question['options'] as $optIndex => $option) { ?>
                            <div class="form-check quiz-option">
                                <input class="form-check-input" type="radio"
                                       name="q<?php echo $i; ?>" id="q<?php echo $i; ?>o<?php echo $optIndex; ?>"
                                       value="<?php echo $optIndex; ?>" required>
                                <label class="form-check-label" for="q<?php echo $i; ?>o<?php echo $optIndex; ?>">
                                    <strong><?php echo chr(65 + $optIndex); ?>.</strong>
                                    <?php echo htmlspecialchars((string)$option); ?>
                                </label>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <button class="btn btn-primary" type="submit">Submit answers</button>
                <a class="btn btn-ghost" href="quizzes.php">Cancel</a>
            </div>
        </form>

        <!-- ---------------- previous attempts ---------------- -->
        <?php if (count($attempts) > 0) { ?>
            <h2 class="section-title">Previous attempts</h2>
            <div class="card mb-4">
                <div class="card-body">
                    <div class="list-stack">
                        <?php foreach ($attempts as $attempt) {
                            $total = (int)$attempt['total_questions'];
                            $pct   = ($total > 0) ? (int)round(((int)$attempt['score'] / $total) * 100) : 0;
                        ?>
                            <div class="list-item">
                                <div class="list-main">
                                    <span class="list-title">Score <?php echo (int)$attempt['score']; ?>/<?php echo $total; ?> (<?php echo $pct; ?>%)</span>
                                    <div class="list-meta"><span><?php echo htmlspecialchars((string)$attempt['created_at']); ?></span></div>
                                </div>
                                <div class="list-actions">
                                    <a class="btn btn-ghost btn-sm" href="quiz_result.php?id=<?php echo (int)$attempt['id']; ?>">Review</a>
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