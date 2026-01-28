<?php
session_start();
require_once '../../config/conn1.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Ensure HR
$stmt = $pdo->prepare("SELECT id, name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || ($user['position'] ?? '') !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

// Get request id
$id = $_GET['id'] ?? '';
if (!$id) {
    header("Location: hr-dashboard.php");
    exit();
}

// Initialize to avoid undefined variable warnings
$request = null;
$daysTaken = 0;
$holidays = 0;
$effectiveDays = 0.0;

/* =========================
   HANDLE VERIFY (FINAL APPROVAL + UPDATE BALANCES)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
    try {
        $pdo->beginTransaction();

        // Lock ONLY the leave_requests row
        $stmt = $pdo->prepare("
            SELECT id, user_id, leave_type_id, start_date, end_date, reason, status
            FROM leave_requests
            WHERE id = :id
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            throw new Exception('Leave request not found.');
        }

        $status = strtolower($req['status'] ?? '');

        // Prevent double subtraction
        if ($status !== 'verified') {

            // Calculate effective days (range - public holidays)
            if (!empty($req['start_date']) && !empty($req['end_date'])) {
                $daysTaken = (strtotime($req['end_date']) - strtotime($req['start_date'])) / 86400 + 1;

                $phStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM public_holidays
                    WHERE holiday_date BETWEEN :start AND :end
                ");
                $phStmt->execute([':start' => $req['start_date'], ':end' => $req['end_date']]);
                $holidays = (int)$phStmt->fetchColumn();

                $effectiveDays = (float)max(0, $daysTaken - $holidays);
            }

            if ($effectiveDays <= 0) {
                throw new Exception("Effective days is 0. Cannot verify this leave.");
            }

            // Use the year of the leave start_date (safer than current year)
            $year = (int)date('Y', strtotime((string)$req['start_date']));

            // Ensure leave_balances row exists (requires UNIQUE(user_id, leave_type_id, year))
            $stmt = $pdo->prepare("
                INSERT INTO leave_balances (user_id, leave_type_id, year, entitled_days, carry_forward, used_days, total_available)
                VALUES (:uid, :ltid, :yr, 0, 0, 0, 0)
                ON CONFLICT (user_id, leave_type_id, year) DO NOTHING
            ");
            $stmt->execute([
                ':uid'  => (int)$req['user_id'],
                ':ltid' => (int)$req['leave_type_id'],
                ':yr'   => $year
            ]);

            // Update balances: used_days += effectiveDays, total_available -= effectiveDays
            $stmt = $pdo->prepare("
                UPDATE leave_balances
                SET used_days = COALESCE(used_days, 0) + :days,
                    total_available = COALESCE(total_available, 0) - :days
                WHERE user_id = :uid AND leave_type_id = :ltid AND year = :yr
            ");
            $stmt->execute([
                ':days' => $effectiveDays,
                ':uid'  => (int)$req['user_id'],
                ':ltid' => (int)$req['leave_type_id'],
                ':yr'   => $year
            ]);

            // Mark as verified (final)
            $stmt = $pdo->prepare("
                UPDATE leave_requests
                SET status = 'verified',
                    verified_by = :hr,
                    verified_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':hr' => $user_id, ':id' => $id]);
        }

        $pdo->commit();

        header("Location: verify-leave.php?id=" . urlencode((string)$id));
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMsg = "Error verifying leave: " . $e->getMessage();
        // fall through to display page with error
    }
}

/* =========================
   FETCH REQUEST (FOR DISPLAY)
   ========================= */
$stmt = $pdo->prepare("
    SELECT lr.*,
           u.name AS employee_name,
           lt.name AS leave_type,
           h.name AS verified_by_name
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    LEFT JOIN users h ON lr.verified_by = h.id
    WHERE lr.id = :id
");
$stmt->execute([':id' => $id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    die("Leave request not found.");
}

/* =========================
   EFFECTIVE DAYS (REMOVE PUBLIC HOLIDAYS) - FOR DISPLAY
   ========================= */
$daysTaken = 0;
$holidays = 0;
$effectiveDays = 0.0;

if (!empty($request['start_date']) && !empty($request['end_date'])) {
    $daysTaken = (strtotime($request['end_date']) - strtotime($request['start_date'])) / 86400 + 1;

    $phStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM public_holidays
        WHERE holiday_date BETWEEN :start AND :end
    ");
    $phStmt->execute([':start' => $request['start_date'], ':end' => $request['end_date']]);
    $holidays = (int)$phStmt->fetchColumn();

    $effectiveDays = (float)max(0, $daysTaken - $holidays);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify Leave Request</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
.card {background: white; border-radius: 10px; padding: 1.5rem 2rem; box-shadow: 0 2px 10px rgba(0,0,0,0.08);}
.info-grid {display: grid; grid-template-columns: 180px auto; row-gap: 10px; column-gap: 10px; font-size: 0.95rem;}
.label {font-weight: 600; color: #475569;}
.btn-verify{
  padding: 10px 16px;
  border-radius: 10px;
  background: #fff;
  color: #16a34a;
  border: 2px solid #16a34a;
  font-weight: 700;
  cursor: pointer;
  transition: background .15s ease, color .15s ease, transform .12s ease;
}
.btn-verify:hover{
  background: #16a34a;
  color: #fff;
  transform: translateY(-1px);
}
.back-link {display:inline-block; margin-bottom:15px; text-decoration:none; color:#2563eb; font-weight:600; background:#e0f2fe; padding:6px 12px; border-radius:6px;}
.back-link:hover {background:#bfdbfe; color:#1e40af;}
.status-badge {padding:4px 10px; border-radius:6px; font-weight:600;}
.note {margin-top:10px; color:#475569; font-size:0.9rem;}
.error-box {background:#fee2e2; color:#991b1b; padding:10px; border-radius:8px; margin-bottom:12px;}
</style>
<script>
function confirmVerify() {
  return confirm("Are you sure you want to verify this leave? This will deduct the employee's leave balance.");
}
</script>
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>

  <main class="main-content">
    <header><h1>Leave Management System</h1></header>

    <a href="all-requests.php" class="back-link">← Back to List</a>

    <div class="card">
      <h2>Verify Leave Request</h2>
      <hr><br>

      <?php if (!empty($errorMsg)): ?>
        <div class="error-box"><?= htmlspecialchars($errorMsg) ?></div>
      <?php endif; ?>

      <div class="info-grid">
        <div class="label">Name:</div>
        <div><?= htmlspecialchars($request['employee_name'] ?? '') ?></div>

        <div class="label">Leave Type:</div>
        <div><?= htmlspecialchars($request['leave_type'] ?? '-') ?></div>

        <div class="label">Start Date:</div>
        <div><?= htmlspecialchars($request['start_date'] ?? '-') ?></div>

        <div class="label">End Date:</div>
        <div><?= htmlspecialchars($request['end_date'] ?? '-') ?></div>

        <div class="label">Effective Days:</div>
        <div><strong><?= (float)$effectiveDays ?></strong></div>

        <div class="label">Status:</div>
        <div>
          <?php
            $status = strtolower($request['status'] ?? 'pending');
            $color = ($status === 'verified') ? '#dcfce7' : '#e0f2fe';
            $text  = ($status === 'verified') ? '#15803d' : '#1e40af';
          ?>
          <span class="status-badge" style="background:<?= $color ?>; color:<?= $text ?>;">
            <?= htmlspecialchars(ucfirst($status)) ?>
          </span>
        </div>

        <?php if (!empty($request['verified_by_name'])): ?>
          <div class="label">Verified By:</div>
          <div>
            <?= htmlspecialchars($request['verified_by_name']) ?>
            on <?= htmlspecialchars($request['verified_at'] ?? '') ?>
          </div>
        <?php endif; ?>

        <div class="label">Reason:</div>
        <div><?= htmlspecialchars(($request['reason'] ?? '') ?: '-') ?></div>
      </div>

      <br>

      <?php $status = strtolower($request['status'] ?? ''); ?>

      <?php if ($status !== 'verified'): ?>
        <form method="POST" action="verify-leave.php?id=<?= (int)$request['id'] ?>">
          <input type="hidden" name="action" value="verify">
          <button type="submit" class="btn-verify" onclick="return confirmVerify()">Verify</button>
        </form>
      <?php else: ?>
        <p class="note">✔ Leave already verified</p>
      <?php endif; ?>

    </div>
  </main>
</div>

<script src="../../assets/js/sidebar.js"></script>
</body>
</html>