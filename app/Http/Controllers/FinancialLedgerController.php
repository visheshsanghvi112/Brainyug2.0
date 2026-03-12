<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\FinancialLedger;
use App\Models\Franchisee;
use App\Models\Supplier;

class FinancialLedgerController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = FinancialLedger::query();
        $context = 'Company Overview';
        
        // Contextual Security
        if ($user->franchisee_id) {
            // Franchisee can ONLY see their own ledger
            $query->where('ledgerable_type', Franchisee::class)
                  ->where('ledgerable_id', $user->franchisee_id);
            $context = 'Your Account Ledger';
        } else {
            // Admin can specifically look up suppliers or franchisees
            $filterType = $request->query('type');
            $filterId = $request->query('id');
            
            if ($filterType === 'franchisee' && $filterId) {
                $query->where('ledgerable_type', Franchisee::class)->where('ledgerable_id', $filterId);
                $context = 'Franchisee Ledger: ' . Franchisee::find($filterId)?->shop_name;
            } elseif ($filterType === 'supplier' && $filterId) {
                $query->where('ledgerable_type', Supplier::class)->where('ledgerable_id', $filterId);
                $context = 'Supplier Ledger: ' . Supplier::find($filterId)?->name;
            }
        }

        $ledgers = $query->orderBy('transaction_date', 'desc')
                         ->orderBy('id', 'desc')
                         ->paginate(50);

        return Inertia::render('Finance/Ledger/Index', [
            'ledgers' => $ledgers,
            'context' => $context
        ]);
    }
}
