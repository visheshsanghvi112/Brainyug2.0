<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref } from 'vue';
import { DocumentArrowDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    summary: Object,
    filters: Object,
    complianceNotes: Array,
});

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);

const updateFilter = () => {
    router.get(route('reports.gst.gstr3b'), {
        start_date: startDate.value,
        end_date: endDate.value,
    }, { preserveState: true });
};

const exportCsv = () => {
    let url = route('reports.gst.gstr3b');
    url += `?start_date=${startDate.value}&end_date=${endDate.value}&export=true`;
    window.location.href = url;
};

const exportAs = (format) => {
    let url = route('reports.gst.gstr3b');
    url += `?start_date=${startDate.value}&end_date=${endDate.value}&export_format=${format}`;
    window.location.href = url;
};

const fmt = (v) => Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head title="GSTR-3B Summary" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-2xl font-black text-gray-900 border-l-4 border-rose-600 pl-4">GSTR-3B: Liability vs ITC Summary</h2>
                <div class="flex gap-4 items-center bg-white p-3 rounded-xl shadow-sm border border-gray-100">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">Period</span>
                    <input type="date" v-model="startDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-rose-500">
                    <span class="text-gray-400">→</span>
                    <input type="date" v-model="endDate" @change="updateFilter" class="border-gray-200 rounded-lg text-sm focus:ring-rose-500">

                    <button @click="exportCsv" class="rounded-lg bg-gray-700 px-3 py-2 text-xs font-bold text-white hover:bg-gray-800">CSV</button>
                    <button @click="exportAs('excel')" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Excel</button>
                    <button @click="exportAs('pdf')" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-bold text-white hover:bg-rose-700 inline-flex items-center gap-1">
                        <DocumentArrowDownIcon class="h-4 w-4" /> PDF
                    </button>
                </div>
            </div>
            <div class="mt-3 inline-flex rounded-lg border border-gray-200 bg-white p-1 text-sm">
                <Link :href="route('reports.gst.gstr1', { start_date: startDate, end_date: endDate })" class="rounded-md px-3 py-1.5 font-semibold text-gray-600 hover:bg-gray-50">GSTR-1</Link>
                <Link :href="route('reports.gst.gstr2', { start_date: startDate, end_date: endDate })" class="rounded-md px-3 py-1.5 font-semibold text-gray-600 hover:bg-gray-50">GSTR-2/ITC</Link>
                <Link :href="route('reports.gst.gstr3b', { start_date: startDate, end_date: endDate })" class="rounded-md bg-rose-600 px-3 py-1.5 font-semibold text-white">GSTR-3B Summary</Link>
            </div>
        </template>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-widest font-bold text-gray-500">Outward GST Liability</p>
                <p class="mt-1 text-2xl font-black text-rose-700">₹{{ fmt(summary?.outward?.total_gst) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs uppercase tracking-widest font-bold text-gray-500">Eligible ITC</p>
                <p class="mt-1 text-2xl font-black text-emerald-700">₹{{ fmt(summary?.itc?.total_gst) }}</p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-5 shadow-sm">
                <p class="text-xs uppercase tracking-widest font-bold text-rose-500">Net Tax Payable</p>
                <p class="mt-1 text-2xl font-black text-rose-700">₹{{ fmt(summary?.net_tax_payable) }}</p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <p class="text-xs uppercase tracking-widest font-bold text-emerald-600">ITC Carry Forward</p>
                <p class="mt-1 text-2xl font-black text-emerald-700">₹{{ fmt(summary?.itc_carry_forward) }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-black text-gray-900 mb-3 uppercase tracking-widest">Table 3.1 Approx - Outward</h3>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">Taxable Value</p><p class="font-bold">₹{{ fmt(summary?.outward?.taxable_value) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">Total GST</p><p class="font-bold">₹{{ fmt(summary?.outward?.total_gst) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">CGST</p><p class="font-bold">₹{{ fmt(summary?.outward?.cgst_amount) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">SGST</p><p class="font-bold">₹{{ fmt(summary?.outward?.sgst_amount) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3 col-span-2"><p class="text-gray-500">IGST</p><p class="font-bold">₹{{ fmt(summary?.outward?.igst_amount) }}</p></div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-black text-gray-900 mb-3 uppercase tracking-widest">Table 4 Approx - ITC</h3>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">Taxable Value</p><p class="font-bold">₹{{ fmt(summary?.itc?.taxable_value) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">Total ITC</p><p class="font-bold">₹{{ fmt(summary?.itc?.total_gst) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">CGST ITC</p><p class="font-bold">₹{{ fmt(summary?.itc?.cgst_amount) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3"><p class="text-gray-500">SGST ITC</p><p class="font-bold">₹{{ fmt(summary?.itc?.sgst_amount) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3 col-span-2"><p class="text-gray-500">IGST ITC</p><p class="font-bold">₹{{ fmt(summary?.itc?.igst_amount) }}</p></div>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
            <p class="text-sm font-bold text-amber-900 mb-2">Compliance Notes</p>
            <ul class="text-xs text-amber-800 space-y-1">
                <li v-for="(note, idx) in (complianceNotes || [])" :key="`gstr3b-note-${idx}`">- {{ note }}</li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
