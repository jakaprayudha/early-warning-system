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
        'handle_alerts' => ['operator'],
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
