<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SessionSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_eliminan_todas_las_sesiones_anteriores_de_la_misma_cuenta(): void
    {
        $user = User::factory()->create();
        $now = now()->timestamp;
        $currentId = str_repeat('c', 40);

        DB::table('sessions')->insert([
            [
                'id' => 'sesion-otra-ip',
                'user_id' => $user->id,
                'ip_address' => '1.1.1.1',
                'user_agent' => 'Prueba',
                'payload' => '',
                'last_activity' => $now,
            ],
            [
                'id' => 'sesion-misma-ip',
                'user_id' => $user->id,
                'ip_address' => '2.2.2.2',
                'user_agent' => 'Prueba',
                'payload' => '',
                'last_activity' => $now,
            ],
            [
                'id' => $currentId,
                'user_id' => $user->id,
                'ip_address' => '2.2.2.2',
                'user_agent' => 'Prueba',
                'payload' => '',
                'last_activity' => $now,
            ],
        ]);

        config(['session.driver' => 'database']);

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '2.2.2.2']);
        $request->setLaravelSession(new Store('prueba', new ArraySessionHandler(120), $currentId));

        $deleted = SessionSecurity::kickOtherSessions($user, $request);

        $this->assertSame(2, $deleted);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-otra-ip']);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-misma-ip']);
        $this->assertDatabaseHas('sessions', ['id' => $currentId]);
    }
}
