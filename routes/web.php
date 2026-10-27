<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DeletionRequestController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ParcelController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/', [TrackingController::class, 'show'])->name('track');
Route::get('/track', [TrackingController::class, 'lookup'])->middleware('throttle:tracking')->name('track.lookup');

// Authentication
Route::get('/login', [AuthController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Every signed-in, active account
Route::middleware(['auth', 'role:staff,dispatch,admin,superadmin'])->group(function () {
    Route::get('/parcels', [ParcelController::class, 'index'])->name('parcels.index');
    Route::get('/parcels/{parcel:tracking_number}', [ParcelController::class, 'show'])->name('parcels.show');
    Route::post('/parcels/{parcel:tracking_number}/deletion-requests', [DeletionRequestController::class, 'store'])->name('deletions.store');
    Route::get('/notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
});

// Warehouse: authenticated + role-gated, then policy-checked inside controllers
Route::middleware(['auth', 'role:staff,admin,superadmin'])->group(function () {
    Route::prefix('warehouse')->group(function () {
        Route::get('/', [WarehouseController::class, 'create'])->name('warehouse.create');
        Route::post('/parcels', [WarehouseController::class, 'store'])->middleware('throttle:booking')->name('warehouse.store');
        Route::post('/parcels/{parcel:tracking_number}/receive', [WarehouseController::class, 'receive'])->name('warehouse.receive');
        Route::post('/scan/parse', [ScanController::class, 'parse'])->middleware('throttle:scan')->name('warehouse.scan.parse');
    });
    Route::post('/parcels/{parcel:tracking_number}/send-tracking', [WarehouseController::class, 'sendTracking'])->middleware('throttle:booking')->name('parcels.sendTracking');
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
});

// Dispatch: riders and deliveries
Route::middleware(['auth', 'role:dispatch,admin,superadmin', 'throttle:booking'])->prefix('dispatch')->group(function () {
    Route::get('/', [DispatchController::class, 'index'])->name('dispatch.index')->withoutMiddleware('throttle:booking');
    Route::post('/assign', [DispatchController::class, 'assign'])->name('dispatch.assign');
    Route::post('/parcels/{parcel:tracking_number}/delivered', [DispatchController::class, 'deliver'])->name('dispatch.deliver');
    Route::post('/parcels/{parcel:tracking_number}/exception', [DispatchController::class, 'exception'])->name('dispatch.exception');
    Route::post('/riders', [DispatchController::class, 'storeRider'])->name('riders.store');
    Route::post('/riders/{rider}/toggle', [DispatchController::class, 'toggleRider'])->name('riders.toggle');
});

// Admin + superadmin
Route::middleware(['auth', 'role:admin,superadmin'])->group(function () {
    Route::post('/parcels/{parcel:tracking_number}/rebook', [ParcelController::class, 'rebook'])->name('parcels.rebook');
    Route::post('/deletion-requests/{deletion}/approve', [DeletionRequestController::class, 'approve'])->name('deletions.approve');
    Route::post('/deletion-requests/{deletion}/reject', [DeletionRequestController::class, 'reject'])->name('deletions.reject');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{user}/edit', [StaffController::class, 'edit'])->name('staff.edit');
    Route::put('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('/staff/{user}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle');
    Route::delete('/staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');
});

Route::middleware(['auth', 'role:superadmin'])->get('/activity', [ActivityController::class, 'index'])->name('activity.index');

// Status transitions (policy decides which role may do which step)
Route::middleware(['auth'])->post('/parcels/{parcel}/advance', [WarehouseController::class, 'advance'])
    ->middleware('throttle:booking')->name('parcels.advance');
