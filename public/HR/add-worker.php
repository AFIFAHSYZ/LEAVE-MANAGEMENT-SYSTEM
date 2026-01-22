<?php
session_start();
require_once '../../config/conn1.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT name FROM users WHERE id = :id");
$stmt->execute([':id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$success = $error = "";

// Helper function to sanitize input
function clean_input($data) {
    return htmlspecialchars(stripslashes(trim((string)$data)));
}

/**
 * Normalize contract selection to boolean stored in DB.
 */
function normalize_contract($val) {
    if ($val === 'yes') return true;
    if ($val === 'no') return false;
    return null;
}

/**
 * Determine tenure in full years between join date and now.
 */
function tenure_years_from_date(?string $join_date): int {
    if (empty($join_date)) return 0;
    try {
        $join = new DateTime($join_date);
        $now = new DateTime();
        $diff = $now->diff($join);
        return (int)$diff->y;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Months worked in current year for pro-rata accrual up to current month (inclusive).
 * If join_date is before Jan 1 of the year, months = current month number.
 */
function months_worked_this_year(?string $join_date): int {
    $now = new DateTime();
    $year = (int)$now->format('Y');
    $currentMonth = (int)$now->format('n'); // 1-12

    if (empty($join_date)) {
        return $currentMonth;
    }

    try {
        $join = new DateTime($join_date);
    } catch (Exception $e) {
        return $currentMonth;
    }

    // If joined in future year -> 0 months
    $joinYear = (int)$join->format('Y');
    $joinMonth = (int)$join->format('n');

    if ($joinYear > $year) {
        return 0;
    }

    if ($joinYear < $year) {
        // joined before this year -> accrual from Jan 1 -> months = current month
        return $currentMonth;
    }

    // joined this year -> months from joinMonth to currentMonth inclusive (if joined after current month -> 0)
    if ($joinMonth > $currentMonth) return 0;
    return $currentMonth - $joinMonth + 1;
}

/**
 * Find a matching tenure policy row for a leave type, tenure and contract flag.
 * Returns associative row or false if not found.
 */
function find_tenure_policy(PDO $pdo, int $leave_type_id, int $tenureYears, bool $isContract) {
    $sql = "
        SELECT id, leave_type_id, min_years, max_years, days_per_year, is_contract
        FROM leave_tenure_policy
        WHERE leave_type_id = :lt
          AND is_contract = :is_contract
          AND :tenure >= min_years
          AND (max_years IS NULL OR :tenure <= max_years)
        ORDER BY min_years DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lt', $leave_type_id, PDO::PARAM_INT);
    $stmt->bindValue(':is_contract', $isContract, PDO::PARAM_BOOL);
    $stmt->bindValue(':tenure', $tenureYears, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Name-matching helpers (case-insensitive).
 */
function name_contains(string $haystack, array $needles): bool {
    $hay = strtolower($haystack);
    foreach ($needles as $n) {
        if (strpos($hay, strtolower($n)) !== false) return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = strtoupper(clean_input($_POST['name'] ?? ''));
    $position = $_POST['position'] ?? 'employee';
    $race = $_POST['race'] ?? '';
    if ($race === 'other') $race = clean_input($_POST['race_other'] ?? 'Other');
    $religion = $_POST['religion'] ?? '';
    if ($religion === 'other') $religion = clean_input($_POST['religion_other'] ?? 'Other');
    $project = strtoupper(clean_input($_POST['project'] ?? ''));
    $contract_raw = $_POST['contract'] ?? null;
    $contract = normalize_contract($contract_raw);
    // New field: date_joined (expects YYYY-MM-DD)
    $date_joined = clean_input($_POST['date_joined'] ?? '');

    if (empty($name) || empty($race) || empty($religion) || empty($project) || $contract === null || empty($date_joined)) {
        $error = "All fields are required!";
    }

    // Validate date_joined format (basic)
    if (!$error) {
        $dt_ok = true;
        try {
            $d = new DateTime($date_joined);
            // optional: don't allow future join date
            $now = new DateTime();
            if ($d > $now) {
                $dt_ok = false;
            }
        } catch (Exception $e) {
            $dt_ok = false;
        }
        if (!$dt_ok) {
            $error = "Invalid join date.";
        }
    }

    if (!$error) {
        try {
            // Insert user and return id (Postgres RETURNING)
            $ins = $pdo->prepare("INSERT INTO users
                (name, position, race, religion, project, contract, date_joined)
                VALUES (:name, :position, :race, :religion, :project, :contract, :date_joined)
                RETURNING id
            ");
            $ins->bindValue(':name', $name, PDO::PARAM_STR);
            $ins->bindValue(':position', $position, PDO::PARAM_STR);
            $ins->bindValue(':race', $race, PDO::PARAM_STR);
            $ins->bindValue(':religion', $religion, PDO::PARAM_STR);
            $ins->bindValue(':project', $project, PDO::PARAM_STR);
            $ins->bindValue(':contract', $contract, PDO::PARAM_BOOL);
            $ins->bindValue(':date_joined', $date_joined, PDO::PARAM_STR);
            $ins->execute();
            $new_user_id = $ins->fetchColumn();

            if (!$new_user_id) {
                throw new PDOException("Failed to retrieve new user id.");
            }

            // Initialize leave_balances according to updated rules
            $pdo->beginTransaction();

            // Fetch leave types (id, name, default_limit)
            $ltStmt = $pdo->query("SELECT id, name, COALESCE(default_limit,0) AS default_limit FROM leave_types");
            $leaveTypes = $ltStmt->fetchAll(PDO::FETCH_ASSOC);

            $year = (int)date('Y');
            $tenureYears = tenure_years_from_date($date_joined);
            $isContract = (bool)$contract;
            $monthsThisYear = months_worked_this_year($date_joined);

            $insBal = $pdo->prepare("
                INSERT INTO leave_balances
                (user_id, leave_type_id, year, used_days, carry_forward, entitled_days, total_available)
                VALUES (:uid, :lt, :yr, :used, :cf, :entitled, :total)
            ");

            foreach ($leaveTypes as $lt) {
                $ltid = (int)$lt['id'];
                $ltname = (string)$lt['name'];
                $lt_default = (int)$lt['default_limit'];

                $entitled = 0;
                $total_available = 0;
                $carry = 0;
                $used = 0;

                // Emergency leave should be unavailable for contract workers
                if ($isContract && name_contains($ltname, ['emergency', 'emg', 'urgent'])) {
                    continue;
                }

                // Hospitalized & Maternity: include according to default_limit (both contract and non-contract)
                if (name_contains($ltname, ['hospital', 'hospitalized', 'matern', 'maternity'])) {
                    $entitled = $lt_default;
                    $total_available = $lt_default;
                }
                // Sick Leave (id = 2) uses tenure policy
                elseif ($ltid === 2) {
                    $policy = find_tenure_policy($pdo, $ltid, $tenureYears, $isContract);
                    if ($policy) {
                        $entitled = (int)$policy['days_per_year'];
                        $total_available = $entitled;
                    } else {
                        $entitled = $lt_default;
                        $total_available = $entitled;
                    }
                }
                // Annual Leave (EL) -> according to tenure policy and joined date (accrual monthly) for ALL workers
                elseif (name_contains($ltname, ['annual', 'annual leave', 'el', 'earned leave', 'al'])) {
                    $policy = find_tenure_policy($pdo, $ltid, $tenureYears, $isContract);
                    $days_per_year = $policy ? (int)$policy['days_per_year'] : $lt_default;

                    $monthly_accrual = $days_per_year / 12.0;

                    // months to accrue this year
                    if (empty($date_joined)) {
                        $months_elapsed = (int)date('n');
                    } else {
                        try {
                            $join = new DateTime($date_joined);
                            $join_year = (int)$join->format('Y');
                            $join_month = (int)$join->format('n');
                            $current_year = (int)date('Y');
                            $current_month = (int)date('n');

                            if ($join_year < $current_year) {
                                $months_elapsed = $current_month;
                            } elseif ($join_year > $current_year) {
                                $months_elapsed = 0;
                            } else {
                                $months_elapsed = $current_month - $join_month + 1;
                                if ($months_elapsed < 0) $months_elapsed = 0;
                            }
                        } catch (Exception $e) {
                            $months_elapsed = (int)date('n');
                        }
                    }

                    // carry_forward left as 0 for new employees
                    $carry = 0;
                    $entitled = $days_per_year;
                    $total_available = (int) floor($carry + ($monthly_accrual * max(0, $months_elapsed)));
                }
                // Other leave types: try tenure policy, otherwise use default_limit
                else {
                    $policy = find_tenure_policy($pdo, $ltid, $tenureYears, $isContract);
                    if ($policy) {
                        $entitled = (int)$policy['days_per_year'];
                        $total_available = $entitled;
                    } else {
                        $entitled = $lt_default;
                        $total_available = $entitled;
                    }
                }

                // Insert only meaningful balances (entitled > 0 or available > 0)
                if ($entitled > 0 || $total_available > 0) {
                    $insBal->execute([
                        ':uid' => $new_user_id,
                        ':lt' => $ltid,
                        ':yr' => $year,
                        ':used' => $used,
                        ':cf' => $carry,
                        ':entitled' => $entitled,
                        ':total' => $total_available
                    ]);
                }
            }

            $pdo->commit();

            $success = "✅ Worker added successfully! Leave balances initialized for year {$year}.";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "❌ Failed to add worker: " . $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "❌ Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Worker | Teraju HR System</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
.card {background: #fff; padding: 30px 40px; border-radius: 12px; box-shadow: 0 3px 10px rgba(0,0,0,0.1); max-width: 900px; margin: 0 auto;}
.card h2 {text-align: center; margin-bottom: 20px;}
.form-grid {display: grid; grid-template-columns: 1fr 1fr; gap: 20px 30px;}
.form-group {display: flex; flex-direction: column;}
.form-group label {font-weight: 600; margin-bottom: 6px;}
.form-group input, .form-group select {width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #d1d5db; background: #f9fafb;}
@media (max-width: 768px) {.form-grid {grid-template-columns: 1fr;}}
.btn-full {display: block; width: 100%; padding: 12px; background: #3a4750; color: #fff; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; transition: background 0.3s;}
.btn-full:hover {background: #273036ff;}
.success-box, .error-box {padding: 10px; border-radius: 6px; margin-bottom: 15px;}
.success-box { background: #dcfce7; color: #166534; }
.error-box { background: #fee2e2; color: #991b1b; }
footer {text-align: center; margin-top: 40px; color: #666; font-size: 0.9rem;}
</style>
</head>
<body>
<div class="layout">
    <?php include "sidebar.php"; ?>

    <header>
        <h1>Teraju HR System</h1>
    </header>

    <div class="main-content">
        <div class="card">
            <h2>Add New Worker</h2>

            <?php if ($error): ?>
                <div class="error-box"><?= htmlentities($error) ?></div>
            <?php elseif ($success): ?>
                <div class="success-box"><?= htmlentities($success) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" placeholder="eg. Ali Bin Abu" required value="<?= htmlspecialchars($name ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Position</label>
                        <select name="position">
                            <option value="employee" <?= isset($position) && $position=='employee'?'selected':'' ?>>Employee</option>
                            <option value="manager" <?= isset($position) && $position=='manager'?'selected':'' ?>>Manager</option>
                            <option value="hr" <?= isset($position) && $position=='hr'?'selected':'' ?>>HR</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Race</label>
                        <select name="race" onchange="toggleOther('race')">
                            <option value="malay" <?= isset($race) && $race=='malay'?'selected':'' ?>>Malay</option>
                            <option value="chinese" <?= isset($race) && $race=='chinese'?'selected':'' ?>>Chinese</option>
                            <option value="indian" <?= isset($race) && $race=='indian'?'selected':'' ?>>Indian</option>
                            <option value="other" <?= isset($race) && !in_array($race,['malay','chinese','indian'])?'selected':'' ?>>Other</option>
                        </select>
                        <input type="text" id="race_other" name="race_other" placeholder="Specify" style="display:none;" value="<?= htmlspecialchars($race ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Religion</label>
                        <select name="religion" onchange="toggleOther('religion')">
                            <option value="islam" <?= isset($religion) && $religion=='islam'?'selected':'' ?>>Islam</option>
                            <option value="buddhism" <?= isset($religion) && $religion=='buddhism'?'selected':'' ?>>Buddhism</option>
                            <option value="christianity" <?= isset($religion) && $religion=='christianity'?'selected':'' ?>>Christianity</option>
                            <option value="hinduism" <?= isset($religion) && $religion=='hinduism'?'selected':'' ?>>Hinduism</option>
                            <option value="other" <?= isset($religion) && !in_array($religion,['islam','buddhism','christianity','hinduism'])?'selected':'' ?>>Other</option>
                        </select>
                        <input type="text" id="religion_other" name="religion_other" placeholder="Specify" style="display:none;" value="<?= htmlspecialchars($religion ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Project</label>
                        <input type="text" name="project" placeholder="Eg. " required value="<?= htmlspecialchars($project ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Contract</label>
                        <select name="contract" required>
                            <option value="" disabled selected>-- Select --</option>
                            <option value="yes" <?= isset($contract_raw) && $contract_raw=='yes'?'selected':'' ?>>Yes</option>
                            <option value="no" <?= isset($contract_raw) && $contract_raw=='no'?'selected':'' ?>>No</option>
                        </select>
                    </div>

                    <!-- new date_joined field -->
                    <div class="form-group">
                        <label>Date Joined</label>
                        <input type="date" name="date_joined" required value="<?= htmlspecialchars($date_joined ?? '') ?>">
                    </div>
                </div>

                <button type="submit" class="btn-full" style="margin-top:20px;">Add Worker</button>
            </form>
        </div>

        <footer>
            &copy; <?= date('Y') ?> Teraju HR System
        </footer>
    </div>
</div>

<script>
function toggleOther(field) {
    const sel = document.querySelector(`select[name="${field}"]`);
    const input = document.getElementById(field+'_other');
    input.style.display = sel.value==='other'?'block':'none';
}
window.onload = function() {
    toggleOther('race');
    toggleOther('religion');
};
</script>

<script src="../../assets/js/sidebar.js"></script>
</body>
</html>