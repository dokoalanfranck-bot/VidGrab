<?php
/**
 * Script de diagnostic pour identifier les problèmes de déploiement
 */

header('Content-Type: text/html; charset=utf-8');
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
        code { background: #0a0e27; padding: 5px 10px; border-radius: 3px; display: block; margin: 10px 0; }
        h2 { color: #60a5fa; }
    </style>
</head>
<body>
<h1>🔍 Diagnostic VidGrab</h1>

<?php
// Test 1: PHP Configuration
echo '<div class="section">';
echo '<h2>1. Configuration PHP</h2>';
echo '<p>Version PHP: <span class="ok">' . PHP_VERSION . '</span></p>';
echo '<p>OS: <span class="ok">' . PHP_OS_FAMILY . '</span></p>';
echo '<p>User: <span class="ok">' . (function_exists('get_current_user') ? get_current_user() : getenv('USER') ?: 'unknown') . '</span></p>';
echo '<p>Répertoire courant: <span class="ok">' . getcwd() . '</span></p>';

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

$paths = [
    'base' => dirname(__FILE__),
    'includes' => dirname(__FILE__) . '/includes',
    'api' => dirname(__FILE__) . '/api',
    'bin' => dirname(__FILE__) . '/bin',
    'tmp' => dirname(__FILE__) . '/tmp',
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
    $files = scandir($paths['bin']);
    $files = array_diff($files, ['.', '..']);
    if (count($files) > 0) {
        echo '<p>Fichiers dans /bin:</p>';
        foreach ($files as $file) {
            $fullPath = $paths['bin'] . '/' . $file;
            $isExe = is_executable($fullPath);
            $status = $isExe ? '<span class="ok">✓ Exécutable</span>' : '<span class="warning">! Non exécutable</span>';
            echo "<code>$file $status</code>";
        }
    } else {
        echo '<p><span class="warning">! Répertoire /bin vide</span></p>';
    }
}
echo '</div>';

// Test 3: yt-dlp Detection
echo '<div class="section">';
echo '<h2>3. Détection yt-dlp</h2>';

require_once dirname(__FILE__) . '/includes/config.php';

if (defined('YTDLP_PATH')) {
    echo '<p>YTDLP_PATH: <code>' . YTDLP_PATH . '</code></p>';
    
    if (file_exists(YTDLP_PATH)) {
        $size = filesize(YTDLP_PATH);
        $perms = substr(sprintf('%o', fileperms(YTDLP_PATH)), -4);
        echo '<p><span class="ok">✓ Fichier trouvé</span> (Taille: ' . ($size / 1024 / 1024) . ' MB, Perms: ' . $perms . ')</p>';
    } else {
        echo '<p><span class="warning">! Fichier non trouvé à ' . YTDLP_PATH . '</span></p>';
    }
    
    if (file_exists(YTDLP_PATH . '.exe')) {
        echo '<p><span class="ok">✓ Fichier .exe trouvé</span></p>';
    }
}

// Test yt-dlp version
echo '<p>Test: yt-dlp --version</p>';
$output = [];
$code = -1;
@exec('yt-dlp --version 2>&1', $output, $code);
if ($code === 0) {
    echo '<p><span class="ok">✓ yt-dlp trouvé en PATH</span></p>';
    echo '<code>' . implode('\n', $output) . '</code>';
} else {
    echo '<p><span class="error">✗ yt-dlp non trouvé en PATH</span></p>';
    echo '<code>Exit code: ' . $code . '</code>';
}

// Test where/which
$cmd = PHP_OS_FAMILY === 'Windows' ? 'where yt-dlp' : 'which yt-dlp';
$output = [];
$code = -1;
@exec($cmd . ' 2>&1', $output, $code);
if ($code === 0 && !empty($output)) {
    echo '<p><span class="ok">✓ Location: ' . implode(', ', $output) . '</span></p>';
}

echo '</div>';

// Test 4: Call Downloader
echo '<div class="section">';
echo '<h2>4. Test Downloader Class</h2>';

try {
    require_once dirname(__FILE__) . '/includes/Downloader.php';
    
    if (Downloader::isYtdlpAvailable()) {
        echo '<p><span class="ok">✓ Downloader::isYtdlpAvailable() = true</span></p>';
    } else {
        echo '<p><span class="error">✗ Downloader::isYtdlpAvailable() = false</span></p>';
        echo '<p><span class="warning">! yt-dlp n\'est pas détecté. Veuillez installer:</span></p>';
        if (PHP_OS_FAMILY === 'Windows') {
            echo '<code>Via le script setup.php ou télécharger manuellement de https://github.com/yt-dlp/yt-dlp/releases</code>';
        } else {
            echo '<code>apt-get install yt-dlp</code>';
        }
    }
} catch (Exception $e) {
    echo '<p><span class="error">✗ Exception: ' . htmlspecialchars($e->getMessage()) . '</span></p>';
}

echo '</div>';

// Test 5: Configuration
echo '<div class="section">';
echo '<h2>5. Configuration (config.php)</h2>';
echo '<p>YTDLP_PATH: <code>' . (defined('YTDLP_PATH') ? YTDLP_PATH : 'Non défini') . '</code></p>';
echo '<p>MAX_FILE_SIZE: <code>' . (defined('MAX_FILE_SIZE') ? (MAX_FILE_SIZE / 1024 / 1024) . ' MB' : 'Non défini') . '</code></p>';
echo '<p>MAX_DURATION: <code>' . (defined('MAX_DURATION') ? MAX_DURATION . ' sec' : 'Non défini') . '</code></p>';
echo '<p>SESSION_DIR: <code>' . (defined('SESSION_DIR') ? SESSION_DIR : 'Non défini') . '</code></p>';
echo '</div>';

// Test 6: Recommended Solutions
echo '<div class="section">';
echo '<h2>🚀 Solutions Recommandées</h2>';
echo '<ol>';
echo '<li><strong>Si yt-dlp n\'est pas détecté:</strong> Accédez à <code>/setup.php</code> et installez yt-dlp via le script automatisé.</li>';
echo '<li><strong>Si l\'installation échoue:</strong> Installez manuellement via SSH (OVH shared hosting): <code>mkdir -p bin && cd bin && wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O yt-dlp && chmod +x yt-dlp</code></li>';
echo '<li><strong>Si c\'est un problème de permissions:</strong> Vérifiez les droits avec <code>ls -la bin/yt-dlp</code> et corrigez avec <code>chmod +x bin/yt-dlp</code></li>';
echo '<li><strong>Si exec est désactivé:</strong> Demandez à OVH d\'activer la fonction exec ou utilisez un VPS.</li>';
echo '</ol>';
echo '</div>';
?>

</body>
</html>
