# Informe de cumplimiento

Fecha: 20 de octubre de 2026. Versión: rama `main` más la Fase 7.

Cada requisito apunta a pruebas con nombre `Clase::metodo`. La prueba `ComplianceReportTest` lee este archivo y falla si alguna de esas pruebas no existe, así el informe no se queda viejo sin que nadie lo note.

## 1. Estado en una tabla

El prompt pide distinguir cuatro niveles. Este es el estado real:

| Nivel | Estado |
|---|---|
| **Terminado funcionalmente** | Sí, para todo lo que no depende de una decisión pendiente. Lo que depende de DP-01, DP-03 y DP-04 está implementado y **apagado**. DP-08 no está implementado |
| **Probado en entorno simulado** | Sí. 362 pruebas contra PostgreSQL 16 real y el simulador de SAP (`FakeSapGateway`), incluidas pruebas con procesos paralelos |
| **Probado en integración** | No. El conector de Service Layer para recibos y OF existe y está probado con respuestas simuladas, pero no se ha corrido contra el SAP DEV (no hay acceso desde esta sesión). La transferencia 04 → 05 no está conectada. Tampoco se probó con sensores, lectores ni impresoras reales |
| **Validado en SAP real** | No |

**No está listo para producción.** Faltan las decisiones de la sección 9, el conector SAP real y una prueba en planta. `php artisan app:preflight` falla a propósito mientras `SAP_DRIVER=fake`.

## 2. Requisitos funcionales

| ID | Requisito | Pruebas |
|---|---|---|
| RF-01 | Recibir eventos de sensores | `ScanEventApiTest::test_an_accepted_box_answers_201_with_the_pallet`, `ScanEventApiTest::test_unknown_wrong_and_inactive_devices_get_401` |
| RF-02 | Validar producto, lote y orden | `ProcessScanEventTest::test_every_master_problem_has_its_own_reason` |
| RF-03 | Crear estibas automáticamente | `ProcessScanEventTest::test_the_first_scan_creates_a_pallet_with_the_standard_frozen` |
| RF-04 | Acumular cajas con eventos únicos | `ProcessScanEventTest::test_each_valid_scan_adds_exactly_one_box`, `ProcessScanEventTest::test_a_repeated_event_is_not_counted_again` |
| RF-05 | Finalizar al llegar al estándar | `ProcessScanEventTest::test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` |
| RF-06 | Cierre manual | `CloseManuallyTest::test_it_closes_with_the_real_count_and_records_who_and_why` |
| RF-07 | Confirmar paletizado | `PalletizeAndLabelTest::test_a_finished_pallet_is_palletized_with_who_and_when` |
| RF-08 | Etiqueta con QR, impresión y reimpresión | `PalletizeAndLabelTest::test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity`, `PalletDetailAndLabelTest::test_the_label_shows_what_the_floor_needs_and_a_qr_with_only_the_token` |
| RF-09 | Confirmar etiqueta aplicada (DP-03, apagado) | `PalletizeAndLabelTest::test_label_confirmation_is_refused_while_dp03_is_open`, `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it` |
| RF-10 | Tablero administrativo | `PalletBoardTest::test_every_filter_narrows_the_list`, `PalletBoardTest::test_indicators_count_each_stage` |
| RF-11 | Recibos de producción en SAP | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references` |
| RF-12 | Conciliar recibos inciertos | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create` |
| RF-13 | Escanear QR en CEDI | `BlindCountTest::test_only_received_and_not_transferred_pallets_are_counted` |
| RF-14 | Validar identidad y estado en el servidor | ídem, más `BlindCountSecrecyTest::test_the_count_in_progress_cannot_be_swapped_from_the_browser` |
| RF-15 | Conteo ciego | `BlindCountTest::test_a_matching_count_approves_the_audit_and_transfers_the_pallet_to_05` |
| RF-16 | Discrepancia bloquea el traslado | `BlindCountTest::test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter`, `InventoryTransferFlowTest::test_a_count_that_does_not_match_moves_nothing` |
| RF-17 | Novedades para revisión | `DiscrepanciesPageTest::test_admins_see_counted_and_system_quantities_and_the_pending_policy` |
| RF-18 | Transferencia 04 → 05 al aprobar el conteo ciego | `InventoryTransferFlowTest::test_an_approved_count_transfers_the_pallet_from_04_to_05_with_sap_references`, `InventoryTransferFlowTest::test_the_transfer_request_and_the_count_commit_together_or_not_at_all` |
| RF-19 | Un documento por estiba (el agrupado se eliminó por decisión del negocio) | `InventoryTransferFlowTest::test_an_approved_count_transfers_the_pallet_from_04_to_05_with_sap_references`, `PalletTransferActionsTest::test_the_transfers_screen_no_longer_exists` |
| RF-20 | Conciliar traslados inciertos | `InventoryTransferFlowTest::test_a_lost_answer_is_reconciled_without_a_second_create` |
| RF-21 | Trazabilidad estiba → auditoría → documento → línea | `InventoryTransferFlowTest::test_the_full_chain_is_traceable_from_the_pallet` |

## 3. Reglas de negocio

