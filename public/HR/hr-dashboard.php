<?php
session_start();
require_once '../../config/conn1.php';

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
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];

try {
    $sql = "
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected
        FROM leave_requests
    ";
    $stmt = $pdo->query($sql);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // fallback on error
}

// --- Fetch Recent Leave Requests ---
$sql = "
    SELECT lr.id, u.name AS employee_name, u.position,
        lt.name AS leave_type, lr.start_date, lr.end_date,
        lr.status, lr.applied_at
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    ORDER BY lr.applied_at DESC
    LIMIT 5
";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HR Dashboard | LMS</title>
<link rel="stylesheet" href="../../assets/css/style.css">

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    .stat-card {
        background: #f8fafc;
        border-radius: 12px;
        padding: 15px;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .stat-card h3 {
        margin: 0;
        font-size: 2em;
        color: #3a4750;
    }
    .stat-card p {
        color: #475569;
        margin-top: 6px;
    }

    /* Compact Table */
    .leave-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        margin-top: 10px;
    }
    .leave-table th, .leave-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
    }
    .leave-table th {
        background: #f3f4f6;
        font-weight: 600;
    }
    .leave-table tr:nth-child(even) {
        background: #fafafa;
    }

</style>
</head>
<body>
<div class="layout">

<?php include 'sidebar.php'; ?>

<header>
    <h1>HR Dashboard</h1>
</header>

<main class="main-content">
    <div class="card">

        <!-- Stats Section -->
        <div class="stats-grid">
            <div class="stat-card"><h3><?= $stats['total'] ?></h3><p>Total Requests</p></div>
            <div class="stat-card"><h3><?= $stats['approved'] ?></h3><p>Approved</p></div>
            <div class="stat-card"><h3><?= $stats['pending'] ?></h3><p>Pending</p></div>
            <div class="stat-card"><h3><?= $stats['rejected'] ?></h3><p>Rejected</p></div>
        </div>

        <hr><br>
        <h2>Recent Leave Requests</h2>

        <!-- Requests Table -->
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
                    <tr>
                        <td colspan="8" style="text-align:center;">No leave records found</td>
                    </tr>
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
                                <a href="verify-leave.php?id=<?= $r['id'] ?>"             
                                        style="background:#3a4750;color:white;padding:6px 10px;border-radius:5px;text-decoration:none;">Review</a>
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
