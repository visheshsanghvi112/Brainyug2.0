<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref } from 'vue';
import { DocumentArrowDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    rows: Array,
    summary: Object,
    filters: Object,
    complianceNotes: Array,
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

const exportAs = (format) => {
    let url = route('reports.gst.gstr1');
    url += `?start_date=${startDate.value}&end_date=${endDate.value}&export_format=${format}`;
    window.location.href = url;
};

const fmt = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head title="GSTR-1 Outward Supplies" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-2xl font-black text-gray-900 border-l-4 border-indigo-600 pl-4">GSTR-1: Outward Supplies</h2>
                <div class="flex gap-4 items-center bg-white p-3 rounded-xl shadow-sm border border-gray-100">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Period</span>
                    <input type="date" v-model="startDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-indigo-500">
                    <span class="text-gray-400">→</span>
                    <input type="date" v-model="endDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-indigo-500">
                    
                    <button @click="exportCsv" class="rounded-lg bg-gray-700 px-3 py-2 text-xs font-bold text-white hover:bg-gray-800">CSV</button>
                    <button @click="exportAs('excel')" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Excel</button>
                    <button @click="exportAs('pdf')" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700 inline-flex items-center gap-1">
                        <DocumentArrowDownIcon class="h-4 w-4" /> PDF
                    </button>
                </div>
            </div>
            <div class="mt-3 inline-flex rounded-lg border border-gray-200 bg-white p-1 text-sm">
                <Link :href="route('reports.gst.gstr1', { start_date: startDate, end_date: endDate })" class="rounded-md bg-indigo-600 px-3 py-1.5 font-semibold text-white">GSTR-1</Link>
                <Link :href="route('reports.gst.gstr2', { start_date: startDate, end_date: endDate })" class="rounded-md px-3 py-1.5 font-semibold text-gray-600 hover:bg-gray-50">GSTR-2/ITC</Link>
                <Link :href="route('reports.gst.gstr3b', { start_date: startDate, end_date: endDate })" class="rounded-md px-3 py-1.5 font-semibold text-gray-600 hover:bg-gray-50">GSTR-3B Summary</Link>
            </div>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white p-5 rounded-xl shadow border border-gray-100">
                <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">Taxable Value</p>
                <p class="text-2xl font-black text-gray-900">₹{{ fmt(summary?.taxable_value) }}</p>
            </div>
            <div class="bg-white p-5 rounded-xl shadow border border-gray-100">
                <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">CGST</p>
                <p class="text-2xl font-black text-indigo-700">₹{{ fmt(summary?.cgst_amount) }}</p>
            </div>
            <div class="bg-white p-5 rounded-xl shadow border border-gray-100">
                <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">SGST</p>
                <p class="text-2xl font-black text-indigo-700">₹{{ fmt(summary?.sgst_amount) }}</p>
            </div>
            <div class="bg-white p-5 rounded-xl shadow border border-gray-100">
                <p class="text-xs uppercase tracking-widest text-gray-500 font-bold">IGST</p>
                <p class="text-2xl font-black text-indigo-700">₹{{ fmt(summary?.igst_amount) }}</p>
            </div>
            <div class="bg-indigo-600 p-5 rounded-xl shadow text-white">
                <p class="text-xs uppercase tracking-widest text-indigo-200 font-bold">Total GST</p>
                <p class="text-2xl font-black">₹{{ fmt(summary?.total_gst) }}</p>
            </div>
        </div>

        <div class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden mb-6">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 text-xs font-black text-gray-500 uppercase tracking-widest">
                    <tr>
                        <th class="px-8 py-5 text-left">HSN Summary</th>
                        <th class="px-8 py-5 text-left">Supply Type</th>
                        <th class="px-8 py-5 text-center">Tax Rate (%)</th>
                        <th class="px-8 py-5 text-right">Taxable Value (₹)</th>
                        <th class="px-8 py-5 text-right">CGST (₹)</th>
                        <th class="px-8 py-5 text-right">SGST (₹)</th>
                        <th class="px-8 py-5 text-right">IGST (₹)</th>
                        <th class="px-8 py-5 text-right font-black text-indigo-600">Total GST (₹)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-if="rows.length === 0">
                        <td colspan="8" class="px-8 py-20 text-center text-gray-400 italic">No outward supplies found for this period.</td>
                    </tr>
                    <tr v-for="item in rows" :key="`${item.hsn_code}-${item.supply_type}-${item.rate}`" class="hover:bg-indigo-50/10 transition-colors">
                        <td class="px-8 py-5 whitespace-nowrap">
                            <div class="text-sm font-black text-gray-900">{{ item.hsn_code }}</div>
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-xs font-semibold text-gray-600">{{ item.supply_type }}</td>
                        <td class="px-8 py-5 whitespace-nowrap text-center text-sm font-bold text-gray-600">
                            {{ Number(item.rate) }}%
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono font-bold text-gray-700">
                            {{ fmt(item.taxable_value) }}
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono text-gray-700">
                            {{ fmt(item.cgst_amount) }}
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono text-gray-700">
                            {{ fmt(item.sgst_amount) }}
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono text-gray-700">
                            {{ fmt(item.igst_amount) }}
                        </td>
                        <td class="px-8 py-5 whitespace-nowrap text-right text-sm font-mono font-black text-indigo-600">
                            {{ fmt(item.total_gst) }}
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>
        
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
            <p class="text-sm font-bold text-amber-900 mb-2">Compliance Notes</p>
            <ul class="text-xs text-amber-800 space-y-1">
                <li v-for="(note, idx) in (complianceNotes || [])" :key="`gstr1-note-${idx}`">- {{ note }}</li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
