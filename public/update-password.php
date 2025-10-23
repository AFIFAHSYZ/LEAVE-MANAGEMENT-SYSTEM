<?php
include '../config/conn.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($token) || empty($newPassword) || empty($confirmPassword)) {
        die("❌ All fields are required.");
    }

    if ($newPassword !== $confirmPassword) {
        die("❌ Passwords do not match.");
    }

    try {
        // Fetch user by token
        $stmt = $pdo->prepare("SELECT id, reset_expiry FROM users WHERE reset_token = :token");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            die("❌ Invalid token.");
        }

        // Correct expiry check
        $expiry_time = strtotime($user['reset_expiry']);
        if ($expiry_time < time()) {
            die("❌ Token expired.");
        }

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

        echo "✅ Password reset successfully. You can now <a href='index.php'>login</a>.";

    } catch (PDOException $e) {
        die("❌ Database error: " . $e->getMessage());
    }
}
?>
