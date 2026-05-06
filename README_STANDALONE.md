# LDAP Standalone Scripts

Scripts PHP puros para gestionar LDAP sin depender de Laravel Artisan.

## Requisitos

- PHP CLI (con extensión LDAP habilitada)
- Acceso de red al servidor LDAP
- Credenciales LDAP en archivo `.env`

## Configuración

Copiar `.env.example` a `.env` y completar con tus credenciales:

```bash
cp .env.example .env
```

Luego editar `.env` con tus valores:

```env
LDAP_HOST=ldap1.cah.gov.py
LDAP_PORT=389
LDAP_BASE_DN=dc=cah,dc=gov,dc=py
LDAP_BIND_DN=uid=usuario,ou=People,dc=cah,dc=gov,dc=py
LDAP_BIND_PASSWORD=tu_password
APP_TIMEZONE=America/Asuncion
```

## Scripts Disponibles

### 1. check_expired_passwords.php
Verifica usuarios con contraseñas expiradas o próximas a expirar.

```bash
php standalone/check_expired_passwords.php
```

Genera reportes:
- `docs/ldap/ldap_password_report.txt` (texto)
- `docs/ldap/ldap_password_report.html` (visual con CSS)

**Clasificación:**
- Expiradas: < 0 días
- Próximas a expirar: < 7 días
- Advertencia: 7-15 días
- Activas: > 15 días

---

### 2. list_active_users.php
Lista usuarios activos (no expirados) ordenados por días restantes.

```bash
php standalone/list_active_users.php
```

Genera reportes:
- `docs/ldap/ldap_active_users_report.txt`
- `docs/ldap/ldap_active_users_report.html`

---

### 3. list_groups_members.php
Muestra todos los grupos LDAP e integrantes.

```bash
php standalone/list_groups_members.php
```

Genera reportes:
- `docs/ldap/ldap_groups_members_report.txt`
- `docs/ldap/ldap_groups_members_report.html`

---

### 4. show_user_permissions.php
Muestra grupos y permisos de un usuario específico.

```bash
php standalone/show_user_permissions.php willians.ojeda
```

Genera reportes:
- `docs/ldap/ldap_user_willians.ojeda_permissions.txt`
- `docs/ldap/ldap_user_willians.ojeda_permissions.html`

---

### 5. set_user_expiration.php
Actualiza la fecha de expiración de contraseña de un usuario.

```bash
php standalone/set_user_expiration.php UID VALOR [UNIDAD] [--dry-run]
```

**Parámetros:**
- `UID`: UID del usuario en LDAP
- `VALOR`: número de tiempo (ej: 30)
- `UNIDAD`: s=segundos, m=minutos, h=horas, d=días (defecto: d)
- `--dry-run`: mostrar cambios sin aplicar

**Ejemplos:**

```bash
# Establecer expiración en 30 días
php standalone/set_user_expiration.php willians.ojeda 30

# Establecer expiración en 6 horas
php standalone/set_user_expiration.php willians.ojeda 6 h

# Establecer expiración en 90 días (solo mostrar, no aplicar)
php standalone/set_user_expiration.php willians.ojeda 90 d --dry-run
```

---

## Salida de Reportes

Todos los scripts generan reportes en dos formatos:

### Texto (TXT)
- Legible en terminal
- Fácil de parsear con scripts
- Ideal para logging

### HTML
- Visualizable en navegador
- Diseño responsive con CSS
- Colores y clasificación visual
- Tema dark moderno

Los archivos HTML tienen estilos inline, no requieren archivos CSS externos.

---

## Variables de Entorno

| Variable | Defecto | Descripción |
|----------|---------|-------------|
| LDAP_HOST | ldap1.cah.gov.py | Servidor LDAP |
| LDAP_PORT | 389 | Puerto LDAP |
| LDAP_BASE_DN | dc=cah,dc=gov,dc=py | Base DN por defecto |
| LDAP_GROUP_BASE_DN | (LDAP_BASE_DN) | Base DN para búsqueda de grupos |
| LDAP_BIND_DN | (vacío) | DN para autenticación |
| LDAP_BIND_PASSWORD | (vacío) | Contraseña de bind |
| LDAP_SSL | false | Usar SSL (true/false) |
| LDAP_TIMEOUT | 10 | Timeout en segundos |
| APP_TIMEZONE | America/Asuncion | Zona horaria para reportes |

---

## Códigos de Salida

- `0`: Ejecución exitosa
- `1`: Error (conexión, usuario no encontrado, permiso denegado, etc.)

---

## Seguridad

⚠️ **Importante:**
- El archivo `.env` contiene credenciales. **Nunca lo commits a Git**
- Añade `.env` a `.gitignore` si trabajas en control de versiones
- Asegúrate que solo usuarios autorizados puedan leer `.env`

```bash
chmod 600 .env
```

---

## Estructura de Directorios

```
ldap/
├── .env                          # Configuración (Git-ignored)
├── .env.example                  # Ejemplo de configuración
├── standalone/
│   ├── common.php               # Funciones compartidas
│   ├── check_expired_passwords.php
│   ├── list_active_users.php
│   ├── list_groups_members.php
│   ├── show_user_permissions.php
│   └── set_user_expiration.php
├── docs/ldap/                   # Reportes generados
│   ├── ldap_password_report.txt
│   ├── ldap_password_report.html
│   └── ...
└── Commands/                     # Comandos Laravel (opcional)
```

---

## Ejemplos de Uso Avanzado

### Generar reporte cada hora (cron)
```bash
0 * * * * cd /home/willians.ojeda/Proyectos/otros/ldap && php standalone/check_expired_passwords.php
```

### Actualizar varias contraseñas
```bash
for uid in sergio.gonzalez lourdes.morinigo miguel.gonzalez; do
  php standalone/set_user_expiration.php "$uid" 90 d
done
```

### Probar cambios sin aplicarlos
```bash
php standalone/set_user_expiration.php willians.ojeda 30 d --dry-run
```

---

## Troubleshooting

### Error: "La extension LDAP de PHP no esta habilitada"
```bash
# En Debian/Ubuntu
sudo apt-get install php-ldap

# En RedHat/CentOS
sudo yum install php-ldap

# Reiniciar PHP o verificar
php -m | grep ldap
```

### Error: "No se puede conectar a ldap1.cah.gov.py:389"
- Verificar conectividad: `ping ldap1.cah.gov.py`
- Verificar puerto: `nc -zv ldap1.cah.gov.py 389`
- Revisar firewall y permisos de red

### Error: "Fallo de autenticacion LDAP"
- Revisar credenciales en `.env`
- Verificar DN exacto del usuario
- Confirmar que el usuario tiene permisos

---

## Diferencias con versión Laravel

| Aspecto | Laravel | Standalone |
|--------|---------|-----------|
| Ejecución | `php artisan ldap:...` | `php standalone/...php` |
| Configuración | `.env` en raíz | `.env` en raíz |
| Reportes | Iguales | Iguales |
| Dependencias | Laravel framework | PHP nativo |
| Portabilidad | Requiere Laravel | Solo PHP |










# Verificar conectividad
php standalone/verify_connection.php

# Generar reportes
php standalone/check_expired_passwords.php
php standalone/list_active_users.php
php standalone/show_user_permissions.php christian.riveros

# Modificar expiración (90 días)
php standalone/set_user_expiration.php sergio.gonzalez 90

# Probar cambios sin aplicar
php standalone/set_user_expiration.php sergio.gonzalez 90 d --dry-run

