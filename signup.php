<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$csrfToken = $_SESSION['signup_csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['signup_csrf_token'] = $csrfToken;
$errors = [
    'invalid' => 'Enter a name, a username using 3–50 letters, numbers, dots, underscores or hyphens, and a password of at least 8 characters.',
    'password-mismatch' => 'The passwords do not match.',
    'username-taken' => 'That username is already in use. Please choose another.',
    'unavailable' => 'Signup is temporarily unavailable. Please try again later.'
];
$error = $errors[$_GET['error'] ?? ''] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Signup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@6.0.0-alpha.1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-B/GM4XqrwHnWXNOWMbloTmrYXZg10cakYGmpfsR/bbzQ6JAJI4ihuyADKLnBgrCe" crossorigin="anonymous">
    <link rel="stylesheet" href="css/style.css?v=20261031-signup-password-toggle">
</head>
<body class="login-body">
    <main class="login-container">
        <form method="post" action="app/register.php" class="p-4">
            <h1 class="signup-title">CREATE ACCOUNT</h1>
            <p class="signup-description">Register as an employee to access your tasks.</p>
            <?php if ($error !== null) { ?>
                <p class="login-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php } ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="mb-3">
                <label for="fullName" class="form-label">Full name</label>
                <input type="text" class="form-control" id="fullName" name="full_name" maxlength="50" autocomplete="name" required>
            </div>
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" class="form-control" id="username" name="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9._\-]{3,50}" autocomplete="username" required>
            </div>
            <div class="mb-3">
                <label for="signupPassword" class="form-label">Password</label>
                <div class="password-input-wrap">
                    <input type="password" class="form-control" id="signupPassword" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    <button type="button" class="password-toggle" data-password-toggle="signupPassword" aria-label="Show password" title="Show password" aria-controls="signupPassword" aria-pressed="false">👁</button>
                </div>
            </div>
            <div class="mb-3">
                <label for="confirmPassword" class="form-label">Confirm password</label>
                <div class="password-input-wrap">
                    <input type="password" class="form-control" id="confirmPassword" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    <button type="button" class="password-toggle" data-password-toggle="confirmPassword" aria-label="Show password" title="Show password" aria-controls="confirmPassword" aria-pressed="false">👁</button>
                </div>
            </div>
            <button type="submit" class="btn-solid theme-primary">Sign up</button>
            <p class="auth-switch">Already have an account? <a href="login.php">Log in</a></p>
        </form>
    </main>
    <script>
    document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
        const passwordInput = document.getElementById(toggle.dataset.passwordToggle);

        toggle.addEventListener('click', () => {
            const shouldShowPassword = passwordInput.type === 'password';
            passwordInput.type = shouldShowPassword ? 'text' : 'password';
            toggle.setAttribute('aria-label', shouldShowPassword ? 'Hide password' : 'Show password');
            toggle.title = shouldShowPassword ? 'Hide password' : 'Show password';
            toggle.setAttribute('aria-pressed', String(shouldShowPassword));
        });
    });
    </script>
</body>
</html>
