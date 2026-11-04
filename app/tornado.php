<?php

declare(strict_types=1);

function tornado_levels(): array
{
    return [
        'normal' => 'Normal',
        'watch' => 'Waspada',
        'alert' => 'Siaga',
        'warning' => 'Awas',
    ];
}

function tornado_region_chain(int $regionId): array
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
function tornado_thresholds(array $station): array
{
    $chain = tornado_region_chain((int) $station['region_id']);
    $marks = implode(',', array_fill(0, count($chain), '?'));
    $stmt = db()->prepare(
        "SELECT t.severity, t.operator, t.value, p.unit FROM thresholds t
         JOIN parameters p ON p.id = t.parameter_id
         WHERE p.code = 'wind_speed' AND t.approval_status = 'approved'
           AND (t.location_id = ? OR (t.location_id IS NULL AND (t.region_id IS NULL OR t.region_id IN ($marks))))
           AND t.valid_from <= date('now') AND (t.valid_until IS NULL OR t.valid_until >= date('now'))
         ORDER BY t.value"
    );
    $stmt->execute(array_merge([(int) $station['id']], $chain));
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        if (isset(tornado_levels()[$row['severity']])) {
            $result[$row['severity']] = ['op' => (string) $row['operator'], 'value' => (float) $row['value']];
        }
    }

    return $result;
}

function tornado_level(float $cm, array $thresholds): string
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

function tornado_trend(float $change): string
{
    return $change >= 3 ? 'rising' : ($change <= -3 ? 'falling' : 'steady');
}

function tornado_stations(array $user): array
{
    $stations = list_monitoring_locations($user, ['hazard' => 'tornado', 'status' => 'active']);
    $labels = region_path_labels(list_managed_regions($user));
    foreach ($stations as &$station) {
        $station['region_label'] = $labels[(int) $station['region_id']] ?? $station['region_name'];
        $station['thresholds'] = tornado_thresholds($station);
    }
    unset($station);

    return $stations;
}

function tornado_dummy_row(string $code, int $at): array{
    $seed = crc32($code) % 1000;
    $wave = static fn(int $t): float => max(3.0, 36 + 22 * sin($t / 6000 + $seed) + 6 * sin($t / 2500 + $seed * 2));
    $current = $wave($at);

    return [
        'code' => $code,
        'observed_at' => gmdate('c', $at - $seed % 40),
        'wind_kmh' => round($current, 1),
        'change_1h_kmh' => round($current - $wave($at - 3600), 1),
        'gust_kmh' => round($current * (1.3 + 0.2 * sin($at / 300 + $seed)), 1),
        'pressure_hpa' => round(1008 - $current * 0.12 + 2 * sin($at / 900 + $seed), 1),
    ];
}

// Pembangkit data simulasi; menggantikan respons JSON sensor sampai API asli tersedia.
function tornado_dummy_feed(array $codes): array{
    $now = time();

    return [
        'source' => 'dummy',
        'generated_at' => gmdate('c', $now),
        'stations' => array_map(static fn(mixed $code): array => tornado_dummy_row((string) $code, $now), $codes),
    ];
}

// Riwayat simulasi 3 jam terakhir (interval 2 menit) untuk grafik halaman detail.
function tornado_dummy_history(string $code): array{
    $now = time();
    $rows = [];
    for ($i = 90; $i >= 1; $i--) {
        $rows[] = tornado_dummy_row($code, $now - $i * 120);
    }

    return $rows;
}


