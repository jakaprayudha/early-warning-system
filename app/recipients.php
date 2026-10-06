<?php

declare(strict_types=1);

function mask_email(string $email): string
{
    if ($email === '' || !str_contains($email, '@')) {
        return '—';
    }
    [$local, $domain] = explode('@', $email, 2);

    return mb_substr($local, 0, 1) . str_repeat('•', max(2, min(5, mb_strlen($local) - 1))) . '@' . $domain;
}

function mask_phone(string $phone): string
{
    return $phone === '' ? '—' : substr($phone, 0, 4) . str_repeat('•', max(2, strlen($phone) - 7)) . substr($phone, -3);
}

function group_usage_count(string $name): int
{
    $statement = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM alert_rules WHERE recipient_group = :n COLLATE NOCASE)
              + (SELECT COUNT(*) FROM alert_rule_escalations WHERE recipient_group = :n COLLATE NOCASE)'
    );
    $statement->execute(['n' => $name]);

    return (int) $statement->fetchColumn();
}

function list_recipient_groups(array $user, array $filters = []): array
{
    $ids = array_map(static fn(array $region): int => (int) $region['id'], user_regions($user));
    if ($ids === []) {
        return [];
    }
    $where = ['recipient_groups.region_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'];
    $params = $ids;
    $hazard = (string) ($filters['hazard'] ?? '');
    if ($hazard !== '') {
        $where[] = 'recipient_groups.hazard_code = ?';
        $params[] = $hazard;
    }
    $statement = db()->prepare(
        'SELECT recipient_groups.*, hazard_types.name AS hazard_name, regions.name AS region_name
         FROM recipient_groups
         JOIN regions ON regions.id = recipient_groups.region_id
         LEFT JOIN hazard_types ON hazard_types.code = recipient_groups.hazard_code
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY recipient_groups.is_active DESC, recipient_groups.name COLLATE NOCASE'
    );
    $statement->execute($params);
    $groups = $statement->fetchAll();
    $members = db()->prepare('SELECT * FROM recipient_members WHERE group_id = ? ORDER BY is_active DESC, name COLLATE NOCASE');
    foreach ($groups as &$group) {
        $members->execute([$group['id']]);
        $group['members'] = $members->fetchAll();
        $group['usage'] = group_usage_count((string) $group['name']);
    }
    unset($group);

    return $groups;
}

function recipient_group_names(array $user): array
{
    return array_map(static fn(array $g): string => (string) $g['name'], array_filter(
        list_recipient_groups($user),
        static fn(array $g): bool => (int) $g['is_active'] === 1
    ));
}

function group_in_scope(array $user, int $id): array
{
    $statement = db()->prepare('SELECT * FROM recipient_groups WHERE id = :id');
    $statement->execute(['id' => $id]);
    $group = $statement->fetch();
    if (!$group) {
        throw new InvalidArgumentException('Kelompok penerima tidak ditemukan.');
    }
    if (!user_has_region_access($user, (int) $group['region_id'])) {
        throw new InvalidArgumentException('Kelompok berada di luar cakupan akses Anda.');
    }

    return $group;
}

function parse_group_input(array $post, bool $create): array
{
    $length = static fn(string $value): int => (int) preg_match_all('/./us', $value);
    $name = trim((string) ($post['name'] ?? ''));
    $description = trim((string) ($post['description'] ?? ''));
    if ($create && ($length($name) < 2 || $length($name) > 100)) {
        throw new InvalidArgumentException('Nama kelompok wajib 2–100 karakter.');
    }
    if ($length($description) > 500) {
        throw new InvalidArgumentException('Deskripsi maksimal 500 karakter.');
    }
    $regionId = filter_var($post['region_id'] ?? '', FILTER_VALIDATE_INT);
    if ($regionId === false || $regionId < 1) {
        throw new InvalidArgumentException('Wilayah wajib dipilih.');
    }
    $hazard = null;
    $rawHazard = (string) ($post['hazard_code'] ?? '');
    if ($rawHazard !== '') {
        foreach (array_keys(alert_hazards()) as $known) {
            if (strcasecmp((string) $known, $rawHazard) === 0) {
                $hazard = (string) $known;
            }
        }
        if ($hazard === null) {
            throw new InvalidArgumentException('Jenis bahaya tidak valid.');
        }
    }
    $from = trim((string) ($post['active_from'] ?? ''));
    $until = trim((string) ($post['active_until'] ?? ''));
    $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if (($from === '') !== ($until === '') || ($from !== '' && (!preg_match($time, $from) || !preg_match($time, $until)))) {
        throw new InvalidArgumentException('Jam aktif harus diisi lengkap atau dikosongkan.');
    }

    return [
        'id' => (int) ($post['group_id'] ?? 0), 'name' => $name, 'description' => $description,
        'region_id' => $regionId, 'hazard_code' => $hazard, 'channels' => parse_channels($post['channels'] ?? []),
        'active_from' => $from === '' ? null : $from, 'active_until' => $until === '' ? null : $until,
        'is_active' => ($post['is_active'] ?? '') === '1' ? 1 : 0,
    ];
}

