<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\ClinicTeamController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\TreatmentController;


// Autenticación
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Vincular contraseña a una cuenta nueva del equipo clínico
Route::post('/clinics/{clinicId}/team', [ClinicTeamController::class,'store',])->whereNumber('clinicId');

Route::middleware('auth:sanctum')->group(function () {
    
    // Sesión
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

   // Citas

    Route::get('/appointments/clinics', [
        AppointmentController::class,
        'clinics',
    ]);

    Route::get('/appointments', [
        AppointmentController::class,
        'index',
    ]);

    Route::get('/appointments/stats', [
        AppointmentController::class,
        'stats',
    ]);

    Route::get('/appointments/options', [
        AppointmentController::class,
        'options',
    ]);

    Route::get('/clinics/{clinicId}/patients/search', [
        AppointmentController::class,
        'searchPatients',
    ]);

    // Crear cita con uno o varios conceptos
    Route::post('/appointments', [
        AppointmentController::class,
        'store',
    ]);

    // Actualizar información general de la cita
    Route::put('/appointments/{appointment}', [
        AppointmentController::class,
        'update',
    ]);

    // Get inidivudual
    Route::get('/appointments/{appointment}', [
        AppointmentController::class,
        'show',
    ]);

    // Agregar conceptos a una cita existente
    Route::post('/appointments/{appointment}/items', [
        AppointmentController::class,
        'addItems',
    ]);

    // Eliminar un concepto de una cita
    Route::delete('/appointments/{appointment}/items/{itemId}', [
        AppointmentController::class,
        'destroyItem',
    ]);

    // Clínicas
     Route::post('/clinics', [ClinicController::class, 'store']);

    Route::get('/my-clinics', [ClinicController::class,'myClinics',]);

    Route::put('/my-clinics/{clinic}', [ClinicController::class,'update',]);

    Route::delete('/clinics/{clinicId}',[ClinicController::class, 'destroy']);

    // Equipo clínico
    Route::get('/clinics/team', [ClinicTeamController::class, 'index']);
    Route::put('/clinics/{clinicId}/team/{userId}', [ClinicTeamController::class,'update',])->whereNumber('clinicId')->whereNumber('userId');
    Route::delete('/users/{userId}', [ClinicTeamController::class,'destroy',]);
    Route::get('/patients/directory', [PatientController::class,'directory',]);
    
    // Vincular contraseña a una cuenta nueva del equipo clínico
    Route::post('/clinics/{clinicId}/team', [ClinicTeamController::class,'store',])->whereNumber('clinicId');

    //Profile Settings
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile']);
    Route::put('/settings/password', [SettingsController::class, 'updatePassword']);
    
    // Organizacion Settings
    Route::get('/organizations/{organization}',[OrganizationController::class, 'show']);
    Route::put('/organizations/{organization}',[OrganizationController::class, 'update']);

    //Patients
    Route::apiResource('patients', PatientController::class);

    // Treatments
    Route::prefix('clinics/{clinicId}/treatments')
        ->whereNumber('clinicId')
        ->group(function () {
            Route::get('/', [TreatmentController::class, 'index']);
            Route::post('/', [TreatmentController::class, 'store']);
            Route::put('/{id}', [TreatmentController::class, 'update']);
            Route::patch('/{id}', [TreatmentController::class, 'update']);
            Route::delete('/{id}', [TreatmentController::class, 'destroy']);
        });


});
