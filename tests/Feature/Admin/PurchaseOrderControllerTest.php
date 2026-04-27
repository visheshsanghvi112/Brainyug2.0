<?php

namespace Tests\Feature\Admin;

use App\Mail\PurchaseOrderSentMail;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_sent_mail_uses_expected_subject_and_view(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'name' => 'Mail Supplier',
            'email' => 'supplier@example.com',
            'is_active' => true,
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'order_number' => 'PO-2025-26-000001',
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'financial_year' => '2025-26',
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
        ]);

        $mailable = new PurchaseOrderSentMail($purchaseOrder);
        $envelope = $mailable->envelope();
        $content = $mailable->content();

        $this->assertSame('Purchase Order PO-2025-26-000001', $envelope->subject);
        $this->assertSame('emails.purchase_orders.sent', $content->view);

        $rendered = $mailable->render();
        $this->assertStringContainsString('PO-2025-26-000001', $rendered);
        $this->assertStringContainsString('Mail Supplier', $rendered);
        $this->assertStringContainsString('Purchase Order', $rendered);
    }

    public function test_purchase_order_sent_mail_loads_supplier_and_items_relationships(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::create([
            'name' => 'Relationship Supplier',
            'email' => 'relationship-supplier@example.com',
            'is_active' => true,
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'order_number' => 'PO-2025-26-000003',
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'financial_year' => '2025-26',
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
        ]);

        $mailable = new PurchaseOrderSentMail($purchaseOrder);

        $this->assertTrue($mailable->purchaseOrder->relationLoaded('supplier'));
        $this->assertTrue($mailable->purchaseOrder->relationLoaded('items'));
    }
}
