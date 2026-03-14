<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    rows: Object,
    summary: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');
const minOutstanding = ref(props.filters?.min_outstanding ?? '');

function applyFilters() {
    router.get(
        route('reports.finance.vendor-outstanding'),
        {
            search: search.value || undefined,
            min_outstanding: minOutstanding.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function clearFilters() {
    search.value = '';
    minOutstanding.value = '';
    applyFilters();
}

function fmt(v) {
    return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtDate(v) {
    if (!v) return '-';
    return new Date(v).toLocaleDateString('en-IN');
}
</script>

<template>
    <Head title="Vendor Outstanding" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Vendor Outstanding</h2>
        </template>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Suppliers with Outstanding</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ summary?.suppliers_with_dues ?? 0 }}</p>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Total Outstanding</p>
                <p class="text-2xl font-bold text-amber-600">INR {{ fmt(summary?.total_outstanding) }}</p>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Estimated 90+ Days Risk</p>
                <p class="text-2xl font-bold text-red-600">INR {{ fmt(summary?.above_90_days) }}</p>
            </div>
        </div>

        <div class="rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search supplier / code / GST / phone"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    @keyup.enter="applyFilters"
                />
                <input
                    v-model="minOutstanding"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="Min outstanding"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                    @keyup.enter="applyFilters"
                />
                <button
                    class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700"
                    @click="applyFilters"
                >
                    Apply Filters
                </button>
                <button
                    class="inline-flex items-center justify-center rounded-lg bg-gray-100 px-4 py-2 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    @click="clearFilters"
                >
                    Reset
                </button>
            </div>
        </div>

        <div class="rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Supplier</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Outstanding</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Current</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">1-30d</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">31-60d</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">61-90d</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">90+d</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Last Invoice</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Last Payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-if="!rows?.data?.length">
                        <td colspan="9" class="px-4 py-10 text-center text-sm text-gray-400">No outstanding suppliers found.</td>
                    </tr>
                    <tr v-for="r in rows.data" :key="r.supplier_id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-4 py-3 text-sm">
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ r.name }}</div>
                            <div class="text-xs text-gray-500">
                                {{ r.code || '-' }}
                                <span v-if="r.phone"> | {{ r.phone }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-amber-600">INR {{ fmt(r.outstanding_balance) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(r.aging.current) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(r.aging.days_1_30) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(r.aging.days_31_60) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(r.aging.days_61_90) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-red-600">{{ fmt(r.aging.days_90_plus) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ fmtDate(r.last_invoice_date) }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ fmtDate(r.last_payment_date) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :data="rows" class="mt-6" />
    </AuthenticatedLayout>
</template>
