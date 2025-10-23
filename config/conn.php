<?php
$host = 'localhost';
$dbname = 'teraju';
$user = 'postgres';
$password = '######';
try {
    $dsn = "pgsql:host=$host;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    //echo "Database connected successfully!";
} catch (PDOException $e) {
   die("Database connection failed: " . $e->getMessage());
}


?>