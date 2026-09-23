<?php
// ============================================================
//  SECURITY.PHP – small helpers shared by the Student Assistant
//  pages (CSRF tokens + one-time "flash" messages).
//
//  It does NOT replace includes/auth.php – include that one too
//  (it starts the session and has requireLogin()).
// ============================================================

require_once __DIR__ . '/auth.php';

/**
 * Returns the CSRF token for this session, creating it once.
 * A CSRF token proves that a form was really posted from our own
 * site, so another website cannot make the user delete a course.
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden input to paste inside every <form> that changes data.
 * Usage:   <?php echo csrfField(); ?>
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

/**
 * Checks the token of a POST request. If it is wrong or missing we
 * stop right there – no data is changed.
 * Usage:   csrfCheck();
 */
function csrfCheck() {
    $sent = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($sent === '' || !hash_equals(csrfToken(), $sent)) {
        http_response_code(400);
        exit('This form has expired. Please go back, reload the page and try again.');
    }
}

// ------------------------------------------------------------
//  Flash messages: a short note ("Course created.") that is shown
//  once on the NEXT page. Perfect after a redirect (Post/Redirect/Get).
// ------------------------------------------------------------

/** Stores a message for the next page. $type is 'success' or 'danger'. */
function flashSet($type, $message) {
    $_SESSION['flash'][] = array('type' => $type, 'text' => $message);
}

/** Returns all pending messages and clears them. */
function flashTake() {
    $messages = isset($_SESSION['flash']) ? $_SESSION['flash'] : array();
    unset($_SESSION['flash']);
    return $messages;
}

/** Prints pending messages as Bootstrap alerts (text is escaped). */
function flashRender() {
    foreach (flashTake() as $flash) {
        $type = ($flash['type'] === 'success') ? 'success' : 'danger';
        echo '<div class="alert alert-' . $type . '">'
           . htmlspecialchars((string)$flash['text']) . '</div>';
    }
}

/**
 * Logs a technical error for the developer (see apache/logs/error.log)
 * and gives the user a friendly message. We never show SQL/credentials.
 */
function friendlyError($context, $e) {
    error_log('[AI Student Assistant] ' . $context . ': ' . $e->getMessage());
    return 'Something went wrong. Please try again.';
}