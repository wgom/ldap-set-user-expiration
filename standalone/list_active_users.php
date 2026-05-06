<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

$config = ldap_script_config();

// Obtener hora actual en zona horaria local (America/Asuncion, actualmente GMT-3)
$nowLocal = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
$nowUtc = $nowLocal->setTimezone(new DateTimeZone('UTC'));
$currentTimestamp = (int) $nowUtc->format('U');

ldap_console_line('Usuarios activos LDAP ordenados por expiracion');
ldap_console_line("Conectando a {$config['host']}:{$config['port']}...");

$conn = ldap_connect_and_bind($config);
$search = ldap_search($conn, $config['base_dn'], '(objectClass=inetOrgPerson)', ['uid', 'cn', 'mail', 'passwordExpirationTime']);

if (!$search) {
    ldap_console_error('Error en busqueda LDAP: ' . ldap_error($conn));
    ldap_close($conn);
    exit(1);
}

$entries = ldap_get_entries($conn, $search);
ldap_close($conn);

$activeUsers = [];
$noExpiration = [];

foreach ($entries as $index => $entry) {
    if ($index === 'count') {
        continue;
    }

    $uid = $entry['uid'][0] ?? 'desconocido';
    $cn = $entry['cn'][0] ?? 'desconocido';
    $mail = $entry['mail'][0] ?? 'sin email';

    if (!isset($entry['passwordexpirationtime'][0])) {
        $noExpiration[] = ['uid' => $uid, 'cn' => $cn, 'mail' => $mail];
        continue;
    }

    $expTime = $entry['passwordexpirationtime'][0];
    $expTimestamp = ldap_date_to_timestamp($expTime);
    $days = (int) floor(($expTimestamp - $currentTimestamp) / 86400);
    $expDt = ldap_parse_generalized_time($expTime);
    $localTz = new DateTimeZone($config['timezone']);
    $expLocal = $expDt ? $expDt->setTimezone($localTz) : null;
    $fechaFormato = $expLocal ? $expLocal->format('d/m/Y') : 'N/A';
    $horaFormato = $expLocal ? $expLocal->format('H:i:s') : 'N/A';
    if ($days >= 0) {
        $activeUsers[] = [
            'uid' => $uid,
            'cn' => $cn,
            'mail' => $mail,
            'days' => $days,
            'expTime' => $expTime,
            'expTimestamp' => $expTimestamp,
            'fecha' => $fechaFormato,
            'hora' => $horaFormato,
        ];
    }
}

// Ordenar por expiracion LDAP exacta (fecha + hora), ascendente para activos.
usort($activeUsers, static fn(array $a, array $b): int => ($a['expTimestamp'] ?? 0) <=> ($b['expTimestamp'] ?? 0));

$txtPath = ldap_output_path('ldap_active_users_report.txt');
$htmlPath = ldap_output_path('ldap_active_users_report.html');

$lines = [];
$lines[] = '=================================================';
$lines[] = 'REPORTE LDAP - USUARIOS ACTIVOS ORDENADOS';
$lines[] = '=================================================';
$lines[] = 'Fecha: ' . date('Y-m-d H:i:s');
$lines[] = '';
$lines[] = 'USUARIOS ACTIVOS (no expirados)';
$lines[] = 'Orden: dias restantes de menor a mayor';
$lines[] = '-------------------------------------------------';
if ($activeUsers === []) {
    $lines[] = 'Sin registros';
} else {
    foreach ($activeUsers as $user) {
        $lines[] = "- {$user['uid']} | {$user['cn']} | {$user['mail']} | {$user['days']} dias | {$user['fecha']} | {$user['hora']} | {$user['expTime']}";
    }
}
$lines[] = '';
$lines[] = 'USUARIOS SIN EXPIRACION CONFIGURADA';
$lines[] = '-------------------------------------------------';
if ($noExpiration === []) {
    $lines[] = 'Sin registros';
} else {
    foreach ($noExpiration as $user) {
        $lines[] = "- {$user['uid']} | {$user['cn']} | {$user['mail']}";
    }
}
$lines[] = '';
$lines[] = 'RESUMEN';
$lines[] = '-------------------------------------------------';
$lines[] = 'Activos listados: ' . count($activeUsers);
$lines[] = 'Sin expiracion: ' . count($noExpiration);
$lines[] = 'Nota: este archivo se reemplaza en cada ejecucion.';

ldap_write_file($txtPath, implode(PHP_EOL, $lines) . PHP_EOL);

