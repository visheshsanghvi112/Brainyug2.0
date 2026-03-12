<script setup>
import { Head, router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';
import { TruckIcon, ArchiveBoxIcon, BuildingStorefrontIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    stock: Object,
    days: Number
});

const dayFilter = ref(props.days);

watch(dayFilter, (val) => {
    router.get(route('reports.stock.non-moving'), { days: val }, { preserveState: true });
});
</script>

<template>
    <Head title="Non-Moving Stock Report" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-black text-gray-900 border-l-4 border-slate-500 pl-4 flex items-center gap-3">
                    Dead Stock Analysis: <span class="text-slate-600">No sales in {{ days }}+ days</span>
                </h2>
                
                <div class="flex gap-4 items-center">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Inactivity Period</span>
                    <select v-model="dayFilter" class="rounded-md border-gray-300 text-sm focus:ring-slate-500">
                        <option :value="30">Last 30 Days</option>
                        <option :value="60">Last 60 Days</option>
                        <option :value="90">Last 90 Days</option>
                        <option :value="180">Last 180 Days (6 Months)</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="bg-amber-50 border border-amber-100 p-4 rounded-xl mb-8 flex items-center gap-4 text-amber-900">
            <TruckIcon class="h-6 w-6 text-amber-600" />
            <p class="text-sm font-semibold italic opacity-80">Products with current stock holding but zero retail movements are listed here. High values here represent blocked capital.</p>
        </div>

        <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-black text-gray-400 uppercase tracking-widest">
                    <tr>
                        <th class="px-8 py-5 text-left">Product / Brand</th>
                        <th class="px-8 py-5 text-left">Location</th>
                        <th class="px-8 py-5 text-right font-black text-slate-800">Dead Stock Qty</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-if="stock.data.length === 0">
                        <td colspan="3" class="px-8 py-20 text-center text-gray-300 font-bold italic">No non-moving stock found. Your inventory turnover is optimal!</td>
                    </tr>
                    <tr v-for="item in stock.data" :key="item.product_id + item.location_id" class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-8 py-5">
                            <div class="text-sm font-black text-gray-900">{{ item.product.product_name }}</div>
                            <div class="text-xs text-gray-400">{{ item.product.company?.name }}</div>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-xs text-gray-500">
                           <div class="flex items-center gap-2">
                                <ArchiveBoxIcon v-if="item.location_type === 'warehouse'" class="h-4 w-4 text-indigo-400" />
                                <BuildingStorefrontIcon v-else class="h-4 w-4 text-emerald-400" />
                                {{ item.location_type === 'warehouse' ? 'HO Warehouse' : 'Franchisee ID: ' + item.location_id }}
                           </div>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-black text-slate-700 font-mono">
                            {{ Number(item.current_stock).toLocaleString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>

        <div v-if="stock.total > stock.per_page" class="mt-6 flex justify-center">
            <div class="flex gap-1">
                <template v-for="(link, index) in (stock.links || []).filter(Boolean)" :key="link.label || `stock-nonmoving-link-${index}`">
                    <Link v-if="link.url" :href="link.url" v-html="link.label"
                        class="px-3 py-1 border rounded text-sm transition-all"
                        :class="link.active ? 'bg-slate-800 text-white' : 'bg-white text-gray-500 hover:bg-gray-100'" />
                    <span v-else v-html="link.label" class="px-3 py-1 border rounded text-sm text-gray-300" />
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
