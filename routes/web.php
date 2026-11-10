<?php

use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
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

// Provisional: el panel real se construye en la siguiente fase.
Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');
