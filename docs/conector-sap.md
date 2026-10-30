# Conector SAP Business One (Service Layer)

## 1. Qué está conectado

| Operación | Service Layer | Estado |
|---|---|---|
| Iniciar sesión | `POST /Login` | Conectado |
| Crear recibo de producción | `POST /InventoryGenEntries` | Conectado |
| Leer un recibo que SAP dijo haber creado | `GET /InventoryGenEntries(<DocEntry>)` | Conectado |
| Buscar un recibo después de un timeout | `GET /InventoryGenEntries?$filter=<campo de correlación> eq '<uuid>'` y luego `GET /InventoryGenEntries(<DocEntry>)` | Conectado, pero **necesita el campo de correlación** (ver sección 4) |
| Consultar una OF | `GET /ProductionOrders?$filter=DocumentNumber eq <n> and startswith(ItemNo,'PT')` y el producto en `GET /Items('<código>')` | Conectado |
| Traer todas las OF liberadas (tablero de OF, `sap:sync-orders`) | `GET /ProductionOrders?$filter=ProductionOrderStatus eq 'boposReleased' and startswith(ItemNo,'PT')` con `Prefer: odata.maxpagesize=500`, siguiendo `odata.nextLink`; productos con `GET /Items?$filter=ItemCode eq '…' or …` de a 20 | Conectado |
| Traslado de stock 04 → 05 | `POST /StockTransfers` | Conectado |
| Leer un traslado que SAP dijo haber creado | `GET /StockTransfers(<DocEntry>)` | Conectado |
| Traslado a no conforme 04 → 11C (calidad retiene) y 11C → 05 (liberada y contada) | `POST /StockTransfers`, el mismo cuerpo con otro origen o destino | Conectado. Ver `docs/calidad.md` |

Ya se creó un recibo real en el SAP DEV. Ahí se vio que el campo `U_TRZ_UUID` no existe: SAP creó el recibo igual, pero la consulta por ese campo responde 400 (`Property 'U_TRZ_UUID' of 'Document'is invalid`). Las pruebas automáticas usan respuestas simuladas de Service Layer (`Http::fake`).

## 2. Configuración

En el `.env` del servidor:

```
SAP_DRIVER=service_layer
SAP_ENVIRONMENT=DEV
SAP_API_URL_DEV=https://servidor:50000/b1s/v1
SAP_COMPANY_DEV=
SAP_USER_DEV=
SAP_PASSWORD_DEV=
SAP_SL_VERIFY_TLS=true
SAP_SL_TIMEOUT=30
SAP_RECEIPT_CORRELATION_FIELD=U_TRZ_UUID
SAP_RECEIPT_COSTING_CODE=PCC
SAP_RECEIPT_COSTING_CODE2=PRODUCCI
SAP_RECEIPT_COSTING_CODE3=ANTIOQUI
SAP_TRANSFER_CARD_CODE=P811022882
SAP_TRANSFER_DISTRIBUTION_RULE=PCC
SAP_TRANSFER_DISTRIBUTION_RULE2=PRODUCCI
SAP_TRANSFER_DISTRIBUTION_RULE3=ANTIOQUI
```

Los valores de arriba ya son los de por defecto: no hace falta escribirlos salvo que cambien. Los almacenes del traslado salen de `SAP_TRANSFER_FROM_WAREHOUSE` (04) y `SAP_TRANSFER_TO_WAREHOUSE` (05).

- `SAP_ENVIRONMENT` elige el juego de credenciales: con `DEV` se leen las variables que terminan en `_DEV`. Para producción se agregan las `_PRD` (o el sufijo que usen) y se cambia `SAP_ENVIRONMENT`.
- `SAP_API_URL_<ENV>` es la raíz de Service Layer, terminada en `/b1s/v1`.
- Las credenciales solo se leen del entorno. No se escriben en logs, en la bitácora ni en la salida de los comandos.
- `php artisan app:preflight` revisa que estén completas, que la URL sea https y que el certificado se verifique.

**Probar la conexión** (solo lectura):

```bash
php artisan sap:check          # inicia sesión y lista las OF liberadas
php artisan sap:check 33128    # consulta una OF
php artisan sap:sync-orders    # trae y guarda todas las OF liberadas
```

## 3. Recibo de producción

Por cada recibo se envía:

```json
{
  "DocDate": "2026-10-21",
  "U_TRZ_UUID": "<request_uuid del recibo>",
  "DocumentLines": [
    {
      "BaseType": 202,
      "BaseEntry": 41659,
      "Quantity": 480,
      "WarehouseCode": "04",
      "CostingCode": "PCC",
      "CostingCode2": "PRODUCCI",
      "CostingCode3": "ANTIOQUI",
      "BatchNumbers": [
        {
          "BaseLineNumber": 0,
          "BatchNumber": "33128294",
          "Quantity": 480,
          "ManufacturingDate": "2026-10-21",
          "ExpiryDate": "2028-10-20",
          "AddmisionDate": "2026-10-21"
        }
      ]
    }
  ]
}
```

