<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    rows: Object,
    totals: Object,
    filters: Object,
});

const startDate = ref(props.filters?.start_date ?? '');
const endDate = ref(props.filters?.end_date ?? '');
const status = ref(props.filters?.status ?? '');
const search = ref(props.filters?.search ?? '');

function applyFilters() {
    router.get(route('reports.sales.daily-register'), {
        start_date: startDate.value || undefined,
        end_date: endDate.value || undefined,
        status: status.value || undefined,
        search: search.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function exportReport(format) {
    const params = {
        start_date: startDate.value || undefined,
        end_date: endDate.value || undefined,
        status: status.value || undefined,
        search: search.value || undefined,
        export_format: format,
    };

    window.location.href = route('reports.sales.daily-register', params);
}

function money(value) {
    return Number(value || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
}
</script>

<template>
    <Head title="Daily Sales Register" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Daily Sales Register</h2>
                <div class="flex items-center gap-2">
                    <button type="button" @click="exportReport('csv')" class="rounded-md bg-gray-700 px-3 py-2 text-xs font-semibold text-white hover:bg-gray-600">CSV</button>
                    <button type="button" @click="exportReport('excel')" class="rounded-md bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-600">Excel</button>
                    <button type="button" @click="exportReport('pdf')" class="rounded-md bg-indigo-700 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-600">PDF</button>
                </div>
            </div>
        </template>

        <div class="space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <input v-model="startDate" type="date" @change="applyFilters"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                <input v-model="endDate" type="date" @change="applyFilters"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                <select v-model="status" @change="applyFilters"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All Statuses</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <input v-model="search" type="text" placeholder="Bill, customer, mobile, shop" @keyup.enter="applyFilters"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 md:col-span-2" />
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500">Bills</p>
                    <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ totals?.bill_count ?? 0 }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500">Total Sales</p>
                    <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ money(totals?.total_sales) }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500">Cash / Bank</p>
                    <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ money(totals?.total_cash) }} / {{ money(totals?.total_bank) }}</p>
                </div>
                <div class="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
                    <p class="text-xs text-gray-500">Credit</p>
                    <p class="text-xl font-semibold text-gray-900 dark:text-white">{{ money(totals?.total_credit) }}</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-2 text-left">Bill</th>
                                <th class="px-3 py-2 text-left">Date</th>
                                <th class="px-3 py-2 text-left">Franchise</th>
                                <th class="px-3 py-2 text-left">Customer</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-right">Cash</th>
                                <th class="px-3 py-2 text-right">Bank</th>
                                <th class="px-3 py-2 text-right">Credit</th>
                                <th class="px-3 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="row in rows.data" :key="row.id">
                                <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ row.bill_no }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.date_time }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.franchisee_name || '-' }} ({{ row.franchisee_code || '-' }})</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.customer_name || 'Walk-in' }}<span class="block text-xs text-gray-500">{{ row.customer_mobile || '-' }}</span></td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-1 text-xs"
                                          :class="row.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                        {{ row.status }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ money(row.cash_amount) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ money(row.bank_amount) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-300">{{ money(row.credit_amount) }}</td>
                                <td class="px-3 py-2 text-right font-semibold text-gray-900 dark:text-gray-100">{{ money(row.total_amount) }}</td>
                            </tr>
                            <tr v-if="!rows.data?.length">
                                <td colspan="9" class="px-3 py-8 text-center text-gray-500">No sales found for this filter window.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination :links="rows.links" />
        </div>
    </AuthenticatedLayout>
</template>
