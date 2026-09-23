<?php
// ============================================================
//  COURSES.PHP – "My Courses": list, add, edit and delete the
//  courses of the logged-in student (and nobody else's).
//
//  Uses the existing auth (requireLogin), the existing database
//  (db.php via includes/study.php) and security.php for CSRF.
// ============================================================

$pageTitle = 'My Courses';
$navActive = 'courses';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId   = (int)$_SESSION['user_id'];
$errors   = array();
$dbError  = '';
$courses  = array();
$progress = array();                       // course id => progress numbers
$form     = array('id' => 0, 'name' => '', 'code' => '', 'description' => '');

$action = (isset($_POST['action']) && is_string($_POST['action'])) ? $_POST['action'] : '';

try {
    $pdo = getPDO();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfCheck();                                    // wrong/missing token = stop

        // ---------------- delete a course ----------------
        if ($action === 'delete') {
            $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $course = findUserCourse($pdo, $id, $userId);
            if ($course === false) {
                flashSet('danger', 'That course does not exist.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM courses WHERE id = ? AND user_id = ?');
                $stmt->execute([$id, $userId]);
                flashSet('success', 'Course "' . $course['name'] . '" was deleted.');
            }
            header('Location: courses.php');
            exit;
        }

        // ---------------- add / update a course ----------------
        $form['id']          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $form['name']        = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $form['code']        = strtoupper(trim((string)(isset($_POST['code']) ? $_POST['code'] : '')));
        $form['description'] = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));

        if ($form['name'] === '') {
            $errors[] = 'Please enter a course name.';
        } elseif (mb_strlen($form['name']) > 150) {
            $errors[] = 'The course name may not be longer than 150 characters.';
        }
        if (mb_strlen($form['code']) > 30) {
            $errors[] = 'The course code may not be longer than 30 characters.';
        }
        if ($form['id'] > 0 && findUserCourse($pdo, $form['id'], $userId) === false) {
            $errors[] = 'That course does not exist.';
        }

        if (count($errors) === 0) {
            if ($form['id'] > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE courses SET name = ?, code = ?, description = ?
                      WHERE id = ? AND user_id = ?'
                );
                $stmt->execute([$form['name'], $form['code'], $form['description'], $form['id'], $userId]);
                flashSet('success', 'Course "' . $form['name'] . '" was updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO courses (user_id, name, code, description) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$userId, $form['name'], $form['code'], $form['description']]);
                flashSet('success', 'Course "' . $form['name'] . '" was created.');
            }
            header('Location: courses.php');           // Post/Redirect/Get
            exit;
        }
    }

    // ---------------- "Edit" link (?edit=5) fills the form ----------------
    if (isset($_GET['edit'])) {
        $course = findUserCourse($pdo, (int)$_GET['edit'], $userId);
        if ($course === false) {
            flashSet('danger', 'That course does not exist.');
            header('Location: courses.php');
            exit;
        }
        $form = array(
            'id'          => (int)$course['id'],
            'name'        => (string)$course['name'],
            'code'        => (string)$course['code'],
            'description' => (string)$course['description'],
        );
    }

    // ---------------- the list (+ simple progress) ----------------
    $courses = listUserCourses($pdo, $userId);
    foreach ($courses as $course) {
        $progress[(int)$course['id']] = courseProgress($pdo, $userId, $course['id']);
    }
} catch (PDOException $e) {
    $dbError = friendlyError('courses', $e);
}

