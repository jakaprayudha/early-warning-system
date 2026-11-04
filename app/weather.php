<?php

declare(strict_types=1);

function weather_rain_levels(): array
{
    return [
        'none' => ['Tidak hujan', 0.0],
        'light' => ['Hujan ringan', 0.1],
        'moderate' => ['Hujan sedang', 5.0],
        'heavy' => ['Hujan lebat', 10.0],
        'extreme' => ['Hujan sangat lebat', 20.0],
    ];
}

function weather_rain_level(float $mmPerHour): string
{
    $level = 'none';
    foreach (weather_rain_levels() as $key => [, $min]) {
        if ($mmPerHour >= $min && $min > 0) {
            $level = $key;
        }
    }

    return $level;
}

function weather_wind_direction(float $degrees): string
{
    $names = ['U', 'TL', 'T', 'TG', 'S', 'BD', 'B', 'BL'];

    return $names[(int) round(fmod($degrees + 360, 360) / 45) % 8];
}

function weather_stations(array $user): array
{
    $stations = list_monitoring_locations($user, ['hazard' => 'weather', 'status' => 'active']);
    $labels = region_path_labels(list_managed_regions($user));
    $sensors = [];
    foreach (list_sensors($user) as $sensor) {
        $sensors[(int) $sensor['location_id']][] = $sensor;
    }
    foreach ($stations as &$station) {
        $station['region_label'] = $labels[(int) $station['region_id']] ?? $station['region_name'];
        $station['sensors'] = $sensors[(int) $station['id']] ?? [];
    }
    unset($station);

    return $stations;
}

function weather_dummy_row(string $code, int $at): array
{
    $seed = crc32($code) % 1000;
    $wave = sin($at / 420 + $seed) + 0.5 * sin($at / 130 + $seed * 2);
    $rain = max(0.0, ($wave - 0.2) * 14 + ($seed % 7));
    $rain = $seed % 4 === 0 ? max(0.0, $rain - 6) : $rain;

    return [
        'code' => $code,
        'observed_at' => gmdate('c', $at - $seed % 40),
        'rain_mm_h' => round($rain, 1),
        'temperature_c' => round(28 + 3 * sin($at / 900 + $seed) - min($rain, 10) * 0.2, 1),
        'humidity_pct' => (int) round(min(100, 72 + min($rain, 20) * 1.2 + 6 * sin($at / 600 + $seed))),
        'wind_kmh' => round(max(0, 11 + 8 * sin($at / 300 + $seed) + $rain * 0.4), 1),
        'wind_deg' => (int) (($seed * 37 + intdiv($at, 60) * 3) % 360),
        'pressure_hpa' => round(1009 + 3 * sin($at / 1800 + $seed) - min($rain, 15) * 0.15, 1),
    ];
}

// Pembangkit data simulasi; menggantikan respons JSON sensor sampai API asli tersedia.
function weather_dummy_feed(array $codes): array
{
    $now = time();

    return [
        'source' => 'dummy',
        'generated_at' => gmdate('c', $now),
        'stations' => array_map(static fn(mixed $code): array => weather_dummy_row((string) $code, $now), $codes),
    ];
}

// Riwayat simulasi 3 jam terakhir (interval 2 menit) untuk grafik halaman detail.
function weather_dummy_history(string $code): array
{
    $now = time();
    $rows = [];
    for ($i = 90; $i >= 1; $i--) {
        $rows[] = weather_dummy_row($code, $now - $i * 120);
    }

    return $rows;
}


