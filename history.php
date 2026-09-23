<?php
// ============================================================
//  HISTORY.PHP – shows all saved questions and answers
//  (only the ones belonging to the logged-in user)
// ============================================================

$pageTitle = 'History';
$navActive = 'history';

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$messages = [];
$dbError  = '';

try {
    require_once __DIR__ . '/db.php';
    $pdo = getPDO();

    // Newest conversations first, at most 200.
    $stmt = $pdo->prepare(
        'SELECT question, answer, created_at
           FROM messages
          WHERE user_id = ?
          ORDER BY created_at DESC
          LIMIT 200'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $messages = $stmt->fetchAll();
} catch (PDOException $e) {
    $dbError = $e->getMessage()
             . '<br>Did you import database.sql yet? See README.md.';
}

// ------------------------------------------------------------
// Formats an AI answer for the history page. We escape ALL HTML
// FIRST (that keeps it safe), then turn common markdown into
// simple styled HTML: **bold**, `code`, headers and bullet lists.
//
// The function_exists() guard is the same one used in
// includes/study.php: the Student Assistant pages share this
// formatter, so whichever file is loaded first wins and a page
// that includes both can never crash with a "redeclare" error.
// ------------------------------------------------------------
if (!function_exists('formatAiText')) {
function formatAiText($text) {
    $t = htmlspecialchars((string)$text, ENT_QUOTES);

    // normalise line endings (needle, replacement, subject)
    $t = str_replace("\r\n", "\n", $t);
    $t = str_replace("\r", "\n", $t);

    // headers
    $t = preg_replace('/^### (.*)$/m', '<h5 class="ai-h">$1</h5>', $t);
    $t = preg_replace('/^## (.*)$/m', '<h4 class="ai-h">$1</h4>', $t);
    $t = preg_replace('/^# (.*)$/m', '<h4 class="ai-h">$1</h4>', $t);

    // bold and inline code (escaped first, so this is safe)
    $t = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $t);
    $t = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $t);

    // bullet list items ("- item" or "* item")
    $t = preg_replace('/^[*-] (.+)$/m', '&bull; $1', $t);

    // final line breaks
    return nl2br($t);
}
}

include __DIR__ . '/includes/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-7">

            <!-- page heading -->
            <div class="page-heading">
                <div>
                    <h1 class="page-title">Conversation <span class="grad-text">history</span></h1>
                    <p class="page-subtitle mb-0">Every question and answer you asked, newest first.</p>
                </div>
                <a class="btn btn-ghost" href="index.php">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 4.5 v15 M4.5 12 h15" fill="none" stroke="currentColor" stroke-width="2.3"/>
                    </svg>
                    <span>New question</span>
                </a>
            </div>

            <?php if ($dbError !== '') { ?>
                <div class="alert alert-danger"><?php echo $dbError; ?></div>
            <?php } elseif (count($messages) === 0) { ?>
                <div class="history-card history-empty">
                    <p class="mb-0">
                        No conversations yet. Ask something on the
                        <a href="index.php">chat page</a>!
                    </p>
                </div>
            <?php } ?>

            <?php
            // htmlspecialchars() makes sure the text from the
            // database is shown as plain text, never as HTML.
            foreach ($messages as $msg) { ?>
                <div class="history-card">
                    <div class="history-head">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="4" y="4" width="16" height="16" rx="3" fill="none" stroke="currentColor" stroke-width="2"/>
                            <path d="M8 11 h8 M8 14.5 h5" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <span><?php echo htmlspecialchars((string)$msg['created_at']); ?></span>
                    </div>

                    <div class="history-q">
                        <span class="history-tag">Question</span>
                        <p class="history-text"><?php echo htmlspecialchars((string)$msg['question']); ?></p>
                    </div>

                    <div class="history-a">
                        <span class="history-tag">Answer</span>
                        <div class="history-text"><?php echo formatAiText((string)($msg['answer'] ?? '')); ?></div>
                    </div>
                </div>
            <?php } ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>