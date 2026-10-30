# Lectura automática de cajas (lector fijo)

## 1. Cómo funciona

Un lector fijo en la línea lee el QR de cada caja (`TRZB:` y el código opaco) y manda el texto por TCP o por serial. El proceso `boxes:reader` se queda conectado al lector y cada lectura pasa por **las mismas reglas que la lectura manual** (`docs/lectura-manual.md`): la caja tiene que ser una etiqueta generada aquí, su OF tiene que seguir liberada, suma a la estiba abierta de esa OF y lote, y la estiba se cierra sola al llegar al estándar.

- **Una caja cuenta una sola vez**, la lea el lector fijo, la lea alguien a mano, o las dos cosas en cualquier orden. Las dos lecturas se guardan bajo el mismo registro de cajas, y la base acepta cada código de caja una vez.
- **Lecturas repetidas.** Si el lector lee dos veces la misma etiqueta mientras pasa la caja, la segunda se ignora sin alarma.
- **Cajas no contadas.** Una lectura fallida (por ejemplo `NoRead`), una etiqueta que no es de esta aplicación o una OF que ya no está liberada no suman caja. Quedan en la bitácora del lector (`scan.rejected`, sujeto el dispositivo), en el log, y levantan la alerta "Cajas no contadas por el lector" en `/operations`. Esa caja hay que leerla a mano.
- **Quién la leyó.** Cada lectura guarda el lector que la hizo (`pallet_scan_events.read_by_device_id`).
- **Alerta en la estación de producción.** El panel "Lectura automática de la línea" muestra la última lectura del lector en grande: **verde "Caja contada"** con el código de caja y cómo va la estiba, o **rojo "Caja no contada"** con el motivo, lo que leyó y "Lee esa caja a mano". Parpadea los primeros 15 segundos de cada lectura nueva. Debajo van las contadas y no contadas de la última hora, las últimas cajas no contadas y el estado de cada lector (conectado, DESCONECTADO o sin conectar aún). Se refresca cada 5 segundos y solo aparece si hay lectores fijos activos. La lectura manual usa el mismo estilo verde y rojo.

No depende de `TRACE_SCAN_INGESTION_ENABLED`, que es del endpoint de sensores que mandan OF, PT y lote (`POST /api/v1/scan-events`).

## 2. Configurar el lector

En el software del lector (cada marca tiene el suyo):

1. **Salida de datos:** el texto del código leído tal cual, sin prefijos.
2. **Terminador:** CR, LF o CR+LF. Cualquiera sirve. STX/ETX también se aceptan.
3. **Conexión TCP:** lo normal es dejar el lector como **servidor TCP** en un puerto (por ejemplo 23 o 2001) y que la aplicación se conecte (`--connect`). Si el lector solo puede ser cliente, se configura para que se conecte a la IP del servidor de la aplicación y un puerto libre (`--listen`).
4. **Serial:** si el lector va por RS-232/USB-serial, tiene que estar conectado físicamente al servidor de la aplicación (`--serial`). Anotar la velocidad (9600, 115200…), 8 bits, sin paridad, 1 bit de parada.
5. Si el lector manda un texto cuando no logra leer (`NoRead`, `ERROR`), dejarlo activo: así queda registro de las cajas que pasaron sin contarse.

## 3. Habilitarlo en la aplicación

1. Migrar: `php artisan migrate`.
2. Registrar el lector (una vez por lector):
   ```bash
   php artisan devices:register LEC-L1 "Lector fijo línea 1" --kind=LECTOR
   ```
   El token que muestra no se usa para el lector fijo; se puede ignorar.
3. Probar la conexión a mano (termina cuando el lector cierra o con Ctrl+C):
   ```bash
   php artisan boxes:reader LEC-L1 --connect=192.168.1.50:23
   php artisan boxes:reader LEC-L1 --listen=0.0.0.0:9100
   php artisan boxes:reader LEC-L1 --serial=/dev/ttyUSB0 --baud=9600
   ```
   Cada caja sale como `Contada: <código de caja> → <estiba>`, `Repetida: …` o `No contada: <motivo>`.
4. Dejarlo corriendo con Supervisor: copiar `deploy/supervisor/lectores-de-cajas.conf` a `/etc/supervisor/conf.d/`, ajustar código y conexión, y `supervisorctl reread && supervisorctl update`. Un bloque por lector.
5. Por TCP, si no llega nada en 10 minutos (`--idle-minutes`) el proceso cierra y vuelve a conectar, por si la conexión se cayó sin aviso. Para pausas largas de la línea no pasa nada: solo reconecta.
6. Para el modo `--listen`, abrir ese puerto en el firewall solo para la IP del lector. Para serial, el usuario `trazabilidad` tiene que estar en el grupo `dialout`.

Para dejar de usar un lector: `php artisan devices:deactivate LEC-L1` y parar su programa en Supervisor. Un lector inactivo no cuenta cajas y el proceso se detiene.

## 4. Alertas

| Alerta | Cuándo |
|---|---|
| El lector de cajas X no está conectado | Estuvo conectado y lleva más de `OPS_HEARTBEAT_MAX_AGE_MINUTES` sin conexión. Un lector registrado que nunca se conectó no alerta |
| Cajas no contadas por el lector | Alguna lectura del lector fijo no sumó caja en la ventana de `OPS_REJECTED_SCANS_WINDOW` |

## 5. Pruebas

`BoxReaderTest` (7): lecturas que llegan en pedazos y con STX/ETX; la misma caja leída dos veces por la línea, y por la línea y a mano en los dos órdenes, cuenta una; `NoRead` y etiquetas desconocidas quedan en la bitácora y alertan; solo un lector fijo activo cuenta; el comando conectado a un lector servidor TCP y esperando a un lector cliente TCP (procesos reales, `tests/Support/fake-box-reader.php`); alerta del lector desconectado. El modo serial no se probó con un equipo real.
