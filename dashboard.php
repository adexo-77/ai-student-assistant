<?php
// ============================================================
//  DASHBOARD.PHP – the Student Assistant home page
//
//  Professional dashboard: welcome block, statistics from the
//  real database, quick actions, recent activity, course cards,
//  upcoming assignments, quiz progress and the AI study card.
//  Reuses the existing auth (auth.php), database (db.php),
//  helpers (includes/study.php) and the existing tables.
// ============================================================

$pageTitle = 'Dashboard';
$navActive = 'dashboard';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId   = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'student');

// ---- data containers --------------------------------------------
$stats = array(
    'courses'     => 0,   // My Courses
    'pending'     => 0,   // Pending Assignments
    'materials'   => 0,   // Study Materials
    'quizzesDone' => 0,   // Completed Quizzes (distinct quizzes attempted)
);
$courses      = array();   // course cards with counts + progress
$upcoming     = array();   // pending / in-progress assignments
$recent       = array();   // latest activity of all types
$recentScores = array();   // latest quiz scores
$overallAvg   = null;      // average quiz score over all attempts
$attemptCount = 0;
$greeting     = 'Hello';

// friendly greeting by the current hour
$hour = (int)date('G');
if ($hour < 12)      { $greeting = 'Good morning'; }
elseif ($hour < 18)  { $greeting = 'Good afternoon'; }
else                 { $greeting = 'Good evening'; }

$dbError = '';

