<?php

declare(strict_types=1);

function river_levels(): array
{
    return [
        'normal' => 'Normal',
        'watch' => 'Waspada',
        'alert' => 'Siaga',
        'warning' => 'Awas',
    ];
}

function river_region_chain(int $regionId): array
{
    $chain = [];
    $stmt = db()->prepare('SELECT parent_id FROM regions WHERE id = ?');
    while ($regionId > 0 && !in_array($regionId, $chain, true) && count($chain) < 10) {
        $chain[] = $regionId;
        $stmt->execute([$regionId]);
        $regionId = (int) $stmt->fetchColumn();
    }

    return $chain;
}

// Ambang tinggi muka air yang disetujui dan berlaku untuk lokasi/wilayah stasiun.
function river_thresholds(array $station): array
{
    $chain = river_region_chain((int) $station['region_id']);
    $marks = implode(',', array_fill(0, count($chain), '?'));
    $stmt = db()->prepare(
        "SELECT t.severity, t.operator, t.value FROM thresholds t
         JOIN parameters p ON p.id = t.parameter_id
         WHERE p.code = 'water_level' AND t.approval_status = 'approved'
           AND (t.location_id = ? OR (t.location_id IS NULL AND (t.region_id IS NULL OR t.region_id IN ($marks))))
           AND t.valid_from <= date('now') AND (t.valid_until IS NULL OR t.valid_until >= date('now'))
         ORDER BY t.value"
    );
    $stmt->execute(array_merge([(int) $station['id']], $chain));
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset(river_levels()[$row['severity']])) {
            $result[$row['severity']] = ['op' => (string) $row['operator'], 'value' => (float) $row['value']];
        }
    }

    return $result;
}

function river_level(float $cm, array $thresholds): string
{
    $level = 'normal';
    foreach (['watch', 'alert', 'warning'] as $severity) {
        if (!isset($thresholds[$severity])) {
            continue;
        }
        $t = $thresholds[$severity];
        if ($t['op'] === '>' ? $cm > $t['value'] : $cm >= $t['value']) {
            $level = $severity;
        }
    }

    return $level;
}

function river_trend(float $change): string
{
    return $change >= 2 ? 'rising' : ($change <= -2 ? 'falling' : 'steady');
}

function river_stations(array $user): array
{
    $stations = list_monitoring_locations($user, ['hazard' => 'river_flood', 'status' => 'active']);
    $labels = region_path_labels(list_managed_regions($user));
    foreach ($stations as &$station) {
        $station['region_label'] = $labels[(int) $station['region_id']] ?? $station['region_name'];
        $station['thresholds'] = river_thresholds($station);
    }
    unset($station);

    return $stations;
}

function river_dummy_row(string $code, int $at): array
{
    $seed = crc32($code) % 1000;
    $level = static fn(int $t): float => max(20.0, 170 + 100 * sin($t / 9000 + $seed) + 15 * sin($t / 2400 + $seed * 3));
    $current = $level($at);

    return [
        'code' => $code,
        'observed_at' => gmdate('c', $at - $seed % 40),
        'water_level_cm' => round($current, 1),
        'change_1h_cm' => round($current - $level($at - 3600), 1),
        'rain_upstream_mm_h' => round(max(0.0, 6 * sin($at / 500 + $seed) + 2), 1),
        'flow_m3s' => round($current * 0.35, 1),
    ];
}

// Pembangkit data simulasi; menggantikan respons JSON sensor sampai API asli tersedia.
function river_dummy_feed(array $codes): array
{
    $now = time();

    return [
        'source' => 'dummy',
        'generated_at' => gmdate('c', $now),
        'stations' => array_map(static fn(mixed $code): array => river_dummy_row((string) $code, $now), $codes),
    ];
}

// Riwayat simulasi 3 jam terakhir (interval 2 menit) untuk grafik halaman detail.
function river_dummy_history(string $code): array
{
    $now = time();
    $rows = [];
    for ($i = 90; $i >= 1; $i--) {
        $rows[] = river_dummy_row($code, $now - $i * 120);
    }

    return $rows;
}