| ID | Regla | Pruebas |
|---|---|---|
| RN-01 | Una estiba = una orden, un producto, un lote; identidad inmutable | `PalletInvariantsTest::test_identity_is_immutable`, `ProcessScanEventTest::test_orders_items_and_lots_never_mix` |
| RN-02 | Un evento cuenta una vez | `ScanEventInvariantsTest::test_an_event_id_is_unique_per_device`, `ScanConcurrencyTest::test_the_same_events_sent_by_many_workers_count_once` |
| RN-03 | Sin identificador confiable no hay producción (DP-01) | `ProcessScanEventTest::test_nothing_is_processed_while_dp01_is_open`, `ScanEventApiTest::test_ingestion_answers_503_while_dp01_is_open` |
| RN-04 | Estándar vigente y positivo | `MasterInvariantsTest::test_current_values_must_be_positive_and_invalid_rows_say_why`, `ProcessScanEventTest::test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason` |
| RN-05 | Estándar congelado | `PalletInvariantsTest::test_a_later_standard_change_does_not_touch_existing_pallets`, `ProcessScanEventTest::test_a_new_standard_applies_to_new_pallets_only` |
| RN-06 | Consecutivo único, sin huecos | `PalletInvariantsTest::test_pallet_codes_are_unique`, `ProcessScanEventTest::test_a_rolled_back_pallet_gives_its_number_back`, `ScanConcurrencyTest::test_parallel_combinations_never_mix_and_share_one_gapless_sequence` |
| RN-07 | Nada confirmado sin verificar en SAP | `SapDocumentInvariantsTest::test_confirmation_requires_doc_entry_and_doc_num`, `ProductionReceiptFlowTest::test_a_document_that_does_not_match_the_request_is_never_confirmed_nor_resent` |
| RN-08 | No recibir dos veces | `SapDocumentInvariantsTest::test_a_pallet_cannot_be_held_by_two_receipts`, `ReceiptConcurrencyTest::test_parallel_receipt_requests_for_the_same_pallets_hold_each_pallet_once` |
| RN-09 | No trasladar dos veces | `SapDocumentInvariantsTest::test_a_pallet_cannot_be_held_by_two_transfers_and_warehouses_must_differ`, `TransferConcurrencyTest::test_parallel_retries_for_the_same_pallets_hold_each_pallet_once` |
| RN-10 | Dimensiones de estado independientes | `PalletInvariantsTest::test_each_dimension_needs_the_previous_process` |
| RN-11 | SAP es la fuente del inventario | No hay ajustes locales. `BlindCountTest::test_closing_a_case_needs_an_approved_action_a_different_person_and_never_adjusts_anything` |
| RN-12 | Concurrencia protegida | `ScanConcurrencyTest::test_parallel_scans_of_one_combination_fill_pallets_exactly`, `ScanConcurrencyTest::test_manual_closes_racing_scans_never_lose_or_overcount_boxes`, `ReceiptConcurrencyTest::test_parallel_receipt_requests_for_the_same_pallets_hold_each_pallet_once`, `TransferConcurrencyTest::test_parallel_retries_for_the_same_pallets_hold_each_pallet_once` |
| RN-13 | Operaciones críticas auditadas | `TraceEventsTest::test_the_logger_records_actor_subject_states_and_correlation`, `TraceEventsTest::test_traces_cannot_be_updated_deleted_or_truncated` |
| RN-14 | Discrepancias sin ajuste automático | `BlindCountTest::test_closing_a_case_needs_an_approved_action_a_different_person_and_never_adjusts_anything` |
| RN-15 | No retroceder estados confirmados | `PalletInvariantsTest::test_confirmed_states_cannot_go_back`, `StateTransitionsMatchDatabaseTest::test_enum_and_database_agree_on_every_pair` |
| RN-16 | Sin secretos en logs | `RedactorTest::test_secret_keys_are_masked_at_any_depth_and_the_rest_is_kept`, `TraceEventsTest::test_context_and_log_lines_never_carry_secrets`, `HardeningTest::test_log_lines_are_json_with_the_correlation_id_and_no_secrets` |
| RN-17 | Nada pendiente por suposición | `ServiceLayerTransferFlowTest::test_a_transfer_without_an_answer_stays_pending_and_is_never_resent`, `BlindCountTest::test_a_discrepancy_blocks_new_counts_until_dp04_allows_a_reaudit`, `HealthAndPreflightTest::test_preflight_fails_on_an_unsafe_configuration` |
| RN-18 | Toda regla con prueba | Este informe y `ComplianceReportTest::test_every_test_cited_in_the_report_exists` |

## 4. Criterios de aceptación

Los textos de CA-01 a CA-18 los propuse en la Fase 0 porque el prompt no los trae. Si existe un documento oficial, hay que ajustar esta tabla.

