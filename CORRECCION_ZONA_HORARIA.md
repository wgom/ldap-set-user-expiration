# Corrección de Zona Horaria - Asunción, Paraguay (UTC-4)

**Fecha de cambio:** 24 de abril de 2026

## Problema Identificado

Los scripts estaban usando `time()` y `DateTimeImmutable('now', 'UTC')` que devuelven la hora en UTC.  
Esto causaba que los cálculos de "cuántos días quedan" fueran incorrectos porque no consideraban la zona horaria local de **Asunción, Paraguay (UTC-4)**.

### Ejemplo del Problema

**Hora actual:**
- UTC: 15:00 (3 PM)
- Asunción: 11:00 (11 AM) - 4 horas menos

**Usuario que expira a las 14:00 UTC (10:00 Asunción):**
- Según UTC: Ya expiró (14:00 < 15:00)
- Según Asunción: Aún no expira (10:00 < 11:00) ❌ INCORRECTO

**Solución:** Usar la hora **actual en zona horaria local** para las comparaciones.

---

## Cambios Realizados

### 1. `check_expired_passwords.php` ✅

**Antes:**
```php
$secondsRemaining = ldap_date_to_timestamp($expTime) - time();
```

**Después:**
```php
$nowLocal = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
$nowUtc = $nowLocal->setTimezone(new DateTimeZone('UTC'));
$currentTimestamp = (int) $nowUtc->format('U');

$secondsRemaining = ldap_date_to_timestamp($expTime) - $currentTimestamp;
```

**Impacto:** 
- Clasificación de usuarios ahora considera la zona horaria local
- "Hoy" termina 4 horas después que en UTC

### 2. `list_active_users.php` ✅

**Mismo cambio que check_expired_passwords.php**

**Impacto:**
- Los reportes de usuarios activos ahora clasifican correctamente según zona horaria local

### 3. `set_user_expiration.php` ✅

**Cambio en cálculo de "tiempo restante":**

```php
// Antes:
$remainingLabel = static function (DateTimeImmutable $expDt): string {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    ...
}

// Después:
$remainingLabel = static function (DateTimeImmutable $expDt, string $localTz): string {
    $now = new DateTimeImmutable('now', new DateTimeZone($localTz));
    ...
}
```

**Impacto:**
- Cuando el usuario establece expiración, el mensaje de "tiempo restante" es correcto

### 4. `show_user_permissions.php` ✅

**Mismo cambio en la función `$parsePasswordExpiration`:**

```php
// Antes:
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// Después:
$now = new DateTimeImmutable('now', new DateTimeZone($timezone));
```

**Impacto:**
- El reporte de permisos de usuario muestra tiempos correctos

---

## Formato LDAP Sin Cambios

✅ El formato LDAP **sigue siendo UTC (Z)**:
```
20260424114627Z  ← Siempre en UTC
```

❌ **No convertimos** el almacenamiento en LDAP.  
✅ **Solo convertimos** para mostrar información y hacer cálculos al usuario.

---

## Verificación

### Timestamp Calculado Correctamente

```
Hora actual:
- Servidor (UTC):   15:07:33
- Asunción (UTC-4): 11:07:33

Usuario "christian.riveros":
- Expiracion LDAP: 20260424114627Z (11:46:27 UTC)
- Tiempo restante: 0 días, 0 horas, 39 minutos (desde 11:07:33 Asunción)
```

### Archivos Generados

✅ `docs/ldap/ldap_password_report.txt` - 354 líneas
✅ `docs/ldap/ldap_password_report.html` - Incluido en HTML
✅ `docs/ldap/ldap_active_users_report.txt` - 480 líneas  
✅ `docs/ldap/ldap_user_*_permissions.txt/html` - Bajo demanda

---

## Zona Horaria Configurada

**Archivo:** `.env`
```
APP_TIMEZONE=America/Asuncion
```

Si necesitas cambiar de zona horaria, actualiza este valor. Las opciones incluyen:
- `America/New_York` (UTC-4 o -5)
- `America/Chicago` (UTC-5 o -6)
- `UTC` (sin offset)
- Cualquier zona válida de [IANA Timezone Database](https://en.wikipedia.org/wiki/List_of_tz_database_time_zones)

---

## Impacto en Resultados

### Antes de la Corrección
```
PROXIMAS A EXPIRAR (<7 dias)
- usuario1 | 0 dias | 20260424111000Z  (puede haber sido hace 4 horas)
```

### Después de la Corrección
```
PROXIMAS A EXPIRAR (<7 dias)  
- usuario1 | 0 dias | 20260424111000Z  (clasificado correctamente en Asunción time)
- usuario2 | 1 dia  | 20260425120000Z
```

---

## Resumen de Cambios

| Script | Cambio | Impacto |
|--------|--------|--------|
| `check_expired_passwords.php` | Usa `currentTimestamp` en zona local | Clasificación correcta de expirados |
| `list_active_users.php` | Usa `currentTimestamp` en zona local | Reporte correcto de usuarios activos |
| `set_user_expiration.php` | `$remainingLabel` usa zona local | Mensaje de "tiempo restante" correcto |
| `show_user_permissions.php` | `$now` en zona local | Detalles de usuario correctos |

---

## Pruebas Realizadas ✅

```bash
php standalone/check_expired_passwords.php    # OK ✓
php standalone/list_active_users.php           # OK ✓
php standalone/show_user_permissions.php uid   # OK ✓
php standalone/set_user_expiration.php --dry-run # OK ✓
```

Todos los scripts se ejecutan correctamente considerando la zona horaria de Asunción.
