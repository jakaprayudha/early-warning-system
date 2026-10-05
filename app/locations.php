<?php

declare(strict_types=1);

function region_admin_levels(): array
{
    return [
        'country' => 'Nasional',
        'province' => 'Provinsi',
        'regency' => 'Kabupaten/Kota',
        'district' => 'Kecamatan',
        'village' => 'Desa/Kelurahan',
        'basin' => 'Daerah aliran sungai',
        'coastal' => 'Zona pesisir',
        'other' => 'Lainnya',
    ];
}

function location_types(): array
{
    return [
        'station' => 'Stasiun pengamatan',
        'river_post' => 'Pos sungai',
        'coastal_post' => 'Pos pantai/muara',
        'weather_station' => 'Stasiun cuaca',
        'village' => 'Permukiman/desa',
        'other' => 'Lainnya',
    ];
}

function region_timezones(): array
{
    return [
        'Asia/Jakarta' => 'WIB (Asia/Jakarta)',
        'Asia/Makassar' => 'WITA (Asia/Makassar)',
        'Asia/Jayapura' => 'WIT (Asia/Jayapura)',
    ];
}

function location_audit(int $actorId, string $action, array $details, string $reason): void
{
    $statement = db()->prepare(
        'INSERT INTO access_audit_log (actor_id, action, details, reason, created_at)
         VALUES (:actor_id, :action, :details, :reason, :created_at)'
    );
    $statement->execute([
        'actor_id' => $actorId,
        'action' => $action,
        'details' => json_encode($details, JSON_THROW_ON_ERROR),
        'reason' => $reason,
        'created_at' => time(),
    ]);
}

function list_managed_regions(array $user): array
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare(
        "SELECT regions.id, regions.code, regions.name, regions.parent_id,
                regions.admin_level, regions.timezone,
                (SELECT COUNT(*) FROM regions child WHERE child.parent_id = regions.id) AS child_count,
                (SELECT COUNT(*) FROM monitoring_locations
                 WHERE monitoring_locations.region_id = regions.id) AS location_count,
                (SELECT COUNT(*) FROM alert_events
                 WHERE alert_events.region_id = regions.id) AS event_count,
                (SELECT COUNT(*) FROM user_regions
                 WHERE user_regions.region_id = regions.id) AS user_count
         FROM regions WHERE regions.id IN ($placeholders)
         ORDER BY regions.name COLLATE NOCASE"
    );
    $statement->execute($ids);

    return $statement->fetchAll();
}

function region_descendant_ids(int $regionId): array
{
    $statement = db()->prepare(
        'WITH RECURSIVE tree(id) AS (
            SELECT :id
            UNION
            SELECT regions.id FROM regions JOIN tree ON regions.parent_id = tree.id
         ) SELECT id FROM tree'
    );
    $statement->execute(['id' => $regionId]);

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function region_path_labels(array $regions): array
{
    $byId = [];
    foreach ($regions as $region) {
        $byId[(int) $region['id']] = $region;
    }
    $labels = [];
    foreach ($byId as $id => $region) {
        $parts = [$region['name']];
        $seen = [$id => true];
        $parentId = $region['parent_id'] === null ? null : (int) $region['parent_id'];
        while ($parentId !== null && isset($byId[$parentId]) && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            array_unshift($parts, $byId[$parentId]['name']);
            $parentId = $byId[$parentId]['parent_id'] === null
                ? null
                : (int) $byId[$parentId]['parent_id'];
        }
        $labels[$id] = implode(' › ', $parts);
    }

    return $labels;
}