- **Una línea por lote**, como se definió. Cada línea va contra la OF (`BaseType` 202, `BaseEntry` = DocEntry de la OF) con un solo lote. `BaseLineNumber` es el número de esa línea (0, 1, 2…).
- **DocEntry de la OF**: sale de la consulta a SAP (etiquetas de caja o tablero de OF). Si la OF nunca se consultó, el recibo se rechaza sin llamar a SAP y dice que hay que consultarla.
- **Fechas**: `DocDate`, `ManufacturingDate` y `AddmisionDate` son la fecha de admisión, fijada al pedir el recibo. `ExpiryDate` es el vencimiento del lote, tomado de las etiquetas de caja.
- **Cantidades**: cajas × unidades por caja, en la unidad de inventario.
- **Normas de reparto**: fijas, desde la configuración. Si una se deja vacía, no se manda.

### Cómo se interpreta la respuesta

| Respuesta de SAP | Resultado |
|---|---|
| 201 con DocEntry y DocNum | Creado. Igual se lee de vuelta y se compara antes de confirmar |
| 4xx con error de SAP (por ejemplo, lote o cantidad no válidos) | Rechazado: el recibo queda fallido y las estibas se pueden pedir otra vez |
| Timeout, error de conexión, 401 después de volver a iniciar sesión, 408, 429 o 5xx | Incierto: pendiente de conciliación. Nunca se reenvía a ciegas |
| No se pudo iniciar sesión | Incierto: no se envió nada; la conciliación lo reintenta cuando SAP vuelva |

**Sesión**: se inicia al primer uso y se guarda en el proceso. Si SAP responde 401 (sesión vencida), se inicia sesión otra vez y se repite la llamada una sola vez. Un 401 significa que SAP no procesó la llamada, así que repetirla no duplica nada.

### Verificación antes de confirmar

Cuando SAP responde 201 (creado), la respuesta trae el recibo completo. Ese documento se compara con lo pedido lote por lote: producto, lote, almacén, cantidad, vencimiento y fabricación, además de la fecha del documento y la OF base. Si todo coincide, el recibo se confirma con el DocEntry y el DocNum de la respuesta, sin otra consulta. Si algo no coincide, queda pendiente para revisión y nunca se reenvía.

- Si la respuesta no trae las líneas, se lee el recibo con `GET /InventoryGenEntries(<DocEntry>)` y se compara ese.
- **Conciliar** un recibo que SAP ya había dado por creado lo lee por ese DocEntry, guardado en el intento de envío. No usa el campo de correlación.
- Si la consulta falla, el detalle del recibo muestra el mensaje de SAP.

## 3b. Traslado de stock 04 → 05

Lo pide el conteo ciego aprobado, o el botón "Reintentar traslado" del detalle de la estiba si el anterior falló. Se envía así, con los datos del JSON que compartieron:

```json
{
  "DocDate": "<fecha del día>",
  "FromWarehouse": "04",
  "ToWarehouse": "05",
  "CardCode": "P811022882",
  "StockTransferLines": [
    {
      "ItemCode": "<producto>",
      "Quantity": <unidades del lote>,
      "FromWarehouseCode": "04",
      "WarehouseCode": "05",
      "DistributionRule": "PCC",
      "DistributionRule2": "PRODUCCI",
      "DistributionRule3": "ANTIOQUI",
      "BatchNumbers": [{ "BatchNumber": "<lote>", "Quantity": <unidades>, "BaseLineNumber": <número de la línea> }]
    }
  ]
}
```

- Una línea por producto y lote. Si van varias estibas del mismo lote, se suman en una línea.
- **Fijos:** almacenes 04 y 05, el socio `P811022882` y las tres normas de reparto. Vienen de la configuración (sección 2).
- **Respuesta:** igual que el recibo. Con un 201 se compara lo que SAP respondió (producto, lote, cantidad, almacén de destino y de origen) y se confirma con su DocEntry y DocNum. Si la respuesta no trae las líneas, se lee con `GET /StockTransfers(<DocEntry>)`. Un 4xx claro deja el traslado fallido, con el mensaje de SAP, y la estiba lista para reintentar. Timeout o 5xx: pendiente de conciliación.
- **Sin campo de correlación.** El traslado no lleva un campo con el `request_uuid`. Si SAP no responde, no hay forma de saber si se creó: queda pendiente, la conciliación lo dice y nunca se reenvía solo, para no mover el stock dos veces. Alguien lo revisa en SAP.

## 4. Campo de correlación (importante)

Cuando SAP contesta, el campo no hace falta: se usa el DocEntry de la respuesta. **Sí hace falta cuando no contesta** (timeout, red caída, error 5xx). En ese caso no se sabe si el recibo se creó, y sin un campo que lleve el `request_uuid` no hay forma segura de averiguarlo. La conciliación falla con el 400 de SAP y el recibo queda pendiente hasta que alguien lo revise en SAP. Nunca se reenvía solo, para no duplicarlo.

