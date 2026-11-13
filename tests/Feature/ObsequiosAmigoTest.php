<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\ConsumableGift;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ExchangeGift;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Obsequios de «Ver tu amigo»: regalo único y consumibles del participante,
 * con conversión a bolívares a la tasa oficial del BCV.
 *
 * Las acciones solo se habilitan cuando el sorteo concluyó y el evento avanzó
 * a la etapa de obsequios (estado «sorteado»).
 */
class ObsequiosAmigoTest extends TestCase
{
    use RefreshDatabase;

    private Module $module;

    private Event $event;

    private User $ana;

    private User $beto;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => null,
                'venta' => null,
                'promedio' => 911.62,
                'fechaActualizacion' => '2026-11-13T00:00:00-04:00',
            ]),
        ]);

        $profile = Profile::query()->create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        $this->module = Module::create([
            'name' => 'Ver tu amigo',
            'slug' => 'ver-tu-amigo',
            'group' => 'Amigo secreto',
            'icon' => 'fa-user-secret',
            'order' => 70,
            'is_active' => true,
        ]);

        $permission = Permission::create(['name' => 'Ver', 'slug' => 'view']);

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'module_id' => $this->module->id,
        ]);

        $this->ana = User::factory()->create([
            'name' => 'Ana Pérez',
            'username' => 'ana',
            'email' => 'ana@example.com',
            'profile_id' => $profile->id,
        ]);

        $this->beto = User::factory()->create([
            'name' => 'Beto Ruiz',
            'username' => 'beto',
            'email' => 'beto@example.com',
            'profile_id' => $profile->id,
        ]);

        $this->event = Event::create([
            'name' => 'Amigo Secreto 2026',
            'year' => 2026,
            'status' => 'drawn',
            'exchange_at' => now()->addDays(37),
        ]);

        EventParticipant::create([
            'event_id' => $this->event->id,
            'user_id' => $this->ana->id,
            'status' => 'active',
            'confirmed_at' => now(),
        ]);

        EventParticipant::create([
            'event_id' => $this->event->id,
            'user_id' => $this->beto->id,
            'status' => 'active',
            'confirmed_at' => now(),
        ]);

        $this->assignment = Assignment::create([
            'event_id' => $this->event->id,
            'giver_id' => $this->ana->id,
            'receiver_id' => $this->beto->id,
            'drawn_at' => now(),
        ]);
    }

    private function pagina(): string
    {
        return parse_url($this->module->url(), PHP_URL_PATH);
    }

    private function dataTable(array $extra = []): string
    {
        return http_build_query(array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'desc']],
        ], $extra));
    }

    public function test_la_pagina_muestra_la_seccion_del_obsequio_y_los_consumibles(): void
    {
        $this->actingAs($this->ana)
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('Obsequio para tu amigo secreto')
            ->assertSee('Obsequios consumibles')
            ->assertSee('Nuevo consumible')
            ->assertSee('Valor en Bs. (tasa BCV)')
            ->assertSee('amigo-consumibles-table', false)
            ->assertSee('ver-tu-amigo.js', false);
    }

    public function test_las_secciones_no_se_activan_antes_de_la_etapa_de_obsequios(): void
    {
        $this->event->update(['status' => 'draw_enabled']);

        $this->actingAs($this->ana)
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('Tu amigo secreto')
            ->assertDontSee('Obsequio para tu amigo secreto')
            ->assertSee('Los obsequios se habilitan cuando el sorteo concluya');
    }

    public function test_en_la_union_tampoco_aparecen_las_secciones(): void
    {
        $this->assignment->delete();
        $this->event->update(['status' => 'registration']);

        $this->actingAs($this->ana)
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('El evento aún no ha comenzado')
            ->assertDontSee('Obsequio para tu amigo secreto')
            ->assertDontSee('Nuevo consumible');

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', ['nombre' => 'Algo', 'valor' => 10])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Todavía no tienes un amigo secreto asignado.');
    }

    public function test_la_tasa_convierte_el_valor_a_bolivares(): void
    {
        $this->actingAs($this->ana)
            ->getJson('/amigo-secreto/ver-tu-amigo/tasa?valor=10')
            ->assertOk()
            ->assertJsonPath('disponible', true)
            ->assertJsonPath('tasa_formateada', '911,62')
            ->assertJsonPath('valor_usd_formateado', 'US$ 10,00')
            ->assertJsonPath('valor_bs_formateado', 'Bs. 9.116,20');
    }

    public function test_registra_y_modifica_el_obsequio_una_sola_vez(): void
    {
        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', [
                'nombre' => 'Libro de aventuras',
                'valor' => '25,50',
            ])
            ->assertOk()
            ->assertJsonPath('gift.nombre', 'Libro de aventuras')
            ->assertJsonPath('gift.valor_usd', 'US$ 25,50')
            ->assertJsonPath('gift.valor_bs', 'Bs. 23.246,31');

        $this->assertDatabaseHas('exchange_gifts', [
            'assignment_id' => $this->assignment->id,
            'product' => 'Libro de aventuras',
            'value' => 25.50,
        ]);

        $gift = ExchangeGift::query()->where('assignment_id', $this->assignment->id)->firstOrFail();

        // Un segundo registro por la misma vía no se permite.
        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', [
                'nombre' => 'Otro',
                'valor' => 10,
            ])
            ->assertStatus(409);

        $this->actingAs($this->ana)
            ->putJson('/amigo-secreto/ver-tu-amigo/obsequio', [
                'nombre' => 'Libro de aventuras (edición especial)',
                'valor' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('gift.nombre', 'Libro de aventuras (edición especial)')
            ->assertJsonPath('gift.valor_usd', 'US$ 30,00');

        $gift->refresh();

        $this->assertSame('Libro de aventuras (edición especial)', $gift->product);
        $this->assertSame('30.00', $gift->value);

        // La página ya lo muestra registrado y en solo lectura.
        $this->actingAs($this->ana)
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('Libro de aventuras (edición especial)')
            ->assertSee('Registrado el')
            ->assertSee('disabled', false);
    }

    public function test_el_obsequio_valida_campos_y_respeta_los_topes_del_evento(): void
    {
        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', ['valor' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nombre']);

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', ['nombre' => 'Algo', 'valor' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valor']);

        $this->event->update(['max_value' => 100]);

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', ['nombre' => 'Algo', 'valor' => 150])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El valor del obsequio no puede superar US$ 100,00.');

        $this->assertDatabaseCount('exchange_gifts', 0);
    }

    public function test_las_acciones_de_obsequios_exigen_la_etapa_y_el_amigo_asignado(): void
    {
        $this->event->update(['status' => 'draw_enabled']);

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', ['nombre' => 'Algo', 'valor' => 10])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Los obsequios se habilitan cuando concluya el sorteo.');

        $this->event->update(['status' => 'drawn']);
        $this->assignment->update(['drawn_at' => null]);

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', ['nombre' => 'Algo', 'valor' => 10])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Primero descubre a tu amigo secreto.');
    }

    public function test_consumibles_se_paginan_y_permiten_crear_modificar_ver_y_eliminar(): void
    {
        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/consumibles', ['nombre' => 'Chocolate', 'valor' => 5])
            ->assertOk()
            ->assertJsonPath('consumible.nombre', 'Chocolate')
            ->assertJsonPath('consumible.valor_usd', 'US$ 5,00');

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/consumibles', ['nombre' => 'Café', 'valor' => 7.5])
            ->assertOk();

        $this->actingAs($this->ana)
            ->getJson('/amigo-secreto/ver-tu-amigo/consumibles/datos?'.$this->dataTable())
            ->assertOk()
            ->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('recordsFiltered', 2)
            ->assertJsonPath('puede_editar', true);

        $chocolate = ConsumableGift::query()->where('description', 'Chocolate')->firstOrFail();

        $this->actingAs($this->ana)
            ->getJson('/amigo-secreto/ver-tu-amigo/consumibles/'.$chocolate->id)
            ->assertOk()
            ->assertJsonPath('consumible.nombre', 'Chocolate')
            ->assertJsonPath('consumible.valor_bs', 'Bs. 4.558,10');

        $json = $this->actingAs($this->ana)
            ->getJson('/amigo-secreto/ver-tu-amigo/consumibles/'.$chocolate->id)
            ->json();

        $this->assertStringContainsString('Chocolate', $json['html']);
        $this->assertStringContainsString('US$ 5,00', $json['html']);
        $this->assertStringContainsString('Bs. 4.558,10', $json['html']);

        $this->actingAs($this->ana)
            ->putJson('/amigo-secreto/ver-tu-amigo/consumibles/'.$chocolate->id, [
                'nombre' => 'Chocolate blanco',
                'valor' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('consumible.nombre', 'Chocolate blanco');

        $this->actingAs($this->ana)
            ->deleteJson('/amigo-secreto/ver-tu-amigo/consumibles/'.$chocolate->id)
            ->assertOk();

        $this->assertDatabaseMissing('consumable_gifts', ['id' => $chocolate->id]);
    }

    public function test_los_consumibles_respetan_el_tope_del_evento(): void
    {
        $this->event->update(['consumable_value_cap' => 10]);

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/consumibles', ['nombre' => 'Chocolate', 'valor' => 6])
            ->assertOk();

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/consumibles', ['nombre' => 'Café', 'valor' => 5])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El tope de consumibles del evento es US$ 10,00. Te quedan US$ 4,00 disponibles.');

        $this->assertDatabaseCount('consumable_gifts', 1);
    }

    public function test_no_puede_modificar_ni_eliminar_consumibles_de_otro_participante(): void
    {
        $otra = Assignment::create([
            'event_id' => $this->event->id,
            'giver_id' => $this->beto->id,
            'receiver_id' => $this->ana->id,
            'drawn_at' => now(),
        ]);

        $ajeno = ConsumableGift::create([
            'assignment_id' => $otra->id,
            'description' => 'Ajeno',
            'quantity' => 1,
            'value' => 3,
            'given_on' => now()->toDateString(),
        ]);

        $this->actingAs($this->ana)
            ->putJson('/amigo-secreto/ver-tu-amigo/consumibles/'.$ajeno->id, [
                'nombre' => 'Cambiado',
                'valor' => 4,
            ])
            ->assertNotFound();

        $this->actingAs($this->ana)
            ->deleteJson('/amigo-secreto/ver-tu-amigo/consumibles/'.$ajeno->id)
            ->assertNotFound();

        $this->assertDatabaseHas('consumable_gifts', ['id' => $ajeno->id, 'description' => 'Ajeno']);
    }

    public function test_cuando_el_evento_termina_los_obsequios_quedan_en_solo_lectura(): void
    {
        $this->event->update(['status' => 'closed']);

        $this->actingAs($this->ana)
            ->get($this->pagina())
            ->assertOk()
            ->assertSee('El evento ya finalizó: el obsequio y los consumibles quedan en solo lectura.')
            ->assertSee('Obsequios consumibles');

        $this->actingAs($this->ana)
            ->postJson('/amigo-secreto/ver-tu-amigo/obsequio', ['nombre' => 'Algo', 'valor' => 10])
            ->assertStatus(409)
            ->assertJsonPath('message', 'El evento ya finalizó: los obsequios quedan en solo lectura.');
    }

    public function test_las_consultas_exigen_el_modulo_de_ver_tu_amigo(): void
    {
        $otro = User::factory()->create();

        $this->actingAs($otro)
            ->get($this->pagina())
            ->assertForbidden();

        $this->actingAs($otro)
            ->getJson('/amigo-secreto/ver-tu-amigo/tasa?valor=10')
            ->assertForbidden();
    }
}