function weather_sensor_today(string $ewsId, string $today): array
{
    $token = getenv('EWS_API_TOKEN');

    if (!is_string($token) || $token === '') {
        throw new RuntimeException('EWS_API_TOKEN belum diatur');
    }

    $url = getenv('EWS_SENSOR_API_URL')
        ?: 'http://72.62.124.177:3200/api/getAllDataSensor';

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Gagal menyiapkan cURL');
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
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    unset($ch); // Tidak perlu curl_close() pada PHP 8.5

    if (!is_string($raw)) {
        throw new RuntimeException('Sensor API gagal: ' . $error);
    }

    if ($status !== 200) {
        throw new RuntimeException('Sensor API HTTP ' . $status);
    }

    $payload = json_decode(
        $raw,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (
        !is_array($payload) ||
        ($payload['success'] ?? false) !== true ||
        !is_array($payload['data'] ?? null)
    ) {
        throw new RuntimeException(
            'Format respons sensor tidak sesuai'
        );
    }

    $number = static function (mixed $value): ?float {
        if (
            $value === null ||
            $value === '' ||
            !is_numeric($value)
        ) {
            return null;
        }

        $n = (float) $value;
        return is_finite($n) ? $n : null;
    };

    $tz = new DateTimeZone('Asia/Jakarta');
    $rows = [];

    foreach ($payload['data'] as $item) {
        if (
            !is_array($item) ||
            (string) ($item['ews_id'] ?? '') !== $ewsId ||
            ($item['tanggal'] ?? '') !== $today
        ) {
            continue;
        }

        $time = $item['waktu'] ?? null;

        if (
            !is_string($time) ||
            !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)
        ) {
            continue;
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            "$today $time",
            $tz
        );

        if (
            !$dateTime ||
            $dateTime->format('Y-m-d H:i:s') !== "$today $time"
        ) {
            continue;
        }

        $rows[] = [
            'id' => isset($item['id'])
                ? (int) $item['id']
                : null,

            'code' => $ewsId,
            'ews_id' => $ewsId,
            'tanggal' => $today,
            'waktu' => $time,
            'observed_at' => $dateTime->format(DATE_ATOM),

            // Nilai mentah curah_hujan diasumsikan akumulasi mm.
            'rain_total_mm' => $number(
                $item['curah_hujan'] ?? null
            ),

            // Dihitung setelah data diurutkan.
            'rain_mm_h' => null,

            'temperature_c' => $number(
                $item['suhu'] ?? null
            ),

            'humidity_pct' => $number(
                $item['kelembapan'] ?? null
            ),

            'wind_kmh' => $number(
                $item['kecepatan_angin'] ?? null
            ),

            'wind_deg' => $number(
                $item['derajat_angin'] ?? null
            ),

            'wind_direction' => is_string(
                $item['arah_angin'] ?? null
            ) ? trim($item['arah_angin']) : null,

            'pressure_hpa' => $number(
                $item['tekanan'] ?? null
            ),

            '_ts' => $dateTime->getTimestamp(),
        ];
    }

    // Urutkan dari waktu terlama ke terbaru.
    usort($rows, static fn(array $a, array $b): int =>
        ($a['_ts'] <=> $b['_ts'])
        ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0))
    );

    $prevTotal = null;
    $prevTime = null;

    foreach ($rows as &$row) {
        $total = $row['rain_total_mm'];

        if (
            $total !== null &&
            $prevTotal !== null &&
            $prevTime !== null
        ) {
            $elapsed = $row['_ts'] - $prevTime;

            // Hanya hitung pada interval valid <= 15 menit.
            // Jika penghitung di-reset, hasil dibiarkan null.
            if (
                $elapsed > 0 &&
                $elapsed <= 900 &&
                $total >= $prevTotal
            ) {
                $row['rain_mm_h'] = round(
                    ($total - $prevTotal) * 3600 / $elapsed,
                    2
                );
            }
        }

        $prevTotal = $total;
        $prevTime = $row['_ts'];

        unset($row['_ts']);
    }

    unset($row);

    return $rows;
}


// GANTI fungsi weather_fetch_feed() lama.
// Parameter kedua tetap opsional.
function weather_fetch_feed(
    array $codes,
    bool $history = false
): array {
    $now = new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Jakarta')
    );

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
        $list = weather_sensor_today((string) $code, $today);

        $last = $list === []
            ? null
            : $list[count($list) - 1];

        // stations tetap ARRAY agar dashboard lama kompatibel.
        $feed['stations'][] = [
            'code' => (string) $code
        ] + ($last ?? []);

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


function handle_weather_feed(array $user): never{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (
        ($user['role'] ?? '') !== 'system_admin' &&
        !user_has_permission($user, 'dashboard')
    ) {
        http_response_code(403);
        exit('{"error":"forbidden"}');
    }

    $codes = array_map(
        static fn(array $s): string => (string) $s['code'],
        weather_stations($user)
    );

    $only = $_GET['code'] ?? null;

    if ($only !== null && !is_string($only)) {
        http_response_code(400);
        exit('{"error":"invalid_code"}');
    }

    if ($only !== null) {
        if (!in_array($only, $codes, true)) {
            http_response_code(404);
            exit('{"error":"not_found"}');
        }

        $codes = [$only];
    }

    try {
        $feed = weather_fetch_feed(
            $codes,
            $only !== null &&
            ($_GET['history'] ?? '') === '1'
        );

        echo json_encode(
            $feed,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
    } catch (Throwable $e) {
        error_log('[WEATHER FEED] ' . $e->getMessage());

        http_response_code(502);
        echo '{"error":"sensor_api_unavailable"}';
    }

    exit;
}


function weather_find_station(array $user, string $code): ?array
{
    foreach (weather_stations($user) as $station) {
        if ($station['code'] === $code) {
            return $station;
        }
    }

    return null;
}
