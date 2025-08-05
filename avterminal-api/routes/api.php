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

Route::get('/amocrm/info', [AmoCRMController::class, 'info']);
Route::get('/amocrm/export-xml', [AmoCRMController::class, 'exportToXml']);
Route::get('/amocrm/lead/{id}', [AmoCRMController::class, 'getLeadData']);

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
