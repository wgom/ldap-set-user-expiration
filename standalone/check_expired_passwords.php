<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

$config = ldap_script_config();

// Obtener hora actual en zona horaria local (America/Asuncion, actualmente GMT-3)
$nowLocal = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
$nowUtc = $nowLocal->setTimezone(new DateTimeZone('UTC'));
$currentTimestamp = (int) $nowUtc->format('U');

ldap_console_line('Verificacion de contrasenas expiradas - LDAP 389 DS');
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

if (($entries['count'] ?? 0) === 0) {
    ldap_console_line('No hay usuarios en LDAP');
    exit(0);
}

$expired = [];
$expiringSoon = [];
$warning = [];
$active = [];
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
    $secondsRemaining = $expTimestamp - $currentTimestamp;
    $days = (int) floor($secondsRemaining / 86400);

    // Parsear y formatear la fecha/hora en zona horaria local
    $expDt = ldap_parse_generalized_time($expTime);
    $localTz = new DateTimeZone($config['timezone']);
    $expLocal = $expDt ? $expDt->setTimezone($localTz) : null;
    $fechaFormato = $expLocal ? $expLocal->format('d/m/Y') : 'N/A';
    $horaFormato = $expLocal ? $expLocal->format('H:i:s') : 'N/A';
    
    $record = [
        'uid' => $uid,
        'cn' => $cn,
        'mail' => $mail,
        'expTime' => $expTime,
        'expTimestamp' => $expTimestamp,
        'days' => $days,
        'secondsRemaining' => $secondsRemaining,
        'fecha' => $fechaFormato,
        'hora' => $horaFormato,
    ];

    // Clasificación basada en segundos restantes (considerando horas)
    if ($secondsRemaining < 0) {
        $expired[] = $record;
    } elseif ($secondsRemaining < 604800) { // 7 días en segundos
        $expiringSoon[] = $record;
    } elseif ($secondsRemaining < 1296000) { // 15 días en segundos
        $warning[] = $record;
    } else {
        $active[] = $record;
    }
}

// Ordenar por expiracion LDAP exacta (fecha + hora):
// - Expiradas: descendente (las mas antiguas primero)
// - No expiradas: ascendente (las que vencen antes primero)
usort($expired, static fn(array $a, array $b): int => ($b['expTimestamp'] ?? 0) <=> ($a['expTimestamp'] ?? 0));
usort($expiringSoon, static fn(array $a, array $b): int => ($a['expTimestamp'] ?? 0) <=> ($b['expTimestamp'] ?? 0));
usort($warning, static fn(array $a, array $b): int => ($a['expTimestamp'] ?? 0) <=> ($b['expTimestamp'] ?? 0));
usort($active, static fn(array $a, array $b): int => ($a['expTimestamp'] ?? 0) <=> ($b['expTimestamp'] ?? 0));

$total = count($expired) + count($expiringSoon) + count($warning) + count($active) + count($noExpiration);
$txtPath = ldap_output_path('ldap_password_report.txt');
$htmlPath = ldap_output_path('ldap_password_report.html');

$lines = [];
$lines[] = '===============================================';
$lines[] = 'REPORTE LDAP - CONTRASENAS EXPIRADAS';
$lines[] = '===============================================';
$lines[] = 'Fecha: ' . date('Y-m-d H:i:s');
$lines[] = '';
$lines[] = 'RESUMEN';
$lines[] = '-----------------------------------------------';
$lines[] = 'Expiradas: ' . count($expired);
$lines[] = 'Proximas (<7d): ' . count($expiringSoon);
$lines[] = 'Advertencia (7-15d): ' . count($warning);
$lines[] = 'Activas (>15d): ' . count($active);
$lines[] = 'Sin expiracion: ' . count($noExpiration);
$lines[] = 'Total usuarios: ' . $total;
$lines[] = '';

$sections = [
    'CONTRASENAS EXPIRADAS' => $expired,
    'PROXIMAS A EXPIRAR (<7 dias)' => $expiringSoon,
    'ADVERTENCIA (7-15 dias)' => $warning,
];

