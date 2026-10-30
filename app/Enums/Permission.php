<?php

namespace App\Enums;

/**
 * Global permissions. Every use case checks its permission on the server;
 * hiding a button is never the protection.
 */
enum Permission: string
{
    /** Pallet board, pallet detail and indicators. */
    case PalletsView = 'pallets.view';
    /** Production station: watch the active pallet and close it manually. */
    case PalletsOperate = 'pallets.operate';
    case PalletsPalletize = 'pallets.palletize';
    /** Print the label and confirm it was applied. */
    case PalletsLabel = 'pallets.label';
    case PalletsReprint = 'pallets.reprint';
    /** Look up a production order in SAP and generate the QR labels for its boxes. */
    case BoxLabelsPrint = 'box_labels.print';
    /** Use another lot than OF + Julian day when generating box labels, while that lot has no Zebra prints. */
    case BoxLabelsChangeLot = 'box_labels.change_lot';
    /** Production order board: progress against SAP, refresh from SAP, "ready to close" alert. */
    case OrdersView = 'orders.view';
    /** Evaluate a rejected scan event again after fixing a master. */
    case ScansReprocess = 'scans.reprocess';
    case ReceiptsRequest = 'receipts.request';
    case ReceiptsReconcile = 'receipts.reconcile';
    /** Blind count at the warehouse. Never grants seeing expected quantities. */
    case AuditsCount = 'audits.count';
    case DiscrepanciesResolve = 'discrepancies.resolve';
    /** Quality: hold labeled pallets before the count, release them or send them to the non-conforming warehouse. */
    case QualityManage = 'quality.manage';
    /** Stock transfers 04 → 05 are requested by the approved blind count; this retries, reconciles or closes them. */
    case TransfersReconcile = 'transfers.reconcile';
    /** Register sensors and readers and rotate their tokens. */
    case DevicesManage = 'devices.manage';
    /** Operational alerts: queues, workers, SAP errors, pending reconciliations. */
    case OpsAlerts = 'ops.alerts';
    /** Traceability log (trace_events). */
    case TraceView = 'trace.view';
    /** Administration audit (users and roles). */
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
}
