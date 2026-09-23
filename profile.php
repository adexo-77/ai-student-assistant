<?php
// ============================================================
//  PROFILE.PHP – the logged-in student's profile: account info
//  plus a few real numbers from the existing tables.
// ============================================================

$pageTitle = 'Profile';
$navActive = 'profile';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId   = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? 'student');
$memberSince = '';
$stats    = array('courses' => 0, 'assignments' => 0, 'quizzes' => 0, 'messages' => 0);
$dbError  = '';

try {
    $pdo = getPDO();

    $stmt = $pdo->prepare('SELECT created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $memberSince = ($row === false) ? '' : (string)$row['created_at'];

    foreach (array(
        'courses'     => 'SELECT COUNT(*) AS c FROM courses WHERE user_id = ?',
        'assignments' => 'SELECT COUNT(*) AS c FROM assignments WHERE user_id = ?',
        'quizzes'     => 'SELECT COUNT(*) AS c FROM quizzes WHERE user_id = ?',
        'messages'    => 'SELECT COUNT(*) AS c FROM messages WHERE user_id = ?',
    ) as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $stats[$key] = (int)$stmt->fetch()['c'];
    }
} catch (PDOException $e) {
    $dbError = friendlyError('profile', $e);
}

include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrap">

    <div class="page-heading">
        <div>
            <h1 class="page-title">My <span class="grad-text">profile</span></h1>
            <p class="page-subtitle mb-0">Your account and study summary.</p>
        </div>
    </div>

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } ?>

    <div class="row g-4">
        <div class="col-12 col-md-5">
            <div class="card h-100">
                <div class="card-body text-center">
                    <span class="topbar-avatar mx-auto mb-3" style="width:64px;height:64px;font-size:1.5rem;">
                        <?php echo htmlspecialchars(mb_strtoupper(mb_substr($username, 0, 1))); ?>
                    </span>
                    <h2 class="h5 mb-1"><?php echo htmlspecialchars($username); ?></h2>
                    <p class="text-muted small mb-3">
                        <?php echo ($memberSince !== '' ? 'Member since ' . htmlspecialchars(date('d M Y', strtotime($memberSince))) : 'Member'); ?>
                    </p>
                    <div class="d-flex justify-content-center gap-2">
                        <a class="btn btn-primary btn-sm" href="index.php">Ask AI</a>
                        <a class="btn btn-ghost btn-sm" href="logout.php">Logout</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-7">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="section-title mt-0">Study summary</h2>
                    <div class="row g-3">
                        <?php foreach (array(
                            'courses'     => 'Courses',
                            'assignments' => 'Assignments',
                            'quizzes'     => 'Quizzes created',
                            'messages'    => 'Chat messages',
                        ) as $key => $label) { ?>
                            <div class="col-6">
                                <div class="stat-card">
                                    <span class="stat-icon">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.7" d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg>
                                    </span>
                                    <span>
                                        <span class="stat-num d-block"><?php echo $stats[$key]; ?></span>
                                        <span class="stat-label"><?php echo htmlspecialchars($label); ?></span>
                                    </span>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
