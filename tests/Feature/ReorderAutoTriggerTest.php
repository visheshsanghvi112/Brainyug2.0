<?php

namespace Tests\Feature;

use App\Events\SaleCompleted;
use App\Listeners\TriggerReorderSuggestion;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\Product;
use App\Models\Franchisee;
use App\Models\User;
use App\Models\B2bCart;
use App\Models\InventoryLedger;
use Tests\TestCase;
use Illuminate\Support\Facades\Event;

class ReorderAutoTriggerTest extends TestCase
{
    /**
     * Test that SaleCompleted event fires and listener processes it.
     */
    public function test_sale_completed_event_triggers_reorder_suggestion()
    {
        // Arrange: Create test data
        $franchisee = Franchisee::first() ?? Franchisee::factory()->create();
        $user = User::where('franchisee_id', $franchisee->id)->first() 
            ?? User::factory()->create(['franchisee_id' => $franchisee->id]);
        
        $product = Product::where('reorder_quantity', '>', 0)->first() 
            ?? Product::factory()->create(['reorder_quantity' => 50]);
        
        // Create a low inventory scenario
        InventoryLedger::where('product_id', $product->id)
            ->where('location_type', 'franchisee')
            ->where('location_id', $franchisee->id)
            ->delete();
        
        InventoryLedger::create([
            'product_id' => $product->id,
            'batch_no' => 'TEST001',
            'location_type' => 'franchisee',
            'location_id' => $franchisee->id,
            'transaction_type' => 'OPENING_BALANCE',
            'qty_in' => 10,
            'qty_out' => 0,
            'rate' => 100,
            'reference_id' => 1,
            'created_by' => $user->id,
        ]);
        
        // Create a sales invoice
        $invoice = SalesInvoice::factory()->create([
            'franchisee_id' => $franchisee->id,
            'customer_id' => null,
            'status' => 'completed',
        ]);
        
        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'TEST001',
            'qty' => 5,
            'free_qty' => 0,
            'mrp' => 150,
            'rate' => 100,
            'discount_percent' => 0,
            'gst_percent' => 18,
        ]);
        
        // Act: Dispatch the event
        Event::fake();
        SaleCompleted::dispatch($invoice, $franchisee->id);
        Event::assertDispatched(SaleCompleted::class);
        
        // Actually run the listener
        $listener = new TriggerReorderSuggestion();
        $event = new SaleCompleted($invoice, $franchisee->id);
        $listener->handle($event);
        
        // Assert: Check that a draft cart was created
        $cart = B2bCart::where('franchisee_id', $franchisee->id)
            ->where('status', 'draft')
            ->first();
        
        $this->assertNotNull($cart, 'Draft cart should be created for reorder suggestion');
        $this->assertStringContainsString('Auto-generated', $cart->notes);
        
        $cartItem = $cart->items()->where('product_id', $product->id)->first();
        $this->assertNotNull($cartItem, 'Product should be added to reorder cart');
        $this->assertTrue($cartItem->is_suggestion, 'Cart item should be marked as suggestion');
    }
}
