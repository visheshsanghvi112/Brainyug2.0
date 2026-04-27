<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftIcon,
    ArrowUturnLeftIcon,
    CheckCircleIcon,
    PencilSquareIcon,
    PrinterIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice: Object,
    actions: Object,
    returnSummary: Object,
    paymentSummary: Object,
    paymentAllocations: Array,
    linkedReturns: Array,
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

function linkedReturnStatusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200';
    }

    if (status === 'reversed') {
        return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-200';
    }

    if (status === 'draft') {
        return 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200';
    }

    return 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-100';
}
</script>

<template>
    <Head :title="`Invoice ${invoice.invoice_number}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Link :href="route('admin.purchase-invoices.index')" class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 dark:hover:bg-gray-700">
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
                    <a
                        :href="route('admin.purchase-invoices.print', invoice.id)"
                        target="_blank"
                        class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 transition-all hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    >
                        <PrinterIcon class="h-5 w-5" /> Print A4
                    </a>

                    <Link
                        v-if="actions?.can_edit"
                        :href="route('admin.purchase-invoices.edit', invoice.id)"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-all hover:bg-indigo-500"
                    >
                        <PencilSquareIcon class="h-5 w-5" /> Edit Draft
                    </Link>

                    <Link
                        v-if="actions?.can_create_return"
                        :href="route('admin.purchase-returns.create', { purchase_invoice_id: invoice.id })"
                        class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition-all hover:bg-amber-500"
                    >
                        <ArrowUturnLeftIcon class="h-5 w-5" /> Create Return
                    </Link>

                    <span v-if="invoice.status === 'draft'" class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-800">DRAFT</span>
                    <span v-if="invoice.status === 'approved'" class="rounded-full bg-emerald-100 px-3 py-1 text-sm font-semibold text-emerald-800">APPROVED</span>
                    <span v-if="invoice.status === 'cancelled'" class="rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-800">CANCELLED</span>

                    <button
                        v-if="actions?.can_approve"
                        @click="approve"
                        class="inline-flex items-center gap-1 rounded bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500"
                    >
                        <CheckCircleIcon class="h-5 w-5" /> Approve (Add Stock)
                    </button>

                    <button
                        v-if="actions?.can_cancel"
                        @click="cancel"
                        class="inline-flex items-center gap-1 rounded bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-500"
                    >
                        <XCircleIcon class="h-5 w-5" /> Cancel
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 rounded-xl bg-white p-6 shadow-sm dark:bg-gray-800 md:grid-cols-4">
                    <div>
                        <div class="text-xs uppercase text-gray-500">Total Amount</div>
                        <div class="text-xl font-bold text-gray-900 dark:text-white">₹{{ invoice.total_amount }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-500">Subtotal</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">₹{{ invoice.subtotal }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-500">Tax ({{ invoice.tax_type }})</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">
                            ₹{{ parseFloat(invoice.sgst_amount) + parseFloat(invoice.cgst_amount) + parseFloat(invoice.igst_amount) }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-500">Invoice Date</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">{{ invoice.invoice_date }}</div>
                    </div>
                </div>

                <div
                    v-if="actions?.can_edit"
                    class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-900/20 dark:text-amber-200"
                >
                    This invoice is still a draft. You can edit quantities, batches, commercial values, or approve it to post stock and supplier payable.
                </div>

                <div
                    v-else-if="invoice.status === 'approved' && !actions?.can_create_return"
                    class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-200"
                >
                    This invoice has already been fully returned against approved purchase returns, so no new return can be raised from it.
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                    <div class="overflow-hidden rounded-xl bg-white shadow-sm dark:bg-gray-800">
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
                                            {{ item.batch_no }}<br />
                                            <span class="text-xs text-gray-500">{{ item.expiry_date || 'N/A' }}</span>
                                        </td>
                                        <td class="px-6 py-4 text-right dark:text-gray-300">
                                            {{ item.qty }} <span v-if="item.free_qty > 0" class="text-xs text-blue-500">(+{{ item.free_qty }})</span>
                                        </td>
                                        <td class="px-6 py-4 text-right dark:text-gray-300">
                                            ₹{{ item.rate }}<br />
                                            <span class="text-xs text-gray-400 line-through">₹{{ item.mrp }}</span>
                                        </td>
                                        <td class="px-6 py-4 text-right font-medium dark:text-white">₹{{ item.taxable_amount }}</td>
                                        <td class="px-6 py-4 text-right dark:text-gray-300">
                                            ₹{{ item.gst_amount }} <span class="text-xs">({{ item.gst_percent }}%)</span>
                                        </td>
                                        <td class="px-6 py-4 text-right font-bold text-indigo-600 dark:text-indigo-400">₹{{ item.total_amount }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Return Position</h3>
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Linked purchase returns and remaining returnable quantity.</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-100">
                                    {{ linkedReturns.length }} Doc{{ linkedReturns.length === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <div class="mt-4 grid gap-3">
                                <div class="rounded-xl bg-emerald-50 p-4 dark:bg-emerald-900/20">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Approved Returned Qty</div>
                                    <div class="mt-1 text-2xl font-bold text-emerald-800 dark:text-emerald-200">{{ returnSummary.approved_returned_qty }}</div>
                                    <div class="text-xs text-emerald-700/80 dark:text-emerald-300/80">Value ₹{{ returnSummary.approved_return_total }}</div>
                                </div>
                                <div class="rounded-xl bg-amber-50 p-4 dark:bg-amber-900/20">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Draft Return Qty</div>
                                    <div class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">{{ returnSummary.draft_return_qty }}</div>
                                    <div class="text-xs text-amber-700/80 dark:text-amber-300/80">Value ₹{{ returnSummary.draft_return_total }}</div>
                                </div>
                                <div class="rounded-xl bg-sky-50 p-4 dark:bg-sky-900/20">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">Remaining Returnable Qty</div>
                                    <div class="mt-1 text-2xl font-bold text-sky-800 dark:text-sky-200">{{ returnSummary.remaining_returnable_qty }}</div>
                                    <div class="text-xs text-sky-700/80 dark:text-sky-300/80">Purchased qty {{ returnSummary.purchased_qty }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Linked Returns</h3>
                                <span v-if="!linkedReturns.length" class="text-xs text-slate-400">No linked documents yet</span>
                            </div>

                            <div v-if="linkedReturns.length" class="mt-4 space-y-3">
                                <Link
                                    v-for="linkedReturn in linkedReturns"
                                    :key="linkedReturn.id"
                                    :href="route('admin.purchase-returns.show', linkedReturn.id)"
                                    class="block rounded-xl border border-slate-200 px-4 py-3 transition hover:border-indigo-300 hover:bg-indigo-50/60 dark:border-slate-700 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/10"
                                >
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="font-semibold text-slate-900 dark:text-slate-100">{{ linkedReturn.return_number }}</div>
                                            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                {{ linkedReturn.return_date || 'Date not set' }}
                                                <span v-if="linkedReturn.created_by_name">· {{ linkedReturn.created_by_name }}</span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">₹{{ linkedReturn.total_amount }}</div>
                                            <span
                                                class="mt-1 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold capitalize"
                                                :class="linkedReturnStatusClass(linkedReturn.status)"
                                            >
                                                {{ linkedReturn.status }}
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            </div>
                        </div>
                    </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Settlement Position</h3>
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Invoice payable after returns, payment allocation, and overdue state.</p>
                                </div>
                                <span
                                    class="rounded-full px-3 py-1 text-xs font-semibold"
                                    :class="paymentSummary?.is_overdue ? 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-200' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200'"
                                >
                                    {{ paymentSummary?.is_overdue ? 'OVERDUE' : 'ON TRACK' }}
                                </span>
                            </div>

                            <div class="mt-4 grid gap-3">
                                <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-900/40">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">Net Payable</div>
                                    <div class="mt-1 text-2xl font-bold text-slate-900 dark:text-slate-100">₹{{ paymentSummary?.net_payable ?? 0 }}</div>
                                </div>
                                <div class="rounded-xl bg-emerald-50 p-4 dark:bg-emerald-900/20">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Allocated Payments</div>
                                    <div class="mt-1 text-2xl font-bold text-emerald-800 dark:text-emerald-200">₹{{ paymentSummary?.paid_amount ?? 0 }}</div>
                                </div>
                                <div class="rounded-xl bg-amber-50 p-4 dark:bg-amber-900/20">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Outstanding</div>
                                    <div class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">₹{{ paymentSummary?.outstanding_amount ?? 0 }}</div>
                                    <div class="text-xs text-amber-700/80 dark:text-amber-300/80">
                                        Due: {{ paymentSummary?.due_date || 'N/A' }} · Allocation records: {{ paymentSummary?.allocation_count || 0 }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Payment Allocation Trail</h3>
                                <span v-if="!(paymentAllocations || []).length" class="text-xs text-slate-400">No allocations yet</span>
                            </div>

                            <div v-if="(paymentAllocations || []).length" class="mt-4 space-y-3">
                                <div
                                    v-for="allocation in paymentAllocations"
                                    :key="allocation.id"
                                    class="rounded-xl border border-slate-200 px-4 py-3 dark:border-slate-700"
                                >
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="font-semibold text-slate-900 dark:text-slate-100">{{ allocation.voucher_no || 'No Voucher' }}</div>
                                            <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                {{ allocation.allocation_date || allocation.transaction_date || 'Date N/A' }}
                                                <span v-if="allocation.payment_mode">· {{ allocation.payment_mode }}</span>
                                            </div>
                                            <div v-if="allocation.narration" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ allocation.narration }}</div>
                                        </div>
                                        <div class="text-right text-sm font-semibold text-slate-900 dark:text-slate-100">₹{{ allocation.amount }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
