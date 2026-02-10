<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\AmoCRMController;


Route::get('/', function () {
    return response()->json(['message' => 'Welcome to the API']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/test/list', [AmoCRMController::class, 'listDtStatusTests']);
Route::get('/test/td/list', [AmoCRMController::class, 'listTdStatusTests']);
Route::get('/test/{number}', [AmoCRMController::class, 'runDtStatusTest'])->where('number', '[1-9]|1[0-9]|2[0-1]');
Route::get('/test/td/{number}', [AmoCRMController::class, 'runTdStatusTest'])->where('number', '2[2-8]');
Route::get('/test', [AmoCRMController::class, 'testFindByVin']);
Route::get('/amocrm/info', [AmoCRMController::class, 'info']);

Route::middleware('auth:sanctum')->prefix('amocrm')->group(function () {
    
    Route::get('/export-xml', [AmoCRMController::class, 'exportToXml']);
    Route::get('/lead/{id}', [AmoCRMController::class, 'getLeadData']);
    Route::get('/lead/{id}/formatted', [AmoCRMController::class, 'getFormattedLeadAndContactData']);
    Route::get('/lead/{id}/xml', [AmoCRMController::class, 'generateXmlByLeadId']);
    Route::post('/dt-status', [AmoCRMController::class, 'updateDtStatus']);
    Route::post('/td-status', [AmoCRMController::class, 'updateTDStatus']);
    
});

Route::post('/setup-test-user', function () {
    $user = User::firstOrCreate(
        ['email' => 'test@example.com'],
        [
            'name' => 'Test User',
            'password' => Hash::make('password'),
        ]
    );

    $token = $user->createToken('test-token')->plainTextToken;

    return ['token' => $token];
});
