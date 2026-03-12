<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    ReceiptPercentIcon, MagnifyingGlassIcon, EyeIcon,
    CurrencyRupeeIcon, XCircleIcon, ArrowDownTrayIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoices: Object,
    totals: Object,
    filters: Object,
});

const search    = ref(props.filters?.search    || '');
const status    = ref(props.filters?.status    || '');
const dateFrom  = ref(props.filters?.date_from || '');
const dateTo    = ref(props.filters?.date_to   || '');

function applyFilters() {
    router.get(route('pos.invoices.index'), {
        search:    search.value    || undefined,
        status:    status.value    || undefined,
        date_from: dateFrom.value  || undefined,
        date_to:   dateTo.value    || undefined,
    }, { preserveState: true, preserveScroll: true });
}

let timer;
watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 300); });
watch([status, dateFrom, dateTo], applyFilters);

const statusColors = {
    completed: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    cancelled:  'bg-red-100 text-red-800 border-red-200',
};

function fmt(val) {
    return Number(val || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Sales Invoice History" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <ReceiptPercentIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Sales Invoice History</h2>
                </div>
                <div class="flex items-center gap-3">
                    <a :href="route('pos.invoices.export', { search, status, date_from: dateFrom, date_to: dateTo })"
                        class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 transition-all">
                        <ArrowDownTrayIcon class="h-4 w-4" /> Export CSV
                    </a>
                    <Link :href="route('pos.index')"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-all">
                        New Sale (POS)
                    </Link>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Summary cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="rounded-xl bg-white dark:bg-gray-800 p-4 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Bills</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ totals?.bill_count ?? 0 }}</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 p-4 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Revenue</p>
                    <p class="text-2xl font-bold text-emerald-600">₹{{ fmt(totals?.total_revenue) }}</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 p-4 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Discount Given</p>
                    <p class="text-2xl font-bold text-amber-600">₹{{ fmt(totals?.total_discount) }}</p>
                </div>
                <div class="rounded-xl bg-white dark:bg-gray-800 p-4 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">GST Collected</p>
                    <p class="text-2xl font-bold text-indigo-600">₹{{ fmt(totals?.total_tax) }}</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-48">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                    <input v-model="search" type="text" placeholder="Bill no / customer name / mobile…"
                        class="w-full rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 pl-9 pr-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                </div>
                <input v-model="dateFrom" type="date"
                    class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 px-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                <input v-model="dateTo" type="date"
                    class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 px-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                <select v-model="status"
                    class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 px-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                    <option value="">All Status</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            <!-- Table -->
            <div class="overflow-hidden rounded-xl bg-white dark:bg-gray-800 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Bill No / Date</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Customer</th>
                            <th class="px-5 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Items</th>
                            <th class="px-5 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Amount</th>
                            <th class="px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                            <th class="px-5 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                        <tr v-if="invoices.data.length === 0">
                            <td colspan="6" class="py-16 text-center text-gray-400 dark:text-gray-500">No sales invoices found.</td>
                        </tr>
                        <tr v-for="inv in invoices.data" :key="inv.id"
                            class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-5 py-4">
                                <p class="font-bold text-sm text-gray-900 dark:text-white">{{ inv.bill_no }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ new Date(inv.date_time).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) }}
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">By: {{ inv.user?.name }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm text-gray-900 dark:text-white">{{ inv.customer?.name ?? 'Walk-in' }}</p>
                                <p v-if="inv.customer?.mobile" class="text-xs text-gray-500 dark:text-gray-400">{{ inv.customer.mobile }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm text-gray-900 dark:text-white">{{ inv.items?.length ?? 0 }} item(s)</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-44">
                                    {{ inv.items?.map(i => i.product?.product_name).slice(0, 2).join(', ') }}
                                    <span v-if="(inv.items?.length ?? 0) > 2">…</span>
                                </p>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <p class="font-semibold text-gray-900 dark:text-white">₹{{ fmt(inv.total_amount) }}</p>
                                <p v-if="inv.total_discount_amount > 0" class="text-xs text-amber-600">
                                    -₹{{ fmt(inv.total_discount_amount) }} disc
                                </p>
                            </td>
                            <td class="px-5 py-4 text-center">
                                <span :class="['inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium', statusColors[inv.status]]">
                                    {{ inv.status }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-center">
                                <Link :href="route('pos.invoices.show', inv.id)"
                                    class="inline-flex items-center gap-1 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 px-3 py-1.5 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-800/40 transition-colors">
                                    <EyeIcon class="h-3.5 w-3.5" /> View
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->

                <Pagination :data="invoices" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
