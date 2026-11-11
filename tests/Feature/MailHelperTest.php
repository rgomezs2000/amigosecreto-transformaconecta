<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailHelperTest extends TestCase
{
    use RefreshDatabase;

    public function test_envia_correo_con_plantilla_accesible(): void
    {
        Mail::fake();

        $sent = send_mail(
            'ana@example.com',
            'Tu cuenta está lista',
            'Tu cuenta está lista',
            ['Tu cuenta fue creada correctamente.', 'Ya puedes entrar al sistema.'],
            ['label' => 'Entrar al sistema', 'url' => 'https://example.com/login'],
            ['recipient_name' => 'Ana', 'eyebrow' => 'Cuenta creada']
        );

        $this->assertTrue($sent);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
            return $mail->hasTo('ana@example.com')
                && $mail->mailSubject === 'Tu cuenta está lista'
                && $mail->mailTitle === 'Tu cuenta está lista';
        });

        $mail = new NotificationMail(
            'Tu cuenta está lista',
            'Tu cuenta está lista',
            ['Tu cuenta fue creada correctamente.'],
            ['label' => 'Entrar al sistema', 'url' => 'https://example.com/login'],
            ['recipient_name' => 'Ana', 'eyebrow' => 'Cuenta creada']
        );

        $html = $mail->render();

        $this->assertStringContainsString('lang="es"', $html);
        $this->assertStringContainsString('Tu cuenta está lista', $html);
        $this->assertStringContainsString('Entrar al sistema', $html);
        $this->assertStringContainsString('#2F6F62', $html);
        $this->assertStringContainsString('max-width: 620px', $html);
        $this->assertStringContainsString('role="presentation"', $html);
    }

    public function test_envia_bienvenida_al_crear_usuario(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
        ]);

        $this->assertTrue($user->sendWelcomeEmail());

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->mailOptions['recipient_name'] === $user->name;
        });
    }

    public function test_sin_destinatarios_no_envia(): void
    {
        Mail::fake();

        $this->assertFalse(send_mail([], 'Asunto', 'Título'));

        Mail::assertNothingSent();
    }
}
