<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/auth.php';
require dirname(__DIR__) . '/app/locations.php';
require dirname(__DIR__) . '/app/locations_views.php';
require dirname(__DIR__) . '/app/sensors.php';
require dirname(__DIR__) . '/app/sensors_views.php';
require dirname(__DIR__) . '/app/thresholds.php';
require dirname(__DIR__) . '/app/thresholds_views.php';
require dirname(__DIR__) . '/app/rules.php';
require dirname(__DIR__) . '/app/rules_views.php';
require dirname(__DIR__) . '/app/recipients.php';
require dirname(__DIR__) . '/app/recipients_views.php';
require dirname(__DIR__) . '/app/integrations.php';
require dirname(__DIR__) . '/app/integrations_views.php';
require dirname(__DIR__) . '/app/reports.php';
require dirname(__DIR__) . '/app/reports_views.php';
require dirname(__DIR__) . '/app/health.php';
require dirname(__DIR__) . '/app/health_views.php';
require dirname(__DIR__) . '/app/audit.php';
require dirname(__DIR__) . '/app/audit_views.php';
require dirname(__DIR__) . '/app/weather.php';
require dirname(__DIR__) . '/app/weather_views.php';
require dirname(__DIR__) . '/app/river.php';
require dirname(__DIR__) . '/app/river_views.php';
require dirname(__DIR__) . '/app/views.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data: https://tile.openstreetmap.org; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$page = $_GET['page'] ?? 'login';
$allowedPages = [
    'login',
    'signup',
    'forgot-password',
    'reset-password',
    'dashboard',
    'admin',
    'alerts',
    'history',
    'api-ingest',
    'api-weather-feed',
    'api-river-feed',
    'logout',
];
if (!is_string($page) || !in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'login';
}
if ($page === 'api-ingest') {
    handle_api_ingest();
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

if (in_array($page, ['dashboard', 'admin', 'alerts', 'history'], true) && $user === null) {
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
if ($page === 'alerts' && !user_has_permission($user, 'handle_alerts')) {
    http_response_code(403);
    render_access_denied();
    exit;
}
if ($page === 'api-river-feed') {
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        exit('{"error":"unauthorized"}');
    }
    handle_river_feed($user);
}
if ($page === 'api-weather-feed') {
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        exit('{"error":"unauthorized"}');
    }
    handle_weather_feed($user);
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'reports'
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && is_string($_GET['export'] ?? null)
    && user_has_permission($user, 'view_reports')) {
    handle_report_export($user, report_filters($_GET), $_GET['export']);
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'audit'
    && ($_GET['export'] ?? '') === 'csv'
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && user_has_permission($user, 'manage_access')) {
    handle_audit_export($user, audit_filters($_GET));
}
if ($page === 'history'
    && !user_has_permission($user, 'view_reports')
    && !user_has_permission($user, 'manage_access')) {
    http_response_code(403);
    render_access_denied();
    exit;
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'hazards'
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_has_permission($user, 'manage_master_data')) {
        http_response_code(403);
        render_access_denied();
        exit;
    }
    if (!csrf_is_valid()) {
        flash('error', 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        http_response_code(400);
        render_dashboard($user, 'hazards');
        exit;
    }

    try {
        $action = post_value('action');
        $code = strtolower(trim(post_value('code')));
        $reason = trim(post_value('reason'));
        $reasonLength = preg_match_all('/./us', $reason);
        if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
            throw new InvalidArgumentException('Alasan perubahan wajib diisi (3–500 karakter).');
        }

        if ($action === 'delete') {
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $code)) {
                throw new InvalidArgumentException('Kode jenis bahaya tidak valid.');
            }
            delete_hazard_type($code, (int) $user['id'], $reason);
            flash('message', 'Jenis bahaya berhasil dihapus dan perubahannya dicatat.');
        } elseif (in_array($action, ['create', 'update'], true)) {
            if ($action === 'create' && !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $code)) {
                throw new InvalidArgumentException(
                    'Kode harus 2–32 karakter, diawali huruf, dan hanya berisi huruf, angka, _ atau -.'
                );
            }
            if ($action === 'update' && !preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $code)) {
                throw new InvalidArgumentException('Kode jenis bahaya tidak valid.');
            }
            $hazard = [
                'code' => $code,
                'name' => trim(post_value('name')),
                'description' => trim(post_value('description')),
                'icon' => trim(post_value('icon')),
                'color' => strtoupper(trim(post_value('color'))),
                'default_unit' => trim(post_value('default_unit')),
                'is_active' => post_value('is_active') === '1',
            ];
            $nameLength = preg_match_all('/./us', $hazard['name']);
            $descriptionLength = preg_match_all('/./us', $hazard['description']);
            $iconLength = preg_match_all('/./us', $hazard['icon']);
            $unitLength = preg_match_all('/./us', $hazard['default_unit']);
            if ($nameLength === false || $nameLength < 2 || $nameLength > 100) {
                throw new InvalidArgumentException('Nama jenis bahaya wajib diisi (2–100 karakter).');
            }
            if ($descriptionLength === false || $descriptionLength > 500) {
                throw new InvalidArgumentException('Deskripsi maksimal 500 karakter.');
            }
            if ($iconLength === false || $iconLength < 1 || $iconLength > 8) {
                throw new InvalidArgumentException('Ikon wajib diisi dan maksimal 8 karakter.');
            }
            if (!preg_match('/^#[0-9A-F]{6}$/', $hazard['color'])) {
                throw new InvalidArgumentException('Warna harus menggunakan format HEX, misalnya #27856E.');
            }
            if ($unitLength === false || $unitLength > 24) {
                throw new InvalidArgumentException('Satuan maksimal 24 karakter.');
            }
            if ($action === 'create') {
                create_hazard_type($hazard, (int) $user['id'], $reason);
                flash('message', 'Jenis bahaya berhasil ditambahkan.');
            } else {
                update_hazard_type($hazard, (int) $user['id'], $reason);
                flash('message', 'Jenis bahaya berhasil diperbarui.');
            }
        } else {
            throw new InvalidArgumentException('Tindakan jenis bahaya tidak dikenal.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        if (!in_array((string) $error->getCode(), ['23000', '19'], true)) {
            throw $error;
        }
        flash('error', 'Kode jenis bahaya tersebut sudah digunakan.');
    }
    redirect_to('/?page=dashboard&section=hazards');
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'locations'
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_has_permission($user, 'manage_master_data')) {
        http_response_code(403);
        render_access_denied();
        exit;
    }
    if (!csrf_is_valid()) {
        flash('error', 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        http_response_code(400);
        render_dashboard($user, 'locations');
        exit;
    }

    $tab = post_value('tab') === 'regions' ? 'regions' : 'locations';
    try {
        $action = post_value('action');
        $reason = trim(post_value('reason'));
        $reasonLength = preg_match_all('/./us', $reason);
        if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
            throw new InvalidArgumentException('Alasan perubahan wajib diisi (3–500 karakter).');
        }

        if (in_array($action, ['create_region', 'update_region'], true)) {
            $tab = 'regions';
            $name = trim(post_value('name'));
            $nameLength = preg_match_all('/./us', $name);
            $code = strtoupper(trim(post_value('code')));
            if ($action === 'create_region' && !preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
                throw new InvalidArgumentException('Kode wilayah harus 2–32 karakter: huruf, angka, _ atau -.');
            }
            if ($nameLength === false || $nameLength < 2 || $nameLength > 120) {
                throw new InvalidArgumentException('Nama wilayah wajib diisi (2–120 karakter).');
            }
            $level = post_value('admin_level');
            $timezone = post_value('timezone');
            if (!isset(region_admin_levels()[$level]) || !isset(region_timezones()[$timezone])) {
                throw new InvalidArgumentException('Tingkat administrasi atau zona waktu tidak valid.');
            }
            $parentRaw = post_value('parent_id');
            $parentId = null;
            if ($parentRaw !== '') {
                $parentId = filter_var($parentRaw, FILTER_VALIDATE_INT);
                if ($parentId === false || $parentId < 1) {
                    throw new InvalidArgumentException('Wilayah induk tidak valid.');
                }
            }
            $regionId = (int) filter_var(post_value('region_id'), FILTER_VALIDATE_INT);
            if ($action === 'update_region' && $regionId < 1) {
                throw new InvalidArgumentException('Wilayah tidak valid.');
            }
            save_region($user, $action, [
                'id' => $regionId,
                'code' => $code,
                'name' => $name,
                'parent_id' => $parentId,
                'admin_level' => $level,
                'timezone' => $timezone,
            ], $reason);
            flash('message', $action === 'create_region' ? 'Wilayah berhasil ditambahkan.' : 'Wilayah berhasil diperbarui.');
        } elseif ($action === 'delete_region') {
            $tab = 'regions';
            delete_region($user, (int) filter_var(post_value('region_id'), FILTER_VALIDATE_INT), $reason);
            flash('message', 'Wilayah berhasil dihapus dan perubahannya dicatat.');
        } elseif (in_array($action, ['create_location', 'update_location'], true)) {
            $data = parse_location_input($_POST, $action === 'create_location');
            if ($action === 'update_location' && $data['id'] < 1) {
                throw new InvalidArgumentException('Lokasi tidak valid.');
            }
            save_monitoring_location($user, $action, $data, $reason);
            flash('message', $action === 'create_location' ? 'Lokasi pantau berhasil ditambahkan.' : 'Lokasi pantau berhasil diperbarui.');
        } elseif ($action === 'delete_location') {
            delete_monitoring_location($user, (int) filter_var(post_value('location_id'), FILTER_VALIDATE_INT), $reason);
            flash('message', 'Lokasi pantau berhasil dihapus dan perubahannya dicatat.');
        } else {
            throw new InvalidArgumentException('Tindakan tidak dikenal.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        if (!in_array((string) $error->getCode(), ['23000', '19'], true)) {
            throw $error;
        }
        flash('error', 'Kode tersebut sudah digunakan atau data terkait masih dipakai.');
    }
    redirect_to('/?page=dashboard&section=locations&tab=' . $tab);
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'sensors'
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_has_permission($user, 'manage_master_data')) {
        http_response_code(403);
        render_access_denied();
        exit;
    }
    if (!csrf_is_valid()) {
        flash('error', 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        http_response_code(400);
        render_dashboard($user, 'sensors');
        exit;
    }
    try {
        $action = post_value('action');
        $reason = trim(post_value('reason'));
        $reasonLength = preg_match_all('/./us', $reason);
        if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
            throw new InvalidArgumentException('Alasan perubahan wajib diisi (3–500 karakter).');
        }
        $sensorId = (int) filter_var(post_value('sensor_id'), FILTER_VALIDATE_INT);
        if (in_array($action, ['create_sensor', 'update_sensor'], true)) {
            $data = parse_sensor_input($_POST, $action === 'create_sensor');
            if ($action === 'update_sensor' && $data['id'] < 1) {
                throw new InvalidArgumentException('Sensor tidak valid.');
            }
            save_sensor($user, $action, $data, $reason);
            flash('message', $action === 'create_sensor' ? 'Sensor berhasil ditambahkan.' : 'Sensor berhasil diperbarui.');
        } elseif ($action === 'record_sensor') {
            record_sensor_heartbeat($user, $sensorId, trim(post_value('value')), $reason);
            flash('message', 'Data terakhir sensor dicatat.');
        } elseif ($action === 'delete_sensor') {
            delete_sensor($user, $sensorId, $reason);
            flash('message', 'Sensor berhasil dihapus dan perubahannya dicatat.');
        } else {
            throw new InvalidArgumentException('Tindakan tidak dikenal.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        if (!in_array((string) $error->getCode(), ['23000', '19'], true)) {
            throw $error;
        }
        flash('error', 'ID sensor tersebut sudah digunakan.');
    }
    redirect_to('/?page=dashboard&section=sensors');
}
if ($page === 'dashboard'
    && in_array($_GET['section'] ?? '', ['parameters', 'thresholds'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = (string) $_GET['section'];
    if (!user_has_permission($user, 'manage_master_data')) {
        http_response_code(403);
        render_access_denied();
        exit;
    }
    if (!csrf_is_valid()) {
        flash('error', 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        http_response_code(400);
        render_dashboard($user, $section);
        exit;
    }
    try {
        $action = post_value('action');
        $reason = trim(post_value('reason'));
        $reasonLength = preg_match_all('/./us', $reason);
        if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
            throw new InvalidArgumentException('Alasan wajib diisi (3–500 karakter).');
        }
        if ($section === 'parameters') {
            if (in_array($action, ['create_parameter', 'update_parameter'], true)) {
                $data = parse_parameter_input($_POST, $action === 'create_parameter');
                if ($action === 'update_parameter' && $data['id'] < 1) {
                    throw new InvalidArgumentException('Parameter tidak valid.');
                }
                save_parameter($user, $action, $data, $reason);
                flash('message', $action === 'create_parameter' ? 'Parameter berhasil ditambahkan.' : 'Parameter berhasil diperbarui.');
            } elseif ($action === 'delete_parameter') {
                delete_parameter($user, (int) filter_var(post_value('parameter_id'), FILTER_VALIDATE_INT), $reason);
                flash('message', 'Parameter berhasil dihapus.');
            } else {
                throw new InvalidArgumentException('Tindakan tidak dikenal.');
            }
        } else {
            $thresholdId = (int) filter_var(post_value('threshold_id'), FILTER_VALIDATE_INT);
            if (in_array($action, ['create_threshold', 'update_threshold'], true)) {
                $data = parse_threshold_input($_POST);
                if ($action === 'update_threshold' && $data['id'] < 1) {
                    throw new InvalidArgumentException('Ambang tidak valid.');
                }
                save_threshold($user, $action, $data, $reason);
                flash('message', $action === 'create_threshold' ? 'Draf ambang disimpan.' : 'Ambang diperbarui sebagai draf.');
            } elseif ($action === 'new_version') {
                create_threshold_version($user, $thresholdId, $reason);
                flash('message', 'Versi baru dibuat sebagai draf.');
            } elseif ($action === 'delete_threshold') {
                delete_threshold($user, $thresholdId, $reason);
                flash('message', 'Ambang dihapus.');
            } else {
                change_threshold_state($user, $thresholdId, $action, $reason);
                flash('message', 'Status ambang diperbarui.');
            }
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        if (!in_array((string) $error->getCode(), ['23000', '19'], true)) {
            throw $error;
        }
        flash('error', 'Kode sudah digunakan atau data masih dipakai.');
    }
    redirect_to('/?page=dashboard&section=' . $section);
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'rules'
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_has_permission($user, 'manage_master_data')) {
        http_response_code(403);
        render_access_denied();
        exit;
    }
    if (!csrf_is_valid()) {
        flash('error', 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        http_response_code(400);
        render_dashboard($user, 'rules');
        exit;
    }
    try {
        $action = post_value('action');
        $reason = trim(post_value('reason'));
        $reasonLength = preg_match_all('/./us', $reason);
        if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
            throw new InvalidArgumentException('Alasan wajib diisi (3–500 karakter).');
        }
        if (in_array($action, ['create_rule', 'update_rule'], true)) {
            $data = parse_rule_input($_POST);
            if ($action === 'update_rule' && $data['id'] < 1) {
                throw new InvalidArgumentException('Aturan tidak valid.');
            }
            save_alert_rule($user, $action, $data, $reason);
            flash('message', $action === 'create_rule' ? 'Aturan berhasil ditambahkan.' : 'Aturan berhasil diperbarui.');
        } elseif ($action === 'delete_rule') {
            delete_alert_rule($user, (int) filter_var(post_value('rule_id'), FILTER_VALIDATE_INT), $reason);
            flash('message', 'Aturan dihapus.');
        } elseif ($action === 'add_step') {
            add_rule_step($user, $_POST, $reason);
            flash('message', 'Langkah eskalasi ditambahkan.');
        } elseif ($action === 'delete_step') {
            delete_rule_step($user, (int) filter_var(post_value('step_id'), FILTER_VALIDATE_INT), $reason);
            flash('message', 'Langkah eskalasi dihapus.');
        } else {
            throw new InvalidArgumentException('Tindakan tidak dikenal.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        if (!in_array((string) $error->getCode(), ['23000', '19'], true)) {
            throw $error;
        }
        flash('error', 'Data tidak dapat disimpan karena masih terkait data lain.');
    }
    redirect_to('/?page=dashboard&section=rules');
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'recipients'
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_has_permission($user, 'manage_master_data')) {
        http_response_code(403);
        render_access_denied();
        exit;
    }
    if (!csrf_is_valid()) {
        flash('error', 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        http_response_code(400);
        render_dashboard($user, 'recipients');
        exit;
    }
    try {
        $action = post_value('action');
        $reason = trim(post_value('reason'));
        $reasonLength = preg_match_all('/./us', $reason);
        if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
            throw new InvalidArgumentException('Alasan wajib diisi (3–500 karakter).');
        }
        if (in_array($action, ['create_group', 'update_group'], true)) {
            $data = parse_group_input($_POST, $action === 'create_group');
            if ($action === 'update_group' && $data['id'] < 1) {
                throw new InvalidArgumentException('Kelompok tidak valid.');
            }
            save_recipient_group($user, $action, $data, $reason);
            flash('message', $action === 'create_group' ? 'Kelompok penerima ditambahkan.' : 'Kelompok penerima diperbarui.');
        } elseif ($action === 'delete_group') {
            delete_recipient_group($user, (int) filter_var(post_value('group_id'), FILTER_VALIDATE_INT), $reason);
            flash('message', 'Kelompok dihapus.');
        } elseif (in_array($action, ['add_member', 'update_member'], true)) {
            save_recipient_member($user, $action, parse_member_input($_POST), $reason);
            flash('message', $action === 'add_member' ? 'Anggota ditambahkan.' : 'Anggota diperbarui.');
        } elseif ($action === 'delete_member') {
            delete_recipient_member($user, (int) filter_var(post_value('member_id'), FILTER_VALIDATE_INT), $reason);
            flash('message', 'Anggota dihapus.');
        } else {
            throw new InvalidArgumentException('Tindakan tidak dikenal.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        if (!in_array((string) $error->getCode(), ['23000', '19'], true)) {
            throw $error;
        }
        flash('error', 'Nama kelompok sudah digunakan atau data masih terkait.');
    }
    redirect_to('/?page=dashboard&section=recipients');
}
if ($page === 'dashboard'
    && ($_GET['section'] ?? '') === 'integrations'
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!user_has_permission($user, 'manage_master_data')) {
        http_response_code(403);
        render_access_denied();
        exit;
    }
    if (!csrf_is_valid()) {
        flash('error', 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        http_response_code(400);
        render_dashboard($user, 'integrations');
        exit;
    }
    try {
        $action = post_value('action');
        $reason = trim(post_value('reason'));
        $reasonLength = preg_match_all('/./us', $reason);
        if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
            throw new InvalidArgumentException('Alasan wajib diisi (3–500 karakter).');
        }
        $sensorId = (int) filter_var(post_value('sensor_id'), FILTER_VALIDATE_INT);
        if ($action === 'create_token') {
            flash('token', create_integration_token($user, post_value('name'), (int) filter_var(post_value('region_id'), FILTER_VALIDATE_INT), $reason));
            flash('message', 'Token dibuat.');
        } elseif (in_array($action, ['disable_token', 'enable_token', 'delete_token'], true)) {
            change_integration_token($user, (int) filter_var(post_value('token_id'), FILTER_VALIDATE_INT), $action, $reason);
            flash('message', 'Token diperbarui.');
        } elseif ($action === 'save_config') {
            save_ingest_config($user, $sensorId, post_value('valid_min'), post_value('valid_max'), post_value('late_after'), $reason);
            flash('message', 'Rentang valid disimpan.');
        } elseif ($action === 'manual_reading') {
            $result = manual_ingest($user, $sensorId, post_value('value'), post_value('source_time'), $reason);
            flash('message', 'Pembacaan dicatat: ' . ingest_statuses()[$result['status']] . ($result['note'] !== '' ? ' — ' . $result['note'] : ''));
        } elseif ($action === 'csv_import') {
            $counts = csv_ingest($user, post_value('csv'), $reason);
            $parts = [];
            foreach ($counts as $key => $count) {
                if ($count > 0) {
                    $parts[] = (ingest_statuses()[$key] ?? 'Sensor tidak dikenal') . ' ' . $count;
                }
            }
            flash('message', 'Impor selesai: ' . ($parts === [] ? 'tidak ada baris diproses' : implode(', ', $parts)) . '.');
        } else {
            throw new InvalidArgumentException('Tindakan tidak dikenal.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    }
    redirect_to('/?page=dashboard&section=integrations');
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
        } elseif ($action === 'create_user' || $action === 'send_reset') {
            $reason = trim(post_value('reason'));
            $reasonLength = preg_match_all('/./us', $reason);
            if ($reasonLength === false || $reasonLength < 3 || $reasonLength > 500) {
                throw new InvalidArgumentException('Alasan wajib diisi (3–500 karakter).');
            }
            if ($action === 'create_user') {
                $regions = $_POST['region_ids'] ?? [];
                if (!is_array($regions) || array_filter($regions, static fn(mixed $r): bool => !is_string($r) || !ctype_digit($r)) !== []) {
                    throw new InvalidArgumentException('Cakupan wilayah tidak valid.');
                }
                $newId = create_user_account(
                    (int) $user['id'],
                    post_value('name'),
                    post_value('email'),
                    post_value('role'),
                    array_map('intval', $regions),
                    $reason
                );
                $sent = send_user_reset((int) $user['id'], $newId, 'Undangan akun baru');
                flash('message', $sent
                    ? 'Akun dibuat. Tautan atur password dikirim ke email pengguna.'
                    : 'Akun dibuat, tetapi email belum terkirim (periksa APP_BASE_URL dan APP_MAIL_FROM). Gunakan "Kirim tautan reset" setelah email dikonfigurasi.');
            } else {
                $targetId = (int) filter_var(post_value('user_id'), FILTER_VALIDATE_INT);
                $sent = send_user_reset((int) $user['id'], $targetId, $reason);
                flash($sent ? 'message' : 'error', $sent
                    ? 'Tautan reset password dikirim.'
                    : 'Email reset tidak terkirim. Periksa konfigurasi email (APP_BASE_URL dan APP_MAIL_FROM).');
            }
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

if ($page === 'alerts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        render_alerts_page(
            $user,
            list_alert_events($user, [], true),
            user_regions($user),
            null,
            'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.'
        );
        exit;
    }

    try {
        $action = post_value('action');
        if ($action === 'create') {
            if (!in_array($user['role'], ['system_admin', 'operator'], true)) {
                throw new InvalidArgumentException('Peran Anda tidak diizinkan mencatat kejadian.');
            }
            $regionId = filter_var(post_value('region_id'), FILTER_VALIDATE_INT);
            $fields = [
                'hazard_type' => post_value('hazard_type'),
                'severity' => post_value('severity'),
                'location_name' => trim(post_value('location_name')),
                'trigger_indicator' => trim(post_value('trigger_indicator')),
                'trigger_value' => trim(post_value('trigger_value')),
                'threshold_value' => trim(post_value('threshold_value')),
                'source_label' => trim(post_value('source_label')),
            ];
            if ($regionId === false || $regionId < 1) {
                throw new InvalidArgumentException('Pilih wilayah yang valid.');
            }
            if (!user_has_region_access($user, $regionId)) {
                throw new InvalidArgumentException('Anda tidak memiliki akses ke wilayah tersebut.');
            }
            foreach ([
                'location_name' => ['Nama lokasi/pos', 120],
                'trigger_indicator' => ['Indikator pemicu', 160],
                'trigger_value' => ['Nilai pemicu', 100],
                'threshold_value' => ['Nilai ambang', 100],
                'source_label' => ['Sumber laporan', 160],
            ] as $field => [$label, $maxLength]) {
                $length = preg_match_all('/./us', $fields[$field]);
                if ($field !== 'threshold_value' && $field !== 'source_label'
                    && ($length === false || $length < 1)) {
                    throw new InvalidArgumentException($label . ' wajib diisi.');
                }
                if ($length === false || $length > $maxLength) {
                    throw new InvalidArgumentException($label . ' maksimal ' . $maxLength . ' karakter.');
                }
            }
            create_alert_event(
                ['region_id' => $regionId] + $fields,
                (int) $user['id']
            );
            flash('message', 'Kejadian berhasil dicatat dan muncul pada daftar kejadian aktif.');
        } else {
            $eventId = filter_var(post_value('event_id'), FILTER_VALIDATE_INT);
            if ($eventId === false || $eventId < 1) {
                throw new InvalidArgumentException('Kejadian yang dipilih tidak valid.');
            }
            if (!alert_event_is_visible($user, $eventId)) {
                throw new InvalidArgumentException('Kejadian tidak ditemukan atau di luar cakupan akses.');
            }
            handle_alert_event($eventId, (int) $user['id'], $action, [
                'assignee_id' => filter_var(post_value('assignee_id'), FILTER_VALIDATE_INT) ?: 0,
                'reason' => trim(post_value('reason')),
                'note' => trim(post_value('note')),
            ]);
            flash('message', 'Tindakan kejadian berhasil dicatat.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    }
    redirect_to('/?page=alerts');
}

if ($page === 'dashboard') {
    $section = $_GET['section'] ?? 'overview';
    if (!is_string($section)) {
        http_response_code(404);
        render_access_denied();
        exit;
    }
    render_dashboard($user, $section);
    exit;
}
if ($page === 'alerts') {
    $filters = [
        'hazard' => $_GET['hazard'] ?? '',
        'severity' => $_GET['severity'] ?? '',
        'region_id' => $_GET['region_id'] ?? '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    render_alerts_page(
        $user,
        list_alert_events($user, $filters, true),
        user_regions($user),
        flash('message'),
        flash('error'),
        $filters
    );
    exit;
}
if ($page === 'history') {
    if (($_GET['export'] ?? '') === 'csv') {
        $events = list_alert_events($user, $_GET);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="riwayat-peringatan-' . date('Ymd-His') . '.csv"');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Tidak dapat membuat ekspor riwayat.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_map('csv_safe_value', [
            'ID',
            'Jenis bahaya',
            'Tingkat',
            'Status penanganan',
            'Wilayah',
            'Lokasi',
            'Indikator pemicu',
            'Nilai pemicu',
            'Ambang',
            'Sumber',
            'Mulai',
            'Diakui',
            'Petugas',
            'Selesai',
            'Alasan penutupan',
        ]), ',', '"', '');
        foreach ($events as $event) {
            fputcsv($output, array_map('csv_safe_value', [
                $event['id'],
                alert_hazards()[$event['hazard_type']] ?? $event['hazard_type'],
                alert_severities()[$event['severity']] ?? $event['severity'],
                $event['handling_status'] === 'closed' ? 'Selesai' : 'Aktif',
                $event['region_name'],
                $event['location_name'],
                $event['trigger_indicator'],
                $event['trigger_value'],
                $event['threshold_value'],
                $event['source_label'],
                gmdate('Y-m-d H:i:s', (int) $event['started_at']),
                $event['acknowledged_at'] ? gmdate('Y-m-d H:i:s', (int) $event['acknowledged_at']) : '',
                $event['assignee_name'],
                $event['closed_at'] ? gmdate('Y-m-d H:i:s', (int) $event['closed_at']) : '',
                $event['close_reason'],
            ]), ',', '"', '');
        }
        fclose($output);
        exit;
    }
    render_history_page(
        $user,
        list_alert_events($user, $_GET),
        user_regions($user),
        $_GET
    );
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
