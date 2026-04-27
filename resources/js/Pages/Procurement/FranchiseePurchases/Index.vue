<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    purchases: Object,
    filters: Object,
    approvalStatuses: Array,
    statuses: Array,
    franchisees: Array,
    suppliers: Array,
    canReview: Boolean,
});

const filters = ref({
    search: props.filters?.search || '',
    approval_status: props.filters?.approval_status || '',
    status: props.filters?.status || '',
    franchisee_id: props.filters?.franchisee_id || '',
    supplier_id: props.filters?.supplier_id || '',
    date_from: props.filters?.date_from || '',
    date_to: props.filters?.date_to || '',
});

let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('admin.franchisee-purchases.index'), filters.value, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300);
}, { deep: true });

const clearFilters = () => {
    filters.value = {
        search: '',
        approval_status: '',
        status: '',
        franchisee_id: '',
        supplier_id: '',
        date_from: '',
        date_to: '',
    };
};

const amount = (v) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(Number(v || 0));
const dt = (v) => v ? new Date(v).toLocaleDateString('en-IN') : '-';

const badgeClass = (status) => {
    if (status === 'approved') return 'bg-green-100 text-green-700 border-green-200';
    if (status === 'rejected') return 'bg-red-100 text-red-700 border-red-200';
    return 'bg-yellow-100 text-yellow-700 border-yellow-200';
};
</script>

<template>
    <Head title="Outside Purchases" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Franchisee Outside Purchases</h2>
                <Link :href="route('admin.franchisee-purchases.create')" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    New Outside Purchase
                </Link>
            </div>
        </template>

        <div class="py-6 lg:py-10">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">
                <div class="bg-white border border-gray-100 rounded-xl p-4 grid grid-cols-1 md:grid-cols-7 gap-3">
                    <input v-model="filters.search" type="text" placeholder="Search transaction/supplier/franchisee" class="rounded-md border-gray-300 md:col-span-2" />

                    <select v-model="filters.approval_status" class="rounded-md border-gray-300">
                        <option value="">All Approval</option>
                        <option v-for="s in approvalStatuses" :key="s" :value="s">{{ s }}</option>
                    </select>

                    <select v-model="filters.status" class="rounded-md border-gray-300">
                        <option value="">All Record Status</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                    </select>

                    <select v-model="filters.franchisee_id" class="rounded-md border-gray-300" :disabled="!canReview">
                        <option value="">All Franchisees</option>
                        <option v-for="f in franchisees" :key="f.id" :value="f.id">{{ f.shop_name }}</option>
                    </select>

                    <select v-model="filters.supplier_id" class="rounded-md border-gray-300">
                        <option value="">All Suppliers</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>

                    <button type="button" @click="clearFilters" class="rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 px-3 py-2">Clear</button>
                </div>

                <div class="bg-white border border-gray-100 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Transaction</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Franchisee</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Supplier</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Approval</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in purchases.data" :key="row.id" class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm font-semibold text-indigo-600">{{ row.transaction_number }}</td>
                                    <td class="px-4 py-3 text-sm">{{ row.franchisee?.shop_name || '-' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ row.supplier?.name || '-' }}</td>
                                    <td class="px-4 py-3 text-sm">{{ dt(row.purchase_date) }}</td>
                                    <td class="px-4 py-3 text-sm text-right font-semibold">{{ amount(row.total_amount) }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-bold" :class="badgeClass(row.approval_status)">{{ row.approval_status }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <Link :href="route('admin.franchisee-purchases.show', row.id)" class="text-indigo-600 hover:text-indigo-800">View</Link>
                                    </td>
                                </tr>
                                <tr v-if="purchases.data.length === 0">
                                    <td colspan="7" class="px-4 py-10 text-center text-sm text-gray-500">No outside purchases found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-between text-sm text-gray-600">
                    <div>Page {{ purchases.current_page }} of {{ purchases.last_page }}</div>
                    <div class="flex gap-2">
                        <Link v-if="purchases.prev_page_url" :href="purchases.prev_page_url" class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200">Prev</Link>
                        <Link v-if="purchases.next_page_url" :href="purchases.next_page_url" class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200">Next</Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
