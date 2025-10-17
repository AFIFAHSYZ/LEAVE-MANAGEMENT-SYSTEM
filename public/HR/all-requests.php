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

// Filters
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$search = trim($_GET['search'] ?? ''); // ✅ New search filter

$sql = "SELECT lr.id, u.name AS employee, u.email, lt.name AS leave_type, lr.start_date, lr.end_date, lr.status, lr.applied_at
        FROM leave_requests lr
        LEFT JOIN users u ON lr.user_id = u.id
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        WHERE 1=1";

$params = [];

// Apply filters
if ($status) {
    $sql .= " AND lr.status = :status";
    $params[':status'] = $status;
}
if ($type) {
    $sql .= " AND lr.leave_type_id = :type";
    $params[':type'] = $type;
}
if ($search) {
    $sql .= " AND (LOWER(u.name) LIKE LOWER(:search) OR LOWER(u.email) LIKE LOWER(:search))";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY lr.applied_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch leave types for filter dropdown
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>All Leave Requests | HR Dashboard</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <style>    
    .filter-form { display: flex;flex-wrap: wrap;align-items: center;gap: 10px; margin-bottom: 15px;}
    .filter-form input, .filter-form select {padding: 8px; border: 1px solid #ccc; border-radius: 6px;}
    .filter-form button {background: #007bff;color: white;border: none;padding: 8px 14px;border-radius: 6px;cursor: pointer;transition: background 0.2s;}
    .filter-form button:hover {background: #0056b3;}
  </style>
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>
  <header><h1>All Leave Requests</h1></header>

  <main class="main-content">
    <div class="card">
      <h2>Leave Requests</h2>
      <p>Review and manage all leave requests</p>
      <hr><br>

      <!-- ✅ Added Search Field -->
      <form method="GET" class="filter-form">
        <input type="text" name="search" placeholder="Search by name or email" value="<?= htmlspecialchars($search) ?>">

        <select name="status">
          <option value="">All Status</option>
          <option value="pending" <?= $status=='pending'?'selected':'' ?>>Pending</option>
          <option value="approved" <?= $status=='approved'?'selected':'' ?>>Approved</option>
          <option value="rejected" <?= $status=='rejected'?'selected':'' ?>>Rejected</option>
        </select>

        <select name="type">
          <option value="">All Types</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $type==$t['id']?'selected':'' ?>>
              <?= htmlspecialchars($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button type="submit" class="btn-submit">Filter</button>
      </form>

      <table class="leave-table" style="margin-top:15px;">
        <thead>
          <tr>
            <th>ID</th>
            <th>Employee</th>
            <th>Email</th> <!-- ✅ Added Email Column -->
            <th>Leave Type</th>
            <th>Start</th>
            <th>End</th>
            <th>Status</th>
            <th>Applied</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td><?= htmlspecialchars($r['employee']) ?></td>
              <td><?= htmlspecialchars($r['email']) ?></td>
              <td><?= htmlspecialchars($r['leave_type']) ?></td>
              <td><?= $r['start_date'] ?></td>
              <td><?= $r['end_date'] ?></td>
              <td class="status <?= strtolower($r['status']); ?>"><?= ucfirst($r['status']); ?></td>
              <td><?= date('Y-m-d', strtotime($r['applied_at'])) ?></td>
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
