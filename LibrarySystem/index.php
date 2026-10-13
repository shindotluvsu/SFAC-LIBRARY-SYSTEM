<?php
session_start();

if (isset($_GET['logout'])) {
    unset($_SESSION['library_user']);
    header('Location: index.php');
    exit;
}

$users = [
    'admin' => [
        'password' => password_hash('admin123', PASSWORD_DEFAULT),
        'name' => 'System Administrator',
        'role' => 'Administrator',
    ],
    'student' => [
        'password' => password_hash('student123', PASSWORD_DEFAULT),
        'name' => 'Student User',
        'role' => 'Student',
    ],
];

$registeredUsers = $_SESSION['registered_users'] ?? [];
$users = $users + $registeredUsers;
$errorMessage = '';
$isRegister = isset($_GET['register']) || (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['form_action'] ?? '') === 'register'
);

if (!isset($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['login_csrf_token'], $csrfToken)) {
        $errorMessage = 'Your session check failed. Please try again.';
    } elseif ($isRegister) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($name === '' || $username === '' || $password === '' || $confirmPassword === '') {
            $errorMessage = 'Please complete all fields.';
        } elseif (strlen($name) > 80) {
            $errorMessage = 'Name must be 80 characters or fewer.';
        } elseif (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) {
            $errorMessage = 'Username must be 3–30 characters using letters, numbers, dots, underscores, or hyphens.';
        } elseif (isset($users[$username])) {
            $errorMessage = 'That username is already in use.';
        } elseif (strlen($password) < 8) {
            $errorMessage = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirmPassword) {
            $errorMessage = 'Passwords do not match.';
        } else {
            $_SESSION['registered_users'][$username] = [
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $name,
                'role' => 'Student',
            ];
            $_SESSION['login_flash'] = 'Account created. You can now sign in.';
            header('Location: index.php');
            exit;
        }
    } else {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $errorMessage = 'Please enter both your username and password.';
        } elseif (!isset($users[$username]) || !password_verify($password, $users[$username]['password'])) {
            $errorMessage = 'Invalid username or password.';
        } else {
            session_regenerate_id(true);
            $_SESSION['library_user'] = [
                'email' => $username,
                'name' => $users[$username]['name'],
                'role' => $users[$username]['role'],
            ];

            header('Location: dashboard.php');
            exit;
        }
    }
}

$successMessage = $_SESSION['login_flash'] ?? '';
unset($_SESSION['login_flash']);

$user = $_SESSION['library_user'] ?? null;

if ($user) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SFAC Library System</title>
    <style>
        :root {
            --bg: #faf7f7;
            --panel: #ffffff;
            --primary: #a34349;
            --primary-dark: #87363c;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #eadcdd;
            --shadow: 0 12px 30px rgba(40, 0, 0, 0.08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: var(--bg);
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
        }

        .login-box {
            width: min(100%, 360px);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: var(--shadow);
            padding: 28px 24px;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 1.8rem;
            color: var(--primary);
        }

        .subtitle {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 0.92rem;
        }

        form {
            display: grid;
            gap: 14px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.86rem;
            font-weight: 600;
        }

        input {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fbfbfc;
            font-size: 0.96rem;
            color: var(--text);
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .error {
            padding: 10px 12px;
            border-radius: 10px;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            color: #b91c1c;
            font-size: 0.82rem;
        }

        .success {
            padding: 10px 12px;
            border-radius: 10px;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #047857;
            font-size: 0.82rem;
        }

        .account-link {
            margin: 18px 0 0;
            color: var(--muted);
            font-size: 0.88rem;
            text-align: center;
        }

        .account-link a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            border: none;
            border-radius: 10px;
            background: var(--primary);
            color: white;
            font-weight: 700;
            font-size: 0.98rem;
            padding: 12px 16px;
            cursor: pointer;
            text-decoration: none;
        }

        .button:hover {
            background: var(--primary-dark);
        }

    </style>
</head>
<body>
    <div class="login-box">
        <h1><?php echo $isRegister ? 'Create an account' : 'Login'; ?></h1>
        <p class="subtitle"><?php echo $isRegister ? 'Register as a student to use the library.' : 'Welcome back'; ?></p>

        <form method="post" action="index.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['login_csrf_token']); ?>">
            <input type="hidden" name="form_action" value="<?php echo $isRegister ? 'register' : 'login'; ?>">

            <?php if ($isRegister): ?>
                <div>
                    <label for="name">Full name</label>
                    <input id="name" name="name" type="text" maxlength="80" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" autocomplete="name" required>
                </div>
            <?php endif; ?>

            <div>
                <label for="username">Username</label>
                <input id="username" name="username" type="text" placeholder="Username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" autocomplete="username" required>
            </div>

            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Enter password" autocomplete="<?php echo $isRegister ? 'new-password' : 'current-password'; ?>" required>
            </div>

            <?php if ($isRegister): ?>
                <div>
                    <label for="confirm-password">Confirm password</label>
                    <input id="confirm-password" name="confirm_password" type="password" placeholder="Re-enter password" autocomplete="new-password" required>
                </div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div class="success" role="status"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="error" role="alert"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <button class="button" type="submit"><?php echo $isRegister ? 'Create Account' : 'Sign In'; ?></button>
        </form>

        <p class="account-link">
            <?php if ($isRegister): ?>
                Already have an account? <a href="index.php">Sign in</a>
            <?php else: ?>
                Don't have an account? <a href="index.php?register=1">Register</a>
            <?php endif; ?>
        </p>
    </div>
</body>
</html>
