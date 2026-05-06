# Formato LDAP GeneralizedTime y Conversión de Unidades

## Formato GeneralizedTime de LDAP

La columna **"Expiracion LDAP"** en los reportes usa el formato **GeneralizedTime** (ISO 8601):

```
YYYYMMDDHHmmssZ
```

### Desglose:
- **YYYY** = Año (4 dígitos)
- **MM** = Mes (01-12)
- **DD** = Día (01-31)
- **HH** = Hora (00-23, UTC)
- **mm** = Minuto (00-59)
- **ss** = Segundo (00-59)
- **Z** = Zona horaria (siempre UTC, indicado por Z)

### Ejemplos reales del servidor LDAP:

```
20260424104212Z  →  24 de abril de 2026, 10:42:12 UTC
20260524104212Z  →  24 de mayo de 2026, 10:42:12 UTC
20190217121528Z  →  17 de febrero de 2019, 12:15:28 UTC
```

---

## Conversión de Unidades en set_user_expiration.php

El script `set_user_expiration.php` convierte correctamente cada unidad de tiempo a **segundos**:

| Unidad | Símbolo | Conversión | Segundos |
|--------|---------|-----------|----------|
| Segundos | `s` | 1 segundo | 1 |
| Minutos | `m` | 1 minuto | 60 |
| Horas | `h` | 1 hora | 3600 |
| Días | `d` | 1 día | 86400 |

### Cálculo Interno:

```
total_segundos = valor * segundos_por_unidad

Luego suma a la fecha/hora actual:
nueva_fecha = ahora + total_segundos
```

### Ejemplos de Ejecución:

**1. Establecer expiración en 30 días:**
```bash
php standalone/set_user_expiration.php willians.ojeda 30 d
```
- Cálculo: `30 * 86400 = 2,592,000 segundos`
- Si hoy es 24/04/2026 10:42:12 UTC
- Nueva expiración: 24/05/2026 10:42:12 UTC
- Formato LDAP: `20260524104212Z`

**2. Establecer expiración en 6 horas:**
```bash
php standalone/set_user_expiration.php willians.ojeda 6 h
```
- Cálculo: `6 * 3600 = 21,600 segundos`
- Nueva expiración: 24/04/2026 16:42:12 UTC
- Formato LDAP: `20260424164212Z`

**3. Establecer expiración en 30 minutos:**
```bash
php standalone/set_user_expiration.php willians.ojeda 30 m
```
- Cálculo: `30 * 60 = 1,800 segundos`
- Nueva expiración: 24/04/2026 11:12:12 UTC
- Formato LDAP: `20260424111212Z`

**4. Establecer expiración en 300 segundos (5 minutos):**
```bash
php standalone/set_user_expiration.php willians.ojeda 300 s
```
- Cálculo: `300 * 1 = 300 segundos`
- Nueva expiración: 24/04/2026 10:47:12 UTC
- Formato LDAP: `20260424104712Z`

---

## Unidad Predeterminada

Si no especificas la unidad, se asume `d` (días):

```bash
# Estas dos son equivalentes:
php standalone/set_user_expiration.php willians.ojeda 30
php standalone/set_user_expiration.php willians.ojeda 30 d
```

---

## Validación del Código

En `standalone/set_user_expiration.php`, la conversión se realiza así:

```php
$units = [
    's' => ['label' => 'segundo(s)', 'seconds' => 1],
    'm' => ['label' => 'minuto(s)', 'seconds' => 60],
    'h' => ['label' => 'hora(s)', 'seconds' => 3600],
    'd' => ['label' => 'dia(s)', 'seconds' => 86400],
];

$totalSeconds = $value * $units[$unit]['seconds'];

$newExpRaw = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify("+{$totalSeconds} seconds")
    ->format('YmdHis\\Z');
```

✅ **Está 100% correcto.** Cada unidad se multiplica correctamente por sus segundos.

---

## Verificación de Reportes Generados

Los reportes muestran la columna "Expiracion LDAP" en este formato:

```
Dias restantes | Expiracion LDAP
4              | 20260428124500Z
1              | 20260425001234Z
```

Esto significa:
- Usuario 1: Expira el 28 de abril de 2026 a las 12:45:00 UTC (4 días)
- Usuario 2: Expira el 25 de abril de 2026 a las 00:12:34 UTC (1 día)

---

## Notas Importantes

1. **Todas las fechas en LDAP están en UTC (Z)**
   - Los reportes convierten a tu zona horaria (APP_TIMEZONE) para visualización
   - El cálculo siempre se hace en UTC internamente

2. **Precisión de Segundos**
   - LDAP almacena hasta segundos, no milisegundos
   - No hay forma de establecer una expiración más precisa que 1 segundo

3. **Rango de Años**
   - Formato YYYY soporta años desde 0000 a 9999
   - Ampliamente suficiente para cualquier caso práctico

4. **El formato es Estándar**
   - GeneralizedTime es el estándar ISO 8601 usado en LDAP, X.509, etc.
   - Totalmente portable y compatible

