
<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';

const props = defineProps({
    summary: Object,
    topRows: Array,
    bottomRows: Array,
    days: Number,
});

const dayFilter = ref(props.days);

watch(dayFilter, (value) => {
    router.get(route('reports.bi.franchisee-sales'), { days: value }, { preserveState: true, preserveScroll: true });
});

function fmt(value) {
    return Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function exportReport(format) {
    window.location.href = route('reports.bi.franchisee-sales', { days: dayFilter.value, format });
}
</script>

<template>
    <Head title="Franchisee Sales Leaderboard" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-2xl font-black text-gray-900 dark:text-white">Franchisee Sales Leaderboard</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Top and bottom stores by completed retail sales in the selected window.</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('reports.bi.top-products', { days: dayFilter })" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Top Products</Link>
                    <Link :href="route('reports.bi.growth', { days: dayFilter })" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Growth</Link>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('csv')">CSV</button>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('excel')">Excel</button>
                    <button class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" @click="exportReport('pdf')">PDF</button>
                    <select v-model="dayFilter" class="rounded-lg border-gray-300 bg-white text-sm font-semibold dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option :value="7">Last 7 Days</option>
                        <option :value="30">Last 30 Days</option>
                        <option :value="90">Last 90 Days</option>
                        <option :value="365">Last 365 Days</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-4 mb-6 mt-4">
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Total Sales</div>
                <div class="mt-2 text-2xl font-black text-emerald-600">INR {{ fmt(summary?.total_sales) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Bills</div>
                <div class="mt-2 text-2xl font-black text-gray-900 dark:text-white">{{ summary?.total_bills ?? 0 }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Active Stores</div>
                <div class="mt-2 text-2xl font-black text-indigo-600">{{ summary?.active_franchisees ?? 0 }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Average Bill</div>
                <div class="mt-2 text-2xl font-black text-amber-600">INR {{ fmt(summary?.average_bill) }}</div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <div class="rounded-2xl bg-white shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-black uppercase tracking-widest text-emerald-600 dark:border-gray-700">Top Performers</div>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Franchisee</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Bills</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Average Bill</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Sales</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="row in topRows" :key="`top-${row.id}`">
                            <td class="px-4 py-3 text-sm">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ row.shop_name }}</div>
                                <div class="text-xs text-gray-500">{{ row.shop_code || 'No code' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ row.bills }}</td>
                            <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(row.avg_bill) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-black text-emerald-600">{{ fmt(row.sales) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-2xl bg-white shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-black uppercase tracking-widest text-rose-600 dark:border-gray-700">Bottom Performers</div>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Franchisee</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Bills</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Average Bill</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Sales</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="row in bottomRows" :key="`bottom-${row.id}`">
                            <td class="px-4 py-3 text-sm">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ row.shop_name }}</div>
                                <div class="text-xs text-gray-500">{{ row.shop_code || 'No code' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ row.bills }}</td>
                            <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(row.avg_bill) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-black text-rose-600">{{ fmt(row.sales) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