function save_region(array $user, string $action, array $data, string $reason): void
{
    $actorId = (int) $user['id'];
    $connection = db();
    $connection->beginTransaction();
    try {
        $parentId = $data['parent_id'];
        if ($parentId === null && $user['role'] !== 'system_admin') {
            throw new InvalidArgumentException('Wilayah tingkat teratas hanya dapat dikelola administrator sistem.');
        }
        if ($parentId !== null && !user_has_region_access($user, $parentId)) {
            throw new InvalidArgumentException('Wilayah induk berada di luar cakupan akses Anda.');
        }

        if ($action === 'create_region') {
            $insert = $connection->prepare(
                'INSERT INTO regions (code, name, parent_id, admin_level, timezone, created_at)
                 VALUES (:code, :name, :parent_id, :admin_level, :timezone, :created_at)'
            );
            $insert->execute([
                'code' => $data['code'],
                'name' => $data['name'],
                'parent_id' => $parentId,
                'admin_level' => $data['admin_level'],
                'timezone' => $data['timezone'],
                'created_at' => time(),
            ]);
            $data['id'] = (int) $connection->lastInsertId();
            location_audit($actorId, 'region.created', $data, $reason);
        } else {
            $regionId = (int) $data['id'];
            if (!user_has_region_access($user, $regionId)) {
                throw new InvalidArgumentException('Wilayah berada di luar cakupan akses Anda.');
            }
            $find = $connection->prepare('SELECT * FROM regions WHERE id = :id');
            $find->execute(['id' => $regionId]);
            $old = $find->fetch();
            if (!$old) {
                throw new InvalidArgumentException('Wilayah tidak ditemukan.');
            }
            if ($parentId !== null && in_array($parentId, region_descendant_ids($regionId), true)) {
                throw new InvalidArgumentException('Wilayah induk tidak boleh berupa wilayah itu sendiri atau turunannya.');
            }
            $update = $connection->prepare(
                'UPDATE regions SET name = :name, parent_id = :parent_id,
                        admin_level = :admin_level, timezone = :timezone
                 WHERE id = :id'
            );
            $update->execute([
                'name' => $data['name'],
                'parent_id' => $parentId,
                'admin_level' => $data['admin_level'],
                'timezone' => $data['timezone'],
                'id' => $regionId,
            ]);
            location_audit($actorId, 'region.updated', ['old' => $old, 'new' => $data], $reason);
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function delete_region(array $user, int $regionId, string $reason): void
{
    if (!user_has_region_access($user, $regionId)) {
        throw new InvalidArgumentException('Wilayah berada di luar cakupan akses Anda.');
    }
    $connection = db();
    $connection->beginTransaction();
    try {
        $find = $connection->prepare('SELECT * FROM regions WHERE id = :id');
        $find->execute(['id' => $regionId]);
        $region = $find->fetch();
        if (!$region) {
            throw new InvalidArgumentException('Wilayah tidak ditemukan.');
        }
        $usage = $connection->prepare(
            'SELECT (SELECT COUNT(*) FROM regions WHERE parent_id = :id)
                  + (SELECT COUNT(*) FROM monitoring_locations WHERE region_id = :id)
                  + (SELECT COUNT(*) FROM alert_events WHERE region_id = :id)
                  + (SELECT COUNT(*) FROM user_regions WHERE region_id = :id)'
        );
        $usage->execute(['id' => $regionId]);
        if ((int) $usage->fetchColumn() > 0) {
            throw new InvalidArgumentException(
                'Wilayah masih memiliki wilayah turunan, lokasi, kejadian, atau akses pengguna sehingga tidak dapat dihapus.'
            );
        }
        $connection->prepare('DELETE FROM regions WHERE id = :id')->execute(['id' => $regionId]);
        location_audit((int) $user['id'], 'region.deleted', $region, $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function list_monitoring_locations(array $user, array $filters = []): array
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where = ["monitoring_locations.region_id IN ($placeholders)"];
    $params = $ids;
    $regionFilter = (int) ($filters['region_id'] ?? 0);
    if ($regionFilter > 0) {
        $subtree = array_values(array_intersect(region_descendant_ids($regionFilter), $ids));
        if ($subtree === []) {
            return [];
        }
        $where[] = 'monitoring_locations.region_id IN (' . implode(',', array_fill(0, count($subtree), '?')) . ')';
        array_push($params, ...$subtree);
    }
    $hazard = (string) ($filters['hazard'] ?? '');
    if ($hazard !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM location_hazards lh
                            WHERE lh.location_id = monitoring_locations.id AND lh.hazard_code = ?)';
        $params[] = $hazard;
    }
    $status = (string) ($filters['status'] ?? '');
    if ($status === 'active' || $status === 'inactive') {
        $where[] = 'monitoring_locations.is_active = ?';
        $params[] = $status === 'active' ? 1 : 0;
    }
    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = "(monitoring_locations.name LIKE ? ESCAPE '\\' OR monitoring_locations.code LIKE ? ESCAPE '\\')";
        $like = '%' . addcslashes($query, '%_\\') . '%';
        array_push($params, $like, $like);
    }
    $statement = db()->prepare(
        'SELECT monitoring_locations.*, regions.name AS region_name,
                (SELECT GROUP_CONCAT(hazard_code) FROM location_hazards
                 WHERE location_id = monitoring_locations.id) AS hazard_codes
         FROM monitoring_locations
         JOIN regions ON regions.id = monitoring_locations.region_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY monitoring_locations.is_active DESC, monitoring_locations.name COLLATE NOCASE'
    );
    $statement->execute($params);
    $locations = $statement->fetchAll();
    foreach ($locations as &$location) {
        $location['hazards'] = $location['hazard_codes'] === null || $location['hazard_codes'] === ''
            ? []
            : explode(',', (string) $location['hazard_codes']);
    }
    unset($location);

    return $locations;
}

function save_monitoring_location(array $user, string $action, array $data, string $reason): void
{
    if (!user_has_region_access($user, (int) $data['region_id'])) {
        throw new InvalidArgumentException('Wilayah lokasi berada di luar cakupan akses Anda.');
    }
    $known = alert_hazards();
    $hazards = [];
    foreach ($data['hazards'] as $code) {
        $match = null;
        foreach (array_keys($known) as $knownCode) {
            if (strcasecmp((string) $knownCode, (string) $code) === 0) {
                $match = (string) $knownCode;
                break;
            }
        }
        if ($match === null) {
            throw new InvalidArgumentException('Jenis bahaya yang dipilih tidak dikenal.');
        }
        $hazards[$match] = true;
    }
    $hazards = array_keys($hazards);

    $connection = db();
    $connection->beginTransaction();
    try {
        $values = [
            'name' => $data['name'],
            'region_id' => $data['region_id'],
            'location_type' => $data['location_type'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'elevation_m' => $data['elevation_m'],
            'vertical_datum' => $data['vertical_datum'],
            'geometry_geojson' => $data['geometry_geojson'],
            'managed_by' => $data['managed_by'],
            'notes' => $data['notes'],
            'is_active' => $data['is_active'] ? 1 : 0,
        ];
        $now = time();
        if ($action === 'create_location') {
            $insert = $connection->prepare(
                'INSERT INTO monitoring_locations
                 (code, name, region_id, location_type, latitude, longitude, elevation_m,
                  vertical_datum, geometry_geojson, managed_by, notes, is_active,
                  created_by, updated_by, created_at, updated_at)
                 VALUES (:code, :name, :region_id, :location_type, :latitude, :longitude,
                         :elevation_m, :vertical_datum, :geometry_geojson, :managed_by, :notes,
                         :is_active, :actor, :actor, :now, :now)'
            );
            $insert->execute($values + ['code' => $data['code'], 'actor' => $user['id'], 'now' => $now]);
            $locationId = (int) $connection->lastInsertId();
            $auditAction = 'location.created';
            $old = [];
        } else {
            $locationId = (int) $data['id'];
            $find = $connection->prepare('SELECT * FROM monitoring_locations WHERE id = :id');
            $find->execute(['id' => $locationId]);
            $old = $find->fetch();
            if (!$old) {
                throw new InvalidArgumentException('Lokasi tidak ditemukan.');
            }
            if (!user_has_region_access($user, (int) $old['region_id'])) {
                throw new InvalidArgumentException('Lokasi berada di luar cakupan akses Anda.');
            }
            $update = $connection->prepare(
                'UPDATE monitoring_locations
                 SET name = :name, region_id = :region_id, location_type = :location_type,
                     latitude = :latitude, longitude = :longitude, elevation_m = :elevation_m,
                     vertical_datum = :vertical_datum, geometry_geojson = :geometry_geojson,
                     managed_by = :managed_by, notes = :notes, is_active = :is_active,
                     updated_by = :actor, updated_at = :now
                 WHERE id = :id'
            );
            $update->execute($values + ['actor' => $user['id'], 'now' => $now, 'id' => $locationId]);
            $auditAction = 'location.updated';
        }
        $connection->prepare('DELETE FROM location_hazards WHERE location_id = :id')
            ->execute(['id' => $locationId]);
        $link = $connection->prepare(
            'INSERT INTO location_hazards (location_id, hazard_code) VALUES (:id, :code)'
        );
        foreach ($hazards as $code) {
            $link->execute(['id' => $locationId, 'code' => $code]);
        }
        location_audit(
            (int) $user['id'],
            $auditAction,
            ['location_id' => $locationId, 'old' => $old, 'new' => $values + ['hazards' => $hazards]],
            $reason
        );
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function delete_monitoring_location(array $user, int $locationId, string $reason): void
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $find = $connection->prepare('SELECT * FROM monitoring_locations WHERE id = :id');
        $find->execute(['id' => $locationId]);
        $location = $find->fetch();
        if (!$location) {
            throw new InvalidArgumentException('Lokasi tidak ditemukan.');
        }
        if (!user_has_region_access($user, (int) $location['region_id'])) {
            throw new InvalidArgumentException('Lokasi berada di luar cakupan akses Anda.');
        }
        $connection->prepare('DELETE FROM monitoring_locations WHERE id = :id')
            ->execute(['id' => $locationId]);
        location_audit((int) $user['id'], 'location.deleted', $location, $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function parse_location_input(array $post, bool $create): array
{
    $length = static fn(string $value): int => (int) preg_match_all('/./us', $value);
    $name = trim((string) ($post['name'] ?? ''));
    if ($length($name) < 2 || $length($name) > 120) {
        throw new InvalidArgumentException('Nama lokasi wajib diisi (2–120 karakter).');
    }
    $code = strtoupper(trim((string) ($post['code'] ?? '')));
    if ($create && !preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
        throw new InvalidArgumentException('Kode lokasi harus 2–32 karakter: huruf, angka, _ atau -.');
    }
    $regionId = filter_var($post['region_id'] ?? '', FILTER_VALIDATE_INT);
    if ($regionId === false || $regionId < 1) {
        throw new InvalidArgumentException('Wilayah lokasi wajib dipilih.');
    }
    $type = (string) ($post['location_type'] ?? '');
    if (!isset(location_types()[$type])) {
        throw new InvalidArgumentException('Tipe lokasi tidak valid.');
    }
    $latitude = filter_var($post['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($post['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $latitude < -90 || $latitude > 90) {
        throw new InvalidArgumentException('Latitude harus berupa angka antara -90 dan 90.');
    }
    if ($longitude === false || $longitude < -180 || $longitude > 180) {
        throw new InvalidArgumentException('Longitude harus berupa angka antara -180 dan 180.');
    }
    $elevationRaw = trim((string) ($post['elevation_m'] ?? ''));
    $elevation = null;
    if ($elevationRaw !== '') {
        $elevation = filter_var($elevationRaw, FILTER_VALIDATE_FLOAT);
        if ($elevation === false || $elevation < -500 || $elevation > 9000) {
            throw new InvalidArgumentException('Elevasi harus berupa angka (-500 sampai 9000 meter).');
        }
    }
    $datum = trim((string) ($post['vertical_datum'] ?? ''));
    $managedBy = trim((string) ($post['managed_by'] ?? ''));
    $notes = trim((string) ($post['notes'] ?? ''));
    if ($length($datum) > 40 || $length($managedBy) > 120 || $length($notes) > 500) {
        throw new InvalidArgumentException('Datum maksimal 40, pengelola 120, dan catatan 500 karakter.');
    }
    $geometry = trim((string) ($post['geometry_geojson'] ?? ''));
    if ($geometry !== '') {
        if (strlen($geometry) > 20000) {
            throw new InvalidArgumentException('Geometri GeoJSON maksimal 20.000 karakter.');
        }
        $decoded = json_decode($geometry, true);
        $geometryTypes = ['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'];
        if (!is_array($decoded) || !in_array($decoded['type'] ?? null, $geometryTypes, true)
            || !isset($decoded['coordinates']) || !is_array($decoded['coordinates'])) {
            throw new InvalidArgumentException('Geometri harus berupa objek GeoJSON geometry yang valid.');
        }
    }
    $hazards = $post['hazards'] ?? [];
    if (!is_array($hazards)) {
        throw new InvalidArgumentException('Pilihan jenis bahaya tidak valid.');
    }
    foreach ($hazards as $hazard) {
        if (!is_string($hazard)) {
            throw new InvalidArgumentException('Pilihan jenis bahaya tidak valid.');
        }
    }

    return [
        'id' => (int) ($post['location_id'] ?? 0),
        'code' => $code,
        'name' => $name,
        'region_id' => $regionId,
        'location_type' => $type,
        'latitude' => (float) $latitude,
        'longitude' => (float) $longitude,
        'elevation_m' => $elevation === null ? null : (float) $elevation,
        'vertical_datum' => $datum,
        'geometry_geojson' => $geometry,
        'managed_by' => $managedBy,
        'notes' => $notes,
        'is_active' => ($post['is_active'] ?? '') === '1',
        'hazards' => array_values($hazards),
    ];
}
