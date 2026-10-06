<?php

declare(strict_types=1);

const INGEST_MAX_BATCH = 100;
const INGEST_MAX_CSV_LINES = 500;
const INGEST_MAX_BODY = 262144;
const INGEST_FUTURE_TOLERANCE = 300;

function ingest_statuses(): array
{
    return [
        'accepted' => 'Diterima',
        'late' => 'Terlambat',
        'duplicate' => 'Duplikat',
        'out_of_range' => 'Di luar rentang',
        'invalid' => 'Tidak valid',
    ];
}

function ingest_channels(): array
{
    return ['api' => 'API', 'manual' => 'Manual', 'csv' => 'Impor CSV'];
}

function parse_source_time(mixed $value): ?int
{
    if ($value === null || $value === '' || $value === false) {
        return null;
    }
    if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^\d{9,13}$/', trim($value)))) {
        $number = (int) $value;
        return $number > 99999999999 ? intdiv($number, 1000) : $number;
    }
    if (!is_string($value) || strlen($value) > 40) {
        throw new InvalidArgumentException('Format waktu tidak dikenali.');
    }
    try {
        return (new DateTimeImmutable(trim($value)))->getTimestamp();
    } catch (Throwable) {
        throw new InvalidArgumentException('Format waktu tidak dikenali.');
    }
}

function ingest_config(int $sensorId): array
{
    $statement = db()->prepare('SELECT * FROM sensor_ingest_config WHERE sensor_id = :id');
    $statement->execute(['id' => $sensorId]);

    return $statement->fetch() ?: ['valid_min' => null, 'valid_max' => null, 'late_after_minutes' => 60];
}

