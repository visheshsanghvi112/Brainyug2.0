<script setup>
import { Head } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'

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

const queryLayout = ref(null)
const queryPaper = ref(null)

const receiptLayout = computed(() => {
    if (queryLayout.value === 'a4') {
        return 'a4'
    }
    return props.printPreferences?.receipt_layout === 'a4' ? 'a4' : 'thermal'
})
const autoPrint = computed(() => props.printPreferences?.auto_print_after_checkout !== false)
const printerPaperWidth = computed(() => {
    if (queryPaper.value && ['58mm', '72mm', '80mm', 'a4'].includes(queryPaper.value)) {
        return queryPaper.value
    }

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
const billLogoUrl = computed(() => {
    const value = String(props.printPreferences?.bill_logo_url || '').trim()
    return value
})
const billHeaderLine1 = computed(() => String(props.printPreferences?.bill_header_line_1 || '').trim())
const billHeaderLine2 = computed(() => String(props.printPreferences?.bill_header_line_2 || '').trim())

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
    const params = new URLSearchParams(window.location.search)
    const layout = params.get('layout')
    const paper = params.get('paper')

    if (layout && ['thermal', 'a4'].includes(layout)) {
        queryLayout.value = layout
    }

    if (paper && ['58mm', '72mm', '80mm', 'a4'].includes(paper)) {
        queryPaper.value = paper
    }

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
            <img
                v-if="billLogoUrl"
                :src="billLogoUrl"
                alt="Shop Logo"
                class="mx-auto mb-2 h-12 w-auto object-contain"
                @error="(event) => { event.target.style.display = 'none' }"
            >
            <h1 class="text-xl font-bold">{{ invoice.franchisee?.shop_name || 'BrainYug ERP' }}</h1>
            <p v-if="billHeaderLine1" class="text-xs font-semibold">{{ billHeaderLine1 }}</p>
            <p v-if="billHeaderLine2" class="text-xs">{{ billHeaderLine2 }}</p>
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
            <div class="flex justify-between">
                <span>Subtotal:</span>
                <span>₹{{ Number(invoice.sub_total || 0).toFixed(2) }}</span>
            </div>
            <div class="flex justify-between" v-if="Number(invoice.total_discount_amount || 0) > 0">
                <span>Discount:</span>
                <span>- ₹{{ Number(invoice.total_discount_amount).toFixed(2) }}</span>
            </div>
            <div class="flex justify-between">
                <span>GST:</span>
                <span>₹{{ Number(invoice.total_tax_amount || 0).toFixed(2) }}</span>
            </div>
            <div class="flex justify-between" v-if="Number(invoice.other_charges || 0) > 0">
                <span>Other Charges:</span>
                <span>₹{{ Number(invoice.other_charges).toFixed(2) }}</span>
            </div>
            <div class="flex justify-between" v-if="Number(invoice.round_off || 0) !== 0">
                <span>Round Off:</span>
                <span>₹{{ Number(invoice.round_off).toFixed(2) }}</span>
            </div>
            <div class="flex justify-between font-bold">
                <span>Net Amount:</span>
                <span>₹{{ Number(invoice.total_amount).toFixed(2) }}</span>
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
