<?php

declare(strict_types=1);

const EVAL_MANUAL_CLOSE_COOLDOWN = 1800;

function eval_convert_unit(float $value, string $from, string $to): float
{
    $factor = ['mm' => 0.001, 'cm' => 0.01, 'm' => 1.0];
    $from = strtolower(trim($from));
    $to = strtolower(trim($to));
    if ($from === $to || !isset($factor[$from], $factor[$to])) {
        return $value;
    }

    return $value * $factor[$from] / $factor[$to];
}

function eval_compare(float $value, string $operator, float $limit): bool
{
    return match ($operator) {
        '>' => $value > $limit,
        '>=' => $value >= $limit,
        '<' => $value < $limit,
        default => $value <= $limit,
    };
}

// Nilai parameter pada waktu $at: pembacaan terakhir (instant) atau agregat dalam jendela waktu.
function eval_value_at(array $sensor, array $threshold, int $at): ?float
{
    $interval = max(5, (int) $sensor['expected_interval_minutes']);
    $aggregation = (string) $threshold['aggregation'];
    $minutes = (int) $threshold['aggregation_minutes'];
    $ratePerHour = str_contains((string) $sensor['unit'], '/jam');
    if ($aggregation === 'sum' && $ratePerHour) {
        $aggregation = 'avg';
    }
    $fresh = $at - $interval * 2 * 60;
    if (in_array($aggregation, ['instant', 'rate'], true) || $minutes < 1) {
        $statement = db()->prepare(
            'SELECT value FROM sensor_readings
             WHERE sensor_id = ? AND status IN ("accepted", "late") AND value IS NOT NULL
               AND source_ts <= ? AND source_ts >= ?
             ORDER BY source_ts DESC LIMIT 1'
        );
        $statement->execute([$sensor['id'], $at, $fresh]);
        $value = $statement->fetchColumn();
    } else {
        $function = ['avg' => 'AVG', 'sum' => 'SUM', 'max' => 'MAX', 'min' => 'MIN'][$aggregation] ?? 'AVG';
        $statement = db()->prepare(
            'SELECT ' . $function . '(value) FROM sensor_readings
             WHERE sensor_id = ? AND status IN ("accepted", "late") AND value IS NOT NULL
               AND source_ts <= ? AND source_ts > ?'
        );
        $statement->execute([$sensor['id'], $at, $at - $minutes * 60]);
        $value = $statement->fetchColumn();
        $latest = db()->prepare(
            'SELECT 1 FROM sensor_readings WHERE sensor_id = ? AND status IN ("accepted", "late")
             AND source_ts <= ? AND source_ts >= ? LIMIT 1'
        );
        $latest->execute([$sensor['id'], $at, $fresh]);
        if ($latest->fetchColumn() === false) {
            return null;
        }
    }
    if ($value === false || $value === null) {
        return null;
    }

    return eval_convert_unit((float) $value, (string) $sensor['unit'], (string) $threshold['unit']);
}

// Ambang terpenuhi bila seluruh sampel selama masa persistensi memenuhi operator.
function eval_threshold_state(array $sensor, array $threshold, int $now): array
{
    $current = eval_value_at($sensor, $threshold, $now);
    if ($current === null) {
        return ['met' => false, 'hold' => false, 'value' => null];
    }
    $operator = (string) $threshold['operator'];
    $limit = (float) $threshold['value'];
    $met = eval_compare($current, $operator, $limit);
    $persistence = (int) $threshold['persistence_minutes'];
    if ($met && $persistence > 0) {
        $steps = min($persistence, 12);
        for ($i = 0; $i < $steps && $met; $i++) {
            $past = eval_value_at($sensor, $threshold, $now - (int) round($persistence * 60 * ($steps - $i) / $steps));
            $met = $past !== null && eval_compare($past, $operator, $limit);
        }
    }
    $hold = $met;
    if (!$met && $threshold['reset_value'] !== null) {
        $reset = (float) $threshold['reset_value'];
        $hold = in_array($operator, ['>', '>='], true) ? $current >= $reset : $current <= $reset;
    }

    return ['met' => $met, 'hold' => $hold, 'value' => $current];
}

function eval_in_active_hours(array $rule, int $now): bool
{
    if ($rule['active_from'] === null || $rule['active_until'] === null) {
        return true;
    }
    $zone = new DateTimeZone((string) ($rule['timezone'] ?: 'Asia/Jakarta'));
    $time = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('H:i');
    $from = (string) $rule['active_from'];
    $until = (string) $rule['active_until'];

    return $from <= $until ? ($time >= $from && $time <= $until) : ($time >= $from || $time <= $until);
}

