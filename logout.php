<?php
// ============================================================
//  LOGOUT.PHP – ends the session and returns to the login page
// ============================================================

require_once __DIR__ . '/includes/auth.php';

$_SESSION = [];            // forget everything stored in the session
session_destroy();         // remove the session from the server

header('Location: login.php');
exit;