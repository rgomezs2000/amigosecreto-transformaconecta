@props([
    'id',
    'title' => '',
    'size' => null,
    'centered' => true,
    'scrollable' => false,
])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true">
    <div class="modal-dialog
        @if ($centered) modal-dialog-centered @endif
        @if ($size) modal-{{ $size }} @endif
        @if ($scrollable) modal-dialog-scrollable @endif">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="{{ $id }}-title">{{ $title }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                {{ $body ?? $slot }}
            </div>

            @isset($footer)
                <div class="modal-footer">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
