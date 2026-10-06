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

// Pembangkit data simulasi; menggantikan respons JSON sensor sampai API asli tersedia.
function tide_dummy_feed(array $codes): array
{
    $now = time();
    $rows = [];
    foreach ($codes as $code) {
        $seed = crc32((string) $code) % 1000;
        $phase = ($seed % 60) * 60;
        $level = static fn(int $t): float => 1.2 + 0.8 * sin(2 * M_PI * ($t + $phase) / 44712) + 0.05 * sin($t / 600 + $seed);
        $current = max(0.1, $level($now));
        $rows[] = [
            'code' => (string) $code,
            'observed_at' => gmdate('c', $now - $seed % 40),
            'tide_level_m' => round($current, 2),
            'change_1h_m' => round($current - $level($now - 3600), 2),
            'wave_height_m' => round(max(0.1, 0.6 + 0.4 * sin($now / 700 + $seed)), 1),
            'wind_kmh' => round(max(0.0, 14 + 8 * sin($now / 500 + $seed)), 1),
        ];
    }

    return ['source' => 'dummy', 'generated_at' => gmdate('c', $now), 'stations' => $rows];
}

function tide_fetch_feed(array $codes): array
{
    $url = (string) (getenv('APP_TIDE_FEED_URL') ?: '');
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

    return tide_dummy_feed($codes);
}

function handle_tide_feed(array $user): never
{
    header('Content-Type: application/json; charset=utf-8');
    if ($user['role'] !== 'system_admin' && !user_has_permission($user, 'dashboard')) {
        http_response_code(403);
        exit('{"error":"forbidden"}');
    }
    $codes = array_map(static fn(array $s): string => (string) $s['code'], tide_stations($user));
    $feed = tide_fetch_feed($codes);
    $thresholds = [];
    foreach (tide_stations($user) as $station) {
        $thresholds[$station['code']] = $station['thresholds'];
    }
    $feed['thresholds'] = $thresholds;
    echo json_encode($feed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}
