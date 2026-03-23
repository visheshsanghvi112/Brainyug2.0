<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowUturnLeftIcon, ArrowLeftIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    suppliers: Array,
    invoices: Array,
    products: Array,
    prefillInvoice: Object,
});

const prefillItems = props.prefillInvoice?.items?.length
    ? props.prefillInvoice.items.map((item) => ({
        product_id: item.product_id || '',
        batch_no: item.batch_no || '',
        expiry_date: item.expiry_date || '',
        qty: Number(item.qty || 0),
        max_qty: Number(item.max_qty || item.qty || 0),
        rate: Number(item.rate || 0),
        gst_percent: Number(item.gst_percent || 0),
        reason: item.reason || '',
    }))
    : null;

const form = useForm({
    supplier_id: props.prefillInvoice?.supplier_id || '',
    purchase_invoice_id: props.prefillInvoice?.id || '',
    return_date: new Date().toISOString().split('T')[0],
    reason: '',
    items: prefillItems || [
        { product_id: '', batch_no: '', expiry_date: '', qty: 1, max_qty: null, rate: 0, gst_percent: 0, reason: '' }
    ],
});

function addItem() {
    form.items.push({ product_id: '', batch_no: '', expiry_date: '', qty: 1, max_qty: null, rate: 0, gst_percent: 0, reason: '' });
}

function removeItem(index) {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
}

function submit() {
    form.post(route('admin.purchase-returns.store'));
}
</script>

<template>
    <Head title="New Purchase Return" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('admin.purchase-returns.index')" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300 transition">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div class="flex items-center gap-3">
                    <ArrowUturnLeftIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        New Purchase Return
                    </h2>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-6">
                    <div v-if="prefillInvoice" class="rounded-xl border border-indigo-200 bg-indigo-50 px-5 py-4 text-sm text-indigo-900 dark:border-indigo-900/50 dark:bg-indigo-900/20 dark:text-indigo-100">
                        Return is prefilled from invoice <span class="font-semibold">{{ prefillInvoice.invoice_number }}</span> for {{ prefillInvoice.supplier_name }}.
                        Line quantities are loaded with the currently returnable balance and can be reduced before saving.
                    </div>

                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Supplier *</label>
                                <select v-model="form.supplier_id" :disabled="!!prefillInvoice" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white disabled:opacity-70">
                                    <option value="">Select...</option>
                                    <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Link to Invoice</label>
                                <select v-model="form.purchase_invoice_id" :disabled="!!prefillInvoice" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white disabled:opacity-70">
                                    <option value="">No specific invoice</option>
                                    <option v-for="inv in invoices" :key="inv.id" :value="inv.id">{{ inv.invoice_number }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Return Date *</label>
                                <input v-model="form.return_date" type="date" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                            </div>
                            <div class="sm:col-span-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason for Return</label>
                                <input v-model="form.reason" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 overflow-x-auto">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Return Items</h3>
                            <button type="button" @click="addItem" class="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                                <PlusIcon class="h-4 w-4" /> Add Row
                            </button>
                        </div>
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                             <thead class="bg-gray-50 dark:bg-gray-900/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Product</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Batch details</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Qty</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">Rate</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">GST %</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <tr v-for="(item, index) in form.items" :key="index">
                                    <td class="p-3">
                                        <select v-model="item.product_id" :disabled="!!prefillInvoice" required class="w-full text-sm rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 p-1 disabled:opacity-70">
                                            <option value="">Select...</option>
                                            <option v-for="p in products" :key="p.id" :value="p.id">{{ p.product_name }}</option>
                                        </select>
                                    </td>
                                    <td class="p-3">
                                        <div class="flex flex-col gap-1">
                                            <input v-model="item.batch_no" :readonly="!!prefillInvoice" placeholder="Batch No *" required class="w-full text-sm rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 p-1 readonly:opacity-70" />
                                            <input v-model="item.expiry_date" :readonly="!!prefillInvoice" type="date" title="Expiry Date" class="w-full text-sm rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 p-1 readonly:opacity-70" />
                                        </div>
                                    </td>
                                    <td class="p-3">
                                        <input v-model.number="item.qty" type="number" min="0.1" :max="item.max_qty || null" step="0.1" required class="w-full text-sm rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 p-1" />
                                        <div v-if="item.max_qty" class="mt-1 text-[11px] text-gray-500">Max returnable: {{ item.max_qty }}</div>
                                    </td>
                                    <td class="p-3">
                                        <input v-model.number="item.rate" :readonly="!!prefillInvoice" type="number" min="0" step="0.01" required class="w-full text-sm rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 p-1 readonly:opacity-70" />
                                    </td>
                                    <td class="p-3">
                                        <input v-model.number="item.gst_percent" :readonly="!!prefillInvoice" type="number" min="0" step="0.1" required class="w-full text-sm rounded border-gray-300 dark:border-gray-600 dark:bg-gray-900 p-1 readonly:opacity-70" />
                                    </td>
                                    <td class="p-3 text-center">
                                         <button v-if="form.items.length > 1" type="button" @click="removeItem(index)" class="text-red-500 hover:text-red-700 p-1">
                                            <TrashIcon class="h-5 w-5" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <Link :href="route('admin.purchase-returns.index')" class="text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</Link>
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50">
                            {{ form.processing ? 'Checking Stock...' : 'Save Draft Return' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