- Por defecto se usa **`U_TRZ_UUID`**: un campo de usuario en la cabecera del recibo de producción (tabla OIGN), alfanumérico de 36 caracteres. **Hay que crearlo en SAP.**
- Mientras no exista, el SAP DEV crea el recibo igual: no rechaza la propiedad en el envío, solo en la consulta.
- Se puede usar otro campo con `SAP_RECEIPT_CORRELATION_FIELD` (por ejemplo `Comments`). Solo se aceptan nombres de campo simples, porque el valor va dentro de un filtro OData.

## 5. Orden de fabricación

Solo se traen OF de producto terminado: las que tienen un `ItemNo` que empieza por `PT` (configurable con `SAP_ORDER_ITEM_PREFIX`). Una OF de otro producto se trata como si SAP no la tuviera.

De `ProductionOrders` se usan: `AbsoluteEntry` (DocEntry), `DocumentNumber`, `ProductionOrderStatus`, `ProductionOrderType`, `ItemNo`, `PlannedQuantity`, `Warehouse`, `Priority`, `PostingDate`, `StartDate`, `DueDate` y `ProductionOrderOrigin`. Del producto (`Items`): `ItemName`, `BarCode`, `InventoryUOM`, `SalesQtyPerPackUnit`, `SalesPackagingUnit` y el campo de usuario `U_TEC_CajMstXPall`. Los tres últimos se guardan en el maestro de productos (`items.sales_qty_per_pack_unit`, `items.sales_packaging_unit` e `items.boxes_per_pallet`) cada vez que se sincroniza una OF.

**Unidades por caja (DP-02).** `SalesQtyPerPackUnit` son las unidades que lleva una caja del PT. Al sincronizar, ese valor queda como el factor de conversión vigente del producto (`box_conversion_factors`). De ahí salen la cantidad sugerida de etiquetas de caja y la cantidad de los recibos (cajas × unidades por caja).

**Cajas por estiba.** `U_TEC_CajMstXPall` es la cantidad real de cajas que lleva una estiba del PT. Solo se toma si es un entero mayor que cero; vacío, cero o con decimales no cambia nada. Al sincronizar, queda como el estándar de estiba vigente del producto (`pallet_standards`, fuente `Items.U_TEC_CajMstXPall`): reemplaza el estándar de prueba o la versión anterior, que se conserva como reemplazada, y queda en la bitácora (`maestros.sincronizados`). Si el valor no cambió no se crea versión nueva. Las estibas ya iniciadas conservan el estándar con el que se crearon; las nuevas toman el de SAP. De ese estándar sale cuándo se completa y se cierra la estiba al leer cajas.
- Si cambia en SAP, se crea una versión nueva y la anterior queda como reemplazada. Las etiquetas y los recibos que ya la usaron conservan la suya, y el cambio queda en la bitácora.
- Si en SAP está vacío o en cero, el factor vigente no se toca y no se inventa uno.

| Estado en SAP | En la aplicación |
|---|---|
| `boposPlanned` | Planificado |
| `boposReleased` | Liberado |
| `boposClosed` | Cerrado |
| `boposCancelled` | Cancelado |
| Otro | No se interpreta: la consulta falla y lo dice |

- El usuario de la OF y el campo "vinculado a" quedan vacíos: `ProductionOrders` da el usuario como número interno y no hay un campo directo para lo segundo.
- `Items.BarCode` trae el EAN-13 de la unidad. La etiqueta de caja imprime el EAN-14 armado con el indicador 1 (`SAP_BOX_GTIN_INDICATOR`), los 12 primeros dígitos del EAN-13 y un dígito de control nuevo. Ver `docs/etiquetas-de-caja.md`.

## 6. Lo que falta

- **Correr `sap:check` y un recibo de prueba contra el SAP DEV.** Los nombres de objetos y campos son los estándar de Service Layer y los del JSON que compartieron, pero no se han validado contra su versión.
- **Crear el campo `U_TRZ_UUID`** en la cabecera del recibo de producción, o definir otro. Sin él, un recibo que quede sin respuesta de SAP no se puede conciliar solo.
- **Probar el traslado contra el SAP DEV.** El cuerpo es el del JSON que compartieron. Las estibas cuyo traslado falló cuando no estaba conectado se reintentan con "Reintentar traslado" en su detalle.
- **Campo de correlación del traslado.** Si se quiere conciliar solo un traslado que quedó sin respuesta, hace falta un campo de usuario en la cabecera de `StockTransfers` (tabla OWTR), como `U_TRZ_UUID` en el recibo.
- **Decimales**: las cantidades viajan como número JSON. Con cantidades de hasta 15 dígitos significativos no hay pérdida.
