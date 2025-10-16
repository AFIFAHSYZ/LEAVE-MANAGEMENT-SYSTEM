<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Verify HR role
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user['position'] !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

// Handle Add/Edit/Delete
$success = $error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';
    $leave_type_id = (int)($_POST['leave_type_id'] ?? 0);
    $min_years = (int)($_POST['min_years'] ?? 0);
    $max_years = ($_POST['max_years'] === '' ? null : (int)$_POST['max_years']);
    $days_per_year = (int)($_POST['days_per_year'] ?? 0);

    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO leave_tenure_policy (leave_type_id, min_years, max_years, days_per_year)
                                   VALUES (:lt, :min, :max, :days)");
            $stmt->execute([':lt' => $leave_type_id, ':min' => $min_years, ':max' => $max_years, ':days' => $days_per_year]);
            $success = "Policy added successfully.";
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM leave_tenure_policy WHERE id = :id");
            $stmt->execute([':id' => $_POST['id']]);
            $success = "Policy deleted successfully.";
        }
    } catch (PDOException $e) {
        $error = "Database error occurred.";
    }
}

// Fetch Leave Types and Policies
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT p.*, lt.name AS leave_type 
        FROM leave_tenure_policy p
        JOIN leave_types lt ON p.leave_type_id = lt.id
        ORDER BY lt.name, p.min_years";
$policies = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Tenure Policy | HR Dashboard</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <style>
    .form-inline {  display: flex;gap: 10px;align-items: center;flex-wrap: wrap;}
    .form-inline input {padding: 10px;border-radius: 8px;border: 1px solid #cbd5e1;font-size: 1rem;flex: 1;}
    .btn-submit {background: #3b82f6;color: white;border: none;padding: 10px 20px;border-radius: 8px;font-size: 1rem;cursor: pointer;transition: 0.3s;}
    .btn-submit:hover {background: #2563eb;}

    /* Table */
    .leave-table {width: 100%;border-collapse: collapse;margin-top: 1.5rem;font-size: 0.95rem;}
    .leave-table th,
    .leave-table td {padding: 12px 16px;border-bottom: 1px solid #e2e8f0;text-align: left;}
    .leave-table th {background-color: #f1f5f9;font-weight: 600;color: #1e293b;}
    .leave-table tr:hover {background-color: #f8fafc;}

    /* Buttons inside table */
    .btn {background: #3b82f6;color: white;border: none;padding: 8px 14px;border-radius: 6px;margin-right: 6px;cursor: pointer;font-size: 0.9rem;transition: 0.3s;}
    .btn:hover {background: #2563eb;}
    .btn[type="submit"][value="delete"],
    .btn-delete {background: #ef4444;}
    .btn[type="submit"][value="delete"]:hover,
    .btn-delete:hover {background: #dc2626;}

    /* Success & Error boxes */
    .success-box, .error-box {padding: 12px 16px;border-radius: 8px;margin-bottom: 15px;font-weight: 500;}
    .success-box {background: #dcfce7;color: #166534;border: 1px solid #bbf7d0;}
    .error-box {background: #fee2e2;color: #991b1b;border: 1px solid #fecaca;}
    .form-inline select {padding: 10px 14px;border-radius: 8px;border: 1px solid #cbd5e1;background-color: #ffffff; font-size: 1rem;color: #1e293b;appearance: none;outline: none;flex: 1;min-width: 200px;cursor: pointer;transition: border-color 0.2s, box-shadow 0.2s;}
    .form-inline select:hover {border-color: #3b82f6;}
    .form-inline select:focus {border-color: #2563eb;box-shadow: 0 0 0 3px rgba(59,130,246,0.2);}

    .form-inline select {background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%236b7280' viewBox='0 0 20 20'%3E%3Cpath d='M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.25 8.29a.75.75 0 01-.02-1.08z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;background-position: right 12px center;background-size: 18px;}

    /* Adjust spacing */
    .form-inline select,.form-inline input,.form-inline button { margin-top: 8px;}
</style>
</head>
<body>
<div class="layout">

    <aside class="sidebar">
        <div class="user-profile">
            <h2>LMS</h2>
            <div class="avatar"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
            <p class="user-name"><?php echo htmlspecialchars($user['name']); ?></p>
        </div>
        <nav>
            <ul>
                <li><a href="hr-dashboard.php">Dashboard</a></li>
                <li><a href="leave-types.php">Leave Types</a></li>
                <li><a href="tenure-policy.php" class="active">Tenure Policy</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">&copy; <?= date('Y'); ?> Teraju LMS</div>
    </aside>
    <header>
        <h1>Leave Management System</h1>
    </header>

    <main class="main-content">
        <div class="card">
            <h2>Manage Tenure-Based Leave Policies</h2>

            <?php if ($success): ?><div class="success-box"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error-box"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" class="form-inline">
                <select name="leave_type_id" required>
                    <option value="">Select Leave Type</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="min_years" placeholder="Min Years" required>
                <input type="number" name="max_years" placeholder="Max Years (optional)">
                <input type="number" name="days_per_year" placeholder="Days" required>
                <button type="submit" name="action" value="add" class="btn-submit">Add Policy</button>
            </form>

            <table class="leave-table" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th>Leave Type</th>
                        <th>Min Years</th>
                        <th>Max Years</th>
                        <th>Days</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($policies as $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($p['leave_type']) ?></td>
                            <td><?= $p['min_years'] ?></td>
                            <td><?= $p['max_years'] ?? '∞' ?></td>
                            <td><?= $p['days_per_year'] ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" name="action" value="delete" class="btn" onclick="return confirm('Delete this policy?')"> Delete 🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
