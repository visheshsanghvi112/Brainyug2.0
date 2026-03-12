<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { BuildingStorefrontIcon, ArchiveBoxIcon, ArrowRightIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    summary: Array
});
</script>

<template>
    <Head title="Enterprise Network Inventory Position" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-indigo-700 pl-4 bg-indigo-50/50 py-2 inline-block px-4 rounded-r-lg shadow-sm w-full">
                Enterprise Network Inventory Valuation & Asset Position
            </h2>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div v-for="loc in summary" :key="loc.location_type + loc.location_id" 
                class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                <div class="flex items-center gap-4 mb-4">
                    <div class="p-3 rounded-lg" :class="loc.location_type === 'warehouse' ? 'bg-indigo-50 text-indigo-600' : 'bg-emerald-50 text-emerald-600'">
                        <BuildingStorefrontIcon v-if="loc.location_type === 'franchisee'" class="h-6 w-6" />
                        <ArchiveBoxIcon v-else class="h-6 w-6" />
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900">{{ loc.location_name }}</h3>
                        <p class="text-xs text-indigo-700 font-bold uppercase tracking-widest mt-0.5">{{ loc.location_type === 'warehouse' ? 'Principal Asset Distribution Hub' : 'Retail Distribution Node' }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 border-t border-gray-100 pt-5 mt-2">
                    <div>
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">Unique SKUs</p>
                        <p class="text-2xl font-black text-slate-800">{{ loc.unique_products }} <span class="text-xs font-normal text-slate-500 ml-1">Lines</span></p>
                    </div>
                    <div>
                        <p class="text-[10px] text-gray-500 font-bold uppercase tracking-widest mb-1">Total Unit Volume</p>
                        <p class="text-2xl font-black text-slate-800 flex items-end">{{ Number(loc.current_stock).toLocaleString() }} <span class="text-xs font-normal text-slate-500 ml-1 mb-1 relative top-[-1px]">Units</span></p>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-gray-50/50 relative">
                    <Link :href="route('reports.stock.current', { location_type: loc.location_type, location_id: loc.location_id })" 
                        class="flex items-center justify-between w-full text-sm font-bold text-indigo-700 hover:text-indigo-900 group bg-indigo-50/50 p-3 rounded-lg border border-indigo-100 transition-colors">
                        Launch Asset Ledger Breakdown
                        <ArrowRightIcon class="h-4 w-4 transform group-hover:translate-x-1 transition-transform stroke-2" />
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
