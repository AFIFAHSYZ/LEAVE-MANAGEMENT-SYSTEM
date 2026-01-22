<?php
session_start();
require_once '../config/conn1.php'; 
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$id = $_GET['id'] ?? '';
$mode = $_GET['mode'] ?? 'view'; 
if (!$id) {
    http_response_code(400);
    exit('Invalid request.');
}

// Fetch leave request and attachment info
$stmt = $pdo->prepare("
    SELECT lr.id, lr.attachment, lr.attachment_type,  u.name AS employee_name, lr.start_date, lr.end_date, lt.name AS leave_type
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.id = :id
");
$stmt->execute([':id' => $id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    http_response_code(404);
    exit('Attachment not found.');
}

if (empty($request['attachment'])) {
    http_response_code(404);
    exit('No attachment for this request.');
}

// Normalize values
$attachmentValue = $request['attachment'];        // could be filename/path OR BLOB/resource
$attachmentType = $request['attachment_type'] ?? '';
$suggestedName = $request['attachment_name'] ?? (is_string($attachmentValue) ? basename($attachmentValue) : 'attachment');

// Path where uploaded files live if attachment stores a filename only
$uploadsDir = __DIR__ . '/../uploads/'; // adjust to your uploads folder

// Helper: determine if a string looks like an existing file path
function findFilePath($val, $uploadsDir) {
    if (!is_string($val) || $val === '') return false;
    // If absolute or relative path exists as is
    if (file_exists($val)) return $val;
    // Otherwise, try uploads dir + filename
    $try = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($val, DIRECTORY_SEPARATOR);
    if (file_exists($try)) return $try;
    return false;
}

// Serve raw bytes with inline disposition
if ($mode === 'raw') {
    // Decide MIME type
    $mime = $attachmentType;

    // Case: stored as a file path/filename
    $filePath = findFilePath($attachmentValue, $uploadsDir);
    if ($filePath !== false) {
        if (empty($mime)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
            finfo_close($finfo);
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        // Optional: support ranges for large files (not implemented here)
        readfile($filePath);
        exit();
    }

    // Case: stored as BLOB string or resource (PDO LOB)
    if (is_resource($attachmentValue) || is_string($attachmentValue)) {
        if (empty($mime)) $mime = 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($suggestedName) . '"');
        // If resource (stream), read and echo
        if (is_resource($attachmentValue)) {
            // Rewind if possible
            if (stream_get_meta_data($attachmentValue)) {
                @rewind($attachmentValue);
            }
            while (!feof($attachmentValue)) {
                echo fread($attachmentValue, 8192);
                @ob_flush();
                flush();
            }
            exit();
        } else {
            // binary string
            echo $attachmentValue;
            exit();
        }
    }

    // Fallback: cannot find/serve attachment
    http_response_code(500);
    exit('Unable to serve attachment.');
}

// mode === 'view' -> render UI page which embeds the raw stream
$rawUrl = htmlspecialchars(basename(__FILE__) . '?id=' . urlencode($request['id']) . '&mode=raw');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Attachment Preview - <?= htmlspecialchars($request['employee_name']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body { font-family: Arial, Helvetica, sans-serif; margin:0; padding:0; background:#f8fafc; color:#111827; }
.header { padding:16px; background:#fff; border-bottom:1px solid #e6edf3; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.meta { font-size:0.95rem; color:#475569; }
.controls { gap:8px; display:flex; align-items:center; }
.btn { padding:8px 12px; border-radius:6px; border:none; cursor:pointer; font-weight:600; }
.btn-print { background:#06b6d4; color:#fff; }
.btn-download { background:#2563eb; color:#fff; text-decoration:none; display:inline-block; padding:8px 12px; border-radius:6px; }
.viewer { padding:12px; height:calc(100vh - 76px); background:#ffffff; display:flex; align-items:center; justify-content:center; }
.viewer-embed { width:100%; height:100%; border:0; }
.fallback { text-align:center; color:#6b7280; }
/* Print-specific rules */
@page { margin: 12mm; } /* reduce if needed */
@media print {
  .header, .controls, nav, .back-link, .footer { display: none !important; }

  .viewer {
    padding: 0 !important;
    height: auto !important;
  }

 .viewer-embed, .viewer-embed img {
    width: 100% !important;
    height: auto !important;
    max-height: none !important;
    border: none !important;
    page-break-inside: avoid;
    page-break-before: auto;
    page-break-after: auto;
  }

  body { margin: 0 !important; }
}
</style>
<script>
function doPrint() {
    var frame = document.getElementById('attachmentFrame');
    // For PDFs in iframe, print the iframe content
    if (frame && frame.contentWindow) {
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
            return;
        } catch (e) {
            // fallback to window.print()
        }
    }
    window.print();
}
</script>
</head>
<body>
    <div class="header">
        <div>
            <div style="font-weight:700; font-size:1rem;">Attachment Preview</div>
            <div class="meta"><?= htmlspecialchars($request['employee_name']) ?> — <?= htmlspecialchars($request['leave_type'] ?? '') ?> (<?= htmlspecialchars($request['start_date']) ?> to <?= htmlspecialchars($request['end_date']) ?>)</div>
        </div>
        <div class="controls">
            <button class="btn btn-print" onclick="doPrint()">Print</button>
        </div>
    </div>

    <div class="viewer">
        <?php
        $mime = $attachmentType;
        if (empty($mime) && is_string($attachmentValue)) {
            $ext = strtolower(pathinfo($attachmentValue, PATHINFO_EXTENSION));
            $map = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg','jpeg' => 'image/jpeg',
                'png' => 'image/png','gif' => 'image/gif',
                'webp' => 'image/webp','bmp'=>'image/bmp','svg'=>'image/svg+xml'
            ];
            if (isset($map[$ext])) $mime = $map[$ext];
        }

        $canEmbedPdf = ($mime && stripos($mime, 'pdf') !== false);
        $canEmbedImage = ($mime && stripos($mime, 'image') !== false);

        if ($canEmbedPdf): ?>
            <iframe id="attachmentFrame" class="viewer-embed" src="<?= $rawUrl ?>" type="application/pdf"></iframe>
        <?php elseif ($canEmbedImage): ?>
            <img id="attachmentFrame" class="viewer-embed" src="<?= $rawUrl ?>" alt="Attachment image" style="object-fit:contain;" />
        <?php else: ?>
            <div class="fallback">
                <p>Preview for this file type may not be available. Click below to open in a new tab or download.</p>
                <p><a class="btn-full" href="<?= $rawUrl ?>" target="_blank" rel="noopener">Open in new tab / Download</a></p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>