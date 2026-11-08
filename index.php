<?php
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name'])) {
    header('Location: login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$displayName = $_SESSION['full_name'];
$pages = $role === 'admin'
    ? ['dashboard', 'manage-users', 'create-task', 'all-tasks', 'notifications', 'profile']
    : ['dashboard', 'profile', 'notifications', 'all-tasks'];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}
$pageTitles = [
    'dashboard' => 'Dashboard',
    'manage-users' => 'Manage Users',
    'create-task' => 'Create Task',
    'all-tasks' => 'All Tasks',
    'profile' => 'Profile',
    'notifications' => 'Notifications'
];
$escape = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

try {
    require_once __DIR__ . '/DB_connection.php';
} catch (PDOException $exception) {
    error_log('Task dashboard database connection error: ' . $exception->getMessage());
    http_response_code(500);
    exit('The task management system is temporarily unavailable. Please try again later.');
}

$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrfToken;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notice = 'save-failed';

    if (!isset($_POST['csrf_token']) || !hash_equals($csrfToken, (string) $_POST['csrf_token'])) {
        $notice = 'invalid-request';
    } elseif (($_POST['action'] ?? '') === 'create_task' && $role === 'admin') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $assignedTo = filter_var($_POST['assigned_to'] ?? null, FILTER_VALIDATE_INT);
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        $parsedDueDate = $dueDate === '' ? null : DateTime::createFromFormat('!Y-m-d', $dueDate);
        $validDueDate = $dueDate === '' || (
            $parsedDueDate !== false && $parsedDueDate->format('Y-m-d') === $dueDate
        );

        if ($title === '' || mb_strlen($title) > 150 || $description === '' || !$assignedTo || !$validDueDate) {
            $notice = 'invalid-task';
        } else {
            try {
                $employeeQuery = $conn->prepare("SELECT id FROM users WHERE id = :id AND role = 'employee'");
                $employeeQuery->execute(['id' => $assignedTo]);
                if ($employeeQuery->fetchColumn()) {
                    $conn->beginTransaction();
                    $insert = $conn->prepare(
                        "INSERT INTO tasks (title, description, assigned_to, created_by, due_date)
                         VALUES (:title, :description, :assigned_to, :created_by, :due_date)"
                    );
                    $insert->execute([
                        'title' => $title,
                        'description' => $description,
                        'assigned_to' => $assignedTo,
                        'created_by' => $userId,
                        'due_date' => $dueDate === '' ? null : $dueDate
                    ]);
                    $notification = $conn->prepare(
                        "INSERT INTO notifications (user_id, actor_user_id, task_id, notification_type, message)
                         VALUES (:user_id, :actor_user_id, :task_id, 'task_assigned', :message)"
                    );
                    $notification->execute([
                        'user_id' => $assignedTo,
                        'actor_user_id' => $userId,
                        'task_id' => $conn->lastInsertId(),
                        'message' => 'New task assigned: ' . $title
                    ]);
                    $conn->commit();
                    $notice = 'task-created';
                } else {
                    $notice = 'invalid-task';
                }
            } catch (PDOException $exception) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('Task creation database error: ' . $exception->getMessage());
            }
        }
    } elseif (($_POST['action'] ?? '') === 'update_profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));

        if ($fullName === '' || mb_strlen($fullName) > 50 || $username === '' || mb_strlen($username) > 50) {
            $notice = 'invalid-profile';
        } else {
            try {
                $duplicateCheck = $conn->prepare(
                    'SELECT id FROM users WHERE username = :username AND id <> :user_id LIMIT 1'
                );
                $duplicateCheck->execute(['username' => $username, 'user_id' => $userId]);

                if ($duplicateCheck->fetchColumn()) {
                    $notice = 'username-taken';
                } else {
                    $updateProfile = $conn->prepare(
                        'UPDATE users SET full_name = :full_name, username = :username WHERE id = :user_id'
                    );
                    $updateProfile->execute([
                        'full_name' => $fullName,
                        'username' => $username,
                        'user_id' => $userId
                    ]);
                    $_SESSION['full_name'] = $fullName;
                    $_SESSION['username'] = $username;
                    $notice = 'profile-updated';
                }
            } catch (PDOException $exception) {
                error_log('Profile update database error: ' . $exception->getMessage());
                $notice = 'profile-save-failed';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'mark_notification_read') {
        $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId) {
            $notice = 'invalid-notification';
        } else {
            try {
                $markRead = $conn->prepare(
                    'UPDATE notifications SET read_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND user_id = :user_id AND read_at IS NULL'
                );
                $markRead->execute(['id' => $notificationId, 'user_id' => $userId]);
                $notice = 'notification-updated';
            } catch (PDOException $exception) {
                error_log('Notification update database error: ' . $exception->getMessage());
            }
        }
    } elseif (($_POST['action'] ?? '') === 'mark_all_notifications_read') {
        try {
            $markAllRead = $conn->prepare(
                'UPDATE notifications SET read_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id AND read_at IS NULL'
            );
            $markAllRead->execute(['user_id' => $userId]);
            $notice = 'notification-updated';
        } catch (PDOException $exception) {
            error_log('Notification update database error: ' . $exception->getMessage());
        }
    } elseif (($_POST['action'] ?? '') === 'update_status') {
        $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = (string) ($_POST['status'] ?? '');
        $allowedStatuses = ['pending', 'in_progress', 'completed'];

        if (!$taskId || !in_array($newStatus, $allowedStatuses, true)) {
            $notice = 'invalid-task';
        } else {
            try {
                $taskQuery = $conn->prepare(
                    'SELECT id, assigned_to, created_by, title, status FROM tasks
                     WHERE id = :id AND (:is_admin = 1 OR assigned_to = :user_id)'
                );
                $taskQuery->execute([
                    'id' => $taskId,
                    'is_admin' => $role === 'admin' ? 1 : 0,
                    'user_id' => $userId
                ]);
                $task = $taskQuery->fetch(PDO::FETCH_ASSOC);
                if ($task) {
                    $conn->beginTransaction();
                    $update = $conn->prepare('UPDATE tasks SET status = :status WHERE id = :id');
                    $update->execute(['status' => $newStatus, 'id' => $taskId]);
                    $recipientId = $role === 'admin' ? $task['assigned_to'] : $task['created_by'];
                    if ($task['status'] !== $newStatus && $recipientId && (int) $recipientId !== $userId) {
                        $notification = $conn->prepare(
                            'INSERT INTO notifications (user_id, actor_user_id, task_id, notification_type, message)
                             VALUES (:user_id, :actor_user_id, :task_id, \'status_updated\', :message)'
                        );
                        $notification->execute([
                            'user_id' => $recipientId,
                            'actor_user_id' => $userId,
                            'task_id' => $taskId,
                            'message' => 'Task status updated: ' . $task['title'] . ' is now '
                                . str_replace('_', ' ', $newStatus) . '.'
                        ]);
                    }
                    $conn->commit();
                    $notice = 'status-updated';
                } else {
                    $notice = 'invalid-task';
                }
            } catch (PDOException $exception) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log('Task status database error: ' . $exception->getMessage());
            }
        }
    } else {
        $notice = 'invalid-request';
    }

    $action = (string) ($_POST['action'] ?? '');
    $returnPage = $action === 'create_task'
        ? 'create-task'
        : (in_array($action, ['mark_notification_read', 'mark_all_notifications_read'], true)
            ? 'notifications'
            : ($action === 'update_profile' ? 'profile' : 'all-tasks'));
    $returnEdit = $action === 'update_profile' && $notice !== 'profile-updated' ? '&edit=1' : '';
    if ($notice === 'profile-updated') {
        $returnPage = 'profile';
    }
    header('Location: index.php?page=' . rawurlencode($returnPage) . $returnEdit . '&notice=' . rawurlencode($notice));
    exit();
}

