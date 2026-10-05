<?php
declare(strict_types=1);

function render_page(
    string $page,
    ?string $message = null,
    array $errors = [],
    array $old = [],
    string $token = '',
    ?array $user = null
): void {
    $titles = [
        'login' => ['Masuk ke akun', 'Pantau kondisi dan peringatan di satu tempat.'],
        'signup' => ['Buat akun EWS', 'Daftarkan akun untuk mengakses sistem peringatan dini.'],
        'forgot-password' => ['Lupa password?', 'Kami akan mengirim tautan untuk membuat password baru.'],
        'reset-password' => ['Buat password baru', 'Gunakan password yang kuat dan belum pernah dipakai.'],
    ];
    $title = $titles[$page][0] ?? 'Early Warning System';
    $subtitle = $titles[$page][1] ?? '';
    $csrf = e(csrf_token());
    $email = e((string) ($old['email'] ?? ''));
    $name = e((string) ($old['name'] ?? ''));
    $tokenValue = e($token);
    $errorHtml = '';
    foreach ($errors as $error) {
        $errorHtml .= '<p class="field-error">' . e($error) . '</p>';
    }
    $messageHtml = $message === null
        ? ''
        : '<div class="notice" role="status">' . e($message) . '</div>';

    if ($page === 'login') {
        $form = <<<HTML
            <form method="post" action="/?page=login">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" value="{$email}" placeholder="nama@instansi.go.id" required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password" required>
                <div class="form-row"><span></span><a href="/?page=forgot-password">Lupa password?</a></div>
                <button type="submit">Masuk <span aria-hidden="true">→</span></button>
            </form>
            <p class="switch">Belum memiliki akun? <a href="/?page=signup">Daftar sekarang</a></p>
        HTML;
    } elseif ($page === 'signup') {
        $form = <<<HTML
            <form method="post" action="/?page=signup">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <label for="name">Nama lengkap</label>
                <input id="name" name="name" type="text" autocomplete="name" value="{$name}" maxlength="100" placeholder="Nama Anda" required>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" value="{$email}" placeholder="nama@instansi.go.id" required>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" placeholder="Minimal 12 karakter" required>
                <label for="password_confirmation">Ulangi password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" placeholder="Masukkan kembali password" required>
                <button type="submit">Buat akun <span aria-hidden="true">→</span></button>
            </form>
            <p class="switch">Sudah memiliki akun? <a href="/?page=login">Masuk</a></p>
        HTML;
    } elseif ($page === 'forgot-password') {
        $form = <<<HTML
            <form method="post" action="/?page=forgot-password">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <label for="email">Email terdaftar</label>
                <input id="email" name="email" type="email" autocomplete="email" value="{$email}" placeholder="nama@instansi.go.id" required>
                <button type="submit">Kirim tautan reset <span aria-hidden="true">→</span></button>
            </form>
            <p class="switch"><a href="/?page=login">← Kembali ke halaman masuk</a></p>
        HTML;
    } elseif ($page === 'reset-password') {
        $form = $token === ''
            ? '<p class="invalid-link">Tautan reset tidak valid atau sudah kedaluwarsa.</p><p class="switch"><a href="/?page=forgot-password">Minta tautan reset baru</a></p>'
            : <<<HTML
                <form method="post" action="/?page=reset-password">
                    <input type="hidden" name="csrf_token" value="{$csrf}">
                    <input type="hidden" name="token" value="{$tokenValue}">
                    <label for="password">Password baru</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" placeholder="Minimal 12 karakter" required>
                    <label for="password_confirmation">Ulangi password baru</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" placeholder="Masukkan kembali password" required>
                    <button type="submit">Simpan password <span aria-hidden="true">→</span></button>
                </form>
            HTML;
    } else {
        $form = '';
    }
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0c1b2a">
        <title><?= e($title) ?> · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
        <script src="/assets/app.js" defer></script>
    </head>
    <body>
    <main class="auth-layout">
        <section class="brand-panel" aria-label="Tentang Early Warning System">
            <a class="brand" href="/?page=login" aria-label="EWS beranda">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>EARLY WARNING <strong>SYSTEM</strong></span>
            </a>
            <div class="brand-copy">
                <p class="eyebrow">PLATFORM PEMANTAUAN TERPADU</p>
                <h1>Waspada lebih awal.<br><em>Bertindak lebih cepat.</em></h1>
                <p>Informasi cuaca, tornado, banjir sungai, dan pasang surut dalam satu pandangan yang jelas.</p>
            </div>
            <div class="brand-footer">
                <span class="live-dot"></span> SISTEM PEMANTAUAN
                <span class="footer-separator">·</span> TERINTEGRASI
            </div>
            <div class="contour contour-one"></div>
            <div class="contour contour-two"></div>
        </section>
        <section class="form-panel">
            <div class="mobile-brand"><span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span> EWS</div>
            <div class="form-wrap">
                <div class="form-heading">
                    <p class="eyebrow">PORTAL OPERASIONAL EWS</p>
                    <h2><?= e($title) ?></h2>
                    <p><?= e($subtitle) ?></p>
                </div>
                <?= $messageHtml ?>
                <?= $errorHtml ?>
                <?= $form ?>
                <p class="security-note"><span aria-hidden="true">◈</span> Akses aman untuk personel berwenang</p>
            </div>
            <footer class="form-footer">© <?= date('Y') ?> Early Warning System <span>•</span> Untuk penggunaan internal</footer>
        </section>
    </main>
    </body>
    </html>
    <?php
}

