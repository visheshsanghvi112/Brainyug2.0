<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
                    <div v-if="invoices.links?.length > 3" class="border-t border-gray-200 bg-gray-50 px-6 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                        <nav class="flex items-center justify-between">
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Showing {{ invoices.from }} to {{ invoices.to }} of {{ invoices.total }}
                            </p>
                            <div class="flex gap-1">
                                <template v-for="(link, index) in (invoices.links || []).filter(Boolean)" :key="link.label || `invoice-link-${index}`">
                                    <Link v-if="link.url" :href="link.url" v-html="link.label" class="rounded-lg px-3 py-1.5 text-sm transition" :class="link.active ? 'bg-indigo-600 text-white' : 'text-gray-600 hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-gray-700'" preserve-state />
                                    <span v-else v-html="link.label" class="rounded-lg px-3 py-1.5 text-sm text-gray-300 dark:text-gray-600" />
                                </template>
                            </div>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
