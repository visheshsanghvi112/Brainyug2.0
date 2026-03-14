<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
    ArrowLeftIcon,
    BanknotesIcon,
    BuildingStorefrontIcon,
    ClipboardDocumentListIcon,
    PencilSquareIcon,
    WalletIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    supplier: Object,
    summary: Object,
    recentLedgers: Array,
    recentInvoices: Array,
    recentReturns: Array,
    ledgerUrl: String,
});

const showPaymentModal = ref(false);

const paymentForm = useForm({
    amount: props.summary?.current_balance || '',
    payment_date: new Date().toISOString().slice(0, 10),
    payment_mode: 'bank',
    narration: '',
});

const paymentModes = ['cash', 'bank', 'upi', 'cheque', 'neft', 'rtgs', 'adjustment'];

const outstandingLabel = computed(() => Number(props.summary?.current_balance || 0) > 0 ? 'Outstanding Payable' : 'No Outstanding Payable');

function formatMoney(value) {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        maximumFractionDigits: 2,
    }).format(Number(value || 0));
}

function openPaymentModal() {
    paymentForm.reset();
    paymentForm.amount = Number(props.summary?.current_balance || 0) || '';
    paymentForm.payment_date = new Date().toISOString().slice(0, 10);
    paymentForm.payment_mode = 'bank';
    paymentForm.narration = '';
    showPaymentModal.value = true;
}

