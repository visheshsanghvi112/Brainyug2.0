# Automation System Design: Stock Alerts, Dispatch, & Notifications

> This document defines the complete purchase, dispatch, and stock notification automation system for BrainYug ERP v2.0.
> It bridges legacy operational patterns with new service-driven architecture.

---

## 1. System Overview

### Core Flows
The system handles three distinct purchase/inventory paths:

```
┌─────────────────────────────────────────────────────────────────────┐
│                    INVENTORY MANAGEMENT AUTOMATION                  │
└─────────────────────────────────────────────────────────────────────┘

PATH 1: HO Procurement (via Supplier)
├─ Supplier Invoice Created (PurchaseInvoice)
├─ Invoice Approved → InventoryLedger PURCHASE entries
├─ Auto-trigger Stock Alert Check
├─ If stock > reorder threshold: No action
└─ If stock < reorder threshold: Create B2B cart suggestion (exists)

PATH 2: Franchisee Outside Purchase (from External Vendor)
├─ Franchisee enters purchase manually (OutsidePurchase record)
├─ HO/Admin reviews and approves
├─ Approval → InventoryLedger UPDATE entries at franchisee location
├─ Auto-trigger Stock Alert Check  
└─ Notification sent to HO if below critical threshold

PATH 3: HO → Franchisee Dispatch (B2B Order)
├─ Franchisee places B2B order (DistOrder)
├─ HO accepts → allocates stock (DistOrderItem.allocated_qty)
├─ HO dispatches (DistOrderDispatchService.dispatch)
├─ Dispatch → InventoryLedger DISPATCH entries (OUT at HO, IN at Franchisee)
├─ Auto-trigger:
│  ├─ HO stock alert if below critical
│  └─ Franchisee notification: "Stock received"
└─ Franchisee receives + marks received in POS
```

### Automation Triggers
Each operational step automatically triggers monitoring:

| Trigger | Point | Action | Notification |
|---------|-------|--------|--------------|
| **Purchase Approved** | HO buys from supplier | Check HO stock level | Alert if < reorder |
| **Outside Purchase Approved** | Franchisee buys from vendor | Check franchisee stock | Alert HO if critical |
| **Order Dispatched** | HO ships to franchisee | Update both locations' stock | Notify franchisee receipt + HO dispatch |
| **Return Processed** | Goods returned to vendor | Adjust stock + creditors | Alert if stock now > max |
| **Stock Expired** | Scheduled daily check | Flag expiring batches | Notify franchisee + HO |

---

## 2. Data Model

### 2.1 Stock Thresholds (Part of Product)
**Existing Fields in `products` table:**
```
reorder_quantity   INT  — Trigger point (when stock < this, create suggestion)
min_stock_level    INT  — Operational minimum
max_stock_level    INT  — Storage capacity
```

### 2.2 Outside Purchase / Franchisee Purchase
**New Model: `FranchiseePurchase`**
```php
FranchiseePurchase {
  id, transaction_number, franchisee_id, supplier_id (external vendor),
  purchase_date, received_date, 
  reason_code (normal|urgent|spot), 
  approval_status (pending|approved|rejected),
  approved_by (user_id), approved_at,
  notes, created_by,
  subtotal, sgst_amount, cgst_amount, igst_amount, total_amount,
  financial_year, status (draft|completed|cancelled)
}

FranchiseePurchaseItem {
  id, franchisee_purchase_id,
  product_id, batch_no, expiry_date, mfg_date,
  qty, free_qty, mrp, rate, discount, gst_amount,
  taxable_amount, total_amount
}
```

Legacy Equivalent: `purchase_challan_vendor` + `purchase_challan_product` (for when franchisees bought from external vendors in legacy)

### 2.3 Stock Alerts (Audit Trail)
**New Model: `StockAlert`**
```php
StockAlert {
  id, alert_type (threshold|expiry|variance),
  product_id, franchisee_id (null for HO),
  current_qty, threshold_qty, alert_level (info|warning|critical),
  triggered_at, acknowledged_by (user_id), acknowledged_at,
  action_taken (text), 
  status (pending|acknowledged|resolved|false_alarm),
  batch_no (for expiry alerts)
}
```

