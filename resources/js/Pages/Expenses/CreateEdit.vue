<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { computed } from 'vue';

const props = defineProps({
    categories: Array,
    expense: {
        type: Object,
        default: null,
    },
});

const isEdit = computed(() => Boolean(props.expense));

const form = useForm({
    expense_category_id: props.expense?.expense_category_id || '',
    expense_date: props.expense?.expense_date || new Date().toISOString().split('T')[0],
    vendor_name: props.expense?.vendor_name || '',
    amount: Number(props.expense?.amount || 0),
    gst_amount: Number(props.expense?.gst_amount || 0),
    payment_mode: props.expense?.payment_mode || 'cash',
    narration: props.expense?.narration || '',
    status: props.expense?.status || 'approved',
    is_tds_applicable: Boolean(props.expense?.is_tds_applicable || false),
    tds_percent: Number(props.expense?.tds_percent || 0),
});

const grossAmount = computed(() => Number(form.amount || 0) + Number(form.gst_amount || 0));
const tdsAmountPreview = computed(() => form.is_tds_applicable ? (grossAmount.value * Number(form.tds_percent || 0) / 100) : 0);
const netAmountPreview = computed(() => Math.max(0, grossAmount.value - tdsAmountPreview.value));

const submit = () => {
    if (isEdit.value) {
        form.put(route('expenses.update', props.expense.id));
        return;
    }

    form.post(route('expenses.store'));
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Operational Expense' : 'Log Operational Expense'" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-rose-500 pl-4">{{ isEdit ? 'Edit Expense' : 'Log New Expense' }}</h2>
        </template>

        <div class="max-w-3xl">
            <form @submit.prevent="submit" class="bg-white shadow-xl rounded-2xl border border-gray-100 overflow-hidden">
                <div class="p-8 space-y-6">
                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Category</label>
                            <select v-model="form.expense_category_id" class="w-full border-gray-300 rounded-lg focus:ring-rose-500 focus:border-rose-500 shadow-sm" required>
                                <option value="">Select a category...</option>
                                <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Expense Date</label>
                            <input type="date" v-model="form.expense_date" class="w-full border-gray-300 rounded-lg focus:ring-rose-500 shadow-sm" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Vendor/Recipient Name</label>
                            <input type="text" v-model="form.vendor_name" class="w-full border-gray-300 rounded-lg focus:ring-rose-500 shadow-sm" placeholder="e.g. Electric Board, Amazon, Local Stationery...">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Payment Mode</label>
                            <select v-model="form.payment_mode" class="w-full border-gray-300 rounded-lg focus:ring-rose-500 shadow-sm">
                                <option value="cash">Cash</option>
                                <option value="upi">UPI / GPay</option>
                                <option value="bank">Bank Transfer / NEFT</option>
                                <option value="card">Debit/Credit Card</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Status</label>
                            <select v-model="form.status" class="w-full border-gray-300 rounded-lg focus:ring-rose-500 shadow-sm">
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                                <input v-model="form.is_tds_applicable" type="checkbox" class="rounded border-gray-300 text-rose-600 focus:ring-rose-500" />
                                TDS Applicable
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-6 bg-rose-50/50 p-6 rounded-xl border border-rose-100">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">Base Amount (Excl. GST)</label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-400">₹</span>
                                <input type="number" step="0.01" v-model="form.amount" class="w-full border-gray-300 rounded-lg pl-8 focus:ring-rose-500 shadow-sm font-bold" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">GST Amount (If any)</label>
                            <div class="relative">
                                <span class="absolute left-3 top-2 text-gray-400">₹</span>
                                <input type="number" step="0.01" v-model="form.gst_amount" class="w-full border-gray-300 rounded-lg pl-8 focus:ring-rose-500 shadow-sm" placeholder="0.00">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">TDS %</label>
                            <div class="relative">
                                <input type="number" step="0.01" min="0" max="100" v-model="form.tds_percent" :disabled="!form.is_tds_applicable" class="w-full border-gray-300 rounded-lg focus:ring-rose-500 shadow-sm disabled:bg-gray-100" placeholder="0.00">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">TDS Amount (Preview)</label>
                            <div class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 font-bold text-amber-700">₹ {{ tdsAmountPreview.toFixed(2) }}</div>
                        </div>
                        <div class="col-span-2 pt-2 border-t border-rose-100 mt-2 flex justify-between items-center">
                            <span class="text-sm font-bold text-rose-800 uppercase tracking-wider">Total Expense Value</span>
                            <span class="text-2xl font-mono font-bold text-rose-700">₹ {{ grossAmount.toFixed(2) }}</span>
                        </div>
                        <div class="col-span-2 pt-2 border-t border-rose-100 mt-2 flex justify-between items-center">
                            <span class="text-sm font-bold text-emerald-800 uppercase tracking-wider">Net Amount After TDS</span>
                            <span class="text-2xl font-mono font-bold text-emerald-700">₹ {{ netAmountPreview.toFixed(2) }}</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">Narration / Notes</label>
                        <textarea v-model="form.narration" class="w-full border-gray-300 rounded-lg focus:ring-rose-500 shadow-sm" rows="3" placeholder="Describe the purpose of this expense..."></textarea>
                    </div>
                </div>

                <div class="bg-gray-50 px-8 py-5 flex justify-end gap-3 border-t border-gray-100">
                    <Link :href="route('expenses.index')" class="px-6 py-2 text-sm font-semibold text-gray-600 hover:text-gray-900">Cancel</Link>
                    <button type="submit" class="bg-rose-600 text-white px-10 py-2 rounded-lg font-bold shadow-lg shadow-rose-200 hover:bg-rose-700 transform transition active:scale-95 disabled:opacity-50" :disabled="form.processing">
                        {{ form.processing ? 'Saving...' : (isEdit ? 'Update Expense' : 'Post Expense') }}
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
