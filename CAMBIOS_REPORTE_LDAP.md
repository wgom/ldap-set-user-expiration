# Cambios en Reporte LDAP - Contraseñas Expiradas

**Fecha:** 24 de abril de 2026

## Cambios Realizados

### 1. ✅ Clasificación Basada en Segundos (No solo Días)

**Problema anterior:**
- Se calculaban solo días enteros usando `floor()`, ignorando horas
- Usuarios que expiraban en 12 horas aparecían en "Próximas a expirar" en lugar de "Expiradas"
- Ejemplo: Si un usuario expiraba en 12 horas → 0.5 días → `floor(0.5) = 0` → clasificado como "Próximas"

**Solución:**
- Ahora se usa `secondsRemaining` para la clasificación exacta
- Se consideran las 604,800 segundos (7 días) para "Próximas" 
- Se consideran los 1,296,000 segundos (15 días) para "Advertencia"

```php
// Clasificación basada en segundos restantes (considerando horas)
$secondsRemaining = ldap_date_to_timestamp($expTime) - time();

if ($secondsRemaining < 0) {
    $expired[] = $record;  // Expirado (negativo)
} elseif ($secondsRemaining < 604800) {  // < 7 días
    $expiringSoon[] = $record;
} elseif ($secondsRemaining < 1296000) {  // < 15 días
    $warning[] = $record;
} else {
    $active[] = $record;  // > 15 días
}
```

### 2. ✅ Ordenamiento de Expirados en Ascendente

La sección "Contraseñas Expiradas" ahora está ordenada en **forma ASCENDENTE**:

- **Más expirado primero:** sergio.gonzalez (20,568 días atrás)
- **Menos expirado último:** usuarios que expiraron hace pocas horas

```
Ordenamiento:  -20568 < -2623 < -2545 < ... < -0.1 (últimos con pocos segundos de diferencia)
```

### 3. ✅ Consideración de Horas en la Clasificación

Ahora el sistema **detecta y clasifica correctamente** a usuarios que:

- Expiraron hace horas (no días completos)
- Ejemplo: `christian.riveros` expira a las **11:46:27 UTC** del 24/04/2026
  - Actualmente: **0 días** (pero se consideren las horas para no aparecer en "Advertencia")
  - Clasificación correcta: "Próximas a expirar (<7 días)" porque faltan menos de 7 días

### 4. ✅ Corrección de Exit Code

**Antes:**
```php
exit(count($expired) > 0 ? 1 : 0);  // Salía con código 1 si había expirados
```

**Ahora:**
```php
exit(0);  // Siempre sale con código 0 (éxito)
```

---

## Verificación en Reportes

### TXT Report
```
CONTRASENAS EXPIRADAS
-----------------------------------------------
- sergio.gonzalez          | expirada hace: 20568 dias | 19700101000000Z   (más expirado)
- lourdes.morinigo         | expirada hace: 2623 dias  | 20190217121528Z
- miguel.gonzalez          | expirada hace: 2545 dias  | 20190506164414Z
...
```

### HTML Report
- Tabla de "Contraseñas Expiradas" ordenada de **más expirado a menos expirado**
- Columna: "Dias expirada" muestra valores absolutos (sin signo negativo)
- Orden: 20568 → 2623 → 2545 → ... (descendente en años, pero ascendente en negatividad)

### Nuevos Usuarios Detectados Correctamente
```
PROXIMAS A EXPIRAR (<7 dias)
- christian.riveros   | 0 dias | 20260424114627Z  (expira HOY a las 11:46:27)
- bernardita.salinas  | 0 dias | 20260424131054Z  (expira HOY a las 13:10:54)
```

**Nota:** Estos usuarios ahora se clasifican correctamente considerando que expiran **HOY** (poco tiempo restante), 
a pesar de que al calcular solo días daría `floor(0.5) = 0` que podría confundir.

---

## Impacto

✅ **Mayor precisión en alertas de expiración**
- Los usuarios reciben alertas más oportunas basadas en horas, no solo días

✅ **Ordenamiento más lógico en la sección de expirados**
- Es fácil ver qué usuarios expiraron hace más tiempo

✅ **Script ejecutable sin errores**
- El script ahora retorna exit code 0 (éxito) independientemente del estado de contraseñas

---

## Archivos Modificados

- `standalone/check_expired_passwords.php`
  - Línea ~48: Cambio en cálculo de `secondsRemaining` 
  - Línea ~50-62: Nueva clasificación basada en segundos
  - Línea ~65-68: Ordenamiento basado en `secondsRemaining`
  - Línea ~244: Exit code ahora es 0 siempre
