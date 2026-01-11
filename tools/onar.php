<?php
/**
 * SpeedPage - Onarım ve Sistem Bakım Dosyası
 * Bu araç sistemdeki kritik sorunları tespit eder ve tek tıkla onarır.
 */
/**
 * SpeedPage v0.2 Alpha - Onarım ve Sistem Bakım Dosyası
 */
// 1. Ayarları Yükle
$settingsPath = __DIR__ . '/settings.php';
if (file_exists($settingsPath)) {
    require_once $settingsPath;
} else {
    die("Hata: settings.php bulunamadı. Lütfen dosya yolunu kontrol edin.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once ROOT_DIR . 'admin/db.php';

$message = "";
$messageType = "info";

// --- 2. ONARIM FONKSİYONLARI ---

/**
 * Eksik klasörleri oluşturur.
 */
function fix_folders()
{
    $dirs = [
        ROOT_DIR . 'sayfalar',
        ROOT_DIR . 'modules',
        ROOT_DIR . 'cdn/images',
        ROOT_DIR . 'admin/veritabanı',
        ROOT_DIR . 'admin/_backups', // Yeni yedekleme klasörü
        ROOT_DIR . 'media',
    ];
    $created = 0;
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            if (mkdir($d, 0755, true))
                $created++;
        }
    }
    return $created;
}
function fix_database($db)
{
    // AI Tabloları ve diğer eksik yapıların kontrolü
    $queries = [
        "CREATE TABLE IF NOT EXISTS ai_settings (key_name TEXT PRIMARY KEY, value_text TEXT)",
        "CREATE TABLE IF NOT EXISTS ai_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, model TEXT, prompt TEXT, response TEXT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, details TEXT, ip_address TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)"
    ];

    foreach ($queries as $q) {
        $db->exec($q);
    }

    // Varsayılan AI ayarlarını kontrol et ve ekle
    $check = $db->query("SELECT COUNT(*) FROM ai_settings WHERE key_name = 'selected_model'")->fetchColumn();
    if ($check == 0) {
        $db->exec("INSERT INTO ai_settings (key_name, value_text) VALUES ('selected_model', 'google/gemini-2.0-flash-exp')");
        $db->exec("INSERT INTO ai_settings (key_name, value_text) VALUES ('custom_models', '[]')");
    }

    return true;
}
/**
 * Geçici yükleme klasörlerini temizler.
 */
function clean_tmp_folders()
{
    $tmpDir = ROOT_DIR . "modules/";
    $cleaned = 0;
    foreach (glob($tmpDir . "tmp_*", GLOB_ONLYDIR) as $dir) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        if (@rmdir($dir))
            $cleaned++;
    }
    return $cleaned;
}

/**
 * DB WAL Modunu ve Meşguliyet Ayarlarını Yapılandırır.
 */
