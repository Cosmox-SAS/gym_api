<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// Controladores
use App\Http\Controllers\GimnasioController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DailyCashboxController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SupplementProductController;
use App\Http\Controllers\SupplementSaleController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\MembershipPlanController;
use App\Http\Controllers\MembershipTypeController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\AccesController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\SubscriptionController;

/*
|--------------------------------------------------------------------------
| RUTAS PÚBLICAS (No requieren Login)
|--------------------------------------------------------------------------
*/

Route::get('/test', function () {
    return response()->json(['ok' => true]);
});

// Autenticación
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Registro Público (QR)
Route::get('/public/plans/{gimnasio_id}', [PublicRegistrationController::class, 'getPlans']);
Route::post('/public/register/{gimnasio_id}', [PublicRegistrationController::class, 'store']);

// Acceso (Kiosco / Huella) — rutas públicas, el kiosco no tiene sesión de admin.
Route::post('/access/identification', [AccesController::class, 'accessByIdentification']);
// El kiosco primero descarga los FMDs, hace el matching con el SDK de DigitalPersona
// y luego llama a /access/fingerprint con el member_id identificado.
Route::get('/access/fingerprints/{gimnasio_id}', [AccesController::class, 'getFingerprintsForGym']);
Route::post('/access/fingerprint', [AccesController::class, 'accessByFingerprint']);
Route::post('/access/fingerprint/match', [AccesController::class, 'matchFingerprint']);
// Templates para identificación 1:N en el kiosco
Route::get('/kiosk/fingerprints/{gimnasio_id}', [AccesController::class, 'getFingerprintTemplates']);

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS (Requieren Token / Login)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Usuario y Logout
    Route::get('/user', function (Request $request) { return $request->user(); });
    Route::post('/logout', [AuthController::class, 'logout']);

    // Gimnasios
    Route::get('/gimnasios', [GimnasioController::class, 'show']);

    // ----------------------------------------------------
    // 🚨 CORRECCIÓN IMPORTANTE AQUÍ 🚨
    // 1. Primero las rutas específicas (stats)
    Route::get('/memberships/stats', [MembershipController::class, 'getStats']);
    Route::get('/memberships/by-member/{memberId}', [MembershipController::class, 'getByMemberId']);

    // 2. Luego el recurso general (apiResource)
    Route::apiResource('/memberships', MembershipController::class);
    // ----------------------------------------------------

    Route::post('/members/{id}/fingerprint', [MemberController::class, 'storeFingerprint']);
    Route::post('/members/photos/upload', [MemberController::class, 'uploadInitialPhoto']);
    Route::apiResource('/members', MemberController::class);
    Route::post('/members/{id}/fingerprint', [MemberController::class, 'enrollFingerprint']);

    // Configuración de Membresías
    Route::apiResource('/membershipPlan', MembershipPlanController::class);
    Route::apiResource('/membershipType', MembershipTypeController::class);

   Route::get('/gimnasio/config', [GimnasioController::class, 'show']);       // Para Cargar datos
    Route::put('/gimnasio/config', [GimnasioController::class, 'updateConfig']); // Para Guardar datos


    // ----------------------------------------------------
    // 🚨 CORRECCIÓN IMPORTANTE AQUÍ TAMBIÉN 🚨
    // 1. Primero el historial y total
    Route::get('/payments/history', [PaymentController::class, 'getHistory']);
    Route::get('/paymentsToday', [PaymentController::class, 'totalToday']);

    // 2. Luego el recurso general
    Route::apiResource('/payments', PaymentController::class);
    // ----------------------------------------------------

    Route::apiResource('/payment_methods', PaymentMethodController::class);

    // Tesorería
    Route::apiResource('/gastos', GastoController::class);
    Route::apiResource('/dailyCashbox', DailyCashboxController::class);

    // Productos y POS
    Route::apiResource('/supplementProduct', SupplementProductController::class);
    Route::post('/supplement-sales/bulk', [SupplementSaleController::class, 'storeBulk']);
    Route::apiResource('/supplementSale', SupplementSaleController::class);
    Route::apiResource('/product-purchases', \App\Http\Controllers\ProductPurchaseController::class);

    // Suscripciones
    Route::get('/subscription', [SubscriptionController::class, 'current']);
    Route::post('/subscription', [SubscriptionController::class, 'subscribe']);
    Route::put('/subscription/switch', [SubscriptionController::class, 'switchPlan']);
    Route::delete('/subscription', [SubscriptionController::class, 'cancel']);
});

// Planes (público para que el frontend los muestre antes de registrarse)
Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);

/*
|--------------------------------------------------------------------------
| RUTAS SERVICE-TO-SERVICE (Requieren X-Service-Key header)
|--------------------------------------------------------------------------
*/
Route::middleware('service.key')->prefix('service')->group(function () {
    Route::get('/subscription-plans', [SubscriptionController::class, 'plans']);
    Route::get('/gyms/subscriptions', [SubscriptionController::class, 'listGymsSubscriptions']);
    Route::get('/gyms/{gimnasioId}/subscription', [SubscriptionController::class, 'getGymSubscription']);
    Route::post('/gyms/{gimnasioId}/subscription', [SubscriptionController::class, 'subscribeGym']);
    Route::put('/gyms/{gimnasioId}/subscription', [SubscriptionController::class, 'switchGymPlan']);
    Route::delete('/gyms/{gimnasioId}/subscription', [SubscriptionController::class, 'cancelGymSubscription']);
});
