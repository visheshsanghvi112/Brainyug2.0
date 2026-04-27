<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftIcon,
    ArrowPathIcon,
    CheckCircleIcon,
    PrinterIcon,
    XCircleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    purchaseReturn: Object,
    actions: Object,
});

function approve() {
    if (confirm('Approve this return? Stock will be deducted from warehouse and supplier payable will be reduced.')) {
        router.post(route('admin.purchase-returns.approve', props.purchaseReturn.id));
    }
}

function cancel() {
    if (confirm('Cancel this draft return?')) {
        router.post(route('admin.purchase-returns.cancel', props.purchaseReturn.id));
    }
}

function reverse() {
    const reason = window.prompt('Why are you reversing this approved return?', props.purchaseReturn.reversal_reason || 'Operational correction');
    if (reason === null || reason.trim() === '') {
        // Optionally notify user here
        return;
    }
    router.post(route('admin.purchase-returns.reverse', props.purchaseReturn.id), {
        reason: reason.trim(),
    });
}

function statusClass(status) {
    if (status === 'approved') {
        return 'bg-emerald-100 text-emerald-800';
    }

    if (status === 'reversed') {
        return 'bg-blue-100 text-blue-800';
    }

    if (status === 'draft') {
        return 'bg-amber-100 text-amber-800';
    }

    return 'bg-gray-100 text-gray-800';
}
</script>

<template>
    <Head :title="`Return ${purchaseReturn.return_number}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <Link :href="route('admin.purchase-returns.index')" class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 dark:hover:bg-gray-700">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                            {{ purchaseReturn.return_number }}
                        </h2>
                        <p class="text-sm text-gray-500">Supplier: {{ purchaseReturn.supplier?.name }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <a
                        :href="route('admin.purchase-returns.print', purchaseReturn.id)"
                        target="_blank"
                        class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 transition-all hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    >
                        <PrinterIcon class="h-5 w-5" /> Print A4
                    </a>

                    <span :class="statusClass(purchaseReturn.workflow_status)" class="rounded-full px-3 py-1 text-sm font-semibold uppercase">
                        {{ purchaseReturn.workflow_status }}
                    </span>

                    <button
                        v-if="actions?.can_approve"
                        @click="approve"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-500"
                    >
                        <CheckCircleIcon class="h-5 w-5" /> Approve
                    </button>

                    <button
                        v-if="actions?.can_reverse"
                        @click="reverse"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-500"
                    >
                        <ArrowPathIcon class="h-5 w-5" /> Reverse
                    </button>

                    <button
                        v-if="actions?.can_cancel"
                        @click="cancel"
                        class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-500"
                    >
                        <XCircleIcon class="h-5 w-5" /> Cancel
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div
                    v-if="purchaseReturn.workflow_status === 'reversed'"
                    class="rounded-2xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm text-blue-800 dark:border-blue-900/50 dark:bg-blue-900/20 dark:text-blue-200"
                >
                    <div class="font-semibold">This purchase return has been reversed.</div>
                    <div class="mt-1">
                        Warehouse stock has been restored and supplier payable has been reopened.
                        <span v-if="purchaseReturn.reversedBy"> Reversed by {{ purchaseReturn.reversedBy.name }}</span>
                        <span v-if="purchaseReturn.reversed_at"> on {{ purchaseReturn.reversed_at }}</span>.
                    </div>
                    <div v-if="purchaseReturn.reversal_reason" class="mt-2 text-blue-700 dark:text-blue-300">
                        Reason: {{ purchaseReturn.reversal_reason }}
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 rounded-xl bg-white p-6 shadow-sm dark:bg-gray-800 md:grid-cols-4">
                    <div>
                        <div class="text-xs uppercase text-gray-500">Total Amount</div>
                        <div class="text-xl font-bold text-gray-900 dark:text-white">Rs {{ purchaseReturn.total_amount }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-500">Subtotal</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">Rs {{ purchaseReturn.subtotal }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-500">Return Date</div>
                        <div class="text-lg font-medium text-gray-900 dark:text-white">{{ purchaseReturn.return_date }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-gray-500">Linked Source Invoices</div>
                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                            <span v-if="purchaseReturn.sourceInvoices?.length">{{ purchaseReturn.sourceInvoices.map((invoice) => invoice.invoice_number).join(', ') }}</span>
                            <span v-else>{{ purchaseReturn.purchaseInvoice?.invoice_number || 'Manual Return' }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                    <div class="overflow-hidden rounded-xl bg-white shadow-sm dark:bg-gray-800">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50 dark:bg-gray-900">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500">Product</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500">Batch / Expiry</th>
                                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">Qty</th>
                                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">Rate</th>
                                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">GST</th>
                                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="item in purchaseReturn.items" :key="item.id">
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-gray-900 dark:text-white">{{ item.product?.product_name }}</div>
                                            <div v-if="item.reason" class="text-xs text-gray-500">{{ item.reason }}</div>
                                        </td>
                                        <td class="px-6 py-4 font-mono text-sm text-gray-600 dark:text-gray-300">
                                            {{ item.batch_no }}<br>
                                            <span class="text-xs text-gray-500">{{ item.expiry_date || 'N/A' }}</span>
                                        </td>
                                        <td class="px-6 py-4 text-right text-gray-700 dark:text-gray-300">{{ item.qty }}</td>
                                        <td class="px-6 py-4 text-right text-gray-700 dark:text-gray-300">Rs {{ item.rate }}</td>
                                        <td class="px-6 py-4 text-right text-gray-700 dark:text-gray-300">
                                            Rs {{ item.gst_amount }} <span class="text-xs">({{ item.gst_percent }}%)</span>
                                        </td>
                                        <td class="px-6 py-4 text-right font-bold text-indigo-600 dark:text-indigo-400">Rs {{ item.total_amount }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-gray-800">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Document Lifecycle</h3>
                            <dl class="mt-4 space-y-3 text-sm">
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">Created By</dt>
                                    <dd class="text-right font-medium text-slate-900 dark:text-slate-100">{{ purchaseReturn.createdBy?.name || 'System' }}</dd>
                                </div>
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">Approved By</dt>
                                    <dd class="text-right font-medium text-slate-900 dark:text-slate-100">{{ purchaseReturn.approvedBy?.name || 'Not approved yet' }}</dd>
                                </div>
                                <div v-if="purchaseReturn.workflow_status === 'reversed'" class="flex items-start justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">Reversed By</dt>
                                    <dd class="text-right font-medium text-slate-900 dark:text-slate-100">{{ purchaseReturn.reversedBy?.name || 'System' }}</dd>
                                </div>
                                <div v-if="purchaseReturn.workflow_status === 'reversed'" class="flex items-start justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">Reversal Reason</dt>
                                    <dd class="text-right font-medium text-slate-900 dark:text-slate-100">{{ purchaseReturn.reversal_reason || 'Operational correction' }}</dd>
                                </div>
                                <div class="flex items-start justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">Header Reason</dt>
                                    <dd class="text-right font-medium text-slate-900 dark:text-slate-100">{{ purchaseReturn.reason || 'None provided' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-gray-800">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Operational Meaning</h3>
                            <p class="mt-3 text-sm text-slate-600 dark:text-slate-300">
                                Draft returns are editable working documents. Approval posts stock-out from warehouse and reduces supplier payable. Reversal restores stock and reopens the supplier liability without deleting the audit trail.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
