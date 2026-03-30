<?php
/**
 * Classe principale du téléchargeur vidéo
 * Supporte YouTube, Instagram et Facebook
 */

require_once __DIR__ . '/config.php';

class Downloader
{
    private string $url;
    private string $platform;

    public function __construct(string $url)
    {
        $this->url = $this->sanitizeUrl($url);
        $this->platform = $this->detectPlatform($this->url);
    }

    /**
     * Nettoyer et valider l'URL
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        $url = filter_var($url, FILTER_SANITIZE_URL);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL invalide.');
        }

        // Vérifier le protocole
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'])) {
            throw new InvalidArgumentException('Seuls les protocoles HTTP et HTTPS sont acceptés.');
        }

        return $url;
    }

    /**
     * Détecter la plateforme à partir de l'URL
     */
    private function detectPlatform(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (preg_match('/(youtube\.com|youtu\.be|youtube-nocookie\.com)/', $host)) {
            return 'youtube';
        }
        if (preg_match('/(instagram\.com|instagr\.am)/', $host)) {
            return 'instagram';
        }
        if (preg_match('/(facebook\.com|fb\.watch|fb\.com|fbcdn\.net)/', $host)) {
            return 'facebook';
        }

        throw new InvalidArgumentException('Plateforme non supportée. Utilisez YouTube, Instagram ou Facebook.');
    }

