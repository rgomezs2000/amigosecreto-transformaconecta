<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\Route;

// Punto de entrada: decide entre panel y acceso según la sesión.
Route::get('/', [HomeController::class, 'index'])->name('home');

// Pantalla de acceso. Por ahora solo la vista: la validación de credenciales
// y la verificación de Google se conectan en la siguiente fase.
Route::get('/login', [LoginController::class, 'index'])->name('login');

// Provisional: el panel real se construye en la siguiente fase.
Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');
