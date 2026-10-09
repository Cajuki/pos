<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        try {
            DB::select('select 1');
            $database = 'connected';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        return response()->json([
            'status' => $database === 'connected' ? 'ok' : 'unavailable',
            'service' => 'pos-api',
            'version' => 'v1',
            'database' => $database,
        ], $database === 'connected' ? 200 : 503);
    })->name('api.v1.health');

    Route::prefix('auth')->name('api.v1.auth.')->group(function (): void {
        Route::post('/register-business', [AuthController::class, 'registerBusiness'])
            ->middleware('throttle:3,1')->name('register-business');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')->name('login');
        Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout'])->name('logout');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', fn (Request $request) => response()->json(['data' => $request->user()]))
            ->name('api.v1.user');

        Route::get('/businesses', [BusinessController::class, 'index'])->name('api.v1.businesses.index');
        Route::get('/business/current', [AuthController::class, 'currentBusiness'])
            ->middleware('business')->name('api.v1.business.current');
        Route::middleware('business')->group(function (): void {
            Route::get('/business/staff', [BusinessController::class, 'staff'])->name('api.v1.business.staff.index');
            Route::post('/business/staff', [BusinessController::class, 'addStaff'])->name('api.v1.business.staff.store');
            Route::get('/business/settings', [BusinessController::class, 'settings'])->name('api.v1.business.settings.show');
            Route::put('/business/settings', [BusinessController::class, 'updateSettings'])->name('api.v1.business.settings.update');
        });
        Route::get('/businesses/{business}', [BusinessController::class, 'show'])
            ->middleware('business')->name('api.v1.businesses.show');

        Route::middleware('business')->group(function (): void {
            // Products & Categories
            Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update']);
            Route::get('/products/barcode/{barcode}', [ProductController::class, 'barcode'])->name('api.v1.products.barcode');
            Route::get('/categories', [CategoryController::class, 'index'])->name('api.v1.categories.index');
            Route::post('/categories', [CategoryController::class, 'store'])->name('api.v1.categories.store');

            // Inventory
            Route::get('/inventory', [InventoryController::class, 'index'])->name('api.v1.inventory.index');
            Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('api.v1.inventory.movements');
            Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])->name('api.v1.inventory.adjustments');
            Route::post('/inventory/barcodes/receive', [InventoryController::class, 'receiveBarcode'])->name('api.v1.inventory.barcodes.receive');

            // Sales & Checkout
            Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show']);

            // Customers
            Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show']);

            // Suppliers
            Route::apiResource('suppliers', SupplierController::class)->only(['index', 'store']);

            // Purchases
            Route::apiResource('purchases', PurchaseController::class)->only(['index', 'store']);

            // Expenses
            Route::get('/expenses', [ExpenseController::class, 'index'])->name('api.v1.expenses.index');
            Route::post('/expenses', [ExpenseController::class, 'store'])->name('api.v1.expenses.store');
            Route::get('/expenses/categories', [ExpenseController::class, 'categories'])->name('api.v1.expenses.categories');

            // Cash Registers
            Route::get('/cash-registers/current', [CashRegisterController::class, 'current'])->name('api.v1.cash-registers.current');
            Route::post('/cash-registers/open', [CashRegisterController::class, 'open'])->name('api.v1.cash-registers.open');
            Route::post('/cash-registers/close', [CashRegisterController::class, 'close'])->name('api.v1.cash-registers.close');

            // Reports / Dashboard
            Route::get('/reports/summary', [ReportController::class, 'summary'])->name('api.v1.reports.summary');
        });
    });
});
