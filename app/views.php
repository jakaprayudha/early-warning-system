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

function render_dashboard(array $user): void
{
    $regions = user_regions($user);
    $roles = role_labels();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Beranda · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
    </head>
    <body class="dashboard-body">
    <header class="dashboard-header">
        <a class="brand dashboard-brand" href="/?page=dashboard">
            <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            <span>EARLY WARNING <strong>SYSTEM</strong></span>
        </a>
        <form method="post" action="/?page=logout">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="logout-button" type="submit">Keluar</button>
        </form>
    </header>
    <main class="dashboard-content">
        <p class="eyebrow">PORTAL OPERASIONAL EWS</p>
        <h1>Selamat datang, <?= e($user['name']) ?></h1>
        <p class="dashboard-message">Akun Anda berhasil masuk ke portal Early Warning System.</p>
        <div class="dashboard-card">
            <span class="live-dot"></span>
            <div>
                <strong><?= e($roles[$user['role']] ?? $user['role']) ?></strong>
                <p><?= e($user['email']) ?></p>
            </div>
        </div>
        <section class="scope-section">
            <h2>Cakupan wilayah</h2>
            <?php if ($user['role'] === 'system_admin'): ?>
                <p class="dashboard-message">Administrator sistem memiliki cakupan ke seluruh wilayah.</p>
            <?php elseif ($regions === []): ?>
                <p class="dashboard-message">Belum ada wilayah yang ditetapkan. Hubungi administrator sistem untuk mendapatkan akses.</p>
            <?php else: ?>
                <ul class="scope-list">
                    <?php foreach ($regions as $region): ?>
                        <li><strong><?= e($region['name']) ?></strong> <span><?= e($region['code']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <p class="scope-hint">Cakupan yang ditetapkan juga berlaku untuk seluruh wilayah turunannya.</p>
            <?php endif; ?>
        </section>
        <?php if ($user['role'] === 'system_admin'): ?>
            <a class="admin-link" href="/?page=admin">Kelola akun dan wilayah <span aria-hidden="true">→</span></a>
        <?php endif; ?>
    </main>
    </body>
    </html>
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
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Kelola akses · EWS</title>
        <link rel="stylesheet" href="/assets/styles.css">
    </head>
    <body class="dashboard-body">
    <header class="dashboard-header">
        <a class="brand dashboard-brand" href="/?page=dashboard">
            <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
            <span>EARLY WARNING <strong>SYSTEM</strong></span>
        </a>
        <div class="header-actions">
            <a class="header-link" href="/?page=dashboard">Beranda</a>
            <form method="post" action="/?page=logout">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button class="logout-button" type="submit">Keluar</button>
            </form>
        </div>
    </header>
    <main class="admin-content">
        <p class="eyebrow">ADMINISTRASI SISTEM</p>
        <h1>Pengguna dan cakupan wilayah</h1>
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
    </main>
    </body>
    </html>
    <?php
}