### 2.4 Dispatch Notifications (Existing, Enhanced)
**Model: `DistOrder` (existing)**
- Already tracks: `status`, `dispatched_at`, `dispatched_by`, `received_at`, `received_by`
- Enhance: Wire into event system for notifications

---

## 3. Service Layer

### 3.1 StockMonitoringService (NEW)
**Purpose:** Central point for all stock threshold checks and alert generation

```php
public function checkThreshold(Product $product, $location = 'HO', $franchiseeId = null): ?StockAlert
  // Returns StockAlert if stock < reorder_quantity, null otherwise
  // Queries InventoryLedger with SUM(qty_in - qty_out)
  
public function checkCritical(Product $product, $franchiseeId = null): bool
  // Return true if stock < min_stock_level (critical)
  
public function createAlert(
    $alertType, $product, $currentQty, $thresholdQty, 
    $level = 'warning', $franchiseeId = null
): StockAlert
  // Creates StockAlert record with audit trail
  
public function acknowledgeAlert(StockAlert $alert, User $user): void
  // Marks alert as acknowledged
  
public function checkExpiry(): Collection
  // Queries InventoryLedger for batches expiring within 30 days
  // Returns StockAlert records for each batch at each location
```

### 3.2 FranchiseePurchaseService (NEW)
**Purpose:** Handle franchisee outside purchases

```php
public function createOutsidePurchase(array $data, User $creator): FranchiseePurchase
  // Draft purchase from franchisee
  
public function approvePurchase(FranchiseePurchase $purchase, User $approver): void
  // On approval:
  //  1. Create InventoryLedger UPDATE at franchisee location
  //  2. Trigger StockMonitoringService.checkThreshold()
  //  3. Dispatch StockAlertTriggered event
  
public function rejectPurchase(FranchiseePurchase $purchase, string $reason): void
  // Soft delete or status = rejected
```

### 3.3 DispatchNotificationService (NEW)
**Purpose:** Handle dispatch lifecycle notifications

```php
public function onOrderDispatched(DistOrder $order, User $dispatcher): void
  // Triggered after DistOrderDispatchService::dispatch() completes
  // Actions:
  //  1. Send SMS/Email to Franchisee: "Your order #{order_no} dispatched"
  //  2. Log shipment tracking details
  //  3. Trigger StockMonitoringService for HO stock check
  
public function onOrderReceived(DistOrder $order, User $receiver): void
  // Triggered after franchisee clicks "Mark Received"
  // Actions:
  //  1. Log receipt timestamp + receiving user
  //  2. Auto-generate POS receipt if needed
  //  3. Notify HO: "Order received at {franchisee}"
```

---

## 4. Event System

### 4.1 Events (NEW)
```php
// In app/Events:

FranchiseePurchaseApproved
  → dispatch(FranchiseePurchase $purchase, User $approver)
  
FranchiseePurchaseRejected
  → dispatch(FranchiseePurchase $purchase, string $reason)

OutsidePurchaseReceived
  → dispatch(FranchiseePurchase $purchase)
  
StockAlertTriggered
  → dispatch(StockAlert $alert, string $triggerPoint) // "purchase_approved", "dispatch_completed", etc.
  
OrderDispatched
  → dispatch(DistOrder $order, User $dispatcher) [ENHANCEMENT: Enhance existing if exists]
  
OrderReceived
  → dispatch(DistOrder $order, User $receiver)
```

