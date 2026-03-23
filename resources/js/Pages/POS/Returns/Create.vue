<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
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

const customerMobile = ref('');
const selectedCustomer = ref(null);
const recentBills = ref([]);
const selectedBill = ref(null);
const loadingBills = ref(false);
const loadingBillItems = ref(false);
const submittingInvoiceReturn = ref(false);

const searchQuery = ref('');
const filteredProducts = computed(() => {
    if (!searchQuery.value) return [];
    return props.products.filter(p => p.product_name.toLowerCase().includes(searchQuery.value.toLowerCase())).slice(0, 10);
});

const addItem = (product) => {
    form.items.push({
        product_id: product.id,
        product_name: product.product_name,
        sales_invoice_item_id: null,
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

const isInvoiceBasedReturn = computed(() => !!selectedBill.value && form.items.some(i => !!i.sales_invoice_item_id));

const loadCustomerBills = async () => {
    selectedCustomer.value = null;
    recentBills.value = [];
    selectedBill.value = null;

    const mobile = (customerMobile.value || '').trim();
    if (mobile.length < 8) {
        alert('Enter a valid customer mobile number first.');
        return;
    }

    loadingBills.value = true;
    try {
        const customerRes = await axios.post(route('pos.lookupCustomer'), { mobile });
        const customer = customerRes.data;

        if (!customer?.id) {
            alert('Customer not found for this mobile.');
            return;
        }

        selectedCustomer.value = customer;

        const billsRes = await axios.post(route('pos.customerRecentBills'), {
            customer_id: customer.id,
            limit: 10,
        });

        recentBills.value = billsRes.data || [];
        if (!recentBills.value.length) {
            alert('No recent completed bills found for this customer.');
        }
    } catch (e) {
        const msg = e?.response?.data?.message || 'Unable to fetch customer bills.';
        alert(msg);
    } finally {
        loadingBills.value = false;
    }
};

const loadBillItems = async (bill) => {
    loadingBillItems.value = true;
    try {
        const res = await axios.get(route('pos.billItems', bill.id));
        const invoice = res.data?.invoice;
        const invoiceItems = res.data?.items || [];

        selectedBill.value = invoice;
        form.items = invoiceItems.map((item) => {
            const qty = Number(item.qty || 0);
            const rate = Number(item.rate || 0);
            const gstPercent = Number(item.gst_percent || 0);
            const refundAmount = rate * qty * (1 + (gstPercent / 100));

            return {
                product_id: item.product_id,
                product_name: item.product_name,
                sales_invoice_item_id: item.sales_invoice_item_id,
                batch_no: item.batch_no || '',
                qty,
                max_qty: qty,
                rate,
                gst_percent: gstPercent,
                refund_amount: refundAmount,
                status: 'restocked'
            };
        });
    } catch (e) {
        const msg = e?.response?.data?.message || 'Unable to load bill items.';
        alert(msg);
    } finally {
        loadingBillItems.value = false;
    }
};

const submit = async () => {
    if (isInvoiceBasedReturn.value) {
        const returnLines = form.items
            .filter(i => Number(i.qty) > 0)
            .map(i => ({
                sales_invoice_item_id: i.sales_invoice_item_id,
                return_qty: Number(i.qty),
                line_amount: Number(i.rate || 0) * Number(i.qty || 0) * (1 + (Number(i.gst_percent || 0) / 100)),
            }));

        if (!returnLines.length) {
            alert('Add at least one return line quantity greater than zero.');
            return;
        }

        const supervisorUsername = String(window.prompt('Supervisor username for return approval:', '') || '').trim();
        if (!supervisorUsername) return;

        const supervisorPassword = String(window.prompt('Supervisor password:', '') || '');
        if (!supervisorPassword) return;

        const overrideReason = String(window.prompt('Reason for processing this return:', form.reason || 'POS return approval') || '').trim();
        if (overrideReason.length < 5) {
            alert('Reason must be at least 5 characters for supervisor approval.');
            return;
        }

        const requestId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        const totalAmount = Number(returnLines.reduce((sum, line) => sum + Number(line.line_amount || 0), 0).toFixed(2));
        const snapshot = {
            item_count: Number(returnLines.length || 0),
            max_line_discount: 0,
            bill_discount_percent: 0,
            total_amount: totalAmount,
        };

        submittingInvoiceReturn.value = true;
        try {
            const authRes = await axios.post(route('pos.override.authorize'), {
                action: 'return_override',
                request_id: requestId,
                reason: overrideReason,
                supervisor_username: supervisorUsername,
                supervisor_password: supervisorPassword,
                approval_snapshot: snapshot,
            });

            const token = String(authRes?.data?.token || '');
            if (!token) {
                alert('Supervisor approval token missing. Please retry.');
                return;
            }

            await axios.post(route('pos.processReturn'), {
                original_bill_no: selectedBill.value.bill_no,
                reason: form.reason,
                refund_mode: form.refund_mode,
                override_request_id: requestId,
                override_token: token,
                override_reason: overrideReason,
                override_snapshot: snapshot,
                items: returnLines.map(i => ({
                        sales_invoice_item_id: i.sales_invoice_item_id,
                    return_qty: Number(i.return_qty),
                    })),
            });

            alert('Sales return processed against original bill successfully.');
            router.visit(route('pos.returns.index'));
        } catch (e) {
            const msg = e?.response?.data?.message || 'Unable to process invoice-based return.';
            alert(msg);
        } finally {
            submittingInvoiceReturn.value = false;
        }
        return;
    }

    form.post(route('pos.returns.store'));
};
</script>

<template>
    <Head title="Create Sales Return" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-2xl font-bold text-gray-900 border-l-4 border-indigo-500 pl-4">Record Sales Return</h2>
        </template>

        <form @submit.prevent="submit" class="space-y-6 max-w-5xl">
            <div class="bg-white p-6 shadow rounded-lg border border-gray-200">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Customer Mobile (for previous bill return)</label>
                        <input type="text" v-model="customerMobile" class="w-full border-gray-300 rounded" placeholder="Enter mobile number...">
                    </div>
                    <div>
                        <button type="button" @click="loadCustomerBills" class="w-full bg-slate-800 text-white px-4 py-2 rounded hover:bg-slate-900" :disabled="loadingBills">
                            {{ loadingBills ? 'Loading...' : 'Find Bills' }}
                        </button>
                    </div>
                    <div v-if="selectedCustomer" class="text-sm text-emerald-700 font-bold">
                        {{ selectedCustomer.name }}
                    </div>
                </div>

                <div v-if="recentBills.length" class="mt-4 border rounded overflow-hidden">
                    <div class="bg-slate-50 px-3 py-2 text-xs font-bold uppercase text-slate-500">Recent Bills (click to load items)</div>
                    <div class="max-h-48 overflow-y-auto divide-y">
                        <button
                            v-for="bill in recentBills"
                            :key="bill.id"
                            type="button"
                            @click="loadBillItems(bill)"
                            class="w-full px-3 py-2 text-left hover:bg-emerald-50 flex justify-between items-center"
                        >
                            <span class="font-mono text-sm text-slate-700">{{ bill.bill_no }}</span>
                            <span class="text-xs text-slate-500">₹ {{ Number(bill.total_amount || 0).toFixed(2) }}</span>
                        </button>
                    </div>
                </div>

                <div v-if="selectedBill" class="mt-3 text-xs font-bold text-indigo-700">
                    Loaded Bill: {{ selectedBill.bill_no }}
                </div>
            </div>

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
                                <input type="text" v-model="item.batch_no" class="w-full text-xs p-1 border-gray-300 rounded uppercase text-center" placeholder="Batch..." :readonly="!!item.sales_invoice_item_id">
                            </td>
                            <td class="px-6 py-3">
                                <input type="number" v-model="item.qty" @input="updateItem(item)" class="w-full text-sm p-1 border-gray-300 rounded text-center" :max="item.max_qty || null" min="0.01">
                                <div v-if="item.max_qty" class="text-[10px] text-center text-gray-500 mt-0.5">Max: {{ item.max_qty }}</div>
                            </td>
                            <td class="px-6 py-3">
                                <input type="number" step="0.01" v-model="item.rate" @input="updateItem(item)" class="w-full text-sm p-1 border-gray-300 rounded text-right" :readonly="!!item.sales_invoice_item_id">
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
                <button type="submit" class="bg-indigo-600 text-white px-8 py-2 rounded font-bold shadow-lg shadow-indigo-200 hover:bg-indigo-700 transform transition active:scale-95" :disabled="form.processing || submittingInvoiceReturn || loadingBillItems">
                    {{ isInvoiceBasedReturn ? 'Process Return Against Bill' : 'Confirm Return & Update Stock' }}
                </button>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
