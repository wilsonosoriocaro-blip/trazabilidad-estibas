# Etiquetas de caja

## 1. Objetivo

Generar el QR que va pegado en cada caja de producto, en etiquetas de 10 × 8 cm para impresora Zebra. El operario escribe el número de la orden de fabricación (OF). El sistema la busca en su propia base, que el Tablero de OF mantiene al día con SAP B1 ("Actualizar desde SAP" o `sap:sync-orders`). Si está **Liberada**, muestra su encabezado y permite generar las etiquetas. No consulta SAP.

Es un módulo nuevo y aparte: no cambia la estación de producción, las estibas, los recibos, el conteo ni las transferencias.

## 2. Pantalla

| Ruta | Para | Permiso |
|---|---|---|
| `/box-labels` (menú Producción → Etiquetas de caja) | Consultar la OF, ver el encabezado, generar etiquetas, ver la vista previa, descargar el ZPL | `box_labels.print`: operario de producción y administrador de trazabilidad |

## 3. Cómo funciona

1. **Búsqueda en la aplicación** (`ConsultProductionOrder`). Nunca llama a SAP.
   - Si la OF no está en la aplicación, se dice y se pide actualizar el Tablero de OF desde SAP.
   - Si está pero quedó Planificada, Cerrada o Cancelada, se dice el estado.
2. **Encabezado.** El Tablero de OF guarda en `production_orders` los mismos campos del formulario de SAP: tipo, estado, producto, descripción, cantidad planificada y unidad, almacén, prioridad, fechas de la orden, de inicio y de finalización, usuario, origen y "vinculado a". También queda la respuesta completa de SAP (`sap_header`). La pantalla dice cuándo se actualizaron esos datos.
   - El producto queda en `items` con su código EAN.
   - Actualizar el tablero refresca el encabezado.
   - La vida útil del producto es un dato local y SAP no la pisa.
   - Si en SAP la OF cambió de producto y aquí ya tiene estibas, la actualización la deja como estaba y lo reporta.
3. **Generar etiquetas** (`GenerateBoxLabels`). El operario indica la cantidad de etiquetas, la fecha de producción (no puede ser futura) y la de vencimiento. La de vencimiento se propone sola con la vida útil del producto, si está cargada.
4. **Lote:** número de OF + día juliano de la fecha de producción, con 3 dígitos. Por ejemplo, la OF 33128 producida el 20/10/2026 (día 293) queda con lote `33128293`.
   - **Cambio de lote (supervisor).** Un usuario con el rol Supervisor de producción (permiso `box_labels.change_lot`) puede escribir otro lote al generar. Se puede mientras ese lote por defecto no tenga etiquetas impresas en la Zebra; después queda fijo. El lote admite letras, números, `.`, `_`, `/` y `-`, hasta 40 caracteres, y nunca puede ser de otra OF. La bitácora guarda el lote usado y el que tocaba por defecto.
   - **Qué es una impresión.** Solo la impresión directa en la Zebra que la impresora aceptó. Cada una queda en `box_label_prints` (quién, cuándo, impresora, tanda y lote; no admite cambios ni borrados) y en la bitácora. Descargar el ZPL no cuenta como impresión.
5. **Sin límite por OF.** Se pueden generar todas las etiquetas que hagan falta, también más de las que llena la cantidad planificada (errores de impresión, cajas averiadas con etiqueta pegada). La pantalla muestra las sugeridas (cajas que llena la cantidad planificada, contando la última aunque quede incompleta; por ejemplo 800 unidades a 24 por caja dan 34) y avisa cuánto se pasa antes de generar. La bitácora guarda las sugeridas y las que ya había. La fila de la OF se bloquea mientras se genera una tanda, así los códigos de caja del lote nunca se repiten ni dejan huecos.
6. **Un vencimiento por lote.** Si ya hay etiquetas de ese lote, una tanda nueva tiene que traer la misma fecha de vencimiento. Ese vencimiento es el que va en el recibo de producción a SAP y en la etiqueta de la estiba.
7. **Qué se guarda.** Cada tanda queda en `box_label_batches` con el texto exacto del QR y el ZPL exacto. La tabla no admite cambios ni borrados (trigger). Generar y descargar quedan en la bitácora.

