<?php

declare(strict_types=1);

function ldap_load_env_file(): void
{
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = preg_replace('/^["\'"]|["\'"]$/', '', $value) ?: $value;

        if (!getenv($key)) {
            putenv($key . '=' . $value);
        }
    }
}

ldap_load_env_file();

function ldap_audit_sanitize_argv(array $argv): array
{
    $sanitized = [];

    foreach ($argv as $index => $value) {
        $text = (string) $value;
        $prev = $index > 0 ? strtolower((string) $argv[$index - 1]) : '';
        $lower = strtolower($text);

        // Evita guardar secretos en texto plano en caso de que se pasen por CLI.
        if (
            strpos($lower, 'password=') !== false
            || strpos($lower, 'token=') !== false
            || strpos($lower, 'secret=') !== false
            || in_array($prev, ['--password', '-p', '--token', '--secret'], true)
        ) {
            $sanitized[] = '***';
            continue;
        }

        $sanitized[] = $text;
    }

    return $sanitized;
}

function ldap_audit_build_command_line(array $argv): string
{
    if ($argv === []) {
        return '';
    }

    $parts = array_map(static function ($arg): string {
        return escapeshellarg((string) $arg);
    }, $argv);

    return implode(' ', $parts);
}

function ldap_audit_write(string $event, array $data = []): void
{
    $config = ldap_script_config();
    if (!$config['audit_enabled']) {
        return;
    }

    $logPath = (string) $config['audit_log_path'];
    $logDir = dirname($logPath);

    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        return;
    }

    $argv = ldap_audit_sanitize_argv($_SERVER['argv'] ?? []);
    $tty = null;
    if (function_exists('posix_ttyname') && defined('STDIN')) {
        $ttyName = @posix_ttyname(STDIN);
        $tty = $ttyName !== false ? $ttyName : null;
    }

    $record = [
        'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s\\Z'),
        'event' => $event,
        'script' => $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''),
        'user' => get_current_user(),
        'os_user' => getenv('USER') ?: null,
        'sudo_user' => getenv('SUDO_USER') ?: null,
        'hostname' => gethostname() ?: null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'tty' => $tty,
        'pid' => getmypid(),
        'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
        'php_binary' => PHP_BINARY,
        'cwd' => getcwd() ?: null,
        'argv' => $argv,
        'command_line' => ldap_audit_build_command_line($argv),
        'data' => $data,
    ];

    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    @file_put_contents($logPath, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function ldap_audit_action(string $action, array $data = []): void
{
    ldap_audit_write('action', [
        'action' => $action,
        'details' => $data,
    ]);
}

// Buffer interno de salida para incluirlo en el evento 'finish' del log.
$GLOBALS['_ldap_audit_output'] = [];

function ldap_audit_capture_line(string $stream, string $text): void
{
    $GLOBALS['_ldap_audit_output'][] = ['stream' => $stream, 'line' => $text];
}

function ldap_audit_init(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }
    $initialized = true;

    ldap_audit_write('start');

    register_shutdown_function(static function (): void {
        $output = $GLOBALS['_ldap_audit_output'] ?? [];

        $lastError = error_get_last();
        if ($lastError !== null && in_array((int) $lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            ldap_audit_write('fatal_error', [
                'message' => (string) ($lastError['message'] ?? ''),
                'file' => (string) ($lastError['file'] ?? ''),
                'line' => (int) ($lastError['line'] ?? 0),
                'output' => $output,
            ]);
            return;
        }

        ldap_audit_write('finish', ['output' => $output]);
    });

    set_exception_handler(static function (Throwable $exception): void {
        ldap_audit_write('exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        fwrite(STDERR, 'Error no controlado: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    });
}

ldap_audit_init();

function ldap_script_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
}

function ldap_script_config(): array
{
    return [
        'host' => ldap_script_env('LDAP_HOST', 'ldap1.cah.gov.py'),
        'port' => (int) ldap_script_env('LDAP_PORT', '389'),
        'base_dn' => ldap_script_env('LDAP_BASE_DN', 'dc=cah,dc=gov,dc=py'),
        'group_base_dn' => ldap_script_env('LDAP_GROUP_BASE_DN', ldap_script_env('LDAP_BASE_DN', 'dc=cah,dc=gov,dc=py')),
        'bind_dn' => ldap_script_env('LDAP_BIND_DN'),
        'bind_password' => ldap_script_env('LDAP_BIND_PASSWORD'),
        'timezone' => ldap_script_env('APP_TIMEZONE', 'America/Asuncion'),
        'timeout' => (int) ldap_script_env('LDAP_TIMEOUT', '10'),
        'ssl' => ldap_script_env('LDAP_SSL', 'false') === 'true',
        'output_dir' => __DIR__ . '/../docs/ldap',
        'audit_enabled' => ldap_script_env('AUDIT_ENABLED', 'true') === 'true',
        'audit_log_path' => ldap_script_env('AUDIT_LOG_PATH', __DIR__ . '/../docs/ldap/execution_ldap_audit.log'),
    ];
}

function ldap_require_extension(): void
{
    if (!extension_loaded('ldap')) {
        fwrite(STDERR, "La extension LDAP de PHP no esta habilitada.\n");
        exit(1);
    }
}

function ldap_console_line(string $text = ''): void
{
    fwrite(STDOUT, $text . PHP_EOL);
    ldap_audit_capture_line('stdout', $text);
}

function ldap_console_error(string $text): void
{
    fwrite(STDERR, $text . PHP_EOL);
    ldap_audit_capture_line('stderr', $text);
}

function ldap_connect_and_bind(array $config)
{
    ldap_require_extension();

    $conn = @ldap_connect($config['host'], $config['port']);
    if (!$conn) {
        ldap_console_error("No se puede conectar a {$config['host']}:{$config['port']}");
        exit(1);
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

    if (!@ldap_bind($conn, $config['bind_dn'], $config['bind_password'])) {
        ldap_console_error('Fallo de autenticacion LDAP');
        ldap_close($conn);
        exit(1);
    }

    return $conn;
}

function ldap_output_path(string $filename): string
{
    $config = ldap_script_config();

    if (!is_dir($config['output_dir']) && !mkdir($config['output_dir'], 0775, true) && !is_dir($config['output_dir'])) {
        ldap_console_error('No se pudo crear el directorio de salida: ' . $config['output_dir']);
        exit(1);
    }

    return $config['output_dir'] . '/' . $filename;
}

function ldap_date_to_timestamp(string $ldapDate): int
{
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $ldapDate, $m)) {
        return 0;
    }

    return gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
}

