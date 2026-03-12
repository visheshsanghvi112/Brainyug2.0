<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    DocumentTextIcon, ArrowLeftIcon, PlusCircleIcon, TrashIcon,
    InformationCircleIcon, TruckIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    invoice:       { type: Object, default: null },
    suppliers:     Array,
    products:      Array,
    hsn_codes:     Array,
    financialYear: String,
});

const isEdit = computed(() => !!props.invoice?.id);
const today  = new Date().toISOString().split('T')[0];

function blankItem() {
    return {
        product_id: '', batch_no: '', mfg_date: '', expiry_date: '',
        qty: 1, free_qty: 0, mrp: 0, rate: 0,
        discount_percent: 0, gst_percent: 0, hsn_id: '',
    };
}

const form = useForm({
    supplier_id:         props.invoice?.supplier_id         || '',
    supplier_invoice_no: props.invoice?.supplier_invoice_no || '',
    invoice_date:        props.invoice?.invoice_date        || today,
    received_date:       props.invoice?.received_date       || today,
    tax_type:            props.invoice?.tax_type            || 'intra_state',
    due_days:            props.invoice?.due_days            ?? 0,
    transporter:         props.invoice?.transporter         || '',
    lr_number:           props.invoice?.lr_number           || '',
    notes:               props.invoice?.notes               || '',
    items: props.invoice?.items?.length
        ? props.invoice.items.map(i => ({
            product_id:       i.product_id        || '',
            batch_no:         i.batch_no          || '',
            mfg_date:         i.mfg_date          || '',
            expiry_date:      i.expiry_date        || '',
            qty:              parseFloat(i.qty)    || 1,
            free_qty:         parseFloat(i.free_qty) || 0,
            mrp:              parseFloat(i.mrp)    || 0,
            rate:             parseFloat(i.rate)   || 0,
            discount_percent: parseFloat(i.discount_percent) || 0,
            gst_percent:      parseFloat(i.gst_percent) || 0,
            hsn_id:           i.hsn_id            || '',
        }))
        : [blankItem()],
});

// ── Item operations ────────────────────────────────────────────────────────

function addItem()           { form.items.push(blankItem()); }
function removeItem(index)   { if (form.items.length > 1) form.items.splice(index, 1); }

function onProductChange(item) {
    const product = props.products?.find(p => p.id == item.product_id);
    if (!product) return;
    item.mrp    = parseFloat(product.mrp) || 0;
    item.hsn_id = product.hsn_id || '';
    const hsn   = props.hsn_codes?.find(h => h.id == product.hsn_id);
    if (hsn) item.gst_percent = parseFloat(hsn.gst_rate) || 0;
}

// ── Per-row calculations ───────────────────────────────────────────────────

const lineGross    = i => (i.qty  || 0) * (i.rate || 0);
const lineDiscount = i => lineGross(i) * ((i.discount_percent || 0) / 100);
const lineTaxable  = i => lineGross(i) - lineDiscount(i);
const lineGstAmt   = i => lineTaxable(i) * ((i.gst_percent || 0) / 100);
const lineTotal    = i => lineTaxable(i) + lineGstAmt(i);

// ── Invoice-level summary ──────────────────────────────────────────────────

const summary = computed(() => {
    let subtotal = 0, totalDiscount = 0, totalGst = 0;
    for (const item of form.items) {
        subtotal      += lineTaxable(item);
        totalDiscount += lineDiscount(item);
        totalGst      += lineGstAmt(item);
    }
    const beforeRound = subtotal + totalGst;
    const roundOff    = Math.round(beforeRound) - beforeRound;
    const halfGst     = totalGst / 2;
    return {
        subtotal, totalDiscount, totalGst,
        cgst:       form.tax_type === 'intra_state' ? halfGst  : 0,
        sgst:       form.tax_type === 'intra_state' ? halfGst  : 0,
        igst:       form.tax_type === 'inter_state' ? totalGst : 0,
        roundOff,
        netPayable: beforeRound + roundOff,
    };
});

// Due date display
const dueDateDisplay = computed(() => {
    if (!form.due_days || form.due_days <= 0) return null;
    const base = new Date(form.invoice_date || today);
    base.setDate(base.getDate() + Number(form.due_days));
    return base.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
});

function submit() {
    isEdit.value
        ? form.put(route('admin.purchase-invoices.update', props.invoice.id))
        : form.post(route('admin.purchase-invoices.store'));
}
</script>

