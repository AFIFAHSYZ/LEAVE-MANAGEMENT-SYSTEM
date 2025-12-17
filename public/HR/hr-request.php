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

/**
 * Encode an uploaded file for storage in a BYTEA column.
 *
 * @param array $file        The $_FILES['field'] array
 * @param int   $maxBytes    Maximum allowed size in bytes (default 8MB)
 * @param array $allowedMime Optional allowed MIME types (empty = allow all)
 * @return array             ['data' => string|null, 'type' => string|null, 'name' => string|null, 'size' => int|null, 'error' => string|null]
 */
function encode_uploaded_file(array $file, int $maxBytes = 8388608, array $allowedMime = []): array {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['data' => null, 'type' => null, 'name' => null, 'size' => null, 'error' => null];
    }

    $err = $file['error'] ?? UPLOAD_ERR_OK;
    if ($err !== UPLOAD_ERR_OK) {
        return ['data' => null, 'type' => null, 'name' => null, 'size' => null, 'error' => "File upload error (code: $err)."];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size > $maxBytes) {
        return ['data' => null, 'type' => null, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => "Attachment too large. Max " . ($maxBytes/1024/1024) . "MB allowed."];
    }

    $tmp = $file['tmp_name'] ?? null;
    if (!$tmp || !is_uploaded_file($tmp)) {
        return ['data' => null, 'type' => null, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => "Invalid uploaded file."];
    }

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $tmp) ?: null;
            finfo_close($finfo);
        }
    }
    if (!$mime) {
        $mime = $file['type'] ?? 'application/octet-stream';
    }

    if (!empty($allowedMime) && !in_array($mime, $allowedMime, true)) {
        return ['data' => null, 'type' => $mime, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => "File type not allowed: $mime"];
    }

    $data = @file_get_contents($tmp);
    if ($data === false) {
        return ['data' => null, 'type' => $mime, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => "Failed to read uploaded file."];
    }

    return ['data' => $data, 'type' => $mime, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => null];
}

