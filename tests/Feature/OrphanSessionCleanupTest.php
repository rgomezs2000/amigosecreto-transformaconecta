<?php

namespace Tests\Feature;

use App\Models\SessionKick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrphanSessionCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_elimina_sesiones_huerfanas_y_limpia_la_sesion_activa(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create([
            'active_session_id' => 'sesion-vieja',
        ]);

        $now = now()->timestamp;

        DB::table('sessions')->insert([
            [
                'id' => 'sesion-vieja',
                'user_id' => $user->id,
                'ip_address' => '1.1.1.1',
                'user_agent' => 'Navegador cerrado',
                'payload' => '',
                'last_activity' => $now - (31 * 60),
            ],
            [
                'id' => 'sesion-reciente',
                'user_id' => $user->id,
                'ip_address' => '2.2.2.2',
                'user_agent' => 'Navegador abierto',
                'payload' => '',
                'last_activity' => $now,
            ],
        ]);

        SessionKick::query()->create([
            'session_id' => 'aviso-viejo',
            'user_id' => $user->id,
            'message' => 'Aviso viejo',
            'consumed_at' => now()->subDays(2),
        ]);

        $this->artisan('sessions:cleanup', ['--minutes' => 30])
            ->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-vieja']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-reciente']);
        $this->assertNull($user->fresh()->active_session_id);
        $this->assertDatabaseMissing('session_kicks', ['session_id' => 'aviso-viejo']);
    }
}
