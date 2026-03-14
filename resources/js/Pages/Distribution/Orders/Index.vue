<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { 
    TruckIcon, 
    CheckCircleIcon, 
    XCircleIcon,
    ClockIcon,
    MagnifyingGlassIcon,
    FunnelIcon,
    ChevronRightIcon
} from '@heroicons/vue/24/outline';
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    orders: Object,
    filters: Object,
    metrics: Object,
});

const search = ref(props.filters.search || '');
const statusFilter = ref(props.filters.status || '');
const queueFilter = ref(props.filters.queue || '');
let filterDebounceTimer = null;

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    accepted: 'bg-blue-100 text-blue-800 border-blue-200',
    dispatched: 'bg-indigo-100 text-indigo-800 border-indigo-200',
    delivered: 'bg-green-100 text-green-800 border-green-200',
    rejected: 'bg-red-100 text-red-800 border-red-200',
    cancelled: 'bg-gray-100 text-gray-800 border-gray-200',
};

watch([search, statusFilter, queueFilter], ([newSearch, newStatus, newQueue]) => {
    clearTimeout(filterDebounceTimer);
    filterDebounceTimer = setTimeout(() => {
        router.get(
            route('admin.dist-orders.index'),
            { search: newSearch, status: newStatus, queue: newQueue },
            { preserveState: true, preserveScroll: true }
        );
    }, 300);
});

const queueCards = [
    {
        key: 'pending_orders',
        label: 'Pending Bill Review',
        hint: 'Orders waiting for batch/qty/rate approval',
        tone: 'bg-amber-50 border-amber-200 text-amber-800',
    },
    {
        key: 'pending_dispatch',
        label: 'Pending Dispatch',
        hint: 'Accepted bills waiting dispatch lock-in',
        tone: 'bg-indigo-50 border-indigo-200 text-indigo-800',
    },
    {
        key: 'payment_review',
        label: 'Payment Review Queue',
        hint: 'Pending submitted payments need HO action',
        tone: 'bg-emerald-50 border-emerald-200 text-emerald-800',
    },
    {
        key: 'open_work',
        label: 'Open Workload',
        hint: 'Pending + accepted + dispatched',
        tone: 'bg-slate-50 border-slate-200 text-slate-800',
    },
];

const setQueueFilter = (key) => {
    queueFilter.value = queueFilter.value === key ? '' : key;
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR'
    }).format(amount || 0);
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-IN', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
};
</script>

<template>
    <Head title="B2B Orders (Dispatch)" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">B2B Distribution & Dispatch</h2>
        </template>

        <div class="py-6 lg:py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                
                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700 mb-6 flex flex-col sm:flex-row gap-4 justify-between items-center transition-colors">
                    <div class="relative flex-1 w-full max-w-md">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <MagnifyingGlassIcon class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                        </div>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search Order No / Franchisee..."
                            class="block w-full pl-10 border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm dark:text-gray-200 transition-colors"
                        >
                    </div>
                    
                    <div class="flex items-center gap-2">
                        <FunnelIcon class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                        <select
                            v-model="statusFilter"
                            class="block w-48 border-gray-300 dark:border-gray-600 dark:bg-gray-900 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm dark:text-gray-200 transition-colors"
                        >
                            <option value="">All Statuses</option>
                            <option value="pending">Pending (Action Required)</option>
                            <option value="accepted">Accepted (Ready for Dispatch)</option>
                            <option value="dispatched">Dispatched</option>
                            <option value="delivered">Delivered</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                    <button
                        v-for="card in queueCards"
                        :key="card.key"
                        type="button"
                        @click="setQueueFilter(card.key)"
                        class="rounded-xl border p-4 text-left transition-all hover:shadow-sm"
                        :class="[
                            card.tone,
                            queueFilter === card.key ? 'ring-2 ring-offset-1 ring-indigo-500' : ''
                        ]"
                    >
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-80">{{ card.label }}</div>
                        <div class="text-2xl font-black mt-2">{{ metrics?.[card.key] ?? 0 }}</div>
                        <div class="text-xs mt-1 opacity-80">{{ card.hint }}</div>
                    </button>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg overflow-hidden border border-gray-100 dark:border-gray-700 transition-colors">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700/50">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Order Reference</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Franchisee</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Est. Value</th>
                                    <th scope="col" class="relative px-6 py-3"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-transparent divide-y divide-gray-200 dark:divide-gray-700/50">
                                <tr v-for="order in orders.data" :key="order.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30 cursor-pointer transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0">
                                                <TruckIcon class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">
                                                    <Link :href="route('admin.dist-orders.show', order.id)">{{ order.order_number }}</Link>
                                                </div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400">By: {{ order.user?.name }}</div>
                                                <div v-if="Number(order.pending_payments_count) > 0" class="mt-1 inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                                                    {{ order.pending_payments_count }} payment review pending
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-200">{{ order.franchisee?.shop_name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ order.franchisee?.shop_code }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ formatDate(order.created_at) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <!-- Using static colors for status badges here, could be extended to dark mode specific ones if needed -->
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border" :class="statusColors[order.status]">
                                            {{ order.status.charAt(0).toUpperCase() + order.status.slice(1) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900 dark:text-gray-200">
                                        {{ formatCurrency(order.total_amount) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link :href="route('admin.dist-orders.show', order.id)" class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 inline-flex items-center gap-1">
                                            Review Flow
                                            <ChevronRightIcon class="w-4 h-4" />
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="orders.data.length === 0">
                                    <td colspan="6" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                        No distribution orders found matching the criteria.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <Pagination :data="orders" />

            </div>
        </div>
    </AuthenticatedLayout>
</template>
