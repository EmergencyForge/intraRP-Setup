<?php

/**
 * intraRP Setup Script
 * Führt Git Pull aus, konfiguriert config.php und leitet zum Admin-Panel weiter
 */

// Fehlerausgabe für Debugging aktivieren
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session starten für Fehlermeldungen
session_start();

// Prüfen ob bereits eingerichtet
if (file_exists('assets/config/config.php')) {
    $existingConfig = file_get_contents('assets/config/config.php');
    if (strpos($existingConfig, 'CHANGE_ME') === false) {
        die('Setup wurde bereits durchgeführt. Bitte löschen Sie diese Datei manuell.');
    }
}

$errors = [];
$success = [];

// POST-Request verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Git Pull ausführen
    $gitOutput = [];
    $gitReturnVar = 0;
    $gitBranch = $_POST['git_branch'] ?? 'release';

    // Prüfen ob Git verfügbar ist
    exec('git --version 2>&1', $gitOutput, $gitReturnVar);

    if ($gitReturnVar === 0) {
        // Repository klonen oder pullen
        if (!is_dir('.git')) {
            // Neues Repository klonen
            $gitOutput = [];
            $repoUrl = 'https://github.com/EmergencyForge/intraRP.git';

            if ($gitBranch === 'main') {
                exec("git clone -b main {$repoUrl} . 2>&1", $gitOutput, $gitReturnVar);
                $success[] = 'Repository geklont (Branch: main - experimentell)';
            } else {
                // Letzten Release holen
                exec("git clone {$repoUrl} . 2>&1", $gitOutput, $gitReturnVar);
                if ($gitReturnVar === 0) {
                    exec('git fetch --tags 2>&1', $gitOutput, $gitReturnVar);
                    exec('git describe --tags `git rev-list --tags --max-count=1` 2>&1', $latestTag, $gitReturnVar);
                    if ($gitReturnVar === 0 && !empty($latestTag[0])) {
                        exec("git checkout {$latestTag[0]} 2>&1", $gitOutput, $gitReturnVar);
                        $success[] = 'Repository geklont (Letzter Release: ' . $latestTag[0] . ')';
                    }
                }
            }

            if ($gitReturnVar !== 0) {
                $errors[] = 'Git Clone Fehler: ' . implode('<br>', $gitOutput);
            }
        } else {
            // Bestehendes Repository aktualisieren
            $gitOutput = [];

            if ($gitBranch === 'main') {
                exec('git checkout main 2>&1', $gitOutput, $gitReturnVar);
                exec('git pull origin main 2>&1', $gitOutput, $gitReturnVar);
                $success[] = 'Git Pull erfolgreich (Branch: main - experimentell): ' . implode('<br>', $gitOutput);
            } else {
                // Zum letzten Release wechseln
                exec('git fetch --tags 2>&1', $gitOutput, $gitReturnVar);
                exec('git describe --tags `git rev-list --tags --max-count=1` 2>&1', $latestTag, $gitReturnVar);
                if ($gitReturnVar === 0 && !empty($latestTag[0])) {
                    exec("git checkout {$latestTag[0]} 2>&1", $gitOutput, $gitReturnVar);
                    $success[] = 'Zum letzten Release gewechselt: ' . $latestTag[0];
                } else {
                    $errors[] = 'Konnte letzten Release nicht ermitteln.';
                }
            }

            if ($gitReturnVar !== 0) {
                $errors[] = 'Git Pull/Checkout Fehler: ' . implode('<br>', $gitOutput);
            }
        }
    } else {
        $errors[] = 'Git ist nicht verfügbar. Überspringe Git Pull.';
    }

    // Version aus version.json auslesen
    $systemVersion = '0.4.4';
    if (file_exists('admin/system/updates/version.json')) {
        $versionData = json_decode(file_get_contents('admin/system/updates/version.json'), true);
        if (isset($versionData['version'])) {
            $systemVersion = ltrim($versionData['version'], 'v'); // 'v' am Anfang entfernen
            $success[] = 'Version aus version.json gelesen: ' . $systemVersion;
        }
    } else {
        $errors[] = 'version.json nicht gefunden. Verwende Standardversion.';
    }

    // Konfigurationsdaten aus Formular holen
    $config = [
        'SYSTEM_NAME' => trim($_POST['system_name'] ?? 'intraRP'),
        'SYSTEM_VERSION' => $systemVersion,
        'SYSTEM_COLOR' => trim($_POST['system_color'] ?? '#d10000'),
        'SYSTEM_URL' => trim($_POST['system_url'] ?? ''),
        'SYSTEM_LOGO' => trim($_POST['system_logo'] ?? '/assets/img/defaultLogo.webp'),
        'META_IMAGE_URL' => trim($_POST['meta_image_url'] ?? ''),
        'SERVER_NAME' => trim($_POST['server_name'] ?? ''),
        'SERVER_CITY' => trim($_POST['server_city'] ?? 'Musterstadt'),
        'RP_ORGTYPE' => trim($_POST['rp_orgtype'] ?? 'Berufsfeuerwehr'),
        'RP_STREET' => trim($_POST['rp_street'] ?? 'Musterweg 0815'),
        'RP_ZIP' => trim($_POST['rp_zip'] ?? '1337'),
        'CHAR_ID' => isset($_POST['char_id']) ? 'true' : 'false',
        'ENOTF_PREREG' => isset($_POST['enotf_prereg']) ? 'true' : 'false',
        // 'LANG' => trim($_POST['lang'] ?? 'de'),
        'BASE_PATH' => trim($_POST['base_path'] ?? '/'),
    ];

    // .env Daten
    $envConfig = [
        'DB_HOST' => trim($_POST['db_host'] ?? 'localhost'),
        'DB_USER' => trim($_POST['db_user'] ?? 'root'),
        'DB_PASS' => trim($_POST['db_pass'] ?? ''),
        'DB_NAME' => trim($_POST['db_name'] ?? 'intrarp'),
        'DISCORD_CLIENT_ID' => trim($_POST['discord_client_id'] ?? ''),
        'DISCORD_CLIENT_SECRET' => trim($_POST['discord_client_secret'] ?? ''),
    ];

    // Validierung
    if (empty($config['SYSTEM_URL'])) {
        $errors[] = 'System-URL ist erforderlich!';
    }
    if (empty($config['SERVER_NAME'])) {
        $errors[] = 'Server-Name ist erforderlich!';
    }
    if (empty($envConfig['DB_NAME'])) {
        $errors[] = 'Datenbank-Name ist erforderlich!';
    }
    if (empty($envConfig['DISCORD_CLIENT_ID'])) {
        $errors[] = 'Discord Client ID ist erforderlich!';
    }
    if (empty($envConfig['DISCORD_CLIENT_SECRET'])) {
        $errors[] = 'Discord Client Secret ist erforderlich!';
    }

    // Config-Datei erstellen wenn keine Fehler
    if (empty($errors)) {

        // Verzeichnis erstellen falls nicht vorhanden
        if (!is_dir('assets/config')) {
            mkdir('assets/config', 0755, true);
        }

        // Config-Inhalt generieren
        $configContent = "<?php\n";
        $configContent .= "// BASIS DATEN\n";
        $configContent .= "define('SYSTEM_NAME', '{$config['SYSTEM_NAME']}'); // Eigenname des Intranets\n";
        $configContent .= "define('SYSTEM_VERSION', '{$config['SYSTEM_VERSION']}'); // Versionsnummer\n";
        $configContent .= "define('SYSTEM_COLOR', '{$config['SYSTEM_COLOR']}'); // Hauptfarbe des Systems\n";
        $configContent .= "define('SYSTEM_URL', '{$config['SYSTEM_URL']}'); // Domain des Systems\n";
        $configContent .= "define('SYSTEM_LOGO', '{$config['SYSTEM_LOGO']}'); // Ort des Logos (entweder als relativer Pfad oder Link)\n";
        $configContent .= "define('META_IMAGE_URL', '{$config['META_IMAGE_URL']}'); // Ort des Bildes, welches in der Link-Vorschau angezeigt werden soll (immer als Link angeben!)\n";
        $configContent .= "// SERVER DATEN\n";
        $configContent .= "define('SERVER_NAME', '{$config['SERVER_NAME']}'); // Name des Servers\n";
        $configContent .= "define('SERVER_CITY', '{$config['SERVER_CITY']}'); // Name der Stadt in welcher der Server spielt\n";
        $configContent .= "// RP DATEN\n";
        $configContent .= "define('RP_ORGTYPE', '{$config['RP_ORGTYPE']}'); // Art/Name der Organisation\n";
        $configContent .= "define('RP_STREET', '{$config['RP_STREET']}'); // Straße der Organisation\n";
        $configContent .= "define('RP_ZIP', '{$config['RP_ZIP']}'); // PLZ der Organisation\n";
        $configContent .= "// FUNKTIONEN\n";
        $configContent .= "define('CHAR_ID', {$config['CHAR_ID']}); // Wird eine eindeutige Charakter-ID verwendet? (true = ja, false = nein)\n";
        $configContent .= "define('ENOTF_PREREG', {$config['ENOTF_PREREG']}); // Wird das Voranmeldungssystem des eNOTF verwendet? (true = ja, false = nein)\n";
        // $configContent .= "define('LANG', '{$config['LANG']}'); // Sprache des Systems (de = Deutsch, en = Englisch) // AKTUELL OHNE FUNKTION!\n";
        $configContent .= "define('LANG', 'de'); // Sprache des Systems (de = Deutsch, en = Englisch) // AKTUELL OHNE FUNKTION!\n";
        $configContent .= "define('BASE_PATH', '{$config['BASE_PATH']}'); // Basis-Pfad des Systems (z.B. /intraRP/ für https://domain.de/intraRP/)";

        // Config-Datei schreiben
        if (file_put_contents('assets/config/config.php', $configContent)) {
            $success[] = 'Konfigurationsdatei erfolgreich erstellt!';

            // .env Datei erstellen
            $envContent = "DB_HOST={$envConfig['DB_HOST']}\n";
            $envContent .= "DB_USER={$envConfig['DB_USER']}\n";
            $envContent .= "DB_PASS={$envConfig['DB_PASS']}\n";
            $envContent .= "DB_NAME={$envConfig['DB_NAME']}\n\n";
            $envContent .= "DISCORD_CLIENT_ID={$envConfig['DISCORD_CLIENT_ID']}\n";
            $envContent .= "DISCORD_CLIENT_SECRET={$envConfig['DISCORD_CLIENT_SECRET']}";

            if (file_put_contents('.env', $envContent)) {
                $success[] = '.env Datei erfolgreich erstellt!';
            } else {
                $errors[] = 'Fehler beim Schreiben der .env Datei. Prüfen Sie die Schreibrechte!';
            }

            // Composer install ausführen
            $composerOutput = [];
            $composerReturnVar = 0;

            // Prüfen ob Composer verfügbar ist
            exec('composer --version 2>&1', $composerOutput, $composerReturnVar);

            if ($composerReturnVar === 0) {
                $composerOutput = [];
                exec('composer install --no-dev --optimize-autoloader 2>&1', $composerOutput, $composerReturnVar);

                if ($composerReturnVar === 0) {
                    $success[] = 'Composer Abhängigkeiten erfolgreich installiert!';
                } else {
                    $errors[] = 'Composer Install Fehler: ' . implode('<br>', $composerOutput);
                }
            } else {
                $errors[] = 'Composer ist nicht verfügbar. Bitte führen Sie "composer install" manuell aus.';
            }

            // Setup-Datei löschen und weiterleiten
            $setupFile = __FILE__;

            // Nur weiterleiten wenn keine kritischen Fehler aufgetreten sind
            if (empty($errors)) {
                // Weiterleitung vorbereiten
                header('refresh:3;url=admin/index.php');

                echo '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup erfolgreich</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        .success { color: #28a745; font-size: 1.5em; margin-bottom: 20px; }
        .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #d10000; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">✓ Setup erfolgreich abgeschlossen!</div>
        <div class="spinner"></div>
        <p>Sie werden in Kürze zum Admin-Panel weitergeleitet...</p>
        <p><small>setup.php wird automatisch gelöscht.</small></p>
    </div>
</body>
</html>';

                // Setup-Datei löschen
                @unlink($setupFile);
                exit;
            }
        } else {
            $errors[] = 'Fehler beim Schreiben der Konfigurationsdatei. Prüfen Sie die Schreibrechte!';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>intraRP Setup</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .header {
            background: #d10000;
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
        }

        .content {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .form-group input[type="text"],
        .form-group input[type="url"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 1em;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #d10000;
        }

        .form-group small {
            display: block;
            color: #666;
            margin-top: 5px;
            font-size: 0.9em;
        }

        .form-group code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            color: #d10000;
        }

        .color-picker-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .color-picker-wrapper input[type="color"] {
            width: 60px;
            height: 45px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            background: white;
        }

        .color-picker-wrapper input[type="color"]::-webkit-color-swatch-wrapper {
            padding: 4px;
        }

        .color-picker-wrapper input[type="color"]::-webkit-color-swatch {
            border: none;
            border-radius: 4px;
        }

        .color-picker-wrapper input[type="text"] {
            flex: 1;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }

        .section-title {
            font-size: 1.3em;
            color: #d10000;
            margin: 30px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        .section-title:first-child {
            margin-top: 0;
        }

        .btn {
            background: #d10000;
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 6px;
            font-size: 1.1em;
            cursor: pointer;
            width: 100%;
            font-weight: 600;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #a00000;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee;
            border-left: 4px solid #d00;
            color: #c00;
        }

        .alert-success {
            background: #efe;
            border-left: 4px solid #0d0;
            color: #0a0;
        }

        .alert ul {
            margin-left: 20px;
            margin-top: 10px;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            color: #1976d2;
        }

        .info-box strong {
            display: block;
            margin-bottom: 5px;
        }

        .radio-group {
            margin-top: 10px;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .radio-group label:hover {
            border-color: #d10000;
            background: #fff5f5;
        }

        .radio-group input[type="radio"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }

        .radio-group input[type="radio"]:checked+span {
            font-weight: 600;
            color: #d10000;
        }

        .radio-group label span {
            flex: 1;
        }

        .radio-group label small {
            display: block;
            color: #666;
            font-size: 0.85em;
            margin-top: 4px;
        }

        .warning-badge {
            display: inline-block;
            background: #ff9800;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            font-weight: 600;
            margin-left: 8px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>intraRP Setup</h1>
            <p>Konfigurieren Sie Ihr Intranet-System</p>
        </div>

        <div class="content">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong>Fehler:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <strong>Erfolg:</strong>
                    <ul>
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo $msg; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="section-title">Git Repository</div>

                <div class="form-group">
                    <label>Version auswählen</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="git_branch" value="release" checked>
                            <span>
                                <div>
                                    <strong>Letzter Release</strong>
                                    <span class="warning-badge" style="background: #4caf50;">EMPFOHLEN</span>
                                </div>
                                <small>Stabile Version - empfohlen für Produktivumgebungen</small>
                            </span>
                        </label>
                        <label>
                            <input type="radio" name="git_branch" value="main">
                            <span>
                                <div>
                                    <strong>Main Branch</strong>
                                    <span class="warning-badge">EXPERIMENTELL</span>
                                </div>
                                <small>Neueste Entwicklungsversion - kann instabil sein</small>
                            </span>
                        </label>
                    </div>
                    <small>Repository: <code>github.com/EmergencyForge/intraRP</code></small>
                </div>

                <div class="section-title">Basis-Daten</div>

                <div class="form-group">
                    <label for="system_name">System-Name</label>
                    <input type="text" id="system_name" name="system_name" value="intraRP" required>
                    <small>Eigenname des Intranets</small>
                </div>

                <div class="form-group">
                    <label for="system_url">System-URL *</label>
                    <input type="url" id="system_url" name="system_url" placeholder="https://example.com" required>
                    <small>Domain des Systems (mit https://)</small>
                </div>

                <div class="form-group">
                    <label for="system_color">System-Farbe</label>
                    <div class="color-picker-wrapper">
                        <input type="color" id="system_color_picker" value="#d10000">
                        <input type="text" id="system_color" name="system_color" value="#d10000" pattern="^#[0-9A-Fa-f]{6}$">
                    </div>
                    <small>Hauptfarbe des Systems (Hex-Code)</small>
                </div>

                <script>
                    // Farbselektor synchronisieren
                    const colorPicker = document.getElementById('system_color_picker');
                    const colorInput = document.getElementById('system_color');

                    colorPicker.addEventListener('input', function() {
                        colorInput.value = this.value;
                    });

                    colorInput.addEventListener('input', function() {
                        if (/^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                            colorPicker.value = this.value;
                        }
                    });
                </script>

                <div class="form-group">
                    <label for="system_logo">System-Logo</label>
                    <input type="text" id="system_logo" name="system_logo" value="/assets/img/defaultLogo.webp">
                    <small>Pfad oder URL zum Logo</small>
                </div>

                <div class="form-group">
                    <label for="meta_image_url">Meta-Bild URL</label>
                    <input type="url" id="meta_image_url" name="meta_image_url" placeholder="https://example.com/preview.jpg">
                    <small>Bild für Link-Vorschau (vollständige URL)</small>
                </div>

                <div class="section-title">Server-Daten</div>

                <div class="form-group">
                    <label for="server_name">Server-Name *</label>
                    <input type="text" id="server_name" name="server_name" required>
                    <small>Name des Roleplay-Servers</small>
                </div>

                <div class="form-group">
                    <label for="server_city">Stadt</label>
                    <input type="text" id="server_city" name="server_city" value="Musterstadt">
                    <small>Stadt in der der Server spielt</small>
                </div>

                <div class="section-title">Roleplay-Daten</div>

                <div class="form-group">
                    <label for="rp_orgtype">Organisationstyp</label>
                    <input type="text" id="rp_orgtype" name="rp_orgtype" value="Berufsfeuerwehr">
                    <small>Art/Name der Organisation</small>
                </div>

                <div class="form-group">
                    <label for="rp_street">Straße</label>
                    <input type="text" id="rp_street" name="rp_street" value="Musterweg 0815">
                    <small>Straße der Organisation</small>
                </div>

                <div class="form-group">
                    <label for="rp_zip">Postleitzahl</label>
                    <input type="text" id="rp_zip" name="rp_zip" value="1337">
                    <small>PLZ der Organisation</small>
                </div>

                <div class="section-title">Funktionen & Einstellungen</div>

                <div class="form-group">
                    <label for="base_path">Basis-Pfad</label>
                    <input type="text" id="base_path" name="base_path" value="/">
                    <small>Basis-Pfad des Systems (z.B. <code>/intraRP/</code> für https://domain.de/intraRP/)</small>
                </div>

                <!--
                <div class="form-group">
                    <label for="lang">Sprache</label>
                    <input type="text" id="lang" name="lang" value="de" maxlength="2">
                    <small>Sprache (de = Deutsch, en = Englisch)</small>
                </div>
                -->

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="char_id" name="char_id" checked>
                        <label for="char_id">Eindeutige Charakter-ID verwenden</label>
                    </div>
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="enotf_prereg" name="enotf_prereg" checked>
                        <label for="enotf_prereg">eNOTF Voranmeldungssystem verwenden</label>
                    </div>
                </div>

                <div class="section-title">Datenbank-Konfiguration</div>

                <div class="form-group">
                    <label for="db_host">Datenbank-Host</label>
                    <input type="text" id="db_host" name="db_host" value="localhost" required>
                    <small>Host der Datenbank (meistens <code>localhost</code>)</small>
                </div>

                <div class="form-group">
                    <label for="db_user">Datenbank-Benutzer</label>
                    <input type="text" id="db_user" name="db_user" value="root" required>
                    <small>Benutzername für die Datenbank</small>
                </div>

                <div class="form-group">
                    <label for="db_pass">Datenbank-Passwort</label>
                    <input type="text" id="db_pass" name="db_pass">
                    <small>Passwort für die Datenbank (optional)</small>
                </div>

                <div class="form-group">
                    <label for="db_name">Datenbank-Name *</label>
                    <input type="text" id="db_name" name="db_name" value="intrarp" required>
                    <small>Name der zu verwendenden Datenbank</small>
                </div>

                <div class="section-title">Discord-Integration</div>

                <div class="info-box">
                    <strong>ℹ️ Discord Applikation benötigt</strong>
                    Für die Discord-Integration muss eine Discord-Applikation erstellt werden. Eine detaillierte Anleitung finden Sie hier:
                    <a href="https://docs.intrarp.de/intrarp/discord-applikation-erstellen" target="_blank" style="color: #1976d2; font-weight: 600;">Discord-Applikation erstellen →</a>
                </div>

                <div class="form-group">
                    <label for="discord_client_id">Discord Client ID *</label>
                    <input type="text" id="discord_client_id" name="discord_client_id" required>
                    <small>Client ID der Discord-Anwendung</small>
                </div>

                <div class="form-group">
                    <label for="discord_client_secret">Discord Client Secret *</label>
                    <input type="text" id="discord_client_secret" name="discord_client_secret" required>
                    <small>Client Secret der Discord-Anwendung</small>
                </div>

                <div class="info-box">
                    <strong>ℹ️ Hinweis:</strong>
                    Alle hier eingegebenen Werte können später in den Dateien <code>/assets/config/config.php</code> und <code>/.env</code> manuell angepasst werden.
                </div>

                <button type="submit" class="btn">Setup durchführen</button>
            </form>
        </div>
    </div>
</body>

</html>