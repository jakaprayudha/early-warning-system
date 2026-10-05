<?php

declare(strict_types=1);

function sensor_types(): array
{
    return [
        'rain_gauge' => 'Penakar hujan',
        'anemometer' => 'Anemometer (angin)',
        'water_level' => 'Tinggi muka air',
        'tide_gauge' => 'Pengukur pasang surut',
        'weather_station' => 'Stasiun cuaca',
        'camera' => 'Kamera/CCTV',
        'forecast_feed' => 'Feed prakiraan',
        'other' => 'Lainnya',
    ];
}

function sensor_protocols(): array
{
    return [
        'manual' => 'Input manual terkontrol',
        'http_api' => 'HTTP API',
        'mqtt' => 'MQTT',
        'file_upload' => 'Unggah berkas (CSV)',
        'other' => 'Lainnya',
    ];
}

function sensor_statuses(): array
{
    return ['active' => 'Aktif', 'maintenance' => 'Pemeliharaan', 'inactive' => 'Nonaktif'];
}

function sensor_health(array $sensor, ?int $now = null): string
{
    $now ??= time();
    if ($sensor['status'] === 'inactive') {
        return 'inactive';
    }
    if ($sensor['status'] === 'maintenance') {
        return 'maintenance';
    }
    $last = max((int) ($sensor['last_heartbeat_at'] ?? 0), (int) ($sensor['last_data_at'] ?? 0));
    if ($last === 0) {
        return 'unknown';
    }
    $interval = max(1, (int) $sensor['expected_interval_minutes']) * 60;

    return $now - $last > 2 * $interval ? 'delayed' : 'healthy';
}

function sensor_health_labels(): array
{
    return [
        'healthy' => 'Sehat',
        'delayed' => 'Terlambat',
        'unknown' => 'Belum ada data',
        'maintenance' => 'Pemeliharaan',
        'inactive' => 'Nonaktif',
    ];
}

function list_sensors(array $user, array $filters = []): array
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return [];
    }
    $where = ['monitoring_locations.region_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
    $params = $ids;
    $type = (string) ($filters['type'] ?? '');
    if (isset(sensor_types()[$type])) {
        $where[] = 'sensors.sensor_type = ?';
        $params[] = $type;
    }
    $locationId = (int) ($filters['location_id'] ?? 0);
    if ($locationId > 0) {
        $where[] = 'sensors.location_id = ?';
        $params[] = $locationId;
    }
    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = "(sensors.name LIKE ? ESCAPE '\\' OR sensors.code LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($query, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $statement = db()->prepare(
        'SELECT sensors.*, monitoring_locations.name AS location_name
         FROM sensors JOIN monitoring_locations ON monitoring_locations.id = sensors.location_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY sensors.name COLLATE NOCASE'
    );
    $statement->execute($params);
    $sensors = $statement->fetchAll();
    $health = (string) ($filters['health'] ?? '');
    $now = time();
    $result = [];
    foreach ($sensors as $sensor) {
        $sensor['health'] = sensor_health($sensor, $now);
        if ($health === '' || $health === $sensor['health']) {
            $result[] = $sensor;
        }
    }

    return $result;
}

function sensor_formable_locations(array $user): array
{
    return list_monitoring_locations($user, ['status' => 'active']);
}

function parse_sensor_input(array $post, bool $create): array
{
    $length = static fn(string $value): int => (int) preg_match_all('/./us', $value);
    $name = trim((string) ($post['name'] ?? ''));
    if ($length($name) < 2 || $length($name) > 120) {
        throw new InvalidArgumentException('Nama sensor wajib diisi (2–120 karakter).');
    }
    $code = strtoupper(trim((string) ($post['code'] ?? '')));
    if ($create && !preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
        throw new InvalidArgumentException('ID sensor harus 2–40 karakter: huruf, angka, _ atau -.');
    }
    $type = (string) ($post['sensor_type'] ?? '');
    $protocol = (string) ($post['protocol'] ?? '');
    $status = (string) ($post['status'] ?? '');
    if (!isset(sensor_types()[$type]) || !isset(sensor_protocols()[$protocol]) || !isset(sensor_statuses()[$status])) {
        throw new InvalidArgumentException('Tipe, protokol, atau status sensor tidak valid.');
    }
    $locationId = filter_var($post['location_id'] ?? '', FILTER_VALIDATE_INT);
    if ($locationId === false || $locationId < 1) {
        throw new InvalidArgumentException('Lokasi sensor wajib dipilih.');
    }
    $parameter = trim((string) ($post['parameter'] ?? ''));
    $unit = trim((string) ($post['unit'] ?? ''));
    $contact = trim((string) ($post['technical_contact'] ?? ''));
    $notes = trim((string) ($post['notes'] ?? ''));
    if ($length($parameter) < 2 || $length($parameter) > 60 || $length($unit) > 24
        || $length($contact) > 120 || $length($notes) > 500) {
        throw new InvalidArgumentException('Parameter wajib diisi (2–60 karakter); satuan maks 24, kontak maks 120, catatan maks 500.');
    }
    $endpoint = trim((string) ($post['endpoint'] ?? ''));
    if ($endpoint !== '') {
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        if (strlen($endpoint) > 300 || $host === '' || !in_array($scheme, ['http', 'https', 'mqtt', 'mqtts'], true)
            || parse_url($endpoint, PHP_URL_USER) !== null || parse_url($endpoint, PHP_URL_PASS) !== null) {
            throw new InvalidArgumentException('Endpoint harus URL http/https/mqtt/mqtts tanpa kredensial (maks 300 karakter).');
        }
    }
    $interval = filter_var($post['expected_interval_minutes'] ?? '', FILTER_VALIDATE_INT);
    if ($interval === false || $interval < 1 || $interval > 10080) {
        throw new InvalidArgumentException('Interval data harus 1–10080 menit.');
    }

    return [
        'id' => (int) ($post['sensor_id'] ?? 0),
        'code' => $code,
        'name' => $name,
        'sensor_type' => $type,
        'location_id' => $locationId,
        'parameter' => $parameter,
        'unit' => $unit,
        'protocol' => $protocol,
        'endpoint' => $endpoint,
        'technical_contact' => $contact,
        'expected_interval_minutes' => $interval,
        'status' => $status,
        'notes' => $notes,
    ];
}

