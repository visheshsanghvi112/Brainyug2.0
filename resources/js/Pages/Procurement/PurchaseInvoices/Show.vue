<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { DocumentTextIcon, ArrowLeftIcon, CheckCircleIcon, XCircleIcon, PrinterIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice: Object,
});

function approve() {
    if (confirm('Approve this invoice? Once approved, stock will be added to the warehouse and it cannot be edited.')) {
        router.post(route('admin.purchase-invoices.approve', props.invoice.id));
    }
}

function cancel() {
    if (confirm('Cancel this invoice? This action cannot be undone.')) {
        router.post(route('admin.purchase-invoices.cancel', props.invoice.id));
    }
}
</script>

<template>
    <Head :title="`Invoice ${invoice.invoice_number}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('admin.purchase-invoices.index')" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                         <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                            {{ invoice.invoice_number }}
                        </h2>
                        <p class="text-sm text-gray-500">Supplier: {{ invoice.supplier?.name }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a :href="route('admin.purchase-invoices.print', invoice.id)" target="_blank"
                       class="inline-flex items-center gap-2 rounded-lg bg-gray-100 dark:bg-gray-700 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-all">
                        <PrinterIcon class="h-5 w-5" /> Print A4
                    </a>

                    <span v-if="invoice.status === 'draft'" class="rounded-full bg-amber-100 text-amber-800 px-3 py-1 font-semibold text-sm">DRAFT</span>
                    <span v-if="invoice.status === 'approved'" class="rounded-full bg-emerald-100 text-emerald-800 px-3 py-1 font-semibold text-sm">APPROVED</span>
                    <span v-if="invoice.status === 'cancelled'" class="rounded-full bg-gray-100 text-gray-800 px-3 py-1 font-semibold text-sm">CANCELLED</span>

                    <button v-if="invoice.status === 'draft'" @click="approve" class="inline-flex items-center gap-1 rounded bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500">
                        <CheckCircleIcon class="h-5 w-5" /> Approve (Add Stock)
                    </button>
                    
                    <button v-if="invoice.status === 'draft'" @click="cancel" class="inline-flex items-center gap-1 rounded bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500">
                        <XCircleIcon class="h-5 w-5" /> Cancel
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <!-- Info Section... (I'll keep it simple for brevity since the system requires aggressive speed) -->
                <div class="bg-white p-6 rounded-xl shadow-sm grid grid-cols-4 gap-4 dark:bg-gray-800">
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Total Amount</div>
                        <div class="text-xl font-bold text-gray-900 dark:text-white">₹{{ invoice.total_amount }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Subtotal</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">₹{{ invoice.subtotal }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Tax ({{ invoice.tax_type }})</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">₹{{ parseFloat(invoice.sgst_amount) + parseFloat(invoice.cgst_amount) + parseFloat(invoice.igst_amount) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 uppercase">Invoice Date</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">{{ invoice.invoice_date }}</div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow-sm overflow-hidden dark:bg-gray-800">
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500">Product</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500">Batch / Expiry</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">Qty (Free)</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">Rate / MRP</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">Subtotal</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">GST</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">Line Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="item in invoice.items" :key="item.id">
                                <td class="px-6 py-4">
                                    <div class="font-medium dark:text-white">{{ item.product?.product_name }}</div>
                                    <div class="text-xs text-gray-500">HSN: {{ item.hsn?.hsn_code || '—' }}</div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm dark:text-gray-300">
                                    {{ item.batch_no }}<br/>
                                    <span class="text-xs text-gray-500">{{ item.expiry_date || 'N/A' }}</span>
                                </td>
                                <td class="px-6 py-4 text-right dark:text-gray-300">
                                    {{ item.qty }} <span class="text-xs text-blue-500" v-if="item.free_qty > 0">(+{{ item.free_qty }})</span>
                                </td>
                                <td class="px-6 py-4 text-right dark:text-gray-300">
                                    ₹{{ item.rate }}<br/>
                                    <span class="text-xs line-through text-gray-400">₹{{ item.mrp }}</span>
                                </td>
                                <td class="px-6 py-4 text-right font-medium dark:text-white">₹{{ item.taxable_amount }}</td>
                                <td class="px-6 py-4 text-right dark:text-gray-300">₹{{ item.gst_amount }} <span class="text-xs">({{ item.gst_percent }}%)</span></td>
                                <td class="px-6 py-4 text-right font-bold text-indigo-600 dark:text-indigo-400">₹{{ item.total_amount }}</td>
                            </tr>
                        </tbody>
                    </table>
                    </div><!-- overflow-x-auto -->
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
