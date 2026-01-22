<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$success = $error = "";

// Fetch user info (only used if you want to show the name, etc.)
$stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch leave types
$types = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/**
 * Encode an uploaded file for storage in a BYTEA column.
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

// Handle leave form submission (TRUST total_days typed by user)
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $leave_type = $_POST['leave_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date   = $_POST['end_date'] ?? '';
    $reason     = trim($_POST['reason'] ?? '');
    $total_days = $_POST['total_days'] ?? '';

    if ($leave_type && $start_date && $end_date && $reason !== '' && $total_days !== '') {
        try {
            if (!is_numeric($total_days)) {
                throw new Exception("Total Days must be a number.");
            }

            $total_days = (float)$total_days;

            if ($total_days <= 0) {
                throw new Exception("Total Days must be greater than 0.");
            }

            // Enforce 0.5 increments server-side
            if (fmod($total_days, 0.5) !== 0.0) {
                throw new Exception("Total Days must be in 0.5 increments (e.g. 0.5, 1.0, 1.5, ...).");
            }

            // Attachment (optional)
            $fileRes = encode_uploaded_file($_FILES['attachment'] ?? [], 8 * 1024 * 1024, [
                'application/pdf', 'image/jpeg', 'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ]);
            if ($fileRes['error']) {
                throw new Exception($fileRes['error']);
            }

            $sql = "INSERT INTO leave_requests
                    (user_id, leave_type_id, start_date, end_date, reason, total_days, attachment, attachment_type, status)
                    VALUES
                    (:user_id, :leave_type, :start_date, :end_date, :reason, :total_days, :attachment, :attachment_type, 'pending')";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':leave_type', $leave_type, PDO::PARAM_INT);
            $stmt->bindValue(':start_date', $start_date, PDO::PARAM_STR);
            $stmt->bindValue(':end_date', $end_date, PDO::PARAM_STR);
            $stmt->bindValue(':reason', $reason, PDO::PARAM_STR);
            $stmt->bindValue(':total_days', (string)$total_days, PDO::PARAM_STR);

            if ($fileRes['data'] !== null) {
                $stmt->bindValue(':attachment', $fileRes['data'], PDO::PARAM_LOB);
                $stmt->bindValue(':attachment_type', $fileRes['type'], PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':attachment', null, PDO::PARAM_NULL);
                $stmt->bindValue(':attachment_type', null, PDO::PARAM_NULL);
            }

            $stmt->execute();
            $success = "Leave request submitted successfully! Total Days: $total_days";
        } catch (PDOException $e) {
            $error = "Failed to submit leave request: " . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
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
.card {background: #fff; padding: 30px 40px; border-radius: 12px; box-shadow: 0 3px 10px rgba(0,0,0,0.1); max-width: 900px; margin: 0 auto;}
.card h2 {text-align: center; margin-bottom: 20px;}
.form-grid {display: grid; grid-template-columns: 1fr 1fr; gap: 20px 30px;}
.form-group {display: flex; flex-direction: column;}
.form-group label {font-weight: 600; margin-bottom: 6px;}
.form-group input, .form-group select, textarea {width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #d1d5db; background: #f9fafb;}
textarea {resize: none;}
@media (max-width: 768px) {.form-grid {grid-template-columns: 1fr;} }
.btn-full {display: block; width: 100%; padding: 12px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; transition: background 0.3s;}
.btn-full:hover {background: #1e40af;}
.success-box, .error-box {padding: 10px; border-radius: 6px; margin-bottom: 15px;}
.success-box { background: #dcfce7; color: #166534; }
.error-box { background: #fee2e2; color: #991b1b; }
footer {text-align: center; margin-top: 40px; color: #666; font-size: 0.9rem;}
.small-note {color: #555; font-size: 0.9rem;}
::placeholder {
  color: #94a3b8;
}
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

      <form method="POST" action="" enctype="multipart/form-data">
        <div class="form-grid">
          <div class="form-group">
            <label for="leave_type">Leave Type <span style="color: red;">*</span></label>
            <select name="leave_type" id="leave_type" required>
              <option value="">-- Select Leave Type --</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlentities($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="reason">Reason <span style="color: red;">*</span></label>
<textarea name="reason" id="reason" rows="3" placeholder="Enter your reason..." required></textarea>          </div>

          <div class="form-group">
            <label for="start_date">Start Date <span style="color: red;">*</span></label>
<input type="text" id="start_date" name="start_date" placeholder="YYYY-MM-DD" required>          </div>

          <div class="form-group">
            <label for="end_date">End Date <span style="color:red;">*</span></label>
<input type="text" id="end_date" name="end_date" placeholder="YYYY-MM-DD" required>          </div>

          <div class="form-group">
            <label for="total_days">Total Days <span style="color:red;">*</span></label>
<input type="number" step="0.5" min="0.5" id="total_days" name="total_days"
       placeholder="e.g. 1.5" required>
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
        <a href="emp-dashboard.php" style="color:#2563eb; text-decoration:none;">← Back to Dashboard</a>
      </div>
    </div>

    <footer>
      &copy; <?= date('Y') ?> Teraju HR System
    </footer>
  </div>
</div>

<script>
// Flatpickr: only disable Sundays + ensure end date not before start date.
function disableSundays(date) { return date.getDay() === 0; }

const startPicker = flatpickr("#start_date", {
  dateFormat: "Y-m-d",
  disable: [disableSundays],
  onChange: function() {
    endPicker.set('minDate', startPicker.selectedDates[0] || null);
  }
});

const endPicker = flatpickr("#end_date", {
  dateFormat: "Y-m-d",
  disable: [disableSundays],
});
</script>

</body>
</html>