<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// ✅ Fetch manager info
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Only allow manager or HR
if (!in_array($user['position'], ['manager', 'hr'])) {
    header("Location: ../../unauthorized.php");
    exit();
}

// 🧾 Handle filters
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

// 🧮 Fetch leave requests from employees
$sql = "
SELECT lr.id, u.name AS employee_name, lt.name AS leave_type,
       lr.start_date, lr.end_date, lr.reason, lr.status, lr.applied_at
FROM leave_requests lr
JOIN users u ON lr.user_id = u.id
LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
$whereSQL
ORDER BY lr.applied_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🗂 Fetch leave types for filters
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// 🟢 Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $req_id = $_POST['request_id'];
    $action = $_POST['action'];

    $newStatus = ($action === 'approve') ? 'approved' : 'rejected';
    $sql = "UPDATE leave_requests SET status = :status, approved_by = :manager, decision_date = NOW() WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status' => $newStatus,
        ':manager' => $user_id,
        ':id' => $req_id
    ]);

    header("Location: team-leaves.php?msg=" . urlencode("Leave request $newStatus successfully!"));
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Team Leaves | Manager Dashboard</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>    
    .filter-form {
        display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;
        align-items: flex-end; background: #fff; padding: 15px;
        border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.05);
    }
    .filter-form label { display: block; font-size: 0.9rem; color: #334155; margin-bottom: 5px; }
    .filter-form input, .filter-form select {
        padding: 8px 10px; border-radius: 8px; border: 1px solid #cbd5e1;
        font-size: 0.9rem; width: 150px;
    }
    .filter-form button {
        padding: 9px 16px; background: #3b82f6; border: none; border-radius: 8px;
        color: #fff; font-weight: 600; cursor: pointer;
    }
    .filter-form button:hover { background: #2563eb; }

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
                <li><a href="manager-dashboard.php">Dashboard</a></li>
                <li><a href="team-leaves.php" class="active">Team Leaves</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">&copy; <?php echo date('Y'); ?> Teraju LMS</div>
    </aside>
        <header>
        <h1>Team Leave Requests</h1>
    </header>


    <!-- Main -->
    <main class="main-content">
        <div class="card">
            <h2>Leave Management System</h2>

            <!-- Filters -->
            <form method="GET" class="filter-form">
                <div class="filter-grid">
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
                        <option value="approved" <?= $statusFilter==='approved'?'selected':'' ?>>Approved</option>
                        <option value="rejected" <?= $statusFilter==='rejected'?'selected':'' ?>>Rejected</option>
                    </select>

                    <select name="type">
                        <option value="">All Leave Types</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $typeFilter==$t['id']?'selected':'' ?>>
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">

                    <button type="submit" >Filter</button>
                </div>
            </form>

            <!-- Table -->
            <table class="leave-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="7" style="text-align:center;">No records found</td></tr>
                    <?php else: ?>
                        <?php foreach ($requests as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($r['employee_name']) ?></td>
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
