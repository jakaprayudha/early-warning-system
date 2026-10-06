<?php

declare(strict_types=1);

function parameter_aggregations(): array
{
    return [
        'instant' => 'Nilai sesaat',
        'avg' => 'Rata-rata',
        'sum' => 'Akumulasi',
        'max' => 'Maksimum',
        'min' => 'Minimum',
        'rate' => 'Laju perubahan',
    ];
}

function threshold_operators(): array
{
    return ['>' => '> lebih dari', '>=' => '≥ minimal', '<' => '< kurang dari', '<=' => '≤ maksimal'];
}

function approval_statuses(): array
{
    return [
        'draft' => 'Draf',
        'pending' => 'Menunggu persetujuan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'superseded' => 'Digantikan',
        'retired' => 'Dinonaktifkan',
    ];
}

function list_parameters(): array
{
    return db()->query(
        'SELECT parameters.*, hazard_types.name AS hazard_name,
                (SELECT COUNT(*) FROM thresholds WHERE thresholds.parameter_id = parameters.id) AS threshold_count
         FROM parameters JOIN hazard_types ON hazard_types.code = parameters.hazard_code
         ORDER BY parameters.is_active DESC, hazard_types.name COLLATE NOCASE, parameters.name COLLATE NOCASE'
    )->fetchAll();
}

function parse_parameter_input(array $post, bool $create): array
{
    $length = static fn(string $value): int => (int) preg_match_all('/./us', $value);
    $code = strtolower(trim((string) ($post['code'] ?? '')));
    if ($create && !preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $code)) {
        throw new InvalidArgumentException('Kode parameter harus 2–40 karakter huruf kecil, angka, _ atau -, diawali huruf.');
    }
    $name = trim((string) ($post['name'] ?? ''));
    $unit = trim((string) ($post['unit'] ?? ''));
    $description = trim((string) ($post['description'] ?? ''));
    if ($length($name) < 2 || $length($name) > 100 || $length($unit) > 24 || $length($description) > 500) {
        throw new InvalidArgumentException('Nama wajib 2–100 karakter; satuan maks 24; deskripsi maks 500.');
    }
    $hazard = (string) ($post['hazard_code'] ?? '');
    $match = null;
    foreach (array_keys(alert_hazards()) as $known) {
        if (strcasecmp((string) $known, $hazard) === 0) {
            $match = (string) $known;
        }
    }
    if ($match === null) {
        throw new InvalidArgumentException('Jenis bahaya tidak valid.');
    }
    $aggregation = (string) ($post['aggregation'] ?? '');
    if (!isset(parameter_aggregations()[$aggregation])) {
        throw new InvalidArgumentException('Agregasi tidak valid.');
    }
    $minutes = filter_var($post['aggregation_minutes'] ?? '0', FILTER_VALIDATE_INT);
    if ($minutes === false || $minutes < 0 || $minutes > 10080) {
        throw new InvalidArgumentException('Durasi agregasi harus 0–10080 menit.');
    }

    return [
        'id' => (int) ($post['parameter_id'] ?? 0),
        'code' => $code,
        'name' => $name,
        'hazard_code' => $match,
        'unit' => $unit,
        'aggregation' => $aggregation,
        'aggregation_minutes' => $aggregation === 'instant' ? 0 : $minutes,
        'description' => $description,
        'is_active' => ($post['is_active'] ?? '') === '1' ? 1 : 0,
    ];
}

