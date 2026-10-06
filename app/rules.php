<?php

declare(strict_types=1);

function rule_channels(): array
{
    return [
        'dashboard' => 'Dashboard',
        'email' => 'Email',
        'sms' => 'SMS',
        'whatsapp' => 'WhatsApp',
        'push' => 'Push',
    ];
}

function rule_channel_list(string $csv): array
{
    return array_values(array_filter(explode(',', $csv), static fn(string $c): bool => isset(rule_channels()[$c])));
}

function list_alert_rules(array $user, array $filters = []): array
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return [];
    }
    $where = ['alert_rules.region_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
    $params = $ids;
    $hazard = (string) ($filters['hazard'] ?? '');
    if ($hazard !== '') {
        $where[] = 'alert_rules.hazard_code = ?';
        $params[] = $hazard;
    }
    $severity = (string) ($filters['severity'] ?? '');
    if (isset(alert_severities()[$severity])) {
        $where[] = 'alert_rules.severity = ?';
        $params[] = $severity;
    }
    $statement = db()->prepare(
        'SELECT alert_rules.*, hazard_types.name AS hazard_name, regions.name AS region_name
         FROM alert_rules
         JOIN hazard_types ON hazard_types.code = alert_rules.hazard_code
         JOIN regions ON regions.id = alert_rules.region_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY alert_rules.is_active DESC, hazard_types.name COLLATE NOCASE, alert_rules.name COLLATE NOCASE'
    );
    $statement->execute($params);
    $rules = $statement->fetchAll();
    $conditions = db()->prepare(
        'SELECT thresholds.*, parameters.name AS parameter_name, parameters.unit AS parameter_unit
         FROM alert_rule_conditions
         JOIN thresholds ON thresholds.id = alert_rule_conditions.threshold_id
         JOIN parameters ON parameters.id = thresholds.parameter_id
         WHERE alert_rule_conditions.rule_id = ? ORDER BY parameters.name COLLATE NOCASE'
    );
    $steps = db()->prepare('SELECT * FROM alert_rule_escalations WHERE rule_id = ? ORDER BY after_minutes, id');
    foreach ($rules as &$rule) {
        $conditions->execute([$rule['id']]);
        $rule['conditions'] = $conditions->fetchAll();
        $steps->execute([$rule['id']]);
        $rule['steps'] = $steps->fetchAll();
    }
    unset($rule);

    return $rules;
}

function rule_in_scope(array $user, int $id): array
{
    $statement = db()->prepare('SELECT * FROM alert_rules WHERE id = :id');
    $statement->execute(['id' => $id]);
    $rule = $statement->fetch();
    if (!$rule) {
        throw new InvalidArgumentException('Aturan tidak ditemukan.');
    }
    if (!user_has_region_access($user, (int) $rule['region_id'])) {
        throw new InvalidArgumentException('Aturan berada di luar cakupan akses Anda.');
    }

    return $rule;
}

function parse_channels(mixed $raw): string
{
    $selected = array_values(array_intersect(array_keys(rule_channels()), is_array($raw) ? array_map('strval', $raw) : []));
    if ($selected === []) {
        throw new InvalidArgumentException('Pilih minimal satu kanal notifikasi.');
    }

    return implode(',', $selected);
}

