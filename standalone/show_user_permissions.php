<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

if ($argc < 2 || trim((string) $argv[1]) === '') {
    ldap_console_error('Uso: php standalone/show_user_permissions.php UID');
    exit(1);
}

$uid = trim((string) $argv[1]);
$config = ldap_script_config();

$parsePasswordExpiration = static function (array $attrs, string $timezone): array {
    $expiresRaw = $attrs['passwordexpirationtime'][0] ?? ($attrs['passwordExpirationTime'][0] ?? null);
    $changedRaw = $attrs['pwdchangedtime'][0] ?? ($attrs['pwdChangedTime'][0] ?? null);
    $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $expiresAt = ldap_parse_generalized_time(is_string($expiresRaw) ? $expiresRaw : null);
    $changedAt = ldap_parse_generalized_time(is_string($changedRaw) ? $changedRaw : null);
    $localTz = new DateTimeZone($timezone);

    $result = [
        'expires_at' => null,
        'changed_at' => null,
        'is_expired' => false,
        'days_remaining' => null,
        'remaining_label' => 'sin informacion',
        'status_label' => 'Sin datos',
    ];

    if ($changedAt) {
        $result['changed_at'] = $changedAt->setTimezone($localTz)->format('H:i:s d/m/Y');
    }

    if ($expiresAt) {
        $totalSeconds = $expiresAt->getTimestamp() - $now->getTimestamp();
        $diff = $now->diff($expiresAt);
        $daysRemaining = (int) floor($totalSeconds / 86400);
        $result['expires_at'] = $expiresAt->setTimezone($localTz)->format('H:i:s d/m/Y');
        $result['days_remaining'] = $daysRemaining;

        if ($totalSeconds <= 0) {
            $result['is_expired'] = true;
            $result['remaining_label'] = abs($daysRemaining) > 0
                ? 'Expirada hace ' . abs($daysRemaining) . ' dia(s)'
                : 'Expirada hace ' . abs((int) $diff->h) . ' hora(s)';
            $result['status_label'] = 'EXPIRADA';
        } elseif ($daysRemaining === 0) {
            $hours = (int) floor($totalSeconds / 3600);
            $minutes = (int) floor(($totalSeconds % 3600) / 60);
            $seconds = (int) ($totalSeconds % 60);
            if ($hours > 0) {
                $result['remaining_label'] = 'Expira hoy en ' . $hours . ' hora(s) y ' . $minutes . ' minuto(s)';
            } elseif ($minutes > 0) {
                $result['remaining_label'] = 'Expira hoy en ' . $minutes . ' minuto(s) y ' . $seconds . ' segundo(s)';
            } else {
                $result['remaining_label'] = 'Expira hoy en ' . $seconds . ' segundo(s)';
            }
            $result['status_label'] = 'Expira hoy';
        } elseif ($daysRemaining <= 7) {
            $result['remaining_label'] = 'En ' . $daysRemaining . ' dia(s) (' . $diff->h . 'h)';
            $result['status_label'] = 'Por vencer';
        } else {
            $result['remaining_label'] = 'En ' . $daysRemaining . ' dia(s)';
            $result['status_label'] = 'Vigente';
        }
    }

    return $result;
};

$conn = ldap_connect_and_bind($config);
$safeUid = ldap_escape($uid, '', LDAP_ESCAPE_FILTER);
$userSearch = ldap_search($conn, $config['base_dn'], "(uid={$safeUid})", ['dn', 'cn', 'mail', 'passwordExpirationTime', 'pwdChangedTime', 'passwordExpWarned']);

if (!$userSearch || ldap_count_entries($conn, $userSearch) === 0) {
    ldap_console_error("Usuario '{$uid}' no encontrado en LDAP.");
    ldap_close($conn);
    exit(1);
}

$userEntry = ldap_first_entry($conn, $userSearch);
$userDn = ldap_get_dn($conn, $userEntry);
$userAttrs = ldap_get_attributes($conn, $userEntry);
$userCn = $userAttrs['cn'][0] ?? $uid;
$userMail = $userAttrs['mail'][0] ?? 'sin email';
$passwordInfo = $parsePasswordExpiration($userAttrs, $config['timezone']);

