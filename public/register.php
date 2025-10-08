<?php
require_once '../config/conn.php';

// Helper function to sanitize input
function clean_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

$registrationSuccess = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean_input($_POST['name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $position = $_POST['position'] ?? 'employee';

    if (!$name || !$email || !$password) {
        $error = "Name, email, and password are required!";
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = "Email already registered!";
            } else {
                // Hash the password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Insert user
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, position) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $email, $passwordHash, $position]);
                $registrationSuccess = true;
            }
        } catch (PDOException $e) {
            $error = "Server error: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Leave Management System</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
</head>
<body>
<div class="container">
    <header>
            <h1>Teraju Leave Management System</h1>
        <p>Register your account</p>
    </header>
    <main>
        <div class="card">
            <h2>Create Account</h2>
            <?php if ($registrationSuccess): ?>
                <p style="color:green;">Registration successful! You can now <a href="login.php">login</a>.</p>
            <?php elseif (!empty($error)): ?>
                <div class="error-box"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if (!$registrationSuccess): ?>
            <form action="register.php" method="POST">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" required value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="position">Position</label>
                    <select id="position" name="position">
                        <option value="employee" <?php if (isset($position) && $position == "employee") echo "selected"; ?>>Employee</option>
                        <option value="manager" <?php if (isset($position) && $position == "manager") echo "selected"; ?>>Manager</option>
                        <option value="admin" <?php if (isset($position) && $position == "admin") echo "selected"; ?>>Admin</option>
                    </select>
                </div>
                <button type="submit" class="btn-full">Register</button>
            </form>
            <div class="form-footer">
                Already have an account? <a href="login.php">Login here</a>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <footer>
        &copy; <?php echo date('Y'); ?> Leave Management System. All rights reserved.
    </footer>
</div>
</body>
</html>