function tornado_sensor_today(string $ewsId, string $today): array{
    $token = getenv('EWS_API_TOKEN');
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('EWS_API_TOKEN belum diatur');
    }

    $url = getenv('EWS_SENSOR_API_URL') ?: 'http://72.62.124.177:3200/api/getAllDataSensor';
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Gagal memulai cURL');
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
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    unset($ch);
    if (!is_string($raw)) {
        throw new RuntimeException('API tidak dapat dihubungi: ' . $error);
    }
    if ($http !== 200) {
        throw new RuntimeException('Sensor API HTTP ' . $http);
    }
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['success'] ?? false) !== true || !is_array($payload['data'] ?? null)) {
        throw new RuntimeException('Format respons API tidak sesuai');
    }
    $number = static fn(mixed $value): ?float =>
    $value !== null && $value !== '' && is_numeric($value) ? (float) $value : null;
    $tz = new DateTimeZone('Asia/Jakarta');
    $rows = [];
    foreach ($payload['data'] as $item) {
        if (!is_array($item) || (string) ($item['ews_id'] ?? '') !== $ewsId || ($item['tanggal'] ?? '') !== $today) {
            continue;
        }
        $time = $item['waktu'] ?? null;
        if (!is_string($time) || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            continue;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            "$today $time",
            $tz
        );

        if (!$date || $date->format('Y-m-d H:i:s') !== "$today $time") {
            continue;
        }
        $rows[] = [
            'id' => isset($item['id']) ? (int) $item['id'] : null,
            'ews_id' => $ewsId,
            'tanggal' => $today,
            'waktu' => $time,
            'observed_at' => $date->format(DATE_ATOM),
            'wind_kmh' => $number($item['kecepatan_angin'] ?? null),
            'wind_deg' => $number($item['derajat_angin'] ?? null),
            'wind_direction' => is_string($item['arah_angin'] ?? null) ? $item['arah_angin'] : null,
            'temperature_c' => $number($item['suhu'] ?? null),
            'humidity_pct' => $number($item['kelembapan'] ?? null),
            'pressure_hpa' => $number($item['tekanan'] ?? null),
            'rain_mm' => $number($item['curah_hujan'] ?? null),
            'lightning_count' => $number($item['jumlah_petir'] ?? null),
            'lightning_distance' => $number($item['jarak_petir'] ?? null),
            'noise' => $number($item['noise'] ?? null),
            'disturber' => $number($item['disturber'] ?? null),
            'change_1h_kmh' => null,
            'trend' => 'TIDAK_ADA_DATA',
            '_ts' => $date->getTimestamp(),
        ];
    }

    usort($rows, static fn(array $a, array $b): int => ($a['_ts'] <=> $b['_ts']) ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0)));
    foreach ($rows as $i => &$row) {
        if ($row['wind_kmh'] === null) {
            continue;
        }

        $target = $row['_ts'] - 3600;
        $bestDiff = 421;
        $prior = null;

        for ($j = $i - 1; $j >= 0; $j--) {
            if ($rows[$j]['_ts'] < $target - 420) {
                break;
            }

            if ($rows[$j]['wind_kmh'] === null) {
                continue;
            }

            $diff = abs($rows[$j]['_ts'] - $target);

            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $prior = $rows[$j]['wind_kmh'];
            }
        }
        if ($prior !== null) {
            $row['change_1h_kmh'] = round(
                $row['wind_kmh'] - $prior,
                2
            );
            $row['trend'] = tornado_trend(
                $row['change_1h_kmh']
            );
        }
    }
    unset($row);
    foreach ($rows as &$row) {
        unset($row['_ts']);
    }
    unset($row);
    return $rows;
}

function tornado_fetch_feed(array $codes, bool $history = false): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));

    $today = $now->format('Y-m-d');
    $feed = [
        'source' => 'live',
        'date' => $today,
        'generated_at' => $now->format(DATE_ATOM),
        'refresh_seconds' => 300,
        'stations' => [],
        'data' => [],
        'latest' => null,
    ];

    foreach ($codes as $code) {
        $list = tornado_sensor_today((string) $code, $today);
        $last = $list ? $list[count($list) - 1] : null;
        $feed['stations'][] = ['code' => (string) $code] + ($last ?? []);
        if ($history) {
            array_push($feed['data'], ...$list);
        }
        if (count($codes) === 1) {
            $feed['latest'] = $last;
        }
    }

    if ($history && count($codes) === 1) {
        $feed['history'] = $feed['data'];
    }
    return $feed;
}

function handle_tornado_feed(array $user): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($user['role'] !== 'system_admin' && !user_has_permission($user, 'dashboard')) {
        http_response_code(403);
        exit('{"error":"forbidden"}');
    }

    $stations = tornado_stations($user);
    $only = $_GET['code'] ?? null;
    if ($only !== null && !is_string($only)) {
        http_response_code(400);
        exit('{"error":"invalid_code"}');
    }

    if ($only !== null) {
        $stations = array_values(array_filter($stations, static fn(array $s): bool => (string) $s['code'] === $only));
        if (!$stations) {
            http_response_code(404);
            exit('{"error":"not_found"}');
        }
    }
    try {
        $codes = array_map(static fn(array $s): string => (string) $s['code'], $stations);
        $feed = tornado_fetch_feed($codes, $only !== null && ($_GET['history'] ?? '') === '1');
        $feed['thresholds'] = [];
        foreach ($stations as $station) {
            $feed['thresholds'][(string) $station['code']] = $station['thresholds'];
        }
        echo json_encode($feed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[TORNADO FEED] ' . $e->getMessage());
        http_response_code(502);
        echo '{"error":"sensor_api_unavailable"}';
    }
    exit;
}


function tornado_find_station(array $user, string $code): ?array
{
    foreach (tornado_stations($user) as $station) {
        if ($station['code'] === $code) {
            return $station;
        }
    }

    return null;
}
