<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';

const props = defineProps({
    summary: Object,
    trend: Array,
    franchiseGrowth: Array,
    productGrowth: Array,
    days: Number,
});

const dayFilter = ref(props.days);

watch(dayFilter, (value) => {
    router.get(route('reports.bi.growth'), { days: value }, { preserveState: true, preserveScroll: true });
});

function fmt(value) {
    return Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pct(value) {
    const num = Number(value || 0);
    return `${num >= 0 ? '+' : ''}${num.toFixed(2)}%`;
}

function exportReport(format) {
    window.location.href = route('reports.bi.growth', { days: dayFilter.value, format });
}
</script>

<template>
    <Head title="Growth Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-2xl font-black text-gray-900 dark:text-white">Growth Report</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">MoM and YoY movement across franchisees and products.</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('reports.bi.top-products', { days: dayFilter })" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Top Products</Link>
                    <Link :href="route('reports.bi.franchisee-sales', { days: dayFilter })" class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Franchisee Sales</Link>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('csv')">CSV</button>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('excel')">Excel</button>
                    <button class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" @click="exportReport('pdf')">PDF</button>
                    <select v-model="dayFilter" class="rounded-lg border-gray-300 bg-white text-sm font-semibold dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                        <option :value="30">Last 30 Days</option>
                        <option :value="90">Last 90 Days</option>
                        <option :value="365">Last 365 Days</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-6 mb-6 mt-4">
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 xl:col-span-2">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Current Window Sales</div>
                <div class="mt-2 text-3xl font-black text-gray-900 dark:text-white">INR {{ fmt(summary?.current_sales) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">Previous Window</div>
                <div class="mt-2 text-2xl font-black text-gray-900 dark:text-white">INR {{ fmt(summary?.previous_sales) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">YoY Baseline</div>
                <div class="mt-2 text-2xl font-black text-gray-900 dark:text-white">INR {{ fmt(summary?.year_ago_sales) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">MoM Delta</div>
                <div class="mt-2 text-2xl font-black" :class="summary?.mom_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">INR {{ fmt(summary?.mom_delta) }}</div>
                <div class="mt-1 text-xs font-semibold" :class="summary?.mom_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ pct(summary?.mom_percent) }}</div>
            </div>
            <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="text-xs font-bold uppercase tracking-widest text-gray-400">YoY Delta</div>
                <div class="mt-2 text-2xl font-black" :class="summary?.yoy_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">INR {{ fmt(summary?.yoy_delta) }}</div>
                <div class="mt-1 text-xs font-semibold" :class="summary?.yoy_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ pct(summary?.yoy_percent) }}</div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 mb-6">
            <div class="text-sm font-black uppercase tracking-widest text-gray-500 mb-4">Monthly Revenue Trend</div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
                <div v-for="point in trend" :key="point.label" class="rounded-xl bg-gray-50 p-3 text-center dark:bg-gray-900/40">
                    <div class="text-[11px] font-bold uppercase tracking-wider text-gray-400">{{ point.label }}</div>
                    <div class="mt-2 text-sm font-black text-gray-900 dark:text-white">{{ fmt(point.sales) }}</div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <div class="rounded-2xl bg-white shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-black uppercase tracking-widest text-indigo-600 dark:border-gray-700">Franchise Growth</div>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Franchisee</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Current</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">MoM</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">YoY</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="row in franchiseGrowth" :key="row.id">
                            <td class="px-4 py-3 text-sm">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ row.label }}</div>
                                <div class="text-xs text-gray-500">{{ row.code || 'No code' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-white">{{ fmt(row.current_sales) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold" :class="row.mom_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ pct(row.mom_percent) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold" :class="row.yoy_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ pct(row.yoy_percent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="rounded-2xl bg-white shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4 text-sm font-black uppercase tracking-widest text-amber-600 dark:border-gray-700">Product Growth</div>
                <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Product</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Current</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">MoM</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">YoY</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-for="row in productGrowth" :key="row.id">
                            <td class="px-4 py-3 text-sm">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ row.label }}</div>
                                <div class="text-xs text-gray-500">{{ row.code || 'No SKU' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900 dark:text-white">{{ fmt(row.current_sales) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold" :class="row.mom_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ pct(row.mom_percent) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold" :class="row.yoy_delta >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ pct(row.yoy_percent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
