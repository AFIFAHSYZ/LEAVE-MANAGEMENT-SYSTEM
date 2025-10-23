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
</head>
<body>
    <h2>Reset Password</h2>
    <form action="update-password.php" method="POST">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="password" name="new_password" placeholder="New Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        <button type="submit">Reset Password</button>
    </form>
</body>
</html>
