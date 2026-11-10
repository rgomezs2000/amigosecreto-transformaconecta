<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cuenta del administrador
    |--------------------------------------------------------------------------
    |
    | Solo se usa al sembrar la base (AdminUserSeeder). En producción hay que
    | definir ADMIN_PASSWORD: el valor por defecto existe para desarrollo.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrador'),
        'username' => env('ADMIN_USERNAME', 'admin'),
        'email' => env('ADMIN_EMAIL', 'admin@amigosecreto.local'),
        'password' => env('ADMIN_PASSWORD', 'Admin123456'),
        'force_password_change' => env('ADMIN_FORCE_PASSWORD_CHANGE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Control de intentos de acceso
    |--------------------------------------------------------------------------
    |
    | Al llegar a max_attempts la cuenta queda bloqueada lockout_minutes.
    | Un administrador puede desbloquearla desde la base de datos.
    |
    */

    'login' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 3),
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
    ],

];
