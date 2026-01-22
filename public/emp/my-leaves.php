<?php
session_start();
require_once '../../config/conn1.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user info
$stmt = $pdo->prepare("SELECT id, name, position, date_joined FROM users WHERE id = :id");
$stmt->execute(['id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User not found");

function calculateEntitledDays($pdo, $user_id, $leave_type_id) {

    // 1. Fetch leave type info
    $stmt = $pdo->prepare("
        SELECT name, default_limit
        FROM leave_types
        WHERE id = :id
    ");
    $stmt->execute(['id' => $leave_type_id]);
    $lt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lt) return 0;

    $leave_name = strtolower($lt['name']);
    $default_limit = (float)$lt['default_limit'];

    $today = new DateTime();
    $current_year = (int)$today->format('Y');
    $current_month = (int)$today->format('n');

    // 2. Fixed leave types (Maternity, Emergency, Hospitalized)
    $fixed_leave_ids = [3, 4, 5]; // IDs from leave_types table
    if (in_array($leave_type_id, $fixed_leave_ids)) {
        return $default_limit;
    }

    // 3. Fetch join date
    $stmt = $pdo->prepare("SELECT date_joined FROM users WHERE id = :uid");
    $stmt->execute(['uid' => $user_id]);
    $join_date = $stmt->fetchColumn();
    if (!$join_date) return 0;

    $join = new DateTime($join_date);
    $join_year = (int)$join->format('Y');
    $join_month = (int)$join->format('n');
    $years = $join->diff($today)->y;

    // 4. Sick Leave (tenure-based)
    if ($leave_type_id == 2) { // Sick Leave
        $stmt = $pdo->prepare("
            SELECT days_per_year
            FROM leave_tenure_policy
            WHERE leave_type_id = :ltid
              AND min_years <= :yrs
              AND (max_years IS NULL OR max_years >= :yrs)
            ORDER BY min_years DESC
            LIMIT 1
        ");
        $stmt->execute(['ltid' => $leave_type_id, 'yrs' => $years]);
        $days_per_year = $stmt->fetchColumn();
        return $days_per_year ? (float)$days_per_year : 0;
    }

    // 5. Annual Leave (accrual-based)
    // Fetch tenure-based days per year
    $stmt = $pdo->prepare("
        SELECT days_per_year
        FROM leave_tenure_policy
        WHERE leave_type_id = :ltid
          AND min_years <= :yrs
          AND (max_years IS NULL OR max_years >= :yrs)
        ORDER BY min_years DESC
        LIMIT 1
    ");
    $stmt->execute(['ltid' => $leave_type_id, 'yrs' => $years]);
    $days_per_year = $stmt->fetchColumn();
    if (!$days_per_year) $days_per_year = $default_limit;

// Monthly accrual
$monthly_accrual = $days_per_year / 12;

// Months to accrue
if ($join->format('Y') < $today->format('Y')) {
    $months_elapsed = (int)$today->format('n'); // Jan = 1 → current month
} else {
    $join_month = (int)$join->format('n');
    $current_month = (int)$today->format('n');
    $months_elapsed = $current_month - $join_month + 1;
    if ($months_elapsed < 0) $months_elapsed = 0;
}

// Fetch carry forward
$carry = (float)$stmt->fetchColumn();

$entitlement = $carry + ($monthly_accrual * $months_elapsed);

    return round($entitlement, 2);
}

// ==============================
// Fetch leave types and sync balances
// ==============================
$leave_types = $pdo->query("SELECT id, name FROM leave_types")->fetchAll(PDO::FETCH_ASSOC);
$leave_stats = [];

foreach ($leave_types as $lt) {
    $entitled = calculateEntitledDays($pdo, $user_id, $lt['id']);

    // Fetch existing balance
    $stmt = $pdo->prepare("
        SELECT id, carry_forward, used_days
        FROM leave_balances
        WHERE user_id = :uid AND leave_type_id = :type AND year = EXTRACT(YEAR FROM CURRENT_DATE)
    ");
    $stmt->execute([':uid' => $user_id, ':type' => $lt['id']]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    $carry = $record['carry_forward'] ?? 0;
    $used = $record['used_days'] ?? 0;

    $total_available = $entitled + ($lt['name'] === 'Annual Leave' ? $carry : 0) - $used;

    if ($record) {
        $stmt = $pdo->prepare("
            UPDATE leave_balances
            SET entitled_days = :entitled, total_available = :total
            WHERE id = :id
        ");
        $stmt->execute([
            ':entitled' => $entitled,
            ':total' => $total_available,
            ':id' => $record['id']
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO leave_balances
            (user_id, leave_type_id, year, entitled_days, carry_forward, used_days, total_available)
            VALUES (:uid, :type, EXTRACT(YEAR FROM CURRENT_DATE), :entitled, 0, 0, :total)
        ");
        $stmt->execute([
            ':uid' => $user_id,
            ':type' => $lt['id'],
            ':entitled' => $entitled,
            ':total' => $total_available
        ]);
    }

    $leave_stats[] = [
        'id' => $lt['id'],
        'leave_type' => $lt['name'],
        'default_limit' => $entitled,
        'carry_forward' => $carry,
        'used_days' => $used,
        'remaining_days' => $total_available
    ];
}

// ==============================
// Leave filters
// ==============================
$filter_type   = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_start  = $_GET['start'] ?? '';
$filter_end    = $_GET['end'] ?? '';

$where = ["lr.user_id = :user_id"];
$params = ['user_id' => $user_id];

if (!empty($filter_type)) { $where[] = "lr.leave_type_id = :type"; $params['type'] = $filter_type; }
if (!empty($filter_status)) { $where[] = "lr.status = :status"; $params['status'] = $filter_status; }
if (!empty($filter_start)) { $where[] = "lr.start_date >= :start"; $params['start'] = $filter_start; }
if (!empty($filter_end)) { $where[] = "lr.end_date <= :end"; $params['end'] = $filter_end; }

$where_sql = implode(' AND ', $where);

// ==============================
// Fetch leave history
// ==============================
$sql = "
    SELECT lr.id, lr.start_date, lr.end_date, lr.reason, lr.status, lr.applied_at, lt.name AS leave_type
    FROM leave_requests lr
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE $where_sql
    ORDER BY lr.applied_at DESC
";
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
<style>
.stat-cards { display:flex; flex-wrap:wrap; gap:20px; margin-bottom:30px; }
.stat-card { flex:1 1 220px; background:#fff; border-radius:12px; box-shadow:0 6px 15px rgba(0,0,0,0.05); padding:20px; text-align:center; transition: transform 0.2s ease, box-shadow 0.2s ease; }
.stat-card:hover { transform:translateY(-3px); box-shadow:0 10px 25px rgba(0,0,0,0.08); }
.stat-card h3 { color:#1f3b4d; font-size:1.1rem; margin-bottom:10px; }
.stat-card .numbers { font-size:1.4rem; font-weight:bold; color:#3b82f6; }
.stat-card p { margin:5px 0; color:#64748b; font-size:0.95rem; }

.leave-table { width:100%; border-collapse:collapse; margin-top:20px; }
.leave-table th, .leave-table td { border:1px solid #ddd; padding:8px; text-align:left; }
.leave-table th { background:#f1f5f9; }
.status.pending { color:#f59e0b; font-weight:bold; }
.status.approved { color:#10b981; font-weight:bold; }
.status.rejected { color:#ef4444; font-weight:bold; }

.filter-form {display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px; align-items:flex-end; background:#fff; padding:15px;border-radius:10px; box-shadow:0 3px 10px rgba(0,0,0,0.05);}
.filter-form label { display:block; font-size:0.9rem; color:#334155; margin-bottom:5px; }
.filter-form input, .filter-form select {padding:8px 10px; border-radius:8px; border:1px solid #cbd5e1; font-size:0.9rem; width:150px; }
.filter-form button {padding:9px 16px; background:#3b82f6; border:none; border-radius:8px; color:#fff; font-weight:600; cursor:pointer;}
.filter-form button:hover { background:#2563eb; }
</style>
</head>
<body>
<div class="layout">
<?php include "emp-sidebar.php"; ?>

<header><h1>My Leave Records</h1></header>

<main class="main-content">

<!-- Stat Cards -->
<div class="stat-cards">
<?php foreach ($leave_stats as $stat): ?>
    <div class="stat-card">
        <h3><?= htmlspecialchars($stat['leave_type']); ?></h3>
        <div class="numbers" style="color:<?= ($stat['remaining_days'] <= 3) ? '#ef4444' : '#3b82f6'; ?>">
            <?= round($stat['remaining_days'], 2); ?> /
            <?= round($stat['default_limit'] + ($stat['leave_type'] === 'Annual Leave' ? $stat['carry_forward'] : 0), 2); ?> Days
        </div>
        <p>
            Used: <?= round($stat['used_days'], 2); ?> days
            <?php if ($stat['carry_forward'] > 0 && $stat['leave_type'] === 'Annual Leave'): ?>
                <br><small style="color:#64748b;">(Includes <?= $stat['carry_forward']; ?> carried forward)</small>
            <?php endif; ?>
        </p>
    </div>
<?php endforeach; ?>
</div>

<!-- Filters -->
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
    <div>
        <button type="submit">Filter</button>
    </div>
    <!-- Leave Table -->
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
                    <td class="status <?= strtolower($leave['status']); ?>"><?= ucfirst($leave['status']); ?></td>
                    <td><?= date('Y-m-d', strtotime($leave['applied_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</form>
</main>
</div>
<footer>
<p>&copy; <?= date('Y'); ?> Teraju HR System</p>
</footer>
</body>
</html>
