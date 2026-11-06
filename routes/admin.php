<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('users', 'pages::admin.users')->middleware('can:users.manage')->name('users');
    Route::livewire('close-reasons', 'pages::admin.close-reasons')->middleware('can:close_reasons.manage')->name('close-reasons');
    Route::livewire('audit', 'pages::admin.audit')->middleware('can:audit.view')->name('audit');
    Route::livewire('label-designer', 'pages::admin.label-designer')->name('label-designer');
    Route::livewire('etiquetas-config', 'pages::admin.label-designer')->name('etiquetas-config');
});