function eval_number(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
}

function eval_log(int $eventId, string $action, string $details, int $now): void
{
    db()->prepare(
        'INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at) VALUES (?, NULL, ?, ?, ?)'
    )->execute([$eventId, $action, $details, $now]);
}

function eval_rule(array $rule, int $now, array &$summary): void
{
    $zone = new DateTimeZone((string) ($rule['timezone'] ?: 'Asia/Jakarta'));
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d');
    $conditionsQuery = db()->prepare(
        'SELECT thresholds.*, parameters.name AS parameter_name, parameters.unit AS unit,
                parameters.aggregation, parameters.aggregation_minutes
         FROM alert_rule_conditions
         JOIN thresholds ON thresholds.id = alert_rule_conditions.threshold_id
         JOIN parameters ON parameters.id = thresholds.parameter_id
         WHERE alert_rule_conditions.rule_id = ? AND thresholds.approval_status = "approved"
           AND thresholds.valid_from <= ? AND (thresholds.valid_until IS NULL OR thresholds.valid_until >= ?)'
    );
    $conditionsQuery->execute([$rule['id'], $today, $today]);
    $conditions = $conditionsQuery->fetchAll();
    if ($conditions === []) {
        return;
    }
    $ruleRegions = region_descendant_ids((int) $rule['region_id']);
    $perLocation = [];
    foreach ($conditions as $index => $condition) {
        $regionIds = array_values(array_intersect($ruleRegions, region_descendant_ids((int) $condition['region_id'])));
        if ($regionIds === []) {
            continue;
        }
        $sql = 'SELECT sensors.*, monitoring_locations.name AS location_name, monitoring_locations.region_id AS location_region
                FROM sensors JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id
                WHERE sensors.parameter_id = ? AND sensors.status = "active"
                  AND monitoring_locations.region_id IN (' . implode(',', array_fill(0, count($regionIds), '?')) . ')';
        $params = [$condition['parameter_id'], ...$regionIds];
        if ($condition['location_id'] !== null) {
            $sql .= ' AND sensors.location_id = ?';
            $params[] = $condition['location_id'];
        }
        $statement = db()->prepare($sql);
        $statement->execute($params);
        foreach ($statement->fetchAll() as $sensor) {
            $summary['sensors']++;
            $state = eval_threshold_state($sensor, $condition, $now);
            $locationId = (int) $sensor['location_id'];
            $candidate = $state + ['sensor' => $sensor, 'condition' => $condition];
            $rank = static fn(?array $item): int => $item === null ? -1 : ($item['met'] ? 2 : ($item['hold'] ? 1 : 0));
            $perLocation[$locationId]['name'] = $sensor['location_name'];
            $perLocation[$locationId]['region'] = (int) $sensor['location_region'];
            if ($rank($candidate) > $rank($perLocation[$locationId]['conditions'][$index] ?? null)) {
                $perLocation[$locationId]['conditions'][$index] = $candidate;
            }
        }
    }

    $all = $rule['combine_mode'] === 'all';
    $open = db()->prepare(
        'SELECT * FROM alert_events WHERE rule_id = ? AND location_id = ? AND handling_status = "open" LIMIT 1'
    );
    foreach ($perLocation as $locationId => $location) {
        $flags = static function (string $key) use ($location, $conditions, $all): bool {
            $results = [];
            foreach (array_keys($conditions) as $index) {
                $results[] = (bool) ($location['conditions'][$index][$key] ?? false);
            }

            return $all ? !in_array(false, $results, true) : in_array(true, $results, true);
        };
        $met = $flags('met');
        $hold = $flags('hold');
        $open->execute([$rule['id'], $locationId]);
        $event = $open->fetch();
        $summary['locations']++;

        if ($event) {
            if (!$hold) {
                db()->prepare(
                    'UPDATE alert_events SET handling_status = "closed", closed_at = ?, close_reason = ?, updated_at = ?
                     WHERE id = ?'
                )->execute([$now, 'Kembali normal (otomatis)', $now, $event['id']]);
                eval_log((int) $event['id'], 'closed', 'Nilai kembali di bawah batas reset; kejadian ditutup otomatis.', $now);
                $summary['closed']++;
            } else {
                eval_escalate($rule, $event, $now, $summary);
            }
            continue;
        }
        if (!$met) {
            continue;
        }
        $recent = db()->prepare(
            'SELECT 1 FROM alert_events WHERE rule_id = ? AND location_id = ? AND handling_status = "closed"
             AND closed_at > ? AND close_reason NOT LIKE "Kembali normal%" LIMIT 1'
        );
        $recent->execute([$rule['id'], $locationId, $now - EVAL_MANUAL_CLOSE_COOLDOWN]);
        if ($recent->fetchColumn()) {
            continue;
        }
        $trigger = null;
        foreach (array_keys($conditions) as $index) {
            if (($location['conditions'][$index]['met'] ?? false) === true) {
                $trigger = $location['conditions'][$index];
                break;
            }
        }
        if ($trigger === null) {
            continue;
        }
        $threshold = $trigger['condition'];
        $unit = (string) $threshold['unit'];
        db()->prepare(
            'INSERT INTO alert_events
             (hazard_type, region_id, location_name, severity, trigger_indicator, trigger_value, threshold_value,
              source_label, created_by, started_at, created_at, updated_at, rule_id, location_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?)'
        )->execute([
            $rule['hazard_code'], $location['region'], $location['name'], $rule['severity'],
            $threshold['parameter_name'] . ' · ' . $trigger['sensor']['code'],
            trim(eval_number((float) $trigger['value']) . ' ' . $unit),
            trim($threshold['operator'] . ' ' . eval_number((float) $threshold['value']) . ' ' . $unit),
            'Otomatis · ' . $rule['name'], $now, $now, $now, $rule['id'], $locationId,
        ]);
        $eventId = (int) db()->lastInsertId();
        eval_log(
            $eventId,
            'created',
            'Dibuat otomatis oleh aturan "' . $rule['name'] . '". Penerima: ' . $rule['recipient_group']
            . ' via ' . implode(', ', rule_channel_list((string) $rule['channels']))
            . ' (pengiriman notifikasi belum diaktifkan).',
            $now
        );
        $summary['created']++;
    }
}