<template>
    <Head :title="isEdit ? 'Edit Purchase Invoice' : 'New Purchase Invoice'" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('admin.purchase-invoices.index')"
                    class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300 transition">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div class="flex items-center gap-3">
                    <DocumentTextIcon class="h-7 w-7 text-indigo-500" />
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">
                            {{ isEdit ? 'Edit Purchase Invoice' : 'New Purchase Invoice' }}
                        </h2>
                        <p class="text-xs text-gray-400 mt-0.5">FY {{ financialYear }}</p>
                    </div>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-screen-xl px-4 sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-5" autocomplete="off">

                    <!-- ── SECTION 1: Invoice Header ─────────────────────── -->
                    <div class="rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">
                        <div class="flex items-center gap-2 px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                            <InformationCircleIcon class="h-5 w-5 text-indigo-400" />
                            <h3 class="font-semibold text-gray-700 dark:text-gray-200 text-sm">Invoice Details</h3>
                        </div>
                        <div class="p-6 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">

                            <!-- Supplier (spans 2 cols on lg) -->
                            <div class="lg:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                    Supplier <span class="text-red-500">*</span>
                                </label>
                                <select v-model="form.supplier_id" required
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">— Select Supplier —</option>
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">
                                        {{ s.name }}{{ s.gst_number ? ` (${s.gst_number})` : '' }}
                                    </option>
                                </select>
                                <p v-if="form.errors.supplier_id" class="text-red-500 text-xs mt-1">{{ form.errors.supplier_id }}</p>
                            </div>

                            <!-- Supplier Invoice No -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                    Supplier Invoice No.
                                </label>
                                <input v-model="form.supplier_invoice_no" type="text" placeholder="e.g. INV/2526/001"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <p v-if="form.errors.supplier_invoice_no" class="text-red-500 text-xs mt-1">{{ form.errors.supplier_invoice_no }}</p>
                            </div>

                            <!-- GST Type -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                    GST Type <span class="text-red-500">*</span>
                                </label>
                                <select v-model="form.tax_type" required
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="intra_state">Intra-State (CGST + SGST)</option>
                                    <option value="inter_state">Inter-State (IGST)</option>
                                </select>
                            </div>

                            <!-- Invoice Date -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                    Invoice Date <span class="text-red-500">*</span>
                                </label>
                                <input v-model="form.invoice_date" type="date" required
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>

                            <!-- Received Date -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                    Received / GRN Date
                                </label>
                                <input v-model="form.received_date" type="date"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>

                            <!-- Credit Days -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                    Credit Days <span class="normal-case font-normal text-gray-400">(0 = cash)</span>
                                </label>
                                <input v-model.number="form.due_days" type="number" min="0" max="365"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                                <p v-if="dueDateDisplay" class="text-xs text-indigo-500 dark:text-indigo-400 mt-1 font-medium">
                                    Due: {{ dueDateDisplay }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ── SECTION 2: Logistics ─────────────────────────── -->
                    <div class="rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">
                        <div class="flex items-center gap-2 px-6 py-3 border-b border-gray-100 dark:border-gray-700">
                            <TruckIcon class="h-5 w-5 text-indigo-400" />
                            <h3 class="font-semibold text-gray-700 dark:text-gray-200 text-sm">Logistics & Notes <span class="font-normal text-gray-400">(Optional)</span></h3>
                        </div>
                        <div class="p-6 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">Transporter</label>
                                <input v-model="form.transporter" type="text" placeholder="e.g. DTDC / Self"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">LR / Lorry Receipt No.</label>
                                <input v-model="form.lr_number" type="text" placeholder="Lorry Receipt No."
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">Remarks / Notes</label>
                                <textarea v-model="form.notes" rows="1" placeholder="Any notes to record…"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 resize-none"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- ── SECTION 3: Line Items (Card layout) ─────────── -->
                    <div class="rounded-2xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">

                        <!-- Section header -->
                        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                            <div>
                                <h3 class="font-semibold text-gray-700 dark:text-gray-200">Line Items</h3>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ form.items.length }} item{{ form.items.length !== 1 ? 's' : '' }}
                                </p>
                            </div>
                            <button type="button" @click="addItem"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 px-4 py-2 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition">
                                <PlusCircleIcon class="h-4 w-4" /> Add Item
                            </button>
                        </div>

                        <!-- One card per item -->
                        <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
                            <div v-for="(item, index) in form.items" :key="index"
                                class="p-4 sm:p-5 transition-colors"
                                :class="item.expiry_date && item.expiry_date < today
                                    ? 'bg-red-50/50 dark:bg-red-900/10'
                                    : 'hover:bg-slate-50/60 dark:hover:bg-gray-700/20'">

                                <!-- Card top: item label + expired badge + delete -->
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-bold text-indigo-400 dark:text-indigo-500 uppercase tracking-widest">
                                        Item {{ index + 1 }}
                                    </span>
                                    <div class="flex items-center gap-2">
                                        <span v-if="item.expiry_date && item.expiry_date < today"
                                            class="inline-flex items-center gap-1 text-xs font-semibold text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/30 px-2.5 py-0.5 rounded-full">
                                            ⚠ Expired Batch
                                        </span>
                                        <button v-if="form.items.length > 1" type="button" @click="removeItem(index)"
                                            class="text-red-400 hover:text-red-600 p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                            <TrashIcon class="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>

                                <!-- Product selector: full width -->
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                        Product <span class="text-red-500">*</span>
                                    </label>
                                    <select v-model="item.product_id" @change="onProductChange(item)" required
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="">— Select Product —</option>
                                        <option v-for="p in products" :key="p.id" :value="p.id">
                                            {{ p.product_name }}{{ p.sku ? ` · ${p.sku}` : '' }}
                                        </option>
                                    </select>
                                </div>

                                <!-- Batch / Mfg / Expiry row -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            Batch No <span class="text-red-500">*</span>
                                        </label>
                                        <input v-model="item.batch_no" type="text" required placeholder="e.g. B250601"
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            Mfg Date
                                        </label>
                                        <input v-model="item.mfg_date" type="date"
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            Expiry Date
                                        </label>
                                        <input v-model="item.expiry_date" type="date"
                                            :class="['w-full rounded-lg border text-sm p-2.5 transition focus:ring-2',
                                                item.expiry_date && item.expiry_date < today
                                                    ? 'border-red-400 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 focus:border-red-400 focus:ring-red-300'
                                                    : 'border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white focus:border-indigo-500 focus:ring-indigo-200']" />
                                    </div>
                                </div>

                                <!-- Qty / Free / MRP / Rate / Disc% / GST% row -->
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
                                    <!-- Qty -->
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            Qty <span class="text-red-500">*</span>
                                        </label>
                                        <input v-model.number="item.qty" type="number" min="0.01" step="0.01" required
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 text-right focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <!-- Free Qty -->
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            Free
                                        </label>
                                        <input v-model.number="item.free_qty" type="number" min="0" step="0.01"
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 text-right focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <!-- MRP -->
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            MRP ₹ <span class="text-red-500">*</span>
                                        </label>
                                        <input v-model.number="item.mrp" type="number" min="0" step="0.01" required
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 text-right focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <!-- Purchase Rate -->
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            Rate ₹ <span class="text-red-500">*</span>
                                        </label>
                                        <input v-model.number="item.rate" type="number" min="0" step="0.01" required
                                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 text-right focus:border-indigo-500 focus:ring-indigo-500" />
                                    </div>
                                    <!-- Discount % -->
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            Disc %
                                        </label>
                                        <div class="relative">
                                            <input v-model.number="item.discount_percent" type="number" min="0" max="100" step="0.01"
                                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 pr-7 text-right focus:border-indigo-500 focus:ring-indigo-500" />
                                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400 pointer-events-none">%</span>
                                        </div>
                                    </div>
                                    <!-- GST % -->
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide">
                                            GST % <span class="text-red-500">*</span>
                                        </label>
                                        <div class="relative">
                                            <input v-model.number="item.gst_percent" type="number" min="0" max="100" step="0.01" required
                                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm p-2.5 pr-7 text-right focus:border-indigo-500 focus:ring-indigo-500" />
                                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400 pointer-events-none">%</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Live calc strip -->
                                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 pt-3 border-t border-dashed border-gray-200 dark:border-gray-700 text-xs">
                                    <span class="text-gray-400 dark:text-gray-500">
                                        Gross <span class="font-mono font-semibold text-gray-600 dark:text-gray-300">₹{{ lineGross(item).toFixed(2) }}</span>
                                    </span>
                                    <span v-if="item.discount_percent > 0" class="text-gray-400 dark:text-gray-500">
                                        − Disc <span class="font-mono font-semibold text-red-500">₹{{ lineDiscount(item).toFixed(2) }}</span>
                                    </span>
                                    <span class="text-gray-400 dark:text-gray-500">
                                        = Taxable <span class="font-mono font-semibold text-gray-700 dark:text-gray-200">₹{{ lineTaxable(item).toFixed(2) }}</span>
                                    </span>
                                    <span class="text-gray-400 dark:text-gray-500">
                                        + GST <span class="font-mono font-semibold text-amber-600 dark:text-amber-400">₹{{ lineGstAmt(item).toFixed(2) }}</span>
                                    </span>
                                    <span class="ml-auto flex items-center gap-1.5 font-semibold text-gray-600 dark:text-gray-300">
                                        Line Total
                                        <span class="font-mono text-base font-bold text-indigo-600 dark:text-indigo-400">
                                            ₹{{ lineTotal(item).toFixed(2) }}
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- GST Summary footer -->
                        <div class="border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/60 px-6 py-5">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-6">
                                <!-- Left: tax mode label -->
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <p class="font-semibold text-gray-700 dark:text-gray-200 mb-1">Invoice Summary</p>
                                    <p>{{ form.items.length }} line item{{ form.items.length !== 1 ? 's' : '' }}</p>
                                    <p class="text-xs mt-1 text-indigo-500 dark:text-indigo-400 font-medium">
                                        {{ form.tax_type === 'intra_state' ? 'Intra-State → CGST + SGST' : 'Inter-State → IGST' }}
                                    </p>
                                </div>

                                <!-- Right: amount rows -->
                                <div class="flex flex-col gap-1.5 text-sm w-full sm:w-72">
                                    <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                        <span>Subtotal (Taxable)</span>
                                        <span class="font-mono">₹{{ summary.subtotal.toFixed(2) }}</span>
                                    </div>
                                    <div v-if="summary.totalDiscount > 0" class="flex justify-between text-red-500 dark:text-red-400">
                                        <span>Total Discount</span>
                                        <span class="font-mono">−₹{{ summary.totalDiscount.toFixed(2) }}</span>
                                    </div>

                                    <template v-if="form.tax_type === 'intra_state'">
                                        <div class="flex justify-between text-amber-600 dark:text-amber-400">
                                            <span>CGST</span>
                                            <span class="font-mono">₹{{ summary.cgst.toFixed(2) }}</span>
                                        </div>
                                        <div class="flex justify-between text-amber-600 dark:text-amber-400">
                                            <span>SGST</span>
                                            <span class="font-mono">₹{{ summary.sgst.toFixed(2) }}</span>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div class="flex justify-between text-amber-600 dark:text-amber-400">
                                            <span>IGST</span>
                                            <span class="font-mono">₹{{ summary.igst.toFixed(2) }}</span>
                                        </div>
                                    </template>

                                    <div v-if="summary.roundOff !== 0" class="flex justify-between text-xs text-gray-400 dark:text-gray-500">
                                        <span>Round Off</span>
                                        <span class="font-mono">{{ summary.roundOff >= 0 ? '+' : '' }}{{ summary.roundOff.toFixed(2) }}</span>
                                    </div>

                                    <div class="flex justify-between font-bold text-base text-indigo-700 dark:text-indigo-300 pt-2.5 border-t border-gray-200 dark:border-gray-600 mt-1">
                                        <span>Net Payable</span>
                                        <span class="font-mono">₹{{ Math.round(summary.netPayable).toFixed(2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Actions ───────────────────────────────────────── -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <p v-if="form.items.some(i => i.expiry_date && i.expiry_date < today)"
                            class="text-sm text-red-600 dark:text-red-400 flex items-center gap-1.5 bg-red-50 dark:bg-red-900/20 px-4 py-2 rounded-lg">
                            ⚠ Expired batches detected — save as draft OK, but approval will be blocked.
                        </p>
                        <div v-else></div>

                        <div class="flex items-center gap-3 justify-end">
                            <Link :href="route('admin.purchase-invoices.index')"
                                class="text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition px-4 py-2.5">
                                Cancel
                            </Link>
                            <button type="submit" :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 px-7 py-2.5 text-sm font-semibold text-white shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed">
                                <span v-if="form.processing" class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
                                {{ form.processing ? 'Saving…' : (isEdit ? 'Update Invoice' : 'Save as Draft') }}
                            </button>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
