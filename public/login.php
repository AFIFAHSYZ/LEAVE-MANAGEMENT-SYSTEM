<?php
session_start();
require_once "../config/conn.php";

$error = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Fetch user by email
    $sql = "SELECT id, name, email, password, position FROM users WHERE email = :email";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if (password_verify($password, $user['password'])) {
        // Valid login
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['position'] = $user['position'];

        // Redirect based on position
        switch (strtolower($user['position'])) {
            case 'employee':
                header("Location: emp/emp-dashboard.php");
                break;
            case 'manager':
                header("Location: manager/manager-dashboard.php");
                break;
            case 'hr':
                header("Location: HR/hr-dashboard.php");
                break;
            default:
                header("Location: emp/emp-dashboard.php");
                break;
        }

        exit;
    } else {
        $error = "Invalid email or password.";
    }
} else {
    $error = "There is no account created for this email. Please register.";
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Teraju LMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
    /* Reset & Base Styles */
    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Roboto', sans-serif; }
    body { background: linear-gradient(135deg, #e0e7ff, #eef2f7); color: #2c3e50; line-height: 1.6; min-height: 100vh; }

    /* Login Page */
    .login-container { display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
    .login-container header { text-align: center; margin-bottom: 40px; }
    .login-container header h1 { font-size: 2rem; color: #1f3b4d; font-weight: 700; margin-bottom: 10px; }
    .login-container header p { font-size: 1rem; color: #4f6d8f; }

    .login-container .card { width: 100%; max-width: 400px; background: #ffffffcc; backdrop-filter: blur(8px); border-radius: 16px; padding: 35px 30px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .login-container .card:hover { transform: translateY(-5px); box-shadow: 0 20px 45px rgba(0,0,0,0.15); }
    .login-container .card h2 { text-align: center; margin-bottom: 25px; color: #1f3b4d; font-weight: 700; }

    .login-container .form-group { margin-bottom: 20px; }
    .login-container .form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #34495e; }
    .login-container .form-group input { width: 100%; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 12px; font-size: 1rem; background: #f9fafb; color: #1f3b4d; transition: all 0.25s ease; }
    .login-container .form-group input:focus { border-color: #4f9eff; box-shadow: 0 0 0 3px rgba(79,158,255,0.2); outline: none; }

    .login-container button.btn-full { width: 100%; background-color: #4f9eff; color: #fff; padding: 14px; font-size: 1rem; font-weight: 600; border: none; border-radius: 12px; cursor: pointer; transition: all 0.25s ease; }
    .login-container button.btn-full:hover { background-color: #3a7ddd; transform: translateY(-2px); }

    .login-container .error-box { background-color: #ffe6e6; color: #e74c3c; padding: 12px; margin-bottom: 20px; border-radius: 10px; font-size: 0.95rem; text-align: center; }

    .login-container .form-footer { text-align: center; margin-top: 15px; font-size: 0.9rem; color: #64748b; }
    .login-container .form-footer a { color: #4f9eff; text-decoration: none; font-weight: 500; }
    .login-container .form-footer a:hover { text-decoration: underline; }

    /* Responsive */
    @media(max-width: 500px) {
        .login-container .card { padding: 30px 20px; }
        .login-container header h1 { font-size: 1.6rem; }
        .login-container header p { font-size: 0.95rem; }
    }
    </style>
</head>
<body>
    <div class="login-container">
        <header>
            <h1>Teraju Leave Management System</h1>
            <p>Welcome back. Please log in to your account.</p>
        </header>

        <div class="card">
            <h2>Login</h2>

            <?php if (!empty($error)): ?>
                <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <button type="submit" class="btn-full">Login</button>
            </form>

            <p class="form-footer">
                Don’t have an account? <a href="register.php">Register here</a>.<br>
                Forgot password? <a href="forgot-pass.php">Click here</a>.
            </p>
        </div>
    </div>
</body>
</html>
