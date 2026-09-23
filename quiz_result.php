<?php
// ============================================================
//  QUIZ_RESULT.PHP – the score of one finished quiz attempt +
//  a per-question review (what you picked, what was right, why).
//
//  The attempt is loaded with "AND a.user_id = ?" so a student
//  can only ever see their own results.
// ============================================================

$navActive = 'quizzes';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId    = (int)$_SESSION['user_id'];
$attemptId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$attempt   = false;
$questions = array();
$answers   = array();
$dbError   = '';

try {
    $pdo = getPDO();
    $attempt = findUserQuizAttempt($pdo, $attemptId, $userId);

    if ($attempt === false) {
        flashSet('danger', 'That quiz attempt does not exist.');
        header('Location: quizzes.php');
        exit;
    }

    $questions = decodeQuizQuestions($attempt['quiz_questions']);
    $answers   = decodeQuizQuestions($attempt['answers']);   // array of picked indexes / null
} catch (PDOException $e) {
    $dbError = friendlyError('quiz result', $e);
}

$score     = ($attempt !== false) ? (int)$attempt['score'] : 0;
$total     = ($attempt !== false) ? (int)$attempt['total_questions'] : 0;
$percent   = ($total > 0) ? (int)round(($score / $total) * 100) : 0;

// Verdict used by the big result banner.
$verdictClass = 'text-bg-success';
$verdictText  = 'Excellent work!';
if ($percent < 40) {
    $verdictClass = 'text-bg-danger';
    $verdictText  = 'Keep practising!';
} elseif ($percent < 70) {
    $verdictClass = 'text-bg-warning';
    $verdictText  = 'Almost there!';
}

$pageTitle = 'Quiz result';

include __DIR__ . '/includes/header.php';
?>
<div class="container page-wrap">

    <p class="crumbs">
        <a href="quizzes.php">Quizzes</a> &rsaquo;
        <?php if ($attempt !== false) { ?>
            <a href="quiz.php?id=<?php echo (int)$attempt['quiz_id']; ?>"><?php echo htmlspecialchars((string)$attempt['quiz_title']); ?></a>
        <?php } ?>
    </p>

    <div class="page-heading">
        <div>
            <h1 class="page-title">Quiz <span class="grad-text">result</span></h1>
            <?php if ($attempt !== false) { ?>
                <p class="page-subtitle mb-0">
                    <?php echo htmlspecialchars((string)$attempt['quiz_title']); ?>
                    · <?php echo htmlspecialchars((string)$attempt['course_name']); ?>
                    · <?php echo htmlspecialchars((string)$attempt['created_at']); ?>
                </p>
            <?php } ?>
        </div>
        <div class="course-actions">
            <?php if ($attempt !== false) { ?>
                <a class="btn btn-ghost" href="quiz.php?id=<?php echo (int)$attempt['quiz_id']; ?>">Retake quiz</a>
            <?php } ?>
            <a class="btn btn-ghost" href="quizzes.php">All quizzes</a>
        </div>
    </div>

    <?php flashRender(); ?>

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } elseif ($attempt !== false) { ?>

        <!-- ---------------- score banner ---------------- -->
        <div class="card mb-3">
            <div class="card-body text-center">
                <div class="quiz-score-display">
                    <span class="display-4 fw-bold"><?php echo $percent; ?>%</span>
                    <span class="badge <?php echo $verdictClass; ?> ms-2"><?php echo htmlspecialchars($verdictText); ?></span>
                </div>
                <p class="text-muted mb-2">
                    You answered <strong><?php echo $score; ?></strong> of <strong><?php echo $total; ?></strong> question(s) correctly.
                </p>
                <div class="quiz-score-counts">
                    <span class="badge text-bg-success"><?php echo $score; ?> correct</span>
                    <span class="badge text-bg-danger"><?php echo max(0, $total - $score); ?> incorrect</span>
                    <span class="badge text-bg-secondary"><?php echo $total; ?> questions</span>
                </div>
                <p class="text-muted small mb-0 mt-2">Your attempt has been saved – you can find it under “Your quizzes”.</p>
            </div>
        </div>

        <!-- ---------------- per-question review ---------------- -->
        <h2 class="section-title">Review your answers</h2>

        <?php foreach ($questions as $i => $question) {
            $picked = isset($answers[$i]) ? $answers[$i] : null;
            $picked = ($picked === null || $picked === '' || (int)$picked < 0 || (int)$picked >= count($question['options'])) ? null : (int)$picked;
            $correct = (int)$question['answer'];
            $isRight = ($picked !== null && $picked === $correct);
        ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h2 class="section-title mt-0">
                        Question <?php echo $i + 1; ?>
                        <span class="badge text-bg-<?php echo ($isRight ? 'success' : 'danger'); ?> ms-2">
                            <?php echo ($isRight ? 'Correct' : ($picked === null ? 'Not answered' : 'Wrong')); ?>
                        </span>
                    </h2>
                    <p class="quiz-question mb-2"><?php echo htmlspecialchars((string)$question['question']); ?></p>

                    <ul class="list-unstyled quiz-review-options mb-2">
                        <?php foreach ($question['options'] as $optIndex => $option) {
                            $classes = array('quiz-review-option');
                            if ($optIndex === $correct) { $classes[] = 'correct'; }
                            if ($optIndex === $picked)  { $classes[] = ($isRight ? 'correct' : 'wrong'); }
                        ?>
                            <li class="<?php echo implode(' ', $classes); ?>">
                                <strong><?php echo chr(65 + $optIndex); ?>.</strong>
                                <?php echo htmlspecialchars((string)$option); ?>
                                <?php if ($optIndex === $correct) { ?>
                                    <span class="badge text-bg-success ms-1">correct answer</span>
                                <?php } ?>
                                <?php if ($optIndex === $picked && $picked !== $correct) { ?>
                                    <span class="badge text-bg-danger ms-1">your answer</span>
                                <?php } elseif ($optIndex === $picked && $isRight) { ?>
                                    <span class="badge text-bg-success ms-1">your answer</span>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>

                    <?php if (trim((string)$question['explanation']) !== '') { ?>
                        <div class="alert alert-light border mb-0 quiz-explanation">
                            <strong>Why:</strong> <?php echo htmlspecialchars((string)$question['explanation']); ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    <?php } ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>