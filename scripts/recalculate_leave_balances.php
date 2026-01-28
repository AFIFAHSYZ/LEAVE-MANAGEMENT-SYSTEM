<?php
/**
 * Recalculate leave balances for all users for the current year.
 * Intended to be run by cron (daily or monthly).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/conn1.php';

date_default_timezone_set('Asia/Kuala_Lumpur'); // change if needed

$today = new DateTime('today');
$currentYear  = (int)$today->format('Y');
$currentMonth = (int)$today->format('n');

function calculateEntitledDays(PDO $pdo, int $userId, int $leaveTypeId, DateTime $today): float
{
    // Fetch leave type info
    $stmt = $pdo->prepare("
        SELECT id, name, default_limit
        FROM leave_types
        WHERE id = :id
    ");
    $stmt->execute(['id' => $leaveTypeId]);
    $lt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$lt) return 0.0;

    $defaultLimit = (float)$lt['default_limit'];

    // Fixed leave types
    $fixedLeaveIds = [3, 4, 5];
    if (in_array($leaveTypeId, $fixedLeaveIds, true)) {
        return $defaultLimit;
    }

    // Join date
    $stmt = $pdo->prepare("SELECT date_joined FROM users WHERE id = :uid");
    $stmt->execute(['uid' => $userId]);
    $joinDate = $stmt->fetchColumn();
    if (!$joinDate) return 0.0;

    $join = new DateTime((string)$joinDate);
    $years = $join->diff($today)->y;

    // Sick leave (tenure-based)
    if ($leaveTypeId === 2) {
        $stmt = $pdo->prepare("
            SELECT days_per_year
            FROM leave_tenure_policy
            WHERE leave_type_id = :ltid
              AND min_years <= :yrs
              AND (max_years IS NULL OR max_years >= :yrs)
            ORDER BY min_years DESC
            LIMIT 1
        ");
        $stmt->execute(['ltid' => $leaveTypeId, 'yrs' => $years]);
        $daysPerYear = $stmt->fetchColumn();
        return $daysPerYear !== false ? (float)$daysPerYear : 0.0;
    }

    // Annual leave (or other accrual-based types)
    $stmt = $pdo->prepare("
        SELECT days_per_year
        FROM leave_tenure_policy
        WHERE leave_type_id = :ltid
          AND min_years <= :yrs
          AND (max_years IS NULL OR max_years >= :yrs)
        ORDER BY min_years DESC
        LIMIT 1
    ");
    $stmt->execute(['ltid' => $leaveTypeId, 'yrs' => $years]);
    $daysPerYear = $stmt->fetchColumn();
    if ($daysPerYear === false) $daysPerYear = $defaultLimit;

    $daysPerYear = (float)$daysPerYear;
    $monthlyAccrual = $daysPerYear / 12.0;

    // months_elapsed:
    // - If joined in a previous year: accrue Jan..current month => currentMonth
    // - If joined this year: accrue from join month..current month (inclusive)
    $joinYear  = (int)$join->format('Y');
    $joinMonth = (int)$join->format('n');
    $currentMonth = (int)$today->format('n');
    $currentYear  = (int)$today->format('Y');

    if ($joinYear < $currentYear) {
        $monthsElapsed = $currentMonth;
    } else {
        $monthsElapsed = $currentMonth - $joinMonth + 1;
        if ($monthsElapsed < 0) $monthsElapsed = 0;
    }

    $entitlement = $monthlyAccrual * $monthsElapsed;
    return round($entitlement, 2);
}

try {
    $pdo->beginTransaction();

    // Fetch all users
    $users = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);
    // Fetch all leave types
    $leaveTypes = $pdo->query("SELECT id, name FROM leave_types")->fetchAll(PDO::FETCH_ASSOC);

    // Prepared statements for performance
    $selectBalance = $pdo->prepare("
        SELECT id, carry_forward, used_days
        FROM leave_balances
        WHERE user_id = :uid AND leave_type_id = :ltid AND year = :yr
        LIMIT 1
    ");

    $updateBalance = $pdo->prepare("
        UPDATE leave_balances
        SET entitled_days = :entitled,
            total_available = :total
        WHERE id = :id
    ");

    $insertBalance = $pdo->prepare("
        INSERT INTO leave_balances
            (user_id, leave_type_id, year, entitled_days, carry_forward, used_days, total_available)
        VALUES
            (:uid, :ltid, :yr, :entitled, :carry, :used, :total)
    ");

    foreach ($users as $userIdRaw) {
        $userId = (int)$userIdRaw;

        foreach ($leaveTypes as $lt) {
            $leaveTypeId = (int)$lt['id'];
            $leaveTypeName = (string)$lt['name'];

            $entitled = calculateEntitledDays($pdo, $userId, $leaveTypeId, $today);

            $selectBalance->execute([
                'uid' => $userId,
                'ltid' => $leaveTypeId,
                'yr' => $currentYear
            ]);
            $record = $selectBalance->fetch(PDO::FETCH_ASSOC);

            $carry = $record ? (float)$record['carry_forward'] : 0.0;
            $used  = $record ? (float)$record['used_days'] : 0.0;

            // Keep your existing rule: carry only adds for Annual Leave
            $totalAvailable = $entitled + ($leaveTypeName === 'Annual Leave' ? $carry : 0.0) - $used;
            $totalAvailable = round($totalAvailable, 2);

            if ($record) {
                $updateBalance->execute([
                    'entitled' => $entitled,
                    'total' => $totalAvailable,
                    'id' => (int)$record['id'],
                ]);
            } else {
                $insertBalance->execute([
                    'uid' => $userId,
                    'ltid' => $leaveTypeId,
                    'yr' => $currentYear,
                    'entitled' => $entitled,
                    'carry' => 0.0,
                    'used' => 0.0,
                    'total' => $totalAvailable,
                ]);
            }
        }
    }

    $pdo->commit();
    echo "[" . date('Y-m-d H:i:s') . "] OK: leave balances recalculated for year {$currentYear}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}