| ID | Criterio | Pruebas | Resultado |
|---|---|---|---|
| CA-01 | El primer evento crea la estiba con el estándar congelado | `ProcessScanEventTest::test_the_first_scan_creates_a_pallet_with_the_standard_frozen` | Pasa |
| CA-02 | Cada evento suma una caja; un duplicado no | `ProcessScanEventTest::test_each_valid_scan_adds_exactly_one_box`, `ScanEventApiTest::test_a_resend_answers_200_and_changes_nothing` | Pasa |
| CA-03 | Al estándar se finaliza una vez y el siguiente abre otra | `ProcessScanEventTest::test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` | Pasa |
| CA-04 | Eventos concurrentes: una estiba activa y suma exacta | `ScanConcurrencyTest::test_parallel_scans_of_one_combination_fill_pallets_exactly` | Pasa |
| CA-05 | No se mezclan órdenes, productos ni lotes | `ProcessScanEventTest::test_orders_items_and_lots_never_mix`, `ScanConcurrencyTest::test_parallel_combinations_never_mix_and_share_one_gapless_sequence` | Pasa |
| CA-06 | Estándar ausente, inválido o vencido rechaza con causa | `ProcessScanEventTest::test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason`, `ProcessScanEventTest::test_every_master_problem_has_its_own_reason` | Pasa |
| CA-07 | Cierre manual entre 1 y estándar − 1, conserva la cantidad | `CloseManuallyTest::test_it_closes_with_the_real_count_and_records_who_and_why`, `CloseManuallyTest::test_an_empty_pallet_cannot_be_closed`, `PalletInvariantsTest::test_manual_close_needs_between_one_box_and_standard_minus_one_and_who_closed` | Pasa |
| CA-08 | No paletizar sin finalizar ni etiquetar sin paletizar | `PalletizeAndLabelTest::test_a_pallet_in_process_cannot_be_palletized_nor_palletized_twice`, `PalletizeAndLabelTest::test_a_label_needs_a_palletized_pallet` | Pasa |
| CA-09 | Reimpresión sin cambiar identidad ni cantidad, auditada | `PalletizeAndLabelTest::test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity` | Pasa |
| CA-10 | El QR solo identifica | `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it`, `PalletizeAndLabelTest::test_the_qr_carries_only_the_opaque_token` | Pasa |
| CA-11 | Tres estibas agrupadas y separadas, conversión correcta | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references`, `ProductionReceiptFlowTest::test_three_pallets_in_separate_receipts`, `ProductionReceiptFlowTest::test_boxes_convert_with_the_current_factor_and_the_line_keeps_which_one` | Pasa (simulador) |
| CA-12 | Timeout → pendiente, sin segunda creación ciega | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create`, `ProductionReceiptFlowTest::test_a_send_left_hanging_by_a_dead_worker_goes_to_reconciliation_not_to_a_new_send` | Pasa (simulador) |
| CA-13 | La conciliación encuentra el documento y no duplica | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create`, `ProductionReceiptFlowTest::test_a_document_proved_absent_is_resent_only_after_the_grace_period` | Pasa (simulador) |
| CA-14 | Solo se audita con recibo confirmado y sin trasladar | `BlindCountTest::test_only_received_and_not_transferred_pallets_are_counted` | Pasa |
| CA-15 | El operario no obtiene la cantidad por ninguna vía | `BlindCountSecrecyTest::test_the_page_state_and_responses_never_carry_the_expected_quantity`, `BlindCountSecrecyTest::test_the_full_http_page_does_not_carry_it_either`, `BlindCountSecrecyTest::test_no_other_screen_or_endpoint_shows_quantities_to_the_operator`, `BlindCountSecrecyTest::test_the_operator_never_receives_the_discrepancy_notification` | Pasa |
| CA-16 | Conteo distinto: discrepancia, bloqueo, novedad, aviso | `BlindCountTest::test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter` | Pasa |
| CA-17 | Transferencia solo de aprobadas, sin duplicados, con relación estiba-documento-línea | `InventoryTransferFlowTest::test_a_count_that_does_not_match_moves_nothing`, `InventoryTransferFlowTest::test_an_approved_count_transfers_the_pallet_from_04_to_05_with_sap_references`, `TransferConcurrencyTest::test_parallel_retries_for_the_same_pallets_hold_each_pallet_once` | Pasa (simulador) |
| CA-18 | Solo una confirmación verificada marca CONFIRMADO_05 | `InventoryTransferFlowTest::test_a_document_that_does_not_match_is_never_confirmed_nor_resent`, `InventoryTransferFlowTest::test_a_rejected_transfer_releases_the_pallet_and_can_be_retried`, `InventoryTransferFlowTest::test_when_sap_cannot_be_asked_nothing_changes` | Pasa (simulador) |

## 5. Escenarios mínimos de la sección 15

### Producción

| Escenario | Prueba |
|---|---|
| Primer escaneo crea una estiba | `ProcessScanEventTest::test_the_first_scan_creates_a_pallet_with_the_standard_frozen` |
| Cada escaneo válido suma una caja | `ProcessScanEventTest::test_each_valid_scan_adds_exactly_one_box` |
| Alcanzar el estándar finaliza una vez | `ProcessScanEventTest::test_reaching_the_standard_closes_the_pallet_once_and_the_next_scan_opens_another` |
| El escaneo siguiente crea otra | ídem |
| Cierre manual conserva la cantidad | `CloseManuallyTest::test_it_closes_with_the_real_count_and_records_who_and_why` |
| No se cierra una estiba vacía | `CloseManuallyTest::test_an_empty_pallet_cannot_be_closed` |
| No se supera el estándar | `PalletInvariantsTest::test_boxes_never_exceed_the_frozen_standard_nor_go_negative` |
| Duplicados no suman | `ProcessScanEventTest::test_a_repeated_event_is_not_counted_again` |
| Concurrencia sin duplicados ni sobreconteo | `ScanConcurrencyTest::test_parallel_scans_of_one_combination_fill_pallets_exactly` |
| No se mezclan órdenes, productos ni lotes | `ProcessScanEventTest::test_orders_items_and_lots_never_mix` |
| Estándar ausente bloquea | `ProcessScanEventTest::test_a_missing_standard_rejects_the_event_and_keeps_it_with_the_reason` |
| Cambio de estándar no altera estibas | `ProcessScanEventTest::test_a_new_standard_applies_to_new_pallets_only` |

### Paletizado y etiquetado

| Escenario | Prueba |
|---|---|
| No se paletiza en proceso | `PalletizeAndLabelTest::test_a_pallet_in_process_cannot_be_palletized_nor_palletized_twice` |
| No se etiqueta sin paletizar | `PalletizeAndLabelTest::test_a_label_needs_a_palletized_pallet` |
| Reimpresión conserva identidad y cantidad | `PalletizeAndLabelTest::test_the_first_print_is_original_and_a_reprint_needs_a_reason_and_keeps_identity_and_quantity` |
| El QR no evade validaciones | `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it` |
| Confirmaciones físicas auditadas | `PalletizeAndLabelTest::test_a_finished_pallet_is_palletized_with_who_and_when` |

### Recibos SAP

| Escenario | Prueba |
|---|---|
| Tres estibas agrupadas | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references` |
| Tres estibas separadas | `ProductionReceiptFlowTest::test_three_pallets_in_separate_receipts` |
| No se reutilizan estibas recibidas | `ProductionReceiptFlowTest::test_a_received_or_held_pallet_cannot_be_requested_again` |
| Factor de conversión correcto | `ProductionReceiptFlowTest::test_boxes_convert_with_the_current_factor_and_the_line_keeps_which_one` |
| DocEntry y DocNum tras verificar | `ProductionReceiptFlowTest::test_three_pallets_in_one_receipt_group_lines_by_lot_and_confirm_with_sap_references` |
| Timeout sin segunda creación ciega | `ProductionReceiptFlowTest::test_a_lost_answer_is_reconciled_to_the_existing_document_without_a_second_create` |
| Conciliación encuentra documentos | ídem |
| Orden parcialmente recibida sujeta a SAP | `ProductionReceiptFlowTest::test_successive_partial_receipts_of_one_order`. **Parcial:** se revisa la orden contra la copia local; el estado real en SAP depende de DP-06 y DP-09 |

