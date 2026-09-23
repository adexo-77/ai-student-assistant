<?php
// ============================================================
//  ASSIGNMENTS.PHP – all assignments of the logged-in student:
//  add, edit, delete, filter by course / status / text and jump
//  to the assignment page (with AI help) of one assignment.
//
//  Reuses: includes/auth.php, includes/security.php (CSRF +
//  flash), includes/study.php (helpers, ownership in SQL).
// ============================================================

$pageTitle = 'Assignments';
$navActive = 'assignments';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId      = (int)$_SESSION['user_id'];
$errors      = array();
$dbError     = '';
$courses     = array();
$assignments = array();
$filtered    = array();

// the form: empty by default, filled from $_POST or from ?edit=ID
$form = array(
    'id'          => 0,
    'course_id'   => 0,
    'title'       => '',
    'description' => '',
    'due_date'    => '',
    'status'      => 'pending',
);

$action = (isset($_POST['action']) && is_string($_POST['action'])) ? $_POST['action'] : '';

try {
    $pdo     = getPDO();
    $courses = listUserCourses($pdo, $userId);

    // ----------------------------------------------------------
    //  POST: delete or save an assignment
    // ----------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfCheck();                                  // wrong token = stop

        if ($action === 'delete') {
            $id         = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $assignment = findUserAssignment($pdo, $id, $userId);

            if ($assignment === false) {
                flashSet('danger', 'That assignment does not exist.');
            } else {
                $stmt = $pdo->prepare('DELETE FROM assignments WHERE id = ? AND user_id = ?');
                $stmt->execute([$id, $userId]);
                flashSet('success', 'Assignment "' . $assignment['title'] . '" was deleted.');
            }

            header('Location: assignments.php');
            exit;
        }

        // ---- read + check the form ----
        $form['id']          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $form['course_id']   = isset($_POST['course_id']) ? (int)$_POST['course_id'] : 0;
        $form['title']       = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
        $form['description'] = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));
        $form['due_date']    = trim((string)(isset($_POST['due_date']) ? $_POST['due_date'] : ''));
        $form['status']      = (string)(isset($_POST['status']) ? $_POST['status'] : 'pending');

        if (findUserCourse($pdo, $form['course_id'], $userId) === false) {
            $errors[] = 'Please choose one of your courses.';
        }
        if ($form['title'] === '') {
            $errors[] = 'Please enter a title.';
        } elseif (mb_strlen($form['title']) > 150) {
            $errors[] = 'The title may not be longer than 150 characters.';
        }
        if (!isset(assignmentStatuses()[$form['status']])) {
            $errors[] = 'Please choose a valid status.';
        }
        if ($form['due_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['due_date'])) {
            $errors[] = 'The due date must look like 2026-05-31.';
        }
        if ($form['id'] > 0 && findUserAssignment($pdo, $form['id'], $userId) === false) {
            $errors[] = 'That assignment does not exist.';
        }

        if (count($errors) === 0) {
            $dueDate = ($form['due_date'] === '') ? null : $form['due_date'];

            if ($form['id'] > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE assignments
                        SET course_id = ?, title = ?, description = ?, due_date = ?, status = ?
                      WHERE id = ? AND user_id = ?'
                );
                $stmt->execute([
                    $form['course_id'], $form['title'], $form['description'],
                    $dueDate, $form['status'], $form['id'], $userId,
                ]);
                flashSet('success', 'Assignment "' . $form['title'] . '" was updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO assignments (user_id, course_id, title, description, due_date, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $userId, $form['course_id'], $form['title'],
                    $form['description'], $dueDate, $form['status'],
                ]);
                flashSet('success', 'Assignment "' . $form['title'] . '" was added.');
            }

            header('Location: assignments.php');
            exit;
        }
    }
    // ----------------------------------------------------------
    //  GET: ?edit=ID fills the form, ?course_id=ID preselects a
    //  course (the "Add assignment" button on the course page).
    // ----------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (isset($_GET['edit'])) {
            $edit = findUserAssignment($pdo, (int)$_GET['edit'], $userId);
            if ($edit === false) {
                flashSet('danger', 'That assignment does not exist.');
                header('Location: assignments.php');
                exit;
            }
            $form['id']          = (int)$edit['id'];
            $form['course_id']   = (int)$edit['course_id'];
            $form['title']       = (string)$edit['title'];
            $form['description'] = (string)$edit['description'];
            $form['due_date']    = (string)$edit['due_date'];
            $form['status']      = (string)$edit['status'];
        } elseif (isset($_GET['course_id'])) {
            $preselect = findUserCourse($pdo, (int)$_GET['course_id'], $userId);
            if ($preselect !== false) {
                $form['course_id'] = (int)$preselect['id'];
            }
        }
    }

    // ----------------------------------------------------------
    //  The list + the simple filters (?course_id=, ?status=, ?q=)
    // ----------------------------------------------------------
    $assignments = listUserAssignments($pdo, $userId);

    $filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    $filterStatus = isset($_GET['status']) ? (string)$_GET['status'] : '';
    $filterText   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

    foreach ($assignments as $assignment) {
        if ($filterCourse > 0 && (int)$assignment['course_id'] !== $filterCourse) {
            continue;
        }
        if ($filterStatus !== '' && (string)$assignment['status'] !== $filterStatus) {
            continue;
        }
        if ($filterText !== '' && mb_stripos((string)$assignment['title'], $filterText) === false) {
            continue;
        }
        $filtered[] = $assignment;
    }
} catch (PDOException $e) {
    $dbError  = friendlyError('assignments', $e);
    $filtered = array();
}

