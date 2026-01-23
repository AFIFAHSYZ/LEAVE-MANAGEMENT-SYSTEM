<?php
require_once __DIR__ . '/../public/config/conn1.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function makeMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $_ENV['SMTP_USER'] ?? '';
    $mail->Password = $_ENV['SMTP_PASS'] ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);

    $fromEmail = $_ENV['MAIL_FROM'] ?? $mail->Username;
    $fromName  = $_ENV['MAIL_FROM_NAME'] ?? 'Teraju HR System';
    $mail->setFrom($fromEmail, $fromName);

    $mail->isHTML(false); // plain text
    return $mail;
}

$limit = 20;

// pick pending emails safely (Postgres: FOR UPDATE SKIP LOCKED)
$stmt = $pdo->prepare("
    SELECT id, to_email, subject, body, attempts
    FROM email_queue
    WHERE status = 'pending' AND attempts < 5
    ORDER BY created_at ASC
    LIMIT :limit
    FOR UPDATE SKIP LOCKED
");

$pdo->beginTransaction();
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pdo->commit();

foreach ($rows as $row) {
    try {
        $mail = makeMailer();
        $mail->addAddress($row['to_email']);
        $mail->Subject = $row['subject'];
        $mail->Body = $row['body'];
        $mail->send();

        $upd = $pdo->prepare("UPDATE email_queue SET status='sent', sent_at=NOW() WHERE id=:id");
        $upd->execute([':id' => $row['id']]);
    } catch (Exception $e) {
        $upd = $pdo->prepare("
            UPDATE email_queue
            SET status='failed',
                attempts = attempts + 1,
                last_error = :err
            WHERE id = :id
        ");
        $upd->execute([':id' => $row['id'], ':err' => $e->getMessage()]);
    }
}