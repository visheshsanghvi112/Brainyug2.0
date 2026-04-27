<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    rows: Object,
    summary: Object,
    filters: Object,
    users: Array,
});

const startDate = ref(props.filters?.start_date || new Date().toISOString().split('T')[0]);
const endDate = ref(props.filters?.end_date || new Date().toISOString().split('T')[0]);
const status = ref(props.filters?.status || '');
const type = ref(props.filters?.type || '');
const userId = ref(props.filters?.user_id || '');

function applyFilters() {
    router.get(route('reports.compliance.tds'), {
        start_date: startDate.value,
        end_date: endDate.value,
        status: status.value || undefined,
        type: type.value || undefined,
        user_id: userId.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function exportReport(format) {
    window.location.href = route('reports.compliance.tds', {
        start_date: startDate.value,
        end_date: endDate.value,
        status: status.value || undefined,
        type: type.value || undefined,
        user_id: userId.value || undefined,
        format,
    });
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
    <Head title="TDS Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">TDS Report</h2>
                <div class="flex items-center gap-2">
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('csv')">CSV</button>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('excel')">Excel</button>
                    <button class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" @click="exportReport('pdf')">PDF</button>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Entries</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ summary?.entries ?? 0 }}</p>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Gross Commission</p>
                <p class="text-2xl font-bold text-indigo-700">INR {{ fmt(summary?.gross) }}</p>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Total TDS</p>
                <p class="text-2xl font-bold text-amber-600">INR {{ fmt(summary?.tds) }}</p>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Net Payable</p>
                <p class="text-2xl font-bold text-emerald-600">INR {{ fmt(summary?.net) }}</p>
            </div>
        </div>

        <div class="rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow p-4 mb-5">
            <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                <input v-model="startDate" type="date" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                <input v-model="endDate" type="date" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                <select v-model="status" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="paid">Paid</option>
                    <option value="reversed">Reversed</option>
                </select>
                <select v-model="type" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All Types</option>
                    <option value="purchase_commission">Purchase</option>
                    <option value="sales_commission">Sales</option>
                    <option value="joining_fee">Joining Fee</option>
                    <option value="other">Other</option>
                </select>
                <select v-model="userId" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All Users</option>
                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
                <button class="inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700" @click="applyFilters">Apply</button>
            </div>
        </div>

        <div class="rounded-xl bg-white dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700 shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">User</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Order</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Type</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Gross</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">TDS %</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">TDS Amount</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Net</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-if="!rows?.data?.length">
                        <td colspan="9" class="px-4 py-10 text-center text-sm text-gray-400">No TDS entries found for this range.</td>
                    </tr>
                    <tr v-for="r in rows.data" :key="r.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ fmtDate(r.created_at) }}</td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ r.user_name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ r.order_number || '-' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ r.type }}</td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-indigo-700">{{ fmt(r.gross_commission) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-200">{{ fmt(r.tds_percent) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-amber-600">{{ fmt(r.tds_amount) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-emerald-600">{{ fmt(r.net_payable) }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="r.status === 'paid' ? 'bg-emerald-100 text-emerald-700' : (r.status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-700')">
                                {{ r.status }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :data="rows" class="mt-6" />
    </AuthenticatedLayout>
</template>
