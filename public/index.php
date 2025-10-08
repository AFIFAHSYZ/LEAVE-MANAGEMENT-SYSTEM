<?php
session_start();
error_reporting(0);
include('../config/conn.php');

if(isset($_POST['signin'])) {
    $uname = $_POST['username'];
    $password = md5($_POST['password']);

    $sql ="SELECT EmailId,Password,Status,id FROM tblemployees WHERE EmailId=:uname AND Password=:password";
    $query = $dbh->prepare($sql);
    $query->bindParam(':uname', $uname, PDO::PARAM_STR);
    $query->bindParam(':password', $password, PDO::PARAM_STR);
    $query->execute();
    $results = $query->fetchAll(PDO::FETCH_OBJ);

    if($query->rowCount() > 0) {
        foreach ($results as $result) {
            $status = $result->Status;
            $_SESSION['eid'] = $result->id;
        } 
        if($status==0) {
            $msg = "Your account is Inactive. Please contact admin";
        } else {
            $_SESSION['emplogin'] = $_POST['username'];
            echo "<script type='text/javascript'> document.location = 'dashboard.php'; </script>";
        } 
    } else {
        echo "<script>alert('Invalid Details');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teraju LMS | Employee Login</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <header>
        <h1>Teraju Leave Management System</h1>
    </header>

    <main>
        <div class="login-card">
            <h2>Employee Login</h2>
            <?php if($msg){?><div class="errorWrap"><?php echo htmlentities($msg); ?> </div><?php } ?>
            <form method="post">
                <label for="username">Email ID</label>
                <input type="text" id="username" name="username" required autocomplete="off">

                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="off">

                <button type="submit" name="signin">Sign In</button>
            </form>
            <div class="links">
                <a href="forgot-password.php">Forgot Password?</a> | 
                <a href="../admin/">Admin Login</a>
            </div>
        </div>
    </main>

<?php include('../includes/footer.php'); ?>
</body>
</html>