### Auditoría logística

| Escenario | Prueba |
|---|---|
| No se audita sin recibo confirmado | `BlindCountTest::test_only_received_and_not_transferred_pallets_are_counted` |
| No se audita una estiba trasladada | ídem |
| El operario no recibe la cantidad | `BlindCountSecrecyTest::test_the_page_state_and_responses_never_carry_the_expected_quantity` |
| Conteo diferente genera discrepancia | `BlindCountTest::test_a_different_count_opens_a_case_blocks_the_transfer_and_notifies_the_admins_not_the_counter` |
| La discrepancia bloquea el traslado | ídem, más `InventoryTransferFlowTest::test_a_count_that_does_not_match_moves_nothing` |
| Conteo correcto transfiere a 05 | `BlindCountTest::test_a_matching_count_approves_the_audit_and_transfers_the_pallet_to_05` |
| Permisos bloquean la resolución | `BlindCountTest::test_people_without_the_permissions_are_refused_on_the_server` |

### Traslados SAP

| Escenario | Prueba |
|---|---|
| Transferencia 04 → 05 al aprobar el conteo | `InventoryTransferFlowTest::test_an_approved_count_transfers_the_pallet_from_04_to_05_with_sap_references` |
| No se trasladan no auditadas | `InventoryTransferFlowTest::test_a_count_that_does_not_match_moves_nothing` |
| No se duplican traslados | `InventoryTransferFlowTest::test_a_pallet_with_an_active_transfer_cannot_be_requested_again`, `InventoryTransferFlowTest::test_running_the_send_twice_sends_once` |
| Cada estiba conserva su relación estiba-documento-línea | `InventoryTransferFlowTest::test_the_full_chain_is_traceable_from_the_pallet` (el agrupado ya no existe) |
| Errores y timeouts mantienen el estado real | `InventoryTransferFlowTest::test_a_rejected_transfer_releases_the_pallet_and_can_be_retried`, `InventoryTransferFlowTest::test_when_sap_cannot_be_asked_nothing_changes` |
| La conciliación evita duplicados | `InventoryTransferFlowTest::test_a_lost_answer_is_reconciled_without_a_second_create` |
| Solo la confirmación verificada marca trasladada | `InventoryTransferFlowTest::test_a_document_that_does_not_match_is_never_confirmed_nor_resent` |

### Seguridad

| Escenario | Prueba |
|---|---|
| Acceso no autorizado a acciones críticas | `CloseManuallyTest::test_people_without_the_permission_are_refused_on_the_server`, `PalletizeAndLabelTest::test_operators_without_permission_are_refused_on_the_server`, `InventoryTransferFlowTest::test_only_people_with_the_permission_retry_a_transfer`, `ProductionReceiptFlowTest::test_only_people_with_the_permission_request_receipts` |
| Manipulación de propiedades Livewire | `ProductionStationTest::test_the_pallet_being_closed_cannot_be_swapped_from_the_browser`, `BlindCountSecrecyTest::test_the_count_in_progress_cannot_be_swapped_from_the_browser` |
| Acceso cruzado a estibas de otros procesos o usuarios | `BlindCountTest::test_a_count_belongs_to_whoever_started_it`, `PalletDetailAndLabelTest::test_a_print_that_does_not_exist_or_belongs_to_another_pallet_is_404_and_access_needs_permission`, `NotificationBellTest::test_people_only_open_their_own_notifications` |
| Consultar cantidades durante el conteo ciego | `BlindCountSecrecyTest::test_no_other_screen_or_endpoint_shows_quantities_to_the_operator` |
| Repetición de solicitudes HTTP | `ScanEventApiTest::test_a_resend_answers_200_and_changes_nothing`, `InventoryTransferFlowTest::test_a_pallet_with_an_active_transfer_cannot_be_requested_again`, `ProductionReceiptFlowTest::test_running_the_send_twice_sends_once` |
| Manipulación de QR | `PalletizeAndLabelTest::test_with_scan_mode_only_this_pallets_qr_confirms_it` |
| Credenciales en logs | `TraceEventsTest::test_context_and_log_lines_never_carry_secrets`, `HardeningTest::test_log_lines_are_json_with_the_correlation_id_and_no_secrets` |
| Recibir o trasladar la misma estiba a la vez | `ReceiptConcurrencyTest::test_parallel_receipt_requests_for_the_same_pallets_hold_each_pallet_once`, `TransferConcurrencyTest::test_parallel_retries_for_the_same_pallets_hold_each_pallet_once` |

