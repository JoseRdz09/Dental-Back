<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\ClinicTeamController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\PatientController;


// Autenticación
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Vincular contraseña a una cuenta nueva del equipo clínico
Route::post('/clinics/{clinicId}/team', [ClinicTeamController::class,'store',])->whereNumber('clinicId');

Route::middleware('auth:sanctum')->group(function () {
    // Sesión
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Citas
    Route::get('/appointments', [AppointmentController::class,'index',]);
    Route::post('/appointments', [AppointmentController::class, 'store']);

    Route::put('/appointments/{appointment}', [
        AppointmentController::class,
        'update',
    ]);

    // Clínicas
     Route::post('/clinics', [ClinicController::class, 'store']);

    Route::get('/my-clinics', [ClinicController::class,'myClinics',]);

    Route::put('/my-clinics/{clinic}', [ClinicController::class,'update',]);

    Route::delete('/clinics/{clinicId}',[ClinicController::class, 'destroy']);

    // Equipo clínico
    Route::get('/clinics/{clinicId}/team', [ClinicTeamController::class,'index',])->whereNumber('clinicId');

    Route::put('/clinics/{clinicId}/team/{userId}', [ClinicTeamController::class,'update',])->whereNumber('clinicId')->whereNumber('userId');

    Route::delete('/users/{userId}', [ClinicTeamController::class,'destroy',]);

    //Profile Settings
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::put('/settings/password', [SettingsController::class, 'updatePassword']);

    //Patients
    Route::apiResource('patients', PatientController::class);

});