$html = [];
$html[] = '<!doctype html>';
$html[] = '<html lang="es">';
$html[] = '<head>';
$html[] = '  <meta charset="utf-8">';
$html[] = '  <meta name="viewport" content="width=device-width, initial-scale=1">';
$html[] = '  <title>Reporte LDAP - Usuarios Activos</title>';
$html[] = '  <style>';
$html[] = '    :root { --bg:#0f172a; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --ok:#22c55e; --line:#1f2937; }';
$html[] = '    body { margin:0; font-family: Segoe UI, Arial, sans-serif; background: linear-gradient(135deg,#0b1220,#111827); color:var(--text); }';
$html[] = '    .wrap { max-width:1500px; margin:24px auto; padding:0 16px; }';
$html[] = '    .card { background:rgba(17,24,39,.92); border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:16px; }';
$html[] = '    h1 { margin:0 0 8px; font-size:24px; }';
$html[] = '    .meta { color:var(--muted); font-size:14px; }';
$html[] = '    .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; margin-top:14px; }';
$html[] = '    .kpi { border:1px solid var(--line); border-radius:10px; padding:12px; background:#0b1324; }';
$html[] = '    .kpi .label { font-size:12px; color:var(--muted); text-transform:uppercase; }';
$html[] = '    .kpi .value { font-size:28px; font-weight:700; margin-top:4px; color:var(--ok); }';
$html[] = '    table { width:100%; border-collapse:collapse; margin-top:10px; }';
$html[] = '    th, td { text-align:left; padding:9px; border-bottom:1px solid var(--line); font-size:14px; }';
$html[] = '    th { color:#cbd5e1; font-weight:600; }';
$html[] = '    .empty { color:var(--muted); padding:8px 0; }';
$html[] = '  </style>';
$html[] = '</head>';
$html[] = '<body>';
$html[] = '  <div class="wrap">';
$html[] = '    <div class="card">';
$html[] = '      <h1>Reporte LDAP - Usuarios Activos</h1>';
$html[] = '      <div class="meta">Generado: ' . ldap_html_escape(ldap_format_date_es(new DateTimeImmutable(), $config['timezone'])) . '</div>';
$html[] = '      <div class="grid">';
$html[] = '        <div class="kpi"><div class="label">Activos listados</div><div class="value">' . count($activeUsers) . '</div></div>';
$html[] = '        <div class="kpi"><div class="label">Sin expiracion</div><div class="value">' . count($noExpiration) . '</div></div>';
$html[] = '      </div>';
$html[] = '    </div>';
$html[] = '    <div class="card">';
$html[] = '      <h2>Usuarios activos (no expirados)</h2>';
$html[] = '      <div class="meta">Ordenados por dias restantes de menor a mayor</div>';
if ($activeUsers === []) {
    $html[] = '      <div class="empty">Sin registros</div>';
} else {
    $html[] = '      <table>';
    $html[] = '        <thead><tr><th>UID</th><th>Nombre</th><th>Email</th><th>Dias restantes</th><th>Fecha formateada</th><th>Hora formateada</th><th>Expiracion LDAP</th></tr></thead>';
    $html[] = '        <tbody>';
    foreach ($activeUsers as $user) {
        $html[] = '          <tr>';
        $html[] = '            <td>' . ldap_html_escape($user['uid']) . '</td>';
        $html[] = '            <td>' . ldap_html_escape($user['cn']) . '</td>';
        $html[] = '            <td>' . ldap_html_escape($user['mail']) . '</td>';
        $html[] = '            <td>' . ldap_html_escape((string) $user['days']) . '</td>';
        $html[] = '            <td>' . ldap_html_escape($user['fecha'] ?? 'N/A') . '</td>';
        $html[] = '            <td>' . ldap_html_escape($user['hora'] ?? 'N/A') . '</td>';
        $html[] = '            <td>' . ldap_html_escape($user['expTime']) . '</td>';
        $html[] = '          </tr>';
    }
    $html[] = '        </tbody>';
    $html[] = '      </table>';
}
$html[] = '    </div>';
$html[] = '    <div class="card">';
$html[] = '      <h2>Usuarios sin expiracion configurada</h2>';
if ($noExpiration === []) {
    $html[] = '      <div class="empty">Sin registros</div>';
} else {
    $html[] = '      <table>';
    $html[] = '        <thead><tr><th>UID</th><th>Nombre</th><th>Email</th></tr></thead>';
    $html[] = '        <tbody>';
    foreach ($noExpiration as $user) {
        $html[] = '          <tr>';
        $html[] = '            <td>' . ldap_html_escape($user['uid']) . '</td>';
        $html[] = '            <td>' . ldap_html_escape($user['cn']) . '</td>';
        $html[] = '            <td>' . ldap_html_escape($user['mail']) . '</td>';
        $html[] = '          </tr>';
    }
    $html[] = '        </tbody>';
    $html[] = '      </table>';
}
$html[] = '    </div>';
$html[] = '  </div>';
$html[] = '</body>';
$html[] = '</html>';

ldap_write_file($htmlPath, implode(PHP_EOL, $html) . PHP_EOL);

ldap_console_line('Reporte TXT: ' . $txtPath);
ldap_console_line('Reporte HTML: ' . $htmlPath);
exit(0);
