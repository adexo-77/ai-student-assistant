<?php
// ============================================================
//  REGISTER.PHP – create a new account
// ============================================================

$pageTitle  = 'Create account';
$hideNavbar = true;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Go straight to the chat page.
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

// The form was submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim((string)$_POST['username']);
    $password = (string)$_POST['password'];
    $confirm  = (string)$_POST['confirm'];

    if (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            require_once __DIR__ . '/db.php';
            $pdo = getPDO();

            // Hash the password BEFORE storing it (never store
            // plain-text passwords!). We can only verify it later,
            // we can never read it back – that is exactly what we want.
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
            $stmt->execute([$username, $hash]);

            // Account created – go to the login page with a success note.
            header('Location: login.php?registered=1');
            exit;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                $error = 'That username is already taken. Please choose another one.';
            } else {
                $error = 'Database problem: ' . $e->getMessage()
                       . '<br>Did you import database.sql yet? See README.md.';
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="login-page">
    <div class="login-form-card">
        <div class="login-form-title">
            <svg viewBox="0 0 24 24" aria-hidden="true" style="width:22px;height:22px;"><circle cx="7" cy="9" r="2.2" fill="#7b5bf5"/><circle cx="12" cy="9" r="2.2" fill="#4d8bff"/><circle cx="17" cy="9" r="2.2" fill="#7b5bf5"/><path d="M5.5 14 L9.5 17.5 L7 19 Z" fill="#4d8bff"/></svg>
            Create an account
        </div>

        <?php if ($error !== '') { ?>
            <div class="alert alert-danger login-alert"><?php echo $error; ?></div>
        <?php } ?>

        <form method="post" action="register.php">
            <div class="mb-3">
                <label for="username" class="form-label">Username (min. 3 characters)</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password (min. 6 characters)</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <div class="mb-3">
                <label for="confirm" class="form-label">Repeat password</label>
                <input type="password" class="form-control" id="confirm" name="confirm" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Create account</button>
        </form>

        <hr>
        <p class="text-center mb-0">Already have an account? <a href="login.php">Log in</a></p>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>