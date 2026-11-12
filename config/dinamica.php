<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Restablecer la dinámica del amigo secreto
    |--------------------------------------------------------------------------
    |
    | El proceso se ejecuta como comando artisan (dinamica:reiniciar). El panel
    | lo lanza en un proceso aparte y consulta el avance leyendo el archivo de
    | estado de la ejecución. Si el servidor no permite abrir procesos, se
    | ejecuta en la misma petición (modo síncrono).
    |
    */

    // 'async' => ejecutar el comando en un proceso aparte.
    'async' => (bool) env('DINAMICA_ASYNC', true),

    // Ruta del ejecutable de PHP para el proceso aparte. Vacío: se detecta.
    'php_binary' => env('DINAMICA_PHP_BINARY'),

    // Carpeta donde queda el estado de cada ejecución (progreso y bitácora).
    'path' => env('DINAMICA_PATH', storage_path('app/dinamica')),

    // Canal de Laravel donde se escribe la bitácora del proceso.
    'log' => env('DINAMICA_LOG', 'dinamica'),

    // Minutos tras los cuales una ejecución «en curso» se considera colgada.
    'stale_after' => (int) env('DINAMICA_STALE_AFTER', 5),

    // Días que se conservan los archivos de ejecuciones anteriores.
    'keep_days' => (int) env('DINAMICA_KEEP_DAYS', 7),

];
