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



Route::middleware('auth:sanctum')->prefix('amocrm')->group(function () {
    Route::get('/info', [AmoCRMController::class, 'info']);
    Route::get('/export-xml', [AmoCRMController::class, 'exportToXml']);
    Route::get('/lead/{id}', [AmoCRMController::class, 'getLeadData']);
    Route::get('/lead/{id}/formatted', [AmoCRMController::class, 'getFormattedLeadAndContactData']);
    Route::get('/lead/{id}/xml', [AmoCRMController::class, 'generateXmlByLeadId']);
    

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
