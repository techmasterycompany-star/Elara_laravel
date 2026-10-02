<?php
// tests/Feature/Localization/LocalizationTest.php

namespace Tests\Feature\Localization;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function productPayload(): array
    {
        return [
            'category_id' => Category::factory()->create()->id,
            'name'        => 'Test Product',
            'price'       => 100,
            'stock'       => 10,
            'sku'         => 'TEST-SKU-001',
        ];
    }

    public function test_returns_arabic_message_when_accept_language_is_ar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['Accept-Language' => 'ar'])
            ->postJson('/api/products', $this->productPayload());

        $response->assertStatus(201)
            ->assertJson(['message' => 'تم إنشاء المنتج بنجاح.'])
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_returns_english_message_by_default(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/products', $this->productPayload());

        $response->assertStatus(201)
            ->assertJson(['message' => 'Product created successfully.'])
            ->assertHeader('Content-Language', 'en');
    }

    public function test_lang_query_param_overrides_accept_language_header(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/api/products?lang=ar', $this->productPayload());

        $response->assertHeader('Content-Language', 'ar');
    }
}