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

// Fetch summary
$summary = $pdo->query("
    SELECT u.name, lt.name AS leave_type, COUNT(lr.id) AS total_requests,
           SUM(DATE_PART('day', lr.end_date::timestamp - lr.start_date::timestamp) + 1) AS total_days
    FROM leave_requests lr
    LEFT JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE EXTRACT(YEAR FROM lr.start_date) = EXTRACT(YEAR FROM CURRENT_DATE)
    GROUP BY u.name, lt.name
    ORDER BY u.name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reports | HR Dashboard</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>
  <header><h1>Reports & Analytics</h1></header>

  <main class="main-content">
    <div class="card">
      <h2>Yearly Leave Summary (<?= date('Y') ?>)</h2>

      <table class="leave-table">
        <thead>
          <tr><th>Employee</th><th>Leave Type</th><th>Requests</th><th>Total Days</th></tr>
        </thead>
        <tbody>
          <?php foreach ($summary as $s): ?>
            <tr>
              <td><?= htmlspecialchars($s['name']) ?></td>
              <td><?= htmlspecialchars($s['leave_type']) ?></td>
              <td><?= $s['total_requests'] ?></td>
              <td><?= $s['total_days'] ?></td>
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
