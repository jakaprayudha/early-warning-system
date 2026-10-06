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

// Pembangkit data simulasi; menggantikan respons JSON sensor sampai API asli tersedia.
function weather_dummy_feed(array $codes): array
{
    $now = time();
    $stations = [];
    foreach ($codes as $code) {
        $seed = crc32((string) $code) % 1000;
        $wave = sin($now / 420 + $seed) + 0.5 * sin($now / 130 + $seed * 2);
        $rain = max(0.0, ($wave - 0.2) * 14 + ($seed % 7));
        $rain = $seed % 4 === 0 ? max(0.0, $rain - 6) : $rain;
        $stations[] = [
            'code' => (string) $code,
            'observed_at' => gmdate('c', $now - $seed % 40),
            'rain_mm_h' => round($rain, 1),
            'temperature_c' => round(28 + 3 * sin($now / 900 + $seed) - min($rain, 10) * 0.2, 1),
            'humidity_pct' => (int) round(min(100, 72 + min($rain, 20) * 1.2 + 6 * sin($now / 600 + $seed))),
            'wind_kmh' => round(max(0, 11 + 8 * sin($now / 300 + $seed) + $rain * 0.4), 1),
            'wind_deg' => (int) (($seed * 37 + intdiv($now, 60) * 3) % 360),
            'pressure_hpa' => round(1009 + 3 * sin($now / 1800 + $seed) - min($rain, 15) * 0.15, 1),
        ];
    }

    return ['source' => 'dummy', 'generated_at' => gmdate('c', $now), 'stations' => $stations];
}

// Bila APP_WEATHER_FEED_URL diatur, JSON dibaca dari sumber asli (skema sama); gagal => kembali ke simulasi.
function weather_fetch_feed(array $codes): array
{
    $url = (string) (getenv('APP_WEATHER_FEED_URL') ?: '');
    if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
        $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => false]]);
        $raw = @file_get_contents($url, false, $context);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && is_array($data['stations'] ?? null)) {
            $data['stations'] = array_values(array_filter(
                $data['stations'],
                static fn(mixed $row): bool => is_array($row) && in_array($row['code'] ?? null, $codes, true)
            ));
            $data['source'] = 'live';
            $data['generated_at'] ??= gmdate('c');

            return $data;
        }
    }

    return weather_dummy_feed($codes);
}

function handle_weather_feed(array $user): never
{
    if (!user_has_permission($user, 'dashboard') && $user['role'] !== 'system_admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"forbidden"}';
        exit;
    }
    $codes = array_map(static fn(array $s): string => (string) $s['code'], weather_stations($user));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(weather_fetch_feed($codes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
