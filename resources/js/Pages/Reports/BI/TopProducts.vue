<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';
import { ChartBarSquareIcon, TrophyIcon, ArrowTrendingUpIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Array,
    days: Number
});

const dayFilter = ref(props.days);

watch(dayFilter, (val) => {
    router.get(route('reports.bi.top-products'), { days: val }, { preserveState: true });
});
</script>

<template>
    <Head title="Top Selling Products" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-black text-gray-900 border-l-4 border-amber-500 pl-4 flex items-center gap-3">
                    Business Analytics: <span class="text-amber-600">Top Selling Products</span>
                    <ChartBarSquareIcon class="h-6 w-6 text-amber-500" />
                </h2>
                
                <div class="flex gap-4 items-center bg-white p-2 px-4 rounded-xl shadow-sm border border-gray-100">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Analysis Period</span>
                    <select v-model="dayFilter" class="rounded-md border-transparent text-sm focus:ring-amber-500 font-bold text-gray-700 bg-gray-50">
                        <option :value="7">Last 7 Days</option>
                        <option :value="30">Last 30 Days</option>
                        <option :value="90">Last 90 Days</option>
                        <option :value="365">Last 365 Days</option>
                    </select>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 mt-4">
            <!-- Podium Top 3 -->
            <div v-for="(item, index) in products.slice(0, 3)" :key="item.product_name"
                 class="relative bg-white p-6 rounded-2xl shadow-sm border overflow-hidden flex flex-col items-center text-center transition-all hover:-translate-y-1 hover:shadow-xl"
                 :class="[
                     index === 0 ? 'border-amber-300 ring-2 ring-amber-100' : 
                     index === 1 ? 'border-slate-300' : 'border-orange-200'
                 ]">
                
                 <!-- Ribbon -->
                 <div class="absolute -right-10 top-4 w-32 pb-4 text-center transform text-[10px] font-black tracking-widest uppercase rotate-45"
                      :class="[
                          index === 0 ? 'bg-amber-400 text-amber-900 shadow-md' : 
                          index === 1 ? 'bg-slate-300 text-slate-800' : 'bg-orange-300 text-orange-900'
                      ]">
                      #{{ index + 1 }} Rank
                 </div>

                 <div class="h-16 w-16 rounded-full flex items-center justify-center mb-4"
                      :class="[
                          index === 0 ? 'bg-amber-100 text-amber-600' : 
                          index === 1 ? 'bg-slate-100 text-slate-600' : 'bg-orange-100 text-orange-600'
                      ]">
                     <TrophyIcon class="h-8 w-8" v-if="index === 0" />
                     <ArrowTrendingUpIcon class="h-8 w-8" v-else />
                 </div>

                 <h3 class="font-black text-gray-900 text-lg mb-1 leading-tight">{{ item.product_name }}</h3>
                 <p class="text-xs text-gray-400 mb-6 font-mono">{{ item.sku }}</p>

                 <div class="w-full bg-gray-50 rounded-xl p-3 border border-gray-100">
                     <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-1">Total Revenue</p>
                     <p class="text-xl font-black font-mono"
                        :class="[
                          index === 0 ? 'text-amber-600' : 
                          index === 1 ? 'text-slate-600' : 'text-orange-600'
                        ]">₹{{ Number(item.total_revenue).toLocaleString() }}</p>
                     
                     <div class="mt-3 pt-3 border-t border-gray-200 flex justify-between text-xs">
                         <span class="text-gray-500 font-bold">Volume Sold</span>
                         <span class="font-black text-gray-800">{{ Number(item.total_units_sold).toLocaleString() }} Units</span>
                     </div>
                 </div>
            </div>
        </div>

        <!-- Remaining Top 20 -->
        <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-4 ml-2">Runner Ups (Rank 4 - 20)</h3>
        <div class="bg-white shadow-lg rounded-2xl border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-black text-gray-500 uppercase tracking-widest">
                    <tr>
                        <th class="px-8 py-4 text-center w-16">Rank</th>
                        <th class="px-8 py-4 text-left">Product / SKU</th>
                        <th class="px-8 py-4 text-right">Units Sold</th>
                        <th class="px-8 py-4 text-right">Total Revenue Generated (₹)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-if="products.length <= 3">
                        <td colspan="4" class="px-8 py-16 text-center text-gray-400 italic">No additional products found in this period.</td>
                    </tr>
                    <tr v-for="(item, index) in products.slice(3)" :key="item.product_name" class="hover:bg-amber-50/10 transition-colors">
                        <td class="px-8 py-4 text-center font-black text-gray-300 text-lg">
                            {{ index + 4 }}
                        </td>
                        <td class="px-8 py-4">
                            <div class="text-sm font-black text-gray-800">{{ item.product_name }}</div>
                            <div class="text-[10px] text-gray-400 font-mono">{{ item.sku }}</div>
                        </td>
                        <td class="px-8 py-4 text-right text-sm font-black text-gray-600">
                            {{ Number(item.total_units_sold).toLocaleString() }}
                        </td>
                        <td class="px-8 py-4 text-right text-sm font-mono font-black text-amber-600">
                            {{ Number(item.total_revenue).toLocaleString() }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>
    </AuthenticatedLayout>
</template>
