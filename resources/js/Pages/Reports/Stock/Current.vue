<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';

const props = defineProps({
    stock: Object,
    location: Object,
    franchisees: Array,
    filters: Object
});

const locFilter = ref(props.filters.location_id || props.location.id);
const typeFilter = ref(props.filters.location_type || props.location.type);

watch([locFilter, typeFilter], () => {
    router.get(route('reports.stock.current'), { 
        location_type: typeFilter.value, 
        location_id: locFilter.value 
    }, { preserveState: true });
});

const exportCsv = () => {
    let url = route('reports.stock.current');
    url += `?location_type=${typeFilter.value}&location_id=${locFilter.value}&export=true`;
    window.location.href = url;
};
</script>

<template>
    <Head title="Current Stock Inventory" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-indigo-500 pl-4">
                    Current Stock: <span class="text-indigo-600">{{ location.name }}</span>
                </h2>
                
                <div class="flex gap-4">
                    <select v-model="typeFilter" class="rounded-md border-gray-300 text-sm focus:ring-indigo-500">
                        <option value="warehouse">HO Warehouse</option>
                        <option value="franchisee">Franchisee Store</option>
                    </select>
                    
                    <select v-if="typeFilter === 'franchisee'" v-model="locFilter" class="rounded-md border-gray-300 text-sm focus:ring-indigo-500 min-w-[200px]">
                        <option v-for="f in franchisees" :key="f.id" :value="f.id">{{ f.name }}</option>
                    </select>
                    
                    <button @click="exportCsv" class="ml-2 bg-slate-800 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-sm hover:bg-slate-900 transition-colors">
                        Export CSV
                    </button>
                </div>
            </div>
        </template>

        <div class="bg-white shadow rounded-lg border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Product Description</th>
                        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Batch No</th>
                        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Expiry</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">MRP (₹)</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">In Stock</th>
                        <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Value (MRP)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="item in stock.data" :key="item.product_id + item.batch_no" class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="text-sm font-bold text-gray-900">{{ item.product.product_name }}</div>
                            <div class="text-xs text-gray-500">{{ item.product.company?.name }} | {{ item.product.category?.name }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-600">
                            {{ item.batch_no }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                            <span :class="new Date(item.expiry_date) < new Date() ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'" class="px-2 py-0.5 rounded text-xs font-bold">
                                {{ item.expiry_date }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-700">
                            ₹{{ Number(item.mrp).toFixed(2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-sm font-bold" :class="Number(item.stock) < 10 ? 'text-rose-600' : 'text-emerald-600'">
                                {{ Number(item.stock).toLocaleString() }}
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-gray-900">
                            ₹{{ (Number(item.stock) * Number(item.mrp)).toFixed(2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>

        <div v-if="stock.total > stock.per_page" class="mt-6 flex justify-center">
            <!-- Simple Pagination fallback if component not found -->
            <div class="flex gap-1">
                <template v-for="(link, index) in (stock.links || []).filter(Boolean)" :key="link.label || `stock-current-link-${index}`">
                    <Link v-if="link.url" :href="link.url" v-html="link.label"
                        class="px-3 py-1 border rounded text-sm"
                        :class="link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'" />
                    <span v-else v-html="link.label" class="px-3 py-1 border rounded text-sm text-gray-300" />
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
