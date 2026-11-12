<?php

use App\Services\MailNotifier;

if (! function_exists('send_mail')) {
    /**
     * Envía un correo con la plantilla accesible del sistema.
     *
     * Reutilizable desde controladores, modelos, seeders o comandos. Cada
     * envío queda registrado en SQL (email_logs) y en Firestore (correos).
     *
     * @param  string|array<int, string>  $to
     * @param  string|array<int, string>  $lines
     * @param  array{label:string, url:string}|null  $action
     * @param  array{recipient_name?:string, eyebrow?:string, preheader?:string, footer_note?:string, source?:string}  $options
     */
    function send_mail(
        string|array $to,
        string $subject,
        string $title,
        string|array $lines = [],
        ?array $action = null,
        array $options = []
    ): bool {
        return app(MailNotifier::class)->send($to, $subject, $title, $lines, $action, $options);
    }
}
