<?php
require_once '../config/conn.php';

// Helper function to sanitize input
function clean_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

$registrationSuccess = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean_input($_POST['name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $position = $_POST['position'] ?? 'employee';

    // Handle Race
    $race = $_POST['race'] ?? '';
    if ($race === 'other') {
        $race_other = clean_input($_POST['race_other'] ?? '');
        $race = $race_other ?: 'Other';
    }

    // Handle Religion
    $religion = $_POST['religion'] ?? '';
    if ($religion === 'other') {
        $religion_other = clean_input($_POST['religion_other'] ?? '');
        $religion = $religion_other ?: 'Other';
    }

    if (!$name || !$email || !$password || !$race || !$religion) {
        $error = "All fields are required!";
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = "Email already registered!";
            } else {
                // Hash the password
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                // Insert user into DB
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, position, race, religion) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $passwordHash, $position, $race, $religion]);

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
</head>
<style>/* Reset & Base Styles */
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Roboto', sans-serif; }
body { background: linear-gradient(135deg, #e0e7ff, #eef2f7); color: #2c3e50; line-height: 1.6; min-height: 100vh; }
main { width: 100%; display: flex; justify-content: center; }

/* Registration Page */
.reg-container { display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }

.reg-container header { text-align: center; margin-bottom: 40px; }
.reg-container header h1 { font-size: 2rem; color: #1f3b4d; font-weight: 700; margin-bottom: 10px; }
.reg-container header p { font-size: 1rem; color: #4f6d8f; }

.reg-container .card { width: 100%; max-width: 450px; background: #ffffffcc; backdrop-filter: blur(8px); border-radius: 16px; padding: 35px 30px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); transition: transform 0.3s ease, box-shadow 0.3s ease; }
.reg-container .card:hover { transform: translateY(-5px); box-shadow: 0 20px 45px rgba(0,0,0,0.15); }
.reg-container .card h2 { text-align: center; margin-bottom: 25px; color: #1f3b4d; font-weight: 700; }

.reg-container .form-group { margin-bottom: 20px; }
.reg-container .form-group label { display: block; margin-bottom: 6px; font-weight: 500; color: #34495e; }
.reg-container .form-group input,
.reg-container .form-group select { width: 100%; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 12px; font-size: 1rem; background: #f9fafb; color: #1f3b4d; transition: all 0.25s ease; }
.reg-container .form-group input:focus,
.reg-container .form-group select:focus { border-color: #4f9eff; box-shadow: 0 0 0 3px rgba(79,158,255,0.2); outline: none; }

.reg-container button.btn-full { width: 100%; background-color: #4f9eff; color: #fff; padding: 14px; font-size: 1rem; font-weight: 600; border: none; border-radius: 12px; cursor: pointer; transition: all 0.25s ease; }
.reg-container button.btn-full:hover { background-color: #3a7ddd; transform: translateY(-2px); }

.reg-container .error-box { background-color: #ffe6e6; color: #e74c3c; padding: 12px; margin-bottom: 20px; border-radius: 10px; font-size: 0.95rem; text-align: center; }

.reg-container .form-footer { text-align: center; margin-top: 15px; font-size: 0.9rem; color: #64748b; }
.reg-container .form-footer a { color: #4f9eff; text-decoration: none; font-weight: 500; }
.reg-container .form-footer a:hover { text-decoration: underline; }

/* Responsive */
@media(max-width: 500px) {
    .reg-container .card { padding: 30px 20px; }
    .reg-container header h1 { font-size: 1.6rem; }
    .reg-container header p { font-size: 0.95rem; }
}
</style>
<body>
<div class="reg-container">
    <header>
        <h1>Teraju Leave Management System</h1>
        <p>Register your account</p>
    </header>
    <main>
        <div class="card">
            <h2>Create Account</h2>

            <!-- Display popup and redirect if registration is successful -->
            <?php if ($registrationSuccess): ?>
                <script>
                    alert('Registration successful! You can now login.');
                    window.location.href = 'login.php';
                </script>
            <?php endif; ?>

            <!-- Display error message -->
            <?php if ($error): ?>
                <div class="error-box"><?php echo $error; ?></div>
            <?php endif; ?>

            <!-- Show form only if registration not successful -->
            <?php if (!$registrationSuccess): ?>
                <form action="register.php" method="POST">
                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" required value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="position">Position</label>
                        <select id="position" name="position">
                            <option value="employee" <?php if (isset($position) && $position == "employee") echo "selected"; ?>>Employee</option>
                            <option value="manager" <?php if (isset($position) && $position == "manager") echo "selected"; ?>>Manager</option>
                            <option value="HR" <?php if (isset($position) && $position == "HR") echo "selected"; ?>>HR</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="race">Race</label>
                        <select id="race" name="race" onchange="toggleOther('race')">
                            <option value="malay" <?php if (isset($race) && $race == "malay") echo "selected"; ?>>Malay</option>
                            <option value="chinese" <?php if (isset($race) && $race == "chinese") echo "selected"; ?>>Chinese</option>
                            <option value="indian" <?php if (isset($race) && $race == "indian") echo "selected"; ?>>Indian</option>
                            <option value="other" <?php if (isset($race) && $race == "other") echo "selected"; ?>>Other</option>
                        </select>
                        <input type="text" id="race_other" name="race_other" placeholder="Please specify" style="display:none;" value="<?php echo isset($race_other) ? $race_other : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="religion">Religion</label>
                        <select id="religion" name="religion" onchange="toggleOther('religion')">
                            <option value="islam" <?php if (isset($religion) && $religion == "islam") echo "selected"; ?>>Islam</option>
                            <option value="buddhism" <?php if (isset($religion) && $religion == "buddhism") echo "selected"; ?>>Buddhism</option>
                            <option value="christianity" <?php if (isset($religion) && $religion == "christianity") echo "selected"; ?>>Christianity</option>
                            <option value="hinduism" <?php if (isset($religion) && $religion == "hinduism") echo "selected"; ?>>Hinduism</option>
                            <option value="other" <?php if (isset($religion) && $religion == "other") echo "selected"; ?>>Other</option>
                        </select>
                        <input type="text" id="religion_other" name="religion_other" placeholder="Please specify" style="display:none;" value="<?php echo isset($religion_other) ? $religion_other : ''; ?>">
                    </div>

                    <script>
                    function toggleOther(field) {
                        const select = document.getElementById(field);
                        const otherInput = document.getElementById(field + '_other');
                        if (select.value === 'other') {
                            otherInput.style.display = 'block';
                        } else {
                            otherInput.style.display = 'none';
                        }
                    }

                    // To show "Other" input if already selected (for edit forms)
                    window.onload = function() {
                        toggleOther('race');
                        toggleOther('religion');
                    }
                    </script>
                    <button type="submit" class="btn-full">Register</button>
                </form>
                <div class="form-footer">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <?php require_once "../includes/footer.php";?>
</div>
</body>
</html>
