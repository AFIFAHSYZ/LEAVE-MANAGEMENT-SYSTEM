<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = $error = "";

// Fetch user info including saturday_cycle
$stmt = $pdo->prepare("SELECT name, saturday_cycle FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$saturday_cycle = $user['saturday_cycle'] ?? 'work';

// Fetch leave types
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Helper: identify emergency leave
function isEmergencyLeave($pdo, $leave_type_id) {
    $s = $pdo->prepare("SELECT name FROM leave_types WHERE id = :id");
    $s->execute([':id' => $leave_type_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return stripos(trim($row['name']), 'emergency') !== false;
}

// Calculate total leave days
function calculateLeaveDaysPHP($start_date, $end_date, $leave_type_id, $saturday_cycle, $pdo) {
    if (isEmergencyLeave($pdo, $leave_type_id)) {
        return 0.5;
    }

    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $days = 0.0;

    while ($start <= $end) {
        $dow = (int)$start->format('w'); // 0=Sun,6=Sat

        if ($dow === 0) {
            // Sunday off
} elseif ($dow === 6) {
    // Saturday logic
    if ($saturday_cycle === 'none') {
        // Never works on Saturday
        // do nothing (always off)
    } else {
        $yearStart = new DateTime($start->format('Y-01-01'));
        $weeksSinceYearStart = floor($start->diff($yearStart)->days / 7);
        $isWorkWeek = ($saturday_cycle === 'work')
            ? ($weeksSinceYearStart % 2 === 0)
            : ($weeksSinceYearStart % 2 === 1);
        if ($isWorkWeek) $days += 0.5;
    }
}
 else {
            $days += 1.0;
        }

        $start->modify('+1 day');
    }

    return round($days, 2);
}

// Handle leave form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $leave_type = $_POST['leave_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($leave_type && $start_date && $end_date) {
        try {
            $total_days = calculateLeaveDaysPHP($start_date, $end_date, $leave_type, $saturday_cycle, $pdo);

            $sql = "INSERT INTO leave_requests 
                    (user_id, leave_type_id, start_date, end_date, reason, total_days, status, date_requested)
                    VALUES (:user_id, :leave_type, :start_date, :end_date, :reason, :total_days, 'pending', NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $user_id,
                ':leave_type' => $leave_type,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':reason' => $reason,
                ':total_days' => $total_days
            ]);

            $success = "✅ Leave request submitted successfully! Total Days: $total_days";
        } catch (PDOException $e) {
            $error = "❌ Failed to submit leave request. Please try again.";
        }
    } else {
        $error = "⚠️ Please fill in all required fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Apply Leave | Teraju HR System</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
.card {background: #fff; padding: 30px 40px; border-radius: 12px;box-shadow: 0 3px 10px rgba(0,0,0,0.1);max-width: 900px;margin: 0 auto;}
.card h2 {text-align: center; margin-bottom: 20px;}
.form-grid {display: grid;grid-template-columns: 1fr 1fr;gap: 20px 30px;}
.form-group {display: flex;flex-direction: column;}
.form-group label {font-weight: 600;margin-bottom: 6px;}
.form-group input, .form-group select, textarea {width: 100%;padding: 10px;border-radius: 8px;border: 1px solid #d1d5db;background: #f9fafb;}
textarea {resize: none;}
@media (max-width: 768px) {.form-grid {grid-template-columns: 1fr;}}
.btn-full {display: block;width: 100%;padding: 12px;background: #2563eb;color: #fff;border: none;border-radius: 8px;font-size: 1rem;cursor: pointer;transition: background 0.3s;}
.btn-full:hover {background: #1e40af;}
.success-box, .error-box {padding: 10px;border-radius: 6px;margin-bottom: 15px;}
.success-box { background: #dcfce7; color: #166534; }
.error-box { background: #fee2e2; color: #991b1b; }
footer {text-align: center; margin-top: 40px;color: #666;font-size: 0.9rem;}
</style>
</head>
<body>
<div class="layout">
  <?php include "emp-sidebar.php"; ?>

  <header>
    <h1>Teraju Leave Management System</h1>
  </header>

  <div class="main-content">
    <div class="card">
      <h2>Apply for Leave</h2>

      <?php if ($error): ?>
        <div class="error-box"><?= htmlentities($error) ?></div>
      <?php elseif ($success): ?>
        <div class="success-box"><?= htmlentities($success) ?></div>
      <?php endif; ?>

      <form method="POST" action="">
        <div class="form-grid">
          <div class="form-group">
            <label for="leave_type">Leave Type <span style="color: red;">*</span></label>
            <select name="leave_type" id="leave_type" required>
              <option value="">-- Select Leave Type --</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlentities($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="reason">Reason (optional)</label>
            <textarea name="reason" id="reason" rows="3"></textarea>
          </div>

          <div class="form-group">
            <label for="start_date">Start Date <span style="color: red;">*</span></label>
            <input type="text" id="start_date" name="start_date" required>
          </div>

          <div class="form-group">
            <label for="end_date">End Date <span style="color: red;">*</span></label>
            <input type="text" id="end_date" name="end_date" required>
          </div>
        </div>

        <p id="dayCount" style="font-weight:600; color:#2563eb; margin-top:10px;">Total Days: 0</p>

        <button type="submit" class="btn-full" style="margin-top:20px;">Submit Leave Request</button>
      </form>

      <div style="text-align:center; margin-top:15px;">
        <a href="emp-dashboard.php" style="color:#2563eb; text-decoration:none;">← Back to Dashboard</a>
      </div>
    </div>

    <footer>
      &copy; <?= date('Y') ?> Teraju HR System
    </footer>
  </div>
</div>

<script>
const dayCount = document.getElementById("dayCount");
const saturdayCycle = '<?= $saturday_cycle ?>';
const leaveTypeSelect = document.getElementById('leave_type');

// Disable Sundays
function disableSundays(date) {
  return date.getDay() === 0;
}

// Calculate total days
function calculateDaysJS(start, end, leaveTypeText, cycle) {
  if (!start || !end) return 0;
  if (leaveTypeText && leaveTypeText.toLowerCase().includes('emergency')) return 0.5;

  let count = 0.0;
  let current = new Date(start);

  while (current <= end) {
    const dow = current.getDay();
    if (dow === 0) {
      // Sunday off
} else if (dow === 6) {
  if (cycle === 'none') {
    // never works Saturday
  } else {
    const yearStart = new Date(current.getFullYear(), 0, 1);
    const diffDays = Math.floor((current - yearStart) / (1000*60*60*24));
    const weeksSinceYearStart = Math.floor(diffDays / 7);
    const isWorkWeek = (cycle === 'work') ? (weeksSinceYearStart % 2 === 0) : (weeksSinceYearStart % 2 === 1);
    if (isWorkWeek) count += 0.5;
  }
}

 else {
      count += 1.0;
    }
    current.setDate(current.getDate() + 1);
  }

  return Math.round(count*100)/100;
}

// Update total day count
function updateDayCount() {
  const start = startPicker.selectedDates[0];
  const end = endPicker.selectedDates[0];
  const leaveTypeText = leaveTypeSelect.options[leaveTypeSelect.selectedIndex]?.text || '';

  if (start && end && end >= start) {
    const total = calculateDaysJS(start, end, leaveTypeText, saturdayCycle);
    dayCount.textContent = `Total Days: ${total}`;
  } else {
    dayCount.textContent = "Total Days: 0";
  }
}

// Handle emergency leave: end date = start date, readonly
function handleEmergencyLeave() {
  const leaveTypeText = leaveTypeSelect.options[leaveTypeSelect.selectedIndex]?.text || '';
  if (leaveTypeText.toLowerCase().includes('emergency')) {
    if (startPicker.selectedDates[0]) {
      endPicker.setDate(startPicker.selectedDates[0], true);
    }
    endPicker.input.setAttribute('readonly', true);
  } else {
    endPicker.input.removeAttribute('readonly');
  }
  updateDayCount();
}

// Flatpickr for start and end dates
const startPicker = flatpickr("#start_date", {
  dateFormat: "Y-m-d",
  minDate: "today",
  disable: [disableSundays],
  onChange: (selectedDates, dateStr) => {
    endPicker.set('minDate', dateStr);
    handleEmergencyLeave();
  }
});

const endPicker = flatpickr("#end_date", {
  dateFormat: "Y-m-d",
  minDate: "today",
  disable: [disableSundays],
  onChange: updateDayCount
});

// Event listener for leave type changes
leaveTypeSelect.addEventListener('change', handleEmergencyLeave);
leaveTypeSelect.addEventListener('change', updateDayCount);
</script>
</body>
</html>
