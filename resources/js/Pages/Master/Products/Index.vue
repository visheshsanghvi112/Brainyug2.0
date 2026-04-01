<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    MagnifyingGlassIcon,
    PlusIcon,
    PencilSquareIcon,
    XMarkIcon,
    FunnelIcon,
    ArrowDownTrayIcon,
    TableCellsIcon,
    PrinterIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Object,
    filters: Object,
    summary: Object,
    categories: Array,
    companies: Array,
});

const search = ref(props.filters?.search || '');
const category = ref(props.filters?.category || '');
const company = ref(props.filters?.company || '');
const status = ref(props.filters?.status ?? '');
const showFilters = ref(!!(props.filters?.category || props.filters?.company || props.filters?.status !== undefined && props.filters?.status !== ''));
const showExportOptions = ref(false);

const exportLayout = ref('detailed');
const includeBarcode = ref(false);
const includeTax = ref(true);
const includeUnits = ref(true);
const includePricing = ref(true);

const hasActiveFilters = computed(() => !!(search.value || category.value || company.value || status.value !== ''));
const activeFilterChips = computed(() => [
    search.value ? `Search: ${search.value}` : null,
    category.value ? `Category: ${props.categories.find((item) => String(item.id) === String(category.value))?.name || category.value}` : null,
    company.value ? `Company: ${props.companies.find((item) => String(item.id) === String(company.value))?.name || company.value}` : null,
    status.value !== '' ? `Status: ${String(status.value) === '1' ? 'Active' : 'Inactive'}` : null,
].filter(Boolean));

function applyFilters() {
    const params = {};
    if (search.value) params.search = search.value;
    if (category.value) params.category = category.value;
    if (company.value) params.company = company.value;
    if (status.value !== '') params.status = status.value;

    router.get(route('admin.products.index'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    search.value = '';
    category.value = '';
    company.value = '';
    status.value = '';
    router.get(route('admin.products.index'), {}, { preserveState: true, replace: true });
}

function exportUrl(type, extra = {}) {
    const params = new URLSearchParams();

    if (search.value) params.set('search', search.value);
    if (category.value) params.set('category', category.value);
    if (company.value) params.set('company', company.value);
    if (status.value !== '') params.set('status', status.value);

    params.set('layout', exportLayout.value);
    if (includeBarcode.value) params.set('include_barcode', '1');
    if (includeTax.value) params.set('include_tax', '1');
    if (includeUnits.value) params.set('include_units', '1');
    if (includePricing.value) params.set('include_pricing', '1');

    Object.entries(extra).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    });

    const qs = params.toString();
    return route(`admin.products.export.${type}`) + (qs ? `?${qs}` : '');
}

function formatMoney(value) {
    return Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

let debounceTimer;
watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(applyFilters, 350);
});
watch([category, company, status], applyFilters);
</script>

