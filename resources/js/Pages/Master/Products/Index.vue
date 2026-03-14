<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { MagnifyingGlassIcon, PlusIcon, PencilSquareIcon, XMarkIcon, FunnelIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Object,
    filters: Object,
    categories: Array,
    companies: Array,
});

const search = ref(props.filters?.search || '');
const category = ref(props.filters?.category || '');
const company = ref(props.filters?.company || '');
const status = ref(props.filters?.status ?? '');
const showFilters = ref(!!(props.filters?.category || props.filters?.company || props.filters?.status !== undefined && props.filters?.status !== ''));

const hasActiveFilters = computed(() => search.value || category.value || company.value || status.value !== '');

function applyFilters() {
    const params = {};
    if (search.value) params.search = search.value;
    if (category.value) params.category = category.value;
    if (company.value) params.company = company.value;
    if (status.value !== '') params.status = status.value;
    router.get(route('admin.products.index'), params, {
        preserveState: true,
        preserveScroll: true,
    });
}

function clearFilters() {
    search.value = '';
    category.value = '';
    company.value = '';
    status.value = '';
    router.get(route('admin.products.index'), {}, { preserveState: true });
}

function exportUrl(type) {
    const params = new URLSearchParams();
    if (search.value) params.set('search', search.value);
    if (category.value) params.set('category', category.value);
    if (company.value) params.set('company', company.value);
    if (status.value !== '') params.set('status', status.value);
    const qs = params.toString();
    return route(`admin.products.export.${type}`) + (qs ? '?' + qs : '');
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
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">Product Catalog</h2>
                <Link
                    :href="route('admin.products.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors"
                >
                    <PlusIcon class="h-4 w-4 stroke-2" />
                    Add Product
                </Link>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Search & Filters -->
                <div class="mb-5 space-y-3">
                    <div class="flex items-center gap-2">
                        <div class="relative flex-1">
                            <MagnifyingGlassIcon class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Search name, salt, company, HSN, SKU..."
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2.5 pl-10 pr-4 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                            />
                        </div>
                        <button @click="showFilters = !showFilters" class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2.5 text-sm font-medium transition-colors" :class="showFilters ? 'border-indigo-300 dark:border-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300' : 'border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700'">
                            <FunnelIcon class="h-4 w-4" />
                            <span class="hidden sm:inline">Filters</span>
                        </button>
                        <button v-if="hasActiveFilters" @click="clearFilters" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2.5 text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <XMarkIcon class="h-4 w-4" />
                            <span class="hidden sm:inline">Clear</span>
                        </button>

                        <!-- Export buttons -->
                        <a :href="exportUrl('excel')" class="inline-flex items-center gap-1.5 rounded-lg border border-green-300 dark:border-green-700 bg-white dark:bg-gray-800 px-3 py-2.5 text-sm font-medium text-green-700 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/30 transition-colors" title="Export Excel">
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            <span class="hidden sm:inline">Excel</span>
                        </a>
                        <a :href="exportUrl('pdf')" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 dark:border-red-700 bg-white dark:bg-gray-800 px-3 py-2.5 text-sm font-medium text-red-700 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition-colors" title="Open printable PDF export">
                            <ArrowDownTrayIcon class="h-4 w-4" />
                            <span class="hidden sm:inline">PDF</span>
                        </a>
                    </div>

                    <!-- Filter dropdowns -->
                    <div v-show="showFilters" class="grid grid-cols-1 sm:grid-cols-3 gap-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30 p-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Category</label>
                            <select v-model="category" class="w-full rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Categories</option>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Company</label>
                            <select v-model="company" class="w-full rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Companies</option>
                                <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                            <select v-model="status" class="w-full rounded-md border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 py-2 text-sm text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">All Status</option>
                                <option :value="1">Active</option>
                                <option :value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">

                    <!-- Mobile card list (< md) -->
                    <ul class="md:hidden divide-y divide-gray-100 dark:divide-gray-700/60">
                        <li v-if="!products.data?.length" class="p-8 text-center text-sm text-gray-400 dark:text-gray-500">No products found.</li>
                        <li v-for="product in products.data" :key="product.id" class="p-4 flex items-start justify-between gap-3 hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-white leading-tight">{{ product.product_name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">{{ product.salt?.name || '—' }}</p>
                                <div class="flex flex-wrap items-center gap-2 mt-1.5">
                                    <span class="text-xs font-mono text-gray-500 dark:text-gray-400">{{ product.sku }}</span>
                                    <span class="text-xs font-semibold text-gray-900 dark:text-white font-mono">₹{{ product.mrp }}</span>
                                    <span class="text-xs bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 px-1.5 py-0.5 rounded">{{ product.hsn?.code || '—' }}</span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <span :class="product.is_active ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400'" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :style="product.is_active ? 'ring-color: rgb(22 163 74 / 0.1)' : 'ring-color: rgb(220 38 38 / 0.1)'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="product.is_active ? 'bg-green-500' : 'bg-red-500'"></span>
                                    {{ product.is_active ? 'Active' : 'Inactive' }}
                                </span>
                                <Link :href="route('admin.products.edit', product.id)" class="text-xs text-indigo-600 dark:text-indigo-400 font-medium hover:underline">Edit →</Link>
                            </div>
                        </li>
                    </ul>

                    <!-- Desktop table (>= md) -->
                    <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Product</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">SKU</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Company</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">HSN</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">MRP</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">PTR</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                            <tr
                                v-for="product in products.data"
                                :key="product.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors"
                            >
                                <td class="px-6 py-4">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ product.product_name }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ product.salt?.name || '—' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-md bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs font-mono font-medium text-gray-700 dark:text-gray-300">
                                        {{ product.sku }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">{{ product.company?.name || '—' }}</td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-md bg-blue-50 dark:bg-blue-900/30 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300 ring-1 ring-inset ring-blue-600/10 dark:ring-blue-400/20">
                                        {{ product.hsn?.code || '—' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900 dark:text-white font-mono">₹{{ product.mrp }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-500 dark:text-gray-400 font-mono">₹{{ product.ptr }}</td>
                                <td class="px-6 py-4 text-center">
                                    <span
                                        :class="product.is_active
                                            ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400 ring-green-600/10 dark:ring-green-400/20'
                                            : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400 ring-red-600/10 dark:ring-red-400/20'"
                                        class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold ring-1 ring-inset"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :class="product.is_active ? 'bg-green-500' : 'bg-red-500'"></span>
                                        {{ product.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <Link
                                        :href="route('admin.products.edit', product.id)"
                                        class="p-2 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors inline-flex"
                                    >
                                        <PencilSquareIcon class="h-4 w-4" />
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!products.data?.length">
                                <td colspan="8" class="px-6 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
                                    No products found. Add your first product to get started.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div><!-- end desktop table -->

                <Pagination :data="products" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
