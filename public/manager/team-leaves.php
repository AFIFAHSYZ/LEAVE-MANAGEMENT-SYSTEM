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

// 🔔 Check for pending requests
$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
$pendingCount = $pendingCountStmt->fetchColumn();

// 🗂 Fetch leave types for filters
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
$req_id = $_POST['leave_id'];
    $action = $_POST['action'];

$newStatus = ($action === 'approved') ? 'approved' : 'rejected';
    $sql = "UPDATE leave_requests 
            SET status = :status, approved_by = :manager, decision_date = NOW() 
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status' => $newStatus,
        ':manager' => $user_id,
        ':id' => $req_id
    ]);

    // ✅ If approved, deduct from leave balance
    if ($newStatus === 'approved') {
        $q = $pdo->prepare("
            SELECT user_id, leave_type_id, start_date, end_date
            FROM leave_requests WHERE id = :id
        ");
        $q->execute([':id' => $req_id]);
        $req = $q->fetch(PDO::FETCH_ASSOC);

        if ($req) {
            $daysTaken = (strtotime($req['end_date']) - strtotime($req['start_date'])) / (60*60*24) + 1;

            // Exclude public holidays automatically (optional)
            $phStmt = $pdo->prepare("
                SELECT COUNT(*) FROM public_holidays 
                WHERE holiday_date BETWEEN :start AND :end
            ");
            $phStmt->execute([
                ':start' => $req['start_date'],
                ':end' => $req['end_date']
            ]);
            $holidays = $phStmt->fetchColumn();

            $effectiveDays = max(0, $daysTaken - $holidays);

            // Update leave balance
            $b = $pdo->prepare("
                SELECT id,  carry_forward, used_days
                FROM leave_balances
                WHERE user_id = :uid 
                  AND leave_type_id = :ltid
                  AND year = EXTRACT(YEAR FROM CURRENT_DATE)
            ");
            $b->execute([
                ':uid' => $req['user_id'],
                ':ltid' => $req['leave_type_id']
            ]);
            $balance = $b->fetch(PDO::FETCH_ASSOC);

            if ($balance) {
                $used = $balance['used_days'] + $effectiveDays;

                $u = $pdo->prepare("
                    UPDATE leave_balances
                    SET used_days = :used
                    WHERE id = :id
                ");
                $u->execute([
                    ':used' => $used,
                    ':id' => $balance['id']
                ]);
            }
        }
    }

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
    .filter-form {display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;align-items: flex-end; background: #fff; padding: 15px;border-radius: 10px; box-shadow: 0 3px 10px rgba(0,0,0,0.05); }
    .filter-form label { display: block; font-size: 0.9rem; color: #334155; margin-bottom: 5px; }
    .filter-form input, .filter-form select { padding: 8px 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.9rem; width: 150px; }
    .filter-form button {     padding: 9px 16px; background: #3b82f6; border: none; border-radius: 8px;  color: #fff; font-weight: 600; cursor: pointer; }
    .filter-form button:hover { background: #2563eb; }
    .table-actions form { display:inline; margin:0 3px; }
    .btn-approve, .btn-reject { border:none; padding:6px 10px; border-radius:6px; color:#fff; cursor:pointer; font-size:0.85rem; }
    .btn-approve { background:#10b981; }
    .btn-reject { background:#ef4444; }
    .btn-approve:hover { background:#059669; }
    .btn-reject:hover { background:#dc2626; }
    .btn-review {background: #f59e0b;color: #fff;border: none;padding: 6px 10px;border-radius: 6px;text-decoration: none;font-size: 0.85rem; cursor: pointer;}
    .btn-review:hover {background: #d97706;}

</style>
</head>
<body>
<div class="layout">

    <!-- Sidebar -->
  <?php include 'm-sidebar.php'; ?>


        <header>
        <h1>Leave Management System</h1>
    </header>
    <!-- Main -->
    <main class="main-content">
        <div class="card">
            <h2>Team Leave Requests</h2>

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
                                  </form>
                                <?php else: ?>
                                        <a href="review-request.php?id=<?= $r['id'] ?>" class="btn-review">Review</a>
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
<script src="../../assets/js/sidebar.js"></script> 
</body>
</html>
