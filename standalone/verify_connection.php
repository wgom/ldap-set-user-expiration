<?php

declare(strict_types=1);

require __DIR__ . '/common.php';

ldap_console_line('Verificacion de conectividad LDAP');
ldap_console_line('');

$config = ldap_script_config();

ldap_console_line('Configuración cargada:');
ldap_console_line('  Host: ' . $config['host']);
ldap_console_line('  Puerto: ' . $config['port']);
ldap_console_line('  Base DN: ' . $config['base_dn']);
ldap_console_line('  Bind DN: ' . ($config['bind_dn'] ? '(configurado)' : '(no configurado)'));
ldap_console_line('  SSL: ' . ($config['ssl'] ? 'si' : 'no'));
ldap_console_line('  Timeout: ' . $config['timeout'] . 's');
ldap_console_line('');

ldap_console_line('Intentando conectar...');

try {
    $conn = ldap_connect($config['host'], $config['port']);
    if (!$conn) {
        throw new Exception('ldap_connect() retorno false');
    }

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, $config['timeout']);

    ldap_console_line('✓ Conexion establecida');

    if (!$config['bind_dn']) {
        ldap_console_line('⚠️  No hay LDAP_BIND_DN configurado. Intentando bind anonimo...');
    }

    if (!@ldap_bind($conn, $config['bind_dn'], $config['bind_password'])) {
        throw new Exception('ldap_bind() fallo: ' . ldap_error($conn));
    }

    ldap_console_line('✓ Autenticacion exitosa');
    ldap_console_line('');

    ldap_console_line('Buscando usuarios...');
    $search = ldap_search($conn, $config['base_dn'], '(objectClass=inetOrgPerson)', ['uid', 'cn'], 0, 5);
    if (!$search) {
        throw new Exception('ldap_search() fallo: ' . ldap_error($conn));
    }

    $entries = ldap_get_entries($conn, $search);
    $count = $entries['count'] ?? 0;
    ldap_console_line("✓ Busqueda exitosa ({$count} usuario(s) encontrados)");

    if ($count > 0) {
        ldap_console_line('');
        ldap_console_line('Primeros usuarios:');
        for ($i = 0; $i < min(3, $count); $i++) {
            $uid = $entries[$i]['uid'][0] ?? '(sin uid)';
            $cn = $entries[$i]['cn'][0] ?? '(sin cn)';
            ldap_console_line("  - {$uid} ({$cn})");
        }
    }

    ldap_close($conn);
    ldap_console_line('');
    ldap_console_line('✓ Todas las pruebas pasadas correctamente');
    exit(0);
} catch (Exception $e) {
    ldap_console_line('');
    ldap_console_error('✗ Error: ' . $e->getMessage());
    exit(1);
}
