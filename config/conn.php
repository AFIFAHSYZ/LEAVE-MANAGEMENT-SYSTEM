<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dsn = "pgsql:host=127.0.0.1;port=5432;dbname=teraju";
$user = "postgres";
$password = "test123";

try {
    $pdo = new PDO($dsn, $user, $password);
    //echo "✅ Database connected successfully!";
} catch (PDOException $e) {
    echo "❌ Database connection failed:<br>";
    echo $e->getMessage();
}