function fix_database_locks()
{
    global $db;
    try {
        $db->exec("PRAGMA journal_mode = WAL;");
        $db->exec("PRAGMA busy_timeout = 5000;");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Eğer hiç admin yoksa varsayılan admin oluşturur.
 */
function recover_admin()
{
    global $db;
    $count = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($count == 0) {
        $pass = password_hash('admin123', PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (username, password, email, role, is_active) VALUES ('admin', ?, 'admin@example.com', 'admin', 1)");
        return $stmt->execute([$pass]);
    }
    return false;
}

// --- 3. AKSİYONLAR ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['auto_fix'])) {
        $fCount = fix_folders();
        $f = fix_folders();
        $d = fix_database($db);
        $tCount = clean_tmp_folders();
        $dbFix = fix_database_locks();
        $message = "Sistem onarımı tamamlandı. $fCount klasör oluşturuldu, $tCount geçici klasör temizlendi.";
        if ($dbFix)
            $message .= " Veritabanı kilit önleme modu (WAL) aktif edildi.";
        $messageType = "success";
        sp_log("Sistem otomatik onarım aracı çalıştırıldı.", "system_repair");
    }

    if (isset($_POST['recover_admin'])) {
        if (recover_admin()) {
            $message = "Yönetici hesabı kurtarıldı! Kullanıcı: admin / Şifre: admin123 (Lütfen hemen değiştirin)";
            $messageType = "success";
        } else {
            $message = "Sistemde zaten en az bir yönetici mevcut, kurtarma gerekmiyor.";
            $messageType = "warning";
        }
    }

    if (isset($_POST['reset_db'])) {
        try {
            $tables = ['logs', 'login_attempts', 'modules', 'module_assets', 'page_assets', 'theme_settings'];
            foreach ($tables as $t) {
                $db->exec("DELETE FROM $t");
            }
            $db->exec("DELETE FROM pages WHERE id > 1");
            $db->exec("DELETE FROM menus WHERE id > 1");
            $db->exec("DELETE FROM users WHERE id > 1");
            $db->exec("UPDATE sqlite_sequence SET seq = 0");
            $db->exec("VACUUM");
            $message = "Fabrika ayarlarına dönüldü. Tüm veriler sıfırlandı.";
            $messageType = "danger";
            sp_log("Veritabanı fabrika ayarlarına döndürüldü.", "system_reset");
        } catch (Exception $e) {
            $message = "Sıfırlama hatası: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// --- 4. ANALİZ ---
$protected_files = [
    '' => ['index.php', 'page.php', 'settings.php'],
    'admin' => ['modul-func.php', 'db.php', 'system-panel.php'],
    'php' => ['theme-init.php', 'logger.php', 'hooks.php'],
    'cdn/lang' => ['tr.json', 'en.json']
];

$missing_files = [];
foreach ($protected_files as $dir => $files) {
    foreach ($files as $f) {
        $path = ROOT_DIR . ($dir ? $dir . '/' : '') . $f;
        if (!file_exists($path))
            $missing_files[] = ($dir ? $dir . '/' : '') . $f;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedPage Onarım Merkezi</title>
    <link rel="stylesheet" href="<?= CDN_URL ?>css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= CDN_URL ?>css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --main-color: #00d2ff;
            --glow-color: rgba(0, 210, 255, 0.5);
            --bg-dark: #0a0a0f;
        }

        body {
            background-color: var(--bg-dark);
            color: #e0e0e0;
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            margin: 0;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(0, 210, 255, 0.05) 0%, transparent 70%);
            z-index: -1;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 30px;
            padding: 3rem;
            width: 100%;
            max-width: 900px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
        }

        .glow-text {
            color: var(--main-color);
            text-shadow: 0 0 15px var(--glow-color);
            font-weight: 800;
        }

        .action-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 1.5rem;
            transition: 0.3s;
            height: 100%;
        }

        .action-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--main-color);
            transform: translateY(-5px);
        }

        .btn-glow {
            background: var(--main-color);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 100px;
            padding: 0.8rem 2rem;
            box-shadow: 0 0 15px var(--glow-color);
            transition: 0.3s;
        }

        .btn-glow:hover {
            box-shadow: 0 0 25px var(--main-color);
            transform: scale(1.05);
            color: white;
        }

        .status-badge {
            font-size: 0.8rem;
            padding: 0.4rem 1rem;
            border-radius: 50px;
        }

        pre {
            background: rgba(0, 0, 0, 0.3);
            color: #00ff00;
            padding: 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
        }
    </style>
</head>

<body>

    <div class="glass-card">
        <div class="text-center mb-5">
            <h1 class="glow-text mb-2"><i class="fas fa-tools me-2"></i> Onarım Merkezi</h1>
            <p class="text-white-50">SpeedPage Sistem Sağlığı ve Hızlı Kurtarma Aracı</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> border-0 rounded-4 shadow-sm mb-4">
                <i class="fas fa-info-circle me-2"></i> <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-5">
            <!-- ANALİZ -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5 class="mb-3"><i class="fas fa-search me-2 text-info"></i> Sistem Analizi</h5>
                    <ul class="list-unstyled small opacity-75">
                        <li class="mb-2">PHP Sürümü: <span class="text-white"><?= PHP_VERSION ?></span></li>
                        <li class="mb-2">Veritabanı: <span
                                class="<?= is_writable(dirname(DB_PATH)) ? 'text-success' : 'text-danger' ?>"><?= is_writable(dirname(DB_PATH)) ? 'Erişilebilir' : 'Kilitli/Yazılamaz' ?></span>
                        </li>
                        <li class="mb-2">Eksik Dosyalar: <span
                                class="<?= empty($missing_files) ? 'text-success' : 'text-danger' ?>"><?= empty($missing_files) ? 'Yok' : count($missing_files) . ' adet' ?></span>
                        </li>
                    </ul>
                    <?php if (!empty($missing_files)): ?>
                        <div class="mt-2">
                            <small class="text-danger d-block mb-1">Eksik Kritik Dosyalar:</small>
                            <pre><?= implode("\n", $missing_files) ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- HIZLI ONARIM -->
            <div class="col-md-6">
                <div class="action-card d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="mb-3"><i class="fas fa-magic me-2 text-warning"></i> Hızlı Onarım</h5>
                        <p class="small text-white-50">Eksik klasörleri oluşturur, veritabanı kilitlerini (WAL) açar ve
                            geçici dosyaları temizler.</p>
                    </div>
                    <form method="POST">
                        <button type="submit" name="auto_fix" class="btn btn-glow w-100">Şimdi Onar</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- ADMIN RECOVERY -->
            <div class="col-md-6">
                <div class="action-card">
                    <h5 class="mb-3"><i class="fas fa-user-shield me-2 text-primary"></i> Yönetici Kurtarma</h5>
                    <p class="small text-white-50">Eğer admin panel girişi yapılamıyorsa (veya kullanıcı silindiyse)
                        yeni bir yönetici oluşturur.</p>
                    <form method="POST">
                        <button type="submit" name="recover_admin"
                            class="btn btn-outline-primary rounded-pill w-100">Hesabı Kurtar</button>
                    </form>
                </div>
            </div>

            <!-- FACTORY RESET -->
            <div class="col-md-6">
                <div class="action-card border-danger border-opacity-25">
                    <h5 class="mb-3 text-danger"><i class="fas fa-exclamation-triangle me-2"></i> Fabrika Ayarları</h5>
                    <p class="small text-white-50">Kritik! Tüm tabloları sıfırlar, özel sayfaları ve logları siler.
                        Sistem ilk kurulum haline döner.</p>
                    <form method="POST"
                        onsubmit="return confirm('TÜM VERİLER SİLİNECEK! Bu işlem geri alınamaz. Emin misiniz?')">
                        <button type="submit" name="reset_db" class="btn btn-outline-danger rounded-pill w-100">Sistemi
                            Sıfırla</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="text-center mt-5">
            <a href="../../admin/index.php" class="text-white-50 text-decoration-none small"><i
                    class="fas fa-arrow-left me-1"></i> Panele Geri Dön</a>
            <span class="mx-3 opacity-25">|</span>
            <small>SpeedPage Repair Tool <span class="badge bg-primary">v0.2 Alpha</span></small>
        </div>
    </div>

</body>

</html>