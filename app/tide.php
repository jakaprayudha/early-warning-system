<?php

declare(strict_types=1);

function tide_levels(): array
{
    return [
        'normal' => 'Normal',
        'watch' => 'Waspada',
        'alert' => 'Siaga',
        'warning' => 'Awas',
    ];
}

function tide_region_chain(int $regionId): array
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
function tide_thresholds(array $station): array
{
    $chain = tide_region_chain((int) $station['region_id']);
    $marks = implode(',', array_fill(0, count($chain), '?'));
    $stmt = db()->prepare(
        "SELECT t.severity, t.operator, t.value, p.unit FROM thresholds t
         JOIN parameters p ON p.id = t.parameter_id
         WHERE p.code = 'tide_height' AND t.approval_status = 'approved'
           AND (t.location_id = ? OR (t.location_id IS NULL AND (t.region_id IS NULL OR t.region_id IN ($marks))))
           AND t.valid_from <= date('now') AND (t.valid_until IS NULL OR t.valid_until >= date('now'))
         ORDER BY t.value"
    );
    $stmt->execute(array_merge([(int) $station['id']], $chain));
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset(tide_levels()[$row['severity']])) {
            $result[$row['severity']] = ['op' => (string) $row['operator'], 'value' => (float) $row['value'] / ($row['unit'] === 'cm' ? 100 : 1)];
        }
    }

    return $result;
}

function tide_level(float $cm, array $thresholds): string
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

function tide_trend(float $change): string
{
    return $change >= 0.05 ? 'rising' : ($change <= -0.05 ? 'falling' : 'steady');
}

function tide_stations(array $user): array
{
    $stations = list_monitoring_locations($user, ['hazard' => 'coastal_tide', 'status' => 'active']);
    $labels = region_path_labels(list_managed_regions($user));
    foreach ($stations as &$station) {
        $station['region_label'] = $labels[(int) $station['region_id']] ?? $station['region_name'];
        $station['thresholds'] = tide_thresholds($station);
    }
    unset($station);

    return $stations;
}

function tide_dummy_row(string $code, int $at): array
{
    $seed = crc32($code) % 1000;
    $phase = ($seed % 60) * 60;
    $level = static fn(int $t): float => max(0.1, 1.2 + 0.8 * sin(2 * M_PI * ($t + $phase) / 44712) + 0.05 * sin($t / 600 + $seed));
    $current = $level($at);

    return [
        'code' => $code,
        'observed_at' => gmdate('c', $at - $seed % 40),
        'tide_level_m' => round($current, 2),
        'change_1h_m' => round($current - $level($at - 3600), 2),
        'wave_height_m' => round(max(0.1, 0.6 + 0.4 * sin($at / 700 + $seed)), 1),
        'wind_kmh' => round(max(0.0, 14 + 8 * sin($at / 500 + $seed)), 1),
    ];
}

// Pembangkit data simulasi; menggantikan respons JSON sensor sampai API asli tersedia.
function tide_dummy_feed(array $codes): array
{
    $now = time();

    return [
        'source' => 'dummy',
        'generated_at' => gmdate('c', $now),
        'stations' => array_map(static fn(mixed $code): array => tide_dummy_row((string) $code, $now), $codes),
    ];
}

// Riwayat simulasi 3 jam terakhir (interval 2 menit) untuk grafik halaman detail.
function tide_dummy_history(string $code): array
{
    $now = time();
    $rows = [];
    for ($i = 90; $i >= 1; $i--) {
        $rows[] = tide_dummy_row($code, $now - $i * 120);
    }

    return $rows;
}