function submitPayment() {
    paymentForm.post(route('admin.suppliers.payments.store', props.supplier.id), {
        preserveScroll: true,
        onSuccess: () => {
            showPaymentModal.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`${supplier.name} Supplier Desk`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-start gap-4">
                    <Link :href="route('admin.suppliers.index')" class="rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-white">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <div class="flex items-center gap-3">
                            <BuildingStorefrontIcon class="h-7 w-7 text-indigo-500" />
                            <div>
                                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ supplier.name }}</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ supplier.code || 'No supplier code' }} • {{ supplier.contact_person || 'No contact person' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <a :href="ledgerUrl" class="inline-flex items-center gap-2 rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-100 dark:hover:bg-slate-600">
                        <WalletIcon class="h-5 w-5" />
                        View Full Ledger
                    </a>
                    <Link :href="route('admin.suppliers.edit', supplier.id)" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500">
                        <PencilSquareIcon class="h-5 w-5" />
                        Edit Supplier
                    </Link>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="Number(summary.current_balance || 0) <= 0"
                        @click="openPaymentModal"
                    >
                        <BanknotesIcon class="h-5 w-5" />
                        Record Payment
                    </button>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <div class="rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-5 shadow-sm dark:border-emerald-900/50 dark:from-emerald-950/40 dark:to-gray-900">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-300">{{ outstandingLabel }}</p>
                        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ formatMoney(summary.current_balance) }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Live running balance from supplier ledger.</p>
                    </div>
                    <div class="rounded-2xl border border-indigo-200 bg-white p-5 shadow-sm dark:border-indigo-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Approved Purchases</p>
                        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ formatMoney(summary.gross_purchases) }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Total supplier bill value approved into stock.</p>
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm dark:border-amber-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-600 dark:text-amber-300">Purchase Returns</p>
                        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ formatMoney(summary.gross_returns) }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Approved return value already reduced from payable.</p>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-white p-5 shadow-sm dark:border-sky-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-600 dark:text-sky-300">Payments Made</p>
                        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ formatMoney(summary.payments_made) }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Outbound settlements posted to the supplier ledger.</p>
                    </div>
                    <div class="rounded-2xl border border-rose-200 bg-white p-5 shadow-sm dark:border-rose-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-rose-600 dark:text-rose-300">Overdue Exposure</p>
                        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ formatMoney(summary.overdue_exposure) }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ summary.overdue_invoices }} invoice(s) past due on gross invoice terms.</p>
                    </div>
                </div>

                <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                    <div class="space-y-6">
                        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Recent Ledger Movement</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Purchases, returns, and payments in one running balance.</p>
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Voucher</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Narration</th>
                                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Debit</th>
                                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Credit</th>
                                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                        <tr v-if="!recentLedgers.length">
                                            <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-400 dark:text-gray-500">No ledger entries recorded yet.</td>
                                        </tr>
                                        <tr v-for="entry in recentLedgers" :key="entry.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/20">
                                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ entry.transaction_date }}</td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ entry.transaction_type }}</div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ entry.voucher_no }}</div>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                                <div>{{ entry.narration || 'No narration' }}</div>
                                                <div v-if="entry.payment_mode" class="mt-1 text-xs uppercase tracking-wide text-gray-400">{{ entry.payment_mode }}</div>
                                            </td>
                                            <td class="px-6 py-4 text-right text-sm font-semibold text-rose-600">{{ Number(entry.debit) ? formatMoney(entry.debit) : '—' }}</td>
                                            <td class="px-6 py-4 text-right text-sm font-semibold text-emerald-600">{{ Number(entry.credit) ? formatMoney(entry.credit) : '—' }}</td>
                                            <td class="px-6 py-4 text-right text-sm font-bold text-gray-900 dark:text-white">{{ formatMoney(entry.running_balance) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">Approved Purchase Invoices</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Recent supplier bills that created payable exposure.</p>
                                </div>
                                <ClipboardDocumentListIcon class="h-5 w-5 text-indigo-500" />
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Invoice</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Invoice Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Due Date</th>
                                            <th class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                        <tr v-if="!recentInvoices.length">
                                            <td colspan="4" class="px-6 py-10 text-center text-sm text-gray-400 dark:text-gray-500">No approved purchase invoices yet.</td>
                                        </tr>
                                        <tr v-for="invoice in recentInvoices" :key="invoice.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/20">
                                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200">
                                                <Link :href="route('admin.purchase-invoices.show', invoice.id)" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                    {{ invoice.invoice_number }}
                                                </Link>
                                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ invoice.supplier_invoice_no || 'No supplier invoice no.' }}</div>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ invoice.invoice_date }}</td>
                                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ invoice.due_date || 'Same day' }}</td>
                                            <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900 dark:text-white">{{ formatMoney(invoice.total_amount) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>

                    <div class="space-y-6">
                        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Supplier Profile</h3>
                            <dl class="mt-4 space-y-3 text-sm">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500 dark:text-gray-400">Phone</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-white">{{ supplier.phone || '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500 dark:text-gray-400">Email</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-white">{{ supplier.email || '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500 dark:text-gray-400">GST</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-white">{{ supplier.gst_number || '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500 dark:text-gray-400">Credit Terms</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-white">{{ supplier.credit_days || 0 }} day(s)</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500 dark:text-gray-400">Credit Limit</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-white">{{ formatMoney(supplier.credit_limit) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-gray-500 dark:text-gray-400">Location</dt>
                                    <dd class="text-right font-medium text-gray-900 dark:text-white">{{ supplier.district?.name || '—' }}, {{ supplier.state?.name || '—' }}</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Approved Purchase Returns</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Returns already reducing the supplier payable.</p>
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                <div v-if="!recentReturns.length" class="px-6 py-8 text-sm text-gray-400 dark:text-gray-500">No approved purchase returns yet.</div>
                                <div v-for="purchaseReturn in recentReturns" :key="purchaseReturn.id" class="px-6 py-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <Link :href="route('admin.purchase-returns.show', purchaseReturn.id)" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                {{ purchaseReturn.return_number }}
                                            </Link>
                                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ purchaseReturn.return_date }} • {{ purchaseReturn.reason || 'No reason noted' }}</p>
                                        </div>
                                        <div class="text-sm font-semibold text-amber-600">{{ formatMoney(purchaseReturn.total_amount) }}</div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="showPaymentModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="showPaymentModal = false" />
            <div class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Record Supplier Payment</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Post a payment directly into the supplier financial ledger.</p>

                <form class="mt-6 space-y-4" @submit.prevent="submitPayment">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Amount</label>
                        <input v-model="paymentForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-emerald-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Current outstanding: {{ formatMoney(summary.current_balance) }}</p>
                        <p v-if="paymentForm.errors.amount" class="mt-1 text-xs text-red-500">{{ paymentForm.errors.amount }}</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Payment Date</label>
                            <input v-model="paymentForm.payment_date" type="date" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-emerald-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600" />
                            <p v-if="paymentForm.errors.payment_date" class="mt-1 text-xs text-red-500">{{ paymentForm.errors.payment_date }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Mode</label>
                            <select v-model="paymentForm.payment_mode" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-emerald-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600">
                                <option v-for="mode in paymentModes" :key="mode" :value="mode">{{ mode }}</option>
                            </select>
                            <p v-if="paymentForm.errors.payment_mode" class="mt-1 text-xs text-red-500">{{ paymentForm.errors.payment_mode }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Narration</label>
                        <textarea v-model="paymentForm.narration" rows="3" class="w-full rounded-xl border-0 bg-gray-50 px-4 py-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-emerald-500 dark:bg-gray-700 dark:text-white dark:ring-gray-600" placeholder="Optional bank reference or settlement note"></textarea>
                        <p v-if="paymentForm.errors.narration" class="mt-1 text-xs text-red-500">{{ paymentForm.errors.narration }}</p>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700" @click="showPaymentModal = false">
                            Cancel
                        </button>
                        <button type="submit" :disabled="paymentForm.processing" class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">
                            Save Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>