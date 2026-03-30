<?php
/**
 * API - Récupérer les informations d'une vidéo
 */

// Headers AVANT tout
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Enchainer les includes sans risque
try {
    require_once __DIR__ . '/../includes/config.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur loading config: ' . $e->getMessage()]);
    exit;
}

try {
    require_once __DIR__ . '/../includes/Downloader.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur loading Downloader: ' . $e->getMessage()]);
    exit;
}

// Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Méthode non autorisée.'], 405);
}

// Vérifier le rate limit
if (!checkRateLimit()) {
    jsonResponse(['error' => 'Trop de requêtes. Veuillez patienter.'], 429);
}

// Lire le body JSON
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (empty($input['url'])) {
    jsonResponse(['error' => 'Veuillez fournir une URL.'], 400);
}

// Valider le token CSRF
if (!validateCsrfToken($input['csrf_token'] ?? null)) {
    jsonResponse(['error' => 'Session expirée. Veuillez rafraîchir la page.'], 403);
}

try {
    $downloader = new Downloader($input['url']);
    $info = $downloader->getInfo();
    jsonResponse(['success' => true, 'data' => $info]);
} catch (InvalidArgumentException $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
} catch (RuntimeException $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    jsonResponse(['error' => 'Erreur: ' . get_class($e) . ': ' . $e->getMessage()], 500);
}
