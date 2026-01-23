<?php
session_start();
require_once '../../config/conn1.php';

/* =========================
   AUTH: HR ONLY
   ========================= */
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$stmt = $pdo->prepare("SELECT id, name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || ($user['position'] ?? '') !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

/* =========================
   HELPERS
   ========================= */
function clean_input($v): string {
    return trim(htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'));
}

/**
 * Encode an uploaded file for storage in a BYTEA column.
 */
function encode_uploaded_file(array $file, int $maxBytes = 8388608, array $allowedMime = []): array {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['data' => null, 'type' => null, 'name' => null, 'size' => null, 'error' => null];
    }

    $err = $file['error'] ?? UPLOAD_ERR_OK;
    if ($err !== UPLOAD_ERR_OK) {
        return [
            'data' => null, 'type' => null, 'name' => null,
            'size' => (int)($file['size'] ?? 0),
            'error' => "File upload error (code: $err)."
        ];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size > $maxBytes) {
        return [
            'data' => null, 'type' => null, 'name' => $file['name'] ?? null, 'size' => $size,
            'error' => "Attachment too large. Max " . ($maxBytes / 1024 / 1024) . "MB allowed."
        ];
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
    if (!$mime) $mime = $file['type'] ?? 'application/octet-stream';

    if (!empty($allowedMime) && !in_array($mime, $allowedMime, true)) {
        return ['data' => null, 'type' => $mime, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => "File type not allowed: $mime"];
    }

    $data = @file_get_contents($tmp);
    if ($data === false) {
        return ['data' => null, 'type' => $mime, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => "Failed to read uploaded file."];
    }

    return ['data' => $data, 'type' => $mime, 'name' => $file['name'] ?? null, 'size' => $size, 'error' => null];
}

/**
 * Validate total days: numeric, >0, 0.5 increments
 */
function validate_total_days($raw): float {
    if ($raw === '' || $raw === null) {
        throw new Exception("Please enter Total Days.");
    }
    if (!is_numeric($raw)) {
        throw new Exception("Total Days must be a number.");
    }
    $td = (float)$raw;
    if ($td <= 0) {
        throw new Exception("Total Days must be greater than 0.");
    }
    if (fmod($td, 0.5) !== 0.0) {
        throw new Exception("Total Days must be in 0.5 increments (e.g. 0.5, 1.0, 1.5...).");
    }
    return $td;
}

/* =========================
   STATE
   ========================= */
$success = $error = "";
$old = [
    'user_id' => '',
    'worker_name' => '',
    'leave_type_id' => '',
    'start_date' => '',
    'end_date' => '',
    'total_days' => '',
    'reason' => ''
];

/* =========================
   SUBMIT (TRUST TYPED TOTAL DAYS)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['user_id'] = (int)($_POST['user_id'] ?? 0);
    $old['worker_name'] = clean_input($_POST['worker_name'] ?? '');
    $old['leave_type_id'] = (int)($_POST['leave_type_id'] ?? 0);
    $old['start_date'] = clean_input($_POST['start_date'] ?? '');
    $old['end_date'] = clean_input($_POST['end_date'] ?? '');
    $old['total_days'] = clean_input($_POST['total_days'] ?? '');
    $old['reason'] = clean_input($_POST['reason'] ?? '');

    if ($old['user_id'] <= 0) {
        $error = "Please select a worker.";
    } elseif ($old['leave_type_id'] <= 0) {
        $error = "Please select a leave type.";
    } elseif ($old['start_date'] === '') {
        $error = "Please provide a start date.";
    } elseif ($old['end_date'] === '') {
        $error = "Please provide an end date.";
    } elseif ($old['reason'] === '') {
        $error = "Please provide a reason.";
    }

    // Verify worker exists
    if (!$error) {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
        $stmt->execute([$old['user_id']]);
        $worker = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$worker) $error = "Selected worker not found.";
    }

    if (!$error) {
        try {
            $total_days = validate_total_days($old['total_days']);
            $old['total_days'] = (string)$total_days;

            // attachment
            $allowedMime = [
                'application/pdf', 'image/jpeg', 'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ];
            $fileRes = encode_uploaded_file($_FILES['attachment'] ?? [], 8 * 1024 * 1024, $allowedMime);
            if ($fileRes['error']) throw new Exception($fileRes['error']);

            // reason note
            $reason_to_store = $old['reason'];
            if ($old['worker_name'] !== '') {
                $reason_to_store .= "\n\n[Worker Name Override: " . $old['worker_name'] . "]";
            }

            $sql = "
                INSERT INTO leave_requests
                (user_id, leave_type_id, start_date, end_date, reason, total_days, attachment, attachment_type,
                 approved_by, verified_by, verified_at, status)
                VALUES
                (:uid, :lt, :sd, :ed, :reason, :td, :attachment, :attachment_type,
                 NULL, :verified_by, NOW(), 'verified')
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':uid', $old['user_id'], PDO::PARAM_INT);
            $stmt->bindValue(':lt', $old['leave_type_id'], PDO::PARAM_INT);
            $stmt->bindValue(':sd', $old['start_date'], PDO::PARAM_STR);
            $stmt->bindValue(':ed', $old['end_date'], PDO::PARAM_STR);
            $stmt->bindValue(':reason', $reason_to_store, PDO::PARAM_STR);
            $stmt->bindValue(':td', (string)$total_days, PDO::PARAM_STR);

            if ($fileRes['data'] !== null) {
                $stmt->bindValue(':attachment', $fileRes['data'], PDO::PARAM_LOB);
                $stmt->bindValue(':attachment_type', $fileRes['type'], PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':attachment', null, PDO::PARAM_NULL);
                $stmt->bindValue(':attachment_type', null, PDO::PARAM_NULL);
            }

            $stmt->bindValue(':verified_by', (int)$user['id'], PDO::PARAM_INT);

            $stmt->execute();

            $success = "Leave request submitted and verified by HR. Total Days: " . htmlspecialchars($old['total_days']);
            $old = array_fill_keys(array_keys($old), '');
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

/* =========================
   DATA FOR FORM
   ========================= */
$workers = $pdo->query("
    SELECT id, name, project, contract
    FROM users
    WHERE project != 'HQ'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$leave_types = $pdo->query("
    SELECT id, name
    FROM leave_types
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Worker Leave Request | Teraju HR System</title>
<link rel="stylesheet" href="../../assets/css/style.css">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

<style>
.card {background:#fff; padding:30px 40px; border-radius:12px; box-shadow:0 3px 10px rgba(0,0,0,0.1); max-width:900px; margin:0 auto;}
.card h2 {text-align:center; margin-bottom:20px;}
.form-grid {display:grid; grid-template-columns:1fr 1fr; gap:20px 30px;}
.form-group {display:flex; flex-direction:column;}
.form-group label {font-weight:600; margin-bottom:6px;}
.form-group input, .form-group select, .form-group textarea {width:100%; padding:10px; border-radius:8px; border:1px solid #d1d5db; background:#f9fafb; font-size:0.95rem;}
.form-group textarea {min-height:100px; resize:vertical;}
@media (max-width:768px){.form-grid{grid-template-columns:1fr;}}
.success-box,.error-box{padding:10px;border-radius:6px;margin-bottom:15px;}
.success-box{background:#dcfce7;color:#166534;}
.error-box{background:#fee2e2;color:#991b1b;}
.layout{display:flex;}
.form-actions{display:flex;justify-content:flex-end;align-items:center;margin-top:18px;}
.btn-full{background:#2563eb;color:#fff;border:none;padding:10px 14px;border-radius:8px;cursor:pointer;}
.btn-full:hover{background:#1d4ed8;}
.small-note{color:#555;font-size:0.9rem;}
</style>
</head>
<body>
<div class="layout">
    <?php include "sidebar.php"; ?>

    <div class="main-content">
        <header><h1>Teraju HR System</h1></header>

        <div class="card">
            <h2>Worker Leave Request</h2>

            <?php if ($error): ?>
                <div class="error-box"><?= htmlentities($error) ?></div>
            <?php elseif ($success): ?>
                <div class="success-box"><?= htmlentities($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" novalidate>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Select Worker <span style="color:red;">*</span></label>
                        <select name="user_id" required>
                            <option value="">-- Select Worker --</option>
                            <?php foreach ($workers as $w): ?>
                                <option value="<?= (int)$w['id'] ?>" <?= ((int)$old['user_id'] === (int)$w['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($w['name']) ?> (<?= htmlspecialchars($w['project']) ?>)
                                    <?= $w['contract'] ? '[Contract]' : '[Permanent]' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Worker Name (Optional Override)</label>
                        <input type="text" name="worker_name" placeholder="Fill only if actual worker name differs" value="<?= htmlspecialchars($old['worker_name']) ?>">
                    </div>

                    <div class="form-group">
                        <label>Leave Type <span style="color:red;">*</span></label>
                        <select name="leave_type_id" id="leave_type_id" required>
                            <option value="">-- Select Leave Type --</option>
                            <?php foreach ($leave_types as $lt): ?>
                                <option value="<?= (int)$lt['id'] ?>" <?= ((int)$old['leave_type_id'] === (int)$lt['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($lt['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Total Days <span style="color:red;">*</span></label>
                        <input type="number" step="0.5" min="0.5" id="total_days" name="total_days"
                               placeholder="e.g. 1.5" required value="<?= htmlspecialchars($old['total_days']) ?>">
                    </div>

                    <div class="form-group">
                        <label>Start Date <span style="color:red;">*</span></label>
                        <input type="text" id="start_date" name="start_date" required value="<?= htmlspecialchars($old['start_date']) ?>">
                    </div>

                    <div class="form-group">
                        <label>End Date <span style="color:red;">*</span></label>
                        <input type="text" id="end_date" name="end_date" required value="<?= htmlspecialchars($old['end_date']) ?>">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Reason <span style="color:red;">*</span></label>
                        <textarea name="reason" placeholder="Reason for requesting leave" required><?= htmlspecialchars($old['reason']) ?></textarea>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label>Attachment <span style="color:red;">*</span></label>
                        <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                        <small class="small-note">Max 8MB. Allowed: PDF, JPG, PNG, DOC/DOCX.</small>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-full">Submit Leave Request</button>
                </div>
            </form>
        </div>

        <footer style="text-align:center; margin-top:40px; color:#666;">
            &copy; <?= date('Y') ?> Teraju HR System
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Disable Sundays
function disableSundays(date) { return date.getDay() === 0; }

// Flatpickr with alt display (dd/mm/yyyy), submits Y-m-d
const startPicker = flatpickr("#start_date", {
  dateFormat: "Y-m-d",
  altInput: true,
  altFormat: "d/m/Y",
  altInputClass: "form-control",
  disable: [disableSundays],
  disableMobile: true,
  onChange: function() {
    endPicker.set('minDate', startPicker.selectedDates[0] || null);
  }
});

const endPicker = flatpickr("#end_date", {
  dateFormat: "Y-m-d",
  altInput: true,
  altFormat: "d/m/Y",
  altInputClass: "form-control",
  disable: [disableSundays],
  disableMobile: true
});
// Placeholders on the visible inputs
if (startPicker.altInput) startPicker.altInput.setAttribute('placeholder', 'dd/mm/yyyy');
if (endPicker.altInput) endPicker.altInput.setAttribute('placeholder', 'dd/mm/yyyy');

// Ensure end minDate on load (edit mode)
document.addEventListener('DOMContentLoaded', function() {
  endPicker.set('minDate', startPicker.selectedDates[0] || null);
});
</script>

<script src="../../assets/js/sidebar.js"></script>
</body>
</html>