include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrap">

    <div class="page-heading">
        <div>
            <h1 class="page-title">My <span class="grad-text">assignments</span></h1>
            <p class="page-subtitle mb-0">Track what is due, change the status and get AI help with the work.</p>
        </div>
        <?php if ($form['id'] === 0) { ?>
            <a class="btn btn-ghost" href="#assignment-form">+ New assignment</a>
        <?php } else { ?>
            <a class="btn btn-ghost" href="assignments.php">Cancel edit</a>
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
            You need a course first. <a href="courses.php">Create your first course</a>,
            then come back here to add assignments.
        </div>
    <?php } ?>

    <!-- ---------------- add / edit form ---------------- -->
    <div class="card" id="assignment-form">
        <div class="card-body">
            <h2 class="section-title mt-0">
                <?php echo ($form['id'] > 0 ? 'Edit assignment' : 'Add an assignment'); ?>
            </h2>

            <form method="post" action="assignments.php" class="row g-3">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

                <div class="col-12 col-md-6">
                    <label class="form-label" for="course_id">Course</label>
                    <select class="form-select" id="course_id" name="course_id" <?php echo (count($courses) === 0 ? 'disabled' : ''); ?>>
                        <option value="0">Choose a course…</option>
                        <?php foreach ($courses as $course) { ?>
                            <option value="<?php echo (int)$course['id']; ?>"<?php echo ((int)$course['id'] === (int)$form['course_id'] ? ' selected' : ''); ?>>
                                <?php echo htmlspecialchars((string)$course['name']); ?><?php echo ($course['code'] !== '' ? ' (' . htmlspecialchars((string)$course['code']) . ')' : ''); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="title">Title</label>
                    <input class="form-control" type="text" id="title" name="title" maxlength="150"
                           placeholder="e.g. Chapter 4 exercises" required
                           value="<?php echo htmlspecialchars((string)$form['title']); ?>">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="due_date">Due date <span class="text-muted">(optional)</span></label>
                    <input class="form-control" type="date" id="due_date" name="due_date"
                           value="<?php echo htmlspecialchars((string)$form['due_date']); ?>">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (assignmentStatuses() as $statusValue => $statusText) { ?>
                            <option value="<?php echo htmlspecialchars($statusValue, ENT_QUOTES); ?>"<?php echo ($statusValue === $form['status'] ? ' selected' : ''); ?>><?php echo htmlspecialchars($statusText); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label" for="description">Description <span class="text-muted">(optional)</span></label>
                    <textarea class="form-control" id="description" name="description" rows="3"
                              placeholder="What exactly has to be done?"><?php echo htmlspecialchars((string)$form['description']); ?></textarea>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit" <?php echo (count($courses) === 0 ? 'disabled' : ''); ?>>
                        <?php echo ($form['id'] > 0 ? 'Save changes' : 'Add assignment'); ?>
                    </button>
                    <?php if ($form['id'] > 0) { ?>
                        <a class="btn btn-ghost" href="assignments.php">Cancel</a>
                    <?php } ?>
                </div>
            </form>
        </div>
    </div>

    <!-- ---------------- filters ---------------- -->
    <h2 class="section-title">Your assignments</h2>
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" action="assignments.php" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label" for="f-course">Course</label>
                    <select class="form-select" id="f-course" name="course_id">
                        <option value="0">All courses</option>
                        <?php foreach ($courses as $course) { ?>
                            <option value="<?php echo (int)$course['id']; ?>"<?php echo ((int)$course['id'] === $filterCourse ? ' selected' : ''); ?>>
                                <?php echo htmlspecialchars((string)$course['name']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="f-status">Status</label>
                    <select class="form-select" id="f-status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach (assignmentStatuses() as $statusValue => $statusText) { ?>
                            <option value="<?php echo htmlspecialchars($statusValue, ENT_QUOTES); ?>"<?php echo ($statusValue === $filterStatus ? ' selected' : ''); ?>><?php echo htmlspecialchars($statusText); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="f-q">Search title</label>
                    <input class="form-control" type="text" id="f-q" name="q" placeholder="Type a word…"
                           value="<?php echo htmlspecialchars($filterText); ?>">
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button class="btn btn-ghost" type="submit">Apply</button>
                </div>
            </form>
            <p class="text-muted small mb-0 mt-2">
                Showing <?php echo count($filtered); ?> of <?php echo count($assignments); ?> assignment(s).
                <a href="assignments.php">Reset filters</a>
            </p>
        </div>
    </div>

    <!-- ---------------- the list ---------------- -->
    <?php if (count($filtered) === 0) { ?>
        <div class="card">
            <div class="card-body text-muted">
                <?php echo (count($assignments) === 0
                    ? 'No assignments yet. Add the first one above.'
                    : 'No assignment matches these filters.'); ?>
            </div>
        </div>
    <?php } else { ?>
        <div class="card">
            <div class="card-body">
                <div class="list-stack">
                    <?php foreach ($filtered as $assignment) { ?>
                        <div class="list-item">
                            <div class="list-main">
                                <a class="list-title" href="assignment.php?id=<?php echo (int)$assignment['id']; ?>"><?php echo htmlspecialchars((string)$assignment['title']); ?></a>
                                <div class="list-meta">
                                    <a href="course.php?id=<?php echo (int)$assignment['course_id']; ?>"><?php echo htmlspecialchars((string)$assignment['course_name']); ?></a>
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
                                <a class="btn btn-ghost btn-sm" href="assignments.php?edit=<?php echo (int)$assignment['id']; ?>#assignment-form">Edit</a>
                                <form method="post" action="assignments.php" class="d-inline"
                                      onsubmit="return confirm('Delete this assignment?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$assignment['id']; ?>">
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

<?php include __DIR__ . '/includes/footer.php'; ?>