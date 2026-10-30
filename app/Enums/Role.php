<?php

namespace App\Enums;

/**
 * Application roles (spatie/laravel-permission). Rules that depend on the
 * record, such as "the person who counted cannot resolve that discrepancy",
 * live in the use cases.
 */
enum Role: string
{
    case Admin = 'admin';
    case TraceabilityAdmin = 'traceability_admin';
    case ProductionSupervisor = 'production_supervisor';
    case Quality = 'quality';
    case ProductionOperator = 'production_operator';
    case LogisticsOperator = 'logistics_operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador del sistema',
            self::TraceabilityAdmin => 'Administrador de trazabilidad',
            self::ProductionSupervisor => 'Supervisor de producción',
            self::Quality => 'Calidad',
            self::ProductionOperator => 'Operario de producción',
            self::LogisticsOperator => 'Operario logístico',
            self::Viewer => 'Consulta',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::TraceabilityAdmin => [
                Permission::PalletsView,
                Permission::PalletsOperate,
                Permission::PalletsPalletize,
                Permission::PalletsLabel,
                Permission::PalletsReprint,
                Permission::BoxLabelsPrint,
                Permission::OrdersView,
                Permission::ScansReprocess,
                Permission::ReceiptsRequest,
                Permission::ReceiptsReconcile,
                Permission::DiscrepanciesResolve,
                Permission::TransfersReconcile,
                Permission::OpsAlerts,
                Permission::TraceView,
            ],
            // Sees the discrepancies and enables a new blind count; follows the orders and pallets;
            // generates box labels and may change their lot.
            self::ProductionSupervisor => [
                Permission::BoxLabelsPrint,
                Permission::BoxLabelsChangeLot,
                Permission::DiscrepanciesResolve,
                Permission::OrdersView,
                Permission::PalletsView,
                Permission::TraceView,
            ],
            // Holds pallets before the count and sees their quantities to decide.
            self::Quality => [
                Permission::QualityManage,
                Permission::PalletsView,
                Permission::TraceView,
            ],
            self::ProductionOperator => [
                Permission::PalletsOperate,
                Permission::PalletsPalletize,
                Permission::PalletsLabel,
                Permission::PalletsReprint,
                Permission::BoxLabelsPrint,
            ],
            // No pallets.view: the board shows box counts, which would break the blind count.
            self::LogisticsOperator => [
                Permission::AuditsCount,
            ],
            self::Viewer => [
                Permission::PalletsView,
                Permission::TraceView,
            ],
        };
    }
}
