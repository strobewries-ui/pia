<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    header('Location: ../login.php?error=invalid');
    exit();
}

try {
    require_once __DIR__ . '/../DB_connection.php';

    $statement = $conn->prepare(
        'SELECT id, full_name, username, password, role FROM users WHERE username = :username LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    $passwordIsValid = $user && password_verify($password, $user['password']);
    if ($user && !$passwordIsValid && hash_equals($user['password'], $password)) {
        $passwordIsValid = true;
        $update = $conn->prepare('UPDATE users SET password = :password WHERE id = :id');
        $update->execute([
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $user['id']
        ]);
    }
} catch (PDOException $exception) {
    error_log('Login database error: ' . $exception->getMessage());
    header('Location: ../login.php?error=unavailable');
    exit();
}

if (!$passwordIsValid) {
    header('Location: ../login.php?error=invalid');
    exit();
}
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];

header('Location: ../index.php');
exit();
