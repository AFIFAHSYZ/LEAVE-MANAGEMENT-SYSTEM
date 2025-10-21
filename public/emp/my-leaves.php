<?php
session_start();
require_once '../../config/conn.php';
require_once '../function.php';


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

// =====================
// Fetch leave stats per type
// =====================
$stats_sql = "
    SELECT 
        lt.id,
        lt.name AS leave_type,
        lt.default_limit,
        COALESCE(lb.carry_forward, 0) AS carry_forward,
        COALESCE(lb.used_days, 0) AS used_days,
        (lt.default_limit + COALESCE(lb.carry_forward, 0) - COALESCE(lb.used_days, 0)) AS remaining_days
    FROM leave_types lt
    LEFT JOIN leave_balances lb 
        ON lb.leave_type_id = lt.id 
        AND lb.user_id = :user_id 
        AND lb.year = EXTRACT(YEAR FROM CURRENT_DATE)
    ORDER BY lt.id ASC";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->bindParam(':user_id', $user_id);
$stats_stmt->execute();
$leave_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);
// Loop through all leave types for this user
$stmt = $pdo->query("SELECT id FROM leave_types");
$leave_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($leave_types as $leave_type_id) {
    $entitled = calculateEntitledDays($pdo, $user_id, $leave_type_id);

    // Upsert leave balance for current year
// Check if balance exists
$stmt = $pdo->prepare("
    SELECT id FROM leave_balances 
    WHERE user_id = :user AND leave_type_id = :type AND year = EXTRACT(YEAR FROM CURRENT_DATE)
");
$stmt->execute([
    ':user' => $user_id,
    ':type' => $leave_type_id
]);
$exists = $stmt->fetchColumn();

if ($exists) {
    // Update
    $stmt = $pdo->prepare("
        UPDATE leave_balances
        SET entitled_days = :entitled
        WHERE id = :id
    ");
    $stmt->execute([
        ':entitled' => $entitled,
        ':id' => $exists
    ]);
} else {
    // Insert
    $stmt = $pdo->prepare("
        INSERT INTO leave_balances (user_id, leave_type_id, year, entitled_days, carry_forward, used_days)
        VALUES (:user, :type, EXTRACT(YEAR FROM CURRENT_DATE), :entitled, 0, 0)
    ");
    $stmt->execute([
        ':user' => $user_id,
        ':type' => $leave_type_id,
        ':entitled' => $entitled
    ]);
}
}

// =====================
// Handle Filters
// =====================
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_start = $_GET['start'] ?? '';
$filter_end = $_GET['end'] ?? '';

$where = ["lr.user_id = :user_id"];
$params = ['user_id' => $user_id];

if (!empty($filter_type)) {
    $where[] = "lr.leave_type_id = :type";
    $params['type'] = $filter_type;
}

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

// =====================
// Fetch leave requests
// =====================
$sql = "
    SELECT lr.id, lr.start_date, lr.end_date, lr.reason, lr.status, lr.applied_at, lt.name AS leave_type
    FROM leave_requests lr
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE $where_sql
    ORDER BY lr.applied_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Leaves | Teraju LMS</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
  <style>
    .stat-cards { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px; }
    .stat-card {
        flex: 1 1 220px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 6px 15px rgba(0,0,0,0.05);
        padding: 20px;
        text-align: center;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    .stat-card h3 { color: #1f3b4d; font-size: 1.1rem; margin-bottom: 10px; }
    .stat-card .numbers { font-size: 1.4rem; font-weight: bold; color: #3b82f6; }
    .stat-card p { margin: 5px 0; color: #64748b; font-size: 0.95rem; }
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
                <li><a href="emp-dashboard.php">Dashboard</a></li>
                <li><a href="apply-leave.php">Apply Leave</a></li>
                <li><a href="my-leaves.php" class="active">My Leaves</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">&copy; <?php echo date('Y'); ?> Teraju LMS</div>
    </aside>

    <header>
        <h1>My Leave Records</h1>
    </header>

    <main class="main-content">
        <!-- Leave Statistics -->
        <div class="stat-cards">
            <?php foreach ($leave_stats as $stat): ?>
                <div class="stat-card">
                    <h3><?php echo htmlspecialchars($stat['leave_type']); ?></h3>
<div class="numbers"
    style="color: <?= ($stat['remaining_days'] <= 3) ? '#ef4444' : '#3b82f6'; ?>">
    <?= (int)$stat['remaining_days']; ?> / <?= (int)$stat['default_limit'] + (int)$stat['carry_forward']; ?> Days
</div>
<p>
    Used: <?= (int)$stat['used_days']; ?> days
    <?php if ($stat['carry_forward'] > 0): ?>
        <br><small style="color:#64748b;">(Includes <?= (int)$stat['carry_forward']; ?> carried forward)</small>
    <?php endif; ?>
</p>
                </div>
            <?php endforeach; ?>
        </div>


        <!-- Leave History -->
        <div class="card">
            <h2>Leave History</h2>
            <p>Track all your submitted leave applications</p>
        <!-- Filter Form -->
        <form method="GET" class="filter-form">
            <div>
                <label for="start">Start Date</label>
                <input type="date" id="start" name="start" value="<?= htmlspecialchars($filter_start) ?>">
            </div>
            <div>
                <label for="end">End Date</label>
                <input type="date" id="end" name="end" value="<?= htmlspecialchars($filter_end) ?>">
            </div>
            <div>
                <label for="type">Leave Type</label>
                <select id="type" name="type">
                    <option value="">All</option>
                    <?php foreach ($leave_stats as $lt): ?>
                        <option value="<?= $lt['id']; ?>" <?= ($filter_type == $lt['id']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($lt['leave_type']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="">All</option>
                    <option value="pending" <?= ($filter_status == 'pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?= ($filter_status == 'approved') ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?= ($filter_status == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            <button type="submit">Filter</button>
        </form>

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
                                <td><?= $index + 1; ?></td>
                                <td><?= htmlspecialchars($leave['leave_type'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($leave['start_date']); ?></td>
                                <td><?= htmlspecialchars($leave['end_date']); ?></td>
                                <td><?= htmlspecialchars($leave['reason'] ?: '-'); ?></td>
                                <td class="status <?= strtolower($leave['status']); ?>">
                                    <?= ucfirst($leave['status']); ?>
                                </td>
                                <td><?= date('Y-m-d', strtotime($leave['applied_at'])); ?></td>
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
