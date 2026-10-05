<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_is_valid(): bool
{
    $submitted = $_POST['csrf_token'] ?? null;
    return is_string($submitted)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['flash'][$key] = $value;
        return null;
    }

    $message = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return is_string($message) ? $message : null;
}

function post_value(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? $value : '';
}

function signed_in_user(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId)) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT id, name, email, role, status FROM users WHERE id = :id'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    return $user;
}

function sign_in(int $userId): void
{
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to secure the session.');
    }
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['csrf_token']);
}

function verify_stored_password(string $password, string $storedHash): bool
{
    if (password_verify($password, $storedHash)) {
        return true;
    }

    return preg_match('/^[a-f0-9]{64}$/i', $storedHash) === 1
        && hash_equals(strtolower($storedHash), hash('sha256', $password));
}

function upgrade_legacy_password_hash(int $userId, string $legacyHash, string $password): void
{
    $statement = db()->prepare(
        'UPDATE users SET password_hash = :password_hash
         WHERE id = :id AND password_hash = :legacy_hash'
    );
    $statement->execute([
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'id' => $userId,
        'legacy_hash' => $legacyHash,
    ]);
}

function role_labels(): array
{
    return [
        'system_admin' => 'Administrator sistem',
        'master_data_manager' => 'Pengelola master data',
        'operator' => 'Operator / analis',
        'observer' => 'Pimpinan / pengamat',
        'field_officer' => 'Petugas lapangan',
    ];
}

function status_labels(): array
{
    return [
        'pending' => 'Menunggu persetujuan',
        'active' => 'Aktif',
        'suspended' => 'Ditangguhkan',
    ];
}

function user_has_permission(?array $user, string $permission): bool
{
    if ($user === null || $user['status'] !== 'active') {
        return false;
    }
    if ($user['role'] === 'system_admin') {
        return true;
    }

    $permissions = [
        'dashboard' => ['master_data_manager', 'operator', 'observer', 'field_officer'],
        'manage_master_data' => ['master_data_manager'],
        'handle_alerts' => ['operator', 'field_officer'],
        'view_reports' => ['operator', 'observer'],
        'handle_assignments' => ['field_officer'],
    ];

    return in_array($user['role'], $permissions[$permission] ?? [], true);
}

function user_regions(?array $user): array
{
    if ($user === null || $user['status'] !== 'active') {
        return [];
    }

    if ($user['role'] === 'system_admin') {
        return db()->query(
            'SELECT id, code, name, parent_id FROM regions ORDER BY name COLLATE NOCASE'
        )->fetchAll();
    }

    $statement = db()->prepare(
        'WITH RECURSIVE scoped(id) AS (
            SELECT region_id FROM user_regions WHERE user_id = :user_id
            UNION
            SELECT regions.id FROM regions JOIN scoped ON regions.parent_id = scoped.id
         )
         SELECT regions.id, regions.code, regions.name, regions.parent_id
         FROM regions JOIN scoped ON regions.id = scoped.id
         ORDER BY regions.name COLLATE NOCASE'
    );
    $statement->execute(['user_id' => $user['id']]);

    return $statement->fetchAll();
}

function user_has_region_access(?array $user, int $regionId): bool
{
    if ($regionId < 1 || $user === null || $user['status'] !== 'active') {
        return false;
    }
    if ($user['role'] === 'system_admin') {
        $statement = db()->prepare('SELECT 1 FROM regions WHERE id = :id');
        $statement->execute(['id' => $regionId]);
        return (bool) $statement->fetchColumn();
    }

    $statement = db()->prepare(
        'WITH RECURSIVE scoped(id) AS (
            SELECT region_id FROM user_regions WHERE user_id = :user_id
            UNION
            SELECT regions.id FROM regions JOIN scoped ON regions.parent_id = scoped.id
         )
         SELECT 1 FROM scoped WHERE id = :region_id'
    );
    $statement->execute(['user_id' => $user['id'], 'region_id' => $regionId]);

    return (bool) $statement->fetchColumn();
}

function all_regions(): array
{
    return db()->query(
        'SELECT id, code, name, parent_id FROM regions ORDER BY name COLLATE NOCASE'
    )->fetchAll();
}

