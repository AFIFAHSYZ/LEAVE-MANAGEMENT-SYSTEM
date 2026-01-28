<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once '../../config/conn1.php';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    fail('Not authenticated', 401);
}

// Verify HR
try {
    $stmt = $pdo->prepare("SELECT position FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser || ($currentUser['position'] ?? '') !== 'hr') {
        fail('Unauthorized', 403);
    }
} catch (Throwable $e) {
    fail('Server error (auth)', 500);
}

// Inputs
$user_id  = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$leave_id = isset($_POST['leave_id']) ? (int)$_POST['leave_id'] : 0;
$year     = isset($_POST['year']) ? (int)$_POST['year'] : 0;

$entitled_raw = $_POST['entitled_days'] ?? null;
$used_raw     = $_POST['used_days'] ?? null;
$carry_raw    = $_POST['carry_forward'] ?? null; // may be absent

if ($user_id <= 0 || $leave_id <= 0 || $year <= 0) {
    fail('Invalid parameters');
}
if ($entitled_raw === null || $entitled_raw === '' || !is_numeric($entitled_raw)) {
    fail('Entitled Days must be a number');
}
if ($used_raw === null || $used_raw === '' || !is_numeric($used_raw)) {
    fail('Used Days must be a number');
}

$entitled_days = (float)$entitled_raw;
$used_days     = (float)$used_raw;

if ($entitled_days < 0 || $used_days < 0) {
    fail('Values cannot be negative');
}

// Fetch leave type to decide carry logic
try {
    $stmt = $pdo->prepare("SELECT name FROM leave_types WHERE id = :id");
    $stmt->execute([':id' => $leave_id]);
    $lt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lt) {
        fail('Leave type not found', 404);
    }
    $isAnnual = (strtolower((string)$lt['name']) === 'annual leave');
} catch (Throwable $e) {
    fail('Server error (leave type)', 500);
}

// Carry forward
if ($isAnnual) {
    if ($carry_raw === null || $carry_raw === '') {
        $carry_forward = 0.0;
    } elseif (!is_numeric($carry_raw)) {
        fail('Carry forward must be a number');
    } else {
        $carry_forward = (float)$carry_raw;
    }
    // cap at 5
    $carry_forward = max(0.0, min(5.0, $carry_forward));
} else {
    // keep existing carry_forward for this year
    try {
        $stmt = $pdo->prepare("
            SELECT carry_forward
            FROM leave_balances
            WHERE user_id = :user_id AND leave_type_id = :leave_id AND year = :yr
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id, ':leave_id' => $leave_id, ':yr' => $year]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        $carry_forward = $existing ? (float)$existing['carry_forward'] : 0.0;
    } catch (Throwable $e) {
        $carry_forward = 0.0;
    }
}

// Compute total_available
$total_available = $entitled_days + ($isAnnual ? $carry_forward : 0.0) - $used_days;
$total_available = round($total_available, 2);

// Update DB (must match year)
try {
    $stmt = $pdo->prepare("
        UPDATE leave_balances
        SET entitled_days = :entitled_days,
            used_days = :used_days,
            carry_forward = :carry_forward,
            total_available = :total_available
        WHERE user_id = :user_id AND leave_type_id = :leave_id AND year = :yr
    ");
    $stmt->execute([
        ':entitled_days'   => $entitled_days,
        ':used_days'       => $used_days,
        ':carry_forward'   => $carry_forward,
        ':total_available' => $total_available,
        ':user_id'         => $user_id,
        ':leave_id'        => $leave_id,
        ':yr'              => $year,
    ]);

    if ($stmt->rowCount() === 0) {
        fail('No matching leave balance row found for this year. (Run recalculation first.)', 404);
    }

    echo json_encode([
        'success' => true,
        'entitled_days' => $entitled_days,
        'used_days' => $used_days,
        'carry_forward' => $carry_forward,
        'total_available' => $total_available,
        'year' => $year,
    ]);
    exit;
} catch (Throwable $e) {
    fail('Failed to update balance', 500);
}