<?php
session_start();
require_once '../../config/conn.php';

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

// Filters
$search = trim($_GET['search'] ?? '');
$position = $_GET['position'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Base query
$sql = "SELECT id, name, email, position, date_joined 
        FROM users 
        WHERE position != 'manager'";
$params = [];

// Apply filters
if ($search) {
    $sql .= " AND (LOWER(name) LIKE LOWER(:search) OR LOWER(email) LIKE LOWER(:search))";
    $params[':search'] = "%$search%";
}
if ($position) {
    $sql .= " AND position = :position";
    $params[':position'] = $position;
}
if ($start_date) {
    $sql .= " AND date_joined >= :start_date";
    $params[':start_date'] = $start_date;
}
if ($end_date) {
    $sql .= " AND date_joined <= :end_date";
    $params[':end_date'] = $end_date;
}

$sql .= " ORDER BY id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Employees | HR Dashboard</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <style>
    .sidebar ul li {position: relative;}
    .sidebar ul li a {display: flex; justify-content: space-between; align-items: center; padding: 10px 20px; font-size: 0.9rem; color: #fff; text-decoration: none;}
    .sidebar ul li a .arrow {font-size: 0.8rem; transition: transform 0.3s ease;}
    .sidebar ul li.active > a .arrow {transform: rotate(180deg);}
    .sidebar ul li .dropdown-menu {display: none; flex-direction: column; background: #1c2942; padding-left: 0;}
    .sidebar ul li.active .dropdown-menu {display: flex;}
    .sidebar ul li .dropdown-menu li a {padding: 8px 30px; font-size: 0.85rem;}
    .filter-form {display: flex;flex-wrap: wrap;align-items: center;gap: 10px;margin-bottom: 15px;}
    .filter-form input, .filter-form select {padding: 8px;border: 1px solid #ccc;border-radius: 6px;}
    .filter-form button {background: #007bff;color: white;border: none;padding: 8px 14px;border-radius: 6px;cursor: pointer;transition: background 0.2s;}
    .filter-form button:hover {background: #0056b3;}
    .btn-view {background: #2563eb;color: #fff;padding: 6px 12px;border-radius: 6px;text-decoration: none;font-size: 0.85rem;transition: background 0.2s ease;}
    .btn-view:hover { background: #1d4ed8; }
  </style>
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>

<header><h1>Leave Management System</h1></header>

<main class="main-content">
  <div class="card">
    <h2>All Employees</h2>
    <p>Filter and manage company employees</p><hr><br>

    <!-- 🔍 Filter Form -->
    <form method="GET" class="filter-form">
      <input type="text" name="search" placeholder="Search name or email" value="<?= htmlspecialchars($search) ?>">
      <select name="position">
        <option value="">All Positions</option>
        <option value="employee" <?= $position=='employee'?'selected':'' ?>>Employee</option>
        <option value="manager" <?= $position=='manager'?'selected':'' ?>>Manager</option>
        <option value="hr" <?= $position=='hr'?'selected':'' ?>>HR</option>
      </select>
      <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
      <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
      <button type="submit">Filter</button>
      <a href="employees.php" style="color:#555; text-decoration:none;">Reset</a>
    </form>

<table class="leave-table">
<thead>
  <tr>
    <th>No.</th>
    <th>Name</th>
    <th>Email</th>
    <th>Position</th>
    <th>Date Joined</th>
    <th>Action</th>
  </tr>
</thead>
<tbody>
  <?php $i = 1; foreach ($employees as $e): ?>
    <tr>
      <td><?= $i++ ?></td> <!-- Row number -->
      <td><?= htmlspecialchars($e['name']) ?></td>
      <td><?= htmlspecialchars($e['email']) ?></td>
      <td><?= ucfirst($e['position']) ?></td>
      <td><?= $e['date_joined'] ?></td>
      <td>
        <a href="employee-balances.php?id=<?= (int)$e['id'] ?>" class="btn-view">View Balances</a>
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