function dashboard_sections(array $user): array
{
    $admin = $user['role'] === 'system_admin';
    $sections = [
        'overview' => [
            'label' => 'Ringkasan',
            'group' => 'Pemantauan',
            'icon' => '◫',
            'permission' => 'dashboard',
        ],
        'alerts' => [
            'label' => 'Kejadian aktif',
            'group' => 'Pemantauan',
            'icon' => '⌁',
            'permission' => 'handle_alerts',
        ],
        'history' => [
            'label' => 'Riwayat peringatan',
            'group' => 'Pemantauan',
            'icon' => '◷',
            'permission' => 'view_reports',
        ],
        'hazards' => [
            'label' => 'Jenis bahaya',
            'group' => 'Master data',
            'icon' => '◇',
            'permission' => 'manage_master_data',
        ],
        'locations' => [
            'label' => 'Wilayah & lokasi',
            'group' => 'Master data',
            'icon' => '⌖',
            'permission' => 'manage_master_data',
        ],
        'sensors' => [
            'label' => 'Sensor & sumber',
            'group' => 'Master data',
            'icon' => '⌁',
            'permission' => 'manage_master_data',
        ],
        'parameters' => [
            'label' => 'Parameter',
            'group' => 'Master data',
            'icon' => '≋',
            'permission' => 'manage_master_data',
        ],
        'thresholds' => [
            'label' => 'Ambang & persetujuan',
            'group' => 'Master data',
            'icon' => '⌗',
            'permission' => 'manage_master_data',
        ],
        'rules' => [
            'label' => 'Aturan & eskalasi',
            'group' => 'Master data',
            'icon' => '⇧',
            'permission' => 'manage_master_data',
        ],
        'recipients' => [
            'label' => 'Penerima notifikasi',
            'group' => 'Master data',
            'icon' => '◎',
            'permission' => 'manage_master_data',
        ],
        'integrations' => [
            'label' => 'Integrasi data',
            'group' => 'Sistem',
            'icon' => '↔',
            'permission' => 'manage_master_data',
        ],
        'reports' => [
            'label' => 'Laporan & ekspor',
            'group' => 'Sistem',
            'icon' => '▤',
            'permission' => 'view_reports',
        ],
        'health' => [
            'label' => 'Kesehatan sistem',
            'group' => 'Sistem',
            'icon' => '⌁',
            'permission' => 'manage_master_data',
        ],
        'users' => [
            'label' => 'Pengguna & akses',
            'group' => 'Administrasi',
            'icon' => '♙',
            'permission' => 'manage_access',
            'page' => 'admin',
        ],
        'audit' => [
            'label' => 'Audit aktivitas',
            'group' => 'Administrasi',
            'icon' => '≡',
            'permission' => 'manage_access',
        ],
    ];

    return array_filter(
        $sections,
        static fn (array $section): bool => $admin
            || user_has_permission($user, $section['permission'])
    );
}

