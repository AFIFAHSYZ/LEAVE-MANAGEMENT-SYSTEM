<?php
session_start();
require '../vendor/autoload.php';
include '../config/conn.php'; // your PostgreSQL connection

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if (empty($email)) {
        die("❌ Please enter your email.");
    }

    try {
        // 1️⃣ Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            die("❌ No user found with that email.");
        }

        // 2️⃣ Generate token + expiry
        $token = bin2hex(random_bytes(16));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // 3️⃣ Save token + expiry
        $update = $pdo->prepare("
            UPDATE users 
            SET reset_token = :token, reset_expiry = :expiry 
            WHERE email = :email
        ");
        $update->execute([
            ':token' => $token,
            ':expiry' => $expiry,
            ':email' => $email
        ]);

        // 4️⃣ Build reset link
        $resetLink = "http://localhost/TERAJU/public/reset-password.php?token=$token";

        // 5️⃣ Send email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
           $mail->Username   = 'afifahsyazahda@gmail.com';   // ✅ your Gmail
            $mail->Password   = '#### #### ####';        // ✅ Gmail App Password
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('your_email@gmail.com', 'Leave Management System');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Link';
        $mail->Body = "
            <p>Hello,</p>
            <p>Click the link below to reset your password:</p>
            <a href='$resetLink'>$resetLink</a>
            <p>This link will expire in 1 hour.</p>
        ";

        $mail->send();
        echo "✅ Reset link sent to your email.";

    } catch (Exception $e) {
        echo "❌ Email could not be sent. Error: {$mail->ErrorInfo}";
    } catch (PDOException $e) {
        echo "❌ Database error: " . $e->getMessage();
    }
}
?>
