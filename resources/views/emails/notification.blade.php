<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no,address=no,email=no,date=no">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title }}</title>

    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->

    <style>
        html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        body {
            background-color: #F9FAFB;
            color: #1E293B;
            font-family: 'Montserrat', Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
        a { color: #2F6F62; }
        .email-container { width: 100%; max-width: 640px; }
        .email-card { background-color: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 14px; overflow: hidden; }
        .email-pad { padding-left: 40px !important; padding-right: 40px !important; }
        .email-title { font-size: 30px; line-height: 1.2; }
        .email-text { font-size: 16px; line-height: 1.65; }
        .email-muted { font-size: 14px; line-height: 1.6; }
        .email-text, .email-greeting, .email-muted { word-wrap: break-word; overflow-wrap: break-word; }
        .email-button a:hover { background-color: #245A4F !important; }
        @media only screen and (max-width: 640px) {
            .email-outer { padding: 18px 10px !important; }
            .email-container { width: 100% !important; max-width: 100% !important; }
            .email-card { border-radius: 0 !important; border-left: 0 !important; border-right: 0 !important; }
            .email-pad { padding-left: 24px !important; padding-right: 24px !important; }
            .email-title { font-size: 27px !important; line-height: 1.22 !important; }
            .email-text { font-size: 16px !important; line-height: 1.6 !important; }
            .email-button { width: 100% !important; max-width: 100% !important; }
            .email-button a { display: block !important; width: 100% !important; box-sizing: border-box !important; }
        }
        @media only screen and (max-width: 420px) {
            .email-outer { padding: 10px 0 !important; }
            .email-pad { padding-left: 18px !important; padding-right: 18px !important; }
            .email-title { font-size: 24px !important; line-height: 1.25 !important; }
            .email-text { font-size: 15px !important; line-height: 1.6 !important; }
            .email-button a { padding: 14px 16px !important; font-size: 15px !important; }
            .email-footer { padding-left: 18px !important; padding-right: 18px !important; }
        }
        @media only screen and (min-width: 900px) {
            .email-outer { padding: 0 !important; }
            .email-container { width: 100% !important; max-width: 100% !important; }
            .email-card { border-radius: 0 !important; border-left: 0 !important; border-right: 0 !important; }
            .email-pad { padding-left: 40px !important; padding-right: 40px !important; }
        }
        @media only screen and (min-width: 1200px) {
            .email-pad { padding-left: 48px !important; padding-right: 48px !important; }
            .email-title { font-size: 32px !important; }
        }
        @media only screen and (min-width: 1100px) {
            /* La tarjeta llena el ancho, pero el contenido se centra en una
               columna legible para TV y monitores grandes. */
            .email-pad,
            .email-footer {
                padding-left: calc(50% - 480px) !important;
                padding-right: calc(50% - 480px) !important;
            }
        }
    </style>

    <!--[if mso]>
    <style>
        body, table, td, p, h1, a { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->
</head>
<body>
    <div style="display:none;font-size:1px;color:#F9FAFB;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
        {{ $options['preheader'] ?? $title }}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F9FAFB;">
        <tr>
            <td class="email-outer" align="center" style="padding:32px 16px;">
                <!--[if mso]>
                <table role="presentation" width="100%" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td>
                <![endif]-->
                <table role="presentation" class="email-container email-card" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;background-color:#FFFFFF;border:1px solid #CBD5E1;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td class="email-divider" bgcolor="#2F6F62" style="height:6px;background-color:#2F6F62;font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    <tr>
                        <td class="email-pad" bgcolor="#FFFFFF" style="padding:36px 40px 10px 40px;background-color:#FFFFFF;">
                            <p class="email-muted" style="margin:0 0 12px;color:#475569;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;letter-spacing:.08em;line-height:1.5;text-transform:uppercase;">
                                {{ $options['eyebrow'] ?? 'Amigo Secreto Inclusivo' }}
                            </p>

                            <h1 class="email-title" style="margin:0 0 22px;color:#1E293B;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:30px;font-weight:800;letter-spacing:-.02em;line-height:1.2;mso-line-height-rule:exactly;">
                                {{ $title }}
                            </h1>

                            @if (! empty($options['recipient_name']))
                                <p class="email-greeting" style="margin:0 0 16px;color:#1E293B;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:16px;font-weight:600;line-height:1.55;word-wrap:break-word;overflow-wrap:break-word;">
                                    Hola, {{ $options['recipient_name'] }}:
                                </p>
                            @endif

                            @foreach ($lines as $line)
                                <p class="email-text" style="margin:0 0 16px;color:#334155;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:16px;line-height:1.65;word-wrap:break-word;overflow-wrap:break-word;">
                                    {!! nl2br(e($line)) !!}
                                </p>
                            @endforeach
                        </td>
                    </tr>

                    @if (! empty($action['label']) && ! empty($action['url']))
                        <tr>
                            <td align="center" class="email-pad" style="padding:8px 40px 34px 40px;background-color:#FFFFFF;">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="email-button" style="width:100%;max-width:360px;">
                                    <tr>
                                        <td align="center" bgcolor="#2F6F62" style="border-radius:10px;background-color:#2F6F62;mso-padding-alt:16px 28px;">
                                            <a href="{{ $action['url'] }}" target="_blank" rel="noopener"
                                               style="display:block;padding:16px 28px;color:#FFFFFF;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;line-height:1.25;text-decoration:none;border-radius:10px;">
                                                {{ $action['label'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="email-footer" bgcolor="#F1F5F9" style="padding:24px 40px 28px 40px;background-color:#F1F5F9;border-top:1px solid #CBD5E1;">
                            <p class="email-muted" style="margin:0 0 8px;color:#475569;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;">
                                {{ $options['footer_note'] ?? 'Este es un correo automático. No respondas a este mensaje.' }}
                            </p>
                            <p class="email-muted" style="margin:0 0 8px;color:#475569;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;">
                                {{ $appName }} · {{ date('Y') }}
                            </p>
                            <p class="email-muted" style="margin:0;color:#475569;font-family:'Montserrat',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;">
                                Si no reconoces esta acción, puedes ignorar este correo.
                            </p>
                        </td>
                    </tr>
                </table>
                <!--[if mso]>
                </td></tr></table>
                <![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
