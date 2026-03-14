<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    MagnifyingGlassIcon, PlusIcon, DocumentTextIcon, CheckCircleIcon,
    XCircleIcon, EyeIcon, ArrowDownTrayIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoices: Object,
    filters: Object,
    suppliers: Array,
    orderOpsMetrics: Object,
    pendingOrderOps: Array,
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const supplierFilter = ref(props.filters?.supplier_id || '');

function applyFilters() {
    router.get(route('admin.purchase-invoices.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        supplier_id: supplierFilter.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

let debounceTimer;
watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 300);
});

watch([statusFilter, supplierFilter], applyFilters);

const statusColors = {
    draft: 'bg-amber-100 text-amber-800 border-amber-200',
    approved: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    cancelled: 'bg-gray-100 text-gray-800 border-gray-200',
};

const orderStatusColors = {
    pending: 'bg-amber-100 text-amber-800 border-amber-200',
    accepted: 'bg-blue-100 text-blue-800 border-blue-200',
    dispatched: 'bg-indigo-100 text-indigo-800 border-indigo-200',
};

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        minimumFractionDigits: 2,
    }).format(Number(amount || 0));
}
</script>

<template>
    <Head title="Purchase Invoices" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <DocumentTextIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        Purchase Invoices (GRN)
                    </h2>
                </div>
                <div class="flex items-center gap-3">
                    <a :href="route('admin.purchase-invoices.export', { search, status: statusFilter, supplier_id: supplierFilter })"
                        class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-green-500 transition-all duration-200">
                        <ArrowDownTrayIcon class="h-4 w-4" /> Export CSV
                    </a>
                    <Link
                        :href="route('admin.purchase-invoices.create')"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-all duration-200"
                    >
                        <PlusIcon class="h-5 w-5" />
                        New Invoice
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Filters Row -->
                <div class="mb-6 flex flex-col gap-4 sm:flex-row">
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search invoice number..."
                            class="w-full rounded-xl border-0 bg-white py-3 pl-10 pr-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                        />
                    </div>
                    <select
                        v-model="supplierFilter"
                        class="rounded-xl border-0 bg-white py-3 px-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <option value="">All Suppliers</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                    <select
                        v-model="statusFilter"
                        class="rounded-xl border-0 bg-white py-3 px-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <!-- Legacy Order Ops Bridge -->
                <div class="mb-6 rounded-xl bg-white p-5 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">Pending Orders & Dispatch Ops</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Legacy-dispatch controls surfaced inside GRN for quicker operations.</p>
                        </div>
                        <Link
                            :href="route('admin.dist-orders.index')"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                        >
                            Open Full Dispatch Queue
                        </Link>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Link
                            :href="route('admin.dist-orders.index', { queue: 'pending_orders' })"
                            class="rounded-lg border border-amber-200 bg-amber-50 p-3"
                        >
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">Pending Orders</div>
                            <div class="mt-1 text-xl font-black text-amber-800">{{ orderOpsMetrics?.pending_orders ?? 0 }}</div>
                        </Link>
                        <Link
                            :href="route('admin.dist-orders.index', { queue: 'pending_dispatch' })"
                            class="rounded-lg border border-blue-200 bg-blue-50 p-3"
                        >
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-blue-700">Pending Dispatch</div>
                            <div class="mt-1 text-xl font-black text-blue-800">{{ orderOpsMetrics?.pending_dispatch ?? 0 }}</div>
                        </Link>
                        <Link
                            :href="route('admin.dist-orders.index', { status: 'dispatched' })"
                            class="rounded-lg border border-indigo-200 bg-indigo-50 p-3"
                        >
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-indigo-700">Dispatched</div>
                            <div class="mt-1 text-xl font-black text-indigo-800">{{ orderOpsMetrics?.in_transit ?? 0 }}</div>
                        </Link>
                        <Link
                            :href="route('admin.dist-orders.index', { queue: 'open_work' })"
                            class="rounded-lg border border-slate-200 bg-slate-50 p-3"
                        >
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-700">Open Work</div>
                            <div class="mt-1 text-xl font-black text-slate-800">{{ orderOpsMetrics?.open_work ?? 0 }}</div>
                        </Link>
                    </div>

                    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th class="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Order</th>
                                    <th class="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Franchisee</th>
                                    <th class="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500">Status</th>
                                    <th class="px-4 py-2 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Amount</th>
                                    <th class="px-4 py-2 text-right text-[11px] font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                <tr v-for="op in (pendingOrderOps || [])" :key="op.id" class="bg-white dark:bg-gray-800">
                                    <td class="px-4 py-2 text-sm font-semibold text-gray-900 dark:text-white">{{ op.order_number }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ op.franchisee?.shop_name || '—' }}</td>
                                    <td class="px-4 py-2">
                                        <span :class="orderStatusColors[op.status] || 'bg-gray-100 text-gray-700 border-gray-200'" class="inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold uppercase">
                                            {{ op.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right text-sm font-semibold text-gray-900 dark:text-white">{{ formatCurrency(op.total_amount) }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <div class="inline-flex items-center gap-2">
                                            <Link :href="route('admin.dist-orders.show', op.id)" class="rounded bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">Review</Link>
                                            <a :href="route('admin.dist-orders.picklist-pdf', op.id)" class="rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100">Picklist</a>
                                            <a :href="route('admin.dist-orders.gst-invoice-pdf', op.id)" class="rounded bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">GST Invoice</a>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!(pendingOrderOps || []).length">
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">No pending dispatch operations right now.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Invoice No / Date</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Supplier</th>
                                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Amount</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <tr
                                v-for="inv in invoices.data"
                                :key="inv.id"
                                class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30"
                            >
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-bold text-gray-900 dark:text-white">{{ inv.invoice_number }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ inv.invoice_date }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ inv.supplier?.name || '—' }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Ref: {{ inv.supplier_invoice_no || '—' }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">₹{{ inv.total_amount }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <span :class="statusColors[inv.status]" class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize">
                                        {{ inv.status }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-center">
                                    <Link
                                        :href="route('admin.purchase-invoices.show', inv.id)"
                                        class="inline-flex items-center rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-700 dark:hover:text-blue-400 transition"
                                    >
                                        <EyeIcon class="h-5 w-5" />
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div><!-- overflow-x-auto -->
                    <div class="border-t border-gray-200 bg-gray-50 px-6 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                        <Pagination :data="invoices" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
