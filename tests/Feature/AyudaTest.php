<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\HelpArticle;
use App\Models\Module;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Centro de ayuda: categoría, módulos, contenido y accesos.
 *
 * El contenido es estático, pero debe salir de la base de datos y respetar el
 * mismo control de acceso que el resto de los módulos.
 */
class AyudaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => null,
                'venta' => null,
                'promedio' => 899.2705,
                'fechaActualizacion' => '2026-11-10T00:00:00-04:00',
            ]),
        ]);
    }

    private function participante(): User
    {
        $this->seed();

        $profile = Profile::query()->where('slug', 'usuario')->firstOrFail();

        return User::factory()->create([
            'name' => 'Ana Pérez',
            'profile_id' => $profile->id,
        ]);
    }

    private function administrador(): User
    {
        $this->seed();

        return User::query()
            ->where('username', config('amigosecreto.admin.username'))
            ->firstOrFail();
    }

    public function test_la_categoria_y_los_modulos_de_ayuda_se_siembran(): void
    {
        $this->seed();

        $categoria = Category::query()->where('slug', 'ayuda')->firstOrFail();

        $this->assertSame('Ayuda', $categoria->name);
        $this->assertSame(8, $categoria->sort_order);

        $this->assertDatabaseHas('modules', [
            'code' => 'acerca-de',
            'slug' => 'acerca-de',
            'category_id' => $categoria->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('modules', [
            'code' => 'ayuda',
            'slug' => 'guia',
            'category_id' => $categoria->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('modules', [
            'code' => 'faq',
            'slug' => 'faq',
            'category_id' => $categoria->id,
            'is_active' => true,
        ]);

        // Contacto y soporte se ve en el menú aunque su pantalla no tenga lógica.
        $this->assertDatabaseHas('modules', [
            'code' => 'contacto-soporte',
            'slug' => 'contacto-soporte',
            'category_id' => $categoria->id,
            'is_active' => true,
        ]);

        // El contenido público y el de administración se distinguen en la base.
        $this->assertDatabaseHas('help_articles', [
            'slug' => 'que-es',
            'audience' => HelpArticle::AUDIENCE_ALL,
        ]);

        $this->assertDatabaseHas('help_articles', [
            'slug' => 'admin-rol',
            'audience' => HelpArticle::AUDIENCE_ADMIN,
        ]);
    }

    public function test_los_accesos_de_ayuda_y_contacto_son_los_esperados(): void
    {
        $this->seed();

        $admin = Profile::query()->where('slug', 'administrador')->firstOrFail();
        $usuario = Profile::query()->where('slug', 'usuario')->firstOrFail();

        $helpModules = Module::query()
            ->whereIn('code', ['acerca-de', 'ayuda', 'faq'])
            ->get();

        $contacto = Module::query()->where('code', 'contacto-soporte')->firstOrFail();

        foreach ([$admin, $usuario] as $profile) {
            foreach ($helpModules as $module) {
                $this->assertDatabaseHas('profile_permission_module', [
                    'profile_id' => $profile->id,
                    'module_id' => $module->id,
                ]);
            }
        }

        // Contacto y soporte es solo para participantes.
        $this->assertDatabaseHas('profile_permission_module', [
            'profile_id' => $usuario->id,
            'module_id' => $contacto->id,
        ]);

        $this->assertDatabaseMissing('profile_permission_module', [
            'profile_id' => $admin->id,
            'module_id' => $contacto->id,
        ]);
    }

    public function test_acerca_de_muestra_la_version_y_el_contenido_guardado(): void
    {
        $user = $this->participante();

        $this->actingAs($user)
            ->get('/ayuda/acerca-de')
            ->assertOk()
            ->assertSee('Versión 0.1')
            ->assertSee('Qué es Amigo Secreto Inclusivo')
            ->assertSee('Decisiones de accesibilidad')
            ->assertSee('Primera versión funcional');
    }

    public function test_la_guia_explica_los_cinco_pasos_de_la_dinamica(): void
    {
        $user = $this->participante();

        $this->actingAs($user)
            ->get('/ayuda/guia')
            ->assertOk()
            ->assertSee('Guía de uso del sistema')
            ->assertSee('Paso 1: Creación y unión')
            ->assertSee('Paso 2: Sorteo ciego')
            ->assertSee('Paso 3: Lista de deseos')
            ->assertSee('Paso 4: Intercambio de pistas')
            ->assertSee('Paso 5: Gran revelación')
            ->assertSee('Han elegido');
    }

    public function test_faq_agrupa_preguntas_y_respuestas_por_tema(): void
    {
        $user = $this->participante();

        $this->actingAs($user)
            ->get('/ayuda/faq')
            ->assertOk()
            ->assertSee('Acceso y cuenta')
            ->assertSee('La dinámica')
            ->assertSee('¿Cómo entro al sistema?')
            ->assertSee('¿Puedo sacarme a mí mismo?')
            ->assertSee('El sistema reparte los nombres de forma que nadie se saque a sí mismo.');
    }

    public function test_el_administrador_ve_las_secciones_de_administracion(): void
    {
        $admin = $this->administrador();

        $this->assertTrue($admin->isAdmin());

        $this->actingAs($admin)
            ->get('/ayuda/acerca-de')
            ->assertOk()
            ->assertSee('Administración del sistema')
            ->assertSee('El rol de administración')
            ->assertSee('Perfiles, módulos y accesos')
            ->assertSee('Solo administración');

        $this->actingAs($admin)
            ->get('/ayuda/guia')
            ->assertOk()
            ->assertSee('Administración de la ayuda')
            ->assertSee('Cómo se organiza el contenido')
            ->assertSee('Habilitar la lógica de Contacto y soporte')
            ->assertSee('Buenas prácticas');

        $this->actingAs($admin)
            ->get('/ayuda/faq')
            ->assertOk()
            ->assertSee('Administración de la ayuda')
            ->assertSee('¿Quién puede ver las secciones de administración?')
            ->assertSee('¿Cómo actualizo el contenido de la ayuda?');
    }

    public function test_el_participante_no_ve_el_bloque_de_administracion(): void
    {
        $user = $this->participante();

        $this->actingAs($user)
            ->get('/ayuda/acerca-de')
            ->assertOk()
            ->assertDontSee('Administración del sistema')
            ->assertDontSee('El rol de administración')
            ->assertDontSee('Solo administración');

        $this->actingAs($user)
            ->get('/ayuda/guia')
            ->assertOk()
            ->assertDontSee('Administración de la ayuda')
            ->assertDontSee('Cómo se organiza el contenido')
            ->assertDontSee('Solo administración');

        $this->actingAs($user)
            ->get('/ayuda/faq')
            ->assertOk()
            ->assertDontSee('¿Quién puede ver las secciones de administración?')
            ->assertDontSee('¿Cómo actualizo el contenido de la ayuda?')
            ->assertDontSee('Solo administración');
    }

    public function test_el_contenido_de_las_pantallas_sale_de_la_base_de_datos(): void
    {
        $user = $this->participante();

        $article = HelpArticle::query()->where('slug', 'que-es')->firstOrFail();
        $article->update([
            'title' => 'Título cambiado en la base',
            'body' => [
                ['type' => 'paragraph', 'text' => 'Este texto se editó directamente en la base de datos.'],
            ],
        ]);

        $this->actingAs($user)
            ->get('/ayuda/acerca-de')
            ->assertOk()
            ->assertSee('Título cambiado en la base')
            ->assertSee('Este texto se editó directamente en la base de datos.');
    }

    public function test_contacto_y_soporte_se_ve_y_abre_el_marcador_de_posicion(): void
    {
        $user = $this->participante();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Contacto y soporte')
            ->assertSee(url('/ayuda/contacto-soporte'), false);

        // Todavía no tiene controlador: muestra el marcador de posición.
        $this->actingAs($user)
            ->get('/ayuda/contacto-soporte')
            ->assertOk()
            ->assertSee('Este módulo está en construcción.');
    }

    public function test_el_administrador_no_ve_ni_entra_a_contacto_soporte(): void
    {
        $admin = $this->administrador();
        $contacto = Module::query()->where('code', 'contacto-soporte')->firstOrFail();

        $this->assertFalse($admin->hasModuleAccess($contacto));

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Contacto y soporte');

        $this->actingAs($admin)
            ->get('/ayuda/contacto-soporte')
            ->assertForbidden();
    }

    public function test_las_pantallas_de_ayuda_no_muestran_volver_al_panel(): void
    {
        $user = $this->participante();

        foreach (['/ayuda/acerca-de', '/ayuda/guia', '/ayuda/faq'] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertDontSee('Volver al panel');
        }
    }

    public function test_el_panel_muestra_el_grupo_ayuda_en_el_menu(): void
    {
        $user = $this->participante();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Acerca de')
            ->assertSee('FAQ')
            ->assertSee('Contacto y soporte')
            ->assertSee(url('/ayuda/guia'), false)
            ->assertSee(url('/ayuda/faq'), false)
            ->assertSee(url('/ayuda/contacto-soporte'), false);
    }

    public function test_sin_acceso_al_modulo_responde_403(): void
    {
        $this->seed();

        $profile = Profile::create([
            'name' => 'Sin ayuda',
            'slug' => 'sin-ayuda',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['profile_id' => $profile->id]);

        $this->assertFalse(
            ProfilePermissionModule::query()->where('profile_id', $profile->id)->exists()
        );

        $this->actingAs($user)
            ->get('/ayuda/faq')
            ->assertForbidden();
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get('/ayuda/faq')->assertRedirect(route('login'));
    }
}