## 4. Contenido del QR

El QR lleva **solo el código único de la caja**, como el QR de la estiba:

```
TRZB:6f1c2b9e0d4a47c58e3b2a1f9c7d6e5b
```

- Cada etiqueta tiene su propio código opaco (32 caracteres al azar), guardado en `box_labels.qr_token`. Al leerlo, la aplicación busca la caja y de ahí saca la OF, el PT, el lote y el vencimiento.
- Etiqueta de 100 × 80 mm: PT y descripción arriba, lote, vence (MM/AAAA) y unidades por caja a la izquierda, el QR grande a la derecha con el código de caja legible debajo (por ejemplo `Caja 33128294-0007`), el ITF-14 con `(01)` y los dígitos abajo a la izquierda y el logo abajo a la derecha. Ese texto no va dentro del QR.
- El código de caja legible es lote + consecutivo de 4 dígitos, que sigue entre tandas del mismo lote. Con él una caja se cuenta una sola vez (ver `docs/lectura-manual.md`).
- Un QR corto se imprime con módulos más grandes y se lee más fácil.
- El EAN-14 va en su propio código de barras.

## 5. Etiqueta e impresión

ZPL II para una Zebra de 203 dpi: 800 × 640 puntos, es decir 100 × 80 mm.

```
┌────────────────────────────────────────┐
│ OF 33128                     ┌──────┐  │
│ PT1350023                    │  QR  │  │
│ GELATINA OLIMPICA SIN ...    │      │  │
│ Lote / Vence / Contenido     └──────┘  │
│────────────────────────────────────────│
│         ║│║│║  ITF-14  ║│║│║            │
│         1 20 00013 50023 3             │
└────────────────────────────────────────┘
```

**Por qué esta distribución:**

- **Arriba:** el texto a la izquierda, en el mismo orden que el QR, y el QR a la derecha. El tamaño del QR se calcula según el largo del texto (`qrMagnification`) para que una descripción larga nunca lo saque de la etiqueta.
- **Abajo:** el EAN-14 a todo el ancho, separado por una línea. Así una pistola no lee el QR cuando apunta al código de barras.

**EAN-14 (GTIN-14) como ITF-14**, la simbología GS1 para cajas corrugadas:

- **Barras:** entrelazado 2 de 5 con relación ancho/angosto de 2.5. La barra angosta mide 4 puntos (0.5 mm), por encima del mínimo GS1 de 0.495 mm. Alto de 17.5 mm.
- **Alrededor:** sin barras portadoras (sin recuadro), como lo pidió el negocio. Se dejan las zonas de silencio de 10 barras angostas a cada lado, que son indispensables para la lectura. GS1 recomienda las barras portadoras en cajas corrugadas para evitar lecturas parciales: si el lector de la línea falla, se vuelven a poner.
- **De dónde sale:** SAP guarda en `Items.BarCode` el EAN-13 de la unidad. El EAN-14 de la caja se arma como indica GS1: el dígito indicador **1**, los 12 primeros dígitos del EAN-13 y un dígito de control nuevo. Por ejemplo, 7700304364725 da 17700304364722. El indicador se cambia con `SAP_BOX_GTIN_INDICATOR` (de 1 a 8). Si SAP ya trae un código de 14 dígitos válido, se usa tal cual.
- **Validación:** si el código de SAP no es un EAN-13 ni un EAN-14 válido (largo o dígito de control mal), no se generan etiquetas, porque lo impreso no coincidiría con el código. El dígito de control lo manda el sistema y la Zebra no le agrega otro.
- **Texto bajo las barras:** `1 20 00013 50023 3`, agrupado como se acostumbra.

**Otros detalles de impresión:**

