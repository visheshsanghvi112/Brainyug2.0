<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { TrashIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    products: Array
});

const form = useForm({
    reason: '',
    refund_mode: 'cash',
    items: []
});

const searchQuery = ref('');
const filteredProducts = computed(() => {
    if (!searchQuery.value) return [];
    return props.products.filter(p => p.product_name.toLowerCase().includes(searchQuery.value.toLowerCase())).slice(0, 10);
});

const addItem = (product) => {
    form.items.push({
        product_id: product.id,
        product_name: product.product_name,
        batch_no: '',
        qty: 1,
        rate: 0,
        gst_percent: 5,
        refund_amount: 0,
        status: 'restocked'
    });
    searchQuery.value = '';
};

const updateItem = (item) => {
    item.refund_amount = item.rate * item.qty * (1 + (item.gst_percent / 100));
};

const removeItem = (i) => form.items.splice(i, 1);

const totalRefund = computed(() => form.items.reduce((sum, item) => sum + item.refund_amount, 0).toFixed(2));

const submit = () => form.post(route('pos.returns.store'));
</script>

<template>
    <Head title="Create Sales Return" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-indigo-500 pl-4">Record Sales Return</h2>
        </template>

        <form @submit.prevent="submit" class="space-y-6 max-w-5xl">
            <div class="bg-white p-6 shadow rounded-lg border border-gray-200">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Return Reason</label>
                        <textarea v-model="form.reason" class="w-full border-gray-300 rounded focus:ring-indigo-500" rows="2" placeholder="e.g. Expired, Customer changed mind..."></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Refund Mode</label>
                        <select v-model="form.refund_mode" class="w-full border-gray-300 rounded focus:ring-indigo-500">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank / UPI</option>
                            <option value="adjust_in_wallet">Adjust in Credit Wallet</option>
                        </select>
                    </div>
                </div>

                <div class="mt-6">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Search Product to Return</label>
                    <div class="relative">
                        <input type="text" v-model="searchQuery" class="w-full border-gray-300 rounded pl-10" placeholder="Type medicine name...">
                        <MagnifyingGlassIcon class="h-5 w-5 absolute left-3 top-2.5 text-gray-400" />
                        
                        <div v-if="filteredProducts.length > 0" class="absolute z-10 w-full mt-1 bg-white border border-gray-200 shadow-lg rounded max-h-48 overflow-y-auto">
                            <div v-for="p in filteredProducts" :key="p.id" @click="addItem(p)" class="p-3 hover:bg-indigo-50 cursor-pointer text-sm border-b last:border-0 border-gray-100 italic">
                                {{ p.product_name }} <span class="text-gray-400 not-italic">({{ p.sku }})</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow rounded-lg border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 text-xs font-bold uppercase text-gray-500">
                        <tr>
                            <th class="px-6 py-3 text-left">Product</th>
                            <th class="px-6 py-3 text-center w-32">Batch</th>
                            <th class="px-6 py-3 text-center w-24">Qty</th>
                            <th class="px-6 py-3 text-right w-28">Rate</th>
                            <th class="px-6 py-3 text-center w-32">Condition</th>
                            <th class="px-6 py-3 text-right w-32 font-bold text-indigo-600">Refund (₹)</th>
                            <th class="px-6 py-3 w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr v-for="(item, i) in form.items" :key="i">
                            <td class="px-6 py-3 text-sm font-bold">{{ item.product_name }}</td>
                            <td class="px-6 py-3">
                                <input type="text" v-model="item.batch_no" class="w-full text-xs p-1 border-gray-300 rounded uppercase text-center" placeholder="Batch...">
                            </td>
                            <td class="px-6 py-3">
                                <input type="number" v-model="item.qty" @input="updateItem(item)" class="w-full text-sm p-1 border-gray-300 rounded text-center">
                            </td>
                            <td class="px-6 py-3">
                                <input type="number" step="0.01" v-model="item.rate" @input="updateItem(item)" class="w-full text-sm p-1 border-gray-300 rounded text-right">
                            </td>
                            <td class="px-6 py-3">
                                <select v-model="item.status" class="w-full text-xs p-1 border-gray-300 rounded">
                                    <option value="restocked">Restock</option>
                                    <option value="damaged">Damaged/Discard</option>
                                </select>
                            </td>
                            <td class="px-6 py-3 text-right font-mono font-bold">{{ item.refund_amount.toFixed(2) }}</td>
                            <td class="px-6 py-3">
                                <button @click="removeItem(i)" class="text-rose-500 hover:text-rose-700">
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="form.items.length === 0">
                            <td colspan="7" class="px-6 py-8 text-center text-gray-400 italic">Add items above to record return</td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->
                <div class="bg-gray-900 text-white p-4 flex justify-between items-center">
                    <div class="text-sm font-bold opacity-50 uppercase tracking-widest">Total Refund Calculation</div>
                    <div class="text-2xl font-mono font-bold text-emerald-400">₹ {{ totalRefund }}</div>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <Link :href="route('pos.returns.index')" class="bg-white border px-4 py-2 rounded text-gray-700 hover:bg-gray-50">Cancel</Link>
                <button type="submit" class="bg-indigo-600 text-white px-8 py-2 rounded font-bold shadow-lg shadow-indigo-200 hover:bg-indigo-700 transform transition active:scale-95" :disabled="form.processing">
                    Confirm Return & Update Stock
                </button>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
