<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    commissions: Object,
    summary: Object,
    users: Array,
    isAdmin: Boolean,
    filters: Object,
});

const status = ref(props.filters?.status ?? '');
const type = ref(props.filters?.type ?? '');
const dateFrom = ref(props.filters?.date_from ?? '');
const dateTo = ref(props.filters?.date_to ?? '');
const userId = ref(props.filters?.user_id ?? '');

function buildQuery(format = '') {
    const params = new URLSearchParams();
    if (status.value) params.set('status', status.value);
    if (type.value) params.set('type', type.value);
    if (dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo.value) params.set('date_to', dateTo.value);
    if (userId.value) params.set('user_id', userId.value);
    if (format) params.set('format', format);
    return params.toString();
}

function applyFilters() {
    const query = buildQuery();
    window.location.href = route('reports.commissions') + (query ? '?' + query : '');
}

function exportReport(format) {
    window.location.href = route('reports.commissions') + '?' + buildQuery(format);
}

function fmt(n) {
    return Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtSigned(n) {
    const value = Number(n || 0);
    const prefix = value > 0 ? '+' : '';
    return `${prefix}${value.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const statusColors = {
    pending: 'bg-amber-100 text-amber-700',
    approved: 'bg-blue-100 text-blue-700',
    paid: 'bg-emerald-100 text-emerald-700',
    reversed: 'bg-rose-100 text-rose-700',
    cancelled: 'bg-red-100 text-red-700',
};
</script>

<template>
    <Head title="Commission Report" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Commission Report</h2>
                <div class="flex items-center gap-2">
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('csv')">CSV</button>
                    <button class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="exportReport('excel')">Excel</button>
                    <button class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white" @click="exportReport('pdf')">PDF</button>
                </div>
            </div>
        </template>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="mb-1 text-xs text-gray-500">Signed Gross Commission</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">Rs {{ fmt(summary?.total_gross) }}</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="mb-1 text-xs text-gray-500">Signed TDS</p>
                <p class="text-2xl font-bold text-red-600">Rs {{ fmt(summary?.total_tds) }}</p>
            </div>
            <div class="rounded-xl bg-white p-5 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="mb-1 text-xs text-gray-500">Signed Net Payable</p>
                <p class="text-2xl font-bold text-emerald-600">Rs {{ fmt(summary?.total_net) }}</p>
            </div>
        </div>

        <div class="mb-6 flex flex-wrap gap-3">
            <select v-model="status" @change="applyFilters"
                class="rounded-xl border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="paid">Paid</option>
                <option value="reversed">Reversed</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <select v-model="type" @change="applyFilters"
                class="rounded-xl border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700">
                <option value="">All Types</option>
                <option value="purchase_commission">Purchase Commission</option>
                <option value="sales_commission">Sales Commission</option>
                <option value="joining_fee">Joining Fee</option>
                <option value="other">Other</option>
            </select>
            <input v-model="dateFrom" type="date" @change="applyFilters"
                class="rounded-xl border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700" />
            <input v-model="dateTo" type="date" @change="applyFilters"
                class="rounded-xl border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700" />
            <select v-if="isAdmin && users?.length" v-model="userId" @change="applyFilters"
                class="rounded-xl border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700">
                <option value="">All Users</option>
                <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
        </div>

        <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th v-if="isAdmin" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">User</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Order Ref</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Dir</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Trigger</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Date</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Gross</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">TDS</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500">Net</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-if="!commissions.data.length">
                            <td :colspan="isAdmin ? 10 : 9" class="py-12 text-center text-gray-400">No commissions found.</td>
                        </tr>
                        <tr v-for="c in commissions.data" :key="c.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td v-if="isAdmin" class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ c.user_name }}</td>
                            <td class="px-4 py-3 font-mono text-sm text-gray-700 dark:text-gray-300">{{ c.order_ref ?? '#' + c.dist_order_id }}</td>
                            <td class="px-4 py-3 text-sm capitalize text-gray-600 dark:text-gray-400">{{ c.type?.replaceAll('_', ' ') }}</td>
                            <td class="px-4 py-3 text-sm font-semibold" :class="c.cr_dr === 'Dr' ? 'text-rose-600' : 'text-emerald-600'">{{ c.cr_dr }}</td>
                            <td class="px-4 py-3 text-sm capitalize text-gray-600 dark:text-gray-400">{{ c.trigger_event ?? '-' }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{{ c.created_at ? new Date(c.created_at).toLocaleDateString('en-IN') : '-' }}</td>
                            <td class="px-4 py-3 text-right text-sm" :class="Number(c.signed_gross_commission) < 0 ? 'text-rose-600' : 'text-gray-700 dark:text-gray-300'">Rs {{ fmtSigned(c.signed_gross_commission) }}</td>
                            <td class="px-4 py-3 text-right text-sm" :class="Number(c.signed_tds_amount) < 0 ? 'text-rose-600' : 'text-red-600'">Rs {{ fmtSigned(c.signed_tds_amount) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-semibold" :class="Number(c.signed_net_payable) < 0 ? 'text-rose-600' : 'text-emerald-600'">Rs {{ fmtSigned(c.signed_net_payable) }}</td>
                            <td class="px-4 py-3">
                                <span :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold', statusColors[c.status] ?? 'bg-gray-100 text-gray-600']">
                                    {{ c.status }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <Pagination :data="commissions" class="mt-6" />
    </AuthenticatedLayout>
</template>