### Etiquetas de caja (requisito agregado después de la Fase 7)

| Escenario | Prueba |
|---|---|
| Etiquetas de caja busca la OF en la base de la aplicación (actualizada por el Tablero de OF), sin llamar a SAP | `BoxLabelsTest::test_a_released_order_is_found_in_the_application_without_asking_sap` |
| OF que no está en la aplicación, planificada, cerrada o cancelada se rechaza con el motivo | `BoxLabelsTest::test_orders_not_in_the_application_or_not_released_are_refused` |
| Lote = OF + día juliano | `BoxLabelsTest::test_the_lot_is_the_order_number_and_the_julian_day` |
| El QR se lee como las líneas de la etiqueta (OF, producto, descripción, lote, vence, contenido); el EAN-14 va aparte como ITF-14 | `BoxLabelsTest::test_the_qr_reads_as_the_lines_of_the_label_and_the_ean14_goes_apart`, `BoxLabelsTest::test_the_ean14_check_digit_and_itf14_pattern_follow_gs1` |
| ZPL de 10 × 8 cm con la cantidad pedida | `BoxLabelsTest::test_the_zpl_is_a_10_by_8_cm_label_printed_as_many_times_as_asked` |
| Texto de SAP no inyecta comandos ZPL | `BoxLabelsTest::test_text_from_sap_can_never_inject_zpl_commands` |
| Sin límite de etiquetas por OF: pasar la cantidad sugerida queda registrado; en paralelo los códigos de caja no se repiten ni dejan huecos | `BoxLabelsTest::test_an_order_can_get_more_labels_than_its_planned_quantity_suggests`, `BoxLabelConcurrencyTest::test_parallel_batches_continue_the_lot_without_gaps_or_repeats` |
| Solo el supervisor cambia el lote, mientras el lote por defecto no tenga impresiones en la Zebra | `BoxLabelLotTest::test_only_a_supervisor_changes_the_lot_and_only_to_a_valid_one`, `BoxLabelLotTest::test_once_the_default_lot_is_printed_on_the_zebra_it_can_no_longer_be_changed` |
| Lo impreso queda como evidencia inmutable | `BoxLabelsTest::test_a_generated_batch_is_evidence_and_never_changes` |
| Permisos en el servidor | `BoxLabelsTest::test_only_people_with_box_labels_print_consult_and_generate`, `BoxLabelsTest::test_the_page_and_the_download_need_the_permission` |
| Un lote tiene un solo vencimiento entre tandas | `BoxLabelsTest::test_a_lot_keeps_one_expiration_date_across_batches` |

### Lotes en el recibo de producción (requisito agregado después de la Fase 7)

| Escenario | Prueba |
|---|---|
| Cada lote va a SAP con el vencimiento de sus etiquetas de caja y fabricación = admisión | `ProductionReceiptFlowTest::test_each_batch_goes_with_the_expiration_of_its_box_labels_and_the_admission_date` |
| Lote sin etiquetas de caja: no hay recibo y no se inventa la fecha | `ProductionReceiptFlowTest::test_a_lot_without_box_labels_is_refused_and_nothing_is_created` |
| Un reenvío después de medianoche conserva la fecha de admisión | `ProductionReceiptFlowTest::test_a_resend_after_midnight_keeps_the_admission_date` |
| Un lote con otras fechas en SAP nunca se confirma | `ProductionReceiptFlowTest::test_a_batch_with_other_dates_in_sap_is_never_confirmed` |
| La etiqueta de la estiba muestra el vencimiento del lote | `PalletDetailAndLabelTest::test_the_label_shows_the_expiration_of_its_lot_from_the_box_labels` |

### Tablero de OF (requisito agregado después de la Fase 7)

| Escenario | Prueba |
|---|---|
| Se traen todas las OF liberadas en SAP y ninguna otra | `OrderBoardTest::test_the_sync_brings_every_order_released_in_sap_and_nothing_else` |
| Una OF cerrada en SAP sale del tablero; SAP sin respuesta no cambia nada; una OF que SAP ya no tiene se reporta | `OrderBoardTest::test_an_order_closed_in_sap_leaves_the_board_on_the_next_sync`, `OrderBoardTest::test_when_sap_does_not_answer_nothing_changes`, `OrderBoardTest::test_an_order_released_here_that_sap_no_longer_has_is_kept_and_reported` |
| Una OF que llegó a "Lista para cerrar" y se cerró en SAP queda como "Cerrada en SAP", con la fecha del cierre, y se consulta en el tablero | `OrderBoardTest::test_an_order_that_reached_ready_to_close_and_was_closed_in_sap_shows_as_closed_in_sap` |
| Calidad retiene estibas recibidas antes del conteo: retener las traslada de 04 a 11C con la cantidad recibida y no se cuentan | `QualityTest::test_holding_moves_the_pallet_from_04_to_11c_with_what_sap_received`, `QualityTest::test_quality_acts_on_received_pallets_before_the_count`, `QualityTest::test_when_sap_refuses_the_transfer_nothing_moved_and_it_can_be_held_again` |
| Calidad libera las conformes: siguen al conteo y van de 11C a 05; varias a la vez, cada una con su traslado | `QualityTest::test_a_released_pallet_is_counted_and_goes_from_11c_to_05`, `QualityTest::test_holding_a_released_pallet_again_moves_nothing`, `QualityTest::test_the_page_reads_several_pallets_and_holds_and_releases_them_together` |
| Avance por recibos confirmados y transferencia a CEDI | `OrderBoardTest::test_progress_follows_confirmed_receipts_and_the_transfer_to_the_cedi`, `OrderBoardTest::test_a_receipt_not_confirmed_yet_does_not_count`, `OrderBoardTest::test_the_last_partial_box_still_counts_as_the_planned_quantity` |
| Aviso "Lista para cerrar" una sola vez, solo a quien sigue el tablero y solo con la OF liberada | `OrderBoardTest::test_the_ready_to_close_alert_goes_once_to_the_people_who_follow_the_board`, `OrderBoardTest::test_an_order_not_released_in_sap_never_raises_the_alert` |
| KPIs y pantalla | `OrderBoardTest::test_the_kpis_add_up_the_released_orders`, `OrderBoardTest::test_the_board_shows_the_kpis_and_the_released_orders_most_urgent_first` |
| Permisos en el servidor | `OrderBoardTest::test_only_people_with_orders_view_sync_or_open_the_board` |

