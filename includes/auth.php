<?php
// ============================================================
//  AUTH.PHP – session / login helpers used by every page
// ============================================================

// Start the session once (keeps the user logged in across pages).
// "session" is PHP's built-in tool for remembering who is logged in.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Returns true if someone is logged in.
function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

// Redirects visitors to the login page if they are not logged in.
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}