foreach ($sections as $title => $rows) {
    $lines[] = $title;
    $lines[] = '-----------------------------------------------';
    if ($rows === []) {
        $lines[] = 'Sin registros';
    } else {
        foreach ($rows as $row) {
            $label = $title === 'CONTRASENAS EXPIRADAS'
                ? 'expirada hace: ' . abs((int) $row['days']) . ' dias'
                : $row['days'] . ' dias';
            $lines[] = "- {$row['uid']} | {$row['cn']} | {$row['mail']} | {$label} | {$row['fecha']} | {$row['hora']} | {$row['expTime']}";
        }
    }
    $lines[] = '';
}

$lines[] = 'SIN EXPIRACION CONFIGURADA';
$lines[] = '-----------------------------------------------';
if ($noExpiration === []) {
    $lines[] = 'Sin registros';
} else {
    foreach ($noExpiration as $row) {
        $lines[] = "- {$row['uid']} | {$row['cn']} | {$row['mail']}";
    }
}
$lines[] = '';
$lines[] = 'Nota: este archivo se reemplaza en cada ejecucion.';

ldap_write_file($txtPath, implode(PHP_EOL, $lines) . PHP_EOL);

$buildTable = static function (array $rows, string $title, string $titleClass, string $daysHeader, bool $absoluteDays = false): string {
    $out = [];
    $out[] = '    <div class="card">';
    $out[] = '      <h2 class="' . ldap_html_escape($titleClass) . '">' . ldap_html_escape($title) . '</h2>';

    if ($rows === []) {
        $out[] = '      <div class="empty">Sin registros</div>';
        $out[] = '    </div>';
        return implode(PHP_EOL, $out);
    }

    $out[] = '      <table>';
    $out[] = '        <thead><tr><th>UID</th><th>Nombre</th><th>Email</th><th>' . ldap_html_escape($daysHeader) . '</th><th>Fecha formateada</th><th>Hora formateada</th><th>Expiracion LDAP</th></tr></thead>';
    $out[] = '        <tbody>';
    foreach ($rows as $row) {
        $displayDays = $absoluteDays ? abs((int) ($row['days'] ?? 0)) : (int) ($row['days'] ?? 0);
        $out[] = '          <tr>';
        $out[] = '            <td>' . ldap_html_escape((string) ($row['uid'] ?? '')) . '</td>';
        $out[] = '            <td>' . ldap_html_escape((string) ($row['cn'] ?? '')) . '</td>';
        $out[] = '            <td>' . ldap_html_escape((string) ($row['mail'] ?? '')) . '</td>';
        $out[] = '            <td>' . ldap_html_escape((string) $displayDays) . '</td>';
        $out[] = '            <td>' . ldap_html_escape((string) ($row['fecha'] ?? 'N/A')) . '</td>';
        $out[] = '            <td>' . ldap_html_escape((string) ($row['hora'] ?? 'N/A')) . '</td>';
        $out[] = '            <td>' . ldap_html_escape((string) ($row['expTime'] ?? '')) . '</td>';
        $out[] = '          </tr>';
    }
    $out[] = '        </tbody>';
    $out[] = '      </table>';
    $out[] = '    </div>';

    return implode(PHP_EOL, $out);
};

$noExpirationHtml = [];
$noExpirationHtml[] = '    <div class="card">';
$noExpirationHtml[] = '      <h2>Usuarios sin expiracion configurada</h2>';
if ($noExpiration === []) {
    $noExpirationHtml[] = '      <div class="empty">Sin registros</div>';
} else {
    $noExpirationHtml[] = '      <table>';
    $noExpirationHtml[] = '        <thead><tr><th>UID</th><th>Nombre</th><th>Email</th></tr></thead>';
    $noExpirationHtml[] = '        <tbody>';
    foreach ($noExpiration as $row) {
        $noExpirationHtml[] = '          <tr>';
        $noExpirationHtml[] = '            <td>' . ldap_html_escape($row['uid']) . '</td>';
        $noExpirationHtml[] = '            <td>' . ldap_html_escape($row['cn']) . '</td>';
        $noExpirationHtml[] = '            <td>' . ldap_html_escape($row['mail']) . '</td>';
        $noExpirationHtml[] = '          </tr>';
    }
    $noExpirationHtml[] = '        </tbody>';
    $noExpirationHtml[] = '      </table>';
}
$noExpirationHtml[] = '    </div>';

