<?php
include '../config/conn.php';

$message = '';
$isError = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($token) || empty($newPassword) || empty($confirmPassword)) {
        $message = "❌ All fields are required.";
        $isError = true;
    } elseif ($newPassword !== $confirmPassword) {
        $message = "❌ Passwords do not match.";
        $isError = true;
    } else {
        try {
            // Fetch user by token
            $stmt = $pdo->prepare("SELECT id, reset_expiry FROM users WHERE reset_token = :token");
            $stmt->execute([':token' => $token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $message = "❌ Invalid token.";
                $isError = true;
            } elseif (strtotime($user['reset_expiry']) < time()) {
                $message = "❌ Token expired.";
                $isError = true;
            } else {
                // Hash password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                // Update password and clear token
                $update = $pdo->prepare("
                    UPDATE users
                    SET password = :password, reset_token = NULL, reset_expiry = NULL
                    WHERE id = :id
                ");
                $update->execute([
                    ':password' => $hashedPassword,
                    ':id' => $user['id']
                ]);

                $message = "✅ Password reset successfully. You can now <a href='index.php'>login</a>.";
                $isError = false;
            }
        } catch (PDOException $e) {
            $message = "❌ Database error: " . $e->getMessage();
            $isError = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Reset Result</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #00bcd4, #2196f3);
            height: 100vh;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .result-container {
            background: #fff;
            padding: 40px 50px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        h2 {
            margin-bottom: 20px;
            color: #333;
        }

        .message {
            padding: 15px;
            border-radius: 10px;
            font-size: 16px;
            line-height: 1.5;
        }

        .error {
            background: #ffe6e6;
            color: #d32f2f;
            border-left: 5px solid #f44336;
        }

        .success {
            background: #e6ffe6;
            color: #388e3c;
            border-left: 5px solid #4caf50;
        }

        a {
            color: #1976d2;
            text-decoration: none;
            font-weight: bold;
        }

        a:hover {
            text-decoration: underline;
        }

        .footer {
            margin-top: 25px;
            font-size: 14px;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="result-container">
        <h2>Password Reset</h2>
        <?php if ($message): ?>
            <div class="message <?php echo $isError ? 'error' : 'success'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Leave Management System</p>
        </div>
    </div>
</body>
</html>
