<script setup>
import { Head } from '@inertiajs/vue3'
import { onMounted } from 'vue'

const props = defineProps({
    purchaseReturn: {
        type: Object,
        required: true,
    },
})

onMounted(() => {
    setTimeout(() => {
        window.print()
    }, 500)
})
</script>

<template>
    <Head title="Print Purchase Return" />

    <div class="print-container">
        <div class="flex justify-between items-start border-b-2 border-gray-800 pb-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold uppercase tracking-wider text-gray-800">PURCHASE RETURN</h1>
                <p class="text-sm font-semibold text-gray-600 mt-1">BrainYug ERP - Procurement & Inventory</p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase text-gray-500 font-bold mb-1">Return No</p>
                <p class="text-xl font-bold border rounded px-3 py-1 bg-gray-50">{{ purchaseReturn.return_number }}</p>
                <p class="text-sm bg-amber-100 text-amber-800 rounded px-2 mt-2 font-bold inline-block">{{ String(purchaseReturn.workflow_status || purchaseReturn.status).toUpperCase() }}</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-8 mb-8">
            <div class="border rounded p-4 border-gray-300">
                <h2 class="text-xs uppercase font-bold text-gray-500 border-b pb-1 mb-2">Supplier Details</h2>
                <p class="font-bold text-lg">{{ purchaseReturn.supplier?.name || 'Unknown Supplier' }}</p>
                <p class="text-sm">Linked Invoice: <span class="font-mono bg-gray-100 px-1">{{ purchaseReturn.purchase_invoice?.invoice_number || 'Manual Return' }}</span></p>
                <p class="text-sm text-gray-600 mt-2 whitespace-pre-line">{{ purchaseReturn.supplier?.address || 'N/A' }}</p>
            </div>

            <div class="border rounded p-4 border-gray-300">
                <h2 class="text-xs uppercase font-bold text-gray-500 border-b pb-1 mb-2">Return Information</h2>
                <table class="w-full text-sm">
                    <tr><td class="text-gray-600 py-1">Return Date:</td><td class="font-bold text-right">{{ new Date(purchaseReturn.return_date).toLocaleDateString() }}</td></tr>
                    <tr><td class="text-gray-600 py-1">Reason:</td><td class="font-bold text-right">{{ purchaseReturn.reason || 'N/A' }}</td></tr>
                    <tr v-if="purchaseReturn.reversal_reason"><td class="text-gray-600 py-1">Reversal:</td><td class="font-bold text-right">{{ purchaseReturn.reversal_reason }}</td></tr>
                    <tr><td class="text-gray-600 py-1">Linked Invoice:</td><td class="font-bold text-right">{{ purchaseReturn.purchase_invoice?.invoice_number || 'N/A' }}</td></tr>
                </table>
            </div>
        </div>

        <table class="w-full text-sm mb-8 border-collapse border border-gray-400">
            <thead class="bg-gray-100 border-b-2 border-gray-400">
                <tr class="text-left text-xs uppercase text-gray-700">
                    <th class="p-2 border-r border-gray-300">#</th>
                    <th class="p-2 border-r border-gray-300 w-1/3">Item / Batch / Exp</th>
                    <th class="p-2 border-r border-gray-300 text-right">Qty</th>
                    <th class="p-2 border-r border-gray-300 text-right">Rate</th>
                    <th class="p-2 border-r border-gray-300 text-right">GST %</th>
                    <th class="p-2 text-right">Total (Rs)</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(item, index) in purchaseReturn.items" :key="item.id" class="border-b border-gray-300">
                    <td class="p-2 border-r border-gray-300 text-center">{{ index + 1 }}</td>
                    <td class="p-2 border-r border-gray-300">
                        <div class="font-bold">{{ item.product?.product_name || 'N/A' }}</div>
                        <div class="text-xs text-gray-600 mt-1">Batch: {{ item.batch_no }} | Exp: {{ item.expiry_date || 'N/A' }}</div>
                    </td>
                    <td class="p-2 border-r border-gray-300 text-right">{{ Number(item.qty).toFixed(2) }}</td>
                    <td class="p-2 border-r border-gray-300 text-right">{{ Number(item.rate).toFixed(2) }}</td>
                    <td class="p-2 border-r border-gray-300 text-right">{{ Number(item.gst_percent).toFixed(1) }}%</td>
                    <td class="p-2 text-right font-mono">{{ Number(item.total_amount).toFixed(2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="flex justify-end mb-8">
            <div class="w-1/2 border rounded p-4 bg-gray-50 border-gray-300">
                <table class="w-full text-sm">
                    <tr><td class="py-1">Subtotal:</td><td class="text-right">Rs {{ Number(purchaseReturn.subtotal).toFixed(2) }}</td></tr>
                    <tr><td class="py-1">CGST:</td><td class="text-right font-mono">Rs {{ Number(purchaseReturn.cgst_amount).toFixed(2) }}</td></tr>
                    <tr><td class="py-1">SGST:</td><td class="text-right font-mono">Rs {{ Number(purchaseReturn.sgst_amount).toFixed(2) }}</td></tr>
                    <tr><td class="py-1">IGST:</td><td class="text-right font-mono">Rs {{ Number(purchaseReturn.igst_amount).toFixed(2) }}</td></tr>
                    <tr class="font-bold text-lg"><td class="py-2 pt-3">Grand Total:</td><td class="text-right pt-3 font-mono">Rs {{ Number(purchaseReturn.total_amount).toFixed(2) }}</td></tr>
                </table>
            </div>
        </div>

        <div class="mt-8 text-xs text-gray-500 border-t pt-2 flex justify-between">
            <div>
                <p>Created By: {{ purchaseReturn.createdBy?.name || 'System' }}</p>
                <p v-if="purchaseReturn.approvedBy">Approved By: {{ purchaseReturn.approvedBy.name }}</p>
                <p v-if="purchaseReturn.reversedBy">Reversed By: {{ purchaseReturn.reversedBy.name }}</p>
            </div>
            <div class="text-right">
                <p>Generated dynamically by BrainYug ERP via system export.</p>
                <p>Supplier debit note / stock reversal support document.</p>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    body {
        margin: 0;
        padding: 0;
        background: white;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    nav, header, aside, .no-print {
        display: none !important;
    }
    main { padding: 0 !important; margin: 0 !important; }

    .print-container {
        width: 100% !important;
        padding: 2em;
        margin: 0;
        color: black !important;
        font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    @page {
        size: A4;
        margin: 1cm;
    }
}

@media screen {
    .print-container {
        width: 210mm;
        min-height: 297mm;
        margin: 2rem auto;
        padding: 2rem;
        background: white;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        color: black;
        font-family: sans-serif;
    }
}
</style>
