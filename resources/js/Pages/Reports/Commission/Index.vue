<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    commissions: Object,
    summary: Object,   // { total_gross, total_tds, total_net }
    users: Array,      // for admin to filter by user
    isAdmin: Boolean,
    filters: Object,
});

const status = ref(props.filters?.status ?? '');
const type = ref(props.filters?.type ?? '');
const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo = ref(props.filters?.date_to ?? '');
const userId = ref(props.filters?.user_id ?? '');

function applyFilters() {
    const params = new URLSearchParams();
    if (status.value) params.set('status', status.value);
    if (type.value) params.set('type', type.value);
    if (dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo.value) params.set('date_to', dateTo.value);
    if (userId.value) params.set('user_id', userId.value);
    window.location.href = route('reports.commissions') + (params.toString() ? '?' + params.toString() : '');
}

function fmt(n) {
    return Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2 });
}

const statusColors = {
    pending: 'bg-amber-100 text-amber-700',
    approved: 'bg-blue-100 text-blue-700',
    paid: 'bg-emerald-100 text-emerald-700',
    cancelled: 'bg-red-100 text-red-700',
};
</script>

<template>
    <Head title="Commission Report" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Commission Report</h2>
        </template>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Total Gross Commission</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">₹{{ fmt(summary?.total_gross ?? 0) }}</p>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">TDS Deducted</p>
                <p class="text-2xl font-bold text-red-600">₹{{ fmt(summary?.total_tds ?? 0) }}</p>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 shadow">
                <p class="text-xs text-gray-500 mb-1">Net Payable</p>
                <p class="text-2xl font-bold text-emerald-600">₹{{ fmt(summary?.total_net ?? 0) }}</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-6">
            <select v-model="status" @change="applyFilters"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="paid">Paid</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <select v-model="type" @change="applyFilters"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                <option value="">All Types</option>
                <option value="b2b">B2B</option>
                <option value="referral">Referral</option>
                <option value="performance">Performance</option>
            </select>
            <input v-model="dateFrom" type="date" @change="applyFilters"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
            <input v-model="dateTo" type="date" @change="applyFilters"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
            <select v-if="isAdmin && users?.length" v-model="userId" @change="applyFilters"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                <option value="">All Users</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
        </div>

        <!-- Table -->
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th v-if="isAdmin" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">User</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Order Ref</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Gross</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">TDS</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Net</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-if="!commissions.data.length">
                        <td :colspan="isAdmin ? 8 : 7" class="text-center py-12 text-gray-400">No commissions found.</td>
                    </tr>
                    <tr v-for="c in commissions.data" :key="c.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td v-if="isAdmin" class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ c.user_name }}</td>
                        <td class="px-4 py-3 text-sm font-mono text-gray-700 dark:text-gray-300">{{ c.order_ref ?? '#' + c.dist_order_id }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 capitalize">{{ c.commission_type }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                            {{ c.created_at ? new Date(c.created_at).toLocaleDateString('en-IN') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-300">₹{{ fmt(c.gross_amount) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-red-600">₹{{ fmt(c.tds_amount ?? 0) }}</td>
                        <td class="px-4 py-3 text-sm text-right font-semibold text-emerald-600">₹{{ fmt(c.net_amount) }}</td>
                        <td class="px-4 py-3">
                            <span :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold', statusColors[c.status] ?? 'bg-gray-100 text-gray-600']">
                                {{ c.status }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>

        <Pagination :data="commissions" class="mt-6" />
    </AuthenticatedLayout>
</template>
