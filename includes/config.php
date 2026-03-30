<?php
/**
 * Configuration du téléchargeur vidéo
 */

// Empêcher l'accès direct
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Configuration générale
define('APP_NAME', 'VidGrab');
define('APP_VERSION', '1.0.0');

// Dossier temporaire pour les téléchargements
define('DOWNLOAD_DIR', APP_ROOT . '/tmp');

// Chemin vers yt-dlp (binaire dans le projet ou système)
define('YTDLP_PATH', APP_ROOT . '/bin/yt-dlp');

// Taille max de fichier en Mo (0 = illimité)
define('MAX_FILE_SIZE_MB', 500);

// Durée max de vidéo en secondes (0 = illimité)
define('MAX_DURATION', 3600);

// Plateformes supportées
define('SUPPORTED_PLATFORMS', ['youtube', 'instagram', 'facebook']);

// Token CSRF - durée de vie en secondes
define('CSRF_LIFETIME', 3600);

// Rate limiting - requêtes par minute par IP
define('RATE_LIMIT', 10);

// Créer les dossiers nécessaires
if (!is_dir(DOWNLOAD_DIR)) {
    @mkdir(DOWNLOAD_DIR, 0755, true);
}
if (!is_dir(APP_ROOT . '/bin')) {
    @mkdir(APP_ROOT . '/bin', 0755, true);
}

// Démarrer la session si pas déjà fait
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Générer un token CSRF
 */
function generateCsrfToken(): string
{
    // Réutiliser le token existant s'il est encore valide
    if (!empty($_SESSION['csrf_token']) && !empty($_SESSION['csrf_time'])) {
        if (time() - $_SESSION['csrf_time'] < CSRF_LIFETIME) {
            return $_SESSION['csrf_token'];
        }
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_time'] = time();
    return $token;
}

/**
 * Valider un token CSRF
 */
function validateCsrfToken(?string $token): bool
{
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    if (time() - ($_SESSION['csrf_time'] ?? 0) > CSRF_LIFETIME) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Rate limiting simple basé sur la session
 */
function checkRateLimit(): bool
{
    $key = 'rate_' . date('YmdHi');
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = 0;
    }
    $_SESSION[$key]++;
    return $_SESSION[$key] <= RATE_LIMIT;
}

/**
 * Réponse JSON
 */
function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Nettoyer les vieux fichiers temporaires (plus de 1h)
 */
function cleanTempFiles(): void
{
    if (!is_dir(DOWNLOAD_DIR)) return;
    $files = glob(DOWNLOAD_DIR . '/*');
    foreach ($files as $file) {
        if (is_file($file) && (time() - filemtime($file)) > 3600) {
            @unlink($file);
        }
    }
}
