<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    franchisees: Array,
    suppliers: Array,
    products: Array,
    nextTransactionNumber: String,
    currentFinancialYear: String,
    effectiveFranchiseeId: Number,
});

const form = useForm({
    franchisee_id: props.effectiveFranchiseeId || '',
    supplier_id: '',
    purchase_date: new Date().toISOString().slice(0, 10),
    received_date: '',
    reason_code: 'normal',
    notes: '',
    items: [],
});

const addLine = () => {
    form.items.push({
        product_id: '',
        batch_no: '',
        mfg_date: '',
        expiry_date: '',
        qty: 1,
        free_qty: 0,
        unit: 'pcs',
        mrp: 0,
        rate: 0,
        discount_percent: 0,
        discount_amount: 0,
        gst_percent: 0,
    });
};

const removeLine = (index) => {
    form.items.splice(index, 1);
};

const onProductChange = (index) => {
    const line = form.items[index];
    const p = props.products.find((x) => x.id === line.product_id);
    if (!p) return;

    line.mrp = Number(p.mrp || 0);
    line.rate = Number(p.ptr || p.rate_a || p.pts || p.mrp || 0);
    line.unit = p.unit || 'pcs';
    line.gst_percent = Number((p.hsn?.sgst_percent || 0) + (p.hsn?.cgst_percent || 0));
};

const lineTotal = (line) => {
    const taxable = Math.max(0, (Number(line.qty || 0) * Number(line.rate || 0)) - Number(line.discount_amount || 0));
    const gst = taxable * (Number(line.gst_percent || 0) / 100);
    return taxable + gst;
};

const subtotal = computed(() => form.items.reduce((sum, l) => sum + lineTotal(l), 0));
const showFranchiseePicker = computed(() => !props.effectiveFranchiseeId);
const fmt = (n) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(Number(n || 0));

const submit = () => {
    if (form.items.length === 0) {
        alert('Add at least one item.');
        return;
    }

    form.post(route('admin.franchisee-purchases.store'));
};
</script>

<template>
    <Head title="Create Outside Purchase" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Create Outside Purchase</h2>
        </template>

        <div class="py-6 lg:py-10">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">
                <div class="bg-white rounded-xl border border-gray-100 p-4">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Transaction</label>
                            <input :value="nextTransactionNumber" disabled class="w-full rounded-md border-gray-300 bg-gray-50" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Financial Year</label>
                            <input :value="currentFinancialYear" disabled class="w-full rounded-md border-gray-300 bg-gray-50" />
                        </div>
                        <div v-if="showFranchiseePicker">
                            <label class="block text-sm font-medium mb-1">Franchisee</label>
                            <select v-model="form.franchisee_id" class="w-full rounded-md border-gray-300">
                                <option value="">Select</option>
                                <option v-for="f in franchisees" :key="f.id" :value="f.id">{{ f.shop_name }} ({{ f.shop_code }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Supplier</label>
                            <select v-model="form.supplier_id" class="w-full rounded-md border-gray-300">
                                <option value="">Select</option>
                                <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Purchase Date</label>
                            <input v-model="form.purchase_date" type="date" class="w-full rounded-md border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Received Date</label>
                            <input v-model="form.received_date" type="date" class="w-full rounded-md border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Reason</label>
                            <select v-model="form.reason_code" class="w-full rounded-md border-gray-300">
                                <option value="normal">normal</option>
                                <option value="urgent">urgent</option>
                                <option value="spot">spot</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-semibold text-gray-900">Line Items</h3>
                        <button type="button" @click="addLine" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500">Add Item</button>
                    </div>

                    <div v-for="(line, index) in form.items" :key="index" class="border border-gray-100 rounded-lg p-3 mb-3">
                        <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium mb-1">Product</label>
                                <select v-model="line.product_id" class="w-full rounded-md border-gray-300" @change="onProductChange(index)">
                                    <option value="">Select</option>
                                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.product_name }} ({{ p.sku }})</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Batch</label>
                                <input v-model="line.batch_no" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Qty</label>
                                <input v-model.number="line.qty" type="number" min="0.01" step="0.01" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Free Qty</label>
                                <input v-model.number="line.free_qty" type="number" min="0" step="0.01" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Rate</label>
                                <input v-model.number="line.rate" type="number" min="0" step="0.01" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">MRP</label>
                                <input v-model.number="line.mrp" type="number" min="0" step="0.01" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">GST %</label>
                                <input v-model.number="line.gst_percent" type="number" min="0" max="100" step="0.01" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Discount Amount</label>
                                <input v-model.number="line.discount_amount" type="number" min="0" step="0.01" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">MFG</label>
                                <input v-model="line.mfg_date" type="date" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Expiry</label>
                                <input v-model="line.expiry_date" type="date" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1">Unit</label>
                                <input v-model="line.unit" class="w-full rounded-md border-gray-300" />
                            </div>
                            <div class="flex items-end justify-between">
                                <div class="text-sm font-semibold text-gray-700">{{ fmt(lineTotal(line)) }}</div>
                                <button type="button" @click="removeLine(index)" class="rounded bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-500">Remove</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-4">
                    <label class="block text-sm font-medium mb-1">Notes</label>
                    <textarea v-model="form.notes" rows="3" class="w-full rounded-md border-gray-300" placeholder="Optional notes"></textarea>
                </div>

                <div class="flex items-center justify-between">
                    <div class="text-lg font-bold text-gray-900">Estimated Total: {{ fmt(subtotal) }}</div>
                    <div class="flex gap-2">
                        <Link :href="route('admin.franchisee-purchases.index')" class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200">Cancel</Link>
                        <button type="button" @click="submit" :disabled="form.processing" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-60">Save Draft</button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
