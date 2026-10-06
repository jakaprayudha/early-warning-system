<?php

declare(strict_types=1);

const REPORT_MAX_EXPORT_ROWS = 10000;

function report_filters(array $source): array
{
    $date = static function (mixed $value, string $fallback): string {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value . ' UTC') !== false
            ? $value
            : $fallback;
    };
    $to = $date($source['to'] ?? null, gmdate('Y-m-d'));
    $from = $date($source['from'] ?? null, gmdate('Y-m-d', strtotime($to . ' -29 days UTC')));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    if (strtotime($to . ' UTC') - strtotime($from . ' UTC') > 366 * 86400) {
        $from = gmdate('Y-m-d', strtotime($to . ' -366 days UTC'));
    }

    return [
        'from' => $from,
        'to' => $to,
        'region_id' => is_string($source['region_id'] ?? null) && ctype_digit($source['region_id']) ? $source['region_id'] : '',
        'hazard' => is_string($source['hazard'] ?? null) ? $source['hazard'] : '',
    ];
}

function report_events(array $user, array $filters): array
{
    return list_alert_events($user, $filters);
}

function report_event_stats(array $events): array
{
    $stats = [
        'total' => count($events), 'open' => 0, 'closed' => 0, 'acknowledged' => 0,
        'by_severity' => [], 'by_hazard' => [], 'by_region' => [], 'by_day' => [],
        'mtta' => null, 'mttr' => null,
    ];
    $ack = [];
    $res = [];
    foreach ($events as $event) {
        $stats[$event['handling_status'] === 'closed' ? 'closed' : 'open']++;
        $stats['by_severity'][$event['severity']] = ($stats['by_severity'][$event['severity']] ?? 0) + 1;
        $stats['by_hazard'][$event['hazard_type']] = ($stats['by_hazard'][$event['hazard_type']] ?? 0) + 1;
        $stats['by_region'][$event['region_name']] = ($stats['by_region'][$event['region_name']] ?? 0) + 1;
        $day = gmdate('Y-m-d', (int) $event['started_at']);
        $stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
        if ($event['acknowledged_at'] !== null && (int) $event['acknowledged_at'] >= (int) $event['started_at']) {
            $stats['acknowledged']++;
            $ack[] = (int) $event['acknowledged_at'] - (int) $event['started_at'];
        }
        if ($event['closed_at'] !== null && (int) $event['closed_at'] >= (int) $event['started_at']) {
            $res[] = (int) $event['closed_at'] - (int) $event['started_at'];
        }
    }
    $stats['mtta'] = $ack === [] ? null : (int) round(array_sum($ack) / count($ack));
    $stats['mttr'] = $res === [] ? null : (int) round(array_sum($res) / count($res));
    arsort($stats['by_hazard']);
    arsort($stats['by_region']);
    ksort($stats['by_day']);

    return $stats;
}

