<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

if ($argc < 3) {
    ldap_console_error('Uso: php standalone/set_user_expiration.php UID VALOR [UNIDAD] [--dry-run]');
    ldap_console_error('  UID: UID del usuario en LDAP');
    ldap_console_error('  VALOR: numero de tiempo (ej: 30)');
    ldap_console_error('  UNIDAD: s=segundos, m=minutos, h=horas, d=dias (defecto: d)');
    ldap_console_error('  --dry-run: solo mostrar cambios sin aplicar');
    exit(1);
}

$uid = trim((string) $argv[1]);
$value = (int) trim((string) $argv[2]);
$unit = isset($argv[3]) && $argv[3] !== '--dry-run' ? strtolower(trim((string) $argv[3])) : 'd';
$dryRun = in_array('--dry-run', $argv, true);

if ($uid === '') {
    ldap_console_error('UID es obligatorio.');
    exit(1);
}

$units = [
    's' => ['label' => 'segundo(s)', 'seconds' => 1],
    'm' => ['label' => 'minuto(s)', 'seconds' => 60],
    'h' => ['label' => 'hora(s)', 'seconds' => 3600],
    'd' => ['label' => 'dia(s)', 'seconds' => 86400],
];

if (!isset($units[$unit])) {
    ldap_console_error("Unidad '{$unit}' no valida. Usa: s (segundos), m (minutos), h (horas), d (dias).");
    exit(1);
}

if ($value < 0) {
    ldap_console_error('El valor no puede ser negativo. Usa 0 o mayor.');
    exit(1);
}

ldap_audit_action('set_user_expiration_requested', [
    'uid' => $uid,
    'value' => $value,
    'unit' => $unit,
    'dry_run' => $dryRun,
]);

$config = ldap_script_config();
$conn = ldap_connect_and_bind($config);

$safeUid = ldap_escape($uid, '', LDAP_ESCAPE_FILTER);
$filter = "(&(objectClass=inetOrgPerson)(uid={$safeUid}))";
$attrs = ['dn', 'uid', 'cn', 'mail', 'passwordExpirationTime'];

$search = ldap_search($conn, $config['base_dn'], $filter, $attrs);
if (!$search) {
    ldap_console_error('Error en busqueda LDAP: ' . ldap_error($conn));
    ldap_close($conn);
    exit(1);
}

$entries = ldap_get_entries($conn, $search);
if (($entries['count'] ?? 0) === 0) {
    ldap_audit_action('set_user_expiration_user_not_found', ['uid' => $uid]);
    ldap_console_error("Usuario '{$uid}' no encontrado en LDAP.");
    ldap_close($conn);
    exit(1);
}

if (($entries['count'] ?? 0) > 1) {
    ldap_audit_action('set_user_expiration_multiple_users', ['uid' => $uid, 'count' => (int) ($entries['count'] ?? 0)]);
    ldap_console_error("Se encontraron multiples usuarios para UID '{$uid}'. Operacion cancelada.");
    ldap_close($conn);
    exit(1);
}

$entry = $entries[0];
$dn = $entry['dn'] ?? null;
if (!$dn) {
    ldap_console_error('No se pudo obtener DN del usuario.');
    ldap_close($conn);
    exit(1);
}

$cn = $entry['cn'][0] ?? 'desconocido';
$mail = $entry['mail'][0] ?? 'sin email';
$currentExpRaw = $entry['passwordexpirationtime'][0] ?? null;

$totalSeconds = $value * $units[$unit]['seconds'];
$unitLabel = $units[$unit]['label'];
$newExpRaw = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify("+{$totalSeconds} seconds")
    ->format('YmdHis\\Z');
$newExpDt = DateTimeImmutable::createFromFormat('YmdHis\Z', $newExpRaw, new DateTimeZone('UTC'));

