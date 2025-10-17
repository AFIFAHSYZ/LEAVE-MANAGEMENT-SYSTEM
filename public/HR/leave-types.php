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
    $name = trim($_POST['name'] ?? '');
    $limit = (int)($_POST['default_limit'] ?? 0);

    try {
        if ($action === 'add' && $name !== '') {
            $stmt = $pdo->prepare("INSERT INTO leave_types (name, default_limit) VALUES (:name, :limit)");
            $stmt->execute([':name' => $name, ':limit' => $limit]);
            $success = "Leave type added successfully.";
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("UPDATE leave_types SET name = :name, default_limit = :limit WHERE id = :id");
            $stmt->execute([':name' => $name, ':limit' => $limit, ':id' => $_POST['id']]);
            $success = "Leave type updated successfully.";
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM leave_types WHERE id = :id");
            $stmt->execute([':id' => $_POST['id']]);
            $success = "Leave type deleted.";
        }
    } catch (PDOException $e) {
        $error = "Database error occurred.";
    }
}

// Fetch all leave types
$types = $pdo->query("SELECT * FROM leave_types ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Leave Types | HR Dashboard</title>
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
</style>
</head>
<body>
<div class="layout">

    <!-- Sidebar -->
  <?php include 'sidebar.php'; ?>
    <!-- Header -->
    <header>
        <h1>Leave Management System</h1>
    </header>

    <!-- Main -->
    <main class="main-content">
        <div class="card">
            <h2>Manage Leave Types</h2>

            <?php if ($success): ?><div class="success-box"><?= htmlspecialchars($success) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="error-box"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="POST" class="form-inline">
                <input type="text" name="name" placeholder="Leave Type Name" required>
                <input type="number" name="default_limit" placeholder="Default Limit (days)" required>
                <button type="submit" name="action" value="add" class="btn-submit">Add Type</button>
            </form>

            <table class="leave-table" style="margin-top:20px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Leave Type</th>
                        <th>Default Limit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $t): ?>
                        <tr>
                            <td><?= $t['id'] ?></td>
                            <td><?= htmlspecialchars($t['name']) ?></td>
                            <td><?= $t['default_limit'] ?></td>
                            <td>
                                <form method="POST" class="form-inline">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <input type="text" name="name" value="<?= htmlspecialchars($t['name']) ?>" required>
                                    <input type="number" name="default_limit" value="<?= $t['default_limit'] ?>" required>
                                    <button type="submit" name="action" value="edit" class="btn"> Edit 💾</button>
                                    <button type="submit" name="action" value="delete" class="btn" onclick="return confirm('Delete this leave type?')"> Delete 🗑</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
<script src="../../assets/js/sidebar.js"></script> 
</body>
</html>