// Eskalasi hanya dicatat pada log kejadian; pengiriman notifikasi dikerjakan terpisah.
function eval_escalate(array $rule, array $event, int $now, array &$summary): void
{
    if ($event['acknowledged_at'] !== null) {
        return;
    }
    $steps = db()->prepare('SELECT * FROM alert_rule_escalations WHERE rule_id = ? ORDER BY after_minutes');
    $steps->execute([$rule['id']]);
    $logged = db()->prepare('SELECT 1 FROM alert_event_log WHERE event_id = ? AND action = "escalated" AND details LIKE ?');
    foreach ($steps->fetchAll() as $step) {
        if ($now - (int) $event['started_at'] < (int) $step['after_minutes'] * 60) {
            continue;
        }
        $marker = '[langkah #' . $step['id'] . ']';
        $logged->execute([$event['id'], '%' . $marker . '%']);
        if ($logged->fetchColumn()) {
            continue;
        }
        eval_log(
            (int) $event['id'],
            'escalated',
            'Belum diakui setelah ' . (int) $step['after_minutes'] . ' menit; eskalasi ke ' . $step['recipient_group']
            . ' via ' . implode(', ', rule_channel_list((string) $step['channels'])) . ' ' . $marker,
            $now
        );
        $summary['escalated']++;
    }
}

function evaluate_alert_rules(?int $now = null): array
{
    $now ??= time();
    $summary = ['rules' => 0, 'sensors' => 0, 'locations' => 0, 'created' => 0, 'closed' => 0, 'escalated' => 0];
    $connection = db();
    $connection->exec('BEGIN IMMEDIATE');
    try {
        $rules = $connection->query(
            'SELECT alert_rules.*, regions.timezone FROM alert_rules JOIN regions ON regions.id = alert_rules.region_id
             WHERE alert_rules.is_active = 1'
        )->fetchAll();
        foreach ($rules as $rule) {
            if (!eval_in_active_hours($rule, $now)) {
                continue;
            }
            $summary['rules']++;
            eval_rule($rule, $now, $summary);
        }
        $connection->exec('COMMIT');
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->exec('ROLLBACK');
        }
        throw $error;
    }

    return $summary;
}

// Dipanggil setelah data masuk; kegagalan evaluasi tidak boleh menggagalkan penerimaan data.
function evaluate_alert_rules_safely(): void
{
    try {
        evaluate_alert_rules();
    } catch (Throwable $error) {
        error_log('Alert evaluation failed: ' . $error->getMessage());
    }
}