### 4.2 Event Listeners (NEW)
```php
// In app/Listeners:

FranchiseePurchaseApproved Listeners:
  → NotifyHOOfPurchase (SMS/Email to admin users)
  → TriggerStockAlert (call StockMonitoringService)

StockAlertTriggered Listeners:
  → LogAlertToDatabase (already done by service, but ensure audit trail)
  → NotifyRelevantUsers:
     - If franchisee alert: Notify franchisee + HO
     - If HO alert: Notify warehouse manager
  → CreateAutoSuggestionCart (if reorder_qty threshold)

OrderDispatched Listeners:
  → NotifyFranchiseeOfDispatch
  → TriggerStockAlert for HO
  → BroadcastViaDatabase (for real-time dashboard)

OrderReceived Listeners:
  → NotifyHOOfReceipt
  → BroadcastViaDatabase (update inventory counts live)
```

---

## 5. Controller/Route Layer

### 5.1 New Routes (Admin/HO)
```php
// In routes/web.php or routes/admin.php:

Route::prefix('admin')->middleware('auth')->group(function () {
    
  // Franchisee purchases (outside vendor)
  Route::resource('franchisee-purchases', FranchiseePurchaseController::class)
    ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
    ->whereNumber('franchisee_purchase');
  Route::post('franchisee-purchases/{id}/approve', 
    [FranchiseePurchaseController::class, 'approve'])->name('franchisee-purchases.approve');
  Route::post('franchisee-purchases/{id}/reject', 
    [FranchiseePurchaseController::class, 'reject'])->name('franchisee-purchases.reject');
  
  // Stock monitoring & alerts
  Route::get('stock-alerts', [StockAlertController::class, 'index'])
    ->name('stock-alerts.index');
  Route::post('stock-alerts/{id}/acknowledge', [StockAlertController::class, 'acknowledge'])
    ->name('stock-alerts.acknowledge');
  Route::get('stock-alerts/report', [StockAlertController::class, 'report'])
    ->name('stock-alerts.report');
});
```

### 5.2 Dashboard Widget Enhancements
```
Dashboard should display:
  - "Pending Approval (Franchisee Purchases)" count
  - "Stock Alerts (Last 24h)" count with color coding (warning/critical)
  - "Orders Awaiting Dispatch" count
  - "Recent Notifications" feed
```

---

## 6. Scheduled Commands

### 6.1 Daily Stock Expiry Check
```php
// In Console/Commands/CheckStockExpiry.php (EXISTING, enhance)

Daily at 06:00 UTC:
  1. Query all InventoryLedger records for expiry_date within 30 days
  2. For each batch:
     a. Check if in_transit=false (not currently in a dispatch)
     b. Create StockAlert with type='expiry'
     c. Notify relevant franchisee/HO warehouse
  3. Mark batches < 7 days as "critical expiry"
```

### 6.2 Stock Reconciliation (NEW)
```php
// In Console/Commands/ReconcileStockLevels.php (NEW)

Daily at 23:00 UTC:
  1. For each Product:
     a. Sum InventoryLedger for HO location
     b. Compare with DistOrder pending/dispatched counts
     c. If variance > 5%: Create StockAlert type='variance'
     d. Alert to Warehouse Manager
```

---

## 7. Legacy-to-New Mapping

### How Franchisee Outside Purchases Work

**Legacy Flow (Still Supported):**
```
Franchisee offline → Calls HO → "I need 100x Aspirin"
HO staff → Enter in system as Purchase Challan
HO accepts delivery from vendor OR franchisee declares self-receipt
Stock updated at franchisee location
```

**New System (SERVICE-DRIVEN):**
```
Franchisee logs in → POS/B2B Dashboard
→ "External Purchase" section
→ "New Purchase from [Select Vendor]"
→ Add items, batch, expiry
→ Submit (status: draft, awaiting HO approval)

HO routes: admin/franchisee-purchases
→ See "Pending Franchisee Purchases" 
→ Review (qty check, price, gst, batch num)
→ "Approve" → InventoryLedger UPDATE created
→ Automatically checks stock thresholds
→ Alert if now < minimum

If HO has that product in warehouse:
→ "Suggest from HO Stock" button
→ Instead of franchisee buying external
→ Creates B2B order, HO dispatches
```

---

## 8. Implementation Phases