function format_duration(?int $seconds): string
{
    if ($seconds === null) {
        return '—';
    }
    if ($seconds < 3600) {
        return max(1, intdiv($seconds, 60)) . ' mnt';
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return $hours . ' j' . ($minutes > 0 ? ' ' . $minutes . ' mnt' : '');
}

function report_reading_rows(array $user, array $filters, int $limit): array
{
    $params = [
        strtotime($filters['from'] . ' 00:00:00 UTC'),
        strtotime($filters['to'] . ' +1 day 00:00:00 UTC'),
    ];
    $where = ['sensor_readings.received_at >= ?', 'sensor_readings.received_at < ?', ingest_scope_filter($user, $params)];
    if ($filters['region_id'] !== '') {
        $where[] = 'monitoring_locations.region_id = ?';
        $params[] = (int) $filters['region_id'];
    }
    $statement = db()->prepare(
        'SELECT sensor_readings.*, sensors.code AS sensor_code, sensors.unit, monitoring_locations.name AS location_name
         FROM sensor_readings JOIN sensors ON sensors.id = sensor_readings.sensor_id
         JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY sensor_readings.id DESC LIMIT ' . $limit
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function report_data_quality(array $user, array $filters): array
{
    $params = [
        strtotime($filters['from'] . ' 00:00:00 UTC'),
        strtotime($filters['to'] . ' +1 day 00:00:00 UTC'),
    ];
    $where = ['sensor_readings.received_at >= ?', 'sensor_readings.received_at < ?', ingest_scope_filter($user, $params)];
    if ($filters['region_id'] !== '') {
        $where[] = 'monitoring_locations.region_id = ?';
        $params[] = (int) $filters['region_id'];
    }
    $statement = db()->prepare(
        'SELECT sensors.code, sensors.name, sensor_readings.status, COUNT(*) AS total
         FROM sensor_readings JOIN sensors ON sensors.id = sensor_readings.sensor_id
         JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id
         WHERE ' . implode(' AND ', $where) . ' GROUP BY sensors.id, sensor_readings.status ORDER BY sensors.code'
    );
    $statement->execute($params);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[$row['code']] ??= ['code' => $row['code'], 'name' => $row['name'], 'total' => 0]
            + array_fill_keys(array_keys(ingest_statuses()), 0);
        $rows[$row['code']][$row['status']] = (int) $row['total'];
        $rows[$row['code']]['total'] += (int) $row['total'];
    }

    return array_values($rows);
}

function stream_csv(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Ymd-His') . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Tidak dapat membuat ekspor.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_map('csv_safe_value', $header), ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($output, array_map('csv_safe_value', $row), ',', '"', '');
    }
    fclose($output);
    exit;
}

function handle_report_export(array $user, array $filters, string $type): never
{
    $audit = ['type' => $type, 'from' => $filters['from'], 'to' => $filters['to'], 'region_id' => $filters['region_id'], 'hazard' => $filters['hazard']];
    $eventFilters = ['from' => $filters['from'], 'to' => $filters['to'], 'region_id' => $filters['region_id'], 'hazard' => $filters['hazard']];
    if ($type === 'events') {
        $events = array_slice(report_events($user, $eventFilters), 0, REPORT_MAX_EXPORT_ROWS);
        location_audit((int) $user['id'], 'report.exported', $audit + ['rows' => count($events)], 'Ekspor laporan');
        $rows = (static function () use ($events): Generator {
            foreach ($events as $e) {
                yield [
                    $e['id'], alert_hazards()[$e['hazard_type']] ?? $e['hazard_type'], alert_severities()[$e['severity']] ?? $e['severity'],
                    $e['handling_status'] === 'closed' ? 'Selesai' : 'Aktif', $e['region_name'], $e['location_name'],
                    $e['trigger_indicator'], $e['trigger_value'], $e['threshold_value'], $e['source_label'],
                    gmdate('Y-m-d H:i:s', (int) $e['started_at']),
                    $e['acknowledged_at'] === null ? '' : gmdate('Y-m-d H:i:s', (int) $e['acknowledged_at']),
                    $e['closed_at'] === null ? '' : gmdate('Y-m-d H:i:s', (int) $e['closed_at']),
                    $e['close_reason'],
                ];
            }
        })();
        stream_csv('laporan-kejadian', ['ID', 'Jenis bahaya', 'Tingkat', 'Status', 'Wilayah', 'Lokasi', 'Indikator', 'Nilai pemicu', 'Ambang', 'Sumber', 'Mulai (UTC)', 'Diakui (UTC)', 'Selesai (UTC)', 'Alasan penutupan'], $rows);
    }
    if ($type === 'readings') {
        $readings = report_reading_rows($user, $filters, REPORT_MAX_EXPORT_ROWS);
        location_audit((int) $user['id'], 'report.exported', $audit + ['rows' => count($readings)], 'Ekspor laporan');
        $rows = (static function () use ($readings): Generator {
            foreach ($readings as $r) {
                yield [
                    $r['id'], $r['sensor_code'], $r['location_name'], $r['raw_value'], $r['unit'],
                    $r['source_ts'] === null ? '' : gmdate('Y-m-d H:i:s', (int) $r['source_ts']),
                    gmdate('Y-m-d H:i:s', (int) $r['received_at']), ingest_statuses()[$r['status']] ?? $r['status'],
                    ingest_channels()[$r['channel']] ?? $r['channel'], $r['note'],
                ];
            }
        })();
        stream_csv('laporan-pembacaan', ['ID', 'Sensor', 'Lokasi', 'Nilai', 'Satuan', 'Waktu sumber (UTC)', 'Diterima (UTC)', 'Status', 'Kanal', 'Catatan'], $rows);
    }
    if ($type === 'quality') {
        $quality = report_data_quality($user, $filters);
        location_audit((int) $user['id'], 'report.exported', $audit + ['rows' => count($quality)], 'Ekspor laporan');
        $rows = array_map(static fn(array $q): array => [$q['code'], $q['name'], $q['total'], $q['accepted'], $q['late'], $q['duplicate'], $q['out_of_range'], $q['invalid']], $quality);
        stream_csv('laporan-kualitas-data', ['Sensor', 'Nama', 'Total', 'Diterima', 'Terlambat', 'Duplikat', 'Di luar rentang', 'Tidak valid'], $rows);
    }
    http_response_code(400);
    exit('Jenis ekspor tidak dikenal.');
}
