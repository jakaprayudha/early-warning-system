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
                            $href = in_array($key, ['alerts', 'history'], true)
                                ? '/?page=' . rawurlencode($key)
                                : ($targetPage === 'dashboard'
                                    ? '/?page=dashboard&section=' . rawurlencode($key)
                                    : '/?page=' . rawurlencode($targetPage));
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
    if ($section === 'alerts' || $section === 'history') {
        redirect_to('/?page=' . $section);
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
    } elseif ($section === 'hazards') {
        render_hazard_types_page(
            list_hazard_types(),
            flash('message'),
            flash('error')
        );
    } elseif ($section === 'sensors') {
        render_sensors_page($user, flash('message'), flash('error'));
    } elseif ($section === 'locations') {
        render_locations_page($user, flash('message'), flash('error'));
    } else {
        render_dashboard_placeholder($section, $title);
    }
    render_app_shell_end();
}

function render_dashboard_overview(array $user): void
{
    $regions = user_regions($user);
    $regionCount = count($regions);
    $openAlertCount = count_alert_events($user, 'open');
    $activeEvents = array_slice(list_alert_events($user, [], true), 0, 3);
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
            <strong class="metric-value"><?= number_format($openAlertCount, 0, ',', '.') ?></strong>
            <p class="metric-foot">Dalam cakupan wilayah Anda</p>
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
                <a class="panel-link" href="/?page=alerts">Lihat semua <span aria-hidden="true">→</span></a>
            </div>
            <?php if ($activeEvents === []): ?>
                <div class="incident-empty">
                    <span class="incident-empty-mark" aria-hidden="true">✓</span>
                    <strong>Belum ada kejadian</strong>
                    <p>Kejadian akan muncul setelah sumber data dan aturan peringatan dikonfigurasi atau dicatat petugas.</p>
                </div>
            <?php else: ?>
                <div class="dashboard-event-list">
                    <?php foreach ($activeEvents as $event): ?>
                        <a class="dashboard-event-row" href="/?page=alerts">
                            <span class="dashboard-event-severity severity-<?= e($event['severity']) ?>"><?= e(alert_severities()[$event['severity']]) ?></span>
                            <span class="dashboard-event-copy"><strong><?= e($event['location_name']) ?></strong><small><?= e(alert_hazards()[$event['hazard_type']]) ?> · <?= e($event['region_name']) ?></small></span>
                            <time><?= e(date('H:i', (int) $event['started_at'])) ?></time>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="source-status">
                <div class="source-status-heading"><strong>Status sumber data</strong><a href="/?page=dashboard&section=sensors">Lihat sumber</a></div>
                <div class="source-row"><span><i class="source-indicator unavailable"></i>Sensor & feed</span><strong>Belum dikonfigurasi</strong></div>
                <div class="source-row"><span><i class="source-indicator unavailable"></i>Pembaruan terakhir</span><strong>Belum tersedia</strong></div>
            </div>
        </section>
    </div>
    <section class="panel setup-panel">
        <?php if ($user['role'] === 'system_admin' || user_has_permission($user, 'manage_master_data')): ?>
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
        <?php else: ?>
            <div class="setup-copy">
                <span class="setup-icon" aria-hidden="true">⌖</span>
                <div><strong>Cakupan pemantauan Anda</strong><p><?= $regions === [] ? 'Belum ada wilayah yang ditetapkan; hubungi administrator untuk mendapatkan akses data.' : 'Ringkasan hanya mencakup wilayah yang ditetapkan beserta seluruh wilayah turunannya.' ?></p></div>
            </div>
            <a class="panel-link" href="/?page=history">Buka riwayat peringatan <span aria-hidden="true">→</span></a>
        <?php endif; ?>
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

function render_hazard_types_page(array $hazardTypes, ?string $message, ?string $error): void
{
    ?>
    <div class="hazard-admin">
        <p class="dashboard-message">Kelola kode, informasi, tampilan, satuan, serta status jenis bahaya. Perubahan dicatat dalam audit konfigurasi.</p>
        <?php if ($message !== null): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
        <?php if ($error !== null): ?><div class="admin-error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section class="panel hazard-create-panel">
            <div class="panel-heading"><div><h2>Tambah jenis bahaya</h2><p>Kode bersifat tetap setelah jenis dibuat.</p></div></div>
            <form class="hazard-form" method="post" action="/?page=dashboard&amp;section=hazards">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <label>Kode unik<input name="code" maxlength="32" pattern="[A-Za-z][A-Za-z0-9_\x2D]{1,31}" placeholder="contoh: longsor" required></label>
                <label>Nama<input name="name" maxlength="100" placeholder="Nama jenis bahaya" required></label>
                <label>Ikon<input name="icon" maxlength="8" placeholder="◇" required></label>
                <label>Warna<input name="color" type="color" value="#27856e" required></label>
                <label>Satuan default<input name="default_unit" maxlength="24" placeholder="mm/jam"></label>
                <label class="hazard-description">Deskripsi<textarea name="description" maxlength="500" rows="2" placeholder="Ringkasan jenis bahaya"></textarea></label>
                <label class="hazard-active"><input type="checkbox" name="is_active" value="1" checked> Aktif</label>
                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Dasar penambahan jenis bahaya"></label>
                <button class="save-button" type="submit">Tambah jenis bahaya</button>
            </form>
        </section>

        <section class="hazard-types-section" aria-labelledby="hazard-types-title">
            <div class="section-heading">
                <div><p class="eyebrow">KATALOG</p><h2 id="hazard-types-title">Jenis bahaya <span><?= count($hazardTypes) ?></span></h2></div>
            </div>
            <?php if ($hazardTypes === []): ?>
                <div class="panel empty-alerts"><h2>Belum ada jenis bahaya</h2><p>Tambahkan jenis bahaya untuk menggunakannya pada pencatatan kejadian.</p></div>
            <?php else: ?>
                <div class="hazard-type-list">
                    <?php foreach ($hazardTypes as $hazard): ?>
                        <?php $code = (string) $hazard['code']; ?>
                        <article class="panel hazard-type-card">
                            <div class="hazard-type-heading">
                                <span class="hazard-icon" aria-hidden="true"><?= e($hazard['icon']) ?></span>
                                <div><h3><?= e($hazard['name']) ?></h3><p><code><?= e($code) ?></code> · <?= (int) $hazard['event_count'] ?> kejadian terkait</p></div>
                                <span class="status-badge <?= (int) $hazard['is_active'] === 1 ? 'status-active' : 'status-suspended' ?>"><?= (int) $hazard['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span>
                            </div>
                            <form id="hazard-update-<?= e($code) ?>" class="hazard-form" method="post" action="/?page=dashboard&amp;section=hazards">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="code" value="<?= e($code) ?>">
                                <label>Kode unik<input value="<?= e($code) ?>" disabled></label>
                                <label>Nama<input name="name" maxlength="100" value="<?= e($hazard['name']) ?>" required></label>
                                <label>Ikon<input name="icon" maxlength="8" value="<?= e($hazard['icon']) ?>" required></label>
                                <label>Warna<input name="color" type="color" value="<?= e($hazard['color']) ?>" required></label>
                                <label>Satuan default<input name="default_unit" maxlength="24" value="<?= e($hazard['default_unit']) ?>"></label>
                                <label class="hazard-description">Deskripsi<textarea name="description" maxlength="500" rows="2"><?= e($hazard['description']) ?></textarea></label>
                                <label class="hazard-active"><input type="checkbox" name="is_active" value="1" <?= (int) $hazard['is_active'] === 1 ? 'checked' : '' ?>> Aktif</label>
                                <label class="hazard-reason">Alasan perubahan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                            </form>
                            <div class="hazard-form-actions">
                                <button class="save-button" type="submit" form="hazard-update-<?= e($code) ?>">Simpan perubahan</button>
                                <?php if ((int) $hazard['event_count'] === 0): ?>
                                    <form class="hazard-delete-form" method="post" action="/?page=dashboard&amp;section=hazards">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="code" value="<?= e($code) ?>">
                                        <label>Alasan penghapusan<input name="reason" maxlength="500" required placeholder="Wajib diisi untuk audit"></label>
                                        <button class="hazard-delete-button" type="submit">Hapus jenis bahaya</button>
                                    </form>
                                <?php else: ?>
                                    <p class="hazard-delete-hint">Tidak dapat dihapus karena sudah memiliki kejadian. Nonaktifkan untuk menghentikan penggunaan baru.</p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <p class="scope-hint">Jenis nonaktif tetap tampil pada riwayat lama tetapi tidak tersedia untuk kejadian baru. Penghapusan hanya diizinkan jika belum pernah digunakan.</p>
    </div>
    <?php
}

function alert_filter_value(array $filters, string $key): string
{
    $value = $filters[$key] ?? '';
    return is_string($value) ? $value : '';
}

function render_alert_filters(array $regions, array $filters, bool $history): void
{
    $hazards = alert_hazards();
    $severities = alert_severities();
    ?>
    <form class="alert-filter-form" method="get" action="/">
        <input type="hidden" name="page" value="<?= $history ? 'history' : 'alerts' ?>">
        <label class="filter-search">Cari
            <input type="search" name="q" value="<?= e(alert_filter_value($filters, 'q')) ?>" placeholder="Lokasi, indikator, sumber">
        </label>
        <label>Jenis bahaya
            <select name="hazard">
                <option value="">Semua bahaya</option>
                <?php foreach ($hazards as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= alert_filter_value($filters, 'hazard') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Tingkat
            <select name="severity">
                <option value="">Semua tingkat</option>
                <?php foreach ($severities as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= alert_filter_value($filters, 'severity') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($history): ?>
            <label>Status
                <select name="status">
                    <option value="">Semua status</option>
                    <option value="open" <?= alert_filter_value($filters, 'status') === 'open' ? 'selected' : '' ?>>Aktif</option>
                    <option value="closed" <?= alert_filter_value($filters, 'status') === 'closed' ? 'selected' : '' ?>>Selesai</option>
                </select>
            </label>
        <?php endif; ?>
        <label>Wilayah
            <select name="region_id">
                <option value="">Semua wilayah</option>
                <?php foreach ($regions as $region): ?>
                    <option value="<?= (int) $region['id'] ?>" <?= alert_filter_value($filters, 'region_id') === (string) $region['id'] ? 'selected' : '' ?>><?= e($region['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Dari tanggal
            <input type="date" name="from" value="<?= e(alert_filter_value($filters, 'from')) ?>">
        </label>
        <label>Sampai tanggal
            <input type="date" name="to" value="<?= e(alert_filter_value($filters, 'to')) ?>">
        </label>
        <button class="filter-submit" type="submit">Terapkan filter</button>
        <a class="filter-reset" href="/?page=<?= $history ? 'history' : 'alerts' ?>">Reset</a>
    </form>
    <?php
}

function render_alert_event_card(array $event, array $user, bool $history = false): void
{
    $hazard = alert_hazards()[$event['hazard_type']] ?? $event['hazard_type'];
    $severity = alert_severities()[$event['severity']] ?? $event['severity'];
    $canOperate = !$history
        && in_array($user['role'], ['system_admin', 'operator'], true)
        && $event['handling_status'] === 'open';
    $timeline = alert_event_timeline((int) $event['id']);
    $assignees = $canOperate
        ? alert_event_assignees((int) $event['region_id'])
        : [];
    ?>
    <article class="alert-card">
        <div class="alert-card-main">
            <div class="alert-card-heading">
                <span class="severity-pill severity-<?= e($event['severity']) ?>"><?= e($severity) ?></span>
                <span class="hazard-label"><?= e($hazard) ?></span>
                <span class="alert-id">EWS-<?= str_pad((string) $event['id'], 5, '0', STR_PAD_LEFT) ?></span>
                <time datetime="<?= e(gmdate('c', (int) $event['started_at'])) ?>"><?= e(date('d M Y · H:i', (int) $event['started_at'])) ?></time>
            </div>
            <h2><?= e($event['location_name']) ?></h2>
            <p class="alert-region"><?= e($event['region_name']) ?> <span>·</span> <?= e($event['region_code']) ?></p>
            <div class="trigger-summary">
                <span><small>Indikator pemicu</small><strong><?= e($event['trigger_indicator']) ?></strong></span>
                <span><small>Nilai terukur</small><strong><?= e($event['trigger_value']) ?><?= $event['threshold_value'] !== '' ? ' <i>· Ambang ' . e($event['threshold_value']) . '</i>' : '' ?></strong></span>
                <span><small>Sumber</small><strong><?= $event['source_label'] !== '' ? e($event['source_label']) : 'Laporan manual' ?></strong></span>
            </div>
            <div class="handling-summary">
                <?php if ($event['acknowledged_at'] !== null): ?>
                    <span class="handling-chip acknowledged">✓ Diakui <?= e($event['acknowledger_name'] ?? '') ?></span>
                <?php else: ?>
                    <span class="handling-chip unacknowledged">Belum diakui</span>
                <?php endif; ?>
                <span class="handling-chip">Petugas: <?= e($event['assignee_name'] ?? 'Belum ditetapkan') ?></span>
                <?php if ($event['handling_status'] === 'closed'): ?>
                    <span class="handling-chip closed-chip">Selesai <?= e(date('d M Y · H:i', (int) $event['closed_at'])) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($event['handling_status'] === 'closed' && $event['close_reason'] !== ''): ?>
                <p class="close-reason"><strong>Alasan penutupan:</strong> <?= e($event['close_reason']) ?></p>
            <?php endif; ?>
        </div>
        <details class="event-details">
            <summary>Riwayat tindakan <span><?= count($timeline) ?></span></summary>
            <div class="event-timeline">
                <?php foreach ($timeline as $entry): ?>
                    <div class="timeline-entry">
                        <span class="timeline-dot"></span>
                        <div><strong><?= e(alert_action_label($entry['action'])) ?> <small>oleh <?= e($entry['actor_name'] ?? 'Sistem') ?></small></strong><p><?= e($entry['details']) ?></p><time><?= e(date('d M Y · H:i', (int) $entry['created_at'])) ?></time></div>
                    </div>
                <?php endforeach; ?>
                <?php if ($timeline === []): ?><p class="scope-hint">Belum ada tindakan tercatat.</p><?php endif; ?>
            </div>
        </details>
        <?php if ($canOperate): ?>
            <div class="alert-actions">
                <?php if ($event['acknowledged_at'] === null): ?>
                    <form method="post" action="/?page=alerts">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="acknowledge">
                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                        <button class="action-button primary-action" type="submit">Akui kejadian</button>
                    </form>
                <?php endif; ?>
                <?php if ($assignees !== []): ?>
                    <form method="post" action="/?page=alerts" class="assign-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                        <label class="visually-hidden" for="assignee-<?= (int) $event['id'] ?>">Petugas penanggung jawab</label>
                        <select id="assignee-<?= (int) $event['id'] ?>" name="assignee_id" required>
                            <option value="">Tetapkan petugas…</option>
                            <?php foreach ($assignees as $assignee): ?>
                                <option value="<?= (int) $assignee['id'] ?>" <?= (int) $event['assigned_to'] === (int) $assignee['id'] ? 'selected' : '' ?>><?= e($assignee['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="action-button" type="submit">Tetapkan</button>
                    </form>
                <?php endif; ?>
                <?php if ($event['severity'] !== 'warning'): ?>
                    <form method="post" action="/?page=alerts">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="escalate">
                        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                        <button class="action-button" type="submit">Eskalasi tingkat</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="/?page=alerts" class="note-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="note">
                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                    <label class="visually-hidden" for="note-<?= (int) $event['id'] ?>">Catatan tindakan</label>
                    <input id="note-<?= (int) $event['id'] ?>" name="note" maxlength="1000" placeholder="Tambah catatan tindakan…" required>
                    <button class="action-button" type="submit">Simpan catatan</button>
                </form>
                <form method="post" action="/?page=alerts" class="close-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                    <label class="visually-hidden" for="reason-<?= (int) $event['id'] ?>">Alasan penutupan</label>
                    <input id="reason-<?= (int) $event['id'] ?>" name="reason" maxlength="500" placeholder="Alasan penutupan kejadian…" required>
                    <button class="action-button close-action" type="submit">Tutup kejadian</button>
                </form>
            </div>
        <?php elseif (!$history && $user['role'] === 'field_officer' && (int) $event['assigned_to'] === (int) $user['id'] && $event['handling_status'] === 'open'): ?>
            <form method="post" action="/?page=alerts" class="field-note-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="note">
                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                <label class="visually-hidden" for="field-note-<?= (int) $event['id'] ?>">Catatan progres lapangan</label>
                <input id="field-note-<?= (int) $event['id'] ?>" name="note" maxlength="1000" placeholder="Catat progres atau tindakan lapangan…" required>
                <button class="action-button primary-action" type="submit">Kirim progres</button>
            </form>
        <?php endif; ?>
    </article>
    <?php
}

function alert_action_label(string $action): string
{
    return [
        'created' => 'Kejadian dicatat',
        'acknowledge' => 'Kejadian diakui',
        'assign' => 'Penanggung jawab ditetapkan',
        'escalate' => 'Tingkat peringatan dieskalasi',
        'close' => 'Kejadian ditutup',
        'note' => 'Catatan tindakan',
    ][$action] ?? $action;
}

function csv_safe_value(mixed $value): string
{
    $text = (string) ($value ?? '');
    if ($text !== '' && preg_match('/^[\s]*[=+\-@]/u', $text)) {
        return "'" . $text;
    }

    return $text;
}

function render_alerts_page(
    array $user,
    array $events,
    array $regions,
    ?string $message = null,
    ?string $error = null,
    array $filters = []
): void {
    $canCreate = in_array($user['role'], ['system_admin', 'operator'], true);
    $hazards = alert_hazards(true);
    $severities = alert_severities();
    $openCount = count_alert_events($user, 'open');
    $watchCount = count_alert_events($user, 'open', 'watch');
    $alertCount = count_alert_events($user, 'open', 'alert');
    $warningCount = count_alert_events($user, 'open', 'warning');
    render_app_shell_start($user, 'alerts', 'Kejadian aktif', 'MONITORING & PERINGATAN');
    ?>
    <?php if ($message !== null): ?><div class="notice" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== null): ?><div class="admin-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="alert-summary">
        <div><span>Aktif dalam cakupan</span><strong><?= number_format($openCount, 0, ',', '.') ?></strong></div>
        <div><span>Waspada</span><strong class="summary-watch"><?= number_format($watchCount, 0, ',', '.') ?></strong></div>
        <div><span>Siaga</span><strong class="summary-alert"><?= number_format($alertCount, 0, ',', '.') ?></strong></div>
        <div><span>Awas</span><strong class="summary-warning"><?= number_format($warningCount, 0, ',', '.') ?></strong></div>
    </div>
    <div class="list-toolbar">
        <div><h2>Daftar kejadian</h2><p>Urut berdasarkan tingkat dan waktu mulai. Status bahaya terpisah dari status penanganan.</p></div>
        <?php if ($canCreate && $regions !== []): ?><a class="create-alert-link" href="#create-alert">+ Catat kejadian</a><?php endif; ?>
    </div>
    <?php render_alert_filters($regions, $filters, false); ?>
    <div class="result-count">Menampilkan <strong><?= count($events) ?></strong> kejadian aktif sesuai filter dan cakupan akses.</div>
    <?php if ($events === []): ?>
        <section class="panel empty-alerts">
            <span aria-hidden="true">✓</span><h2>Belum ada kejadian aktif</h2>
            <p>Kejadian yang masuk melalui sistem evaluasi atau dicatat operator akan tampil di sini.</p>
        </section>
    <?php else: ?>
        <div class="alert-list">
            <?php foreach ($events as $event): ?>
                <?php render_alert_event_card($event, $user); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($canCreate && $regions !== []): ?>
        <details class="panel create-alert-panel" id="create-alert" <?= $error !== null ? 'open' : '' ?>>
            <summary><span><strong>Catat kejadian secara manual</strong><small>Untuk laporan terverifikasi sebelum integrasi otomatis tersedia.</small></span><span aria-hidden="true">＋</span></summary>
            <form method="post" action="/?page=alerts" class="create-alert-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">
                <label>Jenis bahaya
                    <select name="hazard_type" required>
                        <?php foreach ($hazards as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Tingkat peringatan
                    <select name="severity" required>
                        <?php foreach ($severities as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Wilayah
                    <select name="region_id" required>
                        <option value="">Pilih wilayah</option>
                        <?php foreach ($regions as $region): ?><option value="<?= (int) $region['id'] ?>"><?= e($region['name']) ?> (<?= e($region['code']) ?>)</option><?php endforeach; ?>
                    </select>
                </label>
                <label>Lokasi / pos pantau<input name="location_name" maxlength="120" required placeholder="Nama lokasi kejadian"></label>
                <label>Indikator pemicu<input name="trigger_indicator" maxlength="160" required placeholder="Contoh: tinggi muka air"></label>
                <label>Nilai terukur<input name="trigger_value" maxlength="100" required placeholder="Nilai dan satuan"></label>
                <label>Nilai ambang (opsional)<input name="threshold_value" maxlength="100" placeholder="Nilai ambang aktif"></label>
                <label>Sumber laporan (opsional)<input name="source_label" maxlength="160" placeholder="Feed, sensor, atau pelapor terverifikasi"></label>
                <p class="create-alert-disclaimer">Catatan manual dibuat sebagai laporan awal, bukan hasil evaluasi sensor otomatis. Nilai ambang operasional tetap harus disahkan oleh pemilik domain.</p>
                <button class="filter-submit" type="submit">Simpan kejadian aktif</button>
            </form>
        </details>
    <?php elseif ($canCreate && $regions === []): ?>
        <div class="panel setup-required"><strong>Wilayah belum tersedia.</strong><p>Administrator perlu menyiapkan master wilayah sebelum kejadian dapat dicatat.</p></div>
    <?php endif; ?>
    <?php render_app_shell_end(); ?>
    <?php
}

function render_history_page(
    array $user,
    array $events,
    array $regions,
    array $filters = []
): void {
    $closedCount = count_alert_events($user, 'closed');
    $openCount = count_alert_events($user, 'open');
    render_app_shell_start($user, 'history', 'Riwayat peringatan', 'MONITORING & PERINGATAN');
    ?>
    <div class="history-summary">
        <div><span>Total catatan</span><strong><?= count($events) ?></strong></div>
        <div><span>Kejadian selesai</span><strong><?= number_format($closedCount, 0, ',', '.') ?></strong></div>
        <div><span>Masih aktif</span><strong><?= number_format($openCount, 0, ',', '.') ?></strong></div>
        <?php
        $exportQuery = $_GET;
        $exportQuery['page'] = 'history';
        $exportQuery['export'] = 'csv';
        ?>
        <a class="export-link" href="/?<?= e(http_build_query($exportQuery)) ?>">Unduh CSV <span aria-hidden="true">↓</span></a>
    </div>
    <div class="list-toolbar">
        <div><h2>Riwayat kejadian</h2><p>Riwayat mencatat pemicu, perubahan tingkat, pengakuan, penugasan, catatan, dan alasan penutupan.</p></div>
    </div>
    <?php render_alert_filters($regions, $filters, true); ?>
    <div class="result-count">Menampilkan <strong><?= count($events) ?></strong> kejadian yang cocok dengan filter dan cakupan akses.</div>
    <?php if ($events === []): ?>
        <section class="panel empty-alerts">
            <span aria-hidden="true">◷</span><h2>Belum ada riwayat peringatan</h2>
            <p>Kejadian aktif dan selesai yang berada dalam cakupan wilayah Anda akan tersimpan di sini.</p>
        </section>
    <?php else: ?>
        <div class="alert-list">
            <?php foreach ($events as $event): ?>
                <?php render_alert_event_card($event, $user, true); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php render_app_shell_end(); ?>
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
