<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { CreditCardIcon, PlusIcon, PencilSquareIcon } from '@heroicons/vue/24/outline';
import { ref, watch } from 'vue';

const props = defineProps({
    expenses: Object,
    summary: Object,
    filters: Object,
    categories: Array,
    paymentModes: Array,
    statuses: Array,
});

const formatCurrency = (val) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(val);

const search = ref(props.filters?.search || '');
const expenseCategoryId = ref(props.filters?.expense_category_id || '');
const paymentMode = ref(props.filters?.payment_mode || '');
const status = ref(props.filters?.status || '');
const hasTds = ref(props.filters?.has_tds || '');
const fromDate = ref(props.filters?.from_date || '');
const toDate = ref(props.filters?.to_date || '');

function applyFilters() {
    router.get(route('expenses.index'), {
        search: search.value || undefined,
        expense_category_id: expenseCategoryId.value || undefined,
        payment_mode: paymentMode.value || undefined,
        status: status.value || undefined,
        has_tds: hasTds.value || undefined,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    search.value = '';
    expenseCategoryId.value = '';
    paymentMode.value = '';
    status.value = '';
    hasTds.value = '';
    fromDate.value = '';
    toDate.value = '';
    applyFilters();
}

let debounceTimer;
watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 250);
});
</script>

<template>
    <Head title="Operational Expenses" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-rose-500 pl-4">Expense Tracker</h2>
                <Link :href="route('expenses.create')" class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-md font-bold flex items-center gap-2 shadow-lg shadow-rose-100">
                    <PlusIcon class="h-5 w-5" /> Log Expense
                </Link>
            </div>
        </template>

        <div class="space-y-6">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Entries</div>
                    <div class="mt-2 text-2xl font-black text-gray-900">{{ summary?.count || 0 }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gross</div>
                    <div class="mt-2 text-2xl font-black text-indigo-700">{{ formatCurrency(summary?.gross || 0) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">TDS</div>
                    <div class="mt-2 text-2xl font-black text-amber-700">{{ formatCurrency(summary?.tds || 0) }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Net Payable</div>
                    <div class="mt-2 text-2xl font-black text-emerald-700">{{ formatCurrency(summary?.net || 0) }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-8">
                    <input v-model="search" type="text" placeholder="Search voucher, vendor, narration" class="rounded-lg border-gray-300 md:col-span-2" />
                    <select v-model="expenseCategoryId" class="rounded-lg border-gray-300" @change="applyFilters">
                        <option value="">All Categories</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                    </select>
                    <select v-model="paymentMode" class="rounded-lg border-gray-300" @change="applyFilters">
                        <option value="">All Modes</option>
                        <option v-for="mode in paymentModes" :key="mode" :value="mode">{{ mode }}</option>
                    </select>
                    <select v-model="status" class="rounded-lg border-gray-300" @change="applyFilters">
                        <option value="">All Status</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                    </select>
                    <select v-model="hasTds" class="rounded-lg border-gray-300" @change="applyFilters">
                        <option value="">All TDS</option>
                        <option value="yes">TDS Applicable</option>
                        <option value="no">No TDS</option>
                    </select>
                    <input v-model="fromDate" type="date" class="rounded-lg border-gray-300" @change="applyFilters" />
                    <input v-model="toDate" type="date" class="rounded-lg border-gray-300" @change="applyFilters" />
                </div>
                <div class="mt-3">
                    <button type="button" class="rounded-lg bg-gray-100 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-200" @click="clearFilters">Reset Filters</button>
                </div>
            </div>

            <div class="bg-white shadow rounded-xl border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 text-xs font-bold uppercase text-gray-500">
                        <tr>
                            <th class="px-6 py-4 text-left">Date / Voucher</th>
                            <th class="px-6 py-4 text-left">Category</th>
                            <th class="px-6 py-4 text-left">Vendor/Details</th>
                            <th class="px-6 py-4 text-center">Mode</th>
                            <th class="px-6 py-4 text-right">Amount</th>
                            <th class="px-6 py-4 text-right">TDS / Net</th>
                            <th class="px-6 py-4 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-if="expenses.data.length === 0">
                            <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                <CreditCardIcon class="h-12 w-12 mx-auto mb-2 text-gray-200" />
                                No expenses recorded. Start logging your daily operational costs.
                            </td>
                        </tr>
                        <tr v-for="exp in expenses.data" :key="exp.id" class="hover:bg-rose-50/30 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-sm font-bold text-gray-900">{{ exp.voucher_number }}</div>
                                <div class="text-xs text-gray-500">{{ exp.expense_date }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-bold uppercase">{{ exp.expense_category?.name }}</span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-700">{{ exp.vendor_name || 'N/A' }}</div>
                                <div class="text-xs text-gray-400 italic">{{ exp.narration }}</div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="capitalize text-xs font-semibold text-gray-500">{{ exp.payment_mode }}</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="text-sm font-bold text-gray-900">{{ formatCurrency(exp.total_amount) }}</div>
                                <div class="text-[10px] text-gray-400">GST: {{ formatCurrency(exp.gst_amount) }}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="text-sm font-bold text-amber-700">{{ formatCurrency(exp.tds_amount || 0) }}</div>
                                <div class="text-[10px] text-emerald-700">Net: {{ formatCurrency(exp.net_amount || exp.total_amount) }}</div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <Link :href="route('expenses.edit', exp.id)" class="inline-flex items-center rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-indigo-600">
                                    <PencilSquareIcon class="h-4 w-4" />
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->
                
                <Pagination :data="expenses" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