function river_fetch_feed(array $codes): array
{
    $url = 'http://72.62.124.177:3200/api/getDataSensor';
    $token = (string) (getenv('EWS_API_TOKEN') ?: '');
    if ($token === '') {
        error_log('EWS API: EWS_API_TOKEN belum dikonfigurasi.');
        return river_dummy_feed($codes);
    }
    if (!function_exists('curl_init')) {
        error_log('EWS API: ekstensi PHP cURL belum tersedia.');
        return river_dummy_feed($codes);
    }
    $payload = [
        'EWSid' => array_values($codes),
        'token' => $token,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            $payload,
            JSON_THROW_ON_ERROR
        ),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // echo '<pre>';
    // print_r($payload);
    // echo '</pre>';
    // echo $raw;
    // echo $httpCode;
    // echo $curlError;
    if (!is_string($raw) || $httpCode < 200 || $httpCode >= 300) {
        error_log(
            'EWS API gagal. HTTP: ' . $httpCode .
                '; Error: ' . $curlError
        );
        return tide_dummy_feed($codes);
    }
    try {
        $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('EWS API mengembalikan JSON tidak valid.');
        return tide_dummy_feed($codes);
    }
    if (!is_array($response) || !is_array($response['data'] ?? null)) {
        error_log('EWS API: format respons tidak sesuai.');
        return tide_dummy_feed($codes);
    }
    $latestByCode = [];
    foreach ($response['data'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = (string) ($row['ews_id'] ?? '');
        if (!in_array($code, $codes, true)) {
            continue;
        }
        $observedAt = trim(
            (string) ($row['tanggal'] ?? '') . ' ' .
                (string) ($row['waktu'] ?? '')
        );
        if (
            !isset($latestByCode[$code]) ||
            $observedAt > $latestByCode[$code]['_sort_time']
        ) {
            $latestByCode[$code] = [
                '_sort_time' => $observedAt,
                'code' => $code,
                'observed_at' => $observedAt,
                'water_level' => $row['tinggi_air'] ?? null,
            ];
        }
    }
    $stations = [];
    foreach ($latestByCode as $row) {
        $stations[] = [
            'code' => $row['code'],
            'tide_level_m' => $row['water_level'] === null ? null : (float) $row['water_level'] / 10,

            'change_1h_m' => 0,
            'wave_height_m' => 0,
            'wind_kmh' => 0,

            'observed_at' => $row['observed_at'],
        ];
    }
    return [
        'source' => 'live',
        'generated_at' => gmdate('c'),
        'stations' => $stations,
    ];
}


function river_sensor_today(string $ewsId, string $today): array{
    $token = getenv('EWS_API_TOKEN');

    if (!is_string($token) || $token === '') {
        throw new RuntimeException('EWS_API_TOKEN belum diatur');
    }

    $url = getenv('EWS_SENSOR_API_URL')
        ?: 'http://72.62.124.177:3200/api/getAllDataSensor';

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('cURL gagal dibuat');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'EWSid' => $ewsId,
            'token' => $token
        ], JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20
    ]);

    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    if (!is_string($raw)) {
        throw new RuntimeException('Sensor API: ' . $err);
    }
    if ($http !== 200) {
        throw new RuntimeException('Sensor API HTTP ' . $http);
    }
    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (
        !is_array($json) ||
        ($json['success'] ?? false) !== true ||
        !is_array($json['data'] ?? null)
    ) {
        throw new RuntimeException('Struktur respons sensor tidak sesuai');
    }
    $tz = new DateTimeZone('Asia/Jakarta');
    $rows = [];
    foreach ($json['data'] as $item) {
        if (
            !is_array($item) ||
            (string) ($item['ews_id'] ?? '') !== $ewsId ||
            ($item['tanggal'] ?? '') !== $today
        ) {
            continue;
        }
        $time = $item['waktu'] ?? '';
        if (
            !is_string($time) ||
            !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)
        ) {
            continue;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            "$today $time",
            $tz
        );
        if (
            !$date ||
            $date->format('Y-m-d H:i:s') !== "$today $time"
        ) {
            continue;
        }
        $distanceMm = (
            isset($item['water_level']) &&
            is_numeric($item['water_level'])
        ) ? (float) $item['water_level'] : null;
        $heightRaw = $item['tinggi_air']
            ?? $item['water_height_mm']
            ?? null;
        $heightMm = is_numeric($heightRaw)
            ? (float) $heightRaw
            : null;
        $rows[] = [
            'id' => isset($item['id']) ? (int) $item['id'] : null,
            'ews_id' => $ewsId,
            'tanggal' => $today,
            'waktu' => $time,
            'observed_at' => $date->format(DATE_ATOM),
            'water_level_cm' => $heightMm === null
                ? null : round($heightMm / 10, 1),
            'sensor_distance_cm' => $distanceMm === null
                ? null : round($distanceMm / 10, 1),
            'change_1h_cm' => null,
            'trend' => 'TIDAK_ADA_DATA',
            '_timestamp' => $date->getTimestamp()
        ];
    }
    usort(
        $rows,
        static fn($a, $b) => ($a['_timestamp'] <=> $b['_timestamp'])
            ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0))
    );
    foreach ($rows as $i => &$row) {
        $height = $row['water_level_cm'];
        if ($height === null) {
            continue;
        }
        if (
            $i > 0 &&
            $rows[$i - 1]['water_level_cm'] !== null
        ) {
            $seconds = $row['_timestamp']
                - $rows[$i - 1]['_timestamp'];
            if ($seconds > 0 && $seconds <= 600) {
                $delta = $height
                    - $rows[$i - 1]['water_level_cm'];
                $row['trend'] = $delta > 0.5
                    ? 'NAIK'
                    : ($delta < -0.5 ? 'TURUN' : 'STABIL');
            }
        }
        $target = $row['_timestamp'] - 3600;
        $bestDiff = 421;
        $prior = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($rows[$j]['_timestamp'] < $target - 420) {
                break;
            }
            if ($rows[$j]['water_level_cm'] === null) {
                continue;
            }
            $diff = abs(
                $rows[$j]['_timestamp'] - $target
            );
            if ($diff <= 420 && $diff < $bestDiff) {
                $bestDiff = $diff;
                $prior = $rows[$j]['water_level_cm'];
            }
        }
        if ($prior !== null) {
            $row['change_1h_cm'] = round(
                $height - $prior,
                1
            );
        }
    }
    unset($row);
    foreach ($rows as &$row) {
        unset($row['_timestamp']);
    }
    unset($row);
    return $rows;
}

