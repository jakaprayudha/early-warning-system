<?php
declare(strict_types=1);

function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_name('ews_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        throw new RuntimeException('Unable to start the session.');
    }
}

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $databasePath = env_value(
        'EWS_DB_PATH',
        dirname(__DIR__) . '/storage/db_ews.sqlite'
    );
    $directory = dirname($databasePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the database directory.');
    }

    $connection = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL COLLATE NOCASE UNIQUE,
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            role TEXT NOT NULL DEFAULT "observer"
                CHECK (role IN ("system_admin", "master_data_manager", "operator", "observer", "field_officer")),
            status TEXT NOT NULL DEFAULT "pending"
                CHECK (status IN ("pending", "active", "suspended"))
        )'
    );
    $userColumns = $connection->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('role', $userColumns, true)) {
        $connection->exec('ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT "observer"');
    }
    if (!in_array('status', $userColumns, true)) {
        $connection->exec('ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT "active"');
    }
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL COLLATE NOCASE UNIQUE,
            name TEXT NOT NULL,
            parent_id INTEGER REFERENCES regions(id) ON DELETE RESTRICT,
            created_at INTEGER NOT NULL,
            CHECK (parent_id IS NULL OR parent_id != id)
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS user_regions (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            region_id INTEGER NOT NULL REFERENCES regions(id) ON DELETE CASCADE,
            PRIMARY KEY (user_id, region_id)
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS access_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            target_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            action TEXT NOT NULL,
            details TEXT NOT NULL,
            reason TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS password_reset_tokens (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $connection->exec(
        'CREATE INDEX IF NOT EXISTS password_reset_tokens_user_id
         ON password_reset_tokens(user_id)'
    );

    return $connection;
}