### Phase 1: Data Model (IMMEDIATE)
1. Create migration: `FranchiseePurchase` + `FranchiseePurchaseItem` tables
2. Create migration: `StockAlert` table
3. Create Models with relationships

### Phase 2: Stock Monitoring (WEEK 1)
1. Create `StockMonitoringService`
2. Create `StockAlertController` (admin route)
3. Wire existing dispatch service to call monitoring
4. Create dashboard widgets

### Phase 3: Franchisee Purchases (WEEK 2)
1. Create `FranchiseePurchaseController`
2. Create `FranchiseePurchaseService`
3. Create migration handlers
4. Build approval workflow

### Phase 4: Event System (WEEK 2)
1. Create events: `FranchiseePurchaseApproved`, `OrderDispatched`, `OrderReceived`
2. Create listeners for notifications
3. Wire into services

### Phase 5: Notifications (WEEK 3)
1. Create `DispatchNotificationService`
2. Send SMS/Email for key events
3. Database broadcast for real-time updates
4. Build notification dashboard

### Phase 6: Automations (WEEK 3)
1. Scheduled commands: Expiry check, Stock reconciliation
2. Fine-tune thresholds
3. Test with production-like data volumes

---

## 9. Query Examples

### Get stock at a location
```php
$hqStock = InventoryLedger::where('product_id', $product->id)
  ->where('location_type', 'HO')
  ->where('location_id', 0)
  ->sum(DB::raw('qty_in - qty_out'));

$franchiseeStock = InventoryLedger::where('product_id', $product->id)
  ->where('location_type', 'FRANCHISEE')
  ->where('location_id', $franchisee->id)
  ->sum(DB::raw('qty_in - qty_out'));
```

### List all unacknowledged alerts for user
```php
$alerts = StockAlert::where('status', 'pending')
  ->where('alert_level', '!=', 'info')
  ->when($user->role === 'franchisee', 
    fn ($q) => $q->where('franchisee_id', $user->franchisee_id)
  )
  ->orderByRaw("CASE alert_level WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
  ->latest()
  ->paginate(20);
```

---

## 10. Security & Permissions

### Access Control
- `Franchisee`: Can create outside purchases, see own stock, see own alerts
- `Warehouse Manager`: Can see all alerts, approve outside purchases, dispatch orders
- `Admin`: Full access, can modify thresholds
- `State Head / Zone Head`: Can see their network's alerts + suggested purchases

### Audit Trail
- Every StockAlert logged with triggered_at + acknowledged details
- Every FranchiseePurchase approval logged with user + timestamp
- InventoryLedger immutable (no deletes, only new entries)

---

## 11. Testing Strategy

### Unit Tests
- `StockMonitoringService::checkThreshold()` with various inventory levels
- `FranchiseePurchaseService::approvePurchase()` ledger creation
- Event listeners dispatch correctly

### Integration Tests
- Full purchase → approval → alert flow
- DistOrder dispatch → notification → franchisee alert
- Expiry check command creates alerts

### Manual Testing Checklist
- [ ] Franchisee creates outside purchase
- [ ] HO approves → stock updated + alert generated
- [ ] Dashboard shows alert
- [ ] Franchisee acknowledges alert
- [ ] DistOrder dispatch triggers HO alert
- [ ] SMS notification sent (test gateway)
- [ ] Scheduled command runs daily

---

## 12. Rollout Checklist

- [ ] Migrations created + applied in production
- [ ] Models tested with relationships
- [ ] Services fully tested
- [ ] Events wired to listeners
- [ ] Dashboard widgets built + styled
- [ ] Notifications (SMS/Email) configured
- [ ] Permission seeding updated
- [ ] Role guides updated
- [ ] Scheduled commands in crontab
- [ ] Load testing with N franchisees generating alerts
- [ ] Disaster recovery: What if notifications fail?
- [ ] Staff training on new approval UI
- [ ] Genesis data migration (legacy purchases → FranchiseePurchase)

---

**Status:** Documentation Complete | Implementation Pending  
**Owner:** BrainYug Dev Team  
**Last Updated:** April 3, 2026
