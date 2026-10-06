<?php
declare(strict_types=1);

function smtp_config(): ?array
{
    $host = env_value('SMTP_HOST');
    $from = env_value('APP_MAIL_FROM') ?? env_value('SMTP_USER');
    if ($host === null || $from === null || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    $secure = strtolower(env_value('SMTP_SECURE', 'starttls') ?? 'starttls');

    return [
        'host' => $host,
        'port' => (int) env_value('SMTP_PORT', $secure === 'ssl' ? '465' : '587'),
        'user' => env_value('SMTP_USER', '') ?? '',
        'pass' => str_replace(' ', '', env_value('SMTP_PASS', '') ?? ''),
        'secure' => in_array($secure, ['starttls', 'ssl', 'none'], true) ? $secure : 'starttls',
        'from' => $from,
        'from_name' => env_value('APP_MAIL_FROM_NAME', 'Early Warning System') ?? 'Early Warning System',
    ];
}

function smtp_read($socket): array
{
    $code = 0;
    $text = '';
    while (($line = fgets($socket, 1024)) !== false) {
        $text .= $line;
        $code = (int) substr($line, 0, 3);
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    return [$code, trim($text)];
}

function smtp_command($socket, ?string $command, array $expected): string
{
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }
    [$code, $text] = smtp_read($socket);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP ' . $code . ': ' . substr($text, 0, 200));
    }

    return $text;
}

function mail_header_safe(string $value): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $value) ?? '');
}

// Klien SMTP minimal (STARTTLS/SSL + AUTH LOGIN) tanpa dependensi eksternal.
function send_smtp_mail(string $to, string $subject, string $body): void
{
    $config = smtp_config();
    if ($config === null) {
        throw new RuntimeException('SMTP belum dikonfigurasi (SMTP_HOST, SMTP_USER, SMTP_PASS, APP_MAIL_FROM).');
    }
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Alamat email tujuan tidak valid.');
    }
    $scheme = $config['secure'] === 'ssl' ? 'ssl' : 'tcp';
    $socket = @stream_socket_client($scheme . '://' . $config['host'] . ':' . $config['port'], $errno, $errstr, 12);
    if ($socket === false) {
        throw new RuntimeException('Tidak bisa terhubung ke SMTP: ' . $errstr);
    }
    stream_set_timeout($socket, 15);
    try {
        smtp_command($socket, null, [220]);
        $hello = parse_url('//' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost';
        smtp_command($socket, 'EHLO ' . $hello, [250]);
        if ($config['secure'] === 'starttls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Negosiasi TLS gagal.');
            }
            smtp_command($socket, 'EHLO ' . $hello, [250]);
        }
        if ($config['user'] !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode($config['user']), [334]);
            smtp_command($socket, base64_encode($config['pass']), [235]);
        }
        smtp_command($socket, 'MAIL FROM:<' . $config['from'] . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);
        $encode = static fn(string $text): string => '=?UTF-8?B?' . base64_encode($text) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $encode(mail_header_safe($config['from_name'])) . ' <' . $config['from'] . '>',
            'To: <' . $to . '>',
            'Subject: ' . $encode(mail_header_safe($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($config['from'], '@'), 1) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($socket, $message . "\r\n.\r\n");
        smtp_command($socket, null, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function send_mail_message(string $to, string $subject, string $body): bool
{
    try {
        send_smtp_mail($to, $subject, $body);

        return true;
    } catch (Throwable $error) {
        error_log('Email not sent: ' . $error->getMessage());

        return false;
    }
}
