<?php
include '../config/conn.php'; 

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die("❌ Invalid request. Token missing.");
}

try {
    // 1️⃣ Verify token and expiry
    $stmt = $pdo->prepare("
        SELECT id, email, reset_expiry 
        FROM users 
        WHERE reset_token = :token
    ");
    $stmt->bindParam(':token', $token, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die("❌ Invalid token.");
    }

    if (strtotime($user['reset_expiry']) < time()) {
        die("❌ Token expired.");
    }

} catch (PDOException $e) {
    die("❌ Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #00bcd4, #2196f3);
            margin: 0;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .reset-container {
            background: #fff;
            padding: 40px 50px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        h2 {
            color: #333;
            margin-bottom: 25px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        input[type="password"] {
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 16px;
            outline: none;
            transition: border-color 0.2s ease;
        }

        input[type="password"]:focus {
            border-color: #2196f3;
        }

        button {
            padding: 12px;
            background-color: #2196f3;
            border: none;
            color: white;
            font-size: 16px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background-color: #1976d2;
        }

        .footer {
            margin-top: 20px;
            font-size: 14px;
            color: #777;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <h2>🔒 Reset Your Password</h2>
        <form action="update-password.php" method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <button type="submit">Reset Password</button>
        </form>
        <div class="footer">
            <p>&copy; <?php echo date('Y'); ?> Leave Management System</p>
        </div>
    </div>
</body>
</html>
