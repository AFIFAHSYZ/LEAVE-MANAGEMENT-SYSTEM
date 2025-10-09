<?php
session_start();
require_once '../../config/conn.php';
$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
 } catch (PDOException $e) {
    echo "Database error: " . htmlspecialchars($e->getMessage());
}

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['position'] !== 'employee') {
    header("Location: login.php");
    exit;
}

// 1. Query for Leave Balance (sum remaining for all leave types, or just annual if you want)
try {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(lt.annual_limit - lb.used_days), 0) AS leaveBalance
         FROM leave_balances lb
         JOIN leave_types lt ON lb.leave_type_id = lt.id
         WHERE lb.user_id = ? AND lb.year = EXTRACT(YEAR FROM CURRENT_DATE)"
    );
    $stmt->execute([$user_id]);
    $leaveBalance = $stmt->fetchColumn();
} catch (PDOException $e) {
    $leaveBalance = 0;
}

// 2. Query for Pending Requests Count
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = 'pending'"
    );
    $stmt->execute([$user_id]);
    $pendingRequests = $stmt->fetchColumn();
} catch (PDOException $e) {
    $pendingRequests = 0;
}

// 3. Query for Approved Leaves Count
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM leave_requests WHERE user_id = ? AND status = 'approved'"
    );
    $stmt->execute([$user_id]);
    $approvedLeaves = $stmt->fetchColumn();
} catch (PDOException $e) {
    $approvedLeaves = 0;
}

// 4. Query for Recent Leave Applications (last 3)
try {
    $stmt = $pdo->prepare(
        "SELECT lt.name AS type, lr.start_date AS start, lr.end_date AS end, lr.status
         FROM leave_requests lr
         JOIN leave_types lt ON lr.leave_type_id = lt.id
         WHERE lr.user_id = ?
         ORDER BY lr.applied_at DESC
         LIMIT 3"
    );
    $stmt->execute([$user_id]);
    $recentLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recentLeaves = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard - Teraju LMS</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h2>LMS</h2>
        <nav>
            <ul>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="apply-leave.php">Apply Leave</a></li>
                <li><a href="my-leaves.php">My Leaves</a></li>
                <li><a href="profile.php">Profile</a></li>
                <li><a href="../../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            &copy; <?php echo date('Y'); ?> Teraju LMS
        </div>
    </aside>
    <div class="main-content">
        <header>
            <h1>Teraju Leave Management System</h1>
        </header>
        <div class="container">
            <h2>Welcome, <?php echo htmlspecialchars($user['name']); ?>!</h2> 
            <p>Here’s a summary of your leave activity.</p>

            <main>
                <div class="dashboard-cards">
                    <div class="card dashboard-card">
                        <h3>Leave Balance</h3>
                        <p><?php echo $leaveBalance; ?> days</p>
                    </div>
                    <div class="card dashboard-card">
                        <h3>Pending Requests</h3>
                        <p><?php echo $pendingRequests; ?></p>
                    </div>
                    <div class="card dashboard-card">
                        <h3>Approved Leaves</h3>
                        <p><?php echo $approvedLeaves; ?></p>
                    </div>
                </div>

                <div class="card">
                    <h2>Recent Leave Applications</h2>
                    <table class="leave-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recentLeaves as $leave): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($leave['type']); ?></td>
                                    <td><?php echo htmlspecialchars($leave['start']); ?></td>
                                    <td><?php echo htmlspecialchars($leave['end']); ?></td>
                                    <td class="status <?php echo strtolower($leave['status']); ?>"><?php echo htmlspecialchars($leave['status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>

            <?php require_once "../../includes/footer.php"; ?>
        </div>
    </div>
</div>
</body>
</html>