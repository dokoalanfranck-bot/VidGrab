# VidGrab 📥

Téléchargeur de vidéos moderne et sécurisé pour **YouTube**, **Instagram** et **Facebook**.

## Caractéristiques ✨

- 🎬 Téléchargement multi-plateforme (YouTube, Instagram, Facebook)
- 🎨 Interface moderne avec design sombre (glassmorphism)
- 📱 Responsive & mobile-friendly
- 🔒 Sécurisé (CSRF, Rate limiting, validation URL)
- 📊 Affichage des tailles de fichier estimées
- 🎯 Choix de qualité (1080p, 720p, 480p, Audio MP3)
- ⚡ Installation simple sur serveur partagé
- 🔧 Aucune dépendance externe (PHP 7.4+)

## Installation rapide 🚀

### Requirements
- PHP 7.4+
- `exec()` activée
- yt-dlp installé
- ~50 Mo d'espace disque

### Sur serveur local

```bash
git clone https://github.com/dokoalanfranck-bot/VidGrab.git
cd VidGrab
php -S localhost:8000
```

Allez sur http://localhost:8000/setup.php et installez yt-dlp.

### Sur OVH/Mutualisé

1. Télécharger le ZIP du repo
2. Uploader via FTP dans `/www/vidgrab/`
3. Décompresser
4. Accès `https://votresite.com/vidgrab/setup.php`
5. Mot de passe: `vidgrab_setup_2024` (changez-le!)
6. Cliquez "Créer les dossiers" et "Installer yt-dlp"

## Utilisation 📖

1. Collez une URL (YouTube, Instagram ou Facebook)
2. Cliquez "Analyser"
3. Sélectionnez la qualité désirée
4. Téléchargez!

## Architecture 🏗️

```
vidgrab/
├── index.php              # Interface principale
├── setup.php              # Script d'installation
├── includes/
│   ├── config.php         # Configuration + CSRF + Rate limiting
│   └── Downloader.php     # Classe yt-dlp manager
├── api/
│   ├── info.php          # API: récupérer infos vidéo
│   ├── download.php      # API: télécharger vidéo
│   └── serve.php         # API: servir fichier
├── assets/
│   ├── css/style.css     # Styles
│   └── js/app.js         # Frontend logic
└── tmp/                   # Fichiers temporaires (auto-clearé)
```

## Sécurité 🔐

- ✅ CSRF tokens avec validation
- ✅ Rate limiting (10 req/min par session)
- ✅ Validation d'URLs stricte
- ✅ Protection directory traversal
- ✅ Protégé `.htaccess`
- ✅ Fichiers temporaires auto-supprimés après 1h
- ⚠️ **Changer le password setup.php avant production!**

## Variables d'environnement 🔧

Dans `includes/config.php`:

```php
define('MAX_FILE_SIZE_MB', 500);    // Limite de taille
define('MAX_DURATION', 3600);        // Durée max en secondes
define('RATE_LIMIT', 10);            // Requêtes par minute
define('CSRF_LIFETIME', 3600);       // Durée token CSRF
```

## Dépannage 🐛

| Problème | Solution |
|----------|----------|
| yt-dlp not found | Installer via `setup.php` ou télécharger manuellement |
| exec() disabled | Contacter hébergeur, demander activation |
| Timeout | Augmenter `max_execution_time` (60-120s) |
| Fichiers séparés (son + vidéo) | Vérifier que yt-dlp utilise format `best[height<=X]` |

## Limitations ⚠️

- Vidéos > 500 Mo rejetées (configurable)
- Durée max 1h (configurable)
- Dépend de la disponibilité de yt-dlp
- Respect obligatoire des conditions d'utilisation des sites

## License 📄

MIT - Usage personnel uniquement. Respectez les droits d'auteur.

## Support 💬

Pour les bugs/demandes, ouvrir une issue sur GitHub.

---

**Made with ❤️ by [VidGrab Team]**