$safeDn = ldap_escape($userDn, '', LDAP_ESCAPE_FILTER);
$groupFilter = "(|(member={$safeDn})(uniqueMember={$safeDn})(memberUid={$safeUid}))";
$groupSearch = ldap_search($conn, $config['base_dn'], $groupFilter, ['cn', 'dn', 'description']);

$groups = [];
if ($groupSearch) {
    $groupEntries = ldap_get_entries($conn, $groupSearch);
    foreach ($groupEntries as $index => $entry) {
        if ($index === 'count') {
            continue;
        }
        $groups[] = [
            'cn' => $entry['cn'][0] ?? 'sin-cn',
            'dn' => $entry['dn'] ?? '',
            'description' => $entry['description'][0] ?? '',
        ];
    }
    usort($groups, static fn(array $a, array $b): int => strcmp((string) $a['cn'], (string) $b['cn']));
}

ldap_close($conn);

$safeFileUid = preg_replace('/[^A-Za-z0-9._-]/', '_', $uid);
$safeFileUid = is_string($safeFileUid) && $safeFileUid !== '' ? $safeFileUid : 'usuario';
$txtPath = ldap_output_path('ldap_user_' . $safeFileUid . '_permissions.txt');
$htmlPath = ldap_output_path('ldap_user_' . $safeFileUid . '_permissions.html');

$lines = [];
$lines[] = '=================================================';
$lines[] = 'REPORTE LDAP - GRUPOS DEL USUARIO';
$lines[] = '=================================================';
$lines[] = 'Fecha: ' . date('H:i:s d/m/Y');
$lines[] = '';
$lines[] = 'USUARIO';
$lines[] = '-------------------------------------------------';
$lines[] = 'UID:   ' . $uid;
$lines[] = 'Nombre:' . $userCn;
$lines[] = 'Email: ' . $userMail;
$lines[] = 'DN:    ' . $userDn;
$lines[] = '';
$lines[] = 'CONTRASENA';
$lines[] = '-------------------------------------------------';
$lines[] = 'Ultimo cambio   : ' . ($passwordInfo['changed_at'] ?? 'sin informacion');
$lines[] = 'Expira el       : ' . ($passwordInfo['expires_at'] ?? 'sin informacion');
$lines[] = 'Tiempo restante : ' . ($passwordInfo['remaining_label'] ?? 'sin informacion');
$lines[] = 'Estado          : ' . ($passwordInfo['status_label'] ?? 'sin informacion');
$lines[] = '';
$lines[] = 'GRUPOS LDAP (' . count($groups) . ')';
$lines[] = '-------------------------------------------------';
if ($groups === []) {
    $lines[] = '(sin grupos)';
} else {
    foreach ($groups as $group) {
        $desc = $group['description'] !== '' ? ' | ' . $group['description'] : '';
        $lines[] = '- ' . $group['cn'] . $desc;
        $lines[] = '  DN: ' . $group['dn'];
    }
}
$lines[] = '';
$lines[] = 'Nota: este archivo se reemplaza en cada ejecucion.';

ldap_write_file($txtPath, implode(PHP_EOL, $lines) . PHP_EOL);

$badgeClass = 'badge-muted';
if ($passwordInfo['expires_at']) {
    if ($passwordInfo['is_expired']) {
        $badgeClass = 'badge-red';
    } elseif (($passwordInfo['days_remaining'] ?? 999) <= 7) {
        $badgeClass = 'badge-yellow';
    } else {
        $badgeClass = 'badge-green';
    }
}