### Conector SAP Service Layer (recibos y OF)

| Escenario | Prueba |
|---|---|
| El recibo va a InventoryGenEntries con una línea por lote, normas de reparto y correlación | `ServiceLayerGatewayTest::test_the_receipt_goes_as_inventory_gen_entries_with_one_line_per_lot`, `ServiceLayerGatewayTest::test_the_costing_codes_and_the_correlation_field_come_from_the_configuration` |
| Error claro de SAP = rechazado; lo que pudo haber llegado = incierto | `ServiceLayerGatewayTest::test_a_clear_sap_error_is_rejected_and_anything_that_may_have_landed_is_uncertain`, `ServiceLayerGatewayTest::test_a_timeout_is_uncertain_never_rejected`, `ServiceLayerGatewayTest::test_a_failed_login_sends_nothing_and_is_uncertain` |
| Sesión vencida: un solo reintento de login | `ServiceLayerGatewayTest::test_an_expired_session_logs_in_again_once` |
| Recibo encontrado por correlación y leído lote por lote; "no existe" nunca se confunde con "no se pudo preguntar" | `ServiceLayerGatewayTest::test_a_receipt_is_found_by_its_correlation_and_read_lot_by_lot`, `ServiceLayerGatewayTest::test_not_found_is_null_and_not_knowing_is_never_null` |
| OF desde ProductionOrders, una y todas las liberadas con paginación | `ServiceLayerGatewayTest::test_a_production_order_is_read_from_production_orders_with_its_product`, `ServiceLayerGatewayTest::test_the_released_orders_follow_every_page_in_big_pages_and_read_products_in_groups`, `ServiceLayerGatewayTest::test_an_unknown_order_status_or_an_unreachable_sap_is_never_taken_as_an_answer` |
| Flujo completo: crear, comparar lo que SAP respondió y confirmar con su DocEntry y DocNum sin campo de correlación; respuesta sin líneas leída por DocEntry; conciliar por el DocEntry que SAP dio; fechas distintas no se confirman; rechazo libera la estiba | `ServiceLayerReceiptFlowTest::test_a_receipt_is_confirmed_with_the_document_sap_answers`, `ServiceLayerReceiptFlowTest::test_an_answer_without_lines_is_read_back_by_its_doc_entry`, `ServiceLayerReceiptFlowTest::test_reconciling_a_created_receipt_reads_it_by_the_doc_entry_sap_gave`, `ServiceLayerReceiptFlowTest::test_a_receipt_with_other_batch_dates_in_sap_is_never_confirmed`, `ServiceLayerReceiptFlowTest::test_sap_rejecting_the_receipt_fails_it_and_frees_the_pallet` |
| El traslado 04 → 05 va a StockTransfers con el socio y las normas de reparto fijos de la configuración; cada respuesta se clasifica como en el recibo; se lee por DocEntry y nunca por una correlación que no existe | `ServiceLayerGatewayTest::test_the_transfer_goes_as_stock_transfers_with_the_fixed_partner_and_distribution_rules`, `ServiceLayerGatewayTest::test_the_transfer_partner_and_distribution_rules_come_from_the_configuration`, `ServiceLayerGatewayTest::test_each_transfer_answer_is_classified_like_the_receipt`, `ServiceLayerGatewayTest::test_a_transfer_is_read_by_its_doc_entry_and_never_by_a_correlation` |
| Un traslado fallido se reintenta y queda confirmado con el documento de SAP; otra cantidad en SAP no se confirma; sin respuesta queda pendiente y no se reenvía | `ServiceLayerTransferFlowTest::test_a_failed_transfer_retried_goes_to_stock_transfers_and_is_confirmed`, `ServiceLayerTransferFlowTest::test_a_transfer_that_sap_moved_with_another_quantity_is_never_confirmed`, `ServiceLayerTransferFlowTest::test_a_transfer_without_an_answer_stays_pending_and_is_never_resent` |
| Credenciales obligatorias y nunca en logs ni salidas | `ServiceLayerGatewayTest::test_credentials_are_required_and_never_logged`, `ServiceLayerGatewayTest::test_the_check_command_reads_the_released_orders` |

### Lectura manual de cajas y escáner

