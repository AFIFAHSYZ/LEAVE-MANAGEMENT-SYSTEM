<?php
session_start();
require_once '../../config/conn1.php';

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
$editHoliday = null;

// Handle POST actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name']);
        $date = $_POST['date'];

        if ($name && $date) {
            $stmt = $pdo->prepare("INSERT INTO public_holidays (name, holiday_date) VALUES (:n, :d)");
            $stmt->execute([':n' => $name, ':d' => $date]);
            $success = "Holiday added successfully.";
        } else {
            $error = "Please fill all fields.";
        }

    } elseif ($_POST['action'] === 'delete') {
        $id = $_POST['id'];
        $pdo->prepare("DELETE FROM public_holidays WHERE id = :id")->execute([':id' => $id]);
        $success = "Holiday deleted successfully.";

    } elseif ($_POST['action'] === 'edit') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT * FROM public_holidays WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $editHoliday = $stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($_POST['action'] === 'update') {
        $id = $_POST['id'];
        $name = trim($_POST['name']);
        $date = $_POST['date'];

        if ($name && $date) {
            $stmt = $pdo->prepare("UPDATE public_holidays SET name = :n, holiday_date = :d WHERE id = :id");
            $stmt->execute([':n' => $name, ':d' => $date, ':id' => $id]);
            $success = "Holiday updated successfully.";
        } else {
            $error = "All fields are required.";
        }
    }
}

// Fetch holidays for listing
$holidays = $pdo->query("SELECT * FROM public_holidays ORDER BY holiday_date ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Public Holidays | HR Dashboard</title>
  <link rel="stylesheet" href="../../assets/css/style.css">

  <style>
    .manage-form { background:#fff; padding:15px; border-radius:10px; display:flex; gap:12px; flex-wrap:wrap; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-bottom:20px; align-items:center; }
    .manage-form input { padding:8px 10px; border-radius:6px; border:1px solid #cbd5e1; font-size:0.9rem; }
    .manage-form button { padding:8px 14px; background:#3b82f6; border:none; border-radius:6px; font-size:0.9rem; color:#fff; cursor:pointer; }
    .manage-form button:hover { background:#2563eb; }
    .manage-form a { color:#64748b; text-decoration:none; font-size:0.9rem; }

    /* Compact Table */
    .leave-table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; font-size:0.85rem; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
    .leave-table thead { background:#f8fafc; }
    .leave-table th, .leave-table td { padding:6px 10px; border-bottom:1px solid #e2e8f0; }
    .leave-table th { font-size:0.75rem; color:#475569; text-transform:uppercase; }
    .leave-table tbody tr:hover { background:#f1f5f9; }

    /* Action Buttons */
    .action-form { display:inline-flex; gap:6px; margin:0; padding:0; }
    .action-form button { padding:4px 8px; font-size:0.75rem; border-radius:4px; background:#e2e8f0; border:1px solid #cbd5e1; cursor:pointer; transition:.15s; }
    .action-form button:hover { background:#cbd5e1; }
    .action-form .delete { background:#fee2e2; border-color:#fecaca; }
    .action-form .delete:hover { background:#fecaca; }
  </style>
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>

  <header><h1>Manage Public Holidays</h1></header>

  <main class="main-content">
    <div class="card">

      <h2>Public Holidays</h2>
      <p>Review and manage all public holidays</p>
      <hr><br>

      <?php if ($success): ?>
        <div class="success-box"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- Add / Edit Form -->
      <form method="POST" class="manage-form">
        <input type="hidden" name="id" value="<?= $editHoliday['id'] ?? '' ?>">

        <input type="text" name="name" placeholder="Holiday Name" 
               value="<?= htmlspecialchars($editHoliday['name'] ?? '') ?>" required>

        <input type="date" name="date" 
               value="<?= $editHoliday['holiday_date'] ?? '' ?>" required>

        <?php if ($editHoliday): ?>
          <button type="submit" name="action" value="update">Update</button>
          <a href="holidays.php">Cancel</a>
        <?php else: ?>
          <button type="submit" name="action" value="add">Add</button>
        <?php endif; ?>
      </form>

      <!-- Table -->
      <table class="leave-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Holiday Name</th>
            <th>Date</th>
            <th style="text-align:center;">Action</th>
          </tr>
        </thead>

        <tbody>
          <?php foreach ($holidays as $index => $h): ?>
          <tr>
            <td><?= $index + 1 ?></td>
            <td><?= htmlspecialchars($h['name']) ?></td>
            <td><?= date('d M Y', strtotime($h['holiday_date'])) ?></td>

            <td style="text-align:center;">
              <form method="POST" class="action-form">
                <input type="hidden" name="id" value="<?= $h['id'] ?>">

                <button name="action" value="edit">Edit</button>
                <button name="action" value="delete" class="delete"
                        onclick="return confirm('Delete this holiday?')">
                    Delete
                </button>
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
