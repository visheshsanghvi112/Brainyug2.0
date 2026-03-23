<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    rows: Array,
    summary: Object,
    franchisees: Array,
    filters: Object,
});

const days = ref(Number(props.filters?.days || 60));
const search = ref(props.filters?.search || '');
const franchiseeId = ref(props.filters?.franchisee_id || '');

function applyFilters() {
    router.get(route('reports.stock.near-expiry-dispatch'), {
        days: days.value,
        search: search.value || undefined,
        franchisee_id: franchiseeId.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function fmt(value) {
    return Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function exportReport(format) {
    window.location.href = route('reports.stock.near-expiry-dispatch', {
        days: days.value,
        search: search.value || undefined,
        franchisee_id: franchiseeId.value || undefined,
        format,
    });
}
</script>

<template>
    <Head title="Near-Expiry Dispatch Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-2xl font-black text-gray-900 dark:text-white">Near-Expiry Dispatch Report</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Identify franchise stock nearing expiry so HO can plan movement or clearance.</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('reports.stock.expiry', { months: 3 })" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Stock Expiry View</Link>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('csv')">CSV</button>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('excel')">Excel</button>
                    <button class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" @click="exportReport('pdf')">PDF</button>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4 mb-6 mt-4">
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Affected Franchisees</div>
                <div class="mt-2 text-2xl font-black text-gray-900 dark:text-white">{{ summary?.affected_franchisees ?? 0 }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">At-Risk Batches</div>
                <div class="mt-2 text-2xl font-black text-rose-600">{{ summary?.at_risk_batches ?? 0 }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">At-Risk Units</div>
                <div class="mt-2 text-2xl font-black text-amber-600">{{ fmt(summary?.at_risk_units) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">MRP Exposure</div>
                <div class="mt-2 text-2xl font-black text-indigo-600">INR {{ fmt(summary?.at_risk_value_mrp) }}</div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-4 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 mb-6">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <select v-model="days" class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700">
                    <option :value="30">Next 30 Days</option>
                    <option :value="60">Next 60 Days</option>
                    <option :value="90">Next 90 Days</option>
                    <option :value="180">Next 180 Days</option>
                </select>
                <select v-model="franchiseeId" class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700">
                    <option value="">All franchisees</option>
                    <option v-for="franchisee in franchisees" :key="franchisee.id" :value="franchisee.id">{{ franchisee.shop_name }}</option>
                </select>
                <input v-model="search" type="text" placeholder="Search product, SKU, batch, franchisee" class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700" @keyup.enter="applyFilters" />
                <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500" @click="applyFilters">Apply Filters</button>
            </div>
        </div>

        <div class="rounded-2xl bg-white shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Franchisee</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Batch</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Expiry</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Days Left</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Stock</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">MRP Exposure</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-if="!rows.length">
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-400">No near-expiry dispatch candidates found for the selected filters.</td>
                    </tr>
                    <tr v-for="row in rows" :key="`${row.franchisee_id}-${row.product_id}-${row.batch_no}`">
                        <td class="px-4 py-3 text-sm">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ row.shop_name }}</div>
                            <div class="text-xs text-gray-500">{{ row.shop_code || 'No code' }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ row.product_name }}</div>
                            <div class="text-xs text-gray-500">{{ row.sku || 'No SKU' }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ row.batch_no }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ row.expiry_date }}</td>
                        <td class="px-4 py-3 text-right text-sm font-semibold" :class="row.days_left <= 30 ? 'text-rose-600' : 'text-amber-600'">{{ row.days_left }}</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(row.stock) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-indigo-600">{{ fmt(row.stock_value_mrp) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
