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

// Pembangkit data simulasi; menggantikan respons JSON sensor sampai API asli tersedia.
function tornado_dummy_feed(array $codes): array
{
    $now = time();
    $rows = [];
    foreach ($codes as $code) {
        $seed = crc32((string) $code) % 1000;
        $wave = static fn(int $t): float => 36 + 22 * sin($t / 6000 + $seed) + 6 * sin($t / 2500 + $seed * 2);
        $current = max(3.0, $wave($now));
        $rows[] = [
            'code' => (string) $code,
            'observed_at' => gmdate('c', $now - $seed % 40),
            'wind_kmh' => round($current, 1),
            'change_1h_kmh' => round($current - $wave($now - 3600), 1),
            'gust_kmh' => round($current * (1.3 + 0.2 * sin($now / 300 + $seed)), 1),
            'pressure_hpa' => round(1008 - $current * 0.12 + 2 * sin($now / 900 + $seed), 1),
        ];
    }

    return ['source' => 'dummy', 'generated_at' => gmdate('c', $now), 'stations' => $rows];
}

function tornado_fetch_feed(array $codes): array
{
    $url = (string) (getenv('APP_TORNADO_FEED_URL') ?: '');
    if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
        $context = stream_context_create(['http' => ['timeout' => 5]]);
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

    return tornado_dummy_feed($codes);
}

function handle_tornado_feed(array $user): never
{
    header('Content-Type: application/json; charset=utf-8');
    if ($user['role'] !== 'system_admin' && !user_has_permission($user, 'dashboard')) {
        http_response_code(403);
        exit('{"error":"forbidden"}');
    }
    $codes = array_map(static fn(array $s): string => (string) $s['code'], tornado_stations($user));
    $feed = tornado_fetch_feed($codes);
    $thresholds = [];
    foreach (tornado_stations($user) as $station) {
        $thresholds[$station['code']] = $station['thresholds'];
    }
    $feed['thresholds'] = $thresholds;
    echo json_encode($feed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
