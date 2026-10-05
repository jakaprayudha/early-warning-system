<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/views.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$page = $_GET['page'] ?? 'login';
$allowedPages = [
    'login',
    'signup',
    'forgot-password',
    'reset-password',
    'dashboard',
    'admin',
    'logout',
];
if (!is_string($page) || !in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'login';
}
if ($page === 'logout' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('/?page=login');
}

$user = signed_in_user();
if ($user !== null && $user['status'] !== 'active'
    && in_array($page, ['dashboard', 'admin', 'login', 'signup'], true)) {
    session_unset();
    session_regenerate_id(true);
    $user = null;
}

if ($page === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        render_page('login', null, ['Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.']);
        exit;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, [
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    redirect_to('/?page=login');
}

if (in_array($page, ['dashboard', 'admin'], true) && $user === null) {
    redirect_to('/?page=login');
}
if ($page === 'admin' && !user_has_permission($user, 'manage_access')) {
    http_response_code(403);
    render_access_denied();
    exit;
}
if ($page === 'dashboard' && !user_has_permission($user, 'dashboard')) {
    http_response_code(403);
    render_access_denied();
    exit;
}
if ($page === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        render_admin_page(
            $user,
            all_users(),
            all_regions(),
            null,
            'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.'
        );
        exit;
    }

    $action = post_value('action');
    try {
        if ($action === 'create_region') {
            $code = strtoupper(trim(post_value('code')));
            $name = trim(post_value('name'));
            $parentRaw = post_value('parent_id');
            $reason = trim(post_value('reason'));
            $nameLength = preg_match_all('/./us', $name);
            if (!preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
                throw new InvalidArgumentException(
                    'Kode wilayah harus 2–32 karakter: huruf, angka, garis bawah, atau tanda hubung.'
                );
            }
            if ($nameLength === false || $nameLength < 1 || $nameLength > 120) {
                throw new InvalidArgumentException('Nama wilayah wajib diisi (maksimal 120 karakter).');
            }
            if ($reason === '') {
                throw new InvalidArgumentException('Alasan penambahan wilayah wajib diisi.');
            }
            $parentId = null;
            if ($parentRaw !== '') {
                $parentId = filter_var($parentRaw, FILTER_VALIDATE_INT);
                if ($parentId === false || $parentId < 1) {
                    throw new InvalidArgumentException('Wilayah induk tidak valid.');
                }
                $parentCheck = db()->prepare('SELECT 1 FROM regions WHERE id = :id');
                $parentCheck->execute(['id' => $parentId]);
                if (!$parentCheck->fetchColumn()) {
                    throw new InvalidArgumentException('Wilayah induk tidak ditemukan.');
                }
            }
            create_region((int) $user['id'], $code, $name, $parentId, $reason);
            flash('message', 'Wilayah berhasil ditambahkan.');
        } elseif ($action === 'update_user') {
            $targetId = filter_var(post_value('user_id'), FILTER_VALIDATE_INT);
            if ($targetId === false || $targetId < 1) {
                throw new InvalidArgumentException('Akun yang dipilih tidak valid.');
            }
            $regions = $_POST['region_ids'] ?? [];
            if (!is_array($regions)) {
                throw new InvalidArgumentException('Cakupan wilayah tidak valid.');
            }
            $regionIds = [];
            foreach ($regions as $region) {
                if (!is_string($region) || !ctype_digit($region)) {
                    throw new InvalidArgumentException('Cakupan wilayah tidak valid.');
                }
                $regionIds[] = (int) $region;
            }
            $reason = trim(post_value('reason'));
            if ($reason === '') {
                throw new InvalidArgumentException('Alasan perubahan akses wajib diisi.');
            }
            update_user_access(
                (int) $user['id'],
                $targetId,
                post_value('role'),
                post_value('status'),
                $regionIds,
                $reason
            );
            flash('message', 'Akses akun berhasil diperbarui.');
        } else {
            throw new InvalidArgumentException('Tindakan administrator tidak dikenal.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        if ($error->getCode() !== '23000') {
            throw $error;
        }
        flash('error', 'Kode wilayah atau alamat email tersebut sudah digunakan.');
    }
    redirect_to('/?page=admin');
}

if ($page === 'dashboard') {
    render_dashboard($user);
    exit;
}
if ($page === 'admin') {
    $adminMessage = flash('message');
    $adminError = flash('error');
    render_admin_page(
        $user,
        all_users(),
        all_regions(),
        $adminMessage,
        $adminError
    );
    exit;
}

if ($user !== null && in_array($page, ['login', 'signup'], true)) {
    redirect_to('/?page=dashboard');
}

$errors = [];
$old = [];
$message = flash('message');
$token = '';

if ($page === 'reset-password') {
    $candidate = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? post_value('token')
        : ($_GET['token'] ?? '');
    $token = is_string($candidate) && valid_reset_token($candidate) ? $candidate : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'logout') {
    if (!csrf_is_valid()) {
        $errors[] = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
        http_response_code(400);
    } else {
        if ($page === 'signup') {
            $name = trim(post_value('name'));
            $email = strtolower(trim(post_value('email')));
            $password = post_value('password');
            $confirmation = post_value('password_confirmation');
            $old = ['name' => $name, 'email' => $email];

            $nameLength = preg_match_all('/./us', $name);
            if ($nameLength === false || $nameLength < 1 || $nameLength > 100) {
                $errors[] = 'Nama wajib diisi dan maksimal 100 karakter.';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Masukkan alamat email yang valid.';
            }
            if (strlen($password) < 12) {
                $errors[] = 'Password harus terdiri dari minimal 12 karakter.';
            }
            if ($password !== $confirmation) {
                $errors[] = 'Konfirmasi password tidak sama.';
            }

            if ($errors === []) {
                $statement = db()->prepare(
                    'INSERT INTO users (name, email, password_hash, created_at, status)
                     VALUES (:name, :email, :password_hash, :created_at, :status)'
                );
                try {
                    $statement->execute([
                        'name' => $name,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'created_at' => time(),
                        'status' => 'pending',
                    ]);
                    flash(
                        'message',
                        'Pendaftaran berhasil. Akun menunggu persetujuan administrator sebelum dapat digunakan.'
                    );
                    redirect_to('/?page=login');
                } catch (PDOException $error) {
                    if ($error->getCode() !== '23000') {
                        throw $error;
                    }
                    $errors[] = 'Email tersebut sudah terdaftar. Silakan masuk.';
                }
            }
        } elseif ($page === 'login') {
            $email = strtolower(trim(post_value('email')));
            $password = post_value('password');
            $old = ['email' => $email];
            $statement = db()->prepare(
                'SELECT id, password_hash, status FROM users WHERE email = :email'
            );
            $statement->execute(['email' => $email]);
            $account = $statement->fetch();
            if (!$account || !verify_stored_password($password, $account['password_hash'])) {
                $errors[] = 'Email atau password tidak cocok.';
            } elseif ($account['status'] === 'pending') {
                $errors[] = 'Akun Anda menunggu persetujuan administrator.';
            } elseif ($account['status'] !== 'active') {
                $errors[] = 'Akun Anda tidak aktif. Hubungi administrator sistem.';
            } else {
                if (preg_match('/^[a-f0-9]{64}$/i', $account['password_hash']) === 1) {
                    upgrade_legacy_password_hash(
                        (int) $account['id'],
                        $account['password_hash'],
                        $password
                    );
                }
                sign_in((int) $account['id']);
                redirect_to('/?page=dashboard');
            }
        } elseif ($page === 'forgot-password') {
            $email = strtolower(trim(post_value('email')));
            $old = ['email' => $email];
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Masukkan alamat email yang valid.';
            } else {
                issue_password_reset($email);
                flash('message', 'Jika email terdaftar, tautan reset password akan dikirim. Periksa kotak masuk Anda.');
                redirect_to('/?page=forgot-password');
            }
        } elseif ($page === 'reset-password') {
            $token = post_value('token');
            $password = post_value('password');
            $confirmation = post_value('password_confirmation');
            if (!valid_reset_token($token)) {
                $errors[] = 'Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru.';
                $token = '';
            }
            if (strlen($password) < 12) {
                $errors[] = 'Password harus terdiri dari minimal 12 karakter.';
            }
            if ($password !== $confirmation) {
                $errors[] = 'Konfirmasi password tidak sama.';
            }
            if ($errors === []) {
                if (update_password_from_token($token, $password)) {
                    flash('message', 'Password berhasil diperbarui. Silakan masuk dengan password baru.');
                    redirect_to('/?page=login');
                }
                $errors[] = 'Tautan reset tidak valid atau sudah kedaluwarsa. Minta tautan baru.';
                $token = '';
            }
        }
    }
}

render_page($page, $message, $errors, $old, $token);
