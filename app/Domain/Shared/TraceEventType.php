<?php

namespace App\Domain\Shared;

/**
 * Catalog of traceability events. Values are stored in trace_events.event.
 */
enum TraceEventType: string
{
    case DeviceRegistered = 'device.registered';
    case DeviceTokenRotated = 'device.token_rotated';
    case DeviceDeactivated = 'device.deactivated';

    case ScanRejected = 'scan.rejected';
    case ScanDuplicateMismatch = 'scan.duplicate_mismatch';
    case PalletCreated = 'pallet.created';
    case PalletClosedAutomatically = 'pallet.closed_automatic';
    case PalletClosedManually = 'pallet.closed_manual';
    case PalletPalletized = 'pallet.palletized';
    case LabelPrinted = 'label.printed';
    case LabelReprinted = 'label.reprinted';
    case PalletLabeled = 'pallet.labeled';

    case ReceiptRequested = 'receipt.requested';
    case ReceiptConfirmed = 'receipt.confirmed';
    case ReceiptFailed = 'receipt.failed';
    case ReceiptPendingReconciliation = 'receipt.pending_reconciliation';

    case AuditStarted = 'audit.started';
    case AuditApproved = 'audit.approved';
    case AuditDiscrepancy = 'audit.discrepancy';
    case AuditRetry = 'audit.retry';
    case AuditCancelled = 'audit.cancelled';
    case DiscrepancyResolved = 'discrepancy.resolved';

    case TransferRequested = 'transfer.requested';
    case TransferConfirmed = 'transfer.confirmed';
    case TransferFailed = 'transfer.failed';
    case TransferPendingReconciliation = 'transfer.pending_reconciliation';

    case MastersSynced = 'masters.synced';

    case ProductionOrderConsulted = 'production_order.consulted';
    case BoxLabelsGenerated = 'box_labels.generated';
    case BoxLabelsDownloaded = 'box_labels.downloaded';
    case BoxLabelsPrinted = 'box_labels.printed';
    case ProductionOrderRefreshed = 'production_order.refreshed';
    case ProductionOrderReadyToClose = 'production_order.ready_to_close';
    case ProductionOrderClosedInSap = 'production_order.closed_in_sap';
    case QualityHeld = 'quality.held';
    case QualityReleased = 'quality.released';
    case NonConformingRequested = 'quality.non_conforming_requested';
    case NonConformingPending = 'quality.non_conforming_pending';
    case NonConformingConfirmed = 'quality.non_conforming_confirmed';
    case NonConformingFailed = 'quality.non_conforming_failed';

    public function label(): string
    {
        return match ($this) {
            self::DeviceRegistered => 'Dispositivo registrado',
            self::DeviceTokenRotated => 'Token de dispositivo renovado',
            self::DeviceDeactivated => 'Dispositivo desactivado',
            self::ScanRejected => 'Lectura rechazada',
            self::ScanDuplicateMismatch => 'Lectura repetida con datos distintos',
            self::PalletCreated => 'Estiba creada',
            self::PalletClosedAutomatically => 'Estiba cerrada automáticamente',
            self::PalletClosedManually => 'Estiba cerrada manualmente',
            self::PalletPalletized => 'Paletizado confirmado',
            self::LabelPrinted => 'Etiqueta impresa',
            self::LabelReprinted => 'Etiqueta reimpresa',
            self::PalletLabeled => 'Etiqueta aplicada',
            self::ReceiptRequested => 'Recibo de producción solicitado',
            self::ReceiptConfirmed => 'Recibo de producción confirmado en SAP',
            self::ReceiptFailed => 'Recibo de producción fallido',
            self::ReceiptPendingReconciliation => 'Recibo pendiente de conciliación',
            self::AuditStarted => 'Conteo iniciado',
            self::AuditApproved => 'Auditoría aprobada',
            self::AuditDiscrepancy => 'Discrepancia en conteo',
            self::AuditRetry => 'Conteo no coincidió, otra oportunidad',
            self::AuditCancelled => 'Conteo cancelado',
            self::DiscrepancyResolved => 'Discrepancia resuelta',
            self::TransferRequested => 'Traslado solicitado',
            self::TransferConfirmed => 'Traslado confirmado en SAP',
            self::TransferFailed => 'Traslado fallido',
            self::TransferPendingReconciliation => 'Traslado pendiente de conciliación',
            self::MastersSynced => 'Maestros sincronizados',
            self::ProductionOrderConsulted => 'Orden de fabricación consultada en SAP',
            self::BoxLabelsGenerated => 'Etiquetas de caja generadas',
            self::BoxLabelsDownloaded => 'Etiquetas de caja descargadas',
            self::BoxLabelsPrinted => 'Etiquetas de caja impresas en la Zebra',
            self::ProductionOrderRefreshed => 'Orden de fabricación actualizada desde SAP',
            self::ProductionOrderReadyToClose => 'Orden de fabricación lista para cerrar en SAP',
            self::ProductionOrderClosedInSap => 'Orden de fabricación cerrada en SAP',
            self::QualityHeld => 'Estiba retenida por calidad',
            self::QualityReleased => 'Estiba liberada por calidad',
            self::NonConformingRequested => 'Traslado a no conforme solicitado',
            self::NonConformingPending => 'Traslado a no conforme pendiente de conciliación',
            self::NonConformingConfirmed => 'Traslado a no conforme confirmado en SAP',
            self::NonConformingFailed => 'Traslado a no conforme fallido',
        };
    }
}
