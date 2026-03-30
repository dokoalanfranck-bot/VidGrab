<?php
/**
 * Script d'installation de VidGrab
 * Vérifie les prérequis et installe yt-dlp
 */

session_start();

// Protection basique par mot de passe (changer en production)
$setupPassword = 'vidgrab.bissmoi.com';

$authenticated = false;
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['password']) && hash_equals($setupPassword, $_POST['password'])) {
        $_SESSION['setup_auth'] = true;
    }

    if (!empty($_SESSION['setup_auth']) && isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'install_ytdlp':
                $message = installYtdlp();
                $messageType = strpos($message, 'Erreur') === false ? 'success' : 'error';
                break;
            case 'create_dirs':
                $message = createDirectories();
                $messageType = 'success';
                break;
            case 'test':
                $message = runTests();
                $messageType = 'info';
                break;
        }
    }
}

$authenticated = !empty($_SESSION['setup_auth']);

function installYtdlp(): string
{
    $binDir = __DIR__ . '/bin';
    if (!is_dir($binDir)) {
        mkdir($binDir, 0755, true);
    }

    $ytdlpPath = $binDir . '/yt-dlp';

    // Détecter l'OS
    $os = PHP_OS_FAMILY;
    if ($os === 'Linux') {
        $url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp';
    } elseif ($os === 'Windows') {
        $url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe';
        $ytdlpPath .= '.exe';
    } elseif ($os === 'Darwin') {
        $url = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_macos';
    } else {
        return "Erreur : OS non supporté ({$os}).";
    }

    // Télécharger yt-dlp
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 60,
            'user_agent' => 'VidGrab/1.0',
            'follow_location' => true,
        ],
        'ssl' => ['verify_peer' => false],
    ]);

    $content = @file_get_contents($url, false, $ctx);
    if ($content === false) {
        // Essayer avec curl
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || empty($content)) {
                return "Erreur : Impossible de télécharger yt-dlp (HTTP {$httpCode}).";
            }
        } else {
            return "Erreur : Impossible de télécharger yt-dlp. Vérifiez allow_url_fopen ou installez cURL.";
        }
    }

    if (file_put_contents($ytdlpPath, $content) === false) {
        return "Erreur : Impossible d'écrire le fichier. Vérifiez les permissions du dossier bin/.";
    }

    // Rendre exécutable sur Linux/Mac
    if ($os !== 'Windows') {
        chmod($ytdlpPath, 0755);
    }

    return "yt-dlp installé avec succès dans {$ytdlpPath}";
}

function createDirectories(): string
{
    $dirs = ['tmp', 'bin'];
    $results = [];
    foreach ($dirs as $dir) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
            $results[] = "Dossier {$dir}/ créé.";
        } else {
            $results[] = "Dossier {$dir}/ existe déjà.";
        }
    }

    // Fichier .htaccess dans tmp pour bloquer l'accès direct
    $htaccess = __DIR__ . '/tmp/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
        $results[] = "Protection tmp/.htaccess ajoutée.";
    }

    return implode("\n", $results);
}

function runTests(): string
{
    $results = [];

    // PHP version
    $results[] = '✓ PHP ' . PHP_VERSION;

    // Extensions
    $extensions = ['json', 'curl', 'mbstring'];
    foreach ($extensions as $ext) {
        $status = extension_loaded($ext) ? '✓' : '✗';
        $results[] = "{$status} Extension {$ext}";
    }

    // exec()
    $execDisabled = in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
    $results[] = ($execDisabled ? '✗' : '✓') . ' Fonction exec()';

    // allow_url_fopen
    $results[] = (ini_get('allow_url_fopen') ? '✓' : '✗') . ' allow_url_fopen';

    // yt-dlp
    $ytdlpLocal = is_file(__DIR__ . '/bin/yt-dlp') || is_file(__DIR__ . '/bin/yt-dlp.exe');
    $results[] = ($ytdlpLocal ? '✓' : '✗') . ' yt-dlp (local)';

    // Dossiers
    $results[] = (is_writable(__DIR__ . '/tmp') ? '✓' : '✗') . ' Dossier tmp/ accessible en écriture';

    // ffmpeg
    $output = [];
    $code = -1;
    @exec('ffmpeg -version 2>&1', $output, $code);
    $results[] = ($code === 0 ? '✓' : '⚠') . ' ffmpeg ' . ($code === 0 ? '(installé)' : '(non trouvé - fusion audio/vidéo limitée)');

    return implode("\n", $results);
}

