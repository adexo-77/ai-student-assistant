<?php
// ============================================================
//  MATERIALS.PHP – all study materials of the logged-in student.
//  Shows each file's RAG index status (Ready / Processing / Failed)
//  and lets the owner delete a material (its chunks go too).
// ============================================================

$pageTitle = 'Study Materials';
$navActive = 'materials';

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

$userId  = (int)$_SESSION['user_id'];
$grouped = array();   // course_id => array('course' => ..., 'materials' => [...])
$total   = 0;
$dbError = '';

// ---- delete one material (POST + CSRF; chunks vanish via FK cascade) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_material'])) {
    csrfCheck();
    try {
        $pdoDel = getPDO();
        if (deleteUserMaterial($pdoDel, $userId, (int)$_POST['delete_material'])) {
            flashSet('success', 'Material deleted.');
        } else {
            flashSet('danger', 'That material does not exist.');
        }
    } catch (PDOException $e) {
        flashSet('danger', friendlyError('materials-delete', $e));
    }
    header('Location: materials.php');
    exit;
}

try {
    $pdo = getPDO();

    foreach (listUserCourses($pdo, $userId) as $course) {
        $materials = listCourseMaterials($pdo, $userId, $course['id']);
        if (count($materials) > 0) {
            $grouped[(int)$course['id']] = array('course' => $course, 'materials' => $materials);
            $total += count($materials);
        }
    }
} catch (PDOException $e) {
    $dbError = friendlyError('materials', $e);
}

include __DIR__ . '/includes/header.php';
?>

<div class="container page-wrap">

    <?php flashRender(); ?>

    <div class="page-heading">
        <div>
            <h1 class="page-title">Study <span class="grad-text">materials</span></h1>
            <p class="page-subtitle mb-0">
                Files you studied with the AI (<?php echo $total; ?> total).
                Upload new ones from the <a href="index.php">AI Assistant</a> with a course selected.
            </p>
        </div>
        <a class="btn btn-primary" href="index.php">Upload material</a>
    </div>

    <?php if ($dbError !== '') { ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($dbError); ?></div>
    <?php } ?>

    <?php if (count($grouped) === 0) { ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="mb-2">No study materials yet.</p>
                <p class="text-muted small">
                    Open the <a href="index.php">AI Assistant</a>, pick one of your courses in the mode
                    selector and attach a PDF, TXT, DOCX or image (max 10 MB). It is sent straight to
                    Gemini and its metadata is remembered here.
                </p>
            </div>
        </div>
    <?php } else { ?>
        <?php foreach ($grouped as $group) { ?>
            <h2 class="section-title">
                <a class="text-decoration-none" href="course.php?id=<?php echo (int)$group['course']['id']; ?>">
                    <?php echo htmlspecialchars((string)$group['course']['name']); ?>
                </a>
                <?php if ($group['course']['code'] !== '') { ?>
                    <span class="course-code"><?php echo htmlspecialchars((string)$group['course']['code']); ?></span>
                <?php } ?>
            </h2>

            <div class="card mb-4">
                <div class="card-body">
                    <div class="list-stack">
                        <?php foreach ($group['materials'] as $material) { ?>
                            <div class="list-item">
                                <div class="list-main">
                                    <span class="list-title"><?php echo htmlspecialchars((string)$material['file_name']); ?></span>
                                    <div class="list-meta">
                                        <span><?php echo htmlspecialchars(formatFileSize($material['file_size'])); ?></span>
                                        <?php if (trim((string)$material['note']) !== '') { ?>
                                            <span><?php echo htmlspecialchars((string)$material['note']); ?></span>
                                        <?php } ?>
                                        <span><?php echo htmlspecialchars(date('d M Y, H:i', strtotime((string)$material['created_at']))); ?></span>
                                    </div>
                                </div>
                                <div class="list-actions">
                                    <span class="badge text-bg-<?php echo materialStatusBadge(isset($material['index_status']) ? (string)$material['index_status'] : 'pending'); ?>"><?php echo htmlspecialchars(materialStatusLabel(isset($material['index_status']) ? (string)$material['index_status'] : 'pending')); ?></span>
                                    <a class="btn btn-ghost btn-sm" href="index.php?course_id=<?php echo (int)$group['course']['id']; ?>">Continue studying</a>
                                    <form method="post" action="materials.php" style="display:inline" onsubmit="return confirm('Delete this material and its searchable chunks?');">
                                        <?php echo csrfField(); ?>
                                        <button class="btn btn-ghost btn-sm" type="submit" name="delete_material" value="<?php echo (int)$material['id']; ?>">Delete</button>
                                    </form>
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