$notices = [
    'task-created' => ['Task assigned successfully.', 'success'],
    'status-updated' => ['Task status updated.', 'success'],
    'invalid-task' => ['Please check the task details and try again.', 'error'],
    'invalid-request' => ['Your request could not be verified. Please try again.', 'error'],
    'invalid-profile' => ['Enter a name and username, each no longer than 50 characters.', 'error'],
    'username-taken' => ['That username is already in use. Please choose another.', 'error'],
    'profile-save-failed' => ['Your profile could not be saved. Please try again.', 'error'],
    'profile-updated' => ['Your profile has been updated.', 'success'],
    'notification-updated' => ['Notifications updated.', 'success'],
    'invalid-notification' => ['That notification could not be found.', 'error'],
    'save-failed' => ['The task could not be saved. Please try again.', 'error']
];
$notice = $notices[$_GET['notice'] ?? ''] ?? null;

try {
    $unreadCountQuery = $conn->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
    );
    $unreadCountQuery->execute(['user_id' => $userId]);
    $unreadNotificationCount = (int) $unreadCountQuery->fetchColumn();

    $notifications = [];
    if ($page === 'notifications') {
        $notificationQuery = $conn->prepare(
            'SELECT notifications.id, notifications.task_id, notifications.notification_type,
                    notifications.message, notifications.created_at, notifications.read_at,
                    tasks.status AS task_status
             FROM notifications
             LEFT JOIN tasks ON tasks.id = notifications.task_id
             WHERE notifications.user_id = :user_id
             ORDER BY notifications.created_at DESC, notifications.id DESC'
        );
        $notificationQuery->execute(['user_id' => $userId]);
        $notifications = $notificationQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($role === 'admin') {
        $employees = $conn->query(
            "SELECT id, full_name FROM users WHERE role = 'employee' ORDER BY full_name"
        )->fetchAll(PDO::FETCH_ASSOC);
        $users = $conn->query(
            'SELECT id, full_name, username, role, created_at FROM users ORDER BY role, full_name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $taskQuery = $conn->query(
            'SELECT tasks.id, tasks.title, tasks.description, tasks.status, tasks.due_date,
                    tasks.created_at, users.full_name AS employee_name
             FROM tasks
             LEFT JOIN users ON users.id = tasks.assigned_to
             ORDER BY CASE WHEN tasks.status = \'completed\' THEN 1 ELSE 0 END,
                      tasks.due_date IS NULL, tasks.due_date, tasks.created_at DESC'
        );
    } else {
        $employees = [];
        $users = [];
        $taskQuery = $conn->prepare(
            'SELECT id, title, description, status, due_date, created_at
             FROM tasks WHERE assigned_to = :user_id
             ORDER BY CASE WHEN status = \'completed\' THEN 1 ELSE 0 END,
                      due_date IS NULL, due_date, created_at DESC'
        );
        $taskQuery->execute(['user_id' => $userId]);
    }
    $tasks = $taskQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Task dashboard query error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Tasks could not be loaded. Please check the database setup and try again.');
}

