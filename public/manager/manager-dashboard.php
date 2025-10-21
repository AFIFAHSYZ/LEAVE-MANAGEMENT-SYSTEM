<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if the user is a manager
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user['position'] !== 'manager') {
    header("Location: ../../unauthorized.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['leave_id'])) {
    $action = $_POST['action'];
    $leave_id = $_POST['leave_id'];

    if (in_array($action, ['approved', 'rejected'])) {
        $stmt = $pdo->prepare("
            UPDATE leave_requests
            SET status = :status, approved_by = :manager, decision_date = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $action,
            ':manager' => $user_id,
            ':id' => $leave_id
        ]);
    }
}

$stats = $pdo->query("
    SELECT 
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected
    FROM leave_requests
")->fetch(PDO::FETCH_ASSOC);

$filter_status = $_GET['status'] ?? '';
$filter_start = $_GET['start'] ?? '';
$filter_end = $_GET['end'] ?? '';

$where = ['1=1'];
$params = [];

if (!empty($filter_status)) {
    $where[] = "lr.status = :status";
    $params['status'] = $filter_status;
}
if (!empty($filter_start)) {
    $where[] = "lr.start_date >= :start";
    $params['start'] = $filter_start;
}
if (!empty($filter_end)) {
    $where[] = "lr.end_date <= :end";
    $params['end'] = $filter_end;
}

$where_sql = implode(' AND ', $where);

$sql = "
    SELECT lr.*, u.name AS employee_name, lt.name AS leave_type
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE $where_sql
    ORDER BY lr.applied_at DESC
    LIMIT 2
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);


// 🔔 Check for pending requests
$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
$pendingCount = $pendingCountStmt->fetchColumn();


?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manager Dashboard | Teraju LMS</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
  <style>
    .dashboard-stats { display:flex; gap:20px; margin-bottom:30px; flex-wrap:wrap; }
    .stat-box { flex:1 1 200px; background:#fff; border-radius:12px; padding:20px; text-align:center; box-shadow:0 5px 15px rgba(0,0,0,0.05); }
    .stat-box h3 { color:#1f3b4d; margin-bottom:5px; }
    .stat-box p { font-size:1.5rem; font-weight:700; }
    .stat-pending p { color:#f59e0b; }
    .stat-approved p { color:#10b981; }
    .stat-rejected p { color:#ef4444; }
    .filter-bar { background:#fff; border-radius:10px; padding:15px; display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px; box-shadow:0 3px 10px rgba(0,0,0,0.05); }
    .filter-bar input, .filter-bar select { padding:8px 10px; border-radius:8px; border:1px solid #cbd5e1; }
    .filter-bar button { padding:9px 15px; border:none; border-radius:8px; background:#3b82f6; color:#fff; font-weight:600; cursor:pointer; }
    .filter-bar button:hover { background:#2563eb; }
    .table-actions form { display:inline; margin:0 3px; }
    .btn-approve, .btn-reject { border:none; padding:6px 10px; border-radius:6px; color:#fff; cursor:pointer; font-size:0.85rem; }
    .btn-approve { background:#10b981; }
    .btn-reject { background:#ef4444; }
    .btn-approve:hover { background:#059669; }
    .btn-reject:hover { background:#dc2626; }
    .btn-review {background: #f59e0b;color: #fff;border: none;padding: 6px 10px;border-radius: 6px;text-decoration: none;font-size: 0.85rem; cursor: pointer;}
    .btn-review:hover {background: #d97706;}
    .alert-warning {background: #fff3cd;color: #856404;border: 1px solid #ffeeba;padding: 10px 15px;border-radius: 6px;margin-bottom: 15px;font-weight: 500;}
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
            <p style="font-size:0.85rem; color:#64748b;">Manager</p>
        </div>
        <nav>
            <ul>
                <li><a href="manager-dashboard.php" class="active">Dashboard</a></li>
                <li><a href="team-leaves.php">Team Leaves</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">&copy; <?php echo date('Y'); ?> Teraju LMS</div>
    </aside>

    <!-- Header -->
    <header>
        <h1>Manager Dashboard</h1>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <?php if ($pendingCount > 0): ?>
        <div class="alert alert-warning">
            ⚠️ There are <strong><?= $pendingCount ?></strong> pending leave request<?= $pendingCount > 1 ? 's' : '' ?> awaiting your action.
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="dashboard-stats">
            <div class="stat-box stat-pending">
                <h3>Pending Requests</h3>
                <p><?= $stats['pending'] ?? 0 ?></p>
            </div>
            <div class="stat-box stat-approved">
                <h3>Approved</h3>
                <p><?= $stats['approved'] ?? 0 ?></p>
            </div>
            <div class="stat-box stat-rejected">
                <h3>Rejected</h3>
                <p><?= $stats['rejected'] ?? 0 ?></p>
            </div>
        </div>

        <!-- Requests Table -->
        <div class="card">
            <h2>Recent Leave Requests</h2>
                    <!-- Filters -->
        <form method="GET" class="filter-bar">
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <option value="pending" <?= $filter_status == 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $filter_status == 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $filter_status == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div>
                <label>Start Date</label>
                <input type="date" name="start" value="<?= htmlspecialchars($filter_start) ?>">
            </div>
            <div>
                <label>End Date</label>
                <input type="date" name="end" value="<?= htmlspecialchars($filter_end) ?>">
            </div>
            <button type="submit">Filter</button>
        </form>

            <?php if (empty($requests)): ?>
                <p style="text-align:center; color:#64748b;">No leave requests found.</p>
            <?php else: ?>
                <table class="leave-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Leave Type</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Applied On</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $index => $r): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($r['employee_name']) ?></td>
                                <td><?= htmlspecialchars($r['leave_type']) ?></td>
                                <td><?= $r['start_date'] ?></td>
                                <td><?= $r['end_date'] ?></td>
                                <td><?= htmlspecialchars($r['reason'] ?: '-') ?></td>
                                <td class="status <?= strtolower($r['status']); ?>"><?= ucfirst($r['status']); ?></td>
                                <td><?= date('Y-m-d', strtotime($r['applied_at'])); ?></td>
                                <td class="table-actions">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="leave_id" value="<?= $r['id'] ?>">
                                        <a href="review-request.php?id=<?= $r['id'] ?>" class="btn-review">Review</a>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">—</span>
                                <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </main>
</div>

<footer>
  <p>&copy; <?= date('Y'); ?> Teraju HR System</p>
</footer>

</body>
</html>
