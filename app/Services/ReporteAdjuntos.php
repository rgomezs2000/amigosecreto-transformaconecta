<?php

namespace App\Services;

use App\Models\ChatReport;
use App\Models\ChatReportAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Archivos, enlaces y evidencia de un caso de ayuda y soporte.
 *
 * Los archivos viven fuera de la carpeta pública, bajo
 * `storage/app/private/reportes/{ticket}/`, separados por tipo:
 *
 *   imagenes-videos/   imágenes, videos y la evidencia WebP
 *   audios/            audios
 *   documentos/        documentos y cualquier otro archivo
 */
class ReporteAdjuntos
{
    public function __construct(private readonly ReporteEvidencia $evidencia) {}

    /**
     * Guarda uno o más archivos del formulario.
     *
     * @param  array<int, UploadedFile|null>  $files
     */
    public function guardarArchivos(ChatReport $report, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $kind = $this->kindFor($file);
            $directory = $this->directoryFor($kind);
            $path = $this->storeFile($report, $file, $directory);

            if ($path === null) {
                continue;
            }

            $report->attachments()->create([
                'kind' => $kind,
                'path' => $path,
                'name' => $this->originalName($file),
                'mime' => $file->getMimeType(),
                'size' => Storage::disk('local')->size($path) ?: $file->getSize(),
                'is_evidence' => false,
            ]);
        }
    }

    /**
     * Guarda los enlaces de soporte, sin duplicados.
     *
     * @param  array<int, string|null>  $links
     */
    public function guardarEnlaces(ChatReport $report, array $links): void
    {
        $existentes = $report->links()->pluck('url')->all();

        foreach ($links as $link) {
            $url = $this->normalizarUrl((string) $link);

            if ($url === null || in_array($url, $existentes, true)) {
                continue;
            }

            $report->links()->create(['url' => $url]);
            $existentes[] = $url;
        }
    }

    /**
     * Genera (o regenera) la imagen WebP de evidencia del caso.
     */
    public function generarEvidencia(ChatReport $report): ?ChatReportAttachment
    {
        $this->eliminarEvidencia($report);

        $path = $this->evidencia->generar($report);

        if ($path === null) {
            return null;
        }

        return $report->attachments()->create([
            'kind' => ChatReportAttachment::KIND_EVIDENCE,
            'path' => $path,
            'name' => 'Evidencia del contenido.webp',
            'mime' => 'image/webp',
            'size' => Storage::disk('local')->size($path) ?: 0,
            'is_evidence' => true,
        ]);
    }

    /**
     * Borra la evidencia anterior antes de regenerarla.
     */
    public function eliminarEvidencia(ChatReport $report): void
    {
        $report->attachments()
            ->where('is_evidence', true)
            ->get()
            ->each(function (ChatReportAttachment $attachment) {
                Storage::disk('local')->delete($attachment->path);
                $attachment->delete();
            });
    }

    private function kindFor(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        return match (true) {
            Str::startsWith($mime, 'image/') => ChatReportAttachment::KIND_IMAGE,
            Str::startsWith($mime, 'video/') => ChatReportAttachment::KIND_VIDEO,
            Str::startsWith($mime, 'audio/') => ChatReportAttachment::KIND_AUDIO,
            default => ChatReportAttachment::KIND_DOCUMENT,
        };
    }

    private function directoryFor(string $kind): string
    {
        return match ($kind) {
            ChatReportAttachment::KIND_IMAGE,
            ChatReportAttachment::KIND_VIDEO => 'imagenes-videos',
            ChatReportAttachment::KIND_AUDIO => 'audios',
            default => 'documentos',
        };
    }

    private function storeFile(ChatReport $report, UploadedFile $file, string $directory): ?string
    {
        $extension = Str::lower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $base = $base !== '' ? Str::limit($base, 70, '') : 'archivo';
        $filename = $base.'-'.Str::lower(Str::random(8)).'.'.$extension;

        $path = $file->storeAs(
            'reportes/'.$report->id.'/'.$directory,
            $filename,
            'local'
        );

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function originalName(UploadedFile $file): string
    {
        $name = trim((string) $file->getClientOriginalName());

        return $name !== '' ? Str::limit($name, 255, '') : 'Archivo adjunto';
    }

    private function normalizarUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? Str::limit($url, 2000, '') : null;
    }
}
