<?php

namespace Tests\Unit\Services;

use App\Models\SystemSetting;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Limpar o cache antes de cada teste
        Cache::flush();
        
        // Criar algumas configurações de teste
        SystemSetting::create([
            'key' => 'test_string',
            'value' => 'Hello World',
            'group' => 'test',
            'description' => 'Test string setting',
            'is_public' => true,
            'data_type' => 'string',
        ]);

        SystemSetting::create([
            'key' => 'test_boolean',
            'value' => 'true',
            'group' => 'test',
            'description' => 'Test boolean setting',
            'is_public' => true,
            'data_type' => 'boolean',
        ]);

        SystemSetting::create([
            'key' => 'test_integer',
            'value' => '123',
            'group' => 'test',
            'description' => 'Test integer setting',
            'is_public' => true,
            'data_type' => 'integer',
        ]);

        SystemSetting::create([
            'key' => 'test_array',
            'value' => '["one", "two", "three"]',
            'group' => 'test',
            'description' => 'Test array setting',
            'is_public' => true,
            'data_type' => 'array',
        ]);
    }

    /** @test */
    public function it_can_get_a_string_setting()
    {
        $value = Settings::get('test_string');
        $this->assertEquals('Hello World', $value);
    }

    /** @test */
    public function it_can_get_a_boolean_setting()
    {
        $value = Settings::getBool('test_boolean');
        $this->assertTrue($value);
    }

    /** @test */
    public function it_can_get_an_integer_setting()
    {
        $value = Settings::getInt('test_integer');
        $this->assertEquals(123, $value);
        $this->assertIsInt($value);
    }

    /** @test */
    public function it_can_get_an_array_setting()
    {
        $value = Settings::getArray('test_array');
        $this->assertEquals(['one', 'two', 'three'], $value);
        $this->assertIsArray($value);
    }

    /** @test */
    public function it_returns_default_value_when_setting_does_not_exist()
    {
        $value = Settings::get('non_existent', 'default_value');
        $this->assertEquals('default_value', $value);
    }

    /** @test */
    public function it_can_check_if_a_setting_exists()
    {
        $this->assertTrue(Settings::has('test_string'));
        $this->assertFalse(Settings::has('non_existent'));
    }

    /** @test */
    public function it_can_set_a_setting()
    {
        $result = Settings::set('test_string', 'Updated Value');
        $this->assertTrue($result);
        
        $value = Settings::get('test_string');
        $this->assertEquals('Updated Value', $value);
    }

    /** @test */
    public function it_can_get_settings_by_group()
    {
        $settings = Settings::getGroup('test');
        
        $this->assertIsArray($settings);
        $this->assertCount(4, $settings);
        $this->assertEquals('Hello World', $settings['test_string']);
        $this->assertTrue($settings['test_boolean']);
        $this->assertEquals(123, $settings['test_integer']);
        $this->assertEquals(['one', 'two', 'three'], $settings['test_array']);
    }

    /** @test */
    public function it_uses_cache_when_retrieving_settings()
    {
        // Primeira chamada busca do banco e armazena em cache
        $value1 = Settings::get('test_string');
        
        // Modificar valor diretamente no banco
        SystemSetting::where('key', 'test_string')->update(['value' => 'Modified Value']);
        
        // Segunda chamada deve retornar valor em cache
        $value2 = Settings::get('test_string');
        
        $this->assertEquals('Hello World', $value1);
        $this->assertEquals('Hello World', $value2); // Ainda retorna o valor antigo do cache
        
        // Limpar cache e verificar novamente
        Cache::flush();
        $value3 = Settings::get('test_string');
        
        $this->assertEquals('Modified Value', $value3); // Agora retorna o valor atualizado
    }
} 