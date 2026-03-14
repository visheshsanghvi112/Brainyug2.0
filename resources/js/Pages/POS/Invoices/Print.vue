<script setup>
import { Head } from '@inertiajs/vue3'
import { computed, onMounted } from 'vue'

const props = defineProps({
    invoice: {
        type: Object,
        required: true,
    },
    printPreferences: {
        type: Object,
        default: () => ({})
    },
})

const receiptLayout = computed(() => props.printPreferences?.receipt_layout === 'a4' ? 'a4' : 'thermal')
const autoPrint = computed(() => props.printPreferences?.auto_print_after_checkout !== false)
const printerPaperWidth = computed(() => {
    const width = props.printPreferences?.printer_paper_width
    return ['58mm', '72mm', '80mm', 'a4'].includes(width) ? width : '80mm'
})
const printCopies = computed(() => {
    const copies = Number(props.printPreferences?.print_copies ?? 1)
    return Number.isFinite(copies) ? Math.min(Math.max(copies, 1), 5) : 1
})
const printerMeta = computed(() => ({
    printerType: props.printPreferences?.printer_type || 'thermal',
    printerConnection: props.printPreferences?.printer_connection || 'system_spooler',
    printerDriver: props.printPreferences?.printer_driver || 'browser_native',
    printerName: props.printPreferences?.printer_name || '',
    printerIp: props.printPreferences?.printer_ip || '',
    printerPort: Number(props.printPreferences?.printer_port ?? 9100),
    autoCutReceipt: props.printPreferences?.auto_cut_receipt !== false,
    openCashDrawer: props.printPreferences?.open_cash_drawer === true,
    eposTimeoutMs: Number(props.printPreferences?.epos_timeout_ms ?? 5000),
}))

const thermalWidthClass = computed(() => {
    if (receiptLayout.value === 'a4' || printerPaperWidth.value === 'a4') {
        return 'paper-a4'
    }

    if (printerPaperWidth.value === '58mm') {
        return 'paper-58'
    }

    if (printerPaperWidth.value === '72mm') {
        return 'paper-72'
    }

    return 'paper-80'
})

onMounted(() => {
    if (!autoPrint.value) {
        return
    }

    // Runtime hints for future native print adapters; current flow remains browser print for safety.
    window.__BRAINYUG_PRINT_PREFS__ = {
        ...printerMeta.value,
        printCopies: printCopies.value,
    }

    // Auto-trigger print dialog when component mounts.
    setTimeout(() => {
        window.print()
    }, 500)
})
</script>

<template>
    <Head title="Print Invoice" />

    <div class="print-container" :class="[{ 'a4-layout': receiptLayout === 'a4' || printerPaperWidth === 'a4' }, thermalWidthClass]">
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
        max-width: 80mm;
        margin: 0 auto;
        color: black !important;
        font-family: monospace, sans-serif;
    }
    .print-container.paper-58 {
        max-width: 58mm;
    }
    .print-container.paper-72 {
        max-width: 72mm;
    }
    .print-container.paper-80 {
        max-width: 80mm;
    }
    .print-container.a4-layout {
        max-width: 210mm;
        padding: 12mm;
        font-family: Arial, sans-serif;
    }
    @page {
        margin: 0;
        size: auto;
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
    .print-container.paper-58 {
        width: 58mm;
    }
    .print-container.paper-72 {
        width: 72mm;
    }
    .print-container.paper-80 {
        width: 80mm;
    }
    .print-container.a4-layout {
        width: min(900px, 95vw);
        min-height: auto;
        font-family: Arial, sans-serif;
    }
}
</style>
