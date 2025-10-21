<?php
session_start();
require_once '../../config/conn.php';

// Ensure user logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$id = $_GET['id'] ?? '';

if (!$id) {
    header("Location: manager-dashboard.php");
    exit();
}

// 🧩 Get Manager Info
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// 🧩 Get Leave Request Info
$stmt = $pdo->prepare("
    SELECT lr.*, u.name AS employee_name, lt.name AS leave_type, m.name AS approved_by_name
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    LEFT JOIN users m ON lr.approved_by = m.id
    WHERE lr.id = :id
");
$stmt->execute([':id' => $id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) die("Leave request not found.");

// Variables for display
$daysTaken = 0;
$holidays = 0;
$effectiveDays = 0;
$remainingBalance = null;

// Calculate days + balance for display
if ($request['start_date'] && $request['end_date']) {
    $daysTaken = (strtotime($request['end_date']) - strtotime($request['start_date'])) / 86400 + 1;

    // Public holidays between range
    $phStmt = $pdo->prepare("
        SELECT COUNT(*) FROM public_holidays 
        WHERE holiday_date BETWEEN :start AND :end
    ");
    $phStmt->execute([':start' => $request['start_date'], ':end' => $request['end_date']]);
    $holidays = $phStmt->fetchColumn();

    $effectiveDays = max(0, $daysTaken - $holidays);

    // Fetch balance
    $b = $pdo->prepare("
        SELECT carry_forward, used_days, entitled_days,
               (carry_forward + entitled_days - used_days) AS remaining
        FROM leave_balances
        WHERE user_id = :uid 
          AND leave_type_id = :ltid
          AND year = EXTRACT(YEAR FROM CURRENT_DATE)
    ");
    $b->execute([
        ':uid' => $request['user_id'],
        ':ltid' => $request['leave_type_id']
    ]);
    $balance = $b->fetch(PDO::FETCH_ASSOC);
    $remainingBalance = $balance ? $balance['remaining'] : null;
}

// 🟢 Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Prevent editing once approved/rejected
    if ($request['status'] !== 'pending') {
        header("Location: review-request.php?id=$id&msg=" . urlencode("Action not allowed. Leave request already processed."));
        exit();
    }

    $req_id = $_POST['leave_id'];
    $action = $_POST['action'];
    $newStatus = ($action === 'approved') ? 'approved' : 'rejected';

    $pdo->prepare("
        UPDATE leave_requests 
        SET status = :status, approved_by = :manager, decision_date = NOW() 
        WHERE id = :id
    ")->execute([
        ':status' => $newStatus,
        ':manager' => $user_id,
        ':id' => $req_id
    ]);

    // ✅ If approved, deduct from balance
    if ($newStatus === 'approved') {
        $q = $pdo->prepare("SELECT user_id, leave_type_id, start_date, end_date FROM leave_requests WHERE id = :id");
        $q->execute([':id' => $req_id]);
        $req = $q->fetch(PDO::FETCH_ASSOC);

        if ($req) {
            $daysTaken = (strtotime($req['end_date']) - strtotime($req['start_date'])) / 86400 + 1;
            $phStmt = $pdo->prepare("SELECT COUNT(*) FROM public_holidays WHERE holiday_date BETWEEN :start AND :end");
            $phStmt->execute([':start' => $req['start_date'], ':end' => $req['end_date']]);
            $holidays = $phStmt->fetchColumn();
            $effectiveDays = max(0, $daysTaken - $holidays);

            // Update balance
            $b = $pdo->prepare("
                SELECT id, used_days FROM leave_balances
                WHERE user_id = :uid AND leave_type_id = :ltid AND year = EXTRACT(YEAR FROM CURRENT_DATE)
            ");
            $b->execute([':uid' => $req['user_id'], ':ltid' => $req['leave_type_id']]);
            $balance = $b->fetch(PDO::FETCH_ASSOC);

            if ($balance) {
                $used = $balance['used_days'] + $effectiveDays;
                $pdo->prepare("UPDATE leave_balances SET used_days = :used WHERE id = :id")
                    ->execute([':used' => $used, ':id' => $balance['id']]);
            }
        }
    }

    header("Location: review-request.php?id=$req_id&msg=" . urlencode("Leave $newStatus successfully!"));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Review Leave Request</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
    .card {background: white; border-radius: 10px; padding: 1.5rem 2rem; box-shadow: 0 2px 10px rgba(0,0,0,0.08);}
    .info-grid {display: grid; grid-template-columns: 180px auto; row-gap: 10px; column-gap: 10px; font-size: 0.95rem;}
    .label {font-weight: 600; color: #475569;}
    .btn-approve, .btn-reject {border: none; padding: 8px 14px; border-radius: 8px; color: #fff; cursor: pointer; font-size: 0.9rem; margin-right: 10px;}
    .btn-approve {background: #10b981;} .btn-reject {background: #ef4444;}
    .btn-approve:hover {background: #059669;} .btn-reject:hover {background: #dc2626;}
    .back-link {display: inline-block; margin-bottom: 15px; text-decoration: none; color: #2563eb; font-weight: 600; background: #e0f2fe; padding: 6px 12px; border-radius: 6px;}
    .back-link:hover {background: #bfdbfe; color: #1e40af;}
    .status-badge {padding: 4px 10px; border-radius: 6px; font-weight: 600;}
    .note {margin-top: 10px; color: #475569; font-size: 0.9rem;}
</style>
<script>
function confirmAction(type) {
    return confirm(`Are you sure you want to ${type} this leave request?`);
}
</script>
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
                <li><a href="manager-dashboard.php" >Dashboard</a></li>
                <li><a href="team-leaves.php" class="active">Team Leaves</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">&copy; <?php echo date('Y'); ?> Teraju LMS</div>
    </aside>

    <!-- Main -->
    <main class="main-content">
        <header><h1>Leave Management System</h1></header>

        <a href="team-leaves.php" class="back-link">← Back to List</a>

        <div class="card">
            <h2>Review Leave Request</h2><hr><br>
            <div class="info-grid">
                <div class="label">Employee:</div><div><?= htmlspecialchars($request['employee_name']) ?></div>
                <div class="label">Type:</div><div><?= htmlspecialchars($request['leave_type']) ?></div>
                <div class="label">Start Date:</div><div><?= htmlspecialchars($request['start_date']) ?></div>
                <div class="label">End Date:</div><div><?= htmlspecialchars($request['end_date']) ?></div>
                <div class="label">Effective Days:</div><div><strong><?= $effectiveDays ?></strong> days deducted</div>
                <?php if ($remainingBalance !== null): ?>
                    <div class="label">Remaining Balance:</div><div><strong><?= $remainingBalance ?></strong> days</div>
                <?php endif; ?>
                <div class="label">Status:</div>
                <div>
                    <?php
                    $status = strtolower($request['status']);
                    $color = $status === 'approved' ? '#dcfce7' : ($status === 'rejected' ? '#fee2e2' : '#e0f2fe');
                    $text = $status === 'approved' ? '#15803d' : ($status === 'rejected' ? '#b91c1c' : '#1e40af');
                    ?>
                    <span class="status-badge" style="background:<?= $color ?>; color:<?= $text ?>;">
                        <?= ucfirst($status) ?>
                    </span>
                </div>
                <?php if ($request['approved_by_name']): ?>
                    <div class="label">Approved/Rejected By:</div>
                    <div><?= htmlspecialchars($request['approved_by_name']) ?> on <?= htmlspecialchars($request['decision_date']) ?></div>
                <?php endif; ?>
                <div class="label">Reason:</div><div><?= htmlspecialchars($request['reason'] ?: '-') ?></div>
            </div>
            <br>

            <?php if (strtolower($request['status']) === 'pending'): ?>
            <form method="POST" action="review-request.php?id=<?= $request['id'] ?>">
                <input type="hidden" name="leave_id" value="<?= $request['id'] ?>">
                <button type="submit" name="action" value="approved" class="btn-approve" onclick="return confirmAction('approve')">Approve</button>
                <button type="submit" name="action" value="rejected" class="btn-reject" onclick="return confirmAction('reject')">Reject</button>
            </form>
            <?php else: ?>
                <p class="note">✅ This leave request has already been <strong><?= htmlspecialchars($request['status']) ?></strong> and cannot be modified.</p>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