function all_users(): array
{
    $users = db()->query(
        'SELECT id, name, email, role, status, created_at
         FROM users ORDER BY created_at DESC, id DESC'
    )->fetchAll();
    $scopes = db()->query(
        'SELECT user_id, region_id FROM user_regions ORDER BY region_id'
    )->fetchAll();
    $regionIdsByUser = [];
    foreach ($scopes as $scope) {
        $regionIdsByUser[(int) $scope['user_id']][] = (int) $scope['region_id'];
    }
    foreach ($users as &$user) {
        $user['region_ids'] = $regionIdsByUser[(int) $user['id']] ?? [];
    }
    unset($user);

    return $users;
}

function create_region(
    int $actorId,
    string $code,
    string $name,
    ?int $parentId,
    string $reason
): void {
    $connection = db();
    $connection->beginTransaction();
    try {
        $insert = $connection->prepare(
            'INSERT INTO regions (code, name, parent_id, created_at)
             VALUES (:code, :name, :parent_id, :created_at)'
        );
        $insert->execute([
            'code' => $code,
            'name' => $name,
            'parent_id' => $parentId,
            'created_at' => time(),
        ]);
        $regionId = (int) $connection->lastInsertId();
        $audit = $connection->prepare(
            'INSERT INTO access_audit_log (actor_id, action, details, reason, created_at)
             VALUES (:actor_id, :action, :details, :reason, :created_at)'
        );
        $audit->execute([
            'actor_id' => $actorId,
            'action' => 'region.created',
            'details' => json_encode([
                'region_id' => $regionId,
                'code' => $code,
                'name' => $name,
                'parent_id' => $parentId,
            ], JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'created_at' => time(),
        ]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function update_user_access(
    int $actorId,
    int $targetId,
    string $role,
    string $status,
    array $regionIds,
    string $reason
): void {
    if (!array_key_exists($role, role_labels()) || !array_key_exists($status, status_labels())) {
        throw new InvalidArgumentException('Peran atau status akun tidak valid.');
    }

        $regionIds = array_values(array_unique(array_map('intval', $regionIds)));
        if (count($regionIds) !== count(array_filter($regionIds, static fn (int $id): bool => $id > 0))) {
            throw new InvalidArgumentException('Cakupan wilayah tidak valid.');
        }

        $connection = db();
        $connection->beginTransaction();
        try {
            $userQuery = $connection->prepare(
                'SELECT id, role, status FROM users WHERE id = :id'
            );
            $userQuery->execute(['id' => $targetId]);
            $before = $userQuery->fetch();
            if (!$before) {
                throw new InvalidArgumentException('Akun tidak ditemukan.');
            }

            if ($before['role'] === 'system_admin'
                && $before['status'] === 'active'
                && ($role !== 'system_admin' || $status !== 'active')) {
                $admins = $connection->prepare(
                    "SELECT COUNT(*) FROM users
                     WHERE role = 'system_admin' AND status = 'active' AND id != :id"
                );
                $admins->execute(['id' => $targetId]);
                if ((int) $admins->fetchColumn() === 0) {
                    throw new InvalidArgumentException(
                        'Tidak dapat menonaktifkan atau mengubah satu-satunya administrator aktif.'
                    );
                }
            }

            $validRegionIds = [];
            if ($regionIds !== []) {
                $placeholders = implode(',', array_fill(0, count($regionIds), '?'));
                $regionQuery = $connection->prepare(
                    "SELECT id FROM regions WHERE id IN ({$placeholders})"
                );
                $regionQuery->execute($regionIds);
                $validRegionIds = array_map('intval', $regionQuery->fetchAll(PDO::FETCH_COLUMN));
                if (count($validRegionIds) !== count($regionIds)) {
                    throw new InvalidArgumentException('Satu atau lebih wilayah tidak ditemukan.');
                }
            }

            $previousScopes = $connection->prepare(
                'SELECT region_id FROM user_regions WHERE user_id = :user_id ORDER BY region_id'
            );
            $previousScopes->execute(['user_id' => $targetId]);
            $beforeScopes = array_map('intval', $previousScopes->fetchAll(PDO::FETCH_COLUMN));
            sort($validRegionIds);

            $update = $connection->prepare(
                'UPDATE users SET role = :role, status = :status WHERE id = :id'
            );
            $update->execute(['role' => $role, 'status' => $status, 'id' => $targetId]);
            $activeAdminCount = (int) $connection->query(
                "SELECT COUNT(*) FROM users WHERE role = 'system_admin' AND status = 'active'"
            )->fetchColumn();
            if ($activeAdminCount === 0) {
                throw new InvalidArgumentException(
                    'Sistem harus memiliki setidaknya satu administrator aktif.'
                );
            }
            $deleteScopes = $connection->prepare('DELETE FROM user_regions WHERE user_id = :user_id');
            $deleteScopes->execute(['user_id' => $targetId]);
            $addScope = $connection->prepare(
                'INSERT INTO user_regions (user_id, region_id) VALUES (:user_id, :region_id)'
            );
            foreach ($validRegionIds as $regionId) {
                $addScope->execute(['user_id' => $targetId, 'region_id' => $regionId]);
            }

            $audit = $connection->prepare(
                'INSERT INTO access_audit_log
                 (actor_id, target_user_id, action, details, reason, created_at)
                 VALUES (:actor_id, :target_user_id, :action, :details, :reason, :created_at)'
            );
            $audit->execute([
                'actor_id' => $actorId,
                'target_user_id' => $targetId,
                'action' => 'user.access_updated',
                'details' => json_encode([
                    'before' => [
                        'role' => $before['role'],
                        'status' => $before['status'],
                        'region_ids' => $beforeScopes,
                    ],
                    'after' => [
                        'role' => $role,
                        'status' => $status,
                        'region_ids' => $validRegionIds,
                    ],
                ], JSON_THROW_ON_ERROR),
                'reason' => $reason,
                'created_at' => time(),
            ]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
}

function alert_hazards(): array
{
    return [
        'weather' => 'Cuaca',
        'tornado' => 'Tornado',
        'river_flood' => 'Banjir sungai',
        'coastal_tide' => 'Pasang surut pantai/muara',
    ];
}

function alert_severities(): array
{
    return [
        'watch' => 'Waspada',
        'alert' => 'Siaga',
        'warning' => 'Awas',
    ];
}

function alert_event_by_id(int $eventId): ?array
{
    $statement = db()->prepare(
        'SELECT events.*, regions.name AS region_name, regions.code AS region_code,
                assigned.name AS assignee_name, creator.name AS creator_name,
                acknowledger.name AS acknowledger_name
         FROM alert_events AS events
         JOIN regions ON regions.id = events.region_id
         LEFT JOIN users AS assigned ON assigned.id = events.assigned_to
         LEFT JOIN users AS creator ON creator.id = events.created_by
         LEFT JOIN users AS acknowledger ON acknowledger.id = events.acknowledged_by
         WHERE events.id = :id'
    );
    $statement->execute(['id' => $eventId]);
    $event = $statement->fetch();

    return $event ?: null;
}

function alert_event_is_visible(?array $user, int $eventId): bool
{
    $event = alert_event_by_id($eventId);
    return $event !== null && user_has_region_access($user, (int) $event['region_id']);
}

function list_alert_events(array $user, array $filters = [], bool $activeOnly = false): array
{
    $conditions = [];
    $params = [];
    $regionIds = array_map(
        static fn (array $region): int => (int) $region['id'],
        user_regions($user)
    );

    if ($regionIds === []) {
        return [];
    }

    $regionPlaceholders = [];
    foreach ($regionIds as $index => $regionId) {
        $key = ':region_' . $index;
        $regionPlaceholders[] = $key;
        $params[$key] = $regionId;
    }
    $conditions[] = 'events.region_id IN (' . implode(', ', $regionPlaceholders) . ')';

    if ($activeOnly) {
        $conditions[] = "events.handling_status = 'open'";
        if ($user['role'] === 'field_officer') {
            $conditions[] = 'events.assigned_to = :assigned_user';
            $params[':assigned_user'] = (int) $user['id'];
        }
    } elseif (isset($filters['status']) && in_array($filters['status'], ['open', 'closed'], true)) {
        $conditions[] = 'events.handling_status = :status';
        $params[':status'] = $filters['status'];
    }
    if (isset($filters['hazard'])
        && is_string($filters['hazard'])
        && array_key_exists($filters['hazard'], alert_hazards())) {
        $conditions[] = 'events.hazard_type = :hazard';
        $params[':hazard'] = $filters['hazard'];
    }
    if (isset($filters['severity'])
        && is_string($filters['severity'])
        && array_key_exists($filters['severity'], alert_severities())) {
        $conditions[] = 'events.severity = :severity';
        $params[':severity'] = $filters['severity'];
    }
    if (isset($filters['region_id'])
        && is_string($filters['region_id'])
        && ctype_digit($filters['region_id'])) {
        $regionId = (int) $filters['region_id'];
        if (in_array($regionId, $regionIds, true)) {
            $conditions[] = 'events.region_id = :selected_region';
            $params[':selected_region'] = $regionId;
        }
    }
    if (isset($filters['from'])
        && is_string($filters['from'])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
        $conditions[] = 'events.started_at >= :from_time';
        $params[':from_time'] = strtotime((string) $filters['from'] . ' 00:00:00 UTC');
    }
    if (isset($filters['to'])
        && is_string($filters['to'])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
        $conditions[] = 'events.started_at < :to_time';
        $params[':to_time'] = strtotime((string) $filters['to'] . ' +1 day 00:00:00 UTC');
    }
    $search = isset($filters['q']) && is_string($filters['q'])
        ? trim($filters['q'])
        : '';
    if ($search !== '') {
        $conditions[] = '(events.location_name LIKE :search
            OR events.trigger_indicator LIKE :search
            OR events.trigger_value LIKE :search
            OR events.source_label LIKE :search
            OR regions.name LIKE :search
            OR regions.code LIKE :search)';
        $params[':search'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
    }

    $sql = 'SELECT events.*, regions.name AS region_name, regions.code AS region_code,
                   assigned.name AS assignee_name, creator.name AS creator_name,
                   acknowledger.name AS acknowledger_name
            FROM alert_events AS events
            JOIN regions ON regions.id = events.region_id
            LEFT JOIN users AS assigned ON assigned.id = events.assigned_to
            LEFT JOIN users AS creator ON creator.id = events.created_by
            LEFT JOIN users AS acknowledger ON acknowledger.id = events.acknowledged_by
            WHERE ' . implode(' AND ', $conditions)
        . ' ORDER BY CASE events.severity WHEN "warning" THEN 1 WHEN "alert" THEN 2 ELSE 3 END,
                    events.started_at DESC';
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function count_alert_events(array $user, string $status, ?string $severity = null): int
{
    $regionIds = array_map(
        static fn (array $region): int => (int) $region['id'],
        user_regions($user)
    );
    if ($regionIds === []) {
        return 0;
    }
    $placeholders = implode(', ', array_fill(0, count($regionIds), '?'));
    $sql = 'SELECT COUNT(*) FROM alert_events
            WHERE handling_status = ? AND region_id IN (' . $placeholders . ')';
    $params = [$status, ...$regionIds];
    if ($severity !== null) {
        $sql .= ' AND severity = ?';
        $params[] = $severity;
    }
    if ($user['role'] === 'field_officer') {
        $sql .= ' AND assigned_to = ?';
        $params[] = (int) $user['id'];
    }
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

function alert_event_assignees(int $regionId): array
{
    $users = db()->query(
        "SELECT id, name, role, status FROM users
         WHERE status = 'active'
           AND role IN ('system_admin', 'operator', 'field_officer')
         ORDER BY name COLLATE NOCASE"
    )->fetchAll();

    return array_values(array_filter(
        $users,
        static fn (array $candidate): bool => user_has_region_access($candidate, $regionId)
    ));
}

function alert_event_timeline(int $eventId): array
{
    $statement = db()->prepare(
        'SELECT event_log.*, users.name AS actor_name
         FROM alert_event_log AS event_log
         LEFT JOIN users ON users.id = event_log.actor_id
         WHERE event_log.event_id = :event_id
         ORDER BY event_log.created_at DESC, event_log.id DESC'
    );
    $statement->execute(['event_id' => $eventId]);

    return $statement->fetchAll();
}

function create_alert_event(array $event, int $actorId): int
{
    if (!array_key_exists($event['hazard_type'], alert_hazards())
        || !array_key_exists($event['severity'], alert_severities())) {
        throw new InvalidArgumentException('Jenis bahaya atau tingkat peringatan tidak valid.');
    }
    if (!user_has_region_access(
        signed_in_user(),
        (int) $event['region_id']
    )) {
        throw new InvalidArgumentException('Anda tidak memiliki akses ke wilayah tersebut.');
    }

    $now = time();
    $connection = db();
    $connection->beginTransaction();
    try {
        $insert = $connection->prepare(
            'INSERT INTO alert_events
             (hazard_type, region_id, location_name, severity, trigger_indicator,
              trigger_value, threshold_value, source_label, created_by, started_at,
              created_at, updated_at)
             VALUES (:hazard_type, :region_id, :location_name, :severity, :trigger_indicator,
                     :trigger_value, :threshold_value, :source_label, :created_by, :started_at,
                     :created_at, :updated_at)'
        );
        $insert->execute([
            'hazard_type' => $event['hazard_type'],
            'region_id' => $event['region_id'],
            'location_name' => $event['location_name'],
            'severity' => $event['severity'],
            'trigger_indicator' => $event['trigger_indicator'],
            'trigger_value' => $event['trigger_value'],
            'threshold_value' => $event['threshold_value'],
            'source_label' => $event['source_label'],
            'created_by' => $actorId,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $eventId = (int) $connection->lastInsertId();
        $log = $connection->prepare(
            'INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
             VALUES (:event_id, :actor_id, :action, :details, :created_at)'
        );
        $log->execute([
            'event_id' => $eventId,
            'actor_id' => $actorId,
            'action' => 'created',
            'details' => 'Peringatan dicatat secara manual.',
            'created_at' => $now,
        ]);
        $connection->commit();

        return $eventId;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function handle_alert_event(int $eventId, int $actorId, string $action, array $data = []): void
{
    $event = alert_event_by_id($eventId);
    if ($event === null) {
        throw new InvalidArgumentException('Kejadian tidak ditemukan.');
    }
    $actorStatement = db()->prepare('SELECT id, role, status FROM users WHERE id = :id');
    $actorStatement->execute(['id' => $actorId]);
    $actor = $actorStatement->fetch();
    if (!$actor || $actor['status'] !== 'active'
        || !user_has_region_access($actor, (int) $event['region_id'])) {
        throw new InvalidArgumentException('Anda tidak memiliki akses ke kejadian ini.');
    }

    $isOperator = in_array($actor['role'], ['system_admin', 'operator'], true);
    if (!$isOperator && !(
        $actor['role'] === 'field_officer'
        && (int) $event['assigned_to'] === $actorId
        && $action === 'note'
    )) {
        throw new InvalidArgumentException('Peran Anda tidak diizinkan melakukan tindakan ini.');
    }
    if ($event['handling_status'] !== 'open') {
        throw new InvalidArgumentException('Kejadian yang sudah ditutup tidak dapat diubah.');
    }

    $now = time();
    $details = '';
    $connection = db();
    $connection->beginTransaction();
    try {
        if ($action === 'acknowledge') {
            if ($event['acknowledged_at'] !== null) {
                throw new InvalidArgumentException('Kejadian ini sudah diakui.');
            }
            $update = $connection->prepare(
                'UPDATE alert_events SET acknowledged_by = :actor, acknowledged_at = :now,
                 updated_at = :now WHERE id = :id AND handling_status = "open"'
            );
            $update->execute(['actor' => $actorId, 'now' => $now, 'id' => $eventId]);
            $details = 'Kejadian diakui oleh petugas.';
        } elseif ($action === 'assign') {
            $assigneeId = (int) ($data['assignee_id'] ?? 0);
            if ($assigneeId < 1) {
                throw new InvalidArgumentException('Pilih petugas penanggung jawab.');
            }
            $assigneeStatement = db()->prepare(
                "SELECT id, role, status FROM users
                 WHERE id = :id AND status = 'active'
                   AND role IN ('system_admin', 'operator', 'field_officer')"
            );
            $assigneeStatement->execute(['id' => $assigneeId]);
            $assignee = $assigneeStatement->fetch();
            if (!$assignee || !user_has_region_access($assignee, (int) $event['region_id'])) {
                throw new InvalidArgumentException('Petugas harus aktif dan memiliki cakupan wilayah kejadian.');
            }
            $update = $connection->prepare(
                'UPDATE alert_events SET assigned_to = :assignee, updated_at = :now
                 WHERE id = :id AND handling_status = "open"'
            );
            $update->execute(['assignee' => $assigneeId, 'now' => $now, 'id' => $eventId]);
            $details = 'Penanggung jawab diubah menjadi pengguna #' . $assignee['id'] . '.';
        } elseif ($action === 'escalate') {
            $nextSeverity = match ($event['severity']) {
                'watch' => 'alert',
                'alert' => 'warning',
                default => null,
            };
            if ($nextSeverity === null) {
                throw new InvalidArgumentException('Peringatan sudah berada pada tingkat tertinggi.');
            }
            $update = $connection->prepare(
                'UPDATE alert_events SET severity = :severity, updated_at = :now
                 WHERE id = :id AND handling_status = "open"'
            );
            $update->execute(['severity' => $nextSeverity, 'now' => $now, 'id' => $eventId]);
            $details = 'Tingkat peringatan dinaikkan menjadi ' . alert_severities()[$nextSeverity] . '.';
        } elseif ($action === 'close') {
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '') {
                throw new InvalidArgumentException('Alasan penutupan wajib diisi.');
            }
            $update = $connection->prepare(
                'UPDATE alert_events SET handling_status = "closed", closed_at = :now,
                 close_reason = :reason, updated_at = :now
                 WHERE id = :id AND handling_status = "open"'
            );
            $update->execute([
                'now' => $now,
                'reason' => $reason,
                'id' => $eventId,
            ]);
            $details = $reason;
        } elseif ($action === 'note') {
            $note = trim((string) ($data['note'] ?? ''));
            if ($note === '') {
                throw new InvalidArgumentException('Catatan tidak boleh kosong.');
            }
            $update = $connection->prepare(
                'UPDATE alert_events SET updated_at = :now WHERE id = :id'
            );
            $update->execute(['now' => $now, 'id' => $eventId]);
            $details = $note;
        } else {
            throw new InvalidArgumentException('Tindakan kejadian tidak dikenal.');
        }

        $log = $connection->prepare(
            'INSERT INTO alert_event_log (event_id, actor_id, action, details, created_at)
             VALUES (:event_id, :actor_id, :action, :details, :created_at)'
        );
        $log->execute([
            'event_id' => $eventId,
            'actor_id' => $actorId,
            'action' => $action,
            'details' => $details,
            'created_at' => $now,
        ]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function send_password_reset(string $email, string $name, string $token): bool
{
    $baseUrl = env_value('APP_BASE_URL');
    $from = env_value('APP_MAIL_FROM');
    if ($baseUrl === null || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        error_log('Password reset email not sent: configure APP_BASE_URL and APP_MAIL_FROM.');
        return false;
    }

    $resetUrl = rtrim($baseUrl, '/') . '/?page=reset-password&token=' . rawurlencode($token);
    $subject = 'Reset password Early Warning System';
    $body = "Halo {$name},\n\n"
        . "Kami menerima permintaan untuk mengatur ulang password akun EWS Anda.\n"
        . "Buka tautan berikut dalam 60 menit:\n{$resetUrl}\n\n"
        . "Jika Anda tidak meminta reset password, abaikan email ini.\n";
    $headers = [
        'From' => $from,
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'PHP/' . PHP_VERSION,
    ];

    return mail($email, $subject, $body, $headers);
}

function issue_password_reset(string $email): void
{
    $statement = db()->prepare('SELECT id, name, email FROM users WHERE email = :email');
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();
    if (!$user) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $now = time();
    $connection = db();
    $connection->beginTransaction();
    try {
        $delete = $connection->prepare('DELETE FROM password_reset_tokens WHERE user_id = :id');
        $delete->execute(['id' => $user['id']]);
        $insert = $connection->prepare(
            'INSERT INTO password_reset_tokens (token_hash, user_id, expires_at, created_at)
             VALUES (:token_hash, :user_id, :expires_at, :created_at)'
        );
        $insert->execute([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user['id'],
            'expires_at' => $now + 3600,
            'created_at' => $now,
        ]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }

    if (!send_password_reset($user['email'], $user['name'], $token)) {
        error_log('Password reset email delivery failed for user ID ' . $user['id'] . '.');
    }
}

function valid_reset_token(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT 1 FROM password_reset_tokens
         WHERE token_hash = :token_hash AND expires_at > :now'
    );
    $statement->execute([
        'token_hash' => hash('sha256', $token),
        'now' => time(),
    ]);

    return (bool) $statement->fetchColumn();
}

function update_password_from_token(string $token, string $password): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    $connection = db();
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare(
            'SELECT user_id FROM password_reset_tokens
             WHERE token_hash = :token_hash AND expires_at > :now'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'now' => time(),
        ]);
        $userId = $statement->fetchColumn();
        if ($userId === false) {
            $connection->rollBack();
            return false;
        }

        $update = $connection->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
        );
        $update->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
        $delete = $connection->prepare('DELETE FROM password_reset_tokens WHERE user_id = :id');
        $delete->execute(['id' => $userId]);
        $connection->commit();

        return true;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}
