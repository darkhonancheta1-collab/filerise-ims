<?php
// serve_upload.php — streams private uploaded files (currently: distributor
// photos) to an authenticated, authorized session only. Files handled here
// are NOT reachable directly under uploads/ — see uploads/private/.htaccess.
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden.');
}
$user_id = $_SESSION['user_id'];

$type = $_GET['type'] ?? '';
$file = $_GET['file'] ?? '';

// Reject anything that isn't a bare, opaque filename (defense-in-depth —
// safe_filename() already guarantees this shape at upload time).
if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,10}$/i', $file)) {
    http_response_code(400);
    exit('Invalid file reference.');
}

if ($type === 'distributor') {
    // Authorization check: the file must belong to a distributor owned by
    // the requesting branch account (mirrors the ownership scoping used
    // throughout distributor.php / distributor_detail.php).
    $stmt = $pdo->prepare('SELECT id FROM distributors WHERE photo = ? AND user_id = ?');
    $stmt->execute([$file, $user_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        exit('Not found.');
    }

    $privateRoot = __DIR__ . '/uploads/private/distributors';
    $privateRootReal = realpath($privateRoot);
    $path = $privateRoot . '/' . $file;

    if ($privateRootReal !== false && is_file($path)) {
        stream_private_file($path, $privateRootReal);
    }

    // Backward compatibility: photos uploaded before this endpoint existed
    // may still be sitting in the old public uploads/ folder. Serve them
    // from there once, read-only, without exposing the whole folder.
    $legacyPath = __DIR__ . '/uploads/' . $file;
    $legacyRoot = realpath(__DIR__ . '/uploads');
    if ($legacyRoot !== false && is_file($legacyPath)) {
        stream_private_file($legacyPath, $legacyRoot);
    }

    http_response_code(404);
    exit('Not found.');
}

http_response_code(400);
exit('Unknown file type.');
