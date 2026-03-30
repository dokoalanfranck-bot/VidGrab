/**
 * VidGrab - Frontend Application
 */

(function () {
    'use strict';

    // Éléments du DOM
    const form = document.getElementById('videoForm');
    const urlInput = document.getElementById('videoUrl');
    const pasteBtn = document.getElementById('pasteBtn');
    const fetchBtn = document.getElementById('fetchBtn');
    const loading = document.getElementById('loading');
    const loadingText = document.getElementById('loadingText');
    const result = document.getElementById('result');
    const errorDiv = document.getElementById('error');
    const errorText = document.getElementById('errorText');
    const thumbnail = document.getElementById('thumbnail');
    const videoTitle = document.getElementById('videoTitle');
    const videoUploader = document.getElementById('videoUploader');
    const duration = document.getElementById('duration');
    const platformIndicator = document.getElementById('platformIndicator');
    const formatList = document.getElementById('formatList');
    const downloadBtn = document.getElementById('downloadBtn');
    const csrfToken = document.getElementById('csrfToken').value;

    let currentVideoData = null;
    let selectedFormat = null;

    // ---- Coller depuis le presse-papier ----
    pasteBtn.addEventListener('click', async () => {
        try {
            const text = await navigator.clipboard.readText();
            urlInput.value = text.trim();
            urlInput.focus();
        } catch {
            // Fallback si l'API clipboard n'est pas disponible
            urlInput.focus();
            document.execCommand('paste');
        }
    });

    // ---- Soumettre le formulaire ----
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = urlInput.value.trim();

        if (!url) {
            showError('Veuillez entrer une URL.');
            return;
        }

        if (!isValidUrl(url)) {
            showError('Veuillez entrer une URL valide (YouTube, Instagram ou Facebook).');
            return;
        }

        await fetchVideoInfo(url);
    });

    // ---- Validation URL ----
    function isValidUrl(string) {
        try {
            const url = new URL(string);
            if (!['http:', 'https:'].includes(url.protocol)) return false;
            const host = url.hostname.toLowerCase();
            return /youtube\.com|youtu\.be|instagram\.com|instagr\.am|facebook\.com|fb\.watch|fb\.com/.test(host);
        } catch {
            return false;
        }
    }

    // ---- Détecter la plateforme ----
    function detectPlatform(url) {
        const host = new URL(url).hostname.toLowerCase();
        if (/youtube|youtu\.be/.test(host)) return 'youtube';
        if (/instagram|instagr/.test(host)) return 'instagram';
        if (/facebook|fb\./.test(host)) return 'facebook';
        return 'unknown';
    }

    // ---- Récupérer les infos vidéo ----
    async function fetchVideoInfo(url) {
        hideError();
        hideResult();
        showLoading('Analyse de la vidéo en cours...');
        setFetchBtnLoading(true);

        try {
            const response = await fetch('api/info.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url, csrf_token: csrfToken }),
            });

            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.error || 'Erreur lors de l\'analyse.');
            }

            currentVideoData = data.data;
            displayResult(data.data);
        } catch (err) {
            showError(err.message || 'Impossible d\'analyser cette vidéo.');
        } finally {
            hideLoading();
            setFetchBtnLoading(false);
        }
    }

    // ---- Afficher le résultat ----
    function displayResult(data) {
        // Miniature
        if (data.thumbnail) {
            thumbnail.src = data.thumbnail;
            thumbnail.alt = data.title;
        } else {
            thumbnail.src = 'data:image/svg+xml,' + encodeURIComponent(
                '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180" fill="%23222"><rect width="320" height="180"/><text x="160" y="95" text-anchor="middle" fill="%23666" font-size="14">Pas de miniature</text></svg>'
            );
        }

        // Infos
        videoTitle.textContent = data.title;
        videoUploader.textContent = data.uploader || '';
        
        // Durée
        if (data.duration_string) {
            duration.textContent = data.duration_string;
            duration.classList.remove('hidden');
        } else if (data.duration > 0) {
            duration.textContent = formatDuration(data.duration);
            duration.classList.remove('hidden');
        } else {
            duration.classList.add('hidden');
        }

        // Indicateur de plateforme
        platformIndicator.className = 'platform-indicator ' + (data.platform || '');

        // Formats
        formatList.innerHTML = '';
        selectedFormat = null;
        downloadBtn.disabled = true;

        if (data.formats && data.formats.length > 0) {
            data.formats.forEach((fmt, index) => {
                const option = createFormatOption(fmt, index);
                formatList.appendChild(option);
            });

            // Sélectionner le premier format par défaut
            const firstOption = formatList.querySelector('.format-option');
            if (firstOption) {
                firstOption.click();
            }
        }

        result.classList.remove('hidden');
        result.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ---- Créer une option de format ----
    function createFormatOption(fmt, index) {
        const div = document.createElement('div');
        div.className = 'format-option';
        div.dataset.formatId = fmt.format_id;
        div.dataset.ext = fmt.ext;

        let sizeHtml = '';
        if (fmt.filesize && fmt.filesize > 0) {
            sizeHtml = `<span class="format-size">≈ ${formatBytes(fmt.filesize)}</span>`;
        } else {
            sizeHtml = `<span class="format-size format-size-unknown">Taille inconnue</span>`;
        }

        div.innerHTML = `
            <input type="radio" name="format" id="fmt-${index}" value="${escapeHtml(fmt.format_id)}">
            <div class="format-radio"></div>
            <span class="format-label">${escapeHtml(fmt.label)}</span>
            <span class="format-ext">${escapeHtml(fmt.ext)}</span>
            ${sizeHtml}
        `;

        div.addEventListener('click', () => {
            document.querySelectorAll('.format-option').forEach(el => el.classList.remove('selected'));
            div.classList.add('selected');
            div.querySelector('input').checked = true;
            selectedFormat = fmt.format_id;
            downloadBtn.disabled = false;
        });

        return div;
    }

    // ---- Bouton télécharger ----
    downloadBtn.addEventListener('click', async () => {
        if (!currentVideoData || !selectedFormat) return;

        downloadBtn.disabled = true;
        downloadBtn.classList.add('downloading');
        const originalHTML = downloadBtn.innerHTML;
        downloadBtn.innerHTML = `
            <div class="spinner" style="width:22px;height:22px;border-width:2px;margin:0"></div>
            <span>Téléchargement en cours... Patientez</span>
        `;
        hideError();

        try {
            const response = await fetch('api/download.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    url: currentVideoData.url,
                    format: selectedFormat,
                    csrf_token: csrfToken,
                }),
            });

            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.error || 'Erreur lors du téléchargement.');
            }

            // Lancer le téléchargement du fichier via un lien invisible
            if (data.data && data.data.download_url) {
                const a = document.createElement('a');
                a.href = data.data.download_url;
                a.download = '';
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                setTimeout(() => document.body.removeChild(a), 1000);

                // Afficher la taille du fichier téléchargé
                if (data.data.size) {
                    downloadBtn.innerHTML = `
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22">
                            <path d="M20 6L9 17l-5-5"/>
                        </svg>
                        <span>Téléchargé ! (${formatBytes(data.data.size)})</span>
                    `;
                    downloadBtn.classList.remove('downloading');
                    downloadBtn.classList.add('downloaded');
                    setTimeout(() => {
                        downloadBtn.innerHTML = originalHTML;
                        downloadBtn.classList.remove('downloaded');
                        downloadBtn.disabled = false;
                    }, 4000);
                    return;
                }
            }
        } catch (err) {
            showError(err.message || 'Impossible de télécharger la vidéo.');
        }

        downloadBtn.innerHTML = originalHTML;
        downloadBtn.classList.remove('downloading');
        downloadBtn.disabled = false;
    });

    // ---- Utilitaires ----
    function showLoading(text) {
        loadingText.textContent = text;
        loading.classList.remove('hidden');
    }

    function hideLoading() {
        loading.classList.add('hidden');
    }

    function showError(msg) {
        errorText.textContent = msg;
        errorDiv.classList.remove('hidden');
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideError() {
        errorDiv.classList.add('hidden');
    }

    function hideResult() {
        result.classList.add('hidden');
    }

    function setFetchBtnLoading(isLoading) {
        if (isLoading) {
            fetchBtn.disabled = true;
            fetchBtn.innerHTML = `
                <div class="spinner" style="width:20px;height:20px;border-width:2px;margin:0"></div>
                <span>Analyse...</span>
            `;
        } else {
            fetchBtn.disabled = false;
            fetchBtn.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span>Analyser</span>
            `;
        }
    }

    function formatDuration(seconds) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        if (h > 0) {
            return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        }
        return `${m}:${String(s).padStart(2, '0')}`;
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const sizes = ['B', 'Ko', 'Mo', 'Go'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return parseFloat((bytes / Math.pow(1024, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ---- Auto-détection du lien au collage ----
    urlInput.addEventListener('paste', () => {
        setTimeout(() => {
            const val = urlInput.value.trim();
            if (val && isValidUrl(val)) {
                // Animation subtile pour montrer que l'URL est détectée
                urlInput.style.borderColor = 'var(--success)';
                setTimeout(() => {
                    urlInput.style.borderColor = '';
                }, 1500);
            }
        }, 100);
    });

    // ---- Raccourci clavier Entrée ----
    urlInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });

})();