$html = [];
$html[] = '<!doctype html>';
$html[] = '<html lang="es">';
$html[] = '<head>';
$html[] = '  <meta charset="utf-8">';
$html[] = '  <meta name="viewport" content="width=device-width, initial-scale=1">';
$html[] = '  <title>Grupos LDAP - ' . ldap_html_escape($uid) . '</title>';
$html[] = '  <style>';
$html[] = '    :root{--card:#111827;--line:#1f2937;--text:#e5e7eb;--muted:#94a3b8;--cyan:#38bdf8;--red:#f87171;--yellow:#fbbf24;--green:#4ade80;}';
$html[] = '    body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:linear-gradient(135deg,#0b1220,#111827);color:var(--text);}';
$html[] = '    .wrap{max-width:1000px;margin:24px auto;padding:0 16px;}';
$html[] = '    .card{background:rgba(17,24,39,.92);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px;}';
$html[] = '    h1{margin:0 0 6px;font-size:22px;}';
$html[] = '    .meta{color:var(--muted);font-size:13px;line-height:1.8;}';
$html[] = '    h2{margin:0 0 10px;font-size:16px;color:var(--cyan);}';
$html[] = '    table{width:100%;border-collapse:collapse;margin-top:6px;}';
$html[] = '    th,td{text-align:left;padding:8px;border-bottom:1px solid var(--line);font-size:13px;}';
$html[] = '    th{color:#cbd5e1;font-weight:600;}';
$html[] = '    .empty{color:var(--muted);}';
$html[] = '    code{font-family:Consolas,monospace;font-size:12px;color:#94a3b8;}';
$html[] = '    .badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;}';
$html[] = '    .badge-red{background:rgba(248,113,113,.15);color:var(--red);border:1px solid var(--red);}';
$html[] = '    .badge-yellow{background:rgba(251,191,36,.12);color:var(--yellow);border:1px solid var(--yellow);}';
$html[] = '    .badge-green{background:rgba(74,222,128,.12);color:var(--green);border:1px solid var(--green);}';
$html[] = '    .badge-muted{background:rgba(148,163,184,.10);color:var(--muted);border:1px solid var(--muted);}';
$html[] = '    .pwd-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px;}';
$html[] = '    .pwd-item label{display:block;font-size:11px;color:var(--muted);margin-bottom:2px;}';
$html[] = '    .pwd-item span{font-size:13px;}';
$html[] = '  </style>';
$html[] = '</head>';
$html[] = '<body>';
$html[] = '  <div class="wrap">';
$html[] = '    <div class="card">';
$html[] = '      <h1>Grupos LDAP &mdash; ' . ldap_html_escape($userCn) . '</h1>';
$html[] = '      <div class="meta">';
$html[] = '        <b>UID:</b> ' . ldap_html_escape($uid) . '<br>';
$html[] = '        <b>Email:</b> ' . ldap_html_escape($userMail) . '<br>';
$html[] = '        <b>DN:</b> <code>' . ldap_html_escape($userDn) . '</code><br>';
$html[] = '        <b>Generado:</b> ' . ldap_html_escape(ldap_format_date_es(new DateTimeImmutable(), $config['timezone']));
$html[] = '      </div>';
$html[] = '    </div>';
$html[] = '    <div class="card">';
$html[] = '      <h2>Contrasena</h2>';
$html[] = '      <div class="pwd-grid">';
$html[] = '        <div class="pwd-item"><label>Ultimo cambio</label><span>' . ldap_html_escape((string) ($passwordInfo['changed_at'] ?? '—')) . '</span></div>';
$html[] = '        <div class="pwd-item"><label>Expira el</label><span>' . ldap_html_escape((string) ($passwordInfo['expires_at'] ?? '—')) . '</span></div>';
$html[] = '        <div class="pwd-item"><label>Tiempo restante</label><span>' . ldap_html_escape((string) ($passwordInfo['remaining_label'] ?? '—')) . '</span></div>';
$html[] = '        <div class="pwd-item"><label>Estado</label><span class="badge ' . $badgeClass . '">' . ldap_html_escape((string) ($passwordInfo['status_label'] ?? 'Sin datos')) . '</span></div>';
$html[] = '      </div>';
$html[] = '    </div>';
$html[] = '    <div class="card">';
$html[] = '      <h2>Grupos LDAP (' . count($groups) . ')</h2>';
if ($groups === []) {
    $html[] = '      <div class="empty">Sin grupos</div>';
} else {
    $html[] = '      <table>';
    $html[] = '        <thead><tr><th>Grupo (CN)</th><th>Descripcion</th><th>DN</th></tr></thead>';
    $html[] = '        <tbody>';
    foreach ($groups as $group) {
        $html[] = '          <tr>';
        $html[] = '            <td>' . ldap_html_escape($group['cn']) . '</td>';
        $html[] = '            <td>' . ($group['description'] !== '' ? ldap_html_escape($group['description']) : '<span class="empty">—</span>') . '</td>';
        $html[] = '            <td><code>' . ldap_html_escape($group['dn']) . '</code></td>';
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