<template>
    <Head title="Products Master" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <h2 class="text-2xl font-black text-gray-900 dark:text-white">Product Catalog</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Master catalog workspace for pricing, taxonomy, and export-ready product governance.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:bg-gray-800 dark:text-slate-100 dark:hover:bg-gray-700"
                        @click="showExportOptions = !showExportOptions"
                    >
                        <ArrowDownTrayIcon class="h-4 w-4" />
                        Export Options
                    </button>
                    <Link
                        :href="route('admin.products.create')"
                        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500"
                    >
                        <PlusIcon class="h-4 w-4 stroke-2" />
                        Add Product
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-indigo-200 bg-white p-5 shadow-sm dark:border-indigo-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Filtered Products</p>
                        <p class="mt-3 text-3xl font-black text-gray-900 dark:text-white">{{ summary?.total_products ?? 0 }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Current catalog rows matching your active search and filters.</p>
                    </div>
                    <div class="rounded-2xl border border-emerald-200 bg-white p-5 shadow-sm dark:border-emerald-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600 dark:text-emerald-300">Active Products</p>
                        <p class="mt-3 text-3xl font-black text-gray-900 dark:text-white">{{ summary?.active_products ?? 0 }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Commercially usable product rows in this filtered view.</p>
                    </div>
                    <div class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm dark:border-amber-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-600 dark:text-amber-300">Inactive Products</p>
                        <p class="mt-3 text-3xl font-black text-gray-900 dark:text-white">{{ summary?.inactive_products ?? 0 }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Rows still in master data but currently switched off.</p>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-white p-5 shadow-sm dark:border-sky-900/40 dark:bg-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-600 dark:text-sky-300">Companies Covered</p>
                        <p class="mt-3 text-3xl font-black text-gray-900 dark:text-white">{{ summary?.companies_covered ?? 0 }}</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Supplier or company spread represented in this working slice.</p>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-center">
                        <div class="relative flex-1">
                            <MagnifyingGlassIcon class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search by product, salt, company, barcode, HSN, SKU, or product code"
                                class="w-full rounded-xl border-gray-300 bg-white py-3 pl-10 pr-4 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                            />
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-3 text-sm font-medium transition"
                                :class="showFilters ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-300' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700'"
                                @click="showFilters = !showFilters"
                            >
                                <FunnelIcon class="h-4 w-4" />
                                Filters
                            </button>
                            <button
                                v-if="hasActiveFilters"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-3 text-sm font-medium text-gray-500 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700"
                                @click="clearFilters"
                            >
                                <XMarkIcon class="h-4 w-4" />
                                Clear
                            </button>
                            <a
                                :href="exportUrl('excel')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-3 text-sm font-medium text-emerald-700 transition hover:bg-emerald-50 dark:border-emerald-700 dark:bg-gray-800 dark:text-emerald-300 dark:hover:bg-emerald-900/20"
                            >
                                <TableCellsIcon class="h-4 w-4" />
                                Excel
                            </a>
                            <a
                                :href="exportUrl('pdf')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-3 text-sm font-medium text-rose-700 transition hover:bg-rose-50 dark:border-rose-700 dark:bg-gray-800 dark:text-rose-300 dark:hover:bg-rose-900/20"
                            >
                                <ArrowDownTrayIcon class="h-4 w-4" />
                                PDF
                            </a>
                            <a
                                :href="exportUrl('pdf', { preview: 1 })"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:bg-gray-800 dark:text-slate-100 dark:hover:bg-gray-700"
                            >
                                <PrinterIcon class="h-4 w-4" />
                                Preview
                            </a>
                        </div>
                    </div>

                    <div v-show="showFilters" class="mt-4 grid grid-cols-1 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 md:grid-cols-3 dark:border-gray-700 dark:bg-gray-900/30">
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Category</label>
                            <select v-model="category" class="w-full rounded-lg border-gray-300 bg-white py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">All Categories</option>
                                <option v-for="item in categories" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Company</label>
                            <select v-model="company" class="w-full rounded-lg border-gray-300 bg-white py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">All Companies</option>
                                <option v-for="item in companies" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</label>
                            <select v-model="status" class="w-full rounded-lg border-gray-300 bg-white py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">All Status</option>
                                <option :value="1">Active</option>
                                <option :value="0">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="showExportOptions" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/40">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Export Pack Configuration</h3>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    The Excel and PDF buttons above will use these options immediately.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full bg-white px-3 py-1 font-medium text-slate-600 shadow-sm dark:bg-gray-800 dark:text-slate-200">Layout: {{ exportLayout }}</span>
                                <span class="rounded-full bg-white px-3 py-1 font-medium text-slate-600 shadow-sm dark:bg-gray-800 dark:text-slate-200">
                                    Blocks:
                                    {{ [includeBarcode ? 'Barcode' : null, includeTax ? 'Tax' : null, includeUnits ? 'Units' : null, includePricing ? 'Pricing' : null].filter(Boolean).join(', ') || 'Core only' }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-4 xl:grid-cols-[1fr_1.4fr]">
                            <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
                                <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Layout</div>
                                <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                    <input v-model="exportLayout" type="radio" value="detailed" class="mt-1" />
                                    <div>
                                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Detailed</div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">Best for master-data audits, management review, and pricing control.</div>
                                    </div>
                                </label>
                                <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                    <input v-model="exportLayout" type="radio" value="compact" class="mt-1" />
                                    <div>
                                        <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Compact</div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">Cleaner pack for quick sharing and lighter review meetings.</div>
                                    </div>
                                </label>
                            </div>

                            <div class="rounded-xl bg-white p-4 shadow-sm dark:bg-gray-800">
                                <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Column Blocks</div>
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                        <input v-model="includeBarcode" type="checkbox" class="mt-1" />
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Include Barcode</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">Useful for scanning audits and packaging verification.</div>
                                        </div>
                                    </label>
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                        <input v-model="includeTax" type="checkbox" class="mt-1" />
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Include Tax Block</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">HSN and GST columns for compliance-sensitive reviews.</div>
                                        </div>
                                    </label>
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                        <input v-model="includeUnits" type="checkbox" class="mt-1" />
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Include Units and Packing</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">Packing, units, and conversion details for operations.</div>
                                        </div>
                                    </label>
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                        <input v-model="includePricing" type="checkbox" class="mt-1" />
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">Include Pricing Ladder</div>
                                            <div class="text-xs text-slate-500 dark:text-slate-400">MRP, PTR, and internal rates for commercial review.</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="activeFilterChips.length" class="mt-4 flex flex-wrap items-center gap-2">
                        <span
                            v-for="chip in activeFilterChips"
                            :key="chip"
                            class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300"
                        >
                            {{ chip }}
                        </span>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Catalog Register</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Review product identity, taxonomy, pricing anchors, and status from one place.
                                </p>
                            </div>
                            <div class="text-xs font-medium uppercase tracking-wider text-gray-400">
                                {{ products?.data?.length || 0 }} row(s) on this page
                            </div>
                        </div>
                    </div>

                    <ul class="divide-y divide-gray-100 md:hidden dark:divide-gray-700/60">
                        <li v-if="!products.data?.length" class="p-8 text-center text-sm text-gray-400 dark:text-gray-500">No products found.</li>
                        <li
                            v-for="product in products.data"
                            :key="product.id"
                            class="flex items-start justify-between gap-3 p-4 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/40"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ product.product_name }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ product.salt?.name || '-' }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-mono text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ product.sku }}</span>
                                    <span class="text-xs font-semibold text-gray-900 dark:text-white">INR {{ product.mrp }}</span>
                                    <span class="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">{{ product.hsn?.code || '-' }}</span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                    :class="product.is_active ? 'bg-green-50 text-green-700 ring-green-600/10 dark:bg-green-900/30 dark:text-green-300 dark:ring-green-400/20' : 'bg-red-50 text-red-700 ring-red-600/10 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-400/20'"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full" :class="product.is_active ? 'bg-green-500' : 'bg-red-500'" />
                                    {{ product.is_active ? 'Active' : 'Inactive' }}
                                </span>
                                <Link :href="route('admin.products.edit', product.id)" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                    Edit ->
                                </Link>
                            </div>
                        </li>
                    </ul>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Product</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Catalog Identity</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Company / Category</th>
                                    <th class="px-6 py-3.5 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Tax</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">MRP</th>
                                    <th class="px-6 py-3.5 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">PTR</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="px-6 py-3.5 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                <tr
                                    v-for="product in products.data"
                                    :key="product.id"
                                    class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/40"
                                >
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ product.product_name }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ product.salt?.name || '-' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-mono font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                            {{ product.sku }}
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ product.barcode || 'No barcode' }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                        <div class="font-medium text-gray-900 dark:text-white">{{ product.company?.name || '-' }}</div>
                                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ product.category?.name || '-' }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="inline-flex rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/10 dark:bg-blue-900/30 dark:text-blue-300 dark:ring-blue-400/20">
                                            {{ product.hsn?.code || '-' }}
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ product.hsn?.tax || 'Tax not set' }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900 dark:text-white">INR {{ product.mrp }}</td>
                                    <td class="px-6 py-4 text-right text-sm font-medium text-gray-600 dark:text-gray-300">INR {{ product.ptr }}</td>
                                    <td class="px-6 py-4 text-center">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                            :class="product.is_active ? 'bg-green-50 text-green-700 ring-green-600/10 dark:bg-green-900/30 dark:text-green-300 dark:ring-green-400/20' : 'bg-red-50 text-red-700 ring-red-600/10 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-400/20'"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full" :class="product.is_active ? 'bg-green-500' : 'bg-red-500'" />
                                            {{ product.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <Link
                                            :href="route('admin.products.edit', product.id)"
                                            class="inline-flex rounded-lg p-2 text-gray-400 transition hover:bg-gray-100 hover:text-indigo-600 dark:hover:bg-gray-700 dark:hover:text-indigo-400"
                                        >
                                            <PencilSquareIcon class="h-4 w-4" />
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!products.data?.length">
                                    <td colspan="8" class="px-6 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
                                        No products found. Add your first product to start building the live catalog.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-700">
                        <Pagination :data="products" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
