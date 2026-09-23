<?php
// ============================================================
//  QUIZZES.PHP – list of the student's AI-generated quizzes +
//  the "Generate quiz" form (Gemini writes 5 or 10 questions
//  about one course; they are stored as JSON in MySQL).
//
//  Every query is filtered with "AND user_id = ?" so a student
//  can only ever see and delete their own quizzes.
// ============================================================

$navActive = 'quizzes';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId  = (int)$_SESSION['user_id'];
$errors  = array();
$dbError = '';
$courses = array();

try {
    $pdo     = getPDO();
    $courses = listUserCourses($pdo, $userId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfCheck();                                        // wrong token = stop
        $action = (string)(isset($_POST['action']) ? $_POST['action'] : '');

        // ---------------- generate a new quiz with Gemini ----------------
        if ($action === 'generate') {
            $courseId   = (int)(isset($_POST['course_id']) ? $_POST['course_id'] : 0);
            $difficulty = (string)(isset($_POST['difficulty']) ? $_POST['difficulty'] : 'medium');

            // The count can be a preset radio (5/10/20/...) or "custom".
            $rawCount = isset($_POST['count']) ? $_POST['count'] : '5';
            if ($rawCount === 'custom') {
                $count = (int)(isset($_POST['count_custom']) ? $_POST['count_custom'] : 0);
            } else {
                $count = (int)$rawCount;
            }
            if ($count < 1 || $count > 100) {
                $errors[] = 'Choose between 1 and 100 questions.';
            }

            $course = findUserCourse($pdo, $courseId, $userId);
            if ($course === false) {
                $errors[] = 'Please choose one of your courses.';
            } elseif (count($errors) === 0) {
                try {
                    // Big quizzes need several API calls, so give PHP time.
                    @set_time_limit(0);

                    // Same AI pipeline as the chat: askGeminiJson() inside.
                    $questions = generateQuizQuestions($course, $count, $difficulty);
                    $title     = 'Quiz – ' . $course['name'] . ' (' . date('d M Y') . ')';
                    $quizId    = saveQuiz($pdo, $userId, $courseId, $title, $difficulty, $questions);

                    flashSet('success', 'Quiz generated with ' . count($questions) . ' questions. Good luck!');
                    header('Location: quiz.php?id=' . $quizId);
                    exit;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();           // friendly AI/network message
                }
            }
        }

        // ---------------- delete a quiz (attempts cascade too) ----------------
        if ($action === 'delete') {
            $quizId = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
            $stmt   = $pdo->prepare('DELETE FROM quizzes WHERE id = ? AND user_id = ?');
            $stmt->execute([$quizId, $userId]);

            flashSet('success', 'Quiz deleted.');
            header('Location: quizzes.php');
            exit;
        }
    }
} catch (PDOException $e) {
    $dbError = friendlyError('quiz', $e);
}

// The list (empty when the database failed, so the page still renders).
$quizzes   = (isset($pdo) && $dbError === '') ? listUserQuizzes($pdo, $userId) : array();
$preselect = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

$pageTitle = 'Quizzes';