function river_build_feed(array $stations,bool $withHistory = false): array {
    $now = new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Jakarta')
    );
    $today = $now->format('Y-m-d');
    $feed = [
        'success' => true,
        'source' => 'live',
        'date' => $today,
        'generated_at' => $now->format(DATE_ATOM),
        'refresh_seconds' => 300,
        'thresholds' => [],
        'stations' => [],
        'data' => [],
        'latest' => null
    ];
    foreach ($stations as $station) {
        $code = (string) $station['code'];
        $list = river_sensor_today($code, $today);
        $latest = $list === []
            ? null
            : $list[count($list) - 1];
        $feed['thresholds'][$code] = $station['thresholds'];
        $feed['stations'][] = [
            'code' => $code,
            'water_level_cm' => $latest['water_level_cm'] ?? null,
            'sensor_distance_cm' =>
            $latest['sensor_distance_cm'] ?? null,
            'change_1h_cm' =>
            $latest['change_1h_cm'] ?? null,
            'trend' => $latest['trend'] ?? 'TIDAK_ADA_DATA',
            'observed_at' => $latest['observed_at'] ?? null
        ];
        if ($withHistory) {
            $feed['data'] = array_merge(
                $feed['data'],
                $list
            );
        }
        if (count($stations) === 1) {
            $feed['latest'] = $latest;
        }
    }
    if ($withHistory && count($stations) === 1) {
        $feed['history'] = $feed['data'];
    }
    return $feed;
}


function handle_river_feed(array $user): never{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (
        $user['role'] !== 'system_admin' &&
        !user_has_permission($user, 'dashboard')
    ) {
        http_response_code(403);
        exit('{"error":"forbidden"}');
    }
    $stations = river_stations($user);
    $only = $_GET['code'] ?? null;
    if ($only !== null && !is_string($only)) {
        http_response_code(400);
        exit('{"error":"invalid_code"}');
    }
    if ($only !== null) {
        $stations = array_values(array_filter(
            $stations,
            static fn($s) => (string) $s['code'] === $only
        ));
        if (!$stations) {
            http_response_code(404);
            exit('{"error":"not_found"}');
        }
    }
    try {
        $feed = river_build_feed(
            $stations,
            $only !== null &&
                ($_GET['history'] ?? '') === '1'
        );
        echo json_encode(
            $feed,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $e) {
        error_log('[RIVER FEED] ' . $e->getMessage());
        http_response_code(502);
        echo '{"error":"sensor_api_unavailable"}';
    }
    exit;
}

function river_ui_level(?float $height, array $rules, ?string $observedAt): array {
    if ($height === null || !$observedAt) {
        return ['DATA TIDAK TERSEDIA', ''];
    }
    $timestamp = strtotime($observedAt);
    if (
        $timestamp === false ||
        time() - $timestamp > 900 ||
        $timestamp > time() + 60
    ) {
        return ['DATA TERLAMBAT', ''];
    }
    $w = $rules['watch']['value'] ?? null;
    $a = $rules['alert']['value'] ?? null;
    $d = $rules['warning']['value'] ?? null;
    if (
        !is_numeric($w) ||
        !is_numeric($a) ||
        !is_numeric($d) ||
        !(
            (float) $w < (float) $a &&
            (float) $a < (float) $d
        )
    ) {
        return ['AMBANG BELUM DIATUR', ''];
    }
    $level = river_level($height, $rules);
    return [river_levels()[$level], $level];
}


function river_find_station(array $user, string $code): ?array
{
    foreach (river_stations($user) as $station) {
        if ($station['code'] === $code) {
            return $station;
        }
    }

    return null;
}
