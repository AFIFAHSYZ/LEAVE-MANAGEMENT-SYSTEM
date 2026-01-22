<?php
require_once '../config/conn1.php';

// Helper function to sanitize input
function clean_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

$registrationSuccess = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect and clean inputs
// Force all uppercase
$name    = strtoupper(clean_input($_POST['name'] ?? ''));
    $email    = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $position = $_POST['position'] ?? 'employee';

    // Race
    $race = $_POST['race'] ?? '';
    if ($race === 'other') {
        $race = clean_input($_POST['race_other'] ?? 'Other');
    }

    // Religion
    $religion = $_POST['religion'] ?? '';
    if ($religion === 'other') {
        $religion = clean_input($_POST['religion_other'] ?? 'Other');
    }

    // Project
$project = strtoupper(clean_input($_POST['project'] ?? ''));

    // Contract (Yes/No → boolean)
    $contract_raw = $_POST['contract'] ?? null;
    if ($contract_raw === 'yes') {
        $contract = true;
    } elseif ($contract_raw === 'no') {
        $contract = false;
    } else {
        $contract = null; // Not selected
    }

    // Validate required fields
    if (
        empty($name) ||
        empty($email) ||
        empty($password) ||
        empty($race) ||
        empty($religion) ||
        empty($project) ||
        $contract === null
    ) {
        $error = "All fields are required!";
    }

    // Only proceed if no errors
    if (!$error) {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $error = "Email already registered!";
            } else {
                // Hash password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Prepare insert with named params and explicit boolean binding for contract
                $stmt = $pdo->prepare("
                    INSERT INTO users
                    (name, email, password, position, race, religion, project, contract)
                    VALUES (:name, :email, :password, :position, :race, :religion, :project, :contract)
                ");

                // Ensure $contract is strictly boolean (should be true/false by validation)
                $contractForBind = (bool)$contract;

                // Bind values explicitly
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
                $stmt->bindValue(':email', $email, PDO::PARAM_STR);
                $stmt->bindValue(':password', $passwordHash, PDO::PARAM_STR);
                $stmt->bindValue(':position', $position, PDO::PARAM_STR);
                $stmt->bindValue(':race', $race, PDO::PARAM_STR);
                $stmt->bindValue(':religion', $religion, PDO::PARAM_STR);
                $stmt->bindValue(':project', $project, PDO::PARAM_STR);
                $stmt->bindValue(':contract', $contractForBind, PDO::PARAM_BOOL);

                $stmt->execute();

                $registrationSuccess = true;
            }
        } catch (PDOException $e) {
            $error = "Server error: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | Teraju LMS</title>
    <link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
    <style>
        * {
            margin:0; padding:0; box-sizing:border-box; font-family:'Roboto',sans-serif;
        }
        body {
            background: linear-gradient(135deg,#e0e7ff,#eef2f7);
            color:#2c3e50;
            line-height:1.6;
            min-height:100vh;
        }
        main { width:100%; display:flex; justify-content:center; }
        .reg-container {
            display:flex; flex-direction:column; justify-content:center; align-items:center;
            min-height:100vh; padding:20px;
        }
        .reg-container header { text-align:center; margin-bottom:40px; }
        .reg-container header h1 { font-size:2rem; color:#1f3b4d; font-weight:700; margin-bottom:10px; }
        .reg-container header p { font-size:1rem; color:#4f6d8f; }
        .reg-container .card {
            width:100%; max-width:450px; background:#ffffffcc; backdrop-filter:blur(8px);
            border-radius:16px; padding:35px 30px; box-shadow:0 15px 35px rgba(0,0,0,0.1);
        }
        .reg-container .card h2 { text-align:center; margin-bottom:25px; color:#1f3b4d; font-weight:700; }
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; margin-bottom:6px; font-weight:500; color:#34495e; }
        .form-group input, .form-group select {
            width:100%; padding:12px 15px; border:1px solid #d1d5db; border-radius:12px;
            font-size:1rem; background:#f9fafb; color:#1f3b4d;
        }
        button.btn-full {
            width:100%; background-color:#334155; color:#fff; padding:14px;
            font-size:1rem; font-weight:600; border:none; border-radius:12px; cursor:pointer;
        }
        button.btn-full:hover { background-color:#3a7ddd; }
        .error-box {
            background-color:#ffe6e6; color:#e74c3c; padding:12px;
            margin-bottom:20px; border-radius:10px; font-size:0.95rem; text-align:center;
        }
        .form-footer { text-align:center; margin-top:15px; font-size:0.9rem; color:#64748b; }
        .form-footer a { color:#334155; text-decoration:none; font-weight:500; }
        .form-footer a:hover { text-decoration:underline; }
        @media(max-width:500px){
            .reg-container .card{padding:30px 20px;}
            .reg-container header h1{font-size:1.6rem;}
            .reg-container header p{font-size:0.95rem;}
        }
    </style>
</head>
<body>
<div class="reg-container">
    <header>
        <h1>Teraju Leave Management System</h1>
        <p>Register your account</p>
    </header>
    <main>
        <div class="card">
            <h2>Create Account</h2>

            <?php if ($registrationSuccess): ?>
                <script>
                    alert('Registration successful! You can now login.');
                    window.location.href = 'login.php';
                </script>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error-box"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$registrationSuccess): ?>
                <form method="POST" action="register.php">

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="name" required value="<?= htmlspecialchars($name ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($email ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required>
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

                    <div class="form-group">
                        <label>Project</label>
                        <input type="text" name="project" required value="<?= htmlspecialchars($project ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Contract</label>
                        <select name="contract" required>
                            <option value="" disabled selected>-- Select --</option>
                            <option value="yes" <?= isset($contract_raw) && $contract_raw=='yes'?'selected':'' ?>>Yes</option>
                            <option value="no" <?= isset($contract_raw) && $contract_raw=='no'?'selected':'' ?>>No</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-full">Register</button>
                </form>

                <div class="form-footer">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            <?php endif; ?>

        </div>
    </main>
    <?php require_once "../includes/footer.php"; ?>
</div>
</body>
</html>