<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatProfileTest extends TestCase
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

    private function userWithChat(string $name): User
    {
        $profile = Profile::create([
            'name' => 'Perfil '.$name,
            'slug' => 'perfil-'.strtolower($name).'-'.Str::lower(Str::random(5)),
            'is_active' => true,
        ]);

        $module = Module::query()->firstOrCreate(
            ['slug' => 'chat'],
            [
                'name' => 'Chat',
                'group' => 'Mensajes',
                'icon' => 'fa-comments',
                'order' => 10,
                'is_active' => true,
            ]
        );

        $permission = Permission::query()->firstOrCreate(['slug' => 'view'], ['name' => 'Ver']);

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'permission_id' => $permission->id,
            'module_id' => $module->id,
        ]);

        return User::factory()->create([
            'name' => $name,
            'username' => strtolower($name).'-'.Str::lower(Str::random(5)),
            'profile_id' => $profile->id,
            'role' => UserRole::Participant,
            'is_active' => true,
        ]);
    }

    private function directConversation(User $first, User $second): Conversation
    {
        $conversation = Conversation::create(['type' => 'direct']);
        $conversation->users()->attach([$first->id, $second->id]);

        return $conversation;
    }

    private function groupConversation(User $owner, User ...$members): Conversation
    {
        $conversation = Conversation::create([
            'name' => 'Grupo de prueba',
            'type' => 'group',
            'created_by' => $owner->id,
            'is_moderated' => true,
        ]);

        $conversation->users()->attach($owner->id, ['role' => 'moderator']);

        foreach ($members as $member) {
            $conversation->users()->attach($member->id, ['role' => 'member']);
        }

        return $conversation;
    }

    public function test_el_perfil_directo_muestra_identidad_y_opciones(): void
    {
        $ana = $this->userWithChat('Ana');
        $luis = $this->userWithChat('Luis');
        $conversation = $this->directConversation($ana, $luis);

        $response = $this->actingAs($ana)->getJson('/chat/'.$conversation->id.'/perfil');

        $response->assertOk()
            ->assertJsonPath('is_group', false)
            ->assertJsonPath('other.name', 'Luis')
            ->assertJsonPath('other.email', $luis->email)
            ->assertJsonPath('favorite', false)
            ->assertJsonPath('can_block', true)
            ->assertJsonPath('can_report', true);
    }

    public function test_el_perfil_de_grupo_muestra_miembros_y_auditoria_solo_al_dueno(): void
    {
        $owner = $this->userWithChat('Dueno');
        $member = $this->userWithChat('Miembro');
        $outsider = $this->userWithChat('Ajeno');
        $group = $this->groupConversation($owner, $member);

        $this->actingAs($member)
            ->getJson('/chat/'.$group->id.'/perfil')
            ->assertOk()
            ->assertJsonPath('is_group', true)
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('can_leave', true)
            ->assertJsonCount(2, 'members')
            ->assertJsonCount(0, 'audit');

        $this->actingAs($owner)
            ->getJson('/chat/'.$group->id.'/perfil')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('can_delete', true)
            ->assertJsonPath('can_leave', false);

        $this->actingAs($outsider)
            ->getJson('/chat/'.$group->id.'/perfil')
            ->assertForbidden();
    }

    public function test_la_suspension_queda_en_la_auditoria_del_grupo(): void
    {
        $owner = $this->userWithChat('Dueno');
        $member = $this->userWithChat('Miembro');
        $group = $this->groupConversation($owner, $member);

        $this->actingAs($owner)
            ->postJson('/chat/grupo/'.$group->id.'/suspender/'.$member->id, [
                'days' => 2,
                'reason' => 'Incumplimiento de normas',
            ])
            ->assertOk();

        $this->assertDatabaseHas('chat_group_audits', [
            'conversation_id' => $group->id,
            'actor_id' => $owner->id,
            'target_user_id' => $member->id,
            'action' => 'suspension',
            'status' => 'suspendido',
        ]);

        $this->actingAs($owner)
            ->getJson('/chat/'.$group->id.'/perfil')
            ->assertOk()
            ->assertJsonPath('audit.0.label', 'Miembro suspendido')
            ->assertJsonPath('audit.0.actor', 'Dueno')
            ->assertJsonPath('audit.0.target', 'Miembro');
    }
}
