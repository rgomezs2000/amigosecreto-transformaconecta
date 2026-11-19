(function (window) {
    'use strict';

    function versionParts(value) {
        var parts = String(value || '').replace(/_/g, '.').split('.');
        var result = [];

        for (var index = 0; index < parts.length; index += 1) {
            result.push(parseInt(parts[index], 10) || 0);
        }

        return result;
    }

    function majorOf(value) {
        return versionParts(value)[0] || 0;
    }

    function cleanVersion(value) {
        return String(value || '').replace(/_/g, '.');
    }

    function supportsCss(property, value) {
        try {
            return Boolean(window.CSS && window.CSS.supports && window.CSS.supports(property, value));
        } catch (error) {
            return false;
        }
    }

    function detectFeatures() {
        var features = {
            fetch: typeof window.fetch === 'function',
            promise: typeof window.Promise === 'function',
            urlSearchParams: typeof window.URLSearchParams === 'function',
            url: typeof window.URL === 'function',
            closest: Boolean(window.Element && window.Element.prototype && window.Element.prototype.closest),
            history: Boolean(window.history && typeof window.history.replaceState === 'function'),
            cssVariables: supportsCss('color', 'var(--compat-test)'),
            cssGap: supportsCss('gap', '1px'),
            objectAssign: typeof Object.assign === 'function',
            arrayIncludes: Boolean(window.Array && Array.prototype && Array.prototype.includes),
            stringIncludes: Boolean(window.String && String.prototype && String.prototype.includes),
            promiseFinally: Boolean(
                window.Promise &&
                window.Promise.prototype &&
                typeof window.Promise.prototype.finally === 'function'
            ),
            mediaRecorder: typeof window.MediaRecorder !== 'undefined',
            audioContext: Boolean(window.AudioContext || window.webkitAudioContext)
        };

        features.ok = features.fetch &&
            features.promise &&
            features.urlSearchParams &&
            features.url &&
            features.closest &&
            features.history &&
            features.cssVariables &&
            features.cssGap &&
            features.objectAssign &&
            features.arrayIncludes &&
            features.stringIncludes &&
            features.promiseFinally;

        return features;
    }

    function detectOS(userAgent) {
        var match;
        var major;

        match = userAgent.match(/Windows NT ([\d.]+)/i);

        if (match) {
            var nt = match[1];

            if (nt === '10.0') {
                return {
                    name: 'Windows',
                    version: '10 u 11',
                    family: 'windows',
                    supported: true,
                    tier: 2
                };
            }

            return {
                name: 'Windows',
                version: nt === '6.3' ? '8.1' : nt === '6.2' ? '8' : nt === '6.1' ? '7' : nt,
                family: 'windows',
                supported: false,
                tier: 0
            };
        }

        match = userAgent.match(/Android\s+([\d.]+)/i);

        if (match) {
            major = majorOf(match[1]);

            return {
                name: 'Android',
                version: cleanVersion(match[1]),
                family: 'android',
                supported: major >= 10,
                tier: major >= 12 ? 1 : major >= 10 ? 2 : 0
            };
        }

        if (/(iPhone|iPad|iPod)/i.test(userAgent)) {
            match = userAgent.match(/OS (\d+)[._](\d+)/i);
            major = match ? parseInt(match[1], 10) || 0 : 0;

            return {
                name: /iPad/i.test(userAgent) ? 'iPadOS' : 'iOS',
                version: match ? match[1] + '.' + match[2] : '',
                family: 'ios',
                supported: major >= 16,
                tier: major >= 17 ? 1 : major >= 16 ? 2 : 0
            };
        }

        // iPadOS puede identificarse como Macintosh en modo escritorio.
        if (/Macintosh/i.test(userAgent) && navigator.maxTouchPoints && navigator.maxTouchPoints > 1) {
            return {
                name: 'iPadOS',
                version: 'modo escritorio',
                family: 'ios',
                supported: true,
                tier: 2
            };
        }

        match = userAgent.match(/Mac OS X ([\d_]+)/i);

        if (match) {
            major = majorOf(match[1]);

            if (major >= 14) {
                return {
                    name: 'macOS',
                    version: cleanVersion(match[1]),
                    family: 'macos',
                    supported: true,
                    tier: 1
                };
            }

            if (major >= 13) {
                return {
                    name: 'macOS',
                    version: cleanVersion(match[1]),
                    family: 'macos',
                    supported: true,
                    tier: 2
                };
            }

            if (major <= 10) {
                // Los navegadores modernos congelan la versión de macOS en
                // 10.15.7; se valida por navegador y se pide actualizar.
                return {
                    name: 'macOS',
                    version: 'versión no determinada',
                    family: 'macos',
                    supported: true,
                    tier: 2
                };
            }

            return {
                name: 'macOS',
                version: cleanVersion(match[1]),
                family: 'macos',
                supported: false,
                tier: 0
            };
        }

        if (/CrOS/i.test(userAgent)) {
            return {
                name: 'ChromeOS',
                version: 'actual',
                family: 'chromeos',
                supported: true,
                tier: 1
            };
        }

        if (/Linux/i.test(userAgent)) {
            return {
                name: 'Linux',
                version: 'distribución no identificada',
                family: 'linux',
                supported: true,
                tier: 2
            };
        }

        return {
            name: 'Sistema no identificado',
            version: '',
            family: 'unknown',
            supported: false,
            tier: 0
        };
    }

    function detectBrowser(userAgent) {
        var match;
        var major;

        match = userAgent.match(/MSIE\s+([\d.]+)/i) || userAgent.match(/Trident\/.*rv:([\d.]+)/i);

        if (match) {
            return {
                name: 'Internet Explorer',
                version: match[1],
                engine: 'Trident/MSHTML',
                family: 'ie',
                supported: false,
                tier: 0
            };
        }

        match = userAgent.match(/Edge\/([\d.]+)/i);

        if (match) {
            return {
                name: 'Microsoft Edge (EdgeHTML)',
                version: match[1],
                engine: 'EdgeHTML',
                family: 'edgehtml',
                supported: false,
                tier: 0
            };
        }

        if (/Presto\//i.test(userAgent)) {
            match = userAgent.match(/Opera\/([\d.]+)/i);

            return {
                name: 'Opera (Presto)',
                version: match ? match[1] : '',
                engine: 'Presto',
                family: 'presto',
                supported: false,
                tier: 0
            };
        }

        match = userAgent.match(/Konqueror\/([\d.]+)/i);

        if (match) {
            return {
                name: 'Konqueror',
                version: match[1],
                engine: 'KHTML',
                family: 'khtml',
                supported: false,
                tier: 0
            };
        }

        match = userAgent.match(/FxiOS\/([\d.]+)/i);

        if (match) {
            return {
                name: 'Firefox para iOS',
                version: match[1],
                engine: 'WebKit',
                family: 'firefox-ios',
                supported: true,
                tier: 2
            };
        }

        match = userAgent.match(/CriOS\/([\d.]+)/i);

        if (match) {
            return {
                name: 'Chrome para iOS',
                version: match[1],
                engine: 'WebKit',
                family: 'chrome-ios',
                supported: true,
                tier: 2
            };
        }

        match = userAgent.match(/Edg\/([\d.]+)/i);

        if (match) {
            major = majorOf(match[1]);

            return {
                name: 'Microsoft Edge',
                version: cleanVersion(match[1]),
                engine: 'Blink',
                family: 'edge',
                supported: major >= 100,
                tier: major >= 120 ? 1 : 2
            };
        }

        match = userAgent.match(/OPR\/([\d.]+)/i);

        if (match) {
            major = majorOf(match[1]);

            return {
                name: 'Opera',
                version: cleanVersion(match[1]),
                engine: 'Blink',
                family: 'opera',
                supported: major >= 86,
                tier: major >= 100 ? 1 : 2
            };
        }

        match = userAgent.match(/SamsungBrowser\/([\d.]+)/i);

        if (match) {
            major = majorOf(match[1]);

            return {
                name: 'Samsung Internet',
                version: cleanVersion(match[1]),
                engine: 'Blink',
                family: 'samsung',
                supported: major >= 20,
                tier: major >= 23 ? 1 : 2
            };
        }

        match = userAgent.match(/Chrome\/([\d.]+)/i);

        if (match) {
            major = majorOf(match[1]);

            return {
                name: /;\s*wv\)/i.test(userAgent) ? 'Android WebView' : 'Chrome',
                version: cleanVersion(match[1]),
                engine: 'Blink',
                family: 'chrome',
                supported: major >= 100,
                tier: major >= 120 ? 1 : 2
            };
        }

        match = userAgent.match(/Firefox\/([\d.]+)/i);

        if (match) {
            major = majorOf(match[1]);

            return {
                name: 'Firefox',
                version: cleanVersion(match[1]),
                engine: 'Gecko',
                family: 'firefox',
                supported: major >= 100,
                tier: major >= 120 ? 1 : 2
            };
        }

        match = userAgent.match(/Version\/([\d.]+).*Safari/i);

        if (match) {
            major = majorOf(match[1]);

            return {
                name: 'Safari',
                version: cleanVersion(match[1]),
                engine: 'WebKit',
                family: 'safari',
                supported: major >= 16,
                tier: major >= 17 ? 1 : 2
            };
        }

        if (/AppleWebKit/i.test(userAgent)) {
            return {
                name: 'Navegador WebKit',
                version: '',
                engine: 'WebKit',
                family: 'webkit',
                supported: false,
                tier: 0
            };
        }

        return {
            name: 'Navegador no identificado',
            version: '',
            engine: 'No identificado',
            family: 'unknown',
            supported: false,
            tier: 0
        };
    }

    function osAdvice(os) {
        if (os.family === 'windows') {
            return 'Windows 11';
        }

        if (os.family === 'macos') {
            return 'macOS 14 Sonoma o superior';
        }

        if (os.family === 'ios') {
            return 'iOS/iPadOS 17 o superior';
        }

        if (os.family === 'android') {
            return 'Android 12 o superior';
        }

        if (os.family === 'linux') {
            return 'una distribución Linux actualizada (Ubuntu 22.04/24.04 LTS o similar)';
        }

        return 'un sistema operativo compatible';
    }

    function buildRecommendation(result) {
        var parts = [];

        if (!result.supported) {
            parts.push('Tu combinación actual no está soportada.');

            if (!result.os.supported) {
                parts.push('Actualiza el sistema operativo a ' + osAdvice(result.os) + '.');
            }

            if (!result.browser.supported) {
                parts.push('Instala la última versión de Chrome, Edge, Firefox o Safari.');
            }

            if (!result.features.ok) {
                parts.push('El navegador no tiene las funciones técnicas necesarias; actualízalo o cambia a uno compatible.');
            }
        } else if (result.tier === 2) {
            parts.push('Puedes usar el sistema, pero esta combinación está en el nivel de soporte con reservas.');

            if (result.os.tier === 2) {
                parts.push('Para mayor soporte, actualiza a ' + osAdvice(result.os) + '.');
            }

            if (result.browser.tier === 2) {
                parts.push('Mantén el navegador actualizado a su última versión.');
            }
        } else {
            parts.push('Tu sistema operativo y navegador son compatibles.');
        }

        if (!result.features.mediaRecorder) {
            parts.push('La grabación de audio puede no estar disponible en este navegador.');
        } else if (!result.features.audioContext) {
            parts.push('La reproducción y grabación de audio pueden tener limitaciones.');
        }

        return parts.join(' ');
    }

    function analyze() {
        var userAgent = navigator.userAgent || '';
        var os = detectOS(userAgent);
        var browser = detectBrowser(userAgent);
        var features = detectFeatures();

        return refreshResult({
            os: os,
            browser: browser,
            features: features
        });
    }

    function refreshResult(result) {
        result.supported = result.os.supported && result.browser.supported && result.features.ok;
        result.reasons = [];

        if (!result.os.supported) {
            result.reasons.push('Sistema operativo no soportado');
        }

        if (!result.browser.supported) {
            result.reasons.push('Navegador no soportado');
        }

        if (!result.features.ok) {
            result.reasons.push('Faltan funciones técnicas requeridas');
        }

        result.tier = result.supported
            ? (result.os.tier === 1 && result.browser.tier === 1 ? 1 : 2)
            : 0;
        result.recommendation = buildRecommendation(result);

        return result;
    }

    function refinePlatformVersion(result, platformVersion) {
        if (!result || !result.os || result.os.family !== 'windows') {
            return result;
        }

        var major = parseInt(String(platformVersion || '0').split('.')[0], 10) || 0;

        if (major >= 13) {
            result.os.name = 'Windows';
            result.os.version = '11';
            result.os.tier = 1;
        } else if (major > 0) {
            result.os.name = 'Windows';
            result.os.version = '10';
            result.os.tier = 2;
        }

        return refreshResult(result);
    }

    window.BrowserSupport = {
        analyze: analyze,
        refinePlatformVersion: refinePlatformVersion,
        detectOS: detectOS,
        detectBrowser: detectBrowser,
        detectFeatures: detectFeatures
    };
})(window);