include __DIR__ . '/includes/header.php';
?>
<div class="container page-wrap">

    <div class="page-heading">
        <div>
            <h1 class="page-title">Practice <span class="grad-text">quizzes</span></h1>
            <p class="page-subtitle mb-0">Create AI-powered practice quizzes for your courses – the AI writes the questions, you take the quiz and see your score.</p>
        </div>
        <?php if (count($courses) === 0) { ?>
            <a class="btn btn-ghost" href="courses.php">Create a course first</a>
        <?php } ?>
    </div>

    <?php flashRender(); ?>

    <?php foreach ($errors as $error) { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } ?>

    <?php if (count($courses) === 0) { ?>
        <div class="alert alert-info">
            You need a course before the AI can write a quiz about it.
            <a href="courses.php">Create your first course</a>, then come back here.
        </div>
    <?php } ?>

    <!-- ---------------- generate form ---------------- -->
    <div class="card" id="quiz-form">
        <div class="card-body">
            <h2 class="section-title mt-0">Generate a new quiz</h2>

            <form method="post" action="quizzes.php" class="row g-3">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="generate">

                <div class="col-12 col-md-5">
                    <label class="form-label" for="course_id">Course</label>
                    <select class="form-select" id="course_id" name="course_id" <?php echo (count($courses) === 0 ? 'disabled' : ''); ?>>
                        <option value="0">Choose a course…</option>
                        <?php foreach ($courses as $course) { ?>
                            <option value="<?php echo (int)$course['id']; ?>"<?php echo ((int)$course['id'] === $preselect ? ' selected' : ''); ?>>
                                <?php echo htmlspecialchars((string)$course['name']); ?><?php echo ($course['code'] !== '' ? ' (' . htmlspecialchars((string)$course['code']) . ')' : ''); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="col-6 col-md-3">
                    <label class="form-label d-block" id="difficulty-label">Difficulty</label>
                    <div class="seg-group" role="group" aria-labelledby="difficulty-label">
                        <?php foreach (quizDifficulties() as $diffValue => $diffText) { ?>
                            <input class="seg-input" type="radio" name="difficulty"
                                   id="diff-<?php echo htmlspecialchars($diffValue, ENT_QUOTES); ?>"
                                   value="<?php echo htmlspecialchars($diffValue, ENT_QUOTES); ?>"
                                   <?php echo ($diffValue === 'medium' ? 'checked' : ''); ?>>
                            <label class="seg-label" for="diff-<?php echo htmlspecialchars($diffValue, ENT_QUOTES); ?>"><?php echo htmlspecialchars($diffText); ?></label>
                        <?php } ?>
                    </div>
                </div>

                <!-- how many questions – any number from 1 to 100 -->
                <div class="col-12">
                    <label class="form-label d-block" id="count-label">Number of questions</label>
                    <div class="seg-group seg-group-wrap" role="group" aria-labelledby="count-label">
                        <?php foreach (array(5, 10, 20, 30, 50, 100) as $presetCount) { ?>
                            <input class="seg-input count-choice" type="radio" name="count"
                                   id="count-<?php echo $presetCount; ?>" value="<?php echo $presetCount; ?>"
                                   <?php echo ($presetCount === 10 ? 'checked' : ''); ?>>
                            <label class="seg-label" for="count-<?php echo $presetCount; ?>"><?php echo $presetCount; ?></label>
                        <?php } ?>
                        <input class="seg-input count-choice" type="radio" name="count" id="count-custom" value="custom">
                        <label class="seg-label" for="count-custom">Custom</label>
                    </div>

                    <div class="count-custom-box" id="count-custom-box" hidden>
                        <label class="form-label" for="count_custom">Your own number of questions (1–100)</label>
                        <input class="form-control" type="number" id="count_custom" name="count_custom"
                               min="1" max="100" step="1" value="15" inputmode="numeric">
                    </div>
                </div>

                <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
                    <button class="btn btn-primary" type="submit" <?php echo (count($courses) === 0 ? 'disabled' : ''); ?>>
                        Generate quiz
                    </button>
                    <span class="text-muted small">
                        The AI writes the questions for you. Larger quizzes are built in batches,
                        so 50–100 questions can take a minute or two.
                    </span>
                </div>
            </form>
        </div>
    </div>

        <!-- ---------------- the quiz list ---------------- -->
    <h2 class="section-title">Your quizzes</h2>

    <?php if (count($quizzes) === 0) { ?>
        <div class="card">
            <div class="card-body text-muted">
                No quizzes yet. Pick a course above and press <strong>Generate</strong>.
            </div>
        </div>
    <?php } else { ?>
        <div class="card">
            <div class="card-body">
                <div class="list-stack">
                    <?php foreach ($quizzes as $quiz) {
                        $questionCount = count(decodeQuizQuestions($quiz['questions']));
                        $best = ($quiz['best_score'] === null) ? null : (int)round((float)$quiz['best_score']);
                    ?>
                        <div class="list-item">
                            <div class="list-main">
                                <a class="list-title" href="quiz.php?id=<?php echo (int)$quiz['id']; ?>"><?php echo htmlspecialchars((string)$quiz['title']); ?></a>
                                <div class="list-meta">
                                    <a href="course.php?id=<?php echo (int)$quiz['course_id']; ?>"><?php echo htmlspecialchars((string)$quiz['course_name']); ?></a>
                                    <span class="badge text-bg-info"><?php echo htmlspecialchars(difficultyLabel((string)$quiz['difficulty'])); ?></span>
                                    <span><?php echo $questionCount; ?> question(s)</span>
                                    <span>
                                        <?php echo (int)$quiz['attempts']; ?> attempt(s)<?php echo ($best !== null ? ' · best ' . $best . '%' : ''); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="list-actions">
                                <a class="btn btn-primary btn-sm" href="quiz.php?id=<?php echo (int)$quiz['id']; ?>">Take quiz</a>
                                <form method="post" action="quizzes.php" class="d-inline"
                                      onsubmit="return confirm('Delete this quiz and all its attempts?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$quiz['id']; ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>
</div>

<script>
// ---------------------------------------------------------------
//  Quiz generator: show the "custom number of questions" box only
//  when the Custom button is selected, and keep the buttons in sync
//  when the student types a number straight into the box.
//  (Plain JavaScript – the project does not use any JS framework.)
// ---------------------------------------------------------------
(function () {
    var customRadio = document.getElementById('count-custom');
    var customBox   = document.getElementById('count-custom-box');
    var customInput = document.getElementById('count_custom');

    if (!customRadio || !customBox || !customInput) {
        return;
    }

    function syncBox() {
        var isCustom = customRadio.checked;
        if (isCustom) {
            customBox.removeAttribute('hidden');
        } else {
            customBox.setAttribute('hidden', 'hidden');
        }
    }

    var radios = document.querySelectorAll('.count-choice');
    for (var i = 0; i < radios.length; i++) {
        radios[i].addEventListener('change', syncBox);
    }

    // Typing in the box selects "Custom" for you.
    customInput.addEventListener('focus', function () {
        customRadio.checked = true;
        syncBox();
    });
    customInput.addEventListener('input', function () {
        customRadio.checked = true;
        syncBox();
    });

    syncBox();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>