{{ $title }}

@if (! empty($options['recipient_name']))
Hola, {{ $options['recipient_name'] }}:
@endif

@foreach ($lines as $line)
{{ $line }}

@endforeach
@if (! empty($action['label']) && ! empty($action['url']))
{{ $action['label'] }}: {{ $action['url'] }}
@endif

{{ $options['footer_note'] ?? 'Este es un correo automático. No respondas a este mensaje.' }}
{{ $appName }} · {{ date('Y') }}
Si no reconoces esta acción, puedes ignorar este correo.
