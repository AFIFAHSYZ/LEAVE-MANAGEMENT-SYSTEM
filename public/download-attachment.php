<?php
// download-attachment.php
session_start();
require_once '../config/conn1.php';

// Require id param
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id || !isset($_SESSION['user_id'])) {
    http_response_code(404);
    exit('Not found');
}

$currentUserId = (int)$_SESSION['user_id'];

// Load leave request attachment and metadata
$stmt = $pdo->prepare("SELECT user_id, attachment, attachment_type FROM leave_requests WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['attachment'])) {
    http_response_code(404);
    exit('Attachment not found');
}

// Authorization: allow if current user is the requester or has HR/manager role
$allowed = false;
// check if requester
if ($currentUserId === (int)$row['user_id']) $allowed = true;

// check role
$u = $pdo->prepare("SELECT position FROM users WHERE id = :id LIMIT 1");
$u->execute([':id' => $currentUserId]);
$me = $u->fetchColumn();
if ($me && in_array(strtolower($me), ['hr', 'manager'], true)) $allowed = true;

if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

// Prepare filename: try to infer extension from MIME
$mime = $row['attachment_type'] ?: 'application/octet-stream';
$extMap = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
];
$ext = $extMap[$mime] ?? null;
$filename = 'attachment-' . $id . ($ext ? ('.' . $ext) : '');

// Output headers and binary
$data = $row['attachment'];
if (is_resource($data)) {
    // PDO may return a stream resource for large LOBs
    $contents = stream_get_contents($data);
} else {
    $contents = $data;
}

if ($contents === false || $contents === null) {
    http_response_code(500);
    exit('Failed to read attachment');
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . strlen($contents));
echo $contents;
exit();
?>