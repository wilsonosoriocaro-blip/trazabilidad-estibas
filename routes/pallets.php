<?php

use App\Http\Controllers\BoxLabelController;
use App\Http\Controllers\PalletLabelController;
use App\Http\Controllers\ZebraPreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('production', 'pages::production.station')->middleware('can:pallets.operate')->name('production.station');

    Route::livewire('orders', 'pages::orders.index')->middleware('can:orders.view')->name('orders.index');

    Route::livewire('box-labels', 'pages::box-labels.index')->middleware('can:box_labels.print')->name('box-labels.index');
    Route::post('box-labels/preview', [ZebraPreviewController::class, 'preview'])
        ->middleware('can:box_labels.print')
        ->name('box-labels.preview');
    Route::get('box-labels/batches/{batch}/zpl', [BoxLabelController::class, 'zpl'])
        ->middleware('can:box_labels.print')
        ->whereNumber('batch')
        ->name('box-labels.zpl');

    Route::livewire('quality', 'pages::quality.index')->middleware('can:quality.manage')->name('quality.index');
    Route::livewire('cedi/count', 'pages::cedi.count')->middleware('can:audits.count')->name('cedi.count');
    Route::livewire('discrepancies', 'pages::cedi.discrepancies')->middleware('can:discrepancies.resolve')->name('discrepancies.index');

    Route::livewire('operations', 'pages::operations.index')->middleware('can:ops.alerts')->name('operations.index');
    Route::livewire('receipts', 'pages::receipts.index')->middleware('can:receipts.request')->name('receipts.index');

    Route::prefix('pallets')->name('pallets.')->group(function () {
        Route::livewire('/', 'pages::pallets.index')->middleware('can:pallets.view')->name('index');
        Route::livewire('rejected-scans', 'pages::pallets.rejected')->middleware('can:pallets.view')->name('rejected');
        Route::livewire('{pallet}', 'pages::pallets.show')->middleware('can:pallets.view')->whereNumber('pallet')->name('show');

        // Whoever can print or reprint can open the printable page of a recorded print.
        Route::get('{pallet}/labels/{print}', [PalletLabelController::class, 'show'])
            ->middleware('can:pallets.label')
            ->whereNumber(['pallet', 'print'])
            ->name('label');

        Route::get('{pallet}/labels/{print}/zpl', [PalletLabelController::class, 'zpl'])
            ->middleware('can:pallets.label')
            ->whereNumber(['pallet', 'print'])
            ->name('label.zpl');

        Route::post('{pallet}/labels/{print}/print-direct', [PalletLabelController::class, 'printDirect'])
            ->middleware('can:pallets.label')
            ->whereNumber(['pallet', 'print'])
            ->name('label.print-direct');
    });
});
