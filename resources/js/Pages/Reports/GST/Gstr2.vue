<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';
import { CalculatorIcon, DocumentArrowDownIcon, ShoppingCartIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    purchases: Array,
    filters: Object
});

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);

const updateFilter = () => {
    router.get(route('reports.gst.gstr2'), { 
        start_date: startDate.value, 
        end_date: endDate.value 
    }, { preserveState: true });
};
</script>

<template>
    <Head title="GSTR-2 Inward Supplies" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-black text-gray-900 border-l-4 border-emerald-600 pl-4 flex items-center gap-3">
                    GSTR-2: Inward Supplies 
                    <ShoppingCartIcon class="h-6 w-6 text-emerald-400" />
                </h2>
                
                <div class="flex gap-4 items-center bg-white p-3 rounded-xl shadow-sm border border-gray-100">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Period</span>
                    <input type="date" v-model="startDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-emerald-500">
                    <span class="text-gray-400">→</span>
                    <input type="date" v-model="endDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-emerald-500">
                    
                    <button class="bg-emerald-600 text-white p-2 rounded-lg hover:bg-emerald-700 h-10 w-10 flex items-center justify-center transition-all hover:shadow-lg">
                        <DocumentArrowDownIcon class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 overflow-hidden relative">
                <div class="absolute -right-4 -top-4 text-emerald-50 opacity-10">
                    <CalculatorIcon class="h-24 w-24" />
                </div>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1 italic">Total Input Tax Credit (ITC)</p>
                <h3 class="text-3xl font-black text-gray-900 font-mono">₹{{ Number(purchases.reduce((sum, i) => sum + Number(i.total_gst), 0)).toFixed(2) }}</h3>
            </div>
            <div class="bg-emerald-600 p-6 rounded-2xl shadow-xl shadow-emerald-100 text-white overflow-hidden relative">
                 <p class="text-xs font-bold text-emerald-200 uppercase tracking-widest mb-1 italic">Total Taxable Purchase Value</p>
                <h3 class="text-3xl font-black text-white font-mono">₹{{ Number(purchases.reduce((sum, i) => sum + Number(i.taxable_value), 0)).toFixed(2) }}</h3>
            </div>
        </div>

        <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-black text-gray-500 uppercase tracking-widest">
                    <tr>
                        <th class="px-8 py-5 text-left">HSN Summary</th>
                        <th class="px-8 py-5 text-center">Tax Rate (%)</th>
                        <th class="px-8 py-5 text-right">Taxable Value (₹)</th>
                        <th class="px-8 py-5 text-right font-black text-emerald-600">Input Tax (₹)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-if="purchases.length === 0">
                        <td colspan="4" class="px-8 py-20 text-center text-gray-400 italic">No inward supplies found for this period.</td>
                    </tr>
                    <tr v-for="item in purchases" :key="item.hsn_code + item.rate" class="hover:bg-emerald-50/10 transition-colors">
                        <td class="px-8 py-5 whitespace-nowrap">
                            <div class="text-sm font-black text-gray-900 font-serif leading-tight">{{ item.hsn_code }}</div>
                            <div class="text-[10px] text-gray-400 uppercase font-extrabold tracking-tighter italic">Pharma HSN Code Catalog</div>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-center text-sm font-bold text-gray-600">
                            {{ Number(item.rate) }}%
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono font-bold text-gray-700">
                            {{ Number(item.taxable_value).toFixed(2) }}
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono font-black text-emerald-600">
                            {{ Number(item.total_gst).toFixed(2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>
        
        <div class="mt-8 p-6 bg-emerald-50 rounded-2xl border border-emerald-100 flex items-start gap-4">
             <ShoppingCartIcon class="h-6 w-6 text-emerald-600 mt-1 shrink-0" />
             <div>
                <p class="text-sm font-black text-emerald-900 mb-1 font-serif uppercase tracking-widest">ITC Reconciliation</p>
                <p class="text-xs text-emerald-800 leading-relaxed font-semibold italic opacity-80">This report helps in reconciling your Input Tax Credit (ITC). It provides a high-level summary of your inward invoices based on HSN and GST Rate. Ensure all purchase returns have been correctly accounted for in the matching period.</p>
             </div>
        </div>
    </AuthenticatedLayout>
</template>