$checks = [];
if ($authenticated) {
    $checks = [
        'php' => PHP_VERSION,
        'exec' => !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions')))),
        'curl' => extension_loaded('curl'),
        'json' => extension_loaded('json'),
        'ytdlp' => is_file(__DIR__ . '/bin/yt-dlp') || is_file(__DIR__ . '/bin/yt-dlp.exe'),
        'tmp_writable' => is_dir(__DIR__ . '/tmp') && is_writable(__DIR__ . '/tmp'),
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VidGrab - Installation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #0a0a1a; color: #f0f0ff; min-height: 100vh; padding: 40px 20px; }
        .container { max-width: 640px; margin: 0 auto; }
        h1 { font-size: 1.8rem; margin-bottom: 8px; background: linear-gradient(135deg, #7c5cff, #ff6b9d); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        h2 { font-size: 1.1rem; margin: 24px 0 12px; color: #aaa; }
        .card { background: rgba(20,20,50,0.7); border: 1px solid rgba(100,100,200,0.15); border-radius: 16px; padding: 28px; margin: 20px 0; }
        input[type="password"], input[type="text"] { width: 100%; padding: 14px 16px; background: rgba(0,0,0,0.3); border: 1.5px solid rgba(100,100,200,0.15); border-radius: 10px; color: #f0f0ff; font-size: 0.95rem; margin-bottom: 12px; outline: none; }
        input:focus { border-color: rgba(124,92,255,0.5); }
        .btn { display: inline-block; padding: 12px 24px; border: none; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; margin: 4px; transition: 0.3s; }
        .btn-primary { background: linear-gradient(135deg, #7c5cff, #5a3fd4); color: white; }
        .btn-success { background: linear-gradient(135deg, #00c853, #009624); color: white; }
        .btn-warning { background: linear-gradient(135deg, #ffab00, #ff6d00); color: #111; }
        .btn:hover { transform: translateY(-1px); opacity: 0.9; }
        .check { display: flex; align-items: center; gap: 10px; padding: 8px 0; font-size: 0.9rem; }
        .check-ok { color: #00c853; }
        .check-fail { color: #ff1744; }
        .check-warn { color: #ffab00; }
        .message { padding: 14px 20px; border-radius: 10px; margin: 16px 0; font-size: 0.88rem; white-space: pre-line; }
        .message.success { background: rgba(0,200,83,0.1); border: 1px solid rgba(0,200,83,0.3); color: #00c853; }
        .message.error { background: rgba(255,23,68,0.1); border: 1px solid rgba(255,23,68,0.3); color: #ff1744; }
        .message.info { background: rgba(124,92,255,0.1); border: 1px solid rgba(124,92,255,0.3); color: #b8a8ff; }
        a { color: #7c5cff; }
        .back { display: inline-block; margin-top: 20px; color: #888; text-decoration: none; font-size: 0.85rem; }
        .back:hover { color: #aaa; }
    </style>
</head>
<body>
<div class="container">
    <h1>⚙️ VidGrab - Installation</h1>
    <p style="color:#888; margin-bottom: 20px;">Configurez votre instance VidGrab</p>

    <?php if (!$authenticated): ?>
    <div class="card">
        <h2>Authentification</h2>
        <form method="POST">
            <input type="password" name="password" placeholder="Mot de passe d'installation" required>
            <button type="submit" class="btn btn-primary">Accéder</button>
        </form>
        <p style="color:#666; font-size:0.8rem; margin-top:12px;">Mot de passe par défaut : <code>vidgrab_setup_2024</code> — changez-le dans setup.php</p>
    </div>
    <?php else: ?>

    <?php if ($message): ?>
    <div class="message <?= $messageType ?>"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>État du système</h2>
        <div class="check <?= version_compare($checks['php'], '7.4', '>=') ? 'check-ok' : 'check-fail' ?>">
            <?= version_compare($checks['php'], '7.4', '>=') ? '✓' : '✗' ?> PHP <?= $checks['php'] ?> (minimum 7.4)
        </div>
         <div class="check <?= $checks['exec'] ? 'check-ok' : 'check-fail' ?>">
            <?= $checks['exec'] ? '✓' : '✗' ?> Fonction exec() <?= $checks['exec'] ? 'disponible' : 'désactivée' ?>
        </div>
        <div class="check <?= $checks['curl'] ? 'check-ok' : 'check-warn' ?>">
            <?= $checks['curl'] ? '✓' : '⚠' ?> Extension cURL
        </div>
        <div class="check <?= $checks['json'] ? 'check-ok' : 'check-fail' ?>">
            <?= $checks['json'] ? '✓' : '✗' ?> Extension JSON
        </div>
        <div class="check <?= $checks['ytdlp'] ? 'check-ok' : 'check-fail' ?>">
            <?= $checks['ytdlp'] ? '✓' : '✗' ?> yt-dlp <?= $checks['ytdlp'] ? 'installé' : 'non installé' ?>
        </div>
        <div class="check <?= $checks['tmp_writable'] ? 'check-ok' : 'check-fail' ?>">
            <?= $checks['tmp_writable'] ? '✓' : '✗' ?> Dossier tmp/ accessible en écriture
        </div>
    </div>

    <div class="card">
        <h2>Actions</h2>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="create_dirs">
            <button type="submit" class="btn btn-primary">📁 Créer les dossiers</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="install_ytdlp">
            <button type="submit" class="btn btn-success">⬇️ Installer yt-dlp</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="test">
            <button type="submit" class="btn btn-warning">🧪 Tester le système</button>
        </form>
    </div>

    <div class="card">
        <h2>Installation manuelle de yt-dlp</h2>
        <p style="color:#999; font-size:0.85rem; line-height:1.7;">
            Si le téléchargement automatique ne fonctionne pas :<br>
            1. Téléchargez yt-dlp depuis <a href="https://github.com/yt-dlp/yt-dlp/releases" target="_blank">GitHub</a><br>
            2. Placez le fichier dans le dossier <code>bin/</code> de votre site<br>
            3. Sur Linux : <code>chmod +x bin/yt-dlp</code><br>
            4. Rechargez cette page pour vérifier
        </p>
    </div>

    <?php endif; ?>

    <a href="index.php" class="back">← Retour à VidGrab</a>
</div>
</body>
</html>