include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrap">

    <div class="page-heading">
        <div>
            <h1 class="page-title">My <span class="grad-text">courses</span></h1>
            <p class="page-subtitle mb-0">
                <?php echo count($courses); ?> course<?php echo (count($courses) === 1 ? '' : 's'); ?>
                &middot; keep your study material, assignments and quizzes together.
            </p>
        </div>
        <a class="btn btn-ghost" href="assignments.php">All assignments</a>
    </div>

    <?php flashRender(); ?>

    <?php foreach ($errors as $error) { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } ?>

    <div class="row g-4">
        <!-- ---------------- add / edit form ---------------- -->
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h2 class="section-title mt-0"><?php echo ($form['id'] > 0 ? 'Edit course' : 'Add a course'); ?></h2>

                    <form method="post" action="courses.php" class="row g-3">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

                        <div class="col-12">
                            <label class="form-label" for="courseName">Course name *</label>
                            <input class="form-control" type="text" id="courseName" name="name"
                                   maxlength="150" required placeholder="e.g. Database Systems"
                                   value="<?php echo htmlspecialchars($form['name']); ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="courseCode">Course code</label>
                            <input class="form-control" type="text" id="courseCode" name="code"
                                   maxlength="30" placeholder="e.g. DBMS301"
                                   value="<?php echo htmlspecialchars($form['code']); ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="courseDescription">Description</label>
                            <textarea class="form-control" id="courseDescription" name="description"
                                      rows="4" placeholder="What is this course about?"><?php echo htmlspecialchars($form['description']); ?></textarea>
                        </div>

                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">
                                <?php echo ($form['id'] > 0 ? 'Update course' : 'Save course'); ?>
                            </button>
                            <?php if ($form['id'] > 0) { ?>
                                <a class="btn btn-ghost" href="courses.php">Cancel</a>
                            <?php } ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ---------------- course list ---------------- -->
        <div class="col-12 col-lg-8">
            <?php if (count($courses) === 0) { ?>
                <div class="card">
                    <div class="card-body text-muted">
                        You have no courses yet. Add your first course with the form on the left &mdash;
                        then you can add assignments, ask the AI about this course and generate quizzes.
                    </div>
                </div>
            <?php } ?>

            <?php foreach ($courses as $course) {
                $courseId = (int)$course['id'];
                $p        = $progress[$courseId];
                $percent  = ($p['assignments_total'] > 0)
                          ? (int)round($p['assignments_completed'] / $p['assignments_total'] * 100)
                          : 0;
                ?>
                <div class="card course-card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <div>
                                <h3 class="course-name">
                                    <a href="course.php?id=<?php echo $courseId; ?>"><?php echo htmlspecialchars($course['name']); ?></a>
                                </h3>
                                <?php if ($course['code'] !== '') { ?>
                                    <span class="course-code"><?php echo htmlspecialchars($course['code']); ?></span>
                                <?php } ?>
                            </div>

                            <div class="course-actions">
                                <a class="btn btn-ghost btn-sm" href="course.php?id=<?php echo $courseId; ?>">Open</a>
                                <a class="btn btn-ghost btn-sm" href="index.php?course_id=<?php echo $courseId; ?>">Ask AI</a>
                                <a class="btn btn-ghost btn-sm" href="courses.php?edit=<?php echo $courseId; ?>">Edit</a>
                                <form method="post" action="courses.php" class="d-inline"
                                      onsubmit="return confirm('Delete this course together with its assignments, quizzes and material notes?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $courseId; ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>

                        <?php if (trim((string)$course['description']) !== '') { ?>
                            <p class="course-desc"><?php echo nl2br(htmlspecialchars((string)$course['description'])); ?></p>
                        <?php } ?>

                        <div class="progress progress-mini" role="progressbar" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                        <div class="progress-meta">
                            <span>Assignments: <?php echo (int)$p['assignments_completed']; ?>/<?php echo (int)$p['assignments_total']; ?> completed</span>
                            <span>Pending: <?php echo (int)$p['assignments_pending']; ?></span>
                            <span>Quizzes taken: <?php echo (int)$p['quizzes_taken']; ?></span>
                            <span>Average score: <?php echo ($p['average_score'] === null ? '&ndash;' : (int)$p['average_score'] . '%'); ?></span>
                        </div>

                        <div class="course-actions mt-2">
                            <a class="btn btn-ghost btn-sm" href="assignments.php?course_id=<?php echo $courseId; ?>">+ New assignment</a>
                            <a class="btn btn-ghost btn-sm" href="quizzes.php?course_id=<?php echo $courseId; ?>">Generate quiz</a>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>