// Helper: identify emergency leave
function isEmergencyLeave($pdo, $leave_type_id) {
    $s = $pdo->prepare("SELECT name FROM leave_types WHERE id = :id");
    $s->execute([':id' => $leave_type_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return stripos(trim($row['name']), 'emergency') !== false;
}

// Helper: identify annual leave
function isAnnualLeaveById($pdo, $leave_type_id) {
    $s = $pdo->prepare("SELECT name FROM leave_types WHERE id = :id");
    $s->execute([':id' => $leave_type_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return stripos(trim($row['name']), 'annual') !== false;
}

// Calculate total leave days (used server-side for non-annual when total_days not provided)
function calculateLeaveDaysPHP($start_date, $end_date, $leave_type_id, $saturday_cycle, $pdo) {
    // By design: If emergency and total_days not provided, default to 0.5
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
            if ($saturday_cycle !== 'none') {
                $yearStart = new DateTime($start->format('Y-01-01'));
                // use days difference and integer week count
                $weeksSinceYearStart = floor($start->diff($yearStart)->days / 7);
                $isWorkWeek = ($saturday_cycle === 'work')
                    ? ($weeksSinceYearStart % 2 === 0)
                    : ($weeksSinceYearStart % 2 === 1);
                if ($isWorkWeek) $days += 0.5;
            }
        } else {
            $days += 1.0;
        }

        $start->modify('+1 day');
    }

    return round($days, 2);
}

// Validate emergency total (server-side)
function validateEmergencyTotal($val) {
    // allow 0.5 or 1.0 only
    $allowed = [0.5, 1.0];
    return in_array((float)$val, $allowed, true);
}

// Handle leave form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $leave_type = $_POST['leave_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($leave_type && $start_date && ($end_date !== null)) {
        try {
            // If total_days submitted and numeric, use it. Otherwise calculate server-side.
            $submitted_total = isset($_POST['total_days']) && $_POST['total_days'] !== '' && is_numeric($_POST['total_days'])
                ? (float) $_POST['total_days']
                : null;

            // If this leave type is emergency, validate allowed totals (0.5 or 1.0) if provided.
            if (isEmergencyLeave($pdo, $leave_type)) {
                if ($submitted_total === null) {
                    // default to 0.5 if user didn't submit total_days
                    $submitted_total = 0.5;
                } else {
                    if (!validateEmergencyTotal($submitted_total)) {
                        throw new Exception("For Emergency leave you can only request 0.5 or 1.0 day.");
                    }
                }

                // For emergency, by policy we set end_date = start_date server-side as well
                $end_date = $start_date;
            }

            // Handle attachment: encode and validate, then store into DB columns attachment (BYTEA) and attachment_type
            $fileRes = encode_uploaded_file($_FILES['attachment'] ?? [], 8 * 1024 * 1024, [
                'application/pdf', 'image/jpeg', 'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ]);
            if ($fileRes['error']) {
                throw new Exception($fileRes['error']);
            }

            // If annual: front-end should provide total_days and calculate end_date. We accept submitted values.
            // If submitted_total is null (user didn't provide), calculate server-side for non-annual leaves.
            if ($submitted_total === null) {
                // compute from start/end (non-annual path)
                $total_days = calculateLeaveDaysPHP($start_date, $end_date, $leave_type, $saturday_cycle, $pdo);
            } else {
                $total_days = $submitted_total;
            }

            // Final server-side sanity: ensure total_days is >= 0
            if ($total_days <= 0) {
                throw new Exception("Calculated total days is not valid.");
            }

            // Build insert with attachment columns
            $sql = "INSERT INTO leave_requests 
                    (user_id, leave_type_id, start_date, end_date, reason, total_days, attachment, attachment_type, status)
                    VALUES (:user_id, :leave_type, :start_date, :end_date, :reason, :total_days, :attachment, :attachment_type, 'pending')";

            $stmt = $pdo->prepare($sql);
            // bind common params
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':leave_type', $leave_type, PDO::PARAM_INT);
            $stmt->bindValue(':start_date', $start_date, PDO::PARAM_STR);
            $stmt->bindValue(':end_date', $end_date, PDO::PARAM_STR);
            $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
            $stmt->bindValue(':total_days', $total_days, PDO::PARAM_STR);

            if ($fileRes['data'] !== null) {
                $stmt->bindValue(':attachment', $fileRes['data'], PDO::PARAM_LOB);
                $stmt->bindValue(':attachment_type', $fileRes['type'], PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':attachment', null, PDO::PARAM_NULL);
                $stmt->bindValue(':attachment_type', null, PDO::PARAM_NULL);
            }

            $stmt->execute();

            $success = "✅ Leave request submitted successfully! Total Days: $total_days";
        } catch (PDOException $e) {
            $error = "❌ Failed to submit leave request: " . $e->getMessage();
        } catch (Exception $e) {
            $error = "⚠️ " . $e->getMessage();
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
@media (max-width: 768px) {.form-grid {grid-template-columns: 1fr;} }
.btn-full {display: block;width: 100%;padding: 12px;background: #2563eb;color: #fff;border: none;border-radius: 8px;font-size: 1rem;cursor: pointer;transition: background 0.3s;}
.btn-full:hover {background: #1e40af;}
.success-box, .error-box {padding: 10px;border-radius: 6px;margin-bottom: 15px;}
.success-box { background: #dcfce7; color: #166534; }
.error-box { background: #fee2e2; color: #991b1b; }
footer {text-align: center; margin-top: 40px;color: #666;font-size: 0.9rem;}
.small-note {color: #555; font-size: 0.9rem;}
</style>
</head>
<body>
<div class="layout">
  <?php include "sidebar.php"; ?>

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

      <form method="POST" action="" enctype="multipart/form-data">
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
            <label for="reason">Reason <span style="color: red;">*</span></label>
            <textarea name="reason" id="reason" rows="3" required></textarea>
          </div>

          <div class="form-group">
            <label for="start_date">Start Date <span style="color: red;">*</span></label>
            <input type="text" id="start_date" name="start_date" required>
          </div>

          <div class="form-group">
              <label for="end_date">End Date <span style="color:red;">*</span></label>
              <input type="text" id="end_date" name="end_date" readonly>
          </div>

          <div class="form-group">
              <label for="total_days">Total Days <span style="color:red;">*</span></label>
              <input type="number" step="0.5" id="total_days" name="total_days" value="0" required>
              <small class="small-note">For Annual Leave: enter total days (0.5 steps) and the end date will be auto-calculated. For other types: choose start & end. For Emergency: you may enter 0.5 or 1.0 (end date will be start date).</small>
          </div>

          <div class="form-group" style="grid-column: 1 / -1;">
              <label for="attachment">Attachment (optional)</label>
              <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
              <small class="small-note">Max 8MB. Allowed: PDF, JPG, PNG, DOC/DOCX.</small>
          </div>
        </div>

        <button type="submit" class="btn-full" style="margin-top:20px;">Submit Leave Request</button>
      </form>

      <div style="text-align:center; margin-top:15px;">
        <a href="dashboard.php" style="color:#2563eb; text-decoration:none;">← Back to Dashboard</a>
      </div>
    </div>

    <footer>
      &copy; <?= date('Y') ?> Teraju HR System
    </footer>
  </div>
</div>

<script>
/*
  Rewritten JS
  - 3 handlers: annual / emergency / normal
  - annual is inclusive and uses 0.5 increments
  - emergency allows user to pick 0.5 or 1.0 (client + server validated)
  - Saturdays counted as 0.5 depending on saturdayCycle
  - Sundays disabled in flatpickr and are never counted
  - Local-date formatting used (no toISOString for local y-m-d)
*/

const saturdayCycle = '<?= $saturday_cycle ?>';

// Flatpickr helpers and init
function disableSundays(date) { return date.getDay() === 0; }

const startPicker = flatpickr("#start_date", {
  dateFormat: "Y-m-d",
  disable: [disableSundays],
  onChange: onStartChanged
});

const endPicker = flatpickr("#end_date", {
  dateFormat: "Y-m-d",
  disable: [disableSundays],
  onChange: onEndChanged
});

// DOM elements
const leaveTypeSelect = document.getElementById('leave_type');
const totalDaysInput = document.getElementById('total_days');
const endInput = document.getElementById('end_date');
const startInput = document.getElementById('start_date');

// helpers
function txtOfLeave() {
  return (leaveTypeSelect.options[leaveTypeSelect.selectedIndex]?.text || '').toLowerCase();
}
function isAnnual() { return txtOfLeave().includes('annual'); }
function isEmergency() { return txtOfLeave().includes('emergency'); }

// Strict emergency allowed totals
const EMERGENCY_ALLOWED = [0.5, 1.0];

// Format date to local YYYY-MM-DD (no timezone drift)
function formatDateLocal(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

// Compute days since year start using UTC midnights (stable across timezones)
function daysSinceYearStartUTC(date) {
  const yearStartUTC = Date.UTC(date.getFullYear(), 0, 1);
  const dateUTC = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
  return Math.floor((dateUTC - yearStartUTC) / (24 * 60 * 60 * 1000));
}

// Saturday work detection (use UTC daysSinceYearStart to avoid DST issues)
function isWorkSaturday(date) {
  if (saturdayCycle === 'none') return false;
  const weeksSince = Math.floor(daysSinceYearStartUTC(date) / 7);
  return (saturdayCycle === 'work') ? (weeksSince % 2 === 0) : (weeksSince % 2 === 1);
}

/* ===========================
   MODE HANDLERS
   =========================== */

// Emergency: user can pick 0.5 or 1.0 (client validates). End = start.
function handleEmergency() {
  // allow editing but restrict to 0.5/1.0 via attributes
  totalDaysInput.removeAttribute('readonly');
  totalDaysInput.step = '0.5';
  totalDaysInput.min = '0.5';
  totalDaysInput.max = '1.0';

  const s = startPicker.selectedDates[0];
  if (s) {
    // end date should be the start
    const iso = formatDateLocal(new Date(s.getFullYear(), s.getMonth(), s.getDate()));
    endPicker.setDate(iso, true, 'Y-m-d');
  } else {
    endPicker.clear();
  }
  endInput.setAttribute('readonly', true);

  // If user entered an invalid emergency total, clamp it to nearest allowed
  let val = parseFloat(totalDaysInput.value);
  if (isNaN(val)) {
    totalDaysInput.value = '0.5';
  } else {
    if (!EMERGENCY_ALLOWED.includes(val)) {
      // clamp to 0.5 if <0.75 else 1.0
      totalDaysInput.value = (val < 0.75) ? '0.5' : '1.0';
    }
  }
}

// Annual: user inputs total (0.5 step), system finds end date.
// Inclusive: first day is counted.
function handleAnnual() {
  totalDaysInput.removeAttribute('readonly');
  totalDaysInput.step = '0.5';
  totalDaysInput.removeAttribute('min');
  totalDaysInput.removeAttribute('max');
  endInput.setAttribute('readonly', true);

  const startDate = startPicker.selectedDates[0];
  const total = parseFloat(totalDaysInput.value);

  if (!startDate || isNaN(total) || total <= 0) {
    endPicker.clear();
    return;
  }

  let remaining = total;
  // copy local date (midnight local)
  let current = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());

  // safety guard
  const MAX_LOOPS = 2000;
  let loops = 0;

  while (loops++ < MAX_LOOPS) {
    const dow = current.getDay();
    if (dow === 0) {
      // Sunday: do not count
    } else if (dow === 6) {
      if (isWorkSaturday(current)) remaining -= 0.5;
    } else {
      remaining -= 1.0;
    }

    if (remaining <= 0) break;

    // advance one local day
    current.setDate(current.getDate() + 1);
  }

  if (loops >= MAX_LOOPS) {
    console.error('Annual calculation exceeded max loops');
    endPicker.clear();
    return;
  }

  const iso = formatDateLocal(current);
  endPicker.setDate(iso, true, 'Y-m-d');
}

// Normal (non-annual, non-emergency): calculate total days between start+end inclusive
function handleNormal() {
  totalDaysInput.setAttribute('readonly', true);
  totalDaysInput.step = '0.5';
  totalDaysInput.removeAttribute('min');
  totalDaysInput.removeAttribute('max');
  endInput.removeAttribute('readonly');

  const startDate = startPicker.selectedDates[0];
  const endDate = endPicker.selectedDates[0];

  if (!startDate || !endDate || endDate < startDate) {
    totalDaysInput.value = '0';
    return;
  }

  let count = 0.0;
  // copy local date
  let current = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());

  while (current <= endDate) {
    const dow = current.getDay();
    if (dow === 0) {
      // skip Sundays
    } else if (dow === 6) {
      if (isWorkSaturday(current)) count += 0.5;
    } else {
      count += 1.0;
    }
    current.setDate(current.getDate() + 1);
  }

  totalDaysInput.value = Math.round(count * 100) / 100;
}

/* ===========================
   ROUTER / EVENTS
   =========================== */

function routeByType() {
  if (isEmergency()) {
    handleEmergency();
    return;
  }
  if (isAnnual()) {
    handleAnnual();
    return;
  }
  handleNormal();
}

function onStartChanged(selectedDates) {
  // ensure end cannot be earlier than start
  endPicker.set('minDate', startPicker.selectedDates[0] || null);

  // For emergency: end = start; for annual recalc end; otherwise maybe recalc total
  routeByType();
}

function onEndChanged(selectedDates) {
  // When end changes we only need to recalc for normal leaves
  if (!isAnnual() && !isEmergency()) {
    handleNormal();
  }
}

// totalDays changed by user (for annual or emergency)
totalDaysInput.addEventListener('input', function() {
  if (isEmergency()) {
    // clamp to allowed after typing
    const v = parseFloat(totalDaysInput.value);
    if (isNaN(v)) return;
    // if user types something else, fix it on blur
  } else if (isAnnual()) {
    handleAnnual();
  }
});

// when leave type changes
leaveTypeSelect.addEventListener('change', function() {
  // Keep totalDays value where reasonable, otherwise adjust
  routeByType();
});

// when start changes (flatpickr wired)
document.addEventListener('DOMContentLoaded', function() {
  // initial route
  routeByType();
});

// fix emergency input on blur (force to allowed values)
totalDaysInput.addEventListener('blur', function() {
  if (!isEmergency()) return;
  let v = parseFloat(totalDaysInput.value);
  if (isNaN(v)) v = 0.5;
  if (v < 0.75) v = 0.5; else v = 1.0;
  totalDaysInput.value = v.toFixed((v % 1 === 0) ? 0 : 1);
});</script>
<script src="../../assets/js/sidebar.js"></script> 

</body>
</html>