try {
    $pdo = getPDO();

    // ---- statistics (every query filtered by user_id) ----------
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM courses WHERE user_id = ?');
    $stmt->execute([$userId]);
    $stats['courses'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM assignments WHERE user_id = ? AND status <> 'completed'"
    );
    $stmt->execute([$userId]);
    $stats['pending'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM course_materials WHERE user_id = ?');
    $stmt->execute([$userId]);
    $stats['materials'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT quiz_id) AS c FROM quiz_attempts WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $stats['quizzesDone'] = (int)$stmt->fetch()['c'];

    // ---- quiz performance: attempts + average score ------------
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS c,
                AVG(CASE WHEN total_questions > 0
                         THEN score / total_questions * 100 END) AS avg_score
           FROM quiz_attempts
          WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $attemptCount = (int)$row['c'];
    $overallAvg   = ($row['avg_score'] === null) ? null : (int)round((float)$row['avg_score']);

    // ---- recent quiz scores (for the progress section) ---------
    $stmt = $pdo->prepare(
        'SELECT a.score, a.total_questions, a.created_at, q.title
           FROM quiz_attempts a
           JOIN quizzes q ON q.id = a.quiz_id
          WHERE a.user_id = ?
          ORDER BY a.created_at DESC
          LIMIT 5'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $total = (int)$row['total_questions'];
        $recentScores[] = array(
            'title' => (string)$row['title'],
            'score' => (int)$row['score'],
            'total' => $total,
            'pct'   => ($total > 0) ? (int)round(((int)$row['score'] / $total) * 100) : 0,
            'when'  => (string)$row['created_at'],
        );
    }

    // ---- upcoming / pending assignments (open ones first) ------
    $stmt = $pdo->prepare(
        "SELECT a.id, a.title, a.due_date, a.status, c.name AS course_name
           FROM assignments a
           JOIN courses c ON c.id = a.course_id
          WHERE a.user_id = ? AND a.status <> 'completed'
          ORDER BY a.due_date IS NULL, a.due_date ASC, a.created_at DESC
          LIMIT 6"
    );
    $stmt->execute([$userId]);
    $upcoming = $stmt->fetchAll();

    // ---- course cards: counts + progress per course ------------
    // One grouped query for the quiz count per course (cheaper than
    // one query per course and keeps every count real).
    $quizCounts = array();
    $stmt = $pdo->prepare(
        'SELECT course_id, COUNT(*) AS c FROM quizzes WHERE user_id = ? GROUP BY course_id'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $quizCounts[(int)$row['course_id']] = (int)$row['c'];
    }

    foreach (listUserCourses($pdo, $userId) as $course) {
        $progress = courseProgress($pdo, $userId, $course['id']);
        $courses[] = array(
            'course'    => $course,
            'counts'    => array(
                'assignments' => (int)$progress['assignments_total'],
                'quizzes'     => (isset($quizCounts[(int)$course['id']]) ? $quizCounts[(int)$course['id']] : 0),
                'attempts'    => (int)$progress['quizzes_taken'],
            ),
            'materials' => count(listCourseMaterials($pdo, $userId, $course['id'])),
            'percent'   => ($progress['assignments_total'] > 0)
                         ? (int)round($progress['assignments_completed'] / $progress['assignments_total'] * 100)
                         : 0,
            'avg'       => $progress['average_score'],
        );
    }

    // ---- recent activity: chat + assignments + quizzes + courses
    $stmt = $pdo->prepare(
        'SELECT id, question, created_at FROM messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 3'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $recent[] = array('type' => 'Chat', 'label' => $row['question'],
                          'when' => $row['created_at'], 'link' => 'index.php');
    }

    $stmt = $pdo->prepare(
        'SELECT id, title, created_at FROM assignments WHERE user_id = ? ORDER BY created_at DESC LIMIT 3'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $recent[] = array('type' => 'Assignment', 'label' => $row['title'],
                          'when' => $row['created_at'], 'link' => 'assignment.php?id=' . (int)$row['id']);
    }

    $stmt = $pdo->prepare(
        'SELECT id, title, created_at FROM quizzes WHERE user_id = ? ORDER BY created_at DESC LIMIT 3'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $recent[] = array('type' => 'Quiz', 'label' => $row['title'],
                          'when' => $row['created_at'], 'link' => 'quiz.php?id=' . (int)$row['id']);
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, created_at FROM courses WHERE user_id = ? ORDER BY created_at DESC LIMIT 3'
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $recent[] = array('type' => 'Course', 'label' => $row['name'],
                          'when' => $row['created_at'], 'link' => 'course.php?id=' . (int)$row['id']);
    }

    usort($recent, function ($a, $b) {
        return strcmp((string)$b['when'], (string)$a['when']);   // newest first
    });
    $recent = array_slice($recent, 0, 7);

} catch (PDOException $e) {
    $dbError = friendlyError('dashboard', $e);
}

include __DIR__ . '/includes/header.php';
?>
<div class="container page-wrap">

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } ?>

    <!-- ---------- welcome ---------- -->
    <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-indigo-400">Your learning workspace</p>
            <h1 class="m-0 text-3xl font-semibold tracking-tight text-slate-100 sm:text-4xl"><?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars($username); ?></h1>
            <p class="mb-0 mt-3 text-sm leading-6 text-slate-400">Pick up where you left off, or explore something new.</p>
        </div>
        <a href="index.php" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white no-underline shadow-lg shadow-indigo-950/30 transition hover:bg-indigo-500 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-400">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z"/></svg>
            Ask AI
        </a>
    </div>

                <!-- ---------- statistics ---------- -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-card-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M4 5h16v14H4zM4 9h16M8 5v4"/></svg>
                </span>
                <span>
                    <span class="stat-value"><?php echo $stats['courses']; ?></span>
                    <span class="stat-label">My Courses</span>
                </span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-card-icon alt-1">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M6 3h9l3 3v15H6zM9 9h6M9 12.5h6M9 16h4"/></svg>
                </span>
                <span>
                    <span class="stat-value"><?php echo $stats['pending']; ?></span>
                    <span class="stat-label">Pending Assignments</span>
                </span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-card-icon alt-2">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/></svg>
                </span>
                <span>
                    <span class="stat-value"><?php echo $stats['quizzesDone']; ?></span>
                    <span class="stat-label">Completed Quizzes</span>
                </span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <span class="stat-card-icon alt-3">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg>
                </span>
                <span>
                    <span class="stat-value"><?php echo $stats['materials']; ?></span>
                    <span class="stat-label">Study Materials</span>
                </span>
            </div>
        </div>
    </div>

    <!-- ---------- quick actions ---------- -->
    <h2 class="section-title">Quick actions</h2>
    <div class="quick-grid mb-4">
        <a class="quick-btn" href="index.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M4 5h16v11H9l-5 4V5z"/></svg>
            Ask AI
        </a>
        <a class="quick-btn" href="index.php?mode=study&amp;prompt=Help%20me%20study%20a%20topic%3A%20">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M5 4h9l5 5v11H5zM14 4v5h5M8 13h8M8 16h5"/></svg>
            Study with AI
        </a>
        <a class="quick-btn" href="courses.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 5v14M5 12h14"/></svg>
            Add Course
        </a>
        <a class="quick-btn" href="index.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M12 16V4m-5 5 5-5 5 5M5 20h14"/></svg>
            Upload Material
        </a>
        <a class="quick-btn" href="quizzes.php">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6"/></svg>
            Take a Quiz
        </a>
    </div>

