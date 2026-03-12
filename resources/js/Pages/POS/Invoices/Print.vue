<script setup>
import { Head } from '@inertiajs/vue3'
import { onMounted } from 'vue'

const props = defineProps({
    invoice: {
        type: Object,
        required: true,
    },
})

onMounted(() => {
    // Auto-trigger print dialog when component mounts
    setTimeout(() => {
        window.print()
    }, 500)
})
</script>

<template>
    <Head title="Print Invoice" />

    <div class="print-container">
        <!-- Thermal Printer Friendly Layout -->
        <div class="text-center mb-4">
            <h1 class="text-xl font-bold">{{ invoice.franchisee?.shop_name || 'BrainYug ERP' }}</h1>
            <p class="text-sm">{{ invoice.franchisee?.address || 'HQ Address' }}</p>
            <p class="text-sm font-semibold">GSTIN: {{ invoice.franchisee?.gst_number || 'N/A' }}</p>
            <p class="text-sm">Ph: {{ invoice.franchisee?.mobile || 'N/A' }}</p>
        </div>

        <div class="border-t border-b border-black py-2 my-2 text-sm">
            <div class="flex justify-between">
                <span>Bill No: {{ invoice.bill_no }}</span>
                <span>Date: {{ new Date(invoice.date_time).toLocaleDateString() }}</span>
            </div>
            <div class="flex justify-between">
                <span>Customer: {{ invoice.customer?.name || 'Walk-in' }}</span>
                <span>Cashier: {{ invoice.user?.name || 'Admin' }}</span>
            </div>
        </div>

        <table class="w-full text-sm mb-4">
            <thead>
                <tr class="border-b border-black text-left">
                    <th class="py-1">Item</th>
                    <th class="py-1 text-right">Qty</th>
                    <th class="py-1 text-right">Rate</th>
                    <th class="py-1 text-right">Amt</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in invoice.items" :key="item.id">
                    <td class="py-1 break-words w-1/2">{{ item.product?.product_name || 'Unknown' }}</td>
                    <td class="py-1 text-right">{{ item.qty }}</td>
                    <td class="py-1 text-right">{{ Number(item.rate).toFixed(2) }}</td>
                    <td class="py-1 text-right">{{ Number(item.total_amount).toFixed(2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="border-t border-black pt-2 text-sm text-right">
            <div class="flex justify-between font-bold">
                <span>Net Amount:</span>
                <span>₹{{ Number(invoice.total_amount).toFixed(2) }}</span>
            </div>
            <div class="flex justify-between text-xs mt-1">
                <span>Tax Included (GST):</span>
                <span>₹{{ Number(invoice.total_tax_amount).toFixed(2) }}</span>
            </div>
            <div class="flex justify-between text-xs mt-1" v-if="invoice.total_discount_amount > 0">
                <span>Discount Saved:</span>
                <span>₹{{ Number(invoice.total_discount_amount).toFixed(2) }}</span>
            </div>
        </div>

        <div class="text-center mt-6 text-sm">
            <p>Thank you for your visit!</p>
            <p class="text-xs text-gray-500 mt-2 text-center">Software by BrainYug ERP</p>
        </div>
    </div>
</template>

<style>
/* CSS specific to printing */
@media print {
    body {
        margin: 0;
        padding: 0;
        background: white;
    }
    nav, header, aside, .no-print {
        display: none !important;
    }
    .print-container {
        width: 100% !important;
        max-width: 80mm; /* Standard thermal receipt width */
        margin: 0 auto;
        color: black !important;
        font-family: monospace, sans-serif;
    }
    @page {
        margin: 0;
    }
}

/* Screen preview styling */
@media screen {
    body {
        background: #f3f4f6;
    }
    .print-container {
        width: 80mm;
        min-height: 100mm;
        margin: 2rem auto;
        padding: 1rem;
        background: white;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        color: black;
        font-family: monospace, sans-serif;
    }
}
</style>
