<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $input = fgets(STDIN);
    return $input === false ? '' : rtrim($input, "\r\n");
}

$connection = db();
$activeAdmins = (int) $connection->query(
    "SELECT COUNT(*) FROM users WHERE role = 'system_admin' AND status = 'active'"
)->fetchColumn();
if ($activeAdmins > 0) {
    fwrite(STDERR, "Administrator aktif sudah tersedia. Jika sedang menguji login, gunakan akun tersebut; buat akun admin tambahan dari halaman admin.\n");
    exit(1);
}

$name = trim(prompt('Nama administrator: '));
$email = strtolower(trim(prompt('Email administrator: ')));
$nameLength = preg_match_all('/./us', $name);
if ($nameLength === false || $nameLength < 1 || $nameLength > 100) {
    fwrite(STDERR, "Nama wajib diisi dan maksimal 100 karakter.\n");
    exit(1);
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Alamat email tidak valid.\n");
    exit(1);
}

$isTerminal = function_exists('stream_isatty') && stream_isatty(STDIN);
if ($isTerminal) {
    system('stty -echo');
}
try {
    $password = prompt('Password administrator (minimal 12 karakter): ');
} finally {
    if ($isTerminal) {
        system('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Password harus terdiri dari minimal 12 karakter.\n");
    exit(1);
}

$connection->beginTransaction();
try {
    $insertSql = file_get_contents(dirname(__DIR__) . '/database/create_admin.sql');
    if ($insertSql === false) {
        throw new RuntimeException('Unable to read administrator SQL template.');
    }
    $statement = $connection->prepare($insertSql);
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => time(),
    ]);
    $adminId = (int) $connection->lastInsertId();
    $audit = $connection->prepare(
        'INSERT INTO access_audit_log
         (target_user_id, action, details, reason, created_at)
         VALUES (:target_user_id, :action, :details, :reason, :created_at)'
    );
    $audit->execute([
        'target_user_id' => $adminId,
        'action' => 'user.initial_admin_created',
        'details' => json_encode([
            'role' => 'system_admin',
            'status' => 'active',
        ], JSON_THROW_ON_ERROR),
        'reason' => 'Initial administrator bootstrap via CLI',
        'created_at' => time(),
    ]);
    $connection->commit();
} catch (Throwable $error) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    throw $error;
}

fwrite(STDOUT, "Administrator berhasil dibuat. Masuk ke aplikasi dengan email yang didaftarkan.\n");
