<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import {
    ArrowDownTrayIcon,
    ArrowUturnLeftIcon,
    EyeIcon,
    MagnifyingGlassIcon,
    PlusIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    returns: Object,
    filters: Object,
    suppliers: Array,
});

const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const supplierFilter = ref(props.filters?.supplier_id || '');

function applyFilters() {
    router.get(route('admin.purchase-returns.index'), {
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
    reversed: 'bg-blue-100 text-blue-800 border-blue-200',
    cancelled: 'bg-gray-100 text-gray-800 border-gray-200',
};
</script>

<template>
    <Head title="Purchase Returns" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <ArrowUturnLeftIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        Purchase Returns (Debit Note)
                    </h2>
                </div>
                <div class="flex items-center gap-3">
                    <a
                        :href="route('admin.purchase-returns.export', { search, status: statusFilter, supplier_id: supplierFilter, format: 'csv' })"
                        class="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:bg-green-500"
                    >
                        <ArrowDownTrayIcon class="h-4 w-4" /> CSV
                    </a>
                    <a
                        :href="route('admin.purchase-returns.export', { search, status: statusFilter, supplier_id: supplierFilter, format: 'excel' })"
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:bg-emerald-500"
                    >
                        <ArrowDownTrayIcon class="h-4 w-4" /> Excel
                    </a>
                    <a
                        :href="route('admin.purchase-returns.export', { search, status: statusFilter, supplier_id: supplierFilter, format: 'pdf' })"
                        class="inline-flex items-center gap-2 rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:bg-slate-600"
                    >
                        <ArrowDownTrayIcon class="h-4 w-4" /> PDF
                    </a>
                    <Link
                        :href="route('admin.purchase-returns.create')"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-all duration-200 hover:bg-indigo-500"
                    >
                        <PlusIcon class="h-5 w-5" />
                        New Return
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-6 flex flex-col gap-4 sm:flex-row">
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" />
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search return number..."
                            class="w-full rounded-xl border-0 bg-white py-3 pl-10 pr-4 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                        />
                    </div>
                    <select
                        v-model="supplierFilter"
                        class="rounded-xl border-0 bg-white px-4 py-3 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <option value="">All Suppliers</option>
                        <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                    </select>
                    <select
                        v-model="statusFilter"
                        class="rounded-xl border-0 bg-white px-4 py-3 text-sm shadow-lg ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700"
                    >
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="approved">Approved</option>
                        <option value="reversed">Reversed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Return No / Date</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Supplier</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total Amount</th>
                                    <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <tr
                                    v-for="purchaseReturn in returns.data"
                                    :key="purchaseReturn.id"
                                    class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/30"
                                >
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <div class="font-bold text-gray-900 dark:text-white">{{ purchaseReturn.return_number }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ purchaseReturn.return_date }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ purchaseReturn.supplier?.name || '-' }}</div>
                                        <div class="text-xs text-blue-500 hover:underline">
                                            <Link v-if="purchaseReturn.purchase_invoice_id" :href="route('admin.purchase-invoices.show', purchaseReturn.purchase_invoice_id)">
                                                Inv: {{ purchaseReturn.purchase_invoice?.invoice_number }}
                                            </Link>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white">Rs {{ purchaseReturn.total_amount }}</div>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-center">
                                        <span :class="statusColors[purchaseReturn.status]" class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize">
                                            {{ purchaseReturn.status }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-center">
                                        <Link
                                            :href="route('admin.purchase-returns.show', purchaseReturn.id)"
                                            class="inline-flex items-center rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-blue-600 dark:hover:bg-gray-700 dark:hover:text-blue-400"
                                        >
                                            <EyeIcon class="h-5 w-5" />
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-gray-200 bg-gray-50 px-6 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                        <Pagination :data="returns" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
