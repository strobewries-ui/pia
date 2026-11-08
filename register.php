<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../signup.php');
    exit();
}

if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$csrfToken = $_SESSION['signup_csrf_token'] ?? '';
if ($csrfToken === '' || !isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string) $_POST['csrf_token'])) {
    header('Location: ../signup.php?error=invalid');
    exit();
}
unset($_SESSION['signup_csrf_token']);

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if (
    $fullName === ''
    || mb_strlen($fullName) > 50
    || !preg_match('/^[A-Za-z0-9._-]{3,50}$/D', $username)
    || strlen($password) < 8
    || strlen($password) > 72
) {
    header('Location: ../signup.php?error=invalid');
    exit();
}

if (!hash_equals($password, $confirmPassword)) {
    header('Location: ../signup.php?error=password-mismatch');
    exit();
}

try {
    require_once __DIR__ . '/../DB_connection.php';
    $insert = $conn->prepare(
        "INSERT INTO users (full_name, username, password, role)
         VALUES (:full_name, :username, :password, 'employee')"
    );
    $insert->execute([
        'full_name' => $fullName,
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT)
    ]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $conn->lastInsertId();
    $_SESSION['username'] = $username;
    $_SESSION['full_name'] = $fullName;
    $_SESSION['role'] = 'employee';
    unset($_SESSION['signup_csrf_token']);
    header('Location: ../index.php');
    exit();
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        header('Location: ../signup.php?error=username-taken');
        exit();
    }

    error_log('Signup database error: ' . $exception->getMessage());
    header('Location: ../signup.php?error=unavailable');
    exit();
}
