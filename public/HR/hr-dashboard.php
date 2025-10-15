<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch HR info
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Restrict access to HR only
if ($user['position'] !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

// --- Stats Cards ---
$stats = [
    'total_requests' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];

try {
    $sql = "SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected
            FROM leave_requests";
    $stmt = $pdo->query($sql);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // fallback
}

// --- Filters ---
$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$where = [];
$params = [];

if ($statusFilter) {
    $where[] = "lr.status = :status";
    $params[':status'] = $statusFilter;
}
if ($typeFilter) {
    $where[] = "lt.id = :type";
    $params[':type'] = $typeFilter;
}
if ($startDate && $endDate) {
    $where[] = "lr.start_date BETWEEN :start AND :end";
    $params[':start'] = $startDate;
    $params[':end'] = $endDate;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Fetch All Leave Requests ---
$sql = "
SELECT lr.id, u.name AS employee_name, u.position, lt.name AS leave_type,
       lr.start_date, lr.end_date, lr.reason, lr.status, lr.applied_at
FROM leave_requests lr
JOIN users u ON lr.user_id = u.id
LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
$whereSQL
ORDER BY lr.applied_at DESC
LIMIT 5
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Leave Types for filter ---
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- Handle Approve/Reject ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $req_id = $_POST['request_id'];
    $action = $_POST['action'];

    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
    $sql = "UPDATE leave_requests 
            SET status = :status, approved_by = :hr, decision_date = NOW() 
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status' => $newStatus,
        ':hr' => $user_id,
        ':id' => $req_id
    ]);

    header("Location: hr-dashboard.php?msg=" . urlencode("Leave request $newStatus successfully!"));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>HR Dashboard | LMS</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
    .stat-card { background: #f8fafc; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    .stat-card h3 { margin: 0; font-size: 2em; color: #2563eb; }
    .stat-card p { color: #475569; margin-top: 6px; }

    .filter-grid { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; }
    .filter-grid select, .filter-grid input[type="date"] { padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
    .btn-approve { background: #16a34a; color: #fff; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; }
    .btn-reject { background: #dc2626; color: #fff; border: none; padding: 6px 10px; border-radius: 6px; cursor: pointer; }
  </style>
</head>
<body>
<div class="layout">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="user-profile">
            <h2>LMS</h2>
            <div class="avatar"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
            <p class="user-name"><?php echo htmlspecialchars($user['name']); ?></p>
        </div>
        <nav>
            <ul>
                <li><a href="hr-dashboard.php" class="active">Dashboard</a></li>
                <li><a href="leave-types.php">Leave Types</a></li>
                <li><a href="tenure-policy.php">Tenure Policy</a></li>
                <li><a href="../../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">&copy; <?php echo date('Y'); ?> Teraju LMS</div>
    </aside>

    <!-- Header -->
    <header>
        <h1>HR Dashboard</h1>
    </header>

    <!-- Main -->
    <main class="main-content">
        <div class="card">
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card"><h3><?= $stats['total'] ?? 0 ?></h3><p>Total Requests</p></div>
                <div class="stat-card"><h3><?= $stats['approved'] ?? 0 ?></h3><p>Approved</p></div>
                <div class="stat-card"><h3><?= $stats['pending'] ?? 0 ?></h3><p>Pending</p></div>
                <div class="stat-card"><h3><?= $stats['rejected'] ?? 0 ?></h3><p>Rejected</p></div>
            </div>
<hr><br>
<h2>Recent Leave Request</h2>
            <!-- Leave Table -->
            <table class="leave-table">
            
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Position</th>
                        <th>Leave Type</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="8" style="text-align:center;">No leave records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($requests as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= htmlspecialchars($r['employee_name']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($r['position'])) ?></td>
                                <td><?= htmlspecialchars($r['leave_type'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($r['start_date']) ?></td>
                                <td><?= htmlspecialchars($r['end_date']) ?></td>
                                <td class="status <?= strtolower($r['status']) ?>">
                                    <?= ucfirst($r['status']) ?>
                                </td>
                                <td>
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <button type="submit" name="action" value="approve" class="btn-approve">Approve</button>
                                            <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <em>-</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
