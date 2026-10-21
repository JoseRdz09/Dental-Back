<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\ClinicTeamController;


//Auth
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    //Citas
    Route::get('/appointments', [AppointmentController::class, 'index']);
    //Clinicas
    Route::get('/my-clinics', [ClinicController::class, 'myClinics']);
    // Actualizar una clínica existente
    Route::put('/my-clinics/{clinic}', [ClinicController::class, 'update']);
    //Consulta equipo clinico
    Route::get('/clinics/{clinicId}/team', [ClinicTeamController::class, 'index']);
    //Consulta Equipo clinico
    Route::get('/clinics/{clinicId}/team', [ClinicTeamController::class, 'index'])->whereNumber('clinicId');
    //Edita Equipo clinico
    Route::put('/clinics/{clinicId}/team/{userId}', [ClinicTeamController::class, 'update']);
    //Vincula password generado a la cuenta nueva de equipo clinico
    Route::post('/clinics/{clinicId}/team',[ClinicTeamController::class, 'store']);


});

