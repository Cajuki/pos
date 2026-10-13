<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CashRegisterController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CommissionController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RestaurantOrderController;
use App\Http\Controllers\Api\V1\RestaurantTableController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SalonStaffController;
use App\Http\Controllers\Api\V1\StaffScheduleController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\WarehouseController;
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
    Route::get('/industries', [BusinessController::class, 'industries'])->name('api.v1.industries');

    Route::prefix('auth')->name('api.v1.auth.')->group(function (): void {
        Route::post('/register-business', [AuthController::class, 'registerBusiness'])
            ->middleware('throttle:3,1')->name('register-business');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')->name('login');
        Route::get('/team-invitations/{token}', [BusinessController::class, 'showInvitation'])
            ->middleware('throttle:10,1')->name('team-invitations.show');
        Route::post('/team-invitations/{token}/accept', [BusinessController::class, 'acceptInvitation'])
            ->middleware('throttle:5,1')->name('team-invitations.accept');
        Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout'])->name('logout');
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/user', fn (Request $request) => response()->json(['data' => $request->user()]))
            ->name('api.v1.user');

        Route::get('/businesses', [BusinessController::class, 'index'])->name('api.v1.businesses.index');
        Route::get('/business/current', [AuthController::class, 'currentBusiness'])
            ->middleware('business')->name('api.v1.business.current');
        Route::middleware(['business', 'business.permission'])->group(function (): void {
            Route::get('/branches', [BranchController::class, 'index'])->name('api.v1.branches.index');
            Route::post('/branches', [BranchController::class, 'store'])->name('api.v1.branches.store');
            Route::patch('/branches/{branch}', [BranchController::class, 'update'])->name('api.v1.branches.update');
            Route::get('/warehouses', [WarehouseController::class, 'index'])->name('api.v1.warehouses.index');
            Route::post('/warehouses', [WarehouseController::class, 'store'])->name('api.v1.warehouses.store');

            Route::get('/business/staff', [BusinessController::class, 'staff'])->name('api.v1.business.staff.index');
            Route::get('/business/providers', [BusinessController::class, 'providers'])->name('api.v1.business.providers.index');
            Route::post('/business/staff', [BusinessController::class, 'addStaff'])->name('api.v1.business.staff.store');
            Route::get('/business/settings', [BusinessController::class, 'settings'])->name('api.v1.business.settings.show');
            Route::put('/business/settings', [BusinessController::class, 'updateSettings'])->name('api.v1.business.settings.update');
            Route::get('/appointments', [AppointmentController::class, 'index'])->name('api.v1.appointments.index');
            Route::post('/appointments', [AppointmentController::class, 'store'])->name('api.v1.appointments.store');
            Route::put('/appointments/{appointment}', [AppointmentController::class, 'update'])->name('api.v1.appointments.update');
            Route::post('/appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])->name('api.v1.appointments.check-in');
            Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('api.v1.appointments.status');
            Route::get('/staff-schedules', [StaffScheduleController::class, 'index'])->name('api.v1.staff-schedules.index');
            Route::put('/staff-schedules', [StaffScheduleController::class, 'upsert'])->name('api.v1.staff-schedules.upsert');
            Route::get('/commissions', [CommissionController::class, 'index'])->name('api.v1.commissions.index');
            Route::patch('/commissions/{commission}/status', [CommissionController::class, 'updateStatus'])->name('api.v1.commissions.status');
            Route::get('/salon/staff', [SalonStaffController::class, 'index'])->name('api.v1.salon.staff.index');
            Route::post('/salon/staff', [SalonStaffController::class, 'store'])->name('api.v1.salon.staff.store');
            Route::put('/salon/staff/{staff}', [SalonStaffController::class, 'update'])->name('api.v1.salon.staff.update');
            Route::patch('/salon/staff/{staff}/status', [SalonStaffController::class, 'updateStatus'])->name('api.v1.salon.staff.status');
            Route::get('/salon/job-titles', [SalonStaffController::class, 'jobTitles'])->name('api.v1.salon.job-titles.index');
            Route::post('/salon/job-titles', [SalonStaffController::class, 'createJobTitle'])->name('api.v1.salon.job-titles.store');
            Route::get('/restaurant-orders', [RestaurantOrderController::class, 'index'])->name('api.v1.restaurant-orders.index');
            Route::post('/restaurant-orders', [RestaurantOrderController::class, 'store'])->name('api.v1.restaurant-orders.store');
            Route::patch('/restaurant-orders/{order}/status', [RestaurantOrderController::class, 'updateStatus'])->name('api.v1.restaurant-orders.status');
            Route::get('/restaurant/tables', [RestaurantTableController::class, 'index'])->name('api.v1.restaurant.tables.index');
            Route::post('/restaurant/tables', [RestaurantTableController::class, 'store'])->name('api.v1.restaurant.tables.store');
            Route::patch('/restaurant/tables/{table}', [RestaurantTableController::class, 'update'])->name('api.v1.restaurant.tables.update');
        });
        Route::get('/businesses/{business}', [BusinessController::class, 'show'])
            ->middleware('business')->name('api.v1.businesses.show');

        Route::middleware(['business', 'business.permission'])->group(function (): void {
            // Products & Categories
            Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update']);
            Route::get('/products/barcode/{barcode}', [ProductController::class, 'barcode'])->name('api.v1.products.barcode');
            Route::get('/categories', [CategoryController::class, 'index'])->name('api.v1.categories.index');
            Route::post('/categories', [CategoryController::class, 'store'])->name('api.v1.categories.store');

            // Inventory
            Route::get('/inventory', [InventoryController::class, 'index'])->name('api.v1.inventory.index');
            Route::get('/inventory/movements', [InventoryController::class, 'movements'])->name('api.v1.inventory.movements');
            Route::get('/inventory/batches', [InventoryController::class, 'batches'])->name('api.v1.inventory.batches');
            Route::post('/inventory/adjustments', [InventoryController::class, 'adjust'])->name('api.v1.inventory.adjustments');
            Route::post('/inventory/batches/receive', [InventoryController::class, 'receiveBatch'])->name('api.v1.inventory.batches.receive');
            Route::post('/inventory/barcodes/receive', [InventoryController::class, 'receiveBarcode'])->name('api.v1.inventory.barcodes.receive');

            // Sales & Checkout
            Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show']);
            Route::post('/sales/{sale}/refunds', [RefundController::class, 'store'])->name('api.v1.sales.refunds.store');

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
            Route::get('/cash-registers', [CashRegisterController::class, 'index'])->name('api.v1.cash-registers.index');
            Route::post('/cash-registers', [CashRegisterController::class, 'store'])->name('api.v1.cash-registers.store');
            Route::post('/cash-registers/open', [CashRegisterController::class, 'open'])->name('api.v1.cash-registers.open');
            Route::post('/cash-registers/close', [CashRegisterController::class, 'close'])->name('api.v1.cash-registers.close');

            // Reports / Dashboard
            Route::get('/reports/summary', [ReportController::class, 'summary'])->name('api.v1.reports.summary');
        });
    });
});
