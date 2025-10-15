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

// Handle leave form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $leave_type = $_POST['leave_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($leave_type && $start_date && $end_date) {
        try {
            $sql = "INSERT INTO leave_requests 
                    (user_id, leave_type_id, start_date, end_date, reason, status)
                    VALUES (:user_id, :leave_type, :start_date, :end_date, :reason, 'pending')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $user_id,
                ':leave_type' => $leave_type,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':reason' => $reason
            ]);
            $success = "✅ Leave request submitted successfully!";
        } catch (PDOException $e) {
            $error = "❌ Failed to submit leave request. Please try again.";
        }
    } else {
        $error = "⚠️ Please fill in all required fields.";
    }
}
// Fetch user info
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC); // This returns an associative array

// Fetch leave types
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Apply Leave | Teraju LMS</title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <!-- Flatpickr CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">

</head>
<body>
<div class="layout">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="user-profile">
            <h2>LMS</h2>

            <div class="avatar">
                <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
            </div>
            <p class="user-name"><?php echo htmlspecialchars($user['name']); ?></p>
        </div>
        <nav>
            <ul>
                <li><a href="emp-dashboard.php">Dashboard</a></li>
                <li><a href="apply-leave.php" class="active">Apply Leave</a></li>
                <li><a href="my-leaves.php">My Leaves</a></li>
                <li><a href="../logout.php">Logout</a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            &copy; <?php echo date('Y'); ?> Teraju LMS
        </div>
    </aside>

    <!-- Header -->
    <header>
        <h1>Leave Management System</h1>
    </header>

    <!-- Main Content -->
    <div class="main-content">
        <div class="card" style="max-width:600px; margin:0 auto;">
            <h2>Apply for Leave</h2>

            <?php if ($error): ?>
              <div class="error-box"><?= htmlentities($error) ?></div>
            <?php elseif ($success): ?>
              <div class="success-box"><?= htmlentities($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="leave_type">Leave Type</label>
                    <select name="leave_type" id="leave_type" required>
                        <option value="">-- Select Leave Type --</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlentities($t['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reason">Reason (optional)</label>
                    <textarea name="reason" id="reason" rows="4" style="resize:none; width:100%; padding:12px; border-radius:10px; border:1px solid #d1d5db; background:#f9fafb;"></textarea>
                </div>

            <div class="form-group">
            <label for="start_date">Start Date</label>
            <input type="text" id="start_date" name="start_date" required>
            </div>

            <div class="form-group">
            <label for="end_date">End Date</label>
            <input type="text" id="end_date" name="end_date" required>
            </div>

            <p id="dayCount" style="font-weight:600; color:#2563eb; margin-top:8px;">Total Days: 0</p><br>

                <button type="submit" class="btn-full">Submit Leave Request</button>
            </form>

            <div class="form-footer">
                <p><a href="emp-dashboard.php">← Back to Dashboard</a></p>
            </div>
        </div>

        <footer>
            &copy; <?php echo date('Y'); ?> Teraju HR System
        </footer>
    </div>
</div>
<script>
  // Function to disable Sundays
  function disableSundays(date) {
    return (date.getDay() !== 0); // 0 = Sunday
  }

  // Function to calculate number of weekdays (excluding Sundays)
  function calculateDays(start, end) {
    let count = 0;
    let current = new Date(start);

    while (current <= end) {
      if (current.getDay() !== 0) { // exclude Sundays
        count++;
      }
      current.setDate(current.getDate() + 1);
    }
    return count;
  }

  // Reference to the display element
  const dayCount = document.getElementById("dayCount");

  // Initialize Flatpickr for Start Date
  const startPicker = flatpickr("#start_date", {
    dateFormat: "Y-m-d",
    minDate: "today",
    disable: [
      function(date) {
        return !disableSundays(date);
      }
    ],
    onChange: function(selectedDates, dateStr) {
      endPicker.set('minDate', dateStr); // ensure end date ≥ start date
      updateDayCount();
    }
  });

  // Initialize Flatpickr for End Date
  const endPicker = flatpickr("#end_date", {
    dateFormat: "Y-m-d",
    minDate: "today",
    disable: [
      function(date) {
        return !disableSundays(date);
      }
    ],
    onChange: updateDayCount
  });

  // Update total days
  function updateDayCount() {
    const start = startPicker.selectedDates[0];
    const end = endPicker.selectedDates[0];

    if (start && end && end >= start) {
      const total = calculateDays(start, end);
      dayCount.textContent = `Total Days: ${total}`;
    } else {
      dayCount.textContent = "Total Days: 0";
    }
  }
</script>

</body>
</html>
