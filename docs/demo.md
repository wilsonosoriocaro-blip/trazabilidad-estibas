# Datos de demostración

`php artisan demo:flow` llena una base local con un turno completo, de las lecturas del sensor al traslado de 04 a 05. Usa los mismos casos de uso que la aplicación y el simulador de SAP, así que lo que se ve en pantalla es lo que el sistema hace de verdad.

```bash
php artisan migrate:fresh --seed
php artisan demo:flow
```

Se niega a correr en producción, con un conector SAP real o si los datos ya existen.

## Qué deja

El turno es del último día terminado (`D`): lecturas desde las 06:00, paletizado a las 07:10, recibos a las 09:30, conteos con su transferencia a las 13:00, una OF recibida y transferida completa a las 14:00 y el tablero de OF actualizado desde SAP a las 16:00.

**Productos y órdenes.** El demo carga los 40 PT reales del catálogo de SAP (`PT1350002` a `PT1350041`) y consulta en el simulador cuatro órdenes liberadas, igual que la pantalla de etiquetas de caja:

| OF | Producto | Para qué |
|---|---|---|
| 33128 | PT1350023 · GELATINA OLIMPICA SIN SABOR 30 GR | Estándar de 10 cajas por estiba, 12 unidades por caja (así sus 800 unidades alcanzan para las 55 cajas leídas) |
| 33131 | PT1350019 · GELATINA ALKOSTO SIN SABOR 30 GR | Estándar de 8 cajas, 24 unidades por caja |
| 33132 | PT1350002 · GELATINA AZUCARADA BOOM LIMON 1000 GR | Sin estándar de estiba, a propósito: sus lecturas se rechazan |
| 33134 | PT1350030 · GELATINA MERCALDAS SIN SABOR 30 GR | 240 unidades, 24 por caja, 5 cajas por estiba. Se recibe y transfiere completa: queda lista para cerrar en SAP |

Los lotes siguen la regla de las etiquetas de caja: OF + día juliano. `33128DDD` es el lote del día `D`, y los lotes de 33128 de los dos días anteriores van como A2 y A3. Los códigos EAN-14 son del simulador (indicador 1 y prefijo 200, de uso interno) y la vida útil de 730 días es un valor de demo.

Antes de las lecturas, la demo genera las etiquetas de caja de cada lote (5 tandas). De ahí sale el vencimiento que llevan los recibos a SAP y la etiqueta de cada estiba.

| Estiba | Lote | Dónde queda |
|---|---|---|
| EST-00000001 | 33128 del día D | Contada bien y transferida a 05 |
| EST-00000002 | 33128 del día D | Contada bien y transferida a 05 |
| EST-00000003 | 33128 del día D | Discrepancia: se contaron 9 de 10. Retenida |
| EST-00000004 | 33128 del día D | Cierre manual con 5 cajas, contada bien y transferida a 05 |
| EST-00000005 | 33128 del día D−1 | Recibida en 04 y **sin contar**: para probar el conteo ciego a mano |
| EST-00000006 | 33128 del día D−2 | Recibo pendiente de conciliación (SAP creó el documento pero la respuesta se perdió) |
| EST-00000007 | 33131 del día D | Contada bien; la transferencia quedó pendiente de conciliación (timeout sin crear). Se maneja desde su detalle |
| EST-00000008 | 33131 del día D | Paletizada, sin etiqueta |
| EST-00000009 | 33131 del día D | En estibado, 3 de 8 cajas |
| EST-00000010 | 33134 del día D | Recibida, contada y transferida a 05 |
| EST-00000011 | 33134 del día D | Recibida, contada y transferida a 05. Con esta, los recibos cubren las 240 unidades planificadas |

Además:

- **Lecturas.** 3 lecturas rechazadas: 2 por producto sin estándar (OF 33132) y 1 por orden desconocida (99999). También 3 reenvíos duplicados, que no suman.
- **Alertas.** Las alertas de `/operations` muestran los pendientes, la discrepancia y la orden sin estándar.

## Tablero de OF

Entrar como `supervisor@example.com` en `/orders`. La demo trajo de SAP las cuatro OF liberadas (33128, 33131, 33132 y 33134); las planificadas, cerradas y canceladas no entran.

- **33134** sale primero: "Lista para cerrar en SAP" y "Completa en CEDI". En la campana está la notificación.
- **33128** y **33131** van en proceso, con parte transferida a CEDI y pendientes de conciliar con SAP.
- **33132** está sin avance, porque sus lecturas se rechazaron.

Ver `docs/tablero-de-of.md`.

## Etiquetas de caja

Entrar como `produccion@example.com` en `/box-labels` y consultar la OF 33128. Se ve el encabezado guardado y se pueden generar etiquetas (ya hay 55 generadas por la demo; las sugeridas son 67: 800 unidades a 12 por caja, y se pueden generar más). Cambiar el lote lo puede hacer un usuario con el rol Supervisor de producción (o Administrador). Para ver los rechazos: 33129 (planificada), 33130 (cerrada), 33133 (cancelada) o un número que no exista. Ver `docs/etiquetas-de-caja.md`.

## Usuarios

Contraseña `password` para todos:

| Usuario | Rol | Para ver |
|---|---|---|
| `supervisor@example.com` | Administrador de trazabilidad | Tablero, recibos, discrepancias, estado operativo; reintentar o conciliar transferencias desde el detalle |
| `produccion@example.com` | Operario de producción | Estación de producción |
| `cedi@example.com` | Operario logístico | Conteo ciego |
| `cedi2@example.com` | Operario logístico | Hizo el conteo que dio discrepancia |
| `admin@example.com` | Administrador | Todo, usuarios y auditoría |

## Simular la lectura del QR en el CEDI

Al final, el comando imprime el texto que leería el escáner para EST-00000005 (`TRZ1:` seguido de 32 caracteres). Para seguir el flujo desde ahí:

1. Entrar como `cedi@example.com` en `/cedi/count`, pegar ese texto y dar Enter. La pantalla muestra la estiba sin la cantidad esperada.
2. Escribir 10 cajas y registrar. Sale "Conteo aprobado" y, en la misma operación, se pide en SAP la transferencia de 04 a 05.
3. Con un worker corriendo (`composer dev` lo levanta), la transferencia pasa a Confirmado con su DocNum.
4. En `/pallets/5` queda la historia completa: lecturas, cierre, paletizado, etiqueta, recibo, conteo y transferencia.

Con otro número se abre una discrepancia, igual que con EST-00000003.

## Limitaciones

- Las decisiones pendientes (DP-01, DP-03) se encienden solo dentro del proceso del comando. En la aplicación siguen apagadas, por eso la estación de producción dice "Recepción de lecturas apagada (DP-01)".
- El simulador de SAP vive en memoria. Al conciliar un pendiente desde la pantalla, el simulador del worker no lo conoce: dice que no existe y, pasado el tiempo de gracia, lo reenvía. En SAP real la conciliación lo encontraría.
- En la pantalla, "Actualizar desde SAP" consulta al simulador del proceso web. Trae las mismas OF liberadas; el avance no cambia porque sale de los recibos guardados aquí.
- El scheduler no corre solo en local, así que `/operations` muestra "Sin señal" para el scheduler y los workers hasta que se levanten.
