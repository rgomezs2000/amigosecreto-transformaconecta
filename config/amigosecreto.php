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

];
