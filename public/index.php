<?php
// Start session if needed
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teraju LMS</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
<?php require_once "../includes/header.php";?>

        <main>
            <div class="card">
                <h2>Welcome!</h2>
                <p class="intro-text">Get started by logging in or creating a new account.</p>
                <div class="buttons">
                    <a href="login.php" class="btn btn-full">Login</a>
                    <a href="register.php" class="btn btn-outline">Register</a>
                </div>
            </div>
        </main>

        <?php require_once "../includes/footer.php"; ?>
    </div>
</body>
</html>
