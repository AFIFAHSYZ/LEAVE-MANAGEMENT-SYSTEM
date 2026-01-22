<?php
session_start();
require_once '../../config/conn1.php';

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

// Pagination setup
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base query
$sql = "SELECT id, name, email, position, date_joined FROM users WHERE position != 'manager'";
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

// Count total rows for pagination
$countSql = "SELECT COUNT(*) FROM users WHERE position != 'manager'";
$countParams = $params;

if ($search) $countSql .= " AND (LOWER(name) LIKE LOWER(:search) OR LOWER(email) LIKE LOWER(:search))";
if ($position) $countSql .= " AND position = :position";
if ($start_date) $countSql .= " AND date_joined >= :start_date";
if ($end_date) $countSql .= " AND date_joined <= :end_date";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($countParams);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// Fetch paginated data
$sql .= " ORDER BY id ASC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employees | HR Dashboard</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
.filter-form {display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;}
.filter-form input, .filter-form select {padding: 8px; border: 1px solid #ccc; border-radius: 6px;}
.filter-form button {background: #3a4750; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; transition: 0.2s;}
.filter-form button:hover {background: #262e34ff;}
.btn-view {background: #3a4750; color: #fff; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.85rem; transition: 0.2s;}
.btn-view:hover {background: #262e34ff;}
.pagination {display: flex; justify-content: center; margin-top: 20px; gap: 6px; flex-wrap: wrap;}
.pagination a, .pagination span {padding: 8px 14px; border-radius: 8px; text-decoration: none; border: 1px solid #e2e8f0; background: #fff; color: #334155; font-size: 14px; transition: all 0.2s; min-width: 36px; text-align: center;}
.pagination a:hover {background: #2563eb; border-color: #2563eb; color: #fff;}
.pagination .active {background: #2563eb; color: #fff; border-color: #2563eb;}
.pagination .disabled {opacity: 0.4; pointer-events: none;}
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
        <?php $i = ($page-1)*$limit + 1; foreach ($employees as $e): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= htmlspecialchars($e['name']) ?></td>
          <td><?= htmlspecialchars($e['email']) ?></td>
          <td><?= ucfirst($e['position']) ?></td>
          <td><?= $e['date_joined'] ?></td>
          <td><a href="employee-balances.php?id=<?= (int)$e['id'] ?>" class="btn-view">View Balances</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <div class="pagination">
      <?php if($page > 1): ?>
        <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>&position=<?= $position ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>">Previous</a>
      <?php else: ?>
        <span class="disabled">Previous</span>
      <?php endif; ?>

      <?php for($p = 1; $p <= $totalPages; $p++): ?>
        <?php if($p == $page): ?>
          <span class="active"><?= $p ?></span>
        <?php else: ?>
          <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&position=<?= $position ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if($page < $totalPages): ?>
        <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>&position=<?= $position ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>">Next</a>
      <?php else: ?>
        <span class="disabled">Next</span>
      <?php endif; ?>
    </div>

  </div>
</main>
</div>
<script src="../../assets/js/sidebar.js"></script>
</body>
</html>
