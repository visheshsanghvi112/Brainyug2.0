<?php

namespace Tests\Feature\Api;

use App\Models\BoxSize;
use App\Models\CompanyMaster;
use App\Models\Franchisee;
use App\Models\HsnMaster;
use App\Models\InventoryLedger;
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

class CatalogAndStockVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_pagination_returns_only_franchise_visible_products_with_consistent_rate_payload(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $visible = $this->createProduct($support, [
            'product_name' => 'Visible Product',
            'sku' => 'VISIBLE-001',
            'barcode' => '1111111111',
            'rate_a' => null,
            'ptr' => 82,
            'pts' => 79,
            'mrp' => 100,
        ]);

        $this->createProduct($support, [
            'product_name' => 'Hidden Product',
            'sku' => 'HIDDEN-001',
            'barcode' => '2222222222',
            'hide' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/catalog/products?limit=10');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $visible->id);
        $response->assertJsonPath('data.0.franchise_rate', fn ($value) => (float) $value === 82.0);
        $response->assertJsonPath('data.0.tax.gst_percent', fn ($value) => (float) $value === 12.0);
        $response->assertJsonMissing(['sku' => 'HIDDEN-001']);
        $response->assertJsonCount(1, 'data');
    }

    public function test_franchise_stock_endpoint_excludes_hidden_products(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $visible = $this->createProduct($support, [
            'product_name' => 'Visible Stock Product',
            'sku' => 'STOCK-001',
            'barcode' => '3333333333',
            'rate_a' => 75,
        ]);

        $hidden = $this->createProduct($support, [
            'product_name' => 'Hidden Stock Product',
            'sku' => 'STOCK-002',
            'barcode' => '4444444444',
            'hide' => true,
        ]);

        InventoryLedger::create([
            'product_id' => $visible->id,
            'batch_no' => 'BATCH-A',
            'expiry_date' => now()->addMonths(6),
            'mrp' => 100,
            'location_type' => 'franchisee',
            'location_id' => $user->franchisee_id,
            'transaction_type' => 'RECEIVE',
            'reference_type' => 'seed',
            'reference_id' => 1,
            'qty_in' => 10,
            'qty_out' => 0,
            'rate' => 75,
        ]);

        InventoryLedger::create([
            'product_id' => $hidden->id,
            'batch_no' => 'BATCH-B',
            'expiry_date' => now()->addMonths(6),
            'mrp' => 100,
            'location_type' => 'franchisee',
            'location_id' => $user->franchisee_id,
            'transaction_type' => 'RECEIVE',
            'reference_type' => 'seed',
            'reference_id' => 2,
            'qty_in' => 12,
            'qty_out' => 0,
            'rate' => 70,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/stock/current');

        $response->assertOk();
        $response->assertJsonCount(1, 'stock');
        $response->assertJsonPath('stock.0.product_id', $visible->id);
        $response->assertJsonPath('stock.0.franchise_rate', fn ($value) => (float) $value === 75.0);
        $response->assertJsonMissing(['product_id' => $hidden->id]);
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