<!-- MORE_SECTIONS -->

    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <!-- ---------- my courses ---------- -->
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h2 class="section-title mb-0">My courses</h2>
                <a class="btn btn-ghost btn-sm" href="courses.php">Manage</a>
            </div>

            <?php if (count($courses) === 0) { ?>
                <div class="card"><div class="card-body text-muted">
                    No courses yet. <a href="courses.php">Create your first course</a> to organise your study.
                </div></div>
            <?php } else { ?>
                <div class="row g-3">
                    <?php foreach ($courses as $entry) {
                        $course = $entry['course'];
                        $description = trim((string)$course['description']);
                        if (mb_strlen($description) > 90) { $description = mb_substr($description, 0, 90) . '…'; }
                    ?>
                        <div class="col-12 col-md-6">
                            <div class="card h-100">
                                <div class="card-body course-mini-card">
                                    <a class="list-title mb-0" href="course.php?id=<?php echo (int)$course['id']; ?>">
                                        <?php echo htmlspecialchars((string)$course['name']); ?>
                                        <?php if ($course['code'] !== '') { ?>
                                            <span class="course-code"><?php echo htmlspecialchars((string)$course['code']); ?></span>
                                        <?php } ?>
                                    </a>
                                    <?php if ($description !== '') { ?>
                                        <p class="text-muted small mb-0"><?php echo htmlspecialchars($description); ?></p>
                                    <?php } ?>
                                    <div class="course-mini-meta">
                                        <span><?php echo $entry['materials']; ?> material(s)</span>
                                        <span><?php echo $entry['counts']['assignments']; ?> assignment(s)</span>
                                        <span><?php echo $entry['counts']['quizzes']; ?> quiz(zes)</span>
                                    </div>
                                    <div class="progress progress-mini" role="progressbar"
                                         aria-valuenow="<?php echo $entry['percent']; ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar" style="width: <?php echo $entry['percent']; ?>%"></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted small"><?php echo $entry['percent']; ?>% assignments done</span>
                                        <a class="btn btn-primary btn-sm" href="course.php?id=<?php echo (int)$course['id']; ?>">Open Course</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>

            <!-- ---------- assignments ---------- -->
            <div class="d-flex align-items-center justify-content-between mt-4 mb-2">
                <h2 class="section-title mb-0">Upcoming assignments</h2>
                <a class="btn btn-ghost btn-sm" href="assignments.php">All</a>
            </div>

            <?php if (count($upcoming) === 0) { ?>
                <div class="card"><div class="card-body text-muted">
                    Nothing due right now. Enjoy the break or <a href="assignments.php">add an assignment</a>.
                </div></div>
            <?php } else { ?>
                <div class="card">
                    <div class="card-body">
                        <div class="list-stack">
                            <?php foreach ($upcoming as $assignment) { ?>
                                <div class="list-item">
                                    <div class="list-main">
                                        <a class="list-title" href="assignment.php?id=<?php echo (int)$assignment['id']; ?>"><?php echo htmlspecialchars((string)$assignment['title']); ?></a>
                                        <div class="list-meta">
                                            <span><?php echo htmlspecialchars((string)$assignment['course_name']); ?></span>
                                            <span>
                                                <?php echo (trim((string)$assignment['due_date']) !== '' ? 'Due ' . htmlspecialchars((string)$assignment['due_date']) : 'No due date'); ?>
                                            </span>
                                            <span class="badge text-bg-<?php echo statusBadge((string)$assignment['status']); ?>"><?php echo htmlspecialchars(statusLabel((string)$assignment['status'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="list-actions">
                                        <a class="btn btn-ghost btn-sm" href="assignment.php?id=<?php echo (int)$assignment['id']; ?>">Open</a>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>

<!-- MORE_SECTIONS2 -->

        <div class="col-12 col-lg-5">
            <!-- ---------- quiz / learning progress ---------- -->
            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="section-title mt-0">Quiz performance</h2>

                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="stat-icon alt-2">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M5 19 11 5l3 7 2-4 3 11z"/></svg>
                        </span>
                        <span>
                            <span class="stat-num d-block">
                                <?php echo ($overallAvg === null ? '&ndash;' : $overallAvg . '%'); ?>
                            </span>
                            <span class="stat-label">
                                average over <?php echo $attemptCount; ?> attempt(s)
                            </span>
                        </span>
                    </div>

                    <h3 class="h6 text-muted">Recent scores</h3>
                    <?php if (count($recentScores) === 0) { ?>
                        <p class="text-muted small mb-0">
                            No quiz attempts yet. <a href="quizzes.php">Generate a quiz</a> to see your performance here.
                        </p>
                    <?php } else { ?>
                        <?php foreach ($recentScores as $score) { ?>
                            <div class="score-row">
                                <span class="text-truncate me-2"><?php echo htmlspecialchars($score['title']); ?></span>
                                <span class="text-nowrap">
                                    <strong><?php echo $score['pct']; ?>%</strong>
                                    <span class="text-muted small">(<?php echo $score['score']; ?>/<?php echo $score['total']; ?>)</span>
                                </span>
                            </div>
                        <?php } ?>
                        <p class="small mb-0 mt-2">
                            <a href="quizzes.php">Practise again &rarr;</a>
                        </p>
                    <?php } ?>
                </div>
            </div>
<!-- CLOSE_COLUMNS -->

            <!-- ---------- recent activity ---------- -->
            <div class="card">
                <div class="card-body">
                    <h2 class="section-title mt-0">Recent activity</h2>
                    <?php if (count($recent) === 0) { ?>
                        <p class="text-muted mb-0">Nothing here yet. Ask the AI, create a course or add an assignment to get started.</p>
                    <?php } else { ?>
                        <ul class="activity-list">
                            <?php foreach ($recent as $item) { ?>
                                <li class="activity-item">
                                    <span class="activity-badge"><?php echo htmlspecialchars($item['type']); ?></span>
                                    <a class="activity-link text-truncate" href="<?php echo htmlspecialchars($item['link']); ?>"><?php echo htmlspecialchars($item['label']); ?></a>
                                    <span class="activity-when"><?php echo htmlspecialchars(date('d M, H:i', strtotime((string)$item['when']))); ?></span>
                                </li>
                            <?php } ?>
                        </ul>
                        <p class="small mb-0 mt-2"><a href="history.php">Full chat history &rarr;</a></p>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ---------- AI study assistant ---------- -->
    <div class="card ai-study-card mt-4">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-2">
                <span class="stat-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="7" cy="9" r="2.2" fill="#fff"/><circle cx="12" cy="9" r="2.2" fill="#fff"/><circle cx="17" cy="9" r="2.2" fill="#fff"/><path d="M5.5 14 L9.5 17.5 L7 19 Z" fill="#fff"/></svg>
                </span>
                <div>
                    <h2 class="section-title mb-0">How can AI help you study today?</h2>
                    <p class="text-muted small mb-0">One click starts a conversation with your AI study assistant.</p>
                </div>
            </div>

            <div class="ai-chip-row">
                <a class="ai-chip" href="index.php?mode=study&amp;prompt=Explain%20this%20topic%20in%20simple%20words%3A%20">
                    Explain a Topic
                </a>
                <a class="ai-chip" href="index.php?mode=study&amp;prompt=Summarise%20this%20material%20for%20me%3A%20">
                    Summarize Material
                </a>
                <a class="ai-chip" href="quizzes.php">
                    Quiz Me
                </a>
                <a class="ai-chip" href="index.php?mode=study&amp;prompt=Give%20me%20clear%20examples%20of%3A%20">
                    Give Examples
                </a>
                <a class="ai-chip" href="index.php?mode=study&amp;prompt=Help%20me%20with%20this%20assignment%3A%20">
                    Help With Assignment
                </a>
                <a class="ai-chip" href="index.php?mode=study&amp;prompt=Help%20me%20prepare%20for%20my%20exam%20on%3A%20">
                    Exam Preparation
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
