<?php
// ============================================================
//  LOGIN.PHP – login page (simple username + password)
// ============================================================

$pageTitle  = 'Login';
$hideNavbar = true;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in? Go straight to the dashboard.
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

// The login form was submitted (method="post").
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim((string)$_POST['username']);
    $password = (string)$_POST['password'];

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        try {
            require_once __DIR__ . '/db.php';
            $pdo = getPDO();

            // 1) Find the user.
            //    "?" is a placeholder – PDO fills it in safely,
            //    so the question can never break the SQL.
            $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            // 2) Check the password against the stored hash.
            if ($user === false || !password_verify($password, $user['password'])) {
                $error = 'Wrong username or password.';
            } else {
                // 3) Log the user in, remember them in the session
                //    and open the dashboard (the app's home page).
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $username;
                header('Location: dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Database problem: ' . $e->getMessage()
                   . '<br>Did you import database.sql yet? See README.md.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="login-page">
    <div class="login-form-card">
        <div class="login-form-title">
            <svg viewBox="0 0 24 24" aria-hidden="true" style="width:22px;height:22px;"><circle cx="7" cy="9" r="2.2" fill="#7b5bf5"/><circle cx="12" cy="9" r="2.2" fill="#4d8bff"/><circle cx="17" cy="9" r="2.2" fill="#7b5bf5"/><path d="M5.5 14 L9.5 17.5 L7 19 Z" fill="#4d8bff"/></svg>
            <?php echo htmlspecialchars(BOT_NAME); ?> – Login
        </div>

        <?php if ($error !== '') { ?>
            <div class="alert alert-danger login-alert"><?php echo $error; ?></div>
        <?php } ?>

        <?php if (isset($_GET['registered'])) { ?>
            <div class="alert alert-success login-alert">Account created. You can now log in!</div>
        <?php } ?>

        <form method="post" action="login.php">
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Log in</button>
        </form>

        <hr>
        <p class="text-center mb-0">No account yet? <a href="register.php">Create one</a></p>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>