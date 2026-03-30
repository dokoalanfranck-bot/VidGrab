<?php
/**
 * API - Servir un fichier téléchargé
 */

require_once __DIR__ . '/../includes/config.php';

$fileName = $_GET['file'] ?? '';

// Valider le nom de fichier (empêcher directory traversal)
if (empty($fileName) || preg_match('/[\/\\\\\.]{2}/', $fileName) || !preg_match('/^[a-f0-9]{16}\.\w+$/', $fileName)) {
    http_response_code(400);
    die('Fichier invalide.');
}

$filePath = DOWNLOAD_DIR . '/' . $fileName;

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    die('Fichier introuvable.');
}

// Vérifier que le fichier est bien dans le dossier tmp
$realPath = realpath($filePath);
$realDir = realpath(DOWNLOAD_DIR);
if ($realPath === false || $realDir === false || strpos($realPath, $realDir) !== 0) {
    http_response_code(403);
    die('Accès interdit.');
}

$fileSize = filesize($filePath);
$ext = pathinfo($fileName, PATHINFO_EXTENSION);

$mimeTypes = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mkv' => 'video/x-matroska',
    'mp3' => 'audio/mpeg',
    'm4a' => 'audio/mp4',
    'ogg' => 'audio/ogg',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Headers de téléchargement
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="video.' . $ext . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

// Envoyer le fichier
readfile($filePath);

// Supprimer le fichier après envoi
@unlink($filePath);
exit;