$counts = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
foreach ($tasks as $task) {
    if (isset($counts[$task['status']])) {
        $counts[$task['status']]++;
    }
}
$totalTasks = count($tasks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="css/style.css?v=20261031-sidebar-content-gap">
</head>
<body class="app-body">
    <input type="checkbox" id="checkbox">
    <header class="header">
        <h2 class="u-name">TASK <b>MANAGEMENT</b>
            <label for="checkbox">
                <span id="navbtn" class="icon" aria-label="Toggle navigation" role="button" tabindex="0">☰</span>
            </label>
        </h2>
        <a class="header-notifications" href="index.php?page=notifications" aria-label="Notifications<?php echo $unreadNotificationCount > 0 ? ', ' . $unreadNotificationCount . ' unread' : ''; ?>">
            <i class="fa fa-bell header-icon" aria-hidden="true"></i>
            <?php if ($unreadNotificationCount > 0) { ?>
                <span class="notification-count"><?php echo $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount; ?></span>
            <?php } ?>
        </a>
    </header>

    <div class="body">
        <nav class="side-bar" aria-label="Main navigation">
            <div class="user-p">
                <img src="img/user.webp" alt="">
                <h4><?php echo $escape($displayName); ?></h4>
            </div>
            <ul>
                <li><a class="<?php echo $page === 'dashboard' ? 'active' : ''; ?>" href="index.php?page=dashboard"><span class="icon" aria-hidden="true">◴</span><span class="nav-label">Dashboard</span></a></li>
                <?php if ($role === 'employee') { ?>
                    <li><a class="<?php echo $page === 'profile' ? 'active' : ''; ?>" href="index.php?page=profile"><span class="icon profile-icon" aria-hidden="true">👤</span><span class="nav-label">Profile</span></a></li>
                    <li><a class="<?php echo $page === 'notifications' ? 'active' : ''; ?>" href="index.php?page=notifications"><i class="fa fa-bell icon" aria-hidden="true"></i><span class="nav-label">Notifications</span></a></li>
                    <li><a class="<?php echo $page === 'all-tasks' ? 'active' : ''; ?>" href="index.php?page=all-tasks"><span class="icon tasks-icon" aria-hidden="true">☷</span><span class="nav-label">All Tasks</span></a></li>
                <?php } else { ?>
                    <li><a class="<?php echo $page === 'manage-users' ? 'active' : ''; ?>" href="index.php?page=manage-users"><span class="icon" aria-hidden="true">👥</span><span class="nav-label">Manage Users</span></a></li>
                    <li><a class="<?php echo $page === 'create-task' ? 'active' : ''; ?>" href="index.php?page=create-task"><span class="icon plus-icon" aria-hidden="true">＋</span><span class="nav-label">Create Task</span></a></li>
                    <li><a class="<?php echo $page === 'all-tasks' ? 'active' : ''; ?>" href="index.php?page=all-tasks"><span class="icon" aria-hidden="true">✉</span><span class="nav-label">All Tasks</span></a></li>
                    <li><a class="<?php echo $page === 'notifications' ? 'active' : ''; ?>" href="index.php?page=notifications"><i class="fa fa-bell icon" aria-hidden="true"></i><span class="nav-label">Notifications</span></a></li>
                    <li><a class="<?php echo $page === 'profile' ? 'active' : ''; ?>" href="index.php?page=profile"><span class="icon profile-icon" aria-hidden="true">👤</span><span class="nav-label">Profile</span></a></li>
                <?php } ?>
                <li><a href="app/logout.php"><span class="icon" aria-hidden="true">⏻</span><span class="nav-label">Logout</span></a></li>
            </ul>
        </nav>
        <main class="dashboard">
        <section class="page-heading">
            <div>
                <p class="eyebrow"><?php echo $role === 'admin' ? 'ADMINISTRATOR' : 'EMPLOYEE WORKSPACE'; ?></p>
                <h2><?php echo $escape($pageTitles[$page]); ?></h2>
            </div>
        </section>

        <?php if ($notice !== null) { ?>
            <p class="notice notice-<?php echo $escape($notice[1]); ?>" role="status"><?php echo $escape($notice[0]); ?></p>
        <?php } ?>

        <?php if ($page === 'dashboard') { ?>
            <section class="welcome dashboard-welcome">
                <div>
                    <h3><?php echo $role === 'admin' ? 'Team task overview' : 'Welcome back, ' . $escape($displayName); ?></h3>
                    <p><?php echo $role === 'admin'
                        ? 'Assign work and keep track of team progress.'
                        : 'Review your assignments and update their progress.'; ?></p>
                </div>
                <div class="task-total"><strong><?php echo $totalTasks; ?></strong><span><?php echo $role === 'admin' ? 'total tasks' : 'assigned to you'; ?></span></div>
            </section>
        <section class="stats" aria-label="Task summary">
            <article class="stat-card"><span>To do</span><strong><?php echo $counts['pending']; ?></strong></article>
            <article class="stat-card"><span>In progress</span><strong><?php echo $counts['in_progress']; ?></strong></article>
            <article class="stat-card"><span>Completed</span><strong><?php echo $counts['completed']; ?></strong></article>
        </section>
            <section class="panel empty-state">
                <h3>Quick links</h3>
                <p>
                    <?php if ($role === 'admin') { ?>
                        <a class="dashboard-link" href="index.php?page=create-task">Create a task</a>
                        <a class="dashboard-link" href="index.php?page=all-tasks">Review all tasks</a>
                    <?php } else { ?>
                        <a class="dashboard-link" href="index.php?page=all-tasks">Open all tasks</a>
                        <a class="dashboard-link" href="index.php?page=profile">View my profile</a>
                    <?php } ?>
                </p>
            </section>
        <?php } ?>

        <?php if ($page === 'create-task' && $role === 'admin') { ?>
            <section class="panel create-panel">
                <div class="panel-heading">
                    <div><p class="eyebrow">NEW ASSIGNMENT</p><h2>Create a task</h2></div>
                </div>
                <?php if (count($employees) === 0) { ?>
                    <p class="empty-message">There are no employee accounts to assign tasks to yet.</p>
                <?php } else { ?>
                    <form method="post" class="task-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_task">
                        <label>Task title
                            <input type="text" name="title" maxlength="150" required>
                        </label>
                        <label>Assign to
                            <select name="assigned_to" required>
                                <option value="">Select an employee</option>
                                <?php foreach ($employees as $employee) { ?>
                                    <option value="<?php echo (int) $employee['id']; ?>"><?php echo $escape($employee['full_name']); ?></option>
                                <?php } ?>
                            </select>
                        </label>
                        <label>Due date <span class="optional">(optional)</span>
                            <input type="date" name="due_date">
                        </label>
                        <label class="description-field">Description
                            <textarea name="description" rows="3" required></textarea>
                        </label>
                        <button class="primary-button" type="submit">Assign task</button>
                    </form>
                <?php } ?>
            </section>
        <?php } ?>

        <?php if ($page === 'manage-users' && $role === 'admin') { ?>
            <section class="panel">
                <div class="panel-heading"><p class="eyebrow">TEAM DIRECTORY</p><h2>All users</h2></div>
                <?php if (count($users) === 0) { ?>
                    <div class="empty-state"><p>No user accounts were found.</p></div>
                <?php } else { ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Joined</th></tr></thead>
                            <tbody>
                                <?php foreach ($users as $account) { ?>
                                    <tr>
                                        <td><?php echo $escape($account['full_name']); ?></td>
                                        <td><?php echo $escape($account['username']); ?></td>
                                        <td><?php echo $escape(ucfirst($account['role'])); ?></td>
                                        <td><?php echo $escape(date('M j, Y', strtotime($account['created_at']))); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </section>
        <?php } ?>

        <?php if ($page === 'all-tasks') { ?>
        <section class="panel">
            <div class="panel-heading">
                <div><p class="eyebrow"><?php echo $role === 'admin' ? 'TEAM WORK' : 'YOUR WORK'; ?></p><h2>All tasks</h2></div>
            </div>
            <?php if (count($tasks) === 0) { ?>
                <div class="empty-state">
                    <h3><?php echo $role === 'admin' ? 'No tasks yet' : 'You have no assigned tasks'; ?></h3>
                    <p><?php echo $role === 'admin'
                        ? 'Create a task above to assign work to an employee.'
                        : 'Tasks assigned to you will appear here.'; ?></p>
                </div>
            <?php } else { ?>
                <div class="task-list">
                    <?php foreach ($tasks as $task) { ?>
                        <article class="task-card">
                            <div class="task-content">
                                <div class="task-title-row">
                                    <h3><?php echo $escape($task['title']); ?></h3>
                                    <span class="status-badge status-<?php echo $escape($task['status']); ?>">
                                        <?php echo $escape(str_replace('_', ' ', ucfirst($task['status']))); ?>
                                    </span>
                                </div>
                                <p class="task-description"><?php echo nl2br($escape($task['description'])); ?></p>
                                <div class="task-meta">
                                    <?php if ($role === 'admin') { ?>
                                        <span>Assigned to: <?php echo $escape($task['employee_name'] ?? 'Unassigned'); ?></span>
                                    <?php } ?>
                                    <span><?php echo $task['due_date'] === null ? 'No due date' : 'Due ' . $escape(date('M j, Y', strtotime($task['due_date']))); ?></span>
                                </div>
                            </div>
                            <form method="post" class="status-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>">
                                <label class="visually-hidden" for="status-<?php echo (int) $task['id']; ?>">Status for <?php echo $escape($task['title']); ?></label>
                                <select id="status-<?php echo (int) $task['id']; ?>" name="status">
                                    <option value="pending" <?php echo $task['status'] === 'pending' ? 'selected' : ''; ?>>To do</option>
                                    <option value="in_progress" <?php echo $task['status'] === 'in_progress' ? 'selected' : ''; ?>>In progress</option>
                                    <option value="completed" <?php echo $task['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                                <button class="secondary-button" type="submit">Update</button>
                            </form>
                        </article>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
        <?php } ?>

        <?php if ($page === 'profile') { ?>
            <section class="panel profile-panel">
                <div class="panel-heading"><p class="eyebrow">ACCOUNT DETAILS</p><h2>My profile</h2></div>
                <?php if (isset($_GET['edit']) && $_GET['edit'] === '1') { ?>
                    <form method="post" class="task-form profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <label>Full name
                            <input type="text" name="full_name" maxlength="50" value="<?php echo $escape($displayName); ?>" required>
                        </label>
                        <label>Username
                            <input type="text" name="username" maxlength="50" value="<?php echo $escape($_SESSION['username'] ?? ''); ?>" autocomplete="username" required>
                        </label>
                        <button class="primary-button" type="submit">Save changes</button>
                        <a class="secondary-link" href="index.php?page=profile">Cancel</a>
                    </form>
                <?php } else { ?>
                    <dl class="profile-details">
                        <div><dt>Full name</dt><dd><?php echo $escape($displayName); ?></dd></div>
                        <div><dt>Username</dt><dd><?php echo $escape($_SESSION['username'] ?? ''); ?></dd></div>
                        <div><dt>Role</dt><dd><?php echo $escape(ucfirst($role)); ?></dd></div>
                    </dl>
                    <a class="primary-button profile-change-button" href="index.php?page=profile&amp;edit=1">Change Profile</a>
                <?php } ?>
            </section>
        <?php } ?>

        <?php if ($page === 'notifications') { ?>
            <section class="panel notifications-panel">
                <div class="panel-heading notification-heading">
                    <div><p class="eyebrow">TASK ACTIVITY</p><h2>Notifications</h2></div>
                    <?php if ($unreadNotificationCount > 0) { ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                            <input type="hidden" name="action" value="mark_all_notifications_read">
                            <button class="secondary-button" type="submit">Mark all as read</button>
                        </form>
                    <?php } ?>
                </div>
                <?php if (count($notifications) === 0) { ?>
                    <div class="empty-state"><h3>No notifications yet</h3><p>Task assignments and status updates will appear here.</p></div>
                <?php } else { ?>
                    <div class="notification-list">
                        <?php foreach ($notifications as $notification) { ?>
                            <article class="notification-item <?php echo $notification['read_at'] === null ? 'unread' : ''; ?>">
                                <span class="notification-dot" aria-hidden="true"></span>
                                <div class="notification-copy">
                                    <p><?php echo $escape($notification['message']); ?></p>
                                    <span>
                                        <?php echo $escape(date('M j, Y g:i A', strtotime($notification['created_at']))); ?>
                                        <?php if ($notification['task_status'] !== null) { ?>
                                            · <?php echo $escape(str_replace('_', ' ', ucfirst($notification['task_status']))); ?>
                                        <?php } ?>
                                    </span>
                                </div>
                                <?php if ($notification['read_at'] === null) { ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrfToken); ?>">
                                        <input type="hidden" name="action" value="mark_notification_read">
                                        <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                                        <button class="notification-read-button" type="submit">Mark read</button>
                                    </form>
                                <?php } ?>
                            </article>
                        <?php } ?>
                    </div>
                <?php } ?>
            </section>
        <?php } ?>
        </main>
    </div>
</body>
</html>
