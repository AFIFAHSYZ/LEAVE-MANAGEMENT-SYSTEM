<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = $error = "";

// Fetch employee info
$stmt = $pdo->prepare("
    SELECT 
        name, 
        email, 
        position, 
        date_joined,
        religion,
        race
    FROM users 
    WHERE id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}

// ====================
// Handle password change
// ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw = $_POST['new_password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';

    if ($current_pw && $new_pw && $confirm_pw) {
        if ($new_pw !== $confirm_pw) {
            $error = "⚠️ New passwords do not match.";
        } else {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $stored_pw = $stmt->fetchColumn();

            if (!password_verify($current_pw, $stored_pw)) {
                $error = "❌ Current password is incorrect.";
            } else {
                $hashed_pw = password_hash($new_pw, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_pw, $user_id]);
                $success = "✅ Password updated successfully!";
            }
        }
    } else {
        $error = "⚠️ Please fill in all password fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Profile | Teraju HR System</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
.profile-container {max-width: 100%;margin: 0 auto;background: #fff;padding: 25px 40px;border-radius: 12px;box-shadow: 0 3px 10px rgba(0,0,0,0.1);}
.profile-header {text-align: center;margin-bottom: 25px;}
.profile-info {display: grid;grid-template-columns: 1fr 1fr;gap: 10px 30px;margin-bottom: 25px;}
.profile-info p {margin: 6px 0;font-size: 1rem;}
.form-group { margin-bottom: 15px; }
.form-group label { display: block; font-weight: 600; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #d1d5db; }
.btn-full { display: block; width: 100%; padding: 10px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; transition: background 0.3s; }
.btn-full:hover { background: #1e40af; }
.success-box, .error-box { padding: 10px; border-radius: 6px; margin-bottom: 15px; }
.success-box { background: #dcfce7; color: #166534; }
.error-box { background: #fee2e2; color: #991b1b; }
</style>
</head>
<body>
<div class="layout">
  <?php include 'emp-sidebar.php'; ?>
  <header>
      <h1>Teraju Leave Management System</h1>
  </header>
  <div class="main-content">
    <div class="profile-container">
      <div class="profile-header">
        <h2>My Profile</h2>
        <p>View and manage your personal information.</p>
      </div>

      <?php if ($success): ?>
        <div class="success-box"><?= htmlspecialchars($success) ?></div>
      <?php elseif ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="profile-info">
        <p><strong>Name:</strong> <?= htmlspecialchars($user['name']) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
        <p><strong>Position:</strong> <?= htmlspecialchars(ucfirst($user['position'])) ?></p>
        <p><strong>Date Joined:</strong> <?= htmlspecialchars($user['date_joined']) ?></p>
        <p><strong>Religion:</strong> <?= htmlspecialchars($user['religion']) ?></p>
        <p><strong>Race:</strong> <?= htmlspecialchars($user['race']) ?></p>

      </div>



      <hr style="margin: 20px 0;">

      <h3>Change Password</h3>
      <form method="POST" action="">
        <div class="form-group">
          <label for="current_password">Current Password</label>
          <input type="password" id="current_password" name="current_password" required>
        </div>

        <div class="form-group">
          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" required minlength="6">
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
        </div>

        <button type="submit" class="btn-full">Update Password</button>
      </form>

      <div style="text-align:center; margin-top:15px;">
        <a href="emp-dashboard.php" style="color:#2563eb; text-decoration:none;">← Back to Dashboard</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
