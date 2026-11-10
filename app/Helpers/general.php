<?php

if (! function_exists('normalize_login')) {
    /**
     * Normaliza el usuario o correo que llega del formulario.
     */
    function normalize_login(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
