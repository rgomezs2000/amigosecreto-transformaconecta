<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#F9FAFB">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <title>Compatibilidad de navegadores · Amigo Secreto Inclusivo</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 12px;
            background: #f9fafb;
            color: #1e293b;
            font-family: "Montserrat", Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.55;
        }
        a { color: #245a4f; }
        .wrap { max-width: 980px; margin: 0 auto; }
        .header { margin-bottom: 18px; }
        .eyebrow {
            margin: 0 0 4px;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        h1 { margin: 0 0 8px; font-size: 30px; font-weight: 800; letter-spacing: -.03em; line-height: 1.2; }
        h2 { margin: 0 0 12px; font-size: 21px; font-weight: 700; letter-spacing: -.015em; line-height: 1.25; }
        h3 { margin: 18px 0 8px; font-size: 17px; }
        p { margin: 0 0 10px; }
        .lead { max-width: 760px; color: #475569; }
        .card {
            margin-bottom: 18px;
            padding: 20px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            box-shadow: 0 1px 2px rgba(30, 41, 59, .06), 0 8px 24px rgba(30, 41, 59, .06);
        }
        .compat-alert {
            margin-bottom: 18px;
            padding: 16px 18px;
            background: #fef3f2;
            color: #7f1d1d;
            border: 1px solid #fecaca;
            border-left: 6px solid #b42318;
            border-radius: 14px;
        }
        .compat-alert strong { display: block; margin-bottom: 4px; font-size: 17px; }
        .compat-alert p { margin-bottom: 0; }
        .status-card { border-left: 6px solid #64748b; }
        .status-card.is-ok { border-left-color: #2f6f62; }
        .status-card.is-warn { border-left-color: #8a6d3b; }
        .status-card.is-bad { border-left-color: #b42318; }
        .detected { margin: 0 0 14px; }
        .detected dt { font-size: 12px; font-weight: 700; text-transform: uppercase; color: #475569; }
        .detected dd { margin: 2px 0 10px; font-size: 16px; }
        .verdict { margin: 0 0 8px; font-size: 20px; font-weight: 700; }
        .is-ok .verdict { color: #245a4f; }
        .is-warn .verdict { color: #8a6d3b; }
        .is-bad .verdict { color: #b42318; }
        .recommendation { color: #334155; }
        .small { color: #475569; font-size: 14px; }
        .button {
            display: inline-block;
            margin: 4px 8px 4px 0;
            padding: 10px 16px;
            border: 1px solid #245a4f;
            border-radius: 8px;
            background: #2f6f62;
            color: #ffffff;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }
        .button.secondary { background: #ffffff; color: #245a4f; }
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 9px 8px; border: 1px solid #cbd5e1; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
        .pill { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
        .pill.ok { background: #e6f2ef; color: #245a4f; }
        .pill.warn { background: #f7f1e8; color: #8a6d3b; }
        .pill.bad { background: #fef3f2; color: #b42318; }
        ul, ol { margin: 0 0 0 20px; padding: 0; }
        li { margin-bottom: 7px; }
        .foot { margin: 20px 0 0; color: #64748b; font-size: 13px; text-align: center; }
        .error-note { padding: 10px 12px; background: #fef3f2; color: #7f1d1d; border: 1px solid #fecaca; border-radius: 8px; }
        [hidden] { display: none !important; }
        @media (max-width: 640px) {
            body { padding: 14px 8px; }
            h1 { font-size: 25px; }
            .card { padding: 14px; }
            table { font-size: 12px; }
            th, td { padding: 7px 5px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="header">
        <p class="eyebrow">Amigo Secreto Inclusivo</p>
        <h1>Compatibilidad del sistema</h1>
        <p class="lead">
            Este sistema funciona con navegadores modernos Blink, Gecko y WebKit.
            Revisa tu sistema operativo y navegador actuales, y usa una combinación
            compatible para tener soporte técnico.
        </p>
    </header>

    @if (($motivo ?? '') === 'incompatible')
        <section class="compat-alert" role="alert">
            <strong>Acceso bloqueado por incompatibilidad</strong>
            <p>
                El navegador o sistema operativo con el que intentaste entrar no es compatible
                con este sistema. Si tenías una sesión abierta, se cerró automáticamente.
                Actualiza tu navegador o sistema operativo para volver a entrar.
            </p>
        </section>
    @endif

    <section class="card status-card" id="compat-status-card" aria-live="polite">
        <h2>Tu sistema</h2>
        <dl class="detected">
            <dt>Sistema operativo</dt>
            <dd id="detected-os">Detectando...</dd>
            <dt>Navegador</dt>
            <dd id="detected-browser">Detectando...</dd>
            <dt>Motor</dt>
            <dd id="detected-engine">Detectando...</dd>
        </dl>
        <p class="verdict" id="compat-verdict">Comprobando compatibilidad...</p>
        <p class="recommendation" id="compat-recommendation">
            Si tu navegador no es compatible, actualiza el sistema operativo o instala
            una versión actual de Chrome, Edge, Firefox o Safari.
        </p>
        <p class="small" id="compat-audio"></p>
        <p class="small" id="compat-platform-note"></p>
        <p id="compat-continue-wrap" hidden>
            <a class="button" id="compat-continue" href="{{ route('login') }}">Continuar al sistema</a>
        </p>
        <p>
            <button type="button" class="button secondary" id="compat-retry">Volver a comprobar</button>
        </p>
        <noscript>
            <p class="error-note">
                Activa JavaScript para detectar automáticamente tu sistema operativo y navegador.
                Mientras tanto, revisa la tabla de compatibilidad.
            </p>
        </noscript>
    </section>

    <section class="card">
        <h2>Recomendaciones</h2>
        <ol>
            <li>Actualiza el sistema operativo a una versión con soporte de seguridad.</li>
            <li>Instala la última versión de Chrome, Edge, Firefox o Safari.</li>
            <li>En iPhone y iPad actualiza iOS/iPadOS: todos los navegadores usan WebKit.</li>
            <li>Evita Internet Explorer, Edge antiguo (EdgeHTML), Opera Presto y Konqueror/KHTML.</li>
            <li>Si usas un equipo corporativo sin permisos de actualización, contacta a soporte técnico.</li>
        </ol>
    </section>

    <section class="card">
        <h2>Sistemas operativos de escritorio</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Sistema</th>
                    <th>Versión</th>
                    <th>Navegadores</th>
                    <th>Estado</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>Windows</td>
                    <td>11 (23H2 o superior)</td>
                    <td>Chrome, Edge o Firefox actuales</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Windows</td>
                    <td>10 22H2</td>
                    <td>Chrome, Edge o Firefox actuales</td>
                    <td><span class="pill warn">Compatible con reservas</span></td>
                </tr>
                <tr>
                    <td>Windows</td>
                    <td>7, 8 u 8.1</td>
                    <td>Internet Explorer o navegadores antiguos</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>macOS</td>
                    <td>14 Sonoma o superior</td>
                    <td>Safari 17+, Chrome o Firefox actuales</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>macOS</td>
                    <td>13 Ventura</td>
                    <td>Safari 16/17, Chrome o Firefox actuales</td>
                    <td><span class="pill warn">Compatible con reservas</span></td>
                </tr>
                <tr>
                    <td>macOS</td>
                    <td>12 o anterior</td>
                    <td>Safari 15 o anterior</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>Linux</td>
                    <td>Ubuntu 22.04/24.04 LTS</td>
                    <td>Chrome o Firefox actuales</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Linux</td>
                    <td>Debian 12, Fedora actual o RHEL 9</td>
                    <td>Chrome o Firefox actuales</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>ChromeOS</td>
                    <td>Actual</td>
                    <td>Chrome</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Sistemas operativos móviles</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Sistema</th>
                    <th>Versión</th>
                    <th>Navegadores</th>
                    <th>Estado</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>Android</td>
                    <td>12 o superior</td>
                    <td>Chrome, Edge o Samsung Internet actuales</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Android</td>
                    <td>10 u 11</td>
                    <td>Chrome o WebView actualizados</td>
                    <td><span class="pill warn">Compatible con reservas</span></td>
                </tr>
                <tr>
                    <td>Android</td>
                    <td>9 o anterior</td>
                    <td>WebView antiguo</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>iOS / iPadOS</td>
                    <td>17 o superior</td>
                    <td>Safari y navegadores WebKit</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>iOS / iPadOS</td>
                    <td>16</td>
                    <td>Safari y navegadores WebKit</td>
                    <td><span class="pill warn">Compatible con reservas</span></td>
                </tr>
                <tr>
                    <td>iOS / iPadOS</td>
                    <td>15.8 o anterior</td>
                    <td>Safari antiguo</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Versiones de navegador</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Navegador</th>
                    <th>Versión compatible</th>
                    <th>Estado</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>Google Chrome</td>
                    <td>100 o superior; recomendado 120+</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Microsoft Edge (Chromium)</td>
                    <td>100 o superior; recomendado 120+</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Mozilla Firefox</td>
                    <td>100 o superior; ESR actual</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Safari</td>
                    <td>16 o superior; recomendado 17+</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Samsung Internet</td>
                    <td>20 o superior</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Opera (Blink)</td>
                    <td>86 o superior</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Internet Explorer / Trident</td>
                    <td>11 o anterior</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>Microsoft Edge antiguo / EdgeHTML</td>
                    <td>18 o anterior</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>Opera / Presto</td>
                    <td>12 o anterior</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>Konqueror / KHTML</td>
                    <td>4.x y anteriores</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <h2>Motores de navegador</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Motor</th>
                    <th>Navegadores</th>
                    <th>Estado</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>Blink</td>
                    <td>Chrome, Edge actual, Opera, Brave, Samsung Internet</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Gecko</td>
                    <td>Firefox actual y ESR</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>WebKit</td>
                    <td>Safari moderno y navegadores de iOS 16+</td>
                    <td><span class="pill ok">Compatible</span></td>
                </tr>
                <tr>
                    <td>Trident / MSHTML</td>
                    <td>Internet Explorer 11 y anteriores</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>EdgeHTML</td>
                    <td>Microsoft Edge antiguo (12–18)</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>Presto</td>
                    <td>Opera 12 y anteriores</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                <tr>
                    <td>KHTML</td>
                    <td>Konqueror y Safari muy antiguos</td>
                    <td><span class="pill bad">No compatible</span></td>
                </tr>
                </tbody>
            </table>
        </div>
    </section>

    <p class="foot">
        Amigo Secreto Inclusivo · {{ now()->year }} · Si ya actualizaste, vuelve a comprobar tu navegador.
    </p>
</div>

<div id="compat-data"
     data-from="{{ $from }}"
     data-login="{{ route('home') }}"
     data-logout="{{ $logoutUrl }}"
     hidden></div>

<script src="{{ asset('js/browser-support.js') }}?v={{ filemtime(public_path('js/browser-support.js')) }}"></script>
<script>
    (function () {
        var data = document.getElementById('compat-data');

        if (!data) {
            return;
        }

        function setText(id, text) {
            var element = document.getElementById(id);

            if (!element) {
                return;
            }

            if ('textContent' in element) {
                element.textContent = text;
            } else {
                element.innerText = text;
            }
        }

        var result = window.BrowserSupport ? window.BrowserSupport.analyze() : null;
        var card = document.getElementById('compat-status-card');
        var continueWrap = document.getElementById('compat-continue-wrap');
        var continueLink = document.getElementById('compat-continue');
        var retry = document.getElementById('compat-retry');

        function render(compat) {
            if (!compat) {
                setText('detected-os', 'No se pudo detectar');
                setText('detected-browser', 'No se pudo detectar');
                setText('detected-engine', 'No se pudo detectar');
                setText('compat-verdict', 'No se pudo comprobar la compatibilidad');
                return;
            }

            var logoutUrl = data.getAttribute('data-logout') || '';

            if (!compat.supported && logoutUrl) {
                window.location.replace(logoutUrl);
                return;
            }

            var osLabel = compat.os.name + (compat.os.version ? ' ' + compat.os.version : '');
            var browserLabel = compat.browser.name + (compat.browser.version ? ' ' + compat.browser.version : '');
            var statusText = compat.supported
                ? (compat.tier === 1 ? 'Compatible' : 'Compatible con reservas')
                : 'No compatible';
            var statusClass = compat.supported
                ? (compat.tier === 1 ? 'is-ok' : 'is-warn')
                : 'is-bad';

            setText('detected-os', osLabel);
            setText('detected-browser', browserLabel);
            setText('detected-engine', compat.browser.engine);
            setText('compat-verdict', statusText);
            setText('compat-recommendation', compat.recommendation);
            setText(
                'compat-audio',
                compat.features.mediaRecorder
                    ? 'Grabación de audio: disponible.'
                    : 'Grabación de audio: no disponible en este navegador.'
            );
            setText('compat-platform-note', '');

            if (compat.os.family === 'windows' && compat.os.version === '10 u 11') {
                setText(
                    'compat-platform-note',
                    'El navegador no siempre distingue Windows 10 de Windows 11: Windows 11 es compatible y Windows 10 tiene soporte con reservas.'
                );
            } else if (compat.os.family === 'macos' && compat.os.version === 'versión no determinada') {
                setText(
                    'compat-platform-note',
                    'La versión de macOS no se pudo leer por privacidad del navegador; la compatibilidad se validó por la versión del navegador.'
                );
            }

            if (card) {
                card.className = 'card status-card ' + statusClass;
            }

            if (compat.supported && continueWrap && continueLink) {
                var from = data.getAttribute('data-from') || '';
                var login = data.getAttribute('data-login') || '/';

                continueLink.setAttribute('href', from || login);
                continueWrap.removeAttribute('hidden');
            } else if (continueWrap) {
                continueWrap.setAttribute('hidden', 'hidden');
            }

            if (retry) {
                retry.onclick = function () {
                    window.location.reload();
                };
            }
        }

        if (!result) {
            render(null);
            return;
        }

        render(result);

        if (result.os.family === 'windows'
            && result.os.version === '10 u 11'
            && navigator.userAgentData
            && typeof navigator.userAgentData.getHighEntropyValues === 'function') {
            navigator.userAgentData.getHighEntropyValues(['platformVersion']).then(function (values) {
                if (values && values.platformVersion && typeof window.BrowserSupport.refinePlatformVersion === 'function') {
                    render(window.BrowserSupport.refinePlatformVersion(result, values.platformVersion));
                }
            }).catch(function () {
                // Si el navegador no entrega la versión, se mantiene la
                // detección conservadora de Windows 10 u 11.
            });
        }
    }());
</script>
</body>
</html>