function save_recipient_group(array $user, string $action, array $data, string $reason): void
{
    if (!user_has_region_access($user, (int) $data['region_id'])) {
        throw new InvalidArgumentException('Wilayah berada di luar cakupan akses Anda.');
    }
    rules_transaction(static function (PDO $db) use ($user, $action, $data, $reason): void {
        $now = time();
        $values = [
            'description' => $data['description'], 'region' => $data['region_id'], 'hazard' => $data['hazard_code'],
            'channels' => $data['channels'], 'from' => $data['active_from'], 'until' => $data['active_until'],
            'active' => $data['is_active'],
        ];
        if ($action === 'create_group') {
            $db->prepare(
                'INSERT INTO recipient_groups (name, description, region_id, hazard_code, channels, active_from, active_until,
                    is_active, created_by, created_at, updated_at)
                 VALUES (:name, :description, :region, :hazard, :channels, :from, :until, :active, :actor, :now, :now)'
            )->execute($values + ['name' => $data['name'], 'actor' => $user['id'], 'now' => $now]);
            $id = (int) $db->lastInsertId();
            $old = [];
            $event = 'recipient_group.created';
        } else {
            $id = (int) $data['id'];
            $old = group_in_scope($user, $id);
            $db->prepare(
                'UPDATE recipient_groups SET description = :description, region_id = :region, hazard_code = :hazard,
                    channels = :channels, active_from = :from, active_until = :until, is_active = :active, updated_at = :now
                 WHERE id = :id'
            )->execute($values + ['now' => $now, 'id' => $id]);
            $event = 'recipient_group.updated';
        }
        location_audit((int) $user['id'], $event, ['group_id' => $id, 'old' => $old, 'new' => $values], $reason);
    });
}

function delete_recipient_group(array $user, int $id, string $reason): void
{
    rules_transaction(static function (PDO $db) use ($user, $id, $reason): void {
        $group = group_in_scope($user, $id);
        if (group_usage_count((string) $group['name']) > 0) {
            throw new InvalidArgumentException('Kelompok dipakai oleh aturan/eskalasi. Nonaktifkan agar riwayat terjaga atau ganti penerimanya dulu.');
        }
        $db->prepare('DELETE FROM recipient_groups WHERE id = :id')->execute(['id' => $id]);
        location_audit((int) $user['id'], 'recipient_group.deleted', $group, $reason);
    });
}

function parse_member_input(array $post): array
{
    $length = static fn(string $value): int => (int) preg_match_all('/./us', $value);
    $name = trim((string) ($post['member_name'] ?? ''));
    $position = trim((string) ($post['position'] ?? ''));
    $email = strtolower(trim((string) ($post['email'] ?? '')));
    $phone = preg_replace('/[\s\-]/', '', trim((string) ($post['phone'] ?? ''))) ?? '';
    if ($length($name) < 2 || $length($name) > 100 || $length($position) > 100) {
        throw new InvalidArgumentException('Nama anggota wajib 2–100 karakter; jabatan maksimal 100.');
    }
    if ($email === '' && $phone === '') {
        throw new InvalidArgumentException('Isi minimal email atau nomor telepon.');
    }
    if ($email !== '' && (strlen($email) > 160 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        throw new InvalidArgumentException('Format email tidak valid.');
    }
    if ($phone !== '' && !preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
        throw new InvalidArgumentException('Nomor telepon harus 8–15 digit (boleh diawali +).');
    }

    return [
        'id' => (int) ($post['member_id'] ?? 0), 'group_id' => (int) ($post['group_id'] ?? 0),
        'name' => $name, 'position' => $position, 'email' => $email, 'phone' => $phone,
        'is_active' => ($post['is_active'] ?? '') === '1' ? 1 : 0,
    ];
}

function save_recipient_member(array $user, string $action, array $data, string $reason): void
{
    rules_transaction(static function (PDO $db) use ($user, $action, $data, $reason): void {
        $now = time();
        $values = ['name' => $data['name'], 'position' => $data['position'], 'email' => $data['email'], 'phone' => $data['phone'], 'active' => $data['is_active']];
        if ($action === 'add_member') {
            group_in_scope($user, (int) $data['group_id']);
            $db->prepare(
                'INSERT INTO recipient_members (group_id, name, position, email, phone, is_active, created_at, updated_at)
                 VALUES (:group, :name, :position, :email, :phone, :active, :now, :now)'
            )->execute($values + ['group' => $data['group_id'], 'now' => $now]);
            $id = (int) $db->lastInsertId();
            $event = 'recipient_member.created';
        } else {
            $find = $db->prepare('SELECT group_id FROM recipient_members WHERE id = :id');
            $find->execute(['id' => $data['id']]);
            $groupId = $find->fetchColumn();
            if ($groupId === false) {
                throw new InvalidArgumentException('Anggota tidak ditemukan.');
            }
            group_in_scope($user, (int) $groupId);
            $id = (int) $data['id'];
            $db->prepare(
                'UPDATE recipient_members SET name = :name, position = :position, email = :email, phone = :phone,
                    is_active = :active, updated_at = :now WHERE id = :id'
            )->execute($values + ['now' => $now, 'id' => $id]);
            $event = 'recipient_member.updated';
        }
        // Kontak pribadi tidak disalin ke audit.
        location_audit((int) $user['id'], $event, ['member_id' => $id, 'name' => $data['name'], 'active' => $data['is_active']], $reason);
    });
}

function delete_recipient_member(array $user, int $id, string $reason): void
{
    rules_transaction(static function (PDO $db) use ($user, $id, $reason): void {
        $find = $db->prepare('SELECT * FROM recipient_members WHERE id = :id');
        $find->execute(['id' => $id]);
        $member = $find->fetch();
        if (!$member) {
            throw new InvalidArgumentException('Anggota tidak ditemukan.');
        }
        group_in_scope($user, (int) $member['group_id']);
        $db->prepare('DELETE FROM recipient_members WHERE id = :id')->execute(['id' => $id]);
        location_audit((int) $user['id'], 'recipient_member.deleted', ['member_id' => $id, 'name' => $member['name']], $reason);
    });
}