function ldap_parse_generalized_time(?string $raw): ?DateTimeImmutable
{
    if (!$raw) {
        return null;
    }

    $normalized = preg_replace('/\.\d+Z$/', 'Z', $raw);
    if (!is_string($normalized)) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('YmdHis\Z', $normalized, new DateTimeZone('UTC'));

    return $dt instanceof DateTimeImmutable ? $dt : null;
}

function ldap_format_date_es(DateTimeImmutable $date, ?string $timezone = null): string
{
    $localDate = $timezone ? $date->setTimezone(new DateTimeZone($timezone)) : $date;
    $days = [1 => 'lunes', 2 => 'martes', 3 => 'miercoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sabado', 7 => 'domingo'];
    $months = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

    return sprintf(
        '%s %s de %s de %s, %s',
        $days[(int) $localDate->format('N')] ?? '',
        $localDate->format('d'),
        $months[(int) $localDate->format('n')] ?? '',
        $localDate->format('Y'),
        $localDate->format('H:i:s')
    );
}

function ldap_html_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ldap_collect_values(array $entry, string $key): array
{
    if (!isset($entry[$key]) || !is_array($entry[$key])) {
        return [];
    }

    $values = [];
    $count = (int) ($entry[$key]['count'] ?? 0);

    for ($index = 0; $index < $count; $index++) {
        if (isset($entry[$key][$index])) {
            $values[] = (string) $entry[$key][$index];
        }
    }

    return $values;
}

function ldap_write_file(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) {
        ldap_console_error('No se pudo escribir el archivo: ' . $path);
        exit(1);
    }
}
