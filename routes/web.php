<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ModuleController;
use Illuminate\Support\Facades\Route;

// Punto de entrada: decide entre panel y acceso según la sesión.
Route::get('/', [HomeController::class, 'index'])->name('home');

// Pantalla de acceso.
Route::get('/login', [LoginController::class, 'index'])->name('login');

// Validación de usuario/correo y clave (responde JSON para el AJAX).
Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');

// Acceso con Google por OAuth (flujo de redirección).
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'googleLogin'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleCallback'])->name('google.callback');

// Panel principal.
Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// Marcador de posición de cada módulo, protegido por acceso al módulo.
Route::get('/modulos/{module}', [ModuleController::class, 'show'])
    ->middleware(['auth', 'module.access'])
    ->name('modules.show');