function ingest_reading(array $sensor, mixed $rawValue, mixed $timestamp, string $channel, ?int $tokenId = null): array
{
    $connection = db();
    $now = time();
    $sensorId = (int) $sensor['id'];
    $raw = is_scalar($rawValue) ? trim((string) $rawValue) : '';
    $raw = mb_substr($raw, 0, 60);
    $status = 'accepted';
    $note = '';
    $value = null;
    $sourceTs = null;

    try {
        $sourceTs = parse_source_time($timestamp) ?? $now;
    } catch (InvalidArgumentException $error) {
        $status = 'invalid';
        $note = $error->getMessage();
    }
    if ($status === 'accepted' && $sensor['status'] !== 'active') {
        $status = 'invalid';
        $note = 'Sensor tidak berstatus aktif.';
    }
    if ($status === 'accepted') {
        if (!is_scalar($rawValue) || is_bool($rawValue) || !is_numeric($raw) || !is_finite((float) $raw)) {
            $status = 'invalid';
            $note = 'Nilai bukan angka.';
        } else {
            $value = (float) $raw;
        }
    }
    if ($status === 'accepted' && $sourceTs > $now + INGEST_FUTURE_TOLERANCE) {
        $status = 'invalid';
        $note = 'Waktu sumber berada di masa depan.';
    }
    if ($status === 'accepted') {
        $statement = $connection->prepare(
            'SELECT 1 FROM sensor_readings WHERE sensor_id = :id AND source_ts = :ts AND status IN ("accepted", "late")'
        );
        $statement->execute(['id' => $sensorId, 'ts' => $sourceTs]);
        if ($statement->fetchColumn()) {
            $status = 'duplicate';
            $note = 'Pembacaan pada waktu sumber yang sama sudah ada.';
        }
    }
    if ($status === 'accepted') {
        $config = ingest_config($sensorId);
        if (($config['valid_min'] !== null && $value < (float) $config['valid_min'])
            || ($config['valid_max'] !== null && $value > (float) $config['valid_max'])) {
            $status = 'out_of_range';
            $note = 'Nilai di luar rentang valid sensor.';
        } elseif ($now - $sourceTs > (int) $config['late_after_minutes'] * 60) {
            $status = 'late';
            $note = 'Diterima lebih dari ' . (int) $config['late_after_minutes'] . ' menit setelah waktu sumber.';
        }
    }

    $connection->beginTransaction();
    try {
        $connection->prepare(
            'INSERT INTO sensor_readings (sensor_id, value, raw_value, source_ts, received_at, status, note, channel, token_id)
             VALUES (:sensor, :value, :raw, :ts, :now, :status, :note, :channel, :token)'
        )->execute([
            'sensor' => $sensorId, 'value' => $value, 'raw' => $raw, 'ts' => $sourceTs, 'now' => $now,
            'status' => $status, 'note' => $note, 'channel' => $channel, 'token' => $tokenId,
        ]);
        $readingId = (int) $connection->lastInsertId();
        if ($sensor['status'] === 'active') {
            $connection->prepare('UPDATE sensors SET last_heartbeat_at = :now WHERE id = :id')
                ->execute(['now' => $now, 'id' => $sensorId]);
        }
        if (in_array($status, ['accepted', 'late'], true)) {
            $connection->prepare(
                'UPDATE sensors SET last_data_at = :ts, last_value = :value
                 WHERE id = :id AND (last_data_at IS NULL OR last_data_at <= :ts)'
            )->execute(['ts' => $sourceTs, 'value' => $raw, 'id' => $sensorId]);
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }

    return ['id' => $readingId, 'status' => $status, 'note' => $note];
}

function find_sensor_by_code(string $code): ?array
{
    $statement = db()->prepare('SELECT sensors.*, monitoring_locations.region_id FROM sensors
        JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id WHERE sensors.code = :code');
    $statement->execute(['code' => trim($code)]);

    return $statement->fetch() ?: null;
}

function create_integration_token(array $user, string $name, int $regionId, string $reason): string
{
    $name = trim($name);
    $length = (int) preg_match_all('/./us', $name);
    if ($length < 3 || $length > 100) {
        throw new InvalidArgumentException('Nama token wajib 3–100 karakter.');
    }
    if (!user_has_region_access($user, $regionId)) {
        throw new InvalidArgumentException('Wilayah di luar cakupan akses Anda.');
    }
    $token = 'ews_' . bin2hex(random_bytes(24));
    $connection = db();
    $connection->beginTransaction();
    try {
        $connection->prepare(
            'INSERT INTO integration_tokens (name, token_hash, token_prefix, region_id, created_by, created_at)
             VALUES (:name, :hash, :prefix, :region, :actor, :now)'
        )->execute([
            'name' => $name, 'hash' => hash('sha256', $token), 'prefix' => substr($token, 0, 8),
            'region' => $regionId, 'actor' => $user['id'], 'now' => time(),
        ]);
        location_audit((int) $user['id'], 'integration_token.created', ['name' => $name, 'region_id' => $regionId], $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }

    return $token;
}

function list_integration_tokens(array $user): array
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return [];
    }
    $statement = db()->prepare(
        'SELECT integration_tokens.*, regions.name AS region_name FROM integration_tokens
         JOIN regions ON regions.id = integration_tokens.region_id
         WHERE integration_tokens.region_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
         ORDER BY integration_tokens.is_active DESC, integration_tokens.id DESC'
    );
    $statement->execute($ids);

    return $statement->fetchAll();
}

function change_integration_token(array $user, int $tokenId, string $action, string $reason): void
{
    $statement = db()->prepare('SELECT * FROM integration_tokens WHERE id = :id');
    $statement->execute(['id' => $tokenId]);
    $token = $statement->fetch();
    if (!$token || !user_has_region_access($user, (int) $token['region_id'])) {
        throw new InvalidArgumentException('Token tidak ditemukan.');
    }
    $connection = db();
    $connection->beginTransaction();
    try {
        if ($action === 'delete_token') {
            $connection->prepare('DELETE FROM integration_tokens WHERE id = :id')->execute(['id' => $tokenId]);
        } else {
            $connection->prepare('UPDATE integration_tokens SET is_active = :on WHERE id = :id')
                ->execute(['on' => $action === 'enable_token' ? 1 : 0, 'id' => $tokenId]);
        }
        location_audit((int) $user['id'], 'integration_token.' . $action, ['id' => $tokenId, 'name' => $token['name']], $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function save_ingest_config(array $user, int $sensorId, string $min, string $max, string $late, string $reason): void
{
    $sensor = sensor_in_scope($user, $sensorId);
    $parse = static function (string $value, string $label): ?float {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new InvalidArgumentException($label . ' harus berupa angka.');
        }

        return (float) $value;
    };
    $minValue = $parse($min, 'Batas bawah');
    $maxValue = $parse($max, 'Batas atas');
    if ($minValue !== null && $maxValue !== null && $minValue >= $maxValue) {
        throw new InvalidArgumentException('Batas bawah harus lebih kecil dari batas atas.');
    }
    $lateMinutes = filter_var($late, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10080]]);
    if ($lateMinutes === false) {
        throw new InvalidArgumentException('Batas keterlambatan 1–10080 menit.');
    }
    $connection = db();
    $connection->beginTransaction();
    try {
        $connection->prepare(
            'INSERT INTO sensor_ingest_config (sensor_id, valid_min, valid_max, late_after_minutes, updated_at)
             VALUES (:id, :min, :max, :late, :now)
             ON CONFLICT(sensor_id) DO UPDATE SET valid_min = :min, valid_max = :max,
                late_after_minutes = :late, updated_at = :now'
        )->execute(['id' => $sensorId, 'min' => $minValue, 'max' => $maxValue, 'late' => $lateMinutes, 'now' => time()]);
        location_audit((int) $user['id'], 'sensor.ingest_config', [
            'sensor' => $sensor['code'], 'min' => $minValue, 'max' => $maxValue, 'late_minutes' => $lateMinutes,
        ], $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function manual_ingest(array $user, int $sensorId, string $value, string $time, string $reason): array
{
    $sensor = sensor_in_scope($user, $sensorId);
    $result = ingest_reading($sensor, $value, trim($time) === '' ? null : $time, 'manual');
    location_audit((int) $user['id'], 'ingest.manual', ['sensor' => $sensor['code'], 'status' => $result['status']], $reason);

    return $result;
}

function csv_ingest(array $user, string $text, string $reason): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
    $lines = array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== ''));
    if ($lines === []) {
        throw new InvalidArgumentException('Data CSV kosong.');
    }
    if (count($lines) > INGEST_MAX_CSV_LINES) {
        throw new InvalidArgumentException('Maksimal ' . INGEST_MAX_CSV_LINES . ' baris per impor.');
    }
    $counts = array_fill_keys(array_keys(ingest_statuses()), 0);
    $counts['unknown_sensor'] = 0;
    foreach ($lines as $index => $line) {
        $cells = array_map('trim', str_getcsv($line, ',', '"', ''));
        if ($index === 0 && strtolower($cells[0]) === 'sensor_code') {
            continue;
        }
        $sensor = find_sensor_by_code($cells[0] ?? '');
        if ($sensor === null || !sensor_location_in_scope($user, (int) $sensor['location_id'])) {
            $counts['unknown_sensor']++;
            continue;
        }
        $result = ingest_reading($sensor, $cells[1] ?? '', $cells[2] ?? null, 'csv');
        $counts[$result['status']]++;
    }
    location_audit((int) $user['id'], 'ingest.csv', $counts, $reason);

    return $counts;
}

function ingest_scope_filter(array $user, array &$params): string
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return '0';
    }
    array_push($params, ...$ids);

    return 'monitoring_locations.region_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
}

