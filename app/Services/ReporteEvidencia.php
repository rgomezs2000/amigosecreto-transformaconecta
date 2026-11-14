<?php

namespace App\Services;

use App\Models\ChatReport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Genera una imagen WebP de evidencia para un caso de moderación.
 *
 * No es una captura real del navegador: es una tarjeta con el mensaje
 * reportado, su autor, la conversación, la fecha y el motivo. Se guarda en la
 * carpeta de adjuntos del ticket para que la administración la conserve como
 * soporte visual.
 */
class ReporteEvidencia
{
    private const WIDTH = 1200;

    private const HEIGHT = 900;

    public function generar(ChatReport $report): ?string
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            return null;
        }

        $message = $report->message;
        $conversation = $message?->conversation ?? $report->conversation;
        $reporter = $report->reporter;
        $author = $message?->user;
        $body = trim(strip_tags((string) ($message?->body ?? '')));

        if ($body === '') {
            $body = 'Sin texto en el contenido reportado.';
        }

        $lines = [
            ['label' => 'Ticket', 'value' => 'Nº '.$report->id],
            ['label' => 'Tipo', 'value' => $report->typeLabel()],
            ['label' => 'Conversación', 'value' => $this->conversationLabel($conversation)],
            ['label' => 'Autor del mensaje', 'value' => $author?->name ?? '—'],
            ['label' => 'Reportado por', 'value' => $reporter?->name ?? '—'],
            ['label' => 'Fecha', 'value' => $report->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i')],
            ['label' => 'Motivo', 'value' => Str::limit(trim(strip_tags((string) $report->reason)), 500)],
        ];

        $font = $this->fontPath();
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($img === false) {
            return null;
        }

        $background = imagecolorallocate($img, 249, 250, 251);
        $surface = imagecolorallocate($img, 255, 255, 255);
        $ink = imagecolorallocate($img, 30, 41, 59);
        $muted = imagecolorallocate($img, 71, 85, 105);
        $accent = imagecolorallocate($img, 47, 111, 98);
        $border = imagecolorallocate($img, 203, 213, 225);

        imagefill($img, 0, 0, $background);
        imagefilledrectangle($img, 0, 0, self::WIDTH, 120, $accent);
        imagefilledrectangle($img, 40, 145, self::WIDTH - 40, self::HEIGHT - 40, $surface);
        imagerectangle($img, 40, 145, self::WIDTH - 40, self::HEIGHT - 40, $border);

        if ($font !== null) {
            imagettftext($img, 16, 0, 60, 58, imagecolorallocate($img, 230, 242, 239), $font, 'AMIGO SECRETO INCLUSIVO');
            imagettftext($img, 28, 0, 60, 98, imagecolorallocate($img, 255, 255, 255), $font, 'Evidencia del contenido reportado');

            $y = 195;

            foreach ($lines as $line) {
                $y = $this->drawField($img, $font, $line['label'], (string) $line['value'], 70, $y, $muted, $ink, $border);
            }

            $y += 5;
            imagettftext($img, 15, 0, 70, $y, $muted, $font, 'Contenido reportado');
            $y += 34;

            $wrapped = $this->wrap($font, 21, $body, self::WIDTH - 160);

            foreach (array_slice($wrapped, 0, 15) as $row) {
                imagettftext($img, 21, 0, 70, $y, $ink, $font, $row);
                $y += 32;
            }
        } else {
            imagestring($img, 5, 60, 45, 'Evidencia del contenido reportado', imagecolorallocate($img, 255, 255, 255));
            $y = 185;

            foreach ($lines as $line) {
                imagestring($img, 3, 70, $y, Str::limit(Str::ascii($line['label'].': '.$line['value']), 120), $muted);
                $y += 26;
            }

            imagestring($img, 4, 70, $y + 10, 'Contenido reportado', $muted);
            $y += 45;

            foreach (array_slice(explode("\n", wordwrap(Str::ascii($body), 105, "\n", true)), 0, 14) as $row) {
                imagestring($img, 4, 70, $y, $row, $ink);
                $y += 24;
            }
        }

        $path = 'reportes/'.$report->id.'/imagenes-videos/evidencia-'.$report->id.'.webp';
        $absolute = Storage::disk('local')->path($path);
        Storage::disk('local')->makeDirectory(dirname($path));

        $saved = imagewebp($img, $absolute, 88);
        imagedestroy($img);

        return $saved ? $path : null;
    }

    /**
     * Dibuja una etiqueta y su valor, y avanza la coordenada vertical.
     */
    private function drawField($img, string $font, string $label, string $value, int $x, int $y, int $labelColor, int $valueColor, int $borderColor): int
    {
        imagettftext($img, 13, 0, $x, $y, $labelColor, $font, mb_strtoupper($label));
        $y += 26;

        $wrapped = $this->wrap($font, 18, $value, self::WIDTH - 160);

        foreach (array_slice($wrapped, 0, 2) as $row) {
            imagettftext($img, 18, 0, $x, $y, $valueColor, $font, $row);
            $y += 26;
        }

        // Línea tenue de separación.
        imageline($img, $x, $y + 2, self::WIDTH - 70, $y + 2, $borderColor);

        return $y + 14;
    }

    /**
     * @return array<int, string>
     */
    private function wrap(string $font, int $size, string $text, int $maxWidth): array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $words = preg_split('/\s+/u', trim($paragraph)) ?: [];
            $line = '';

            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;
                $box = imagettfbbox($size, 0, $font, $candidate);
                $width = $box !== false ? abs($box[2] - $box[0]) : 0;

                if ($width > $maxWidth && $line !== '') {
                    $rows[] = $line;
                    $line = $word;
                } else {
                    $line = $candidate;
                }
            }

            if ($line !== '') {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    private function conversationLabel($conversation): string
    {
        if ($conversation === null) {
            return '—';
        }

        if ($conversation->isGroup()) {
            return 'Grupo: '.($conversation->name ?: 'Sin nombre');
        }

        $names = $conversation->users->pluck('name')->filter()->implode(' / ');

        return $names !== '' ? 'Directa: '.$names : 'Conversación directa';
    }

    private function fontPath(): ?string
    {
        $candidates = [
            resource_path('fonts/DejaVuSans.ttf'),
            base_path('public/fonts/DejaVuSans.ttf'),
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\segoeui.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
