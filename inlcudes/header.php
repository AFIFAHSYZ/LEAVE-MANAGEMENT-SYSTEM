<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Leave Management System</title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <header>
    <h2>Company Leave Management</h2>
    <nav>
      <a href="/public/dashboard.php">Dashboard</a>
      <a href="/public/apply_leave.php">Apply Leave</a>
      <a href="/public/view_requests.php">My Requests</a>
      <?php if ($_SESSION['role'] === 'hr' || $_SESSION['role'] === 'admin'): ?>
        <a href="/public/approve_leave.php">Approve Leaves</a>
      <?php endif; ?>
      <a href="/public/logout.php">Logout</a>
    </nav>
  </header>

  <main>