function ingest_summary(array $user): array
{
    $params = [time() - 86400];
    $scope = ingest_scope_filter($user, $params);
    $statement = db()->prepare(
        'SELECT sensor_readings.status, COUNT(*) FROM sensor_readings
         JOIN sensors ON sensors.id = sensor_readings.sensor_id
         JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id
         WHERE sensor_readings.received_at >= ? AND ' . $scope . ' GROUP BY sensor_readings.status'
    );
    $statement->execute($params);
    $summary = array_fill_keys(array_keys(ingest_statuses()), 0);
    foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$status, $count]) {
        $summary[$status] = (int) $count;
    }

    return $summary;
}

function list_readings(array $user, string $status = '', int $sensorId = 0, int $limit = 50): array
{
    $params = [];
    $where = [ingest_scope_filter($user, $params)];
    if (isset(ingest_statuses()[$status])) {
        $where[] = 'sensor_readings.status = ?';
        $params[] = $status;
    }
    if ($sensorId > 0) {
        $where[] = 'sensor_readings.sensor_id = ?';
        $params[] = $sensorId;
    }
    $statement = db()->prepare(
        'SELECT sensor_readings.*, sensors.code AS sensor_code, sensors.name AS sensor_name, sensors.unit,
                integration_tokens.name AS token_name
         FROM sensor_readings JOIN sensors ON sensors.id = sensor_readings.sensor_id
         JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id
         LEFT JOIN integration_tokens ON integration_tokens.id = sensor_readings.token_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sensor_readings.id DESC LIMIT ' . max(1, min(200, $limit))
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function api_json(int $code, array $body): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handle_api_ingest(): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        api_json(405, ['error' => 'Gunakan metode POST.']);
    }
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $token = null;
    if (preg_match('/^Bearer\s+(ews_[0-9a-f]{48})$/', $header, $match)) {
        $statement = db()->prepare('SELECT * FROM integration_tokens WHERE token_hash = :hash AND is_active = 1');
        $statement->execute(['hash' => hash('sha256', $match[1])]);
        $token = $statement->fetch() ?: null;
    }
    if ($token === null) {
        header('WWW-Authenticate: Bearer');
        api_json(401, ['error' => 'Token tidak valid atau dinonaktifkan.']);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > INGEST_MAX_BODY) {
        api_json(413, ['error' => 'Badan permintaan terlalu besar.']);
    }
    $body = file_get_contents('php://input', false, null, 0, INGEST_MAX_BODY + 1);
    $data = is_string($body) && strlen($body) <= INGEST_MAX_BODY ? json_decode($body, true) : null;
    if (!is_array($data)) {
        api_json(400, ['error' => 'Badan permintaan harus JSON.']);
    }
    $items = isset($data['readings']) && is_array($data['readings']) ? $data['readings'] : [$data];
    if (!array_is_list($items) || $items === [] || count($items) > INGEST_MAX_BATCH) {
        api_json(400, ['error' => 'Kirim 1–' . INGEST_MAX_BATCH . ' pembacaan.']);
    }
    db()->prepare('UPDATE integration_tokens SET last_used_at = :now WHERE id = :id')
        ->execute(['now' => time(), 'id' => $token['id']]);
    $allowed = array_flip(region_descendant_ids((int) $token['region_id']));
    $results = [];
    foreach ($items as $item) {
        $code = is_array($item) && is_string($item['sensor_code'] ?? null) ? $item['sensor_code'] : '';
        $sensor = $code === '' ? null : find_sensor_by_code($code);
        if ($sensor === null || !isset($allowed[(int) $sensor['region_id']])) {
            $results[] = ['sensor_code' => $code, 'status' => 'unknown_sensor', 'note' => 'Sensor tidak dikenal atau di luar cakupan token.'];
            continue;
        }
        $result = ingest_reading($sensor, $item['value'] ?? null, $item['timestamp'] ?? null, 'api', (int) $token['id']);
        $results[] = ['sensor_code' => $sensor['code'], 'status' => $result['status'], 'note' => $result['note']];
    }
    api_json(200, ['results' => $results]);
}
