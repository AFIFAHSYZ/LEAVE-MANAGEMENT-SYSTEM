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
            $stmt = $pdo->prepare("INSERT INTO public_holidays (name, holiday_date) VALUES (:n, :d)");
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
      <style>    
    .filter-form {
        display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;
        align-items: flex-end; background: #fff; padding: 15px;
        border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.05);
    }
    .filter-form label { display: block; font-size: 0.9rem; color: #334155; margin-bottom: 5px; }
    .filter-form input, .filter-form select {
        padding: 8px 10px; border-radius: 8px; border: 1px solid #cbd5e1;
        font-size: 0.9rem; width: 150px;
    }
    .filter-form button {
        padding: 9px 16px; background: #3b82f6; border: none; border-radius: 8px;
        color: #fff; font-weight: 600; cursor: pointer;
    }
    .filter-form button:hover { background: #2563eb; }

</style>

</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>
  <header><h1>Manage Public Holidays</h1></header>

  <main class="main-content">
    <div class="card">
      <h2>Public Holidays</h2>
      <p>Review and manage all public holiday</p><hr><br>

      <?php if ($success): ?><div class="success-box"><?= $success ?></div><?php endif; ?>

      <form method="POST" class="filter-form" style="gap:10px;">
        <input type="text" name="name" placeholder="Holiday Name" required>
        <input type="date" name="date" required>
        <button type="submit" name="action" value="add" class="btn-submit">Add</button>
      </form>

      <table class="leave-table" style="margin-top:15px;">
        <thead><tr><th>ID</th><th>Name</th><th>Date</th><th>Action</th></tr></thead>
<tbody>
  <?php foreach ($holidays as $index => $h): ?>
    <tr>
      <td><?= $index + 1 ?></td> <!-- Show row number -->
      <td><?= htmlspecialchars($h['name']) ?></td>
      <td><?= date('d M Y', strtotime($h['holiday_date'])) ?></td>
      <td>
        <form method="POST"  class="filter-form" style="gap:10px;">
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