function parse_rule_input(array $post): array
{
    $length = static fn(string $value): int => (int) preg_match_all('/./us', $value);
    $name = trim((string) ($post['name'] ?? ''));
    $group = trim((string) ($post['recipient_group'] ?? ''));
    $notes = trim((string) ($post['notes'] ?? ''));
    if ($length($name) < 3 || $length($name) > 120) {
        throw new InvalidArgumentException('Nama aturan wajib 3–120 karakter.');
    }
    if ($length($group) < 2 || $length($group) > 100 || $length($notes) > 500) {
        throw new InvalidArgumentException('Kelompok penerima wajib 2–100 karakter; catatan maksimal 500.');
    }
    $hazard = null;
    foreach (array_keys(alert_hazards()) as $known) {
        if (strcasecmp((string) $known, (string) ($post['hazard_code'] ?? '')) === 0) {
            $hazard = (string) $known;
        }
    }
    $regionId = filter_var($post['region_id'] ?? '', FILTER_VALIDATE_INT);
    $severity = (string) ($post['severity'] ?? '');
    $mode = (string) ($post['combine_mode'] ?? '');
    if ($hazard === null || $regionId === false || $regionId < 1 || !isset(alert_severities()[$severity]) || !in_array($mode, ['all', 'any'], true)) {
        throw new InvalidArgumentException('Bahaya, wilayah, tingkat, dan mode kombinasi wajib valid.');
    }
    $repeat = filter_var($post['repeat_interval_minutes'] ?? '0', FILTER_VALIDATE_INT);
    $timeout = filter_var($post['ack_timeout_minutes'] ?? '', FILTER_VALIDATE_INT);
    if ($repeat === false || $repeat < 0 || $repeat > 10080 || $timeout === false || $timeout < 1 || $timeout > 10080) {
        throw new InvalidArgumentException('Jeda pengulangan 0–10080 menit dan batas pengakuan 1–10080 menit.');
    }
    $from = trim((string) ($post['active_from'] ?? ''));
    $until = trim((string) ($post['active_until'] ?? ''));
    $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if (($from === '') !== ($until === '') || ($from !== '' && (!preg_match($time, $from) || !preg_match($time, $until)))) {
        throw new InvalidArgumentException('Jam aktif harus diisi lengkap (mulai dan selesai) atau dikosongkan.');
    }
    $conditions = array_values(array_unique(array_filter(
        array_map('intval', is_array($post['conditions'] ?? null) ? $post['conditions'] : []),
        static fn(int $id): bool => $id > 0
    )));
    if ($conditions === []) {
        throw new InvalidArgumentException('Pilih minimal satu ambang sebagai indikator.');
    }

    return [
        'id' => (int) ($post['rule_id'] ?? 0),
        'name' => $name, 'hazard_code' => $hazard, 'region_id' => $regionId, 'severity' => $severity,
        'combine_mode' => $mode, 'recipient_group' => $group, 'channels' => parse_channels($post['channels'] ?? []),
        'repeat_interval_minutes' => $repeat, 'ack_timeout_minutes' => $timeout,
        'active_from' => $from === '' ? null : $from, 'active_until' => $until === '' ? null : $until,
        'is_active' => ($post['is_active'] ?? '') === '1' ? 1 : 0,
        'notes' => $notes, 'conditions' => $conditions,
    ];
}

function rules_transaction(callable $work): void
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

function validate_rule_targets(array $user, array $data): void
{
    if (!user_has_region_access($user, (int) $data['region_id'])) {
        throw new InvalidArgumentException('Wilayah aturan berada di luar cakupan akses Anda.');
    }
    $find = db()->prepare(
        'SELECT thresholds.region_id, thresholds.approval_status, parameters.hazard_code
         FROM thresholds JOIN parameters ON parameters.id = thresholds.parameter_id WHERE thresholds.id = :id'
    );
    foreach ($data['conditions'] as $thresholdId) {
        $find->execute(['id' => $thresholdId]);
        $threshold = $find->fetch();
        if (!$threshold || $threshold['approval_status'] !== 'approved') {
            throw new InvalidArgumentException('Hanya ambang berstatus disetujui yang dapat dipakai sebagai indikator.');
        }
        if (strcasecmp((string) $threshold['hazard_code'], (string) $data['hazard_code']) !== 0) {
            throw new InvalidArgumentException('Semua indikator harus berasal dari jenis bahaya yang sama dengan aturan.');
        }
        if (!user_has_region_access($user, (int) $threshold['region_id'])) {
            throw new InvalidArgumentException('Ambang indikator berada di luar cakupan akses Anda.');
        }
    }
}

