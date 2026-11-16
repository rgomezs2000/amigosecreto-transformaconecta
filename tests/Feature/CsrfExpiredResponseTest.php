<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * Un token CSRF vencido debe llegar al navegador como un aviso entendible y no
 * como la traza de la excepción («CSRF token mismatch»).
 */
class CsrfExpiredResponseTest extends TestCase
{
    public function test_un_token_vencido_responde_un_aviso_entendible(): void
    {
        $request = Request::create('/login', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['csrf_expired']);
        $this->assertStringNotContainsString('CSRF token mismatch', (string) $response->getContent());
    }

    public function test_una_peticion_normal_conserva_la_pagina_419(): void
    {
        $request = Request::create('/login', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertSame(419, $response->getStatusCode());
    }
}
