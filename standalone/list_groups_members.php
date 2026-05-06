<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

$config = ldap_script_config();

ldap_console_line('Grupos LDAP con integrantes');
ldap_console_line("Conectando a {$config['host']}:{$config['port']}...");

$conn = ldap_connect_and_bind($config);
$filter = '(|(objectClass=groupOfNames)(objectClass=groupOfUniqueNames)(objectClass=posixGroup)(objectClass=group))';
$attrs = ['cn', 'description', 'member', 'uniqueMember', 'memberUid', 'objectClass'];
$search = ldap_search($conn, $config['group_base_dn'], $filter, $attrs);

if (!$search) {
    ldap_console_error('Error en busqueda LDAP: ' . ldap_error($conn));
    ldap_close($conn);
    exit(1);
}

$entries = ldap_get_entries($conn, $search);
ldap_close($conn);

$groups = [];
foreach ($entries as $index => $entry) {
    if ($index === 'count') {
        continue;
    }

    $members = [];
    foreach (array_merge(ldap_collect_values($entry, 'member'), ldap_collect_values($entry, 'uniquemember')) as $memberDn) {
        $members[] = ['type' => 'dn', 'value' => $memberDn];
    }
    foreach (ldap_collect_values($entry, 'memberuid') as $memberUid) {
        $members[] = ['type' => 'uid', 'value' => $memberUid];
    }

    usort($members, static fn(array $a, array $b): int => strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? '')));
    $groups[] = [
        'name' => $entry['cn'][0] ?? 'sin-cn',
        'description' => $entry['description'][0] ?? '',
        'dn' => $entry['dn'] ?? '',
        'objectClass' => ldap_collect_values($entry, 'objectclass'),
        'members' => $members,
        'membersCount' => count($members),
    ];
}

usort($groups, static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

$txtPath = ldap_output_path('ldap_groups_members_report.txt');
$htmlPath = ldap_output_path('ldap_groups_members_report.html');

$lines = [];
$lines[] = '=================================================';
$lines[] = 'REPORTE LDAP - GRUPOS E INTEGRANTES';
$lines[] = '=================================================';
$lines[] = 'Fecha: ' . date('Y-m-d H:i:s');
$lines[] = 'Total grupos: ' . count($groups);
$lines[] = '';
foreach ($groups as $group) {
    $lines[] = 'Grupo: ' . $group['name'];
    $lines[] = 'DN: ' . $group['dn'];
    $lines[] = 'ObjectClass: ' . implode(', ', $group['objectClass']);
    $lines[] = 'Descripcion: ' . ($group['description'] !== '' ? $group['description'] : '(sin descripcion)');
    $lines[] = 'Integrantes: ' . $group['membersCount'];
    if ($group['members'] === []) {
        $lines[] = '  - Sin integrantes';
    } else {
        foreach ($group['members'] as $member) {
            $lines[] = '  - [' . $member['type'] . '] ' . $member['value'];
        }
    }
    $lines[] = str_repeat('-', 49);
}
$lines[] = 'Nota: este archivo se reemplaza en cada ejecucion.';

ldap_write_file($txtPath, implode(PHP_EOL, $lines) . PHP_EOL);

$html = [];
$html[] = '<!doctype html>';
$html[] = '<html lang="es">';
$html[] = '<head>';
$html[] = '  <meta charset="utf-8">';
$html[] = '  <meta name="viewport" content="width=device-width, initial-scale=1">';
$html[] = '  <title>Reporte LDAP - Grupos e Integrantes</title>';
$html[] = '  <style>';
$html[] = '    :root { --bg:#0f172a; --card:#111827; --line:#1f2937; --text:#e5e7eb; --muted:#94a3b8; --ok:#22c55e; }';
$html[] = '    body { margin:0; font-family: Segoe UI, Arial, sans-serif; background: linear-gradient(135deg,#0b1220,#111827); color:var(--text); }';
$html[] = '    .wrap { max-width:1500px; margin:24px auto; padding:0 16px; }';
$html[] = '    .card { background:rgba(17,24,39,.92); border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:16px; }';
$html[] = '    h1, h2 { margin:0 0 8px; }';
$html[] = '    .meta { color:var(--muted); font-size:14px; margin-bottom:8px; }';
$html[] = '    .kpi { color:var(--ok); font-weight:700; font-size:24px; }';
$html[] = '    .pill { display:inline-block; font-size:12px; border:1px solid var(--line); border-radius:999px; padding:3px 8px; margin-right:4px; color:#cbd5e1; }';
$html[] = '    ul { margin:8px 0 0 18px; padding:0; }';
$html[] = '    li { margin:4px 0; font-family: Consolas, monospace; font-size:13px; }';
$html[] = '    .empty { color:var(--muted); }';
$html[] = '  </style>';
$html[] = '</head>';
$html[] = '<body>';
$html[] = '  <div class="wrap">';
$html[] = '    <div class="card">';
$html[] = '      <h1>Reporte LDAP - Grupos e Integrantes</h1>';
$html[] = '      <div class="meta">Generado: ' . ldap_html_escape(ldap_format_date_es(new DateTimeImmutable(), $config['timezone'])) . '</div>';
$html[] = '      <div class="kpi">Total grupos: ' . count($groups) . '</div>';
$html[] = '    </div>';
foreach ($groups as $group) {
    $html[] = '    <div class="card">';
    $html[] = '      <h2>' . ldap_html_escape((string) $group['name']) . '</h2>';
    $html[] = '      <div class="meta">DN: ' . ldap_html_escape((string) $group['dn']) . '</div>';
    $html[] = '      <div class="meta">Descripcion: ' . ldap_html_escape((string) ($group['description'] !== '' ? $group['description'] : '(sin descripcion)')) . '</div>';
    $html[] = '      <div class="meta">Integrantes: ' . (int) $group['membersCount'] . '</div>';
    if ($group['objectClass'] !== []) {
        $html[] = '      <div>';
        foreach ($group['objectClass'] as $oc) {
            $html[] = '        <span class="pill">' . ldap_html_escape((string) $oc) . '</span>';
        }
        $html[] = '      </div>';
    }
    if ($group['members'] === []) {
        $html[] = '      <div class="empty">Sin integrantes</div>';
    } else {
        $html[] = '      <ul>';
        foreach ($group['members'] as $member) {
            $html[] = '        <li>[' . ldap_html_escape((string) $member['type']) . '] ' . ldap_html_escape((string) $member['value']) . '</li>';
        }
        $html[] = '      </ul>';
    }
    $html[] = '    </div>';
}
$html[] = '  </div>';
$html[] = '</body>';
$html[] = '</html>';

ldap_write_file($htmlPath, implode(PHP_EOL, $html) . PHP_EOL);

ldap_console_line('Reporte TXT: ' . $txtPath);
ldap_console_line('Reporte HTML: ' . $htmlPath);
exit(0);
