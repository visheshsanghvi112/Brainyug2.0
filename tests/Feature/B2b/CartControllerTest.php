<?php

namespace Tests\Feature\B2b;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\B2bCart;
use App\Models\B2bCartItem;
use App\Models\BoxSize;
use App\Models\CompanyMaster;
use App\Models\DistOrder;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);
    }

    public function test_cart_index_reprices_visible_items_and_removes_hidden_ones(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $visibleProduct = $this->createProduct($support, [
            'product_name' => 'Visible Cart Product',
            'sku' => 'CART-001',
            'rate_a' => 91,
        ]);

        $hiddenProduct = $this->createProduct($support, [
            'product_name' => 'Hidden Cart Product',
            'sku' => 'CART-002',
            'hide' => true,
        ]);

        $cart = B2bCart::create([
            'franchisee_id' => $user->franchisee_id,
            'user_id' => $user->id,
            'subtotal' => 0,
            'total_amount' => 0,
        ]);

        $visibleItem = B2bCartItem::create([
            'b2b_cart_id' => $cart->id,
            'product_id' => $visibleProduct->id,
            'qty' => 2,
            'free_qty' => 0,
            'rate' => 70,
            'total_amount' => 140,
        ]);

        $hiddenItem = B2bCartItem::create([
            'b2b_cart_id' => $cart->id,
            'product_id' => $hiddenProduct->id,
            'qty' => 1,
            'free_qty' => 0,
            'rate' => 60,
            'total_amount' => 60,
        ]);

        $response = $this->actingAs($user)->get(route('b2b.cart.index'));

        $response->assertOk();
        $this->assertDatabaseMissing('b2b_cart_items', ['id' => $hiddenItem->id]);
        $this->assertDatabaseHas('b2b_cart_items', [
            'id' => $visibleItem->id,
            'rate' => '91.00',
            'total_amount' => '182.00',
        ]);
        $this->assertDatabaseHas('b2b_carts', [
            'id' => $cart->id,
            'subtotal' => '182.00',
            'total_amount' => '182.00',
        ]);
    }

    public function test_add_to_cart_recomputes_free_qty_after_increment(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $product = $this->createProduct($support, [
            'product_name' => 'Threshold Product',
            'sku' => 'CART-THRESHOLD',
            'rate_a' => 90,
        ]);

        $cart = B2bCart::create([
            'franchisee_id' => $user->franchisee_id,
            'user_id' => $user->id,
            'subtotal' => 810,
            'total_amount' => 810,
        ]);

        $item = B2bCartItem::create([
            'b2b_cart_id' => $cart->id,
            'product_id' => $product->id,
            'qty' => 9,
            'free_qty' => 0,
            'rate' => 90,
            'total_amount' => 810,
        ]);

        $this->actingAs($user)
            ->post(route('b2b.cart.add'), [
                'product_id' => $product->id,
                'qty' => 2,
            ])
            ->assertRedirect();

        $item->refresh();
        $cart->refresh();

        $this->assertSame(11.0, (float) $item->qty);
        $this->assertSame(1.0, (float) $item->free_qty);
        $this->assertSame(990.0, (float) $item->total_amount);
        $this->assertSame(990.0, (float) $cart->subtotal);
    }

    public function test_checkout_recomputes_order_totals_from_current_product_tax_and_rate(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $product = $this->createProduct($support, [
            'product_name' => 'Checkout Product',
            'sku' => 'CART-CHECKOUT',
            'rate_a' => 100,
            'sgst' => 0,
            'cgst' => 0,
            'igst' => 0,
        ]);

        $cart = B2bCart::create([
            'franchisee_id' => $user->franchisee_id,
            'user_id' => $user->id,
            'subtotal' => 1,
            'total_amount' => 1,
        ]);

        B2bCartItem::create([
            'b2b_cart_id' => $cart->id,
            'product_id' => $product->id,
            'qty' => 100,
            'free_qty' => 0,
            'rate' => 10,
            'total_amount' => 1000,
        ]);

        $this->actingAs($user)
            ->post(route('b2b.cart.checkout'))
            ->assertRedirect(route('admin.dist-orders.index'));

        $order = DistOrder::query()->where('user_id', $user->id)->firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertSame(10000.0, (float) $order->subtotal);
        $this->assertSame(600.0, (float) $order->sgst_amount);
        $this->assertSame(600.0, (float) $order->cgst_amount);
        $this->assertSame(11200.0, (float) $order->total_amount);

        $this->assertSame(100.0, (float) $item->rate);
        $this->assertSame(12.0, (float) $item->gst_percent);
        $this->assertSame(1200.0, (float) $item->gst_amount);
        $this->assertSame(11200.0, (float) $item->total_amount);

        $cart->refresh();
        $this->assertSame(0.0, (float) $cart->subtotal);
        $this->assertSame(0, $cart->items()->count());
    }

    public function test_update_qty_reprices_cart_line_using_current_franchise_rate(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $product = $this->createProduct($support, [
            'product_name' => 'Mutable Rate Product',
            'sku' => 'CART-UPDATE-001',
            'rate_a' => 120,
        ]);

        $cart = B2bCart::create([
            'franchisee_id' => $user->franchisee_id,
            'user_id' => $user->id,
            'subtotal' => 160,
            'total_amount' => 160,
        ]);

        $item = B2bCartItem::create([
            'b2b_cart_id' => $cart->id,
            'product_id' => $product->id,
            'qty' => 2,
            'free_qty' => 0,
            'rate' => 80,
            'remark' => 'old',
            'total_amount' => 160,
        ]);

        $this->actingAs($user)
            ->patch(route('b2b.cart.updateQty', $item->id), [
                'qty' => 11,
                'remark' => 'updated',
            ])
            ->assertRedirect();

        $item->refresh();
        $cart->refresh();

        $this->assertSame(11.0, (float) $item->qty);
        $this->assertSame(1.0, (float) $item->free_qty);
        $this->assertSame(120.0, (float) $item->rate);
        $this->assertSame('updated', $item->remark);
        $this->assertSame(1320.0, (float) $item->total_amount);
        $this->assertSame(1320.0, (float) $cart->total_amount);
    }

    public function test_update_qty_removes_line_when_product_is_no_longer_visible_for_franchise(): void
    {
        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $product = $this->createProduct($support, [
            'product_name' => 'Hidden On Update Product',
            'sku' => 'CART-UPDATE-002',
            'rate_a' => 90,
            'hide' => true,
        ]);

        $cart = B2bCart::create([
            'franchisee_id' => $user->franchisee_id,
            'user_id' => $user->id,
            'subtotal' => 180,
            'total_amount' => 180,
        ]);

        $item = B2bCartItem::create([
            'b2b_cart_id' => $cart->id,
            'product_id' => $product->id,
            'qty' => 2,
            'free_qty' => 0,
            'rate' => 90,
            'total_amount' => 180,
        ]);

        $this->actingAs($user)
            ->from(route('b2b.cart.index'))
            ->patch(route('b2b.cart.updateQty', $item->id), [
                'qty' => 3,
            ])
            ->assertRedirect(route('b2b.cart.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('b2b_cart_items', ['id' => $item->id]);
        $cart->refresh();
        $this->assertSame(0.0, (float) $cart->total_amount);
    }

    private function makeFranchiseUser(): User
    {
        $permissions = [
            'module.b2b_cart.view',
            'module.b2b_cart.create',
            'module.b2b_cart.update',
            'module.b2b_cart.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $role = Role::create(['name' => 'Franchisee']);
        $role->givePermissionTo($permissions);

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
