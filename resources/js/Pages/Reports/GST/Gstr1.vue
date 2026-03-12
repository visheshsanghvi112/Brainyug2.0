<script setup>
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref, watch } from 'vue';
import { CalculatorIcon, DocumentArrowDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    retail_sales: Array,
    filters: Object
});

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);

const updateFilter = () => {
    router.get(route('reports.gst.gstr1'), { 
        start_date: startDate.value, 
        end_date: endDate.value 
    }, { preserveState: true });
};

const exportCsv = () => {
    let url = route('reports.gst.gstr1');
    url += `?start_date=${startDate.value}&end_date=${endDate.value}&export=true`;
    window.location.href = url;
};
</script>

<template>
    <Head title="GSTR-1 Outward Supplies" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-black text-gray-900 border-l-4 border-indigo-600 pl-4 flex items-center gap-3">
                    GSTR-1: Outward Supplies 
                    <CalculatorIcon class="h-6 w-6 text-indigo-400" />
                </h2>
                
                <div class="flex gap-4 items-center bg-white p-3 rounded-xl shadow-sm border border-gray-100">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Period</span>
                    <input type="date" v-model="startDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-indigo-500">
                    <span class="text-gray-400">→</span>
                    <input type="date" v-model="endDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-indigo-500">
                    
                    <button @click="exportCsv" class="bg-indigo-600 text-white p-2 rounded-lg hover:bg-indigo-700 h-10 w-10 flex items-center justify-center transition-all hover:shadow-lg" title="Export to CSV">
                        <DocumentArrowDownIcon class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 overflow-hidden relative">
                <div class="absolute -right-4 -top-4 text-indigo-50 opacity-10">
                    <CalculatorIcon class="h-24 w-24" />
                </div>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-1 italic">Total Taxable Value</p>
                <h3 class="text-3xl font-black text-gray-900 font-mono">₹{{ Number(retail_sales.reduce((sum, i) => sum + Number(i.taxable_value), 0)).toFixed(2) }}</h3>
            </div>
            <div class="bg-indigo-600 p-6 rounded-2xl shadow-xl shadow-indigo-100 text-white overflow-hidden relative">
                 <p class="text-xs font-bold text-indigo-200 uppercase tracking-widest mb-1 italic">Expected GST Liability</p>
                <h3 class="text-3xl font-black text-white font-mono">₹{{ Number(retail_sales.reduce((sum, i) => sum + Number(i.total_gst), 0)).toFixed(2) }}</h3>
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
                        <th class="px-8 py-5 text-right font-black text-indigo-600">Total GST (₹)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-if="retail_sales.length === 0">
                        <td colspan="4" class="px-8 py-20 text-center text-gray-400 italic">No sales found for this period.</td>
                    </tr>
                    <tr v-for="item in retail_sales" :key="item.hsn_code + item.rate" class="hover:bg-indigo-50/10 transition-colors">
                        <td class="px-8 py-5 whitespace-nowrap">
                            <div class="text-sm font-black text-gray-900">{{ item.hsn_code }}</div>
                            <div class="text-[10px] text-gray-400 uppercase font-bold">Pharmaceutical Products</div>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-center text-sm font-bold text-gray-600">
                            {{ Number(item.rate) }}%
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono font-bold text-gray-700">
                            {{ Number(item.taxable_value).toFixed(2) }}
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono font-black text-indigo-600">
                            {{ Number(item.total_gst).toFixed(2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>
        
        <div class="mt-8 p-6 bg-amber-50 rounded-2xl border border-amber-100 flex items-start gap-4">
             <CalculatorIcon class="h-6 w-6 text-amber-600 mt-1 shrink-0" />
             <div>
                <p class="text-sm font-black text-amber-900 mb-1 font-serif uppercase tracking-widest">Filing Note</p>
                <p class="text-xs text-amber-800 leading-relaxed font-semibold italic opacity-80">GSTR-1 data is aggregated by HSN and Tax Rate per 2026 government mandates. This excludes nil-rated and exempt supplies. Ensure all B2B and B2C splits are reconciled before final filing.</p>
             </div>
        </div>
    </AuthenticatedLayout>
</template>
