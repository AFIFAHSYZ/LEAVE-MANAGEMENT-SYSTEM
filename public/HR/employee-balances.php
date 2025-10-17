<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Check HR role
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user['position'] !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

// Get employee ID
$emp_id = $_GET['id'] ?? null;
if (!$emp_id) {
    header("Location: employees.php");
    exit();
}

// Fetch employee info
$stmt = $pdo->prepare("SELECT name, email, position, date_joined FROM users WHERE id = :id");
$stmt->execute([':id' => $emp_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch leave balances
$sql = "
    SELECT lt.name AS leave_type, lt.default_limit, 
           COALESCE(lb.used_days, 0) AS used_days,
           COALESCE(lb.carry_forward, 0) AS carry_forward,
           (lt.default_limit + COALESCE(lb.carry_forward, 0) - COALESCE(lb.used_days, 0)) AS available
    FROM leave_types lt
    LEFT JOIN leave_balances lb ON lt.id = lb.leave_type_id AND lb.user_id = :emp_id
    ORDER BY lt.id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':emp_id' => $emp_id]);
$balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($employee['name']) ?> | Leave Balances</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
  .card h2 { margin-bottom: 5px; }
  .employee-info { margin-bottom: 20px; background: #f8fafc; padding: 15px; border-radius: 10px; }
  .employee-info p { margin: 5px 0; }
  table.leave-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  table.leave-table th, table.leave-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
  table.leave-table th { background: #2563eb; color: #fff; }
  table.leave-table tr:nth-child(even) { background: #f9fafb; }
  .btn-back { background: #64748b; color: #fff; padding: 6px 12px; border-radius: 6px; text-decoration: none; }
  .btn-back:hover { background: #475569; }
</style>
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>
<header><h1>Leave Management System</h1></header>

  <main class="main-content"> 
    <div style="margin-top:15px; margin-bottom: 15px;">
        <a href="employees.php" class="btn-back">← Back to Employees</a>
    </div>

    <div class="card"><h2>Leave Balances</h2>
      <h2><?= htmlspecialchars($employee['name']) ?></h2>

      <div class="employee-info">
        <p><strong>Email:</strong> <?= htmlspecialchars($employee['email']) ?></p>
        <p><strong>Position:</strong> <?= ucfirst($employee['position']) ?></p>
        <p><strong>Date Joined:</strong> <?= $employee['date_joined'] ?></p>
      </div>

      <table class="leave-table">
        <thead>
          <tr>
            <th>Leave Type</th>
            <th>Default Limit</th>
            <th>Used Days</th>
            <th>Carry Forward</th>
            <th>Available</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($balances as $b): ?>
            <tr>
              <td><?= htmlspecialchars($b['leave_type']) ?></td>
              <td><?= $b['default_limit'] ?></td>
              <td><?= $b['used_days'] ?></td>
              <td><?= $b['carry_forward'] ?></td>
              <td><strong><?= $b['available'] ?></strong></td>
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
