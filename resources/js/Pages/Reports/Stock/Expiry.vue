<script setup>
import { Head, router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';
import { ExclamationTriangleIcon, ArchiveBoxIcon, BuildingStorefrontIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    expired: Array,
    months: Number
});

const monthFilter = ref(props.months);

watch(monthFilter, (val) => {
    router.get(route('reports.stock.expiry'), { months: val }, { preserveState: true });
});
</script>

<template>
    <Head title="Expiry Alerts Report" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-rose-500 pl-4 flex items-center gap-3">
                    Expiry Alert: <span class="text-rose-600">Next {{ months }} Months</span>
                    <ExclamationTriangleIcon class="h-6 w-6 text-rose-500 animate-pulse" />
                </h2>
                
                <div class="flex gap-4 items-center">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Threshold</span>
                    <select v-model="monthFilter" class="rounded-md border-gray-300 text-sm focus:ring-rose-500">
                        <option :value="1">Next 1 month</option>
                        <option :value="3">Next 3 months</option>
                        <option :value="6">Next 6 months</option>
                        <option :value="12">Next 12 months</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-rose-50 p-6 rounded-xl border border-rose-200">
                <p class="text-xs font-bold text-rose-400 uppercase truncate">Total Expiring Units</p>
                <h3 class="text-2xl font-black text-rose-700">{{ expired.reduce((sum, i) => sum + Number(i.stock), 0).toLocaleString() }}</h3>
            </div>
            <div class="bg-amber-50 p-6 rounded-xl border border-amber-200">
                <p class="text-xs font-bold text-amber-400 uppercase truncate">Unique Batches at Risk</p>
                <h3 class="text-2xl font-black text-amber-700">{{ expired.length }}</h3>
            </div>
        </div>

        <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-900 text-white">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Product Name</th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Batch No</th>
                        <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Expiry Date</th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Location</th>
                        <th class="px-6 py-4 text-right text-xs font-bold uppercase tracking-wider">Current Stock</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-if="expired.length === 0">
                        <td colspan="5" class="px-6 py-12 text-center text-gray-400 font-bold italic">
                            Amazing! No products are expiring within this window.
                        </td>
                    </tr>
                    <tr v-for="item in expired" :key="item.product_id + item.batch_no" class="hover:bg-rose-50/20 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                            {{ item.product.product_name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500">
                            {{ item.batch_no }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                            <span :class="new Date(item.expiry_date) < new Date() ? 'text-rose-600 font-extrabold' : 'text-amber-600 font-bold'">
                                {{ item.expiry_date }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500">
                           <div class="flex items-center gap-2">
                                <ArchiveBoxIcon v-if="item.location_type === 'warehouse'" class="h-4 w-4 text-indigo-400" />
                                <BuildingStorefrontIcon v-else class="h-4 w-4 text-emerald-400" />
                                {{ item.location_type === 'warehouse' ? 'HO Warehouse' : 'Franchisee ID:' + item.location_id }}
                           </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-black text-rose-600">
                            {{ Number(item.stock).toLocaleString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>
    </AuthenticatedLayout>
</template>
