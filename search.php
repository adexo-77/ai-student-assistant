<?php
// ============================================================
//  SEARCH.PHP – quick search over the student's own data:
//  courses, assignments and quizzes. Every query is filtered
//  with "AND user_id = ?" so a student only finds own records.
// ============================================================

$pageTitle = 'Search';
$navActive = 'search';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId  = (int)$_SESSION['user_id'];
$query   = (isset($_GET['q']) && is_string($_GET['q'])) ? trim($_GET['q']) : '';
$results = array('courses' => array(), 'assignments' => array(), 'quizzes' => array());
$dbError = '';

if ($query !== '' && mb_strlen($query) <= 100) {
    try {
        $pdo  = getPDO();
        $like = '%' . $query . '%';

        $stmt = $pdo->prepare(
            'SELECT id, name, code, description FROM courses
              WHERE user_id = ? AND (name LIKE ? OR code LIKE ? OR description LIKE ?)
              ORDER BY name ASC LIMIT 8'
        );
        $stmt->execute([$userId, $like, $like, $like]);
        $results['courses'] = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT a.id, a.title, a.status, c.name AS course_name
               FROM assignments a
               JOIN courses c ON c.id = a.course_id
              WHERE a.user_id = ? AND (a.title LIKE ? OR a.description LIKE ?)
              ORDER BY a.created_at DESC LIMIT 8'
        );
        $stmt->execute([$userId, $like, $like]);
        $results['assignments'] = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT q.id, q.title, q.difficulty, c.name AS course_name
               FROM quizzes q
               JOIN courses c ON c.id = q.course_id
              WHERE q.user_id = ? AND q.title LIKE ?
              ORDER BY q.created_at DESC LIMIT 8'
        );
        $stmt->execute([$userId, $like]);
        $results['quizzes'] = $stmt->fetchAll();

    } catch (PDOException $e) {
        $dbError = friendlyError('search', $e);
    }
}

$found = count($results['courses']) + count($results['assignments']) + count($results['quizzes']);

include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrap">

    <div class="page-heading">
        <div>
            <h1 class="page-title">Search</h1>
            <p class="page-subtitle mb-0">Find your courses, assignments and quizzes.</p>
        </div>
    </div>

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } ?>

    <form method="get" action="search.php" class="row g-2 mb-4">
        <div class="col">
            <input type="search" class="form-control" name="q" value="<?php echo htmlspecialchars($query); ?>"
                   placeholder="Type a keyword&hellip;" maxlength="100" autofocus>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary" type="submit">Search</button>
        </div>
    </form>

    <?php if ($query === '') { ?>
        <div class="card"><div class="card-body text-muted">
            Type a keyword above, or use the search box in the top bar.
        </div></div>
    <?php } elseif ($found === 0) { ?>
        <div class="card"><div class="card-body text-muted">
            Nothing found for <strong>&ldquo;<?php echo htmlspecialchars($query); ?>&rdquo;</strong>.
        </div></div>
    <?php } else { ?>
        <p class="text-muted small"><?php echo $found; ?> result(s) for <strong>&ldquo;<?php echo htmlspecialchars($query); ?>&rdquo;</strong></p>

        <?php if (count($results['courses']) > 0) { ?>
            <h2 class="section-title">Courses</h2>
            <div class="card mb-4"><div class="card-body"><div class="list-stack">
                <?php foreach ($results['courses'] as $row) { ?>
                    <div class="list-item">
                        <div class="list-main">
                            <a class="list-title" href="course.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars((string)$row['name']); ?></a>
                            <div class="list-meta"><?php if ($row['code'] !== '') { ?><span><?php echo htmlspecialchars((string)$row['code']); ?></span><?php } ?></div>
                        </div>
                        <div class="list-actions"><a class="btn btn-ghost btn-sm" href="course.php?id=<?php echo (int)$row['id']; ?>">Open</a></div>
                    </div>
                <?php } ?>
            </div></div></div>
        <?php } ?>

        <?php if (count($results['assignments']) > 0) { ?>
            <h2 class="section-title">Assignments</h2>
            <div class="card mb-4"><div class="card-body"><div class="list-stack">
                <?php foreach ($results['assignments'] as $row) { ?>
                    <div class="list-item">
                        <div class="list-main">
                            <a class="list-title" href="assignment.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars((string)$row['title']); ?></a>
                            <div class="list-meta">
                                <span><?php echo htmlspecialchars((string)$row['course_name']); ?></span>
                                <span class="badge text-bg-<?php echo statusBadge((string)$row['status']); ?>"><?php echo htmlspecialchars(statusLabel((string)$row['status'])); ?></span>
                            </div>
                        </div>
                        <div class="list-actions"><a class="btn btn-ghost btn-sm" href="assignment.php?id=<?php echo (int)$row['id']; ?>">Open</a></div>
                    </div>
                <?php } ?>
            </div></div></div>
        <?php } ?>

        <?php if (count($results['quizzes']) > 0) { ?>
            <h2 class="section-title">Quizzes</h2>
            <div class="card mb-4"><div class="card-body"><div class="list-stack">
                <?php foreach ($results['quizzes'] as $row) { ?>
                    <div class="list-item">
                        <div class="list-main">
                            <a class="list-title" href="quiz.php?id=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars((string)$row['title']); ?></a>
                            <div class="list-meta">
                                <span><?php echo htmlspecialchars((string)$row['course_name']); ?></span>
                                <span class="badge text-bg-info"><?php echo htmlspecialchars(difficultyLabel((string)$row['difficulty'])); ?></span>
                            </div>
                        </div>
                        <div class="list-actions"><a class="btn btn-ghost btn-sm" href="quiz.php?id=<?php echo (int)$row['id']; ?>">Open</a></div>
                    </div>
                <?php } ?>
            </div></div></div>
        <?php } ?>

    <?php } ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