- **Una etiqueta por caja:** como cada una lleva su código, el ZPL trae un formato `^XA…^XZ` por caja (no una sola etiqueta repetida con `^PQ`).
- **Caracteres:** con `^CI28` salen bien la Ñ y las tildes.
- **Saltos de línea del QR:** van en hexadecimal (`^FH`).
- **Texto desde SAP:** cualquier `^` o `~` se quita, para que una descripción no pueda meter comandos en la impresora.
- **Envío a la Zebra:** el archivo `.zpl` se descarga desde la pantalla y se manda con la utilidad de Zebra (Zebra Setup Utilities, "enviar archivo") o con el driver en modo de texto sin procesar.
- **Vista previa:** la pantalla muestra la misma distribución, con el mismo QR y el mismo ITF-14. Lo que se imprime es el ZPL.

## 6. Pruebas

| Prueba | Qué demuestra |
|---|---|
| `BoxLabelLotTest` (6) | Lote del supervisor en las cajas y el ZPL; solo el supervisor y solo un lote válido; el lote se fija al imprimir en la Zebra (la descarga no cuenta, una impresión fallida tampoco); un lote no es de dos OF; impresiones inmutables; pantalla con el campo de lote y el aviso de más etiquetas |
| `BoxLabelsTest` (19) | Cada etiqueta con su código de caja; búsqueda en la base sin llamar a SAP; OF que no está en la aplicación, planificada, cerrada o cancelada; actualización del tablero que conserva la vida útil; cambio de producto con estibas; regla del lote; el QR con las líneas de la etiqueta y el EAN-14 aparte; ZPL con QR e ITF-14; dígito de control y patrón ITF-14 contra el ejemplo de GS1; EAN-13 o dígito de control erróneo rechazados; texto de SAP que intenta meter comandos; vencimiento; un solo vencimiento por lote; sin límite por OF; datos que faltan; tanda inmutable; permisos; pantalla completa con la descarga |
| `BoxLabelConcurrencyTest` | 12 tandas en paralelo sobre la misma OF nunca pasan de 34. Sin el bloqueo de la fila, la prueba falló 4 de 5 veces |

Además, desde una captura de la vista previa en el navegador, zxing-cpp leyó el QR (las seis líneas exactas) y el ITF-14 (`12000013500233`). El ZPL no se pudo dibujar con un renderizador externo porque el servicio está bloqueado en esta sesión; las medidas se revisaron a mano.

## 7. Uso en el resto del proceso

- **Recibo de producción.** Cada lote va a SAP con el vencimiento de sus etiquetas de caja y con fecha de fabricación igual a la de admisión. Sin etiquetas de caja del lote no hay recibo. Ver `docs/fase-4-recibos.md`.
- **Etiqueta de la estiba.** Muestra "Vence" con la fecha del lote. Si el lote todavía no tiene etiquetas de caja, sale una raya.

## 8. Lo que falta o está supuesto

- **Datos al día.** Si una OF se liberó en SAP después de la última actualización del tablero, no aparece aquí hasta actualizarlo.
- **Simulador.** Responde con los 40 PT reales y siete órdenes de ejemplo (33128 a 33134) basadas en la 33128 que mandaste. Los EAN-14 del simulador **no son reales**: llevan indicador 1 y prefijo 200, que es de uso interno.
- **Unidades por caja.** Salen de `Items.SalesQtyPerPackUnit` en SAP, que se guarda como factor de conversión al consultar o sincronizar la OF. Sin factor vigente no se generan etiquetas.
- **Vida útil.** No viene de SAP. Es un campo local por producto (`items.shelf_life_days`). Si está vacío, el operario escribe el vencimiento. Falta definir quién la carga y de dónde sale.
- **Cantidad planificada.** Supuse que está en la misma unidad de inventario que usa el factor de conversión, como en los recibos. Si SAP la diera en cajas, la cantidad sugerida cambia.
- **Impresora sin probar.** Revisé el ZPL a mano y con pruebas automáticas, pero no lo imprimí en una Zebra real. Antes de usarlo en planta hay que imprimir una etiqueta y leer el QR y el ITF-14 con el lector de la línea. El ITF-14 con barra de 0.5 mm queda al mínimo GS1: si el lector de bodega es de larga distancia, puede convenir una etiqueta más grande.
