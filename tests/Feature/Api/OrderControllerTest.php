<?php

namespace Tests\Feature\Api;

use App\Models\BoxSize;
use App\Models\CompanyMaster;
use App\Models\Franchisee;
use App\Models\HsnMaster;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\RackArea;
use App\Models\RackSection;
use App\Models\SaltMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_b2b_order_uses_current_schema_and_master_franchise_rate(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $product = $this->createProduct($support, [
            'product_name' => 'Rate Fallback Product',
            'sku' => 'ORDER-001',
            'barcode' => '5555555555',
            'rate_a' => null,
            'ptr' => 82,
            'pts' => 79,
            'mrp' => 100,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'items' => [
                ['product_id' => $product->id, 'qty' => 2],
            ],
            'remarks' => 'Urgent replenishment',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('order_number', fn ($value) => is_string($value) && str_starts_with($value, 'ORD-'));

        $this->assertDatabaseHas('dist_orders', [
            'franchisee_id' => $user->franchisee_id,
            'user_id' => $user->id,
            'status' => 'pending',
            'notes' => 'Urgent replenishment',
            'subtotal' => 164.00,
            'sgst_amount' => 9.84,
            'cgst_amount' => 9.84,
            'total_amount' => 183.68,
        ]);

        $this->assertDatabaseHas('dist_order_items', [
            'product_id' => $product->id,
            'request_qty' => 2.00,
            'rate' => 82.00,
            'mrp' => 100.00,
            'gst_percent' => 12.00,
            'taxable_amount' => 164.00,
            'gst_amount' => 19.68,
            'total_amount' => 183.68,
        ]);
    }

    private function makeFranchiseUser(): User
    {
        $role = Role::create(['name' => 'Franchisee']);
        $franchisee = Franchisee::create([
            'shop_name' => 'Test Franchise',
            'owner_name' => 'Owner Name',
            'mobile' => '9999999999',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'username' => 'fr_' . Str::lower(Str::random(8)),
            'is_active' => true,
            'franchisee_id' => $franchisee->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createSupportRecords(): array
    {
        $company = CompanyMaster::create(['name' => 'Acme Pharma']);
        $category = ItemCategory::create(['name' => 'Tablet']);
        $salt = SaltMaster::create(['name' => 'Paracetamol']);
        $boxSize = (new BoxSize())->forceFill(['size_name' => '10x10']);
        $boxSize->save();
        $hsn = HsnMaster::create([
            'hsn_code' => '30049099',
            'cgst_percent' => 6,
            'sgst_percent' => 6,
            'igst_percent' => 12,
        ]);
        $rackSection = RackSection::create(['name' => 'Section A', 'status' => true]);
        $rackArea = RackArea::create([
            'rack_section_id' => $rackSection->id,
            'name' => 'A-01',
            'status' => true,
        ]);

        return compact('company', 'category', 'salt', 'boxSize', 'hsn', 'rackSection', 'rackArea');
    }

    private function createProduct(array $support, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $support['company']->id,
            'category_id' => $support['category']->id,
            'salt_id' => $support['salt']->id,
            'hsn_id' => $support['hsn']->id,
            'box_size_id' => $support['boxSize']->id,
            'product_name' => 'Default Product',
            'sku' => 'DEFAULT-' . Str::upper(Str::random(6)),
            'barcode' => null,
            'product_code' => 'PRD-' . random_int(100000, 999999),
            'product_type' => 'Normal',
            'mrp' => 100,
            'ptr' => 80,
            'pts' => 75,
            'cost' => 60,
            'rate_a' => 78,
            'conversion_factor' => 1,
            'rack_section_id' => $support['rackSection']->id,
            'rack_area_id' => $support['rackArea']->id,
            'is_active' => true,
            'hide' => false,
            'is_banned' => false,
        ], $overrides));
    }
}