<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OperationalController;

// Mobile API Authentication
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Current User Profile
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Catalog & Products (Cached heavily)
    Route::get('/catalog/products', [CatalogController::class, 'index']);
    Route::get('/catalog/categories', [CatalogController::class, 'categories']);

    // Stock & Inventory
    Route::get('/stock/current', [StockController::class, 'current']);
    
    // B2B Ordering (Mobile to HO)
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    
    // Retail POS via Mobile
    Route::post('/pos/sale', [OrderController::class, 'posSale']);

    // Operational Tools
    
    // Meetings
    Route::get('/meetings', [OperationalController::class, 'meetings']);
    Route::post('/meetings', [OperationalController::class, 'createMeeting']);

    // Support Tickets
    Route::get('/tickets', [OperationalController::class, 'tickets']);
    Route::post('/tickets', [OperationalController::class, 'createTicket']);
    Route::post('/tickets/{id}/reply', [OperationalController::class, 'replyTicket']);

    // Shop Audits (For Territory Heads)
    Route::post('/audits', [OperationalController::class, 'submitAudit']);
});
