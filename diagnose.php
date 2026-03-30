<?php
/**
 * Script de diagnostic pour identifier les problèmes de déploiement
 */

header('Content-Type: text/html; charset=utf-8');

// Catch les erreurs PHP
ini_set('display_errors', 1);
error_reporting(E_ALL);
?>
<!DOCTYPE html>
<html>
<head>
    <title>VidGrab - Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #0a0e27; color: #fff; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #444; border-radius: 5px; background: #1a1f3a; }
        .ok { color: #4ade80; font-weight: bold; }
        .error { color: #ff6b6b; font-weight: bold; }
        .warning { color: #fbbf24; font-weight: bold; }
        code { background: #0a0e27; padding: 5px 10px; border-radius: 3px; display: block; margin: 10px 0; word-break: break-all; }
        h2 { color: #60a5fa; }
    </style>
</head>
<body>
<h1>🔍 Diagnostic VidGrab</h1>

<?php
try {
// Test 1: PHP Configuration
echo '<div class="section">';
echo '<h2>1. Configuration PHP</h2>';
echo '<p>Version PHP: <span class="ok">' . PHP_VERSION . '</span></p>';
echo '<p>OS: <span class="ok">' . PHP_OS_FAMILY . '</span></p>';

// User - safer way
$user = 'unknown';
if (function_exists('posix_getpwuid')) {
    $userData = posix_getpwuid(posix_geteuid());
    $user = $userData['name'] ?? 'unknown';
}
echo '<p>User: <span class="ok">' . htmlspecialchars($user) . '</span></p>';
echo '<p>Répertoire courant: <span class="ok">' . htmlspecialchars(getcwd()) . '</span></p>';

// Test fonction exec
$testCode = -1;
@exec('echo test', $testOutput, $testCode);
if ($testCode === 0) {
    echo '<p>Fonction exec: <span class="ok">✓ Disponible</span></p>';
} else {
    echo '<p>Fonction exec: <span class="error">✗ Désactivée (disabled_functions)</span></p>';
}
echo '</div>';

// Test 2: Directories
echo '<div class="section">';
echo '<h2>2. Répertoires</h2>';

$baseDir = dirname(__FILE__);
$paths = [
    'base' => $baseDir,
    'includes' => $baseDir . '/includes',
    'api' => $baseDir . '/api',
    'bin' => $baseDir . '/bin',
    'tmp' => $baseDir . '/tmp',
];

foreach ($paths as $name => $path) {
    if (is_dir($path)) {
        $perms = substr(sprintf('%o', fileperms($path)), -4);
        echo "<p>$name: <span class=\"ok\">✓ Existe</span> (Perms: $perms)</p>";
    } else {
        echo "<p>$name: <span class=\"error\">✗ N'existe pas</span></p>";
    }
}

// Check bin directory content
if (is_dir($paths['bin'])) {
    $files = @scandir($paths['bin']);
    if ($files) {
        $files = array_diff($files, ['.', '..']);
        if (count($files) > 0) {
            echo '<p>Fichiers dans /bin:</p>';
            foreach ($files as $file) {
                $fullPath = $paths['bin'] . '/' . $file;
                $isExe = is_executable($fullPath);
                $status = $isExe ? '<span class="ok">✓ Exécutable</span>' : '<span class="warning">! Non exécutable</span>';
                echo "<code>" . htmlspecialchars($file) . " $status</code>";
            }
        } else {
            echo '<p><span class="warning">! Répertoire /bin vide</span></p>';
        }
    }
}
echo '</div>';

// Test 3: yt-dlp Detection
echo '<div class="section">';
echo '<h2>3. Détection yt-dlp</h2>';

if (@file_exists($baseDir . '/includes/config.php')) {
    require_once $baseDir . '/includes/config.php';
    
    if (defined('YTDLP_PATH')) {
        echo '<p>YTDLP_PATH: <code>' . htmlspecialchars(YTDLP_PATH) . '</code></p>';
        
        if (file_exists(YTDLP_PATH)) {
            $size = filesize(YTDLP_PATH);
            $perms = substr(sprintf('%o', fileperms(YTDLP_PATH)), -4);
            echo '<p><span class="ok">✓ Fichier trouvé</span> (Taille: ' . round($size / 1024 / 1024, 2) . ' MB, Perms: ' . $perms . ')</p>';
        } else {
            echo '<p><span class="warning">! Fichier non trouvé à ' . htmlspecialchars(YTDLP_PATH) . '</span></p>';
        }
        
        if (file_exists(YTDLP_PATH . '.exe')) {
            echo '<p><span class="ok">✓ Fichier .exe trouvé</span></p>';
        }
    }
}

// Test yt-dlp version
echo '<p><strong>Test: yt-dlp --version</strong></p>';
$output = [];
$code = -1;
@exec('yt-dlp --version 2>&1', $output, $code);
if ($code === 0 && !empty($output)) {
    echo '<p><span class="ok">✓ yt-dlp trouvé en PATH</span></p>';
    echo '<code>' . htmlspecialchars(implode("\n", $output)) . '</code>';
} else {
    echo '<p><span class="error">✗ yt-dlp non trouvé en PATH</span></p>';
    if (!empty($output)) {
        echo '<code>Erreur: ' . htmlspecialchars(implode("\n", $output)) . '</code>';
    }
    echo '<code>Exit code: ' . $code . '</code>';
}

// Test where/which
$cmd = PHP_OS_FAMILY === 'Windows' ? 'where yt-dlp' : 'which yt-dlp';
$output = [];
$code = -1;
@exec($cmd . ' 2>&1', $output, $code);
if ($code === 0 && !empty($output)) {
    echo '<p><span class="ok">✓ Location: ' . htmlspecialchars(implode(', ', $output)) . '</span></p>';
}

echo '</div>';

// Test 4: Call Downloader
echo '<div class="section">';
echo '<h2>4. Test Downloader Class</h2>';

try {
    if (@file_exists($baseDir . '/includes/Downloader.php')) {
        require_once $baseDir . '/includes/Downloader.php';
        
        if (Downloader::isYtdlpAvailable()) {
            echo '<p><span class="ok">✓ Downloader::isYtdlpAvailable() = true</span></p>';
        } else {
            echo '<p><span class="error">✗ Downloader::isYtdlpAvailable() = false</span></p>';
            echo '<p><span class="warning">! yt-dlp n\'est pas détecté. Solution:</span></p>';
            if (PHP_OS_FAMILY === 'Windows') {
                echo '<code>Télécharger manuellement de https://github.com/yt-dlp/yt-dlp/releases dans le dossier /bin</code>';
            } else {
                echo '<code>SSH: cd bin && wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp && chmod +x yt-dlp</code>';
            }
        }
    } else {
        echo '<p><span class="error">✗ Fichier Downloader.php non trouvé</span></p>';
    }
} catch (Exception $e) {
    echo '<p><span class="error">✗ Exception: ' . htmlspecialchars($e->getMessage()) . '</span></p>';
}

echo '</div>';

// Test 5: Configuration
echo '<div class="section">';
echo '<h2>5. Configuration (config.php)</h2>';
echo '<p>YTDLP_PATH: <code>' . (defined('YTDLP_PATH') ? htmlspecialchars(YTDLP_PATH) : 'Non défini') . '</code></p>';
echo '<p>MAX_FILE_SIZE: <code>' . (defined('MAX_FILE_SIZE') ? round(MAX_FILE_SIZE / 1024 / 1024, 2) . ' MB' : 'Non défini') . '</code></p>';
echo '<p>MAX_DURATION: <code>' . (defined('MAX_DURATION') ? MAX_DURATION . ' sec' : 'Non défini') . '</code></p>';
echo '<p>SESSION_DIR: <code>' . (defined('SESSION_DIR') ? htmlspecialchars(SESSION_DIR) : 'Non défini') . '</code></p>';
echo '</div>';

// Test 6: Test API call
echo '<div class="section">';
echo '<h2>6. Test API Fetch</h2>';
echo '<p>Essayons d\'appeler l\'API avec une URL YouTube...</p>';

try {
    if (@file_exists($baseDir . '/includes/Downloader.php')) {
        require_once $baseDir . '/includes/Downloader.php';
        
        $testUrl = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $dl = new Downloader($testUrl);
        
        echo '<p>Plateforme détectée: <span class="ok">' . $dl->getPlatform() . '</span></p>';
        
        if (Downloader::isYtdlpAvailable()) {
            echo '<p>Appel yt-dlp...</p>';
            $info = $dl->getInfo();
            echo '<p><span class="ok">✓ API fonctionne!</span></p>';
            echo '<code>Titre: ' . htmlspecialchars($info['title'] ?? 'N/A') . '</code>';
        }
    }
} catch (Exception $e) {
    echo '<p><span class="error">✗ Erreur API: ' . htmlspecialchars($e->getMessage()) . '</span></p>';
}

echo '</div>';

// Test 7: Recommended Solutions
echo '<div class="section">';
echo '<h2>🚀 Solutions Recommandées</h2>';
echo '<ol>';
echo '<li><strong>Si yt-dlp n\'est pas détecté:</strong> Accédez à <code>/setup.php</code> et installez yt-dlp via le script automatisé.</li>';
echo '<li><strong>Si l\'installation échoue:</strong> Installez manuellement via SSH : <code>mkdir -p bin && cd bin && wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O yt-dlp && chmod +x yt-dlp</code></li>';
echo '<li><strong>Si c\'est un problème de permissions:</strong> Via SSH : <code>chmod +x bin/yt-dlp && chmod 755 tmp</code></li>';
echo '<li><strong>Si exec est désactivé:</strong> Contactez OVH support pour activer la fonction exec.</li>';
echo '</ol>';
echo '</div>';

} catch (Throwable $e) {
    echo '<div class="section"><span class="error">Erreur: ' . htmlspecialchars($e->getMessage()) . '</span></div>';
}
?>

</body>
</html>
