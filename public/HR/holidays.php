<?php
session_start();
require_once '../../config/conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Verify HR role
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user['position'] !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

$success = $error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name']);
        $date = $_POST['date'];
        if ($name && $date) {
            $stmt = $pdo->prepare("INSERT INTO public_holidays (holiday_name, holiday_date) VALUES (:n, :d)");
            $stmt->execute([':n' => $name, ':d' => $date]);
            $success = "Holiday added successfully.";
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = $_POST['id'];
        $pdo->prepare("DELETE FROM public_holidays WHERE id = :id")->execute([':id' => $id]);
        $success = "Holiday deleted.";
    }
}

$holidays = $pdo->query("SELECT * FROM public_holidays ORDER BY holiday_date ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Public Holidays | HR Dashboard</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>
  <header><h1>Manage Public Holidays</h1></header>

  <main class="main-content">
    <div class="card">
      <h2>Public Holidays</h2>

      <?php if ($success): ?><div class="success-box"><?= $success ?></div><?php endif; ?>

      <form method="POST" class="form-inline" style="gap:10px;">
        <input type="text" name="name" placeholder="Holiday Name" required>
        <input type="date" name="date" required>
        <button type="submit" name="action" value="add" class="btn-submit">Add</button>
      </form>

      <table class="leave-table" style="margin-top:15px;">
        <thead><tr><th>ID</th><th>Name</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($holidays as $h): ?>
            <tr>
              <td><?= $h['id'] ?></td>
              <td><?= htmlspecialchars($h['holiday_name']) ?></td>
              <td><?= $h['holiday_date'] ?></td>
              <td>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="id" value="<?= $h['id'] ?>">
                  <button name="action" value="delete" class="btn" onclick="return confirm('Delete this holiday?')">🗑 Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>
<script src="../../assets/js/sidebar.js"></script> 
</body>
</html>
