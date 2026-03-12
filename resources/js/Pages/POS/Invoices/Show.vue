<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { PrinterIcon, ArrowLeftIcon, XCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ invoice: Object });

function fmt(val) {
    return Number(val || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
}

function cancelBill() {
    if (!confirm(`Cancel bill ${props.invoice.bill_no}? This cannot be undone.`)) return;
    router.post(route('pos.invoices.cancel', props.invoice.id));
}
</script>

<template>
    <Head :title="`Bill ${invoice.bill_no}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('pos.invoices.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Bill: {{ invoice.bill_no }}</h2>
                    <span :class="[
                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                        invoice.status === 'completed' ? 'bg-emerald-100 text-emerald-800 border-emerald-200' : 'bg-red-100 text-red-800 border-red-200'
                    ]">{{ invoice.status }}</span>
                </div>
                <div class="flex gap-2">
                    <a :href="route('pos.invoices.print', invoice.id)" target="_blank"
                        class="inline-flex items-center gap-2 rounded-lg bg-gray-100 dark:bg-gray-700 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-all print:hidden">
                        <PrinterIcon class="h-5 w-5" /> Print Receipt
                    </a>
                    <button v-if="invoice.status === 'completed'" @click="cancelBill"
                        class="inline-flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-900/30 px-4 py-2 text-sm font-semibold text-red-700 dark:text-red-300 hover:bg-red-100 transition-all print:hidden">
                        <XCircleIcon class="h-5 w-5" /> Cancel Bill
                    </button>
                </div>
            </div>
        </template>

        <!-- Printable Bill -->
        <div class="max-w-3xl mx-auto">
            <div class="rounded-xl bg-white dark:bg-gray-800 shadow ring-1 ring-gray-200 dark:ring-gray-700 p-8 print:shadow-none print:ring-0">

                <!-- Shop Header -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-5 mb-5 text-center">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ invoice.franchisee?.shop_name }}</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ invoice.franchisee?.address }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        GST: {{ invoice.franchisee?.gst_number ?? 'N/A' }} | Ph: {{ invoice.franchisee?.mobile }}
                    </p>
                </div>

                <!-- Bill Meta -->
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Bill No</p>
                        <p class="font-bold text-gray-900 dark:text-white">{{ invoice.bill_no }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Date & Time</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-200">
                            {{ new Date(invoice.date_time).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) }}
                        </p>
                    </div>
                    <div v-if="invoice.customer">
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Customer</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-200">{{ invoice.customer.name }}</p>
                        <p class="text-sm text-gray-500">{{ invoice.customer.mobile }}</p>
                    </div>
                    <div v-if="invoice.doctor" class="text-right">
                        <p class="text-xs text-gray-500 uppercase tracking-wide">Doctor</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-200">Dr. {{ invoice.doctor.name }}</p>
                    </div>
                </div>

                <!-- Items Table -->
                <table class="w-full mb-6 text-sm">
                    <thead>
                        <tr class="border-b-2 border-gray-300 dark:border-gray-600">
                            <th class="text-left py-2 text-gray-600 dark:text-gray-300">#</th>
                            <th class="text-left py-2 text-gray-600 dark:text-gray-300">Product</th>
                            <th class="text-center py-2 text-gray-600 dark:text-gray-300">Batch</th>
                            <th class="text-center py-2 text-gray-600 dark:text-gray-300">Exp</th>
                            <th class="text-center py-2 text-gray-600 dark:text-gray-300">Qty</th>
                            <th class="text-right py-2 text-gray-600 dark:text-gray-300">MRP</th>
                            <th class="text-right py-2 text-gray-600 dark:text-gray-300">Rate</th>
                            <th class="text-right py-2 text-gray-600 dark:text-gray-300">Disc%</th>
                            <th class="text-right py-2 text-gray-600 dark:text-gray-300">GST%</th>
                            <th class="text-right py-2 text-gray-600 dark:text-gray-300">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="(item, i) in invoice.items" :key="item.id">
                            <td class="py-2 text-gray-500">{{ i + 1 }}</td>
                            <td class="py-2 text-gray-900 dark:text-white font-medium">{{ item.product?.product_name }}</td>
                            <td class="py-2 text-center text-gray-600 dark:text-gray-400">{{ item.batch_no }}</td>
                            <td class="py-2 text-center text-gray-600 dark:text-gray-400">{{ item.exp_date }}</td>
                            <td class="py-2 text-center text-gray-900 dark:text-white">{{ item.qty }}</td>
                            <td class="py-2 text-right text-gray-600 dark:text-gray-400">{{ fmt(item.mrp) }}</td>
                            <td class="py-2 text-right text-gray-900 dark:text-white">{{ fmt(item.rate) }}</td>
                            <td class="py-2 text-right text-gray-600 dark:text-gray-400">{{ item.discount_percent }}%</td>
                            <td class="py-2 text-right text-gray-600 dark:text-gray-400">{{ item.gst_percent }}%</td>
                            <td class="py-2 text-right font-semibold text-gray-900 dark:text-white">₹{{ fmt(item.total_amount) }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Totals -->
                <div class="border-t-2 border-gray-300 dark:border-gray-600 pt-4 space-y-1.5">
                    <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>Sub Total</span><span>₹{{ fmt(invoice.sub_total) }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-amber-600">
                        <span>Discount</span><span>-₹{{ fmt(invoice.total_discount_amount) }}</span>
                    </div>
                    <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400">
                        <span>GST</span><span>+₹{{ fmt(invoice.total_tax_amount) }}</span>
                    </div>
                    <div class="flex justify-between text-lg font-bold text-gray-900 dark:text-white border-t border-gray-300 dark:border-gray-600 pt-2 mt-2">
                        <span>Total</span><span>₹{{ fmt(invoice.total_amount) }}</span>
                    </div>
                </div>

                <!-- Payment Breakdown -->
                <div v-if="invoice.payments?.length" class="mt-5 border-t border-gray-200 dark:border-gray-700 pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Payment</p>
                    <div v-for="pay in invoice.payments" :key="pay.id" class="text-sm">
                        <span class="capitalize font-medium text-gray-700 dark:text-gray-300">{{ pay.payment_mode }}</span>
                        <span v-if="pay.cash_amount > 0"> — Cash: ₹{{ fmt(pay.cash_amount) }}</span>
                        <span v-if="pay.bank_amount > 0"> — Bank/UPI: ₹{{ fmt(pay.bank_amount) }}</span>
                        <span v-if="pay.credit_amount > 0"> — Credit: ₹{{ fmt(pay.credit_amount) }}</span>
                        <span v-if="pay.transaction_no" class="text-gray-400 text-xs"> (Ref: {{ pay.transaction_no }})</span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="mt-8 text-center text-xs text-gray-400">
                    Thank you for shopping with us! | Biller: {{ invoice.user?.name }}
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
@media print {
    nav, aside, header .print\:hidden, button.print\:hidden { display: none !important; }
    body { background: white; }
}
</style>
