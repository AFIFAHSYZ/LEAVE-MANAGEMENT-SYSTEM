<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

require_once '../../config/conn1.php';

// Basic auth check
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Verify HR
try {
    $stmt = $pdo->prepare("SELECT position FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentUser || $currentUser['position'] !== 'hr') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error (auth)']);
    exit;
}

// Validate inputs
$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$leave_id = isset($_POST['leave_id']) ? (int)$_POST['leave_id'] : 0;
$entitled_days = isset($_POST['entitled_days']) ? (int)$_POST['entitled_days'] : null;
$used_days = isset($_POST['used_days']) ? (int)$_POST['used_days'] : null;
$carry_forward = isset($_POST['carry_forward']) ? $_POST['carry_forward'] : null; // may be absent for non-annual

if ($user_id <= 0 || $leave_id <= 0 || $entitled_days === null || $used_days === null) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Fetch leave type to decide carry logic
try {
    $stmt = $pdo->prepare("SELECT name FROM leave_types WHERE id = :id");
    $stmt->execute([':id' => $leave_id]);
    $lt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lt) {
        echo json_encode(['success' => false, 'message' => 'Leave type not found']);
        exit;
    }
    $isAnnual = (strtolower($lt['name']) === 'annual leave');
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error (leave type)']);
    exit;
}

// Normalize values
$entitled_days = max(0, $entitled_days);
$used_days = max(0, $used_days);

// Carry forward logic
if ($isAnnual) {
    if ($carry_forward === null || $carry_forward === '') {
        // If missing, treat as 0 or keep existing value; here we treat as 0 to be explicit
        $carry_forward = 0;
    }
    $carry_forward = (int)$carry_forward;
    $carry_forward = max(0, min(5, $carry_forward)); // cap at 5
} else {
    // For non-annual, ignore the provided carry_forward and keep what’s already stored (or set to 0)
    try {
        $stmt = $pdo->prepare("
            SELECT carry_forward
            FROM leave_balances
            WHERE user_id = :user_id AND leave_type_id = :leave_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id, ':leave_id' => $leave_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $carry_forward = $existing ? (int)$existing['carry_forward'] : 0;
    } catch (Throwable $e) {
        $carry_forward = 0;
    }
}

// Compute total_available safely
$total_available = max(0, $entitled_days + $carry_forward - $used_days);

// Update DB
try {
    $stmt = $pdo->prepare("
        UPDATE leave_balances
        SET entitled_days = :entitled_days,
            used_days = :used_days,
            carry_forward = :carry_forward,
            total_available = :total_available
        WHERE user_id = :user_id AND leave_type_id = :leave_id
    ");
    $stmt->execute([
        ':entitled_days'   => $entitled_days,
        ':used_days'       => $used_days,
        ':carry_forward'   => $carry_forward,
        ':total_available' => $total_available,
        ':user_id'         => $user_id,
        ':leave_id'        => $leave_id
    ]);

    echo json_encode([
        'success' => true,
        'entitled_days' => $entitled_days,
        'used_days' => $used_days,
        'carry_forward' => $carry_forward,
        'total_available' => $total_available
    ]);
    exit;
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to update balance']);
    exit;
}