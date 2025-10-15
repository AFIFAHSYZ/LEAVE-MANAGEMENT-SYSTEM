
<?php
session_start(); // must be first!

// Destroy all session data
$_SESSION = []; // clear session variables

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy(); // finally destroy the session

// Redirect to login page
header("Location: index.php");
exit();
?>