| Escenario | Prueba |
|---|---|
| Cada caja leída llena la estiba de su OF y lote y la cierra en el estándar (de prueba si no hay real) | `ManualBoxScanTest::test_each_box_read_fills_the_pallet_of_its_order_and_lot_with_the_test_standard`, `ManualBoxScanTest::test_a_real_pallet_standard_is_used_when_there_is_one` |
| El QR de la caja lleva solo su código opaco; la caja se busca por él y su OF debe estar liberada | `ManualBoxScanTest::test_the_box_qr_carries_only_the_token`, `ManualBoxScanTest::test_only_a_box_qr_printed_here_for_a_released_order_is_counted` |
| Cada caja se cuenta una sola vez, también en paralelo | `ManualBoxScanTest::test_a_box_is_counted_only_once_by_its_code`, `ManualBoxScanConcurrencyTest::test_parallel_reads_of_the_same_box_count_it_once`, `BoxLabelsTest::test_every_box_label_has_its_own_code_continuing_the_lot` |
| Lectores con otra distribución de teclado | `ManualBoxScanTest::test_what_a_hand_reader_types_is_read_even_with_another_keyboard_layout` |
| Etiqueta confirmada leyendo el QR de la estiba | `ManualBoxScanTest::test_the_label_stuck_on_a_pallet_is_confirmed_by_reading_it` |
| Pantallas de la estación y del conteo ciego con escáner; permisos | `ManualBoxScanTest::test_the_station_shows_each_read_and_why_a_box_was_not_counted`, `ManualBoxScanTest::test_the_blind_count_finds_the_pallet_with_the_scanner`, `ManualBoxScanTest::test_only_people_who_operate_the_station_read_boxes` |

## 6. Seguridad (sección 12)

| Control | Cómo | Evidencia |
|---|---|---|
| Autorización en el servidor | Permisos granulares por caso de uso (`Gate::authorize`) y por página (`boot()`) | Pruebas de la sección 5, Seguridad |
| CSRF | Middleware de Laravel en todas las rutas web; Livewire lo incluye en cada petición | Configuración del framework. Las pruebas lo omiten por diseño de Laravel; no tiene prueba propia |
| Validación estricta | Form Requests en la API, `validate()` en Livewire, CHECK en la base | `ScanEventApiTest::test_malformed_requests_are_refused_without_storing_anything` |
| Asignación masiva | Modelos de dominio sin `fillable`; los casos de uso usan `forceFill` con campos explícitos | `HardeningTest::test_domain_models_ignore_mass_assignment` |
| Acceso por objeto | Conteo de quien lo empezó, impresiones de su estiba, notificaciones propias | Sección 5, acceso cruzado |
| Rate limiting de sensores | `throttle:scan-events` por dispositivo | `ScanEventApiTest::test_each_device_is_rate_limited` |
| Autenticación de dispositivos | Token por dispositivo, guardado como hash, rotación | `DeviceCommandsTest::test_a_registered_device_gets_a_token_once_and_only_its_hash_is_stored`, `DeviceCommandsTest::test_rotating_the_token_invalidates_the_old_one` |
| TLS | Nginx solo TLS 1.2/1.3, redirección, HSTS | `deploy/nginx/trazabilidad.conf`. Sin prueba automática |
| Secretos | `.env` fuera del repo y de `public/`, enmascarado en logs | `HardeningTest::test_nothing_private_lives_under_the_web_root`, `RedactorTest::test_secret_keys_are_masked_at_any_depth_and_the_rest_is_kept` |
| Cookies y sesión | `SESSION_SECURE_COOKIE`, `http_only`, `same_site`, verificados al desplegar | `HealthAndPreflightTest::test_preflight_fails_on_an_unsafe_configuration` |
| Errores internos ocultos | `APP_DEBUG=false` exigido, `display_errors` bloqueado en el pool | `HardeningTest::test_internal_errors_are_not_exposed_in_html_or_json` |
| Bitácora inmutable | Triggers contra UPDATE, DELETE y TRUNCATE | `TraceEventsTest::test_traces_cannot_be_updated_deleted_or_truncated` |
| Respaldo y recuperación | `deploy/scripts/backup.sh`, procedimiento en `docs/despliegue.md` y `docs/operacion.md` | Script verificado con `bash -n`; no ejecutado contra un servidor |
| Dependencias | `composer audit`: sin avisos. `npm audit`: se corrigieron `shell-quote` y `source-map-js`; queda `tinypool` dentro de `vite-plus` 0.3.0 | Solo se usa al compilar assets; no llega al servidor ni al navegador |

## 7. Auditoría y observabilidad (sección 13)

| Requisito | Cómo |
|---|---|
| Usuario o dispositivo, fecha, evento, entidad, estados, motivo, origen, correlación, resultado, referencias SAP | Columnas de `trace_events`, `TraceEventsTest::test_the_logger_records_actor_subject_states_and_correlation` |
| Intentos y errores normalizados, latencias | `integration_attempts`, `ProductionReceiptFlowTest::test_every_call_to_sap_is_recorded` |
| Logs estructurados con correlación | JSON por línea con `correlation_id`, `HardeningTest::test_log_lines_are_json_with_the_correlation_id_and_no_secrets`, `CorrelationIdTest::test_every_response_carries_a_correlation_id` |
| Alerta: eventos rechazados | `OperationalChecksTest::test_rejected_scans_above_the_threshold_are_reported` |
| Alerta: duplicados anormales | `OperationalChecksTest::test_abnormal_duplicates_are_reported` |
| Alerta: estándares ausentes | `OperationalChecksTest::test_missing_standards_are_critical_and_open_orders_without_one_are_flagged` |
| Alerta: errores SAP, pendientes de conciliación | `OperationalChecksTest::test_sap_errors_pending_reconciliations_and_mismatches_are_reported` |
| Alerta: discrepancias abiertas | `OperationalChecksTest::test_open_discrepancies_are_reported_without_quantities` |
| Alerta: cola acumulada | `OperationalChecksTest::test_queue_backlog_and_failed_jobs_are_reported` |
| Alerta: fallos de workers | `OperationalChecksTest::test_a_dead_scheduler_or_worker_is_critical`, `HealthAndPreflightTest::test_health_is_ok_only_when_database_scheduler_and_workers_are_alive` |
| Aviso a responsables sin repetir | `OperationalChecksTest::test_the_check_notifies_ops_people_once_per_cooldown_and_never_the_operators` |

