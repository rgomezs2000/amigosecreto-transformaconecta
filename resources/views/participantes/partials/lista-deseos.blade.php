<div class="participantes-modal">
    <p class="participantes-modal__eyebrow">Participante</p>
    <h3 class="participantes-modal__name">{{ $participant->name }}</h3>

    @if ($items->isEmpty())
        <p class="participantes-empty">
            <i class="fa-solid fa-list" aria-hidden="true"></i>
            <span>Sin lista de deseos registrada.</span>
        </p>
    @else
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th scope="col">Deseo</th>
                        <th scope="col">Descripción</th>
                        <th scope="col">Enlace</th>
                        <th scope="col">Registrado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->title }}</td>
                            <td>{{ format_text($item->description, '—') }}</td>
                            <td>
                                @if ($item->url)
                                    <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer">Abrir</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ format_date($item->created_at, 'd/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
