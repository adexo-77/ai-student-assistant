<?php
// ============================================================
//  INDEX.PHP – the chat page
//  The messages you see here are added by js/chat.js.
//  Every question you send (and every file you attach) is
//  answered by Gemini and saved in MySQL by api/chat.php.
// ============================================================

$pageTitle = 'Chat';
$navActive = 'chat';

require_once __DIR__ . '/includes/auth.php';
requireLogin();   // visitors who are not logged in go to login.php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/study.php';

// Optional course context: index.php?course_id=7 tells the AI which
// course the student is asking about. Only the student's OWN courses
// are accepted – anything else is ignored with a short note.
$courseId    = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$course      = false;
$courseError = '';

if ($courseId > 0) {
    try {
        $course = findUserCourse(getPDO(), $courseId, (int)$_SESSION['user_id']);
        if ($course === false) {
            $courseError = 'That course does not exist, so the normal chat is shown.';
            $courseId    = 0;
        }
    } catch (PDOException $e) {
        $courseError = friendlyError('chat course context', $e);
        $courseId    = 0;
    }
}

// Optional prefill from the dashboard AI quick actions:
// index.php?mode=study&prompt=Explain%20this%20topic%3A%20...
// The mode must be one of the EXISTING assistant modes and the
// prompt only pre-fills the input box (nothing is sent yet).
$prefillMode = '';
$prefillText = '';
if (isset($_GET['mode']) && is_string($_GET['mode']) && isset(assistantModes()[$_GET['mode']])) {
    $prefillMode = (string)$_GET['mode'];
}
if (isset($_GET['prompt']) && is_string($_GET['prompt'])) {
    $prefillText = trim($_GET['prompt']);
    if (mb_strlen($prefillText) > 200) {
        $prefillText = mb_substr($prefillText, 0, 200);
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-7">

                        <!-- page heading -->
            <div class="page-hero">
                <h1 class="page-title">Ask <span class="grad-text">anything</span></h1>
                <p class="page-subtitle">Powered by Gemini &middot; attach a file, pick a mode, and ask away.</p>
            </div>

            <?php if ($courseError !== '') { ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($courseError); ?></div>
            <?php } ?>

            <?php if ($course !== false) { ?>
                <!-- course context: the AI is told which course this is -->
                <div class="course-context">
                    <span class="course-context-tag">Course chat</span>
                    <span class="flex-grow-1">
                        Asking about <strong><?php echo htmlspecialchars($course['name']); ?></strong>
                        <?php if ($course['code'] !== '') { ?>
                            <span class="course-code"><?php echo htmlspecialchars($course['code']); ?></span>
                        <?php } ?>
                    </span>
                    <a class="btn btn-ghost btn-sm" href="course.php?id=<?php echo (int)$course['id']; ?>">Open course</a>
                    <a class="btn btn-ghost btn-sm" href="index.php">Leave</a>
                </div>
            <?php } ?>

            <!-- chat card -->
            <div class="card chat-card">

                <div class="card-header chat-header d-flex align-items-center gap-3">
                    <div class="avatar avatar-bot">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="7" cy="9" r="2.2" fill="#fff"/>
                            <circle cx="12" cy="9" r="2.2" fill="#fff"/>
                            <circle cx="17" cy="9" r="2.2" fill="#fff"/>
                            <path d="M5.5 14 L9.5 17.5 L7 19 Z" fill="#fff"/>
                        </svg>
                    </div>
                    <div class="flex-grow-1">
                        <div class="chat-title">AI Assistant</div>
                        <div class="chat-status"><span class="status-dot"></span>Online &middot; replies with Gemini</div>
                    </div>
                    <button type="button" class="btn btn-ghost" id="regenerateBtn" title="Ask the same question again" disabled>
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/>
                            <path d="M14.5 5.5 L19 5.5 L17.2 2.2 Z" fill="currentColor"/>
                        </svg>
                        <span>Regenerate</span>
                    </button>
                    <button type="button" class="btn btn-ghost" id="newChatBtn" title="Clear this chat (history is kept)">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 4.5 v15 M4.5 12 h15" fill="none" stroke="currentColor" stroke-width="2.3"/>
                        </svg>
                        <span>New Chat</span>
                    </button>
                </div>

                <!-- The chat area. js/chat.js adds message bubbles here. -->
                <div class="card-body chat-box" id="chatBox"
                     data-user="<?php echo htmlspecialchars((string)($_SESSION['username'] ?? ''), ENT_QUOTES); ?>"
                     data-course-id="<?php echo (int)$courseId; ?>"
                     data-prefill="<?php echo htmlspecialchars($prefillText, ENT_QUOTES); ?>"
                     data-mode="<?php echo htmlspecialchars($prefillMode, ENT_QUOTES); ?>"></div>

                <div class="card-footer chat-footer">

                    <!-- attached file chip (hidden until a file is chosen) -->
                    <div class="d-none" id="fileChip">
                        <div class="file-chip">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="6" y="4" width="12" height="16" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/>
                                <path d="M8.5 8.5 h7 M8.5 11.5 h7 M8.5 14.5 h4.5" stroke="currentColor" stroke-width="1.6"/>
                            </svg>
                            <span id="fileChipName" class="file-chip-name"></span>
                            <button type="button" class="chip-remove" id="removeFileBtn" title="Remove file">&times;</button>
                        </div>
                    </div>

                    <!-- assistant mode selector -->
                    <div class="mode-row d-flex align-items-center gap-2 mt-2">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor" d="M12 2.5 L14.5 5 17 7.5 M12 21.5 L9.5 19 7 16.5 M5 8 L8 11 11 14 M19 8 L16 11 13 14 M8 14 L10 16 12 18 M16 14 L14 16 12 18 Z"/>
                        </svg>
                        <span class="mode-label">Mode</span>
                        <select id="modeSelect" class="form-select mode-select" title="Choose an assistant mode">
                            <?php foreach (assistantModes() as $modeValue => $modeLabel) {
                                $isSelected = ($course !== false)
                                            ? ($modeValue === 'course')
                                            : ($prefillMode !== ''
                                                ? ($modeValue === $prefillMode)
                                                : ($modeValue === 'general'));
                                ?>
                                <option value="<?php echo htmlspecialchars($modeValue, ENT_QUOTES); ?>"<?php echo ($isSelected ? ' selected' : ''); ?>><?php echo htmlspecialchars($modeLabel); ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <!-- question + attachment + send -->
                    <form id="chatForm" class="d-flex align-items-stretch gap-2 mt-2">
                        <input type="file" id="fileInput" class="d-none"
                               accept=".pdf,.txt,.docx,.png,.jpg,.jpeg,.gif,.webp">
                        <button type="button" class="btn attach-btn" id="attachBtn" title="Attach a PDF, TXT, DOCX or image (max 10 MB)">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <rect x="6" y="4" width="12" height="16" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.8"/>
                                <path d="M8.5 8.5 h7 M8.5 11.5 h7 M8.5 14.5 h4.5" stroke="currentColor" stroke-width="1.6"/>
                            </svg>
                        </button>
                        <textarea id="question" class="form-control chat-input" rows="1"
                                  placeholder="Type your question..." autocomplete="off" autofocus></textarea>
                        <button type="submit" class="btn send-btn" id="sendBtn" title="Send">
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path fill="#fff" d="M21.5 4.5 L12.4 9.1 L8 12.3 L9.4 4.5 L10.7 5.3 L17.2 14.2 L20.5 9 L21 12.6 L17.9 17.5 L13.2 18.9 L6.5 18.5 L7.1 12.5 L5.6 17.6 L5.5 13.6 Z"/>
                            </svg>
                        </button>
                    </form>
                    <div class="input-hint mt-2">
                        <kbd class="hint-kbd">Enter</kbd> send &middot;
                        <kbd class="hint-kbd">Shift + Enter</kbd> new line &middot;
                        attach up to 10 MB &middot;
                        <a href="history.php">History</a> keeps every Q&amp;A
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<script src="js/chat.js?v=<?php echo (int)filemtime(__DIR__ . '/js/chat.js'); ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>