$parseGenTime = static function (?string $raw): ?DateTimeImmutable {
    if (!$raw) {
        return null;
    }
    $normalized = preg_replace('/\.\d+Z$/', 'Z', $raw);
    if (!is_string($normalized)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('YmdHis\Z', $normalized, new DateTimeZone('UTC'));
    return $dt instanceof DateTimeImmutable ? $dt : null;
};

$currentExpDt = $parseGenTime($currentExpRaw);
$localTz = new DateTimeZone($config['timezone']);

$formatReadable = static function (DateTimeImmutable $dt, DateTimeZone $tz): string {
    return $dt->setTimezone($tz)->format('d/m/Y H:i:s');
};

$remainingLabel = static function (DateTimeImmutable $expDt, string $localTz): string {
    $now = new DateTimeImmutable('now', new DateTimeZone($localTz));
    $diff = $now->diff($expDt);

    if ($expDt <= $now) {
        $absDiff = $expDt->diff($now);
        if ($absDiff->days > 0) {
            return '⛔ EXPIRADA hace ' . $absDiff->days . ' dia(s)';
        }
        if ($absDiff->h > 0) {
            return '⛔ EXPIRADA hace ' . $absDiff->h . ' hora(s) y ' . $absDiff->i . ' minuto(s)';
        }
        return '⛔ EXPIRADA hace ' . $absDiff->i . ' minuto(s)';
    }

    if ($diff->days > 0) {
        $extra = $diff->h > 0 ? ' y ' . $diff->h . ' hora(s)' : '';
        return '✅ ' . $diff->days . ' dia(s)' . $extra;
    }
    if ($diff->h > 0) {
        $extra = $diff->i > 0 ? ' y ' . $diff->i . ' minuto(s)' : '';
        return '⚠️  ' . $diff->h . ' hora(s)' . $extra;
    }
    return '⚠️  ' . $diff->i . ' minuto(s) y ' . $diff->s . ' segundo(s)';
};

ldap_console_line('');
ldap_console_line('─────────────────────────────────────────────────────');
ldap_console_line('  Usuario: ' . $cn . ' (' . $uid . ')');
ldap_console_line('─────────────────────────────────────────────────────');
ldap_console_line('  Email       : ' . $mail);
ldap_console_line('  DN          : ' . $dn);
ldap_console_line('');

if ($currentExpDt) {
    $remaining = $remainingLabel($currentExpDt, $config['timezone']);
    ldap_console_line('  Expiracion actual  : ' . $formatReadable($currentExpDt, $localTz));
    ldap_console_line('  Tiempo restante    : ' . $remaining);
} else {
    ldap_console_line('  Expiracion actual  : no configurada');
}

ldap_console_line('');
ldap_console_line('  Nueva expiracion   : ' . $formatReadable($newExpDt, $localTz));
ldap_console_line('  (en ' . $value . ' ' . $unitLabel . ' a partir de ahora)');
ldap_console_line('─────────────────────────────────────────────────────');

if ($dryRun) {
    ldap_audit_action('set_user_expiration_dry_run', [
        'uid' => $uid,
        'dn' => $dn,
        'previous_expiration_raw' => $currentExpRaw,
        'new_expiration_raw' => $newExpRaw,
        'seconds_requested' => $totalSeconds,
    ]);
    ldap_console_line('  DRY RUN: no se aplicaron cambios.');
    ldap_console_line('');
    ldap_close($conn);
    exit(0);
}

$mod = ['passwordExpirationTime' => $newExpRaw];
if (!@ldap_modify($conn, $dn, $mod)) {
    ldap_audit_action('set_user_expiration_failed', [
        'uid' => $uid,
        'dn' => $dn,
        'new_expiration_raw' => $newExpRaw,
        'error' => ldap_error($conn),
    ]);
    ldap_console_error('No se pudo actualizar passwordExpirationTime: ' . ldap_error($conn));
    ldap_close($conn);
    exit(1);
}

ldap_audit_action('set_user_expiration_applied', [
    'uid' => $uid,
    'dn' => $dn,
    'previous_expiration_raw' => $currentExpRaw,
    'new_expiration_raw' => $newExpRaw,
    'seconds_requested' => $totalSeconds,
]);

ldap_close($conn);

ldap_console_line('');
ldap_console_line('✓ Expiracion actualizada correctamente.');
exit(0);