function sensor_location_in_scope(array $user, int $locationId): bool
{
    $statement = db()->prepare('SELECT region_id FROM monitoring_locations WHERE id = :id');
    $statement->execute(['id' => $locationId]);
    $regionId = $statement->fetchColumn();

    return $regionId !== false && user_has_region_access($user, (int) $regionId);
}

function save_sensor(array $user, string $action, array $data, string $reason): void
{
    if (!sensor_location_in_scope($user, (int) $data['location_id'])) {
        throw new InvalidArgumentException('Lokasi sensor tidak ditemukan atau di luar cakupan akses Anda.');
    }
    $connection = db();
    $connection->beginTransaction();
    try {
        $values = [
            'name' => $data['name'],
            'sensor_type' => $data['sensor_type'],
            'location_id' => $data['location_id'],
            'parameter' => $data['parameter'],
            'unit' => $data['unit'],
            'protocol' => $data['protocol'],
            'endpoint' => $data['endpoint'],
            'technical_contact' => $data['technical_contact'],
            'expected_interval_minutes' => $data['expected_interval_minutes'],
            'status' => $data['status'],
            'notes' => $data['notes'],
        ];
        $now = time();
        if ($action === 'create_sensor') {
            $insert = $connection->prepare(
                'INSERT INTO sensors (code, name, sensor_type, location_id, parameter, unit, protocol,
                    endpoint, technical_contact, expected_interval_minutes, status, notes,
                    created_by, updated_by, created_at, updated_at)
                 VALUES (:code, :name, :sensor_type, :location_id, :parameter, :unit, :protocol,
                    :endpoint, :technical_contact, :expected_interval_minutes, :status, :notes,
                    :actor, :actor, :now, :now)'
            );
            $insert->execute($values + ['code' => $data['code'], 'actor' => $user['id'], 'now' => $now]);
            $sensorId = (int) $connection->lastInsertId();
            $old = [];
            $auditAction = 'sensor.created';
        } else {
            $sensorId = (int) $data['id'];
            $old = sensor_in_scope($user, $sensorId);
            $update = $connection->prepare(
                'UPDATE sensors SET name = :name, sensor_type = :sensor_type, location_id = :location_id,
                    parameter = :parameter, unit = :unit, protocol = :protocol, endpoint = :endpoint,
                    technical_contact = :technical_contact,
                    expected_interval_minutes = :expected_interval_minutes, status = :status,
                    notes = :notes, updated_by = :actor, updated_at = :now
                 WHERE id = :id'
            );
            $update->execute($values + ['actor' => $user['id'], 'now' => $now, 'id' => $sensorId]);
            $auditAction = 'sensor.updated';
        }
        location_audit((int) $user['id'], $auditAction, ['sensor_id' => $sensorId, 'old' => $old, 'new' => $values], $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function sensor_in_scope(array $user, int $sensorId): array
{
    $statement = db()->prepare('SELECT * FROM sensors WHERE id = :id');
    $statement->execute(['id' => $sensorId]);
    $sensor = $statement->fetch();
    if (!$sensor) {
        throw new InvalidArgumentException('Sensor tidak ditemukan.');
    }
    if (!sensor_location_in_scope($user, (int) $sensor['location_id'])) {
        throw new InvalidArgumentException('Sensor berada di luar cakupan akses Anda.');
    }

    return $sensor;
}

function record_sensor_heartbeat(array $user, int $sensorId, string $value, string $reason): void
{
    $length = (int) preg_match_all('/./us', $value);
    if ($length > 60) {
        throw new InvalidArgumentException('Nilai maksimal 60 karakter.');
    }
    $connection = db();
    $connection->beginTransaction();
    try {
        $sensor = sensor_in_scope($user, $sensorId);
        if ($sensor['protocol'] !== 'manual') {
            throw new InvalidArgumentException('Pencatatan manual hanya untuk sensor dengan protokol input manual.');
        }
        $now = time();
        $statement = $connection->prepare(
            'UPDATE sensors SET last_heartbeat_at = :now, last_data_at = :now, last_value = :value,
                    updated_by = :actor, updated_at = :now WHERE id = :id'
        );
        $statement->execute(['now' => $now, 'value' => $value, 'actor' => $user['id'], 'id' => $sensorId]);
        location_audit((int) $user['id'], 'sensor.data_recorded', ['sensor_id' => $sensorId, 'value' => $value], $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function delete_sensor(array $user, int $sensorId, string $reason): void
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $sensor = sensor_in_scope($user, $sensorId);
        $connection->prepare('DELETE FROM sensors WHERE id = :id')->execute(['id' => $sensorId]);
        location_audit((int) $user['id'], 'sensor.deleted', $sensor, $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}