function save_parameter(array $user, string $action, array $data, string $reason): void
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $now = time();
        $values = [
            'name' => $data['name'], 'hazard_code' => $data['hazard_code'], 'unit' => $data['unit'],
            'aggregation' => $data['aggregation'], 'aggregation_minutes' => $data['aggregation_minutes'],
            'description' => $data['description'], 'is_active' => $data['is_active'],
        ];
        if ($action === 'create_parameter') {
            $connection->prepare(
                'INSERT INTO parameters (code, name, hazard_code, unit, aggregation, aggregation_minutes,
                    description, is_active, created_by, created_at, updated_at)
                 VALUES (:code, :name, :hazard_code, :unit, :aggregation, :aggregation_minutes,
                    :description, :is_active, :actor, :now, :now)'
            )->execute($values + ['code' => $data['code'], 'actor' => $user['id'], 'now' => $now]);
            $id = (int) $connection->lastInsertId();
            $old = [];
            $auditAction = 'parameter.created';
        } else {
            $id = (int) $data['id'];
            $find = $connection->prepare('SELECT * FROM parameters WHERE id = :id');
            $find->execute(['id' => $id]);
            $old = $find->fetch();
            if (!$old) {
                throw new InvalidArgumentException('Parameter tidak ditemukan.');
            }
            $connection->prepare(
                'UPDATE parameters SET name = :name, hazard_code = :hazard_code, unit = :unit,
                    aggregation = :aggregation, aggregation_minutes = :aggregation_minutes,
                    description = :description, is_active = :is_active, updated_at = :now WHERE id = :id'
            )->execute($values + ['now' => $now, 'id' => $id]);
            $auditAction = 'parameter.updated';
        }
        location_audit((int) $user['id'], $auditAction, ['parameter_id' => $id, 'old' => $old, 'new' => $values], $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function delete_parameter(array $user, int $id, string $reason): void
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $find = $connection->prepare('SELECT * FROM parameters WHERE id = :id');
        $find->execute(['id' => $id]);
        $parameter = $find->fetch();
        if (!$parameter) {
            throw new InvalidArgumentException('Parameter tidak ditemukan.');
        }
        $usage = $connection->prepare('SELECT COUNT(*) FROM thresholds WHERE parameter_id = :id');
        $usage->execute(['id' => $id]);
        if ((int) $usage->fetchColumn() > 0) {
            throw new InvalidArgumentException('Parameter sudah memiliki ambang. Nonaktifkan agar riwayat tetap terjaga.');
        }
        $connection->prepare('DELETE FROM parameters WHERE id = :id')->execute(['id' => $id]);
        location_audit((int) $user['id'], 'parameter.deleted', $parameter, $reason);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function list_thresholds(array $user, array $filters = []): array
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return [];
    }
    $where = ['thresholds.region_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
    $params = $ids;
    $parameterId = (int) ($filters['parameter_id'] ?? 0);
    if ($parameterId > 0) {
        $where[] = 'thresholds.parameter_id = ?';
        $params[] = $parameterId;
    }
    $status = (string) ($filters['status'] ?? '');
    if (isset(approval_statuses()[$status])) {
        $where[] = 'thresholds.approval_status = ?';
        $params[] = $status;
    } elseif (($filters['current_only'] ?? false) === true) {
        $where[] = "thresholds.approval_status NOT IN ('superseded', 'retired')";
    }
    $statement = db()->prepare(
        'SELECT thresholds.*, parameters.name AS parameter_name, parameters.unit AS parameter_unit,
                parameters.code AS parameter_code, hazard_types.name AS hazard_name,
                regions.name AS region_name, monitoring_locations.name AS location_name,
                creator.name AS creator_name, decider.name AS decider_name
         FROM thresholds
         JOIN parameters ON parameters.id = thresholds.parameter_id
         JOIN hazard_types ON hazard_types.code = parameters.hazard_code
         JOIN regions ON regions.id = thresholds.region_id
         LEFT JOIN monitoring_locations ON monitoring_locations.id = thresholds.location_id
         LEFT JOIN users creator ON creator.id = thresholds.created_by
         LEFT JOIN users decider ON decider.id = thresholds.decided_by
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY hazard_types.name COLLATE NOCASE, parameters.name COLLATE NOCASE,
                  regions.name COLLATE NOCASE, thresholds.priority DESC, thresholds.version DESC'
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function threshold_in_scope(array $user, int $id): array
{
    $statement = db()->prepare('SELECT * FROM thresholds WHERE id = :id');
    $statement->execute(['id' => $id]);
    $threshold = $statement->fetch();
    if (!$threshold) {
        throw new InvalidArgumentException('Ambang tidak ditemukan.');
    }
    if (!user_has_region_access($user, (int) $threshold['region_id'])) {
        throw new InvalidArgumentException('Ambang berada di luar cakupan akses Anda.');
    }

    return $threshold;
}

function parse_threshold_input(array $post): array
{
    $length = static fn(string $value): int => (int) preg_match_all('/./us', $value);
    $parameterId = filter_var($post['parameter_id'] ?? '', FILTER_VALIDATE_INT);
    $regionId = filter_var($post['region_id'] ?? '', FILTER_VALIDATE_INT);
    if ($parameterId === false || $parameterId < 1 || $regionId === false || $regionId < 1) {
        throw new InvalidArgumentException('Parameter dan wilayah wajib dipilih.');
    }
    $locationRaw = (string) ($post['location_id'] ?? '');
    $locationId = null;
    if ($locationRaw !== '') {
        $locationId = filter_var($locationRaw, FILTER_VALIDATE_INT);
        if ($locationId === false || $locationId < 1) {
            throw new InvalidArgumentException('Lokasi tidak valid.');
        }
    }
    $severity = (string) ($post['severity'] ?? '');
    $operator = (string) ($post['operator'] ?? '');
    if (!isset(alert_severities()[$severity]) || !isset(threshold_operators()[$operator])) {
        throw new InvalidArgumentException('Tingkat atau operator tidak valid.');
    }
    $value = filter_var($post['value'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($value === false || abs($value) > 1.0E9) {
        throw new InvalidArgumentException('Nilai ambang harus berupa angka.');
    }
    $resetRaw = trim((string) ($post['reset_value'] ?? ''));
    $reset = null;
    if ($resetRaw !== '') {
        $reset = filter_var($resetRaw, FILTER_VALIDATE_FLOAT);
        if ($reset === false || abs($reset) > 1.0E9) {
            throw new InvalidArgumentException('Nilai reset harus berupa angka.');
        }
        $upward = in_array($operator, ['>', '>='], true);
        if (($upward && $reset >= $value) || (!$upward && $reset <= $value)) {
            throw new InvalidArgumentException('Nilai reset (histeresis) harus ' . ($upward ? 'lebih rendah' : 'lebih tinggi') . ' dari nilai ambang.');
        }
    }
    $persistence = filter_var($post['persistence_minutes'] ?? '0', FILTER_VALIDATE_INT);
    $priority = filter_var($post['priority'] ?? '50', FILTER_VALIDATE_INT);
    if ($persistence === false || $persistence < 0 || $persistence > 10080 || $priority === false || $priority < 1 || $priority > 100) {
        throw new InvalidArgumentException('Persistensi 0–10080 menit dan prioritas 1–100.');
    }
    $from = (string) ($post['valid_from'] ?? '');
    $until = trim((string) ($post['valid_until'] ?? ''));
    $isDate = static fn(string $date): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        && DateTimeImmutable::createFromFormat('Y-m-d', $date) !== false
        && DateTimeImmutable::createFromFormat('Y-m-d', $date)->format('Y-m-d') === $date;
    if (!$isDate($from) || ($until !== '' && !$isDate($until)) || ($until !== '' && $until < $from)) {
        throw new InvalidArgumentException('Masa berlaku tidak valid (tanggal akhir tidak boleh sebelum tanggal mulai).');
    }
    $notes = trim((string) ($post['notes'] ?? ''));
    if ($length($notes) > 500) {
        throw new InvalidArgumentException('Catatan maksimal 500 karakter.');
    }

    return [
        'id' => (int) ($post['threshold_id'] ?? 0),
        'parameter_id' => $parameterId,
        'region_id' => $regionId,
        'location_id' => $locationId,
        'severity' => $severity,
        'operator' => $operator,
        'value' => (float) $value,
        'reset_value' => $reset === null ? null : (float) $reset,
        'persistence_minutes' => $persistence,
        'priority' => $priority,
        'valid_from' => $from,
        'valid_until' => $until === '' ? null : $until,
        'notes' => $notes,
    ];
}

function threshold_transaction(callable $work): void
{
    $connection = db();
    $connection->beginTransaction();
    try {
        $work($connection);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function validate_threshold_targets(array $user, array $data): void
{
    if (!user_has_region_access($user, (int) $data['region_id'])) {
        throw new InvalidArgumentException('Wilayah ambang berada di luar cakupan akses Anda.');
    }
    $parameter = db()->prepare('SELECT is_active FROM parameters WHERE id = :id');
    $parameter->execute(['id' => $data['parameter_id']]);
    $active = $parameter->fetchColumn();
    if ($active === false) {
        throw new InvalidArgumentException('Parameter tidak ditemukan.');
    }
    if ((int) $active !== 1) {
        throw new InvalidArgumentException('Parameter nonaktif tidak dapat dipakai untuk ambang baru.');
    }
    if ($data['location_id'] !== null) {
        $location = db()->prepare('SELECT region_id FROM monitoring_locations WHERE id = :id');
        $location->execute(['id' => $data['location_id']]);
        $locationRegion = $location->fetchColumn();
        if ($locationRegion === false
            || !in_array((int) $locationRegion, region_descendant_ids((int) $data['region_id']), true)) {
            throw new InvalidArgumentException('Lokasi harus berada pada wilayah yang dipilih.');
        }
    }
}

function save_threshold(array $user, string $action, array $data, string $reason): void
{
    validate_threshold_targets($user, $data);
    threshold_transaction(static function (PDO $connection) use ($user, $action, $data, $reason): void {
        $now = time();
        $values = [
            'parameter_id' => $data['parameter_id'], 'region_id' => $data['region_id'],
            'location_id' => $data['location_id'], 'severity' => $data['severity'],
            'operator' => $data['operator'], 'value' => $data['value'], 'reset_value' => $data['reset_value'],
            'persistence_minutes' => $data['persistence_minutes'], 'priority' => $data['priority'],
            'valid_from' => $data['valid_from'], 'valid_until' => $data['valid_until'], 'notes' => $data['notes'],
        ];
        if ($action === 'create_threshold') {
            $connection->prepare(
                'INSERT INTO thresholds (parameter_id, region_id, location_id, severity, operator, value,
                    reset_value, persistence_minutes, priority, valid_from, valid_until, notes,
                    version, approval_status, created_by, created_at, updated_at)
                 VALUES (:parameter_id, :region_id, :location_id, :severity, :operator, :value,
                    :reset_value, :persistence_minutes, :priority, :valid_from, :valid_until, :notes,
                    1, "draft", :actor, :now, :now)'
            )->execute($values + ['actor' => $user['id'], 'now' => $now]);
            $id = (int) $connection->lastInsertId();
            $old = [];
            $auditAction = 'threshold.created';
        } else {
            $id = (int) $data['id'];
            $old = threshold_in_scope($user, $id);
            if (!in_array($old['approval_status'], ['draft', 'rejected'], true)) {
                throw new InvalidArgumentException('Hanya draf atau ambang ditolak yang dapat diubah. Buat versi baru untuk ambang lain.');
            }
            $connection->prepare(
                'UPDATE thresholds SET parameter_id = :parameter_id, region_id = :region_id,
                    location_id = :location_id, severity = :severity, operator = :operator, value = :value,
                    reset_value = :reset_value, persistence_minutes = :persistence_minutes,
                    priority = :priority, valid_from = :valid_from, valid_until = :valid_until,
                    notes = :notes, approval_status = "draft", updated_at = :now WHERE id = :id'
            )->execute($values + ['now' => $now, 'id' => $id]);
            $auditAction = 'threshold.updated';
        }
        location_audit((int) $user['id'], $auditAction, ['threshold_id' => $id, 'old' => $old, 'new' => $values], $reason);
    });
}

function create_threshold_version(array $user, int $id, string $reason): void
{
    threshold_transaction(static function (PDO $connection) use ($user, $id, $reason): void {
        $base = threshold_in_scope($user, $id);
        if ($base['approval_status'] !== 'approved') {
            throw new InvalidArgumentException('Versi baru hanya dapat dibuat dari ambang yang disetujui.');
        }
        $open = $connection->prepare(
            'SELECT COUNT(*) FROM thresholds WHERE supersedes_id = :id AND approval_status IN ("draft", "pending")'
        );
        $open->execute(['id' => $id]);
        if ((int) $open->fetchColumn() > 0) {
            throw new InvalidArgumentException('Sudah ada versi baru yang belum diputuskan untuk ambang ini.');
        }
        $now = time();
        $connection->prepare(
            'INSERT INTO thresholds (parameter_id, region_id, location_id, severity, operator, value,
                reset_value, persistence_minutes, priority, valid_from, valid_until, notes,
                version, supersedes_id, approval_status, created_by, created_at, updated_at)
             SELECT parameter_id, region_id, location_id, severity, operator, value, reset_value,
                persistence_minutes, priority, valid_from, valid_until, notes,
                version + 1, id, "draft", :actor, :now, :now FROM thresholds WHERE id = :id'
        )->execute(['actor' => $user['id'], 'now' => $now, 'id' => $id]);
        $newId = (int) $connection->lastInsertId();
        location_audit((int) $user['id'], 'threshold.version_created', ['threshold_id' => $newId, 'from' => $id], $reason);
    });
}

function change_threshold_state(array $user, int $id, string $action, string $reason): void
{
    threshold_transaction(static function (PDO $connection) use ($user, $id, $action, $reason): void {
        $threshold = threshold_in_scope($user, $id);
        $status = $threshold['approval_status'];
        $now = time();
        if ($action === 'submit_threshold') {
            if (!in_array($status, ['draft', 'rejected'], true)) {
                throw new InvalidArgumentException('Hanya draf atau ambang ditolak yang dapat diajukan.');
            }
            $new = 'pending';
        } elseif ($action === 'approve_threshold' || $action === 'reject_threshold') {
            if ($status !== 'pending') {
                throw new InvalidArgumentException('Ambang tidak sedang menunggu persetujuan.');
            }
            if ((int) $threshold['created_by'] === (int) $user['id']) {
                throw new InvalidArgumentException('Pembuat ambang tidak dapat menyetujui atau menolak ambangnya sendiri (persetujuan dua pihak).');
            }
            $new = $action === 'approve_threshold' ? 'approved' : 'rejected';
        } elseif ($action === 'retire_threshold') {
            if ($status !== 'approved') {
                throw new InvalidArgumentException('Hanya ambang yang disetujui yang dapat dinonaktifkan.');
            }
            $new = 'retired';
        } else {
            throw new InvalidArgumentException('Tindakan tidak dikenal.');
        }
        $decision = in_array($new, ['approved', 'rejected', 'retired'], true);
        $connection->prepare(
            'UPDATE thresholds SET approval_status = :status, updated_at = :now,
                    decided_by = CASE WHEN :decision THEN :actor ELSE decided_by END,
                    decided_at = CASE WHEN :decision THEN :now ELSE decided_at END,
                    decision_reason = CASE WHEN :decision THEN :reason ELSE decision_reason END
             WHERE id = :id'
        )->execute([
            'status' => $new, 'now' => $now, 'decision' => $decision ? 1 : 0,
            'actor' => $user['id'], 'reason' => $reason, 'id' => $id,
        ]);
        if ($new === 'approved' && $threshold['supersedes_id'] !== null) {
            $connection->prepare(
                'UPDATE thresholds SET approval_status = "superseded", updated_at = :now
                 WHERE id = :old AND approval_status = "approved"'
            )->execute(['now' => $now, 'old' => $threshold['supersedes_id']]);
        }
        location_audit((int) $user['id'], 'threshold.' . $new, ['threshold_id' => $id, 'from' => $status], $reason);
    });
}

function delete_threshold(array $user, int $id, string $reason): void
{
    threshold_transaction(static function (PDO $connection) use ($user, $id, $reason): void {
        $threshold = threshold_in_scope($user, $id);
        if (!in_array($threshold['approval_status'], ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException('Hanya draf atau ambang ditolak yang dapat dihapus.');
        }
        $connection->prepare('DELETE FROM thresholds WHERE id = :id')->execute(['id' => $id]);
        location_audit((int) $user['id'], 'threshold.deleted', $threshold, $reason);
    });
}
