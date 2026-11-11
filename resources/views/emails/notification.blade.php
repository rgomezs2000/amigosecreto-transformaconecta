<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no">
    <title>{{ $title }}</title>
    <style>
        html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        body {
            background-color: #F3F4F6;
            color: #334155;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table { border-collapse: collapse; }
        a { color: #2F6F62; }
        .email-container { width: 600px; max-width: 600px; }
        .email-button a:hover { background-color: #275E53 !important; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .email-pad { padding-left: 22px !important; padding-right: 22px !important; }
            .email-title { font-size: 24px !important; line-height: 1.25 !important; }
            .email-button { width: 100% !important; }
            .email-button a { display: block !important; width: 100% !important; box-sizing: border-box !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .email-body { background-color: #0F172A !important; }
            .email-card { background-color: #1E293B !important; }
            .email-title, .email-text, .email-greeting { color: #E2E8F0 !important; }
            .email-muted { color: #CBD5E1 !important; }
            .email-footer { background-color: #172033 !important; border-top-color: #334155 !important; }
            .email-divider { background-color: #2F6F62 !important; }
        }
    </style>
</head>
<body class="email-body">
    <div style="display:none;font-size:1px;color:#F3F4F6;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        {{ $options['preheader'] ?? $title }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F3F4F6;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" class="email-container email-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background-color:#FFFFFF;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td class="email-divider" style="height:6px;background-color:#2F6F62;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td class="email-pad" style="padding:32px 36px 8px 36px;background-color:#FFFFFF;">
                            <p class="email-muted" style="margin:0 0 12px;color:#64748B;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">
                                {{ $options['eyebrow'] ?? 'Amigo Secreto Inclusivo' }}
                            </p>

                            <h1 class="email-title" style="margin:0 0 20px;color:#1E293B;font-size:28px;line-height:1.25;font-weight:700;">
                                {{ $title }}
                            </h1>

                            @if (! empty($options['recipient_name']))
                                <p class="email-greeting" style="margin:0 0 16px;color:#1E293B;font-size:16px;font-weight:600;">
                                    Hola, {{ $options['recipient_name'] }}:
                                </p>
                            @endif

                            @foreach ($lines as $line)
                                <p class="email-text" style="margin:0 0 16px;color:#334155;font-size:16px;line-height:1.6;">
                                    {!! nl2br(e($line)) !!}
                                </p>
                            @endforeach
                        </td>
                    </tr>

                    @if (! empty($action['label']) && ! empty($action['url']))
                        <tr>
                            <td align="center" class="email-pad" style="padding:8px 36px 32px 36px;background-color:#FFFFFF;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="email-button" style="width:100%;max-width:360px;">
                                    <tr>
                                        <td align="center" bgcolor="#2F6F62" style="border-radius:10px;background-color:#2F6F62;">
                                            <a href="{{ $action['url'] }}" target="_blank" rel="noopener"
                                               style="display:inline-block;padding:15px 26px;color:#FFFFFF;font-family:'Inter',Arial,sans-serif;font-size:16px;font-weight:700;line-height:1.25;text-decoration:none;border-radius:10px;mso-padding-alt:15px 26px;">
                                                {{ $action['label'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="email-footer" style="padding:22px 36px 28px 36px;background-color:#F8FAFC;border-top:1px solid #E2E8F0;">
                            <p class="email-muted" style="margin:0 0 8px;color:#64748B;font-size:14px;line-height:1.6;">
                                {{ $options['footer_note'] ?? 'Este es un correo automático. No respondas a este mensaje.' }}
                            </p>
                            <p class="email-muted" style="margin:0 0 8px;color:#64748B;font-size:14px;line-height:1.6;">
                                {{ $appName }} · {{ date('Y') }}
                            </p>
                            <p class="email-muted" style="margin:0;color:#64748B;font-size:14px;line-height:1.6;">
                                Si no reconoces esta acción, puedes ignorar este correo.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