## 8. Despliegue (sección 16)

Todo en `docs/despliegue.md` y `deploy/`:

| Tema | Dónde |
|---|---|
| Requisitos, dependencias, extensiones PHP | Secciones 1 y 5 |
| Variables de entorno | Sección 4 |
| Migraciones | Secciones 7 y 10 (regla de compatibilidad hacia atrás) |
| Usuario de sistema y permisos mínimos | Sección 2 |
| Nginx y TLS | Sección 6, `deploy/nginx/trazabilidad.conf` |
| PHP-FPM | Sección 5, `deploy/php/` |
| Colas y supervisión de workers | Sección 8, `deploy/supervisor/trazabilidad.conf` |
| Scheduler | Sección 9, `deploy/cron/trazabilidad` |
| Reinicio seguro | Sección 15 |
| Logs | Sección 12 |
| Respaldos y restauración | Sección 13, `deploy/scripts/backup.sh` |
| Actualizaciones y rollback sin perder trabajos | Secciones 10 y 11, `deploy/scripts/deploy.sh`, `deploy/scripts/rollback.sh` |
| Health checks | Sección 14, `/up` y `/api/health` |
| Conciliación operativa | `docs/operacion.md` |

**No probado en un servidor.** Las configuraciones y scripts se revisaron a mano y con `bash -n`, pero esta sesión no tiene un servidor Linux con Nginx y Supervisor. Ninguna prueba automática los ejecuta.

## 9. Decisiones pendientes

| DP | Tema | Estado en el código | Bloquea producción |
|---|---|---|---|
| DP-01 | Identificador del sensor y eventos tardíos | Motor completo, `TRACE_SCAN_INGESTION_ENABLED=false` | Sí: sin esto no entra producción |
| DP-02 | Fuente de órdenes, lotes y factores | Tablas y validación; sin sincronización con SAP | Sí |
| DP-03 | Confirmación de etiqueta aplicada | Tres modos implementados, ninguno activo | Sí: sin esto no hay recibos |
| DP-04 | Resolución de discrepancias | Reauditoría aprobada y encendida: el supervisor de producción habilita el reconteo, hasta 3 oportunidades por conteo. Cierre con acción implementado y apagado | No |
| DP-05 | Traslado agrupado | **Cerrada.** El negocio eliminó el paso de traslado: el conteo aprobado transfiere cada estiba por separado | No |
| DP-06 | Cierre de órdenes en SAP con recibos parciales | Se revisa la copia local | Depende de cómo opere SAP |
| DP-07 | Etiqueta física | Etiqueta HTML 100 × 150 mm | Depende de la impresora |
| DP-08 | Reversión de documentos y estibas devueltas | No implementado | Sí, para operar devoluciones |
| DP-09 | Acceso a SAP, correlación, almacenes | Recibos y OF probados contra SAP DEV; traslado 04 → 05 conectado (StockTransfers), falta probarlo en DEV; falta crear los campos de correlación | Sí |

## 10. Definición de terminado

| Punto | Estado |
|---|---|
| Requisitos aprobados implementados | Sí, salvo DP-08 y lo que espera DP-01 a DP-04 (implementado y apagado) |
| CA-01 a CA-18 con pruebas satisfactorias | Sí, en simulador. CA-11 a CA-13, CA-17 y CA-18 falta repetirlos contra SAP |
| Reglas transversales con pruebas | Sí (sección 3) |
| Operaciones concurrentes seguras | Sí, con procesos paralelos reales contra PostgreSQL; además se comprobó que las pruebas fallan al quitar los bloqueos |
| SAP con idempotencia y conciliación | Sí, en simulador |
| Estados locales con evidencia de SAP | Sí: confirmación solo después de leer el documento; restricción en la base |
| Conteo ciego protegido en todas las interfaces | Sí (CA-15) |
| Procedimiento autorizado de discrepancias | Reconteo aprobado y encendido; cierre con acción pendiente (DP-04) |
| Trazabilidad reconstruible | Sí (RF-21) |
| Permisos y auditoría | Sí |
| Despliegue reproducible | Documentado y con scripts; sin ejecutar en un servidor |
| Documentación técnica y operativa | Sí: `docs/fase-0` a `fase-7`, `despliegue.md`, `operacion.md`, este informe |
| Sin decisiones críticas abiertas | **No.** Ver sección 9 |

## 11. Resultados de esta verificación

PHP 8.3.6 y PostgreSQL 16.15, en la sesión de la Fase 7:

```
php artisan test             → 362 pruebas, 362 pasan (QR de caja con código opaco)
phpstan analyse (nivel 7)    → 0 errores
pint --test                  → pasa
composer audit               → sin avisos
npm audit --omit=dev         → 1 aviso restante (tinypool, herramienta de compilación)
bash -n deploy/scripts/*.sh  → sin errores
```

No ejecutado: compilación de assets (fonts.bunny.net está bloqueado en esta sesión; el CI de GitHub sí compila), pruebas en navegador, despliegue en un servidor, SAP real.