    /**
     * Obtenir la plateforme détectée
     */
    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * Vérifier si yt-dlp est disponible
     */
    public static function isYtdlpAvailable(): bool
    {
        // Vérifier le binaire local (avec et sans extension .exe sur Windows)
        $paths = [YTDLP_PATH];
        if (PHP_OS_FAMILY === 'Windows') {
            $paths[] = YTDLP_PATH . '.exe';
        }
        
        foreach ($paths as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        // Essayer d'exécuter yt-dlp depuis le PATH système
        $cmd = PHP_OS_FAMILY === 'Windows' ? 'where yt-dlp' : 'which yt-dlp';
        $output = [];
        $code = -1;
        @exec($cmd . ' 2>NUL', $output, $code);
        if ($code === 0 && !empty($output)) {
            return true;
        }
        
        // Test ultime: essayer d'exécuter yt-dlp directement
        $output = [];
        $code = -1;
        @exec('yt-dlp --version 2>&1', $output, $code);
        return $code === 0;
    }

    /**
     * Obtenir le chemin de yt-dlp
     */
    private function getYtdlpBin(): string
    {
        // Chercher le binaire local
        if (is_file(YTDLP_PATH)) {
            return YTDLP_PATH;
        }
        if (is_file(YTDLP_PATH . '.exe')) {
            return YTDLP_PATH . '.exe';
        }
        
        // Sinon, utiliser la commande depuis le PATH
        return 'yt-dlp';
    }

    /**
     * Obtenir les informations de la vidéo via yt-dlp
     */
    public function getInfo(): array
    {
        if (!self::isYtdlpAvailable()) {
            return $this->getInfoFallback();
        }

        $binPath = $this->getYtdlpBin();
        $isWindows = PHP_OS_FAMILY === 'Windows';
        $bin = $isWindows ? '"' . $binPath . '"' : escapeshellarg($binPath);
        $url = escapeshellarg($this->url);

        $cmd = "{$bin} --dump-json --no-playlist --no-warnings --no-check-certificates {$url} 2>&1";
        $output = [];
        $code = -1;
        @exec($cmd, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('Impossible de récupérer les informations de la vidéo.');
        }

        $json = json_decode(implode('', $output), true);
        if (!$json) {
            throw new RuntimeException('Réponse invalide du serveur.');
        }

        return $this->formatVideoInfo($json);
    }

    /**
     * Formater les informations brutes de yt-dlp
     */
    private function formatVideoInfo(array $raw): array
    {
        $formats = [];
        $seenQualities = [];

        if (!empty($raw['formats'])) {
            foreach ($raw['formats'] as $f) {
                // Filtrer les formats sans vidéo ou audio seul
                $hasVideo = !empty($f['vcodec']) && $f['vcodec'] !== 'none';
                $hasAudio = !empty($f['acodec']) && $f['acodec'] !== 'none';

                if (!$hasVideo) continue;

                $height = $f['height'] ?? 0;
                $ext = $f['ext'] ?? 'mp4';
                $label = $height ? "{$height}p" : 'Inconnu';

                // Éviter les doublons de qualité
                $qualityKey = "{$height}_{$ext}";
                if (isset($seenQualities[$qualityKey])) continue;
                $seenQualities[$qualityKey] = true;

                $formats[] = [
                    'format_id' => $f['format_id'],
                    'label' => $label,
                    'ext' => $ext,
                    'height' => $height,
                    'filesize' => $f['filesize'] ?? $f['filesize_approx'] ?? null,
                    'has_audio' => $hasAudio,
                ];
            }
        }

        // Trier par qualité décroissante
        usort($formats, fn($a, $b) => ($b['height'] ?? 0) - ($a['height'] ?? 0));

        // Calculer les tailles estimées à partir des formats bruts
        $totalSize = 0;
        $bestVideoSize = 0;
        $bestAudioSize = 0;
        $sizeByHeight = [];

        if (!empty($raw['formats'])) {
            foreach ($raw['formats'] as $f) {
                $fs = $f['filesize'] ?? $f['filesize_approx'] ?? 0;
                $hasVideo = !empty($f['vcodec']) && $f['vcodec'] !== 'none';
                $hasAudio = !empty($f['acodec']) && $f['acodec'] !== 'none';
                $h = $f['height'] ?? 0;

                if ($hasVideo && $h > 0 && $fs > 0) {
                    if (!isset($sizeByHeight[$h]) || $fs > $sizeByHeight[$h]) {
                        $sizeByHeight[$h] = $fs;
                    }
                    if ($fs > $bestVideoSize) {
                        $bestVideoSize = $fs;
                    }
                }
                if ($hasAudio && !$hasVideo && $fs > 0 && $fs > $bestAudioSize) {
                    $bestAudioSize = $fs;
                }
                if ($hasVideo && $hasAudio && $fs > $totalSize) {
                    $totalSize = $fs;
                }
            }
        }

        $estimateBest = $totalSize > 0 ? $totalSize : ($bestVideoSize + $bestAudioSize);

        // Estimer par hauteur (vidéo + audio)
        $estimate1080 = ($sizeByHeight[1080] ?? $sizeByHeight[1920] ?? 0) + $bestAudioSize;
        $estimate720 = ($sizeByHeight[720] ?? 0) + $bestAudioSize;
        $estimate480 = ($sizeByHeight[480] ?? 0) + $bestAudioSize;

        // Ajouter les options combinées pratiques (sans ffmpeg, utiliser des formats existants)
        $quickFormats = [
            ['format_id' => 'best', 'label' => 'Meilleure qualité auto', 'ext' => 'mp4', 'height' => 9999, 'filesize' => $estimateBest ?: null, 'has_audio' => true],
            ['format_id' => 'best[height<=1080]', 'label' => '1080p (recommandé)', 'ext' => 'mp4', 'height' => 1080, 'filesize' => $estimate1080 ?: null, 'has_audio' => true],
            ['format_id' => 'best[height<=720]', 'label' => '720p', 'ext' => 'mp4', 'height' => 720, 'filesize' => $estimate720 ?: null, 'has_audio' => true],
            ['format_id' => 'best[height<=480]', 'label' => '480p', 'ext' => 'mp4', 'height' => 480, 'filesize' => $estimate480 ?: null, 'has_audio' => true],
            ['format_id' => 'bestaudio/best', 'label' => 'Audio uniquement (MP3)', 'ext' => 'mp3', 'height' => 0, 'filesize' => $bestAudioSize ?: null, 'has_audio' => true],
        ];

        return [
            'title' => $raw['title'] ?? 'Vidéo sans titre',
            'thumbnail' => $raw['thumbnail'] ?? '',
            'duration' => $raw['duration'] ?? 0,
            'duration_string' => $raw['duration_string'] ?? '',
            'uploader' => $raw['uploader'] ?? $raw['channel'] ?? '',
            'platform' => $this->platform,
            'formats' => $quickFormats,
            'url' => $this->url,
        ];
    }

    /**
     * Fallback PHP natif pour extraire les infos (sans yt-dlp)
     */
    private function getInfoFallback(): array
    {
        switch ($this->platform) {
            case 'youtube':
                return $this->getYouTubeInfoFallback();
            case 'instagram':
            case 'facebook':
                return $this->getGenericInfoFallback();
            default:
                throw new RuntimeException('Plateforme non supportée en mode fallback.');
        }
    }

    /**
     * Extraction YouTube en PHP pur
     */
    private function getYouTubeInfoFallback(): array
    {
        // Extraire l'ID de la vidéo
        $videoId = $this->extractYouTubeId($this->url);
        if (!$videoId) {
            throw new RuntimeException('ID de vidéo YouTube invalide.');
        }

        // Utiliser l'endpoint oEmbed pour les métadonnées
        $oembedUrl = "https://www.youtube.com/oembed?url=" . urlencode($this->url) . "&format=json";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
            'ssl' => ['verify_peer' => false],
        ]);

