<?php

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

if (! function_exists('send_mail')) {
    /**
     * Envía un correo con la plantilla accesible del sistema.
     *
     * Reutilizable desde controladores, modelos, seeders o comandos.
     *
     * @param  string|array<int, string>  $to
     * @param  string|array<int, string>  $lines
     * @param  array{label:string, url:string}|null  $action
     * @param  array{recipient_name?:string, eyebrow?:string, preheader?:string, footer_note?:string}  $options
     */
    function send_mail(
        string|array $to,
        string $subject,
        string $title,
        string|array $lines = [],
        ?array $action = null,
        array $options = []
    ): bool {
        $recipients = is_array($to) ? array_values($to) : [$to];
        $mailLines = is_array($lines) ? array_values($lines) : [$lines];

        if ($recipients === []) {
            return false;
        }

        if (! \App\Models\NotificationSetting::current()->email_enabled) {
            Log::info('Correo omitido: las notificaciones por correo están desactivadas.', [
                'to' => $recipients,
                'subject' => $subject,
            ]);

            return false;
        }

        try {
            Mail::to($recipients)->send(
                new NotificationMail($subject, $title, $mailLines, $action, $options)
            );

            return true;
        } catch (Throwable $exception) {
            Log::error('No se pudo enviar el correo', [
                'to' => $recipients,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
