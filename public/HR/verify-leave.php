<?php
session_start();
require_once '../../config/conn1.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Ensure HR
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->execute([':id'=>$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user['position'] !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

$id = $_GET['id'] ?? '';
if (!$id) {
    header("Location: hr-dashboard.php");
    exit();
}

// Handle verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $stmt = $pdo->prepare("SELECT lr.*, lt.name AS leave_type FROM leave_requests lr LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id WHERE lr.id = :id");
    $stmt->execute([':id'=>$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) die("Leave request not found.");

    $leaveType = strtolower($request['leave_type'] ?? '');
    $status = strtolower($request['status'] ?? '');

    if ($leaveType === 'annual leave' || $status === 'verified') {
        // Do nothing, just redirect
        header("Location: verify-leave.php?id=$id");
        exit();
    }

    // Update leave to verified
    $stmt = $pdo->prepare("UPDATE leave_requests SET status = 'verified', verified_by = :hr, verified_at = NOW() WHERE id = :id");
    $stmt->execute([':hr' => $user_id, ':id' => $id]);

    // After update, redirect to refresh the page
    header("Location: verify-leave.php?id=$id");
    exit();
}

// Fetch leave request info (always latest from DB)
$stmt = $pdo->prepare("
    SELECT lr.*, u.name AS employee_name, lt.name AS leave_type,
           h.name AS verified_by_name
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    LEFT JOIN users h ON lr.verified_by = h.id
    WHERE lr.id = :id
");
$stmt->execute([':id'=>$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) die("Leave request not found.");

// Calculate effective days
$daysTaken = $holidays = $effectiveDays = 0;
if ($request['start_date'] && $request['end_date']) {
    $daysTaken = (strtotime($request['end_date']) - strtotime($request['start_date'])) / 86400 + 1;
    $phStmt = $pdo->prepare("SELECT COUNT(*) FROM public_holidays WHERE holiday_date BETWEEN :start AND :end");
    $phStmt->execute([':start'=>$request['start_date'], ':end'=>$request['end_date']]);
    $holidays = $phStmt->fetchColumn();
    $effectiveDays = max(0, $daysTaken - $holidays);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify Leave Request</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
.card {background: white; border-radius: 10px; padding: 1.5rem 2rem; box-shadow: 0 2px 10px rgba(0,0,0,0.08);}
.info-grid {display: grid; grid-template-columns: 180px auto; row-gap: 10px; column-gap: 10px; font-size: 0.95rem;}
.label {font-weight: 600; color: #475569;}
.btn-verify {border:none; padding:8px 14px; border-radius:8px; color:#fff; cursor:pointer; background:#3b82f6;}
.btn-verify:hover {background:#2563eb;}
.back-link {display: inline-block; margin-bottom: 15px; text-decoration: none; color: #2563eb; font-weight: 600; background: #e0f2fe; padding: 6px 12px; border-radius: 6px;}
.back-link:hover {background: #bfdbfe; color: #1e40af;}
.status-badge {padding: 4px 10px; border-radius: 6px; font-weight: 600;}
.note {margin-top: 10px; color: #475569; font-size: 0.9rem;}
</style>
<script>
function confirmVerify() {
    return confirm("Are you sure you want to verify this leave?");
}
</script>
</head>
<body>
<div class="layout">
<?php include 'sidebar.php'; ?>
<main class="main-content">
    <header><h1>Leave Management System</h1></header>
    <a href="all-requests.php" class="back-link">← Back to List</a>

    <div class="card">
        <h2>Verify Leave Request</h2><hr><br>
        <div class="info-grid">
            <div class="label">Name:</div><div><?= htmlspecialchars($request['employee_name']) ?></div>
            <div class="label">Leave Type:</div><div><?= htmlspecialchars($request['leave_type']) ?></div>
            <div class="label">Start Date:</div><div><?= htmlspecialchars($request['start_date']) ?></div>
            <div class="label">End Date:</div><div><?= htmlspecialchars($request['end_date']) ?></div>
            <div class="label">Effective Days:</div><div><strong><?= $effectiveDays ?></strong></div>
            <div class="label">Status:</div>
            <div>
                <?php
                $status = strtolower($request['status']);
                $color = $status === 'verified' ? '#dcfce7' : '#e0f2fe';
                $text = $status === 'verified' ? '#15803d' : '#1e40af';
                ?>
                <span class="status-badge" style="background:<?= $color ?>; color:<?= $text ?>;"><?= ucfirst($status) ?></span>
            </div>
            <?php if($request['verified_by_name']): ?>
                <div class="label">Verified By:</div>
                <div><?= htmlspecialchars($request['verified_by_name']) ?> on <?= htmlspecialchars($request['verified_at']) ?></div>
            <?php endif; ?>
            <div class="label">Reason:</div><div><?= htmlspecialchars($request['reason'] ?: '-') ?></div>
        </div>
        <br>

        <?php
        $leaveType = strtolower($request['leave_type']);
        $status = strtolower($request['status']);
        ?>

        <?php if($leaveType !== 'annual leave' && $status !== 'verified'): ?>
            <form method="POST" action="verify-leave.php?id=<?= $request['id'] ?>">
                <input type="hidden" name="action" value="verify">
                <button type="submit" class="btn-verify" onclick="return confirmVerify()">Verify</button>
            </form>
        <?php else: ?>
            <p class="note">
                <?php if($leaveType === 'annual leave'): ?>
                    - Cannot verify annual leave
                <?php else: ?>
                    ✔ Leave already verified
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</main>
</div>
<script src="../../assets/js/sidebar.js"></script>

</body>
</html>
