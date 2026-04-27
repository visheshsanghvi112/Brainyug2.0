<?php

namespace App\Services;

use App\Models\PurchaseInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceDraftService
{
    public function create(array $validated, int $actorId): PurchaseInvoice
    {
        return DB::transaction(function () use ($validated, $actorId) {
            [$invoicePayload, $itemsData] = $this->buildDraftPayload($validated);
            $financialYear = PurchaseInvoice::financialYearForDate($validated['invoice_date']);

            $lastInvoice = PurchaseInvoice::where('financial_year', $financialYear)
                ->orderByDesc('id')
                ->first();
            $nextNum = $lastInvoice ? ((int) substr($lastInvoice->invoice_number, -4)) + 1 : 1;

            $invoice = PurchaseInvoice::create(array_merge($invoicePayload, [
                'invoice_number' => 'PI-' . $financialYear . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT),
                'financial_year' => $financialYear,
                'status' => 'draft',
                'created_by' => $actorId,
            ]));

            foreach ($itemsData as $item) {
                $invoice->items()->create($item);
            }

            return $invoice->fresh(['items']);
        });
    }

    public function update(PurchaseInvoice $purchaseInvoice, array $validated): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice, $validated) {
            $locked = PurchaseInvoice::whereKey($purchaseInvoice->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            $this->assertEditable($locked);

            [$invoicePayload, $itemsData] = $this->buildDraftPayload($validated, $locked->financial_year);

            $locked->update($invoicePayload);
            $locked->items()->delete();

            foreach ($itemsData as $item) {
                $locked->items()->create($item);
            }

            return $locked->fresh(['items']);
        });
    }

    private function buildDraftPayload(array $validated, ?string $financialYear = null): array
    {
        $subtotal = 0.0;
        $totalDiscount = 0.0;
        $totalSgst = 0.0;
        $totalCgst = 0.0;
        $totalIgst = 0.0;
        $itemsData = [];

        foreach ($validated['items'] as $item) {
            $qty = (float) $item['qty'];
            $rate = (float) $item['rate'];
            $discPct = (float) ($item['discount_percent'] ?? 0);
            $gstPct = (float) $item['gst_percent'];

            $lineTotal = $qty * $rate;
            $discAmt = $lineTotal * ($discPct / 100);
            $taxable = $lineTotal - $discAmt;
            $gstAmt = $taxable * ($gstPct / 100);
            $totalDiscount += $discAmt;

            if ($validated['tax_type'] === 'intra_state') {
                $totalSgst += $gstAmt / 2;
                $totalCgst += $gstAmt / 2;
            } else {
                $totalIgst += $gstAmt;
            }

            $subtotal += $taxable;

            $itemsData[] = array_merge($item, [
                'discount_amount' => round($discAmt, 2),
                'gst_amount' => round($gstAmt, 2),
                'taxable_amount' => round($taxable, 2),
                'total_amount' => round($taxable + $gstAmt, 2),
            ]);
        }

        $totalAmount = $subtotal + $totalSgst + $totalCgst + $totalIgst;
        $roundOff = round($totalAmount) - $totalAmount;

        return [[
            'supplier_id' => $validated['supplier_id'],
            'supplier_invoice_no' => $validated['supplier_invoice_no'] ?? null,
            'invoice_date' => $validated['invoice_date'],
            'received_date' => $validated['received_date'] ?? null,
            'due_days' => $validated['due_days'] ?? 0,
            'transporter' => $validated['transporter'] ?? null,
            'lr_number' => $validated['lr_number'] ?? null,
            'financial_year' => $financialYear ?? PurchaseInvoice::currentFinancialYear(),
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($totalDiscount, 2),
            'sgst_amount' => round($totalSgst, 2),
            'cgst_amount' => round($totalCgst, 2),
            'igst_amount' => round($totalIgst, 2),
            'round_off' => round($roundOff, 2),
            'total_amount' => round($totalAmount + $roundOff, 2),
            'tax_type' => $validated['tax_type'],
            'notes' => $validated['notes'] ?? null,
        ], $itemsData];
    }

    private function assertEditable(PurchaseInvoice $purchaseInvoice): void
    {
        if ($purchaseInvoice->isLegacy()) {
            throw ValidationException::withMessages([
                'status' => 'Legacy historical invoices are read-only and cannot be edited.',
            ]);
        }

        if (!$purchaseInvoice->canEdit()) {
            throw ValidationException::withMessages([
                'status' => 'This purchase invoice is no longer draft and cannot be edited.',
            ]);
        }
    }
}
