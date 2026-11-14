<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatReportAttachment extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    public const KIND_AUDIO = 'audio';

    public const KIND_DOCUMENT = 'document';

    public const KIND_EVIDENCE = 'evidence';

    protected $fillable = [
        'report_id',
        'kind',
        'path',
        'name',
        'mime',
        'size',
        'is_evidence',
    ];

    protected $casts = [
        'size' => 'integer',
        'is_evidence' => 'boolean',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ChatReport::class, 'report_id');
    }

    public function isImage(): bool
    {
        return $this->kind === self::KIND_IMAGE || $this->kind === self::KIND_EVIDENCE;
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    public function isAudio(): bool
    {
        return $this->kind === self::KIND_AUDIO;
    }

    public function isDocument(): bool
    {
        return $this->kind === self::KIND_DOCUMENT;
    }

    public function humanSize(): string
    {
        $size = (int) $this->size;

        if ($size >= 1048576) {
            return number_format($size / 1048576, 1, ',', '.').' MB';
        }

        if ($size >= 1024) {
            return number_format($size / 1024, 0, ',', '.').' KB';
        }

        return $size.' B';
    }
}