$html = [];
$html[] = '<!doctype html>';
$html[] = '<html lang="es">';
$html[] = '<head>';
$html[] = '  <meta charset="utf-8">';
$html[] = '  <meta name="viewport" content="width=device-width, initial-scale=1">';
$html[] = '  <title>Reporte LDAP - Contrasenas</title>';
$html[] = '  <style>';
$html[] = '    :root { --bg:#0f172a; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --danger:#ef4444; --warn:#f59e0b; --info:#38bdf8; --ok:#22c55e; --line:#1f2937; }';
$html[] = '    body { margin:0; font-family: Segoe UI, Arial, sans-serif; background: linear-gradient(135deg,#0b1220,#111827); color:var(--text); }';
$html[] = '    .wrap { max-width:1500px; margin:24px auto; padding:0 16px; }';
$html[] = '    .card { background:rgba(17,24,39,.92); border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:16px; }';
$html[] = '    h1 { margin:0 0 8px; font-size:24px; }';
$html[] = '    .meta { color:var(--muted); font-size:14px; }';
$html[] = '    .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-top:14px; }';
$html[] = '    .kpi { border:1px solid var(--line); border-radius:10px; padding:12px; background:#0b1324; }';
$html[] = '    .kpi .label { font-size:12px; color:var(--muted); text-transform:uppercase; }';
$html[] = '    .kpi .value { font-size:28px; font-weight:700; margin-top:4px; }';
$html[] = '    .danger { color:var(--danger); } .warn { color:var(--warn); } .info { color:var(--info); } .ok { color:var(--ok); }';
$html[] = '    table { width:100%; border-collapse:collapse; margin-top:10px; }';
$html[] = '    th, td { text-align:left; padding:9px; border-bottom:1px solid var(--line); font-size:14px; }';
$html[] = '    th { color:#cbd5e1; font-weight:600; }';
$html[] = '    .empty { color:var(--muted); padding:8px 0; }';
$html[] = '  </style>';
$html[] = '</head>';
$html[] = '<body>';
$html[] = '  <div class="wrap">';
$html[] = '    <div class="card">';
$html[] = '      <h1>Reporte LDAP - Contrasenas Expiradas</h1>';
$html[] = '      <div class="meta">Generado: ' . ldap_html_escape(ldap_format_date_es(new DateTimeImmutable(), $config['timezone'])) . '</div>';
$html[] = '      <div class="grid">';
$html[] = '        <div class="kpi"><div class="label">Expiradas</div><div class="value danger">' . count($expired) . '</div></div>';
$html[] = '        <div class="kpi"><div class="label">Proximas (&lt;7d)</div><div class="value warn">' . count($expiringSoon) . '</div></div>';
$html[] = '        <div class="kpi"><div class="label">Advertencia (7-15d)</div><div class="value info">' . count($warning) . '</div></div>';
$html[] = '        <div class="kpi"><div class="label">Activas (&gt;15d)</div><div class="value ok">' . count($active) . '</div></div>';
$html[] = '        <div class="kpi"><div class="label">Sin expiracion</div><div class="value">' . count($noExpiration) . '</div></div>';
$html[] = '        <div class="kpi"><div class="label">Total usuarios</div><div class="value">' . $total . '</div></div>';
$html[] = '      </div>';
$html[] = '    </div>';
$html[] = $buildTable($expired, 'Contrasenas expiradas', 'danger', 'Dias expirada', true);
$html[] = $buildTable($expiringSoon, 'Proximas a expirar (< 7 dias)', 'warn', 'Dias restantes');
$html[] = $buildTable($warning, 'Advertencia (7-15 dias)', 'info', 'Dias restantes');
$html[] = implode(PHP_EOL, $noExpirationHtml);
$html[] = '  </div>';
$html[] = '</body>';
$html[] = '</html>';

ldap_write_file($htmlPath, implode(PHP_EOL, $html) . PHP_EOL);

ldap_console_line('Reporte TXT: ' . $txtPath);
ldap_console_line('Reporte HTML: ' . $htmlPath);
exit(0);
