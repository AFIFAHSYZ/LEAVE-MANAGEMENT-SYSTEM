<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user info
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch leave requests with leave type name
$sql = "SELECT lr.id, lr.start_date, lr.end_date, lr.reason, lr.status, lr.applied_at, lt.name AS leave_type
        FROM leave_requests lr
        LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
        WHERE lr.user_id = :user_id
        ORDER BY lr.applied_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Leaves | LMS</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
</head>
<body>

<div class="layout">

    <!-- Sidebar -->
    <aside class="sidebar">

<div class="user-profile">
    <h2>LMS</h2>

    <div class="avatar">
        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
    </div>
    <p class="user-name"><?php echo htmlspecialchars($user['name']); ?></p>
</div>

        <nav>
            <ul>
                <li><a href="emp-dashboard.php">Dashboard</a></li>
                <li><a href="apply-leave.php">Apply Leave</a></li>
                <li><a href="my-leaves.php" class="active">My Leaves</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>

        <div class="sidebar-footer">
            &copy; <?php echo date('Y'); ?> Teraju LMS
        </div>
    </aside>

    <header>
        <h1>My Leave Records</h1>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="card">
            <h2>Leave History</h2>
                    <p>Track all your submitted leave applications</p>


            <?php if (empty($leaves)): ?>
                <p style="text-align:center; color:#64748b;">No leave requests found.</p>
            <?php else: ?>
                <table class="leave-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Leave Type</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Applied On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaves as $index => $leave): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($leave['leave_type'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($leave['start_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['end_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['reason'] ?: '-'); ?></td>
                                <td class="status <?php echo strtolower($leave['status']); ?>">
                                    <?php echo ucfirst($leave['status']); ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($leave['applied_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</div>

<footer>
  <p>&copy; <?php echo date('Y'); ?> Teraju HR System</p>
</footer>

</body>
</html>