function tide_fetch_feed(array $codes): array
{
    $url = 'http://72.62.124.177:3200/api/getDataSensor';
    $token = (string) (getenv('EWS_API_TOKEN') ?: '');
    if ($token === '') {
        error_log('EWS API: EWS_API_TOKEN belum dikonfigurasi.');
        return tide_dummy_feed($codes);
    }
    if (!function_exists('curl_init')) {
        error_log('EWS API: ekstensi PHP cURL belum tersedia.');
        return tide_dummy_feed($codes);
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
            'tide_level_m' => $row['water_level'] === null
                ? null
                : (float) $row['water_level'] / 10,

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

function tide_sensor_today(string $ewsId, string $today): array {
    $token = getenv('EWS_API_TOKEN');
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('EWS_SENSOR_API_TOKEN belum diatur');
    }
    $url = getenv('EWS_SENSOR_API_URL')
        ?: 'http://72.62.124.177:3200/api/getAllDataSensor';
    $body = json_encode([
        'EWSid' => $ewsId,
        'token' => $token
    ], JSON_THROW_ON_ERROR);
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Gagal menyiapkan cURL');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('API tidak dapat dihubungi: ' . $curlError);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('API HTTP ' . $httpCode);
    }
    $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (
        !is_array($response) ||
        ($response['success'] ?? false) !== true ||
        !is_array($response['data'] ?? null)
    ) {
        throw new RuntimeException('Format respons API tidak sesuai');
    }
    $tz = new DateTimeZone('Asia/Jakarta');
    $rows = [];
    foreach ($response['data'] as $item) {
        if (
            !is_array($item) ||
            ($item['ews_id'] ?? null) !== $ewsId ||
            ($item['tanggal'] ?? null) !== $today
        ) {
            continue;
        }
        $time = $item['waktu'] ?? null;
        if (!is_string($time) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            continue;
        }
        $dateTime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $today . ' ' . $time,
            $tz
        );
        if (!$dateTime || $dateTime->format('Y-m-d H:i:s') !== $today . ' ' . $time) {
            continue;
        }
        $distanceMm = isset($item['water_level']) &&
            is_numeric($item['water_level'])
            ? (float) $item['water_level']
            : null;
        $heightMm = isset($item['tinggi_air']) &&
            is_numeric($item['tinggi_air'])
            ? (float) $item['tinggi_air']
            : null;
        $rows[] = [
            'id' => isset($item['id']) ? (int) $item['id'] : null,
            'ews_id' => $ewsId,
            'tanggal' => $today,
            'waktu' => $time,
            'observed_at' => $dateTime->format(DATE_ATOM),
            'water_level' => $distanceMm,
            'tinggi_air' => $heightMm,
            'sensor_distance_m' => $distanceMm === null
                ? null : round($distanceMm / 1000, 3),
            'tide_level_m' => $heightMm === null
                ? null : round($heightMm / 1000, 3),
            'change_1h_m' => null,
            'trend' => 'TIDAK_ADA_DATA',
            '_timestamp' => $dateTime->getTimestamp()
        ];
    }
    usort($rows, static fn(array $a, array $b): int =>
        ($a['_timestamp'] <=> $b['_timestamp'])
        ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0))
    );
    $prevHeight = null;
    $prevTimestamp = null;
    foreach ($rows as $i => &$row) {
        $height = $row['tide_level_m'];
        if (
            $height !== null &&
            $prevHeight !== null &&
            $prevTimestamp !== null &&
            $row['_timestamp'] - $prevTimestamp <= 600
        ) {
            $delta = $height - $prevHeight;
            $row['trend'] = $delta > 0.005
                ? 'NAIK'
                : ($delta < -0.005 ? 'TURUN' : 'STABIL');
        }
        $prevHeight = $height;
        $prevTimestamp = $row['_timestamp'];
        if ($height !== null) {
            $target = $row['_timestamp'] - 3600;
            $bestDiff = 420;
            $priorHeight = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($rows[$j]['_timestamp'] < $target - 420) {
                    break;
                }
                if ($rows[$j]['tide_level_m'] === null) {
                    continue;
                }
                $diff = abs($rows[$j]['_timestamp'] - $target);
                if ($diff <= $bestDiff) {
                    $bestDiff = $diff;
                    $priorHeight = $rows[$j]['tide_level_m'];
                }
            }
            if ($priorHeight !== null) {
                $row['change_1h_m'] = round(
                    $height - $priorHeight,
                    3
                );
            }
        }
    }
    unset($row);
    foreach ($rows as &$row) {
        unset($row['_timestamp']);
    }
    unset($row);
    return $rows;
}

function handle_tide_feed(array $user): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (
        $user['role'] !== 'system_admin' &&
        !user_has_permission($user, 'dashboard')
    ) {
        http_response_code(403);
        exit('{"error":"forbidden"}');
    }
    $stations = tide_stations($user);
    $only = $_GET['code'] ?? null;
    if ($only !== null && !is_string($only)) {
        http_response_code(400);
        exit('{"error":"invalid_code"}');
    }
    if (is_string($only)) {
        $stations = array_values(array_filter(
            $stations,
            static fn(array $s): bool =>
                (string) $s['code'] === $only
        ));
        if ($stations === []) {
            http_response_code(404);
            exit('{"error":"not_found"}');
        }
    }
    $now = new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Jakarta')
    );
    $today = $now->format('Y-m-d');
    $feed = [
        'source' => 'api',
        'date' => $today,
        'timezone' => 'Asia/Jakarta',
        'updated_at' => $now->format(DATE_ATOM),
        'refresh_seconds' => 300,
        'thresholds' => [],
        'stations' => [],
        'data' => [],
        'latest' => null
    ];

    try {
        foreach ($stations as $station) {
            $code = (string) $station['code'];
            $rows = tide_sensor_today($code, $today);
            $latest = $rows === []
                ? null
                : $rows[count($rows) - 1];
            $feed['thresholds'][$code] = $station['thresholds'];
            $feed['stations'][$code] = [
                'latest' => $latest,
                'count' => count($rows)
            ];
            foreach ($rows as $r) {
                $feed['data'][] = $r;
            }
            if ($only !== null) {
                $feed['latest'] = $latest;
            }
        }
        if (
            $only !== null &&
            ($_GET['history'] ?? '') === '1'
        ) {
            $feed['history'] = $feed['data'];
        }
    } catch (Throwable $e) {
        error_log('[TIDE FEED] ' . $e->getMessage());
        http_response_code(502);
        exit('{"error":"sensor_api_unavailable"}');
    }
    echo json_encode(
        $feed,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
    );
    exit;
}


function tide_find_station(array $user, string $code): ?array
{
    foreach (tide_stations($user) as $station) {
        if ($station['code'] === $code) {
            return $station;
        }
    }

    return null;
}
