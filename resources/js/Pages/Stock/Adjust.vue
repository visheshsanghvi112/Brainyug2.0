<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';
import { ref, computed } from 'vue';

const props = defineProps({
    products: Array,  // [{id, name, generic_name, company_master, batches: [{batch_no, exp_date, current_stock}]}]
    franchisees: Array,
});

const form = useForm({
    product_id: '',
    batch_no: '',
    expiry_date: '',
    location_type: 'warehouse',
    location_id: null,
    adjustment_type: 'in',  // 'in' | 'out'
    qty: '',
    rate: '',
    remarks: '',
});

const selectedProduct = computed(() => props.products?.find(p => p.id == form.product_id) ?? null);
const currentBatch = computed(() => selectedProduct.value?.batches?.find(b => b.batch_no === form.batch_no) ?? null);

function submit() {
    // Convert to signed qty for InventoryService::recordAdjustment
    const payload = {
        product_id: form.product_id,
        batch_no: form.batch_no,
        expiry_date: form.expiry_date,
        location_type: form.location_type,
        location_id: form.location_id,
        qty: form.adjustment_type === 'out' ? -Math.abs(form.qty) : Math.abs(form.qty),
        rate: form.rate,
        remarks: form.remarks,
    };
    form.transform(() => payload).post(route('admin.stock.adjust.store'), {
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Stock Adjustment" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('admin.stock.adjust')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Manual Stock Adjustment</h2>
                    <p class="text-sm text-gray-500">Correct inventory levels with an audit trail</p>
                </div>
            </div>
        </template>

        <div class="max-w-2xl">
            <form @submit.prevent="submit" class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700 space-y-5">

                <!-- Adjustment Type Toggle -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Adjustment Type</label>
                    <div class="inline-flex rounded-xl border border-gray-200 dark:border-gray-600 overflow-hidden">
                        <button type="button" @click="form.adjustment_type = 'in'"
                            :class="['px-6 py-2 text-sm font-semibold transition',
                                form.adjustment_type === 'in' ? 'bg-emerald-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50']">
                            + Stock In
                        </button>
                        <button type="button" @click="form.adjustment_type = 'out'"
                            :class="['px-6 py-2 text-sm font-semibold transition',
                                form.adjustment_type === 'out' ? 'bg-red-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-50']">
                            − Stock Out
                        </button>
                    </div>
                </div>

                <!-- Product -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Product <span class="text-red-500">*</span></label>
                    <select v-model="form.product_id" required
                        class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                        <option value="">Select product…</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} — {{ p.generic_name }}</option>
                    </select>
                    <p v-if="form.errors.product_id" class="mt-1 text-xs text-red-500">{{ form.errors.product_id }}</p>
                </div>

                <!-- Batch No + Expiry -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Batch No <span class="text-red-500">*</span></label>
                        <input v-model="form.batch_no" type="text" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                            placeholder="e.g. B24001" />
                        <p v-if="form.errors.batch_no" class="mt-1 text-xs text-red-500">{{ form.errors.batch_no }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Expiry Date <span class="text-red-500">*</span></label>
                        <input v-model="form.expiry_date" type="date" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        <p v-if="form.errors.expiry_date" class="mt-1 text-xs text-red-500">{{ form.errors.expiry_date }}</p>
                    </div>
                </div>

                <!-- Location -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location Type <span class="text-red-500">*</span></label>
                        <select v-model="form.location_type" @change="form.location_id = null" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                            <option value="warehouse">Warehouse / HO</option>
                            <option value="franchisee">Franchisee Shop</option>
                        </select>
                    </div>
                    <div v-if="form.location_type === 'franchisee'">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Franchisee <span class="text-red-500">*</span></label>
                        <select v-model="form.location_id"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                            <option :value="null">Select franchisee…</option>
                            <option v-for="f in franchisees" :key="f.id" :value="f.id">{{ f.shop_name }}</option>
                        </select>
                        <p v-if="form.errors.location_id" class="mt-1 text-xs text-red-500">{{ form.errors.location_id }}</p>
                    </div>
                </div>

                <!-- Qty + Rate -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity <span class="text-red-500">*</span></label>
                        <input v-model.number="form.qty" type="number" min="1" step="1" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                            placeholder="Units" />
                        <p v-if="form.errors.qty" class="mt-1 text-xs text-red-500">{{ form.errors.qty }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unit Cost (₹) <span class="text-red-500">*</span></label>
                        <input v-model.number="form.rate" type="number" min="0" step="0.01" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                            placeholder="0.00" />
                        <p v-if="form.errors.rate" class="mt-1 text-xs text-red-500">{{ form.errors.rate }}</p>
                    </div>
                </div>

                <!-- Remarks -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason / Remarks <span class="text-red-500">*</span></label>
                    <textarea v-model="form.remarks" rows="3" required
                        class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                        placeholder="e.g. Damaged stock write-off, Cycle count correction, Opening balance…" />
                    <p v-if="form.errors.remarks" class="mt-1 text-xs text-red-500">{{ form.errors.remarks }}</p>
                </div>

                <!-- Warning for Stock Out -->
                <div v-if="form.adjustment_type === 'out'" class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 px-4 py-3">
                    <p class="text-sm text-amber-800 dark:text-amber-200 font-medium">⚠ Stock-out adjustment will reduce inventory permanently. Ensure you have verified the physical count.</p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-2">
                    <Link :href="route('admin.stock.adjust')"
                        class="rounded-lg px-5 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing"
                        :class="['rounded-lg px-6 py-2 text-sm font-semibold text-white disabled:opacity-50',
                            form.adjustment_type === 'out' ? 'bg-red-600 hover:bg-red-500' : 'bg-emerald-600 hover:bg-emerald-500']">
                        {{ form.adjustment_type === 'out' ? 'Deduct Stock' : 'Add Stock' }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
