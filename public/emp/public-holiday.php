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
if ($user['position'] !== 'employee') {
    header("Location: ../../unauthorized.php");
    exit();
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
    .filter-form {display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; align-items: flex-end; background: #fff; padding: 15px;border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.05);}
    .filter-form label { display: block; font-size: 0.9rem; color: #334155; margin-bottom: 5px; }
    .filter-form input, .filter-form select {padding: 8px 10px; border-radius: 8px; border: 1px solid #cbd5e1;font-size: 0.9rem; width: 150px; }
    .filter-form button {padding: 9px 16px; background: #3b82f6; border: none; border-radius: 8px;    color: #fff; font-weight: 600; cursor: pointer; }
    .filter-form button:hover { background: #2563eb; }
    .leave-table {width: 100%;border-collapse: collapse;background: #fff;border-radius: 8px;overflow: hidden;font-size: 0.9rem;box-shadow: 0 2px 8px rgba(0,0,0,0.05);}
    .leave-table thead {background: #f1f5f9;}
    .leave-table th,.leave-table td {padding: 6px 10px;text-align: left;border-bottom: 1px solid #e2e8f0;vertical-align: middle;}
    .leave-table th {font-weight: 600;color: #334155;text-transform: uppercase;font-size: 0.8rem;}
    .leave-table tbody tr:hover {background: #f9fafb;}
    .leave-table td form {margin: 0;}
    .leave-table td:last-child {text-align: center;padding: 4px 6px;}
    .leave-table button {padding: 4px 8px;font-size: 0.8rem;border-radius: 6px;}
  </style>
</head>
<body>
<div class="layout">

<?php include "emp-sidebar.php"?>
  <header><h1> Public Holidays</h1></header>

  <main class="main-content">
    <div class="card">
      <h2>Public Holidays</h2>
<p id="ph-title"></p>

<script>
  // get current year from the browser clock
  const year = new Date().getFullYear();
  document.getElementById('ph-title').textContent = `${year}'s Public Holiday`;
</script>

      <table class="leave-table" style="margin-top:15px;">
        <thead><tr><th>ID</th><th>Name</th><th>Date</th></tr></thead>
<tbody>
  <?php foreach ($holidays as $index => $h): ?>
    <tr>
      <td><?= $index + 1 ?></td> <!-- Show row number -->
      <td><?= htmlspecialchars($h['name']) ?></td>
      <td><?= date('d M Y', strtotime($h['holiday_date'])) ?></td>
      <td>
        <form method="POST"  class="filter-form" style="gap:10px;">
          <input type="hidden" name="id" value="<?= $h['id'] ?>">
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
</tbody>
      </table>
    </div>
  </main>
</div>
</body>
</html>