function render_app_shell_start(
    array $user,
    string $activeSection,
    string $pageTitle,
    string $pageEyebrow
): void {
    $sections = dashboard_sections($user);
    $roles = role_labels();
    $pendingUsers = $user['role'] === 'system_admin'
        ? (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn()
        : 0;
    $groups = [];
    foreach ($sections as $key => $section) {
        $groups[$section['group']][$key] = $section;
    }
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0c1b2a">
        <title><?= e($pageTitle) ?> · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
        <script src="/assets/app.js" defer></script>
    </head>
    <body class="app-body">
    <div class="app-shell">
        <aside class="app-sidebar" id="app-sidebar">
            <a class="brand sidebar-brand" href="/?page=dashboard">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>EARLY WARNING <strong>SYSTEM</strong></span>
            </a>
            <div class="workspace-switcher">
                <span class="workspace-mark">E</span>
                <span><strong>Workspace EWS</strong><small>Operasional terpadu</small></span>
                <span class="workspace-chevron" aria-hidden="true">⌄</span>
            </div>
            <nav class="app-nav" aria-label="Menu utama">
                <?php foreach ($groups as $groupName => $items): ?>
                    <div class="nav-group">
                        <p class="nav-group-label"><?= e(strtoupper($groupName)) ?></p>
                        <?php foreach ($items as $key => $item): ?>
                            <?php
                            $targetPage = $item['page'] ?? 'dashboard';
                            $href = $targetPage === 'dashboard'
                                ? '/?page=dashboard&section=' . rawurlencode($key)
                                : '/?page=' . rawurlencode($targetPage);
                            ?>
                            <a
                                class="nav-link <?= $activeSection === $key ? 'is-active' : '' ?>"
                                href="<?= e($href) ?>"
                                <?= $activeSection === $key ? 'aria-current="page"' : '' ?>
                            >
                                <span class="nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span>
                                <span><?= e($item['label']) ?></span>
                                <?php if ($key === 'users' && $pendingUsers > 0): ?>
                                    <span class="nav-count"><?= $pendingUsers ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-help"><span aria-hidden="true">?</span><span><strong>Pusat bantuan</strong><small>Panduan penggunaan EWS</small></span></div>
                <div class="sidebar-account">
                    <span class="account-avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
                    <span class="account-copy"><strong><?= e($user['name']) ?></strong><small><?= e($roles[$user['role']] ?? $user['role']) ?></small></span>
                    <form method="post" action="/?page=logout">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button class="account-logout" type="submit" aria-label="Keluar" title="Keluar">↗</button>
                    </form>
                </div>
            </div>
        </aside>
        <div class="app-main">
            <header class="app-topbar">
                <button class="sidebar-toggle" type="button" aria-label="Buka menu" aria-controls="app-sidebar" aria-expanded="false" data-sidebar-toggle>☰</button>
                <div class="breadcrumb"><span>Early Warning System</span><span aria-hidden="true">/</span><strong><?= e($pageTitle) ?></strong></div>
                <div class="topbar-actions">
                    <span class="system-status"><span class="status-dot"></span> Akun aktif</span>
                    <span class="topbar-divider"></span>
                    <span class="topbar-date"><?= e(date('d M Y')) ?></span>
                    <span class="topbar-avatar" aria-label="<?= e($user['name']) ?>"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span>
                </div>
            </header>
            <main class="app-content">
                <div class="page-heading">
                    <div>
                        <p class="eyebrow"><?= e($pageEyebrow) ?></p>
                        <h1><?= e($pageTitle) ?></h1>
                    </div>
                    <?php if ($activeSection === 'overview'): ?>
                        <div class="update-label"><span class="update-ring" aria-hidden="true">↻</span><span>Ringkasan sistem</span></div>
                    <?php endif; ?>
                </div>
    <?php
}

function render_app_shell_end(): void
{
    ?>
            </main>
        </div>
    </div>
    <div class="sidebar-backdrop" data-sidebar-backdrop></div>
    </body>
    </html>
    <?php
}

function render_dashboard(array $user, string $section = 'overview'): void
{
    $sections = dashboard_sections($user);
    if (!isset($sections[$section])) {
        http_response_code(403);
        render_access_denied();
        return;
    }
    if ($section === 'users') {
        redirect_to('/?page=admin');
    }

    $metadata = [
        'overview' => ['Ringkasan', 'PANTAUAN TERPADU'],
        'alerts' => ['Kejadian aktif', 'MONITORING & PERINGATAN'],
        'history' => ['Riwayat peringatan', 'MONITORING & PERINGATAN'],
        'hazards' => ['Jenis bahaya', 'KONFIGURASI MASTER DATA'],
        'locations' => ['Wilayah & lokasi', 'KONFIGURASI MASTER DATA'],
        'sensors' => ['Sensor & sumber data', 'KONFIGURASI MASTER DATA'],
        'parameters' => ['Parameter operasional', 'KONFIGURASI MASTER DATA'],
        'thresholds' => ['Ambang & persetujuan', 'KONFIGURASI MASTER DATA'],
        'rules' => ['Aturan & eskalasi', 'KONFIGURASI MASTER DATA'],
        'recipients' => ['Penerima notifikasi', 'KONFIGURASI MASTER DATA'],
        'integrations' => ['Integrasi data', 'OPERASIONAL SISTEM'],
        'reports' => ['Laporan & ekspor', 'OPERASIONAL SISTEM'],
        'health' => ['Kesehatan sistem', 'OPERASIONAL SISTEM'],
        'audit' => ['Audit aktivitas', 'TATA KELOLA & AUDIT'],
    ];
    [$title, $eyebrow] = $metadata[$section];
    render_app_shell_start($user, $section, $title, $eyebrow);

    if ($section === 'overview') {
        render_dashboard_overview($user);
    } else {
        render_dashboard_placeholder($section, $title);
    }
    render_app_shell_end();
}

function render_dashboard_overview(array $user): void
{
    $regions = user_regions($user);
    $regionCount = count($regions);
    $userCount = null;
    $pendingCount = null;
    if ($user['role'] === 'system_admin') {
        $userCount = (int) db()->query(
            "SELECT COUNT(*) FROM users WHERE status = 'active'"
        )->fetchColumn();
        $pendingCount = (int) db()->query(
            "SELECT COUNT(*) FROM users WHERE status = 'pending'"
        )->fetchColumn();
    }
    ?>
    <div class="dashboard-welcome">
        <div>
            <h2>Selamat datang, <?= e($user['name']) ?></h2>
            <p>Ringkasan kondisi Early Warning System dan konfigurasi pemantauan Anda.</p>
        </div>
        <span class="scope-chip"><span class="scope-chip-dot"></span><?= $user['role'] === 'system_admin' ? 'Seluruh wilayah' : count($regions) . ' wilayah dalam cakupan' ?></span>
    </div>
    <div class="metric-grid">
        <article class="metric-card">
            <div class="metric-top"><span>Lokasi dipantau</span><span class="metric-icon mint">⌖</span></div>
            <strong class="metric-value metric-unavailable">—</strong>
            <p class="metric-foot">Master lokasi belum tersedia</p>
        </article>
        <article class="metric-card">
            <div class="metric-top"><span>Peringatan aktif</span><span class="metric-icon amber">⌁</span></div>
            <strong class="metric-value metric-unavailable">—</strong>
            <p class="metric-foot">Belum ada data kejadian</p>
        </article>
        <article class="metric-card">
            <div class="metric-top"><span>Sumber data sehat</span><span class="metric-icon blue">◉</span></div>
            <strong class="metric-value metric-unavailable">—</strong>
            <p class="metric-foot">Sensor/feed belum terhubung</p>
        </article>
        <article class="metric-card">
            <div class="metric-top"><span>Wilayah cakupan</span><span class="metric-icon violet">▦</span></div>
            <strong class="metric-value"><?= number_format($regionCount, 0, ',', '.') ?></strong>
            <p class="metric-foot"><?= $user['role'] === 'system_admin' ? 'Hierarki master wilayah' : 'Termasuk wilayah turunan' ?></p>
        </article>
    </div>
    <div class="dashboard-grid">
        <section class="panel map-panel">
            <div class="panel-heading">
                <div><h2>Peta pemantauan</h2><p>Distribusi kondisi menurut lokasi</p></div>
                <span class="panel-tag"><span class="status-dot muted-dot"></span>Belum terhubung</span>
            </div>
            <div class="map-canvas" role="img" aria-label="Peta pemantauan belum tersedia karena lokasi dan data peta belum dikonfigurasi">
                <div class="map-lines map-lines-one"></div><div class="map-lines map-lines-two"></div>
                <div class="map-empty">
                    <span class="map-empty-icon" aria-hidden="true">⌖</span>
                    <strong>Peta akan tampil di sini</strong>
                    <p>Tambahkan lokasi pantau beserta koordinat untuk memulai visualisasi.</p>
                    <?php if ($user['role'] === 'system_admin' || user_has_permission($user, 'manage_master_data')): ?>
                        <a href="/?page=dashboard&section=locations">Buka wilayah & lokasi <span aria-hidden="true">→</span></a>
                    <?php endif; ?>
                </div>
                <span class="map-attribution">Peta tersedia setelah sumber peta dikonfigurasi</span>
            </div>
            <div class="map-legend">
                <span><i class="legend-dot normal"></i>Normal</span>
                <span><i class="legend-dot watch"></i>Waspada</span>
                <span><i class="legend-dot alert"></i>Siaga / Awas</span>
                <span><i class="legend-dot offline"></i>Data terputus</span>
            </div>
        </section>
        <section class="panel incident-panel">
            <div class="panel-heading">
                <div><h2>Kejadian terbaru</h2><p>Peringatan dan status penanganan</p></div>
                <a class="panel-link" href="/?page=dashboard&section=alerts">Lihat semua <span aria-hidden="true">→</span></a>
            </div>
            <div class="incident-empty">
                <span class="incident-empty-mark" aria-hidden="true">✓</span>
                <strong>Belum ada kejadian</strong>
                <p>Kejadian akan muncul setelah sumber data dan aturan peringatan dikonfigurasi.</p>
            </div>
            <div class="source-status">
                <div class="source-status-heading"><strong>Status sumber data</strong><a href="/?page=dashboard&section=sensors">Lihat sumber</a></div>
                <div class="source-row"><span><i class="source-indicator unavailable"></i>Sensor & feed</span><strong>Belum dikonfigurasi</strong></div>
                <div class="source-row"><span><i class="source-indicator unavailable"></i>Pembaruan terakhir</span><strong>Belum tersedia</strong></div>
            </div>
        </section>
    </div>
    <section class="panel setup-panel">
        <div class="setup-copy">
            <span class="setup-icon" aria-hidden="true">✦</span>
            <div><strong>Mulai siapkan pemantauan EWS</strong><p>Lengkapi konfigurasi master sebelum sistem dapat memetakan risiko dan membuat peringatan.</p></div>
        </div>
        <div class="setup-steps">
            <a href="/?page=dashboard&section=hazards"><span>01</span> Jenis bahaya <b>→</b></a>
            <a href="/?page=dashboard&section=locations"><span>02</span> Wilayah & lokasi <b>→</b></a>
            <a href="/?page=dashboard&section=sensors"><span>03</span> Sumber data <b>→</b></a>
            <a href="/?page=dashboard&section=thresholds"><span>04</span> Ambang & aturan <b>→</b></a>
        </div>
    </section>
    <?php if ($user['role'] === 'system_admin'): ?>
        <div class="dashboard-admin-summary">
            <span>Akun aktif <strong><?= number_format($userCount, 0, ',', '.') ?></strong></span>
            <span>Menunggu persetujuan <strong><?= number_format($pendingCount, 0, ',', '.') ?></strong></span>
            <a href="/?page=admin">Kelola akses pengguna <span aria-hidden="true">→</span></a>
        </div>
    <?php endif; ?>
    <?php
}

function render_dashboard_placeholder(string $section, string $title): void
{
    $descriptions = [
        'alerts' => 'Daftar kejadian aktif, prioritas, indikator pemicu, umur data, dan status penanganan.',
        'history' => 'Riwayat peringatan dapat ditelusuri dan difilter setelah data kejadian tersedia.',
        'hazards' => 'Konfigurasi empat jenis bahaya: cuaca, tornado, banjir sungai, dan pasang surut pantai/muara.',
        'locations' => 'Kelola hierarki wilayah serta lokasi pantau, koordinat, geometri, zona waktu, dan cakupan bahaya.',
        'sensors' => 'Daftarkan sensor atau feed, parameter, lokasi, metode koneksi, heartbeat, dan kesehatan sumber.',
        'parameters' => 'Kelola parameter, satuan, tipe nilai, rentang valid, dan frekuensi pengukuran.',
        'thresholds' => 'Atur ambang, persistensi, histeresis, masa berlaku, versi konfigurasi, dan persetujuan.',
        'rules' => 'Rancang kombinasi indikator, tingkat peringatan, penerima, pengulangan, dan aturan eskalasi.',
        'recipients' => 'Konfigurasikan kelompok penerima, kanal notifikasi, jam aktif, serta urutan eskalasi.',
        'integrations' => 'Pantau integrasi API, MQTT, impor terkontrol, validasi, dan waktu penerimaan data.',
        'reports' => 'Cari, filter, dan ekspor rekap peringatan setelah data operasional tersedia.',
        'health' => 'Ringkasan status koneksi, data stale, pembaruan terakhir, dan kesehatan layanan.',
        'audit' => 'Telusuri perubahan konfigurasi serta tindakan pengguna menurut pelaku dan waktu.',
    ];
    ?>
    <div class="module-intro">
        <div class="module-intro-mark" aria-hidden="true"><?= e(match ($section) {
            'alerts' => '⌁',
            'history' => '◷',
            'hazards' => '◇',
            'locations' => '⌖',
            'sensors' => '◉',
            'parameters' => '≋',
            'thresholds' => '⌗',
            'rules' => '⇧',
            'recipients' => '◎',
            'integrations' => '↔',
            'reports' => '▤',
            'health' => '+',
            default => '≡',
        }) ?></div>
        <div><h2><?= e($title) ?></h2><p><?= e($descriptions[$section] ?? '') ?></p></div>
    </div>
    <section class="panel module-status-panel">
        <div class="module-status-heading"><span class="status-dot muted-dot"></span><div><strong>Modul belum terhubung ke data operasional</strong><p>Menu dan alur modul sudah disiapkan sesuai PRD. Pengelolaan data dan integrasi akan dibangun pada tahap berikutnya.</p></div></div>
        <a class="panel-link" href="/?page=dashboard">Kembali ke ringkasan <span aria-hidden="true">→</span></a>
    </section>
    <?php
}

function render_access_denied(): void
{
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Akses tidak tersedia · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
    </head>
    <body class="dashboard-body">
    <main class="access-denied">
        <p class="eyebrow">AKSES PORTAL EWS</p>
        <h1>Akses tidak tersedia</h1>
        <p>Akun Anda belum aktif atau tidak memiliki izin untuk membuka halaman ini. Hubungi administrator sistem jika Anda memerlukan akses.</p>
        <a class="admin-link" href="/?page=login">Kembali ke halaman masuk</a>
    </main>
    </body>
    </html>
    <?php
}

function render_admin_page(
    array $admin,
    array $users,
    array $regions,
    ?string $message,
    ?string $error
): void {
    $roles = role_labels();
    $statuses = status_labels();
    $csrf = e(csrf_token());
    $regionDepths = [];
    $regionsById = [];
    foreach ($regions as $region) {
        $regionsById[(int) $region['id']] = $region;
    }
    $getDepth = static function (array $region) use (&$getDepth, $regionsById): int {
        $parentId = $region['parent_id'];
        if ($parentId === null || !isset($regionsById[(int) $parentId])) {
            return 0;
        }
        return 1 + $getDepth($regionsById[(int) $parentId]);
    };
    foreach ($regions as $region) {
        $regionDepths[(int) $region['id']] = $getDepth($region);
    }
    render_app_shell_start($admin, 'users', 'Pengguna & akses', 'TATA KELOLA & ADMINISTRASI');
    ?>
    <div class="admin-content">
        <h2 class="admin-page-title">Pengguna dan cakupan wilayah</h2>
        <p class="dashboard-message">Setujui akun, tetapkan peran, dan batasi akses berdasarkan wilayah. Penetapan wilayah mencakup semua wilayah turunannya.</p>
        <?php if ($message !== null): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="admin-error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="admin-section">
            <div class="section-heading">
                <div><p class="eyebrow">AKUN</p><h2>Daftar pengguna <span><?= count($users) ?></span></h2></div>
            </div>
            <?php if ($users === []): ?>
                <p class="empty-state">Belum ada akun pengguna.</p>
            <?php else: ?>
                <div class="user-list">
                    <?php foreach ($users as $managedUser): ?>
                        <form class="user-card" method="post" action="/?page=admin">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="action" value="update_user">
                            <input type="hidden" name="user_id" value="<?= (int) $managedUser['id'] ?>">
                            <div class="user-identity">
                                <div class="avatar"><?= e(strtoupper(substr($managedUser['name'], 0, 1))) ?></div>
                                <div>
                                    <strong><?= e($managedUser['name']) ?></strong>
                                    <p><?= e($managedUser['email']) ?></p>
                                </div>
                                <span class="status-badge status-<?= e($managedUser['status']) ?>"><?= e($statuses[$managedUser['status']] ?? $managedUser['status']) ?></span>
                            </div>
                            <div class="user-access-fields">
                                <div>
                                    <label for="role-<?= (int) $managedUser['id'] ?>">Peran</label>
                                    <select id="role-<?= (int) $managedUser['id'] ?>" name="role">
                                        <?php foreach ($roles as $role => $label): ?>
                                            <option value="<?= e($role) ?>" <?= $managedUser['role'] === $role ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="status-<?= (int) $managedUser['id'] ?>">Status akun</label>
                                    <select id="status-<?= (int) $managedUser['id'] ?>" name="status">
                                        <?php foreach ($statuses as $status => $label): ?>
                                            <option value="<?= e($status) ?>" <?= $managedUser['status'] === $status ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <fieldset class="region-picker">
                                <legend>Wilayah yang ditetapkan</legend>
                                <?php if ($regions === []): ?>
                                    <p class="scope-hint">Tambahkan wilayah di bagian bawah sebelum menetapkan cakupan.</p>
                                <?php else: ?>
                                    <div class="region-options">
                                        <?php foreach ($regions as $region): ?>
                                            <label class="region-option">
                                                <input type="checkbox" name="region_ids[]" value="<?= (int) $region['id'] ?>" <?= in_array((int) $region['id'], $managedUser['region_ids'], true) ? 'checked' : '' ?>>
                                                <span><?= str_repeat('— ', $regionDepths[(int) $region['id']]) ?><?= e($region['name']) ?> <small><?= e($region['code']) ?></small></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <p class="scope-hint">Memilih wilayah induk otomatis memberi cakupan ke seluruh turunannya.</p>
                            </fieldset>
                            <div class="user-save-row">
                                <label class="reason-field">Alasan perubahan
                                    <input type="text" name="reason" maxlength="500" placeholder="Contoh: persetujuan akses wilayah pilot" required>
                                </label>
                                <button class="save-button" type="submit">Simpan akses</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-section region-admin">
            <div class="section-heading">
                <div><p class="eyebrow">STRUKTUR WILAYAH</p><h2>Wilayah <span><?= count($regions) ?></span></h2></div>
            </div>
            <form class="region-form" method="post" action="/?page=admin">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_region">
                <div><label for="region-code">Kode</label><input id="region-code" name="code" maxlength="32" placeholder="Contoh: JBR" required></div>
                <div><label for="region-name">Nama wilayah</label><input id="region-name" name="name" maxlength="120" placeholder="Contoh: Jawa Barat" required></div>
                <div>
                    <label for="parent-id">Wilayah induk</label>
                    <select id="parent-id" name="parent_id">
                        <option value="">Tanpa induk</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= (int) $region['id'] ?>"><?= str_repeat('— ', $regionDepths[(int) $region['id']]) ?><?= e($region['name']) ?> (<?= e($region['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label for="region-reason">Alasan penambahan</label><input id="region-reason" name="reason" maxlength="500" placeholder="Dasar pembentukan wilayah" required></div>
                <button class="save-button" type="submit">Tambah wilayah</button>
            </form>
            <?php if ($regions !== []): ?>
                <ul class="region-list">
                    <?php foreach ($regions as $region): ?>
                        <li>
                            <span><?= str_repeat('— ', $regionDepths[(int) $region['id']]) ?><?= e($region['name']) ?></span>
                            <small><?= e($region['code']) ?><?= $region['parent_id'] !== null && isset($regionsById[(int) $region['parent_id']]) ? ' · Induk: ' . e($regionsById[(int) $region['parent_id']]['name']) : '' ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <p class="scope-hint">Perubahan peran, status, dan cakupan wilayah dicatat pada audit akses.</p>
    </div>
    <?php render_app_shell_end(); ?>
    <?php
}
