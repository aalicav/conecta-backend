<?php

namespace Tests\Feature\Api;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemSettingControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Configurações de permissões
        Permission::create(['name' => 'view settings']);
        Permission::create(['name' => 'edit settings']);

        // Criar e configurar roles
        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo(['view settings', 'edit settings']);

        $userRole = Role::create(['name' => 'user']);

        // Criar usuários para teste
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin');

        $this->regularUser = User::factory()->create();
        $this->regularUser->assignRole('user');

        // Criar algumas configurações de sistema para teste
        SystemSetting::create([
            'key' => 'test_setting',
            'value' => 'test_value',
            'group' => 'test_group',
            'description' => 'A test setting',
            'is_public' => true,
            'data_type' => 'string',
        ]);

        SystemSetting::create([
            'key' => 'private_setting',
            'value' => 'private_value',
            'group' => 'test_group',
            'description' => 'A private setting',
            'is_public' => false,
            'data_type' => 'string',
        ]);

        SystemSetting::create([
            'key' => 'boolean_setting',
            'value' => 'true',
            'group' => 'test_group',
            'description' => 'A boolean setting',
            'is_public' => true,
            'data_type' => 'boolean',
        ]);
    }

    /** @test */
    public function it_returns_settings_based_on_user_permissions()
    {
        // Usuário admin pode ver todas as configurações
        Sanctum::actingAs($this->adminUser);
        $response = $this->getJson('/api/system-settings');
        $response->assertOk();
        $response->assertJsonCount(3, 'settings');

        // Usuário regular só pode ver configurações públicas
        Sanctum::actingAs($this->regularUser);
        $response = $this->getJson('/api/system-settings');
        $response->assertOk();
        $response->assertJsonCount(2, 'settings');
    }

    /** @test */
    public function it_returns_settings_by_group()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->getJson('/api/system-settings/group/test_group');
        $response->assertOk();
        $response->assertJsonCount(3, 'settings');
        $response->assertJsonPath('group', 'test_group');
    }

    /** @test */
    public function it_returns_a_single_setting_by_key()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->getJson('/api/system-settings/test_setting');
        $response->assertOk();
        $response->assertJsonPath('setting.key', 'test_setting');
        $response->assertJsonPath('setting.value', 'test_value');
    }

    /** @test */
    public function it_prevents_access_to_private_settings_for_regular_users()
    {
        Sanctum::actingAs($this->regularUser);
        $response = $this->getJson('/api/system-settings/private_setting');
        $response->assertStatus(403);
    }

    /** @test */
    public function it_allows_admin_to_create_a_new_setting()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->postJson('/api/system-settings/create', [
            'key' => 'new_setting',
            'value' => 'new_value',
            'group' => 'new_group',
            'description' => 'A new setting',
            'is_public' => true,
            'data_type' => 'string',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('setting.key', 'new_setting');
        $this->assertDatabaseHas('system_settings', ['key' => 'new_setting']);
    }

    /** @test */
    public function it_prevents_regular_users_from_creating_settings()
    {
        Sanctum::actingAs($this->regularUser);
        $response = $this->postJson('/api/system-settings/create', [
            'key' => 'new_setting',
            'value' => 'new_value',
            'group' => 'new_group',
            'description' => 'A new setting',
            'is_public' => true,
            'data_type' => 'string',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('system_settings', ['key' => 'new_setting']);
    }

    /** @test */
    public function it_allows_admin_to_update_a_setting()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->putJson('/api/system-settings/test_setting', [
            'value' => 'updated_value',
        ]);

        $response->assertOk();
        $response->assertJsonPath('setting.value', 'updated_value');
        $this->assertDatabaseHas('system_settings', [
            'key' => 'test_setting',
            'value' => 'updated_value',
        ]);
    }

    /** @test */
    public function it_properly_handles_boolean_settings()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->getJson('/api/system-settings/boolean_setting');
        $response->assertOk();
        $response->assertJsonPath('setting.value', true);

        $response = $this->putJson('/api/system-settings/boolean_setting', [
            'value' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('setting.value', false);
        $this->assertDatabaseHas('system_settings', [
            'key' => 'boolean_setting',
            'value' => 'false',
        ]);
    }

    /** @test */
    public function it_allows_admin_to_update_multiple_settings_at_once()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->postJson('/api/system-settings', [
            'settings' => [
                'test_setting' => 'bulk_updated_value',
                'boolean_setting' => false,
            ]
        ]);

        $response->assertOk();
        $response->assertJsonPath('updated.0', 'test_setting');
        $response->assertJsonPath('updated.1', 'boolean_setting');
        
        $this->assertDatabaseHas('system_settings', [
            'key' => 'test_setting',
            'value' => 'bulk_updated_value',
        ]);
        
        $this->assertDatabaseHas('system_settings', [
            'key' => 'boolean_setting',
            'value' => 'false',
        ]);
    }

    /** @test */
    public function it_allows_admin_to_delete_a_setting()
    {
        Sanctum::actingAs($this->adminUser);
        $response = $this->deleteJson('/api/system-settings/test_setting');
        $response->assertOk();
        $this->assertDatabaseMissing('system_settings', ['key' => 'test_setting']);
    }

    /** @test */
    public function it_prevents_deletion_of_critical_settings()
    {
        // Criar uma configuração crítica do sistema
        SystemSetting::create([
            'key' => 'scheduling_enabled',
            'value' => 'true',
            'group' => 'scheduling',
            'description' => 'Enable or disable automatic scheduling',
            'is_public' => true,
            'data_type' => 'boolean',
        ]);

        Sanctum::actingAs($this->adminUser);
        $response = $this->deleteJson('/api/system-settings/scheduling_enabled');
        $response->assertStatus(403);
        $this->assertDatabaseHas('system_settings', ['key' => 'scheduling_enabled']);
    }

    /** @test */
    public function it_validates_data_when_creating_settings()
    {
        Sanctum::actingAs($this->adminUser);
        
        // Teste sem campo obrigatório
        $response = $this->postJson('/api/system-settings/create', [
            'key' => 'invalid_setting',
            'group' => 'test_group',
            // Faltando value
        ]);
        $response->assertStatus(422);
        
        // Teste com tipo de dados inválido
        $response = $this->postJson('/api/system-settings/create', [
            'key' => 'invalid_setting',
            'value' => 'test',
            'group' => 'test_group',
            'data_type' => 'invalid_type', // Tipo inválido
        ]);
        $response->assertStatus(422);
    }
} 