        $response = @file_get_contents($oembedUrl, false, $ctx);
        $meta = $response ? json_decode($response, true) : [];

        return [
            'title' => $meta['title'] ?? 'Vidéo YouTube',
            'thumbnail' => "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg",
            'duration' => 0,
            'duration_string' => '',
            'uploader' => $meta['author_name'] ?? '',
            'platform' => 'youtube',
            'formats' => [
                ['format_id' => 'best', 'label' => 'Meilleure qualité auto', 'ext' => 'mp4', 'height' => 9999, 'filesize' => null, 'has_audio' => true],
                ['format_id' => 'best[height<=720]', 'label' => '720p', 'ext' => 'mp4', 'height' => 720, 'filesize' => null, 'has_audio' => true],
                ['format_id' => 'bestaudio/best', 'label' => 'Audio uniquement', 'ext' => 'mp3', 'height' => 0, 'filesize' => null, 'has_audio' => true],
            ],
            'url' => $this->url,
        ];
    }

    /**
     * Extraction générique pour Instagram/Facebook
     */
    private function getGenericInfoFallback(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'header' => "Accept: text/html,application/xhtml+xml\r\n",
            ],
            'ssl' => ['verify_peer' => false],
        ]);

        $html = @file_get_contents($this->url, false, $ctx);
        $title = 'Vidéo ' . ucfirst($this->platform);
        $thumbnail = '';

        if ($html) {
            // Extraire le titre via og:title
            if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/', $html, $m)) {
                $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            // Extraire la miniature via og:image
            if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/', $html, $m)) {
                $thumbnail = $m[1];
            }
        }

        return [
            'title' => $title,
            'thumbnail' => $thumbnail,
            'duration' => 0,
            'duration_string' => '',
            'uploader' => '',
            'platform' => $this->platform,
            'formats' => [
                ['format_id' => 'best', 'label' => 'Meilleure qualité', 'ext' => 'mp4', 'height' => 9999, 'filesize' => null, 'has_audio' => true],
            ],
            'url' => $this->url,
        ];
    }

    /**
     * Extraire l'ID YouTube d'une URL
     */
    private function extractYouTubeId(string $url): ?string
    {
        $patterns = [
            '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }

    /**
     * Télécharger la vidéo et renvoyer le chemin du fichier
     */
    public function download(string $formatId = 'best'): array
    {
        if (!self::isYtdlpAvailable()) {
            throw new RuntimeException('yt-dlp n\'est pas installé. Veuillez exécuter le script d\'installation.');
        }

        // Nettoyer les anciens fichiers
        cleanTempFiles();

        // Générer un nom de fichier unique
        $outputId = bin2hex(random_bytes(8));
        $outputTemplate = DOWNLOAD_DIR . "/{$outputId}.%(ext)s";

        $binPath = $this->getYtdlpBin();
        // Sur Windows, utiliser des guillemets doubles pour les chemins avec espaces
        $isWindows = PHP_OS_FAMILY === 'Windows';
        $bin = $isWindows ? '"' . $binPath . '"' : escapeshellarg($binPath);
        $url = escapeshellarg($this->url);
        $output = escapeshellarg($outputTemplate);

        // Pour l'audio uniquement
        $isAudioOnly = strpos($formatId, 'bestaudio') === 0 && strpos($formatId, 'bestvideo') === false;

        // Adapter le format selon la plateforme
        // Instagram/Facebook n'acceptent pas best[height<=X], utiliser 'best' directement
        $adaptedFormat = $formatId;
        if ($this->platform !== 'youtube' && preg_match('/^best\[height/', $formatId)) {
            $adaptedFormat = 'best';
        }

        if ($isAudioOnly) {
            $cmd = "{$bin} --no-playlist --no-warnings --no-check-certificates -f bestaudio --extract-audio --audio-format mp3 -o {$output} {$url} 2>&1";
        } else {
            $format = escapeshellarg($adaptedFormat);
            $cmd = "{$bin} --no-playlist --no-warnings --no-check-certificates";
            $cmd .= " -f {$format}";
            // Ajouter -S pour économiser la bande passante et fusionner automatiquement si ffmpeg est présent
            $cmd .= " -S res,ext:mp4:m4a";
            $cmd .= " -o {$output}";

            // Limiter la durée si configuré
            if (MAX_DURATION > 0) {
                $cmd .= ' --match-filter "duration <= ' . (int)MAX_DURATION . '"';
            }

            $cmd .= " {$url} 2>&1";
        }

        $outputLines = [];
        $code = -1;
        @exec($cmd, $outputLines, $code);

        // Retry avec format fallback si le format n'est pas disponible
        if ($code !== 0 && $this->platform !== 'youtube' && $adaptedFormat !== 'best') {
            // Réessayer avec 'best' directement
            $format = escapeshellarg('best');
            $cmd = "{$bin} --no-playlist --no-warnings --no-check-certificates";
            $cmd .= " -f {$format}";
            $cmd .= " -S res,ext:mp4:m4a";
            $cmd .= " -o {$output}";

            if (MAX_DURATION > 0) {
                $cmd .= ' --match-filter "duration <= ' . (int)MAX_DURATION . '"';
            }

            $cmd .= " {$url} 2>&1";

            $outputLines = [];
            $code = -1;
            @exec($cmd, $outputLines, $code);
        }

        if ($code !== 0) {
            $error = implode("\n", $outputLines);
            // Garder les 10 dernières lignes si c'est trop verbose
            if (strlen($error) > 500) {
                $error = implode("\n", array_slice($outputLines, -10));
            }
            throw new RuntimeException('Erreur de téléchargement (' . $code . '): ' . trim($error));
        }

        // Trouver le fichier téléchargé
        // Attendre un peu que ffmpeg fusionne (s'il est utilisé)
        sleep(1);
        
        $files = glob(DOWNLOAD_DIR . "/{$outputId}.*");
        if (empty($files)) {
            throw new RuntimeException('Le fichier téléchargé est introuvable.');
        }

        // Si plusieurs fichiers (vidéo + audio séparés), chercher le fichier final
        $filePath = null;
        $extension = null;

        // Chercher d'abord un .mp4 (vidéo fusionnée)
        foreach ($files as $f) {
            if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'mp4') {
                $filePath = $f;
                $extension = 'mp4';
                break;
            }
        }

        // Sinon chercher le fichier le plus gros (probablement la vidéo +  audio fusionnée)
        if (!$filePath) {
            $largestFile = null;
            $largestSize = 0;
            foreach ($files as $f) {
                $size = filesize($f);
                if ($size > $largestSize) {
                    $largestSize = $size;
                    $largestFile = $f;
                }
            }
            if ($largestFile) {
                $filePath = $largestFile;
                $extension = pathinfo($largestFile, PATHINFO_EXTENSION);
            }
        }

        if (!$filePath) {
            // Nettoyer les fichiers orphelins
            foreach ($files as $f) {
                @unlink($f);
            }
            throw new RuntimeException('Impossible de finaliser le téléchargement. Vérifiez que ffmpeg est installé.');
        }

        // Renommer en .mp4 si nécessaire
        if (strtolower($extension) !== 'mp4' && strtolower($extension) !== 'mp3') {
            $newPath = substr($filePath, 0, -strlen($extension)) . 'mp4';
            rename($filePath, $newPath);
            $filePath = $newPath;
            
            // Nettoyer les autres fichiers
            foreach ($files as $f) {
                if ($f !== $filePath) {
                    @unlink($f);
                }
            }
        } else {
            // Nettoyer les autres fichiers (vidéo + audio séparés)
            foreach ($files as $f) {
                if ($f !== $filePath) {
                    @unlink($f);
                }
            }
        }

        $fileName = basename($filePath);
        $fileSize = filesize($filePath);

        // Vérifier la taille max
        if (MAX_FILE_SIZE_MB > 0 && $fileSize > MAX_FILE_SIZE_MB * 1024 * 1024) {
            @unlink($filePath);
            throw new RuntimeException('Le fichier dépasse la taille maximale autorisée.');
        }

        return [
            'file' => $fileName,
            'path' => $filePath,
            'size' => $fileSize,
        ];
    }
}
