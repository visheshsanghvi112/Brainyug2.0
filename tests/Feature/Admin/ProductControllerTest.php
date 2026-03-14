<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\BoxSize;
use App\Models\CompanyMaster;
use App\Models\HsnMaster;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\RackArea;
use App\Models\RackSection;
use App\Models\SaltMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_generates_product_code_and_fast_search_index(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $user = $this->makeAdminUser();
        $support = $this->createSupportRecords();

        $this->createProduct($support, [
            'product_name' => 'Legacy Product',
            'sku' => 'SKU-LEGACY',
            'product_code' => 'PRD-000099',
        ]);

        $response = $this->actingAs($user)
            ->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload($support, [
                'product_name' => 'Paracetamol 650',
                'sku' => 'sku-new-001',
                'barcode' => '1234567891',
                'product_code' => '',
                'fast_search_index' => '',
            ]));

        $response->assertRedirect(route('admin.products.index'));

        $product = Product::query()->where('sku', 'SKU-NEW-001')->first();

        $this->assertNotNull($product);
        $this->assertSame('PRD-000100', $product->product_code);
        $this->assertSame('Paracetamol 650 | SKU-NEW-001 | 1234567891', $product->fast_search_index);
    }

    public function test_store_rejects_rack_area_from_other_section(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $user = $this->makeAdminUser();
        $support = $this->createSupportRecords();

        $otherSection = RackSection::create(['name' => 'Section B', 'status' => true]);
        $otherArea = RackArea::create([
            'rack_section_id' => $otherSection->id,
            'name' => 'B-01',
            'status' => true,
        ]);

        $response = $this->actingAs($user)
            ->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->validPayload($support, [
                'rack_section_id' => $support['rackSection']->id,
                'rack_area_id' => $otherArea->id,
            ]));

        $response->assertRedirect(route('admin.products.create'));
        $response->assertSessionHasErrors(['rack_area_id']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_search_excludes_hidden_banned_and_inactive_products(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $user = $this->makeAdminUser();
        $support = $this->createSupportRecords();

        $visible = $this->createProduct($support, [
            'product_name' => 'Searchable Product',
            'sku' => 'SEARCH-001',
            'barcode' => '111111',
            'product_code' => 'PRD-000201',
            'fast_search_index' => 'Searchable Product | SEARCH-001',
        ]);

        $this->createProduct($support, [
            'product_name' => 'Searchable Hidden Product',
            'sku' => 'SEARCH-002',
            'hide' => true,
            'product_code' => 'PRD-000202',
            'fast_search_index' => 'Searchable Hidden Product | SEARCH-002',
        ]);

        $this->createProduct($support, [
            'product_name' => 'Searchable Banned Product',
            'sku' => 'SEARCH-003',
            'is_banned' => true,
            'product_code' => 'PRD-000203',
            'fast_search_index' => 'Searchable Banned Product | SEARCH-003',
        ]);

        $this->createProduct($support, [
            'product_name' => 'Searchable Inactive Product',
            'sku' => 'SEARCH-004',
            'is_active' => false,
            'product_code' => 'PRD-000204',
            'fast_search_index' => 'Searchable Inactive Product | SEARCH-004',
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('admin.products.search', ['term' => 'Searchable']));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment([
            'id' => $visible->id,
            'product_name' => 'Searchable Product',
            'sku' => 'SEARCH-001',
        ]);
    }

    private function makeAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin']);
        $viewPermission = Permission::firstOrCreate(['name' => 'module.products.view']);
        $createPermission = Permission::firstOrCreate(['name' => 'module.products.create']);
        $role->givePermissionTo([$viewPermission, $createPermission]);

        $user = User::factory()->create([
            'username' => 'admin_' . Str::lower(Str::random(8)),
            'is_active' => true,
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

    private function validPayload(array $support, array $overrides = []): array
    {
        return array_merge([
            'company_id' => $support['company']->id,
            'category_id' => $support['category']->id,
            'salt_id' => $support['salt']->id,
            'hsn_id' => $support['hsn']->id,
            'box_size_id' => $support['boxSize']->id,
            'product_name' => 'Default Product',
            'sku' => 'DEFAULT-001',
            'barcode' => '1234567890',
            'product_code' => 'PRD-000010',
            'product_type' => 'Normal',
            'mrp' => 100,
            'ptr' => 80,
            'pts' => 75,
            'cost' => 60,
            'conversion_factor' => 1,
            'rack_section_id' => $support['rackSection']->id,
            'rack_area_id' => $support['rackArea']->id,
            'is_active' => true,
            'hide' => false,
            'is_banned' => false,
        ], $overrides);
    }

    private function createProduct(array $support, array $overrides = []): Product
    {
        return Product::create($this->validPayload($support, $overrides));
    }
}