function save_alert_rule(array $user, string $action, array $data, string $reason): void
{
    validate_rule_targets($user, $data);
    rules_transaction(static function (PDO $db) use ($user, $action, $data, $reason): void {
        $now = time();
        $values = [
            'name' => $data['name'], 'hazard_code' => $data['hazard_code'], 'region_id' => $data['region_id'],
            'severity' => $data['severity'], 'combine_mode' => $data['combine_mode'],
            'recipient_group' => $data['recipient_group'], 'channels' => $data['channels'],
            'repeat' => $data['repeat_interval_minutes'], 'timeout' => $data['ack_timeout_minutes'],
            'from' => $data['active_from'], 'until' => $data['active_until'],
            'active' => $data['is_active'], 'notes' => $data['notes'],
        ];
        if ($action === 'create_rule') {
            $db->prepare(
                'INSERT INTO alert_rules (name, hazard_code, region_id, severity, combine_mode, recipient_group, channels,
                    repeat_interval_minutes, ack_timeout_minutes, active_from, active_until, is_active, notes,
                    created_by, created_at, updated_at)
                 VALUES (:name, :hazard_code, :region_id, :severity, :combine_mode, :recipient_group, :channels,
                    :repeat, :timeout, :from, :until, :active, :notes, :actor, :now, :now)'
            )->execute($values + ['actor' => $user['id'], 'now' => $now]);
            $id = (int) $db->lastInsertId();
            $old = [];
            $auditAction = 'rule.created';
        } else {
            $id = (int) $data['id'];
            $old = rule_in_scope($user, $id);
            $db->prepare(
                'UPDATE alert_rules SET name = :name, hazard_code = :hazard_code, region_id = :region_id,
                    severity = :severity, combine_mode = :combine_mode, recipient_group = :recipient_group,
                    channels = :channels, repeat_interval_minutes = :repeat, ack_timeout_minutes = :timeout,
                    active_from = :from, active_until = :until, is_active = :active, notes = :notes,
                    updated_at = :now WHERE id = :id'
            )->execute($values + ['now' => $now, 'id' => $id]);
            $db->prepare('DELETE FROM alert_rule_conditions WHERE rule_id = :id')->execute(['id' => $id]);
            $auditAction = 'rule.updated';
        }
        $link = $db->prepare('INSERT INTO alert_rule_conditions (rule_id, threshold_id) VALUES (:rule, :threshold)');
        foreach ($data['conditions'] as $thresholdId) {
            $link->execute(['rule' => $id, 'threshold' => $thresholdId]);
        }
        location_audit((int) $user['id'], $auditAction, ['rule_id' => $id, 'old' => $old, 'new' => $values, 'conditions' => $data['conditions']], $reason);
    });
}

function delete_alert_rule(array $user, int $id, string $reason): void
{
    rules_transaction(static function (PDO $db) use ($user, $id, $reason): void {
        $rule = rule_in_scope($user, $id);
        $db->prepare('DELETE FROM alert_rules WHERE id = :id')->execute(['id' => $id]);
        location_audit((int) $user['id'], 'rule.deleted', $rule, $reason);
    });
}

function add_rule_step(array $user, array $post, string $reason): void
{
    $ruleId = (int) filter_var($post['rule_id'] ?? '', FILTER_VALIDATE_INT);
    $minutes = filter_var($post['after_minutes'] ?? '', FILTER_VALIDATE_INT);
    $group = trim((string) ($post['recipient_group'] ?? ''));
    $length = (int) preg_match_all('/./us', $group);
    if ($minutes === false || $minutes < 1 || $minutes > 10080 || $length < 2 || $length > 100) {
        throw new InvalidArgumentException('Waktu eskalasi 1–10080 menit dan penerima 2–100 karakter.');
    }
    $channels = parse_channels($post['channels'] ?? []);
    rules_transaction(static function (PDO $db) use ($user, $ruleId, $minutes, $group, $channels, $reason): void {
        $rule = rule_in_scope($user, $ruleId);
        $last = $db->prepare('SELECT MAX(after_minutes) FROM alert_rule_escalations WHERE rule_id = :id');
        $last->execute(['id' => $ruleId]);
        $previous = (int) $last->fetchColumn();
        if ($minutes <= $previous) {
            throw new InvalidArgumentException('Waktu eskalasi harus lebih lama dari langkah sebelumnya (' . $previous . ' menit).');
        }
        if ($minutes < (int) $rule['ack_timeout_minutes']) {
            throw new InvalidArgumentException('Eskalasi tidak boleh lebih cepat dari batas pengakuan (' . (int) $rule['ack_timeout_minutes'] . ' menit).');
        }
        $db->prepare('INSERT INTO alert_rule_escalations (rule_id, after_minutes, recipient_group, channels) VALUES (:r, :m, :g, :c)')
            ->execute(['r' => $ruleId, 'm' => $minutes, 'g' => $group, 'c' => $channels]);
        location_audit((int) $user['id'], 'rule.step_added', ['rule_id' => $ruleId, 'after_minutes' => $minutes, 'group' => $group], $reason);
    });
}

function delete_rule_step(array $user, int $stepId, string $reason): void
{
    rules_transaction(static function (PDO $db) use ($user, $stepId, $reason): void {
        $find = $db->prepare('SELECT * FROM alert_rule_escalations WHERE id = :id');
        $find->execute(['id' => $stepId]);
        $step = $find->fetch();
        if (!$step) {
            throw new InvalidArgumentException('Langkah eskalasi tidak ditemukan.');
        }
        rule_in_scope($user, (int) $step['rule_id']);
        $db->prepare('DELETE FROM alert_rule_escalations WHERE id = :id')->execute(['id' => $stepId]);
        location_audit((int) $user['id'], 'rule.step_deleted', $step, $reason);
    });
}
