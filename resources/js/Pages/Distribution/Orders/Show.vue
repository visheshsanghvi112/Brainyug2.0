<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { 
    CheckCircleIcon, 
    XCircleIcon,
    TruckIcon,
    DocumentTextIcon,
    ArrowLeftIcon,
    BuildingStorefrontIcon,
    CalculatorIcon,
    DocumentArrowDownIcon,
    BoltIcon, // for auto-allocate
    ExclamationTriangleIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    order: Object,
});

// Calculate missing defaults & init Form
const acceptForm = useForm({
    items: props.order.items.map(i => ({
        id: i.id,
        batch_no: i.batch_no || '',
        available_batches: i.available_batches || [],
        
        // Editable fields
        approved_qty: Number(i.request_qty),
        free_qty: Number(i.free_qty || 0),
        rate: Number(i.rate || 0),
        discount_percent: Number(i.discount_percent || 0),
        gst_percent: Number(i.gst_percent || 0),
        
        product_name: i.product?.product_name,
        sku: i.product?.sku
    }))
});

const dispatchForm = useForm({
    courier_name: '',
    tracking_number: '',
    tracking_link: '',
    dispatch_date: new Date().toISOString().split('T')[0],
    invoice_number: `INV-${props.order.order_number.split('-')[2]}-${Math.floor(Math.random() * 1000)}`,
    ebill_number: '',
});

const rejectForm = useForm({
    rejection_reason: '',
});

const isAccepting = ref(false);
const isDispatching = ref(false);
const isRejecting = ref(false);
const showCalculator = ref(false);

const handleAccept = () => {
    isAccepting.value = true;
    acceptForm.post(route('admin.dist-orders.accept', props.order.id), {
        preserveScroll: true,
        onFinish: () => {
            isAccepting.value = false;
        }
    });
};

const handleDispatch = () => {
    isDispatching.value = true;
    dispatchForm.post(route('admin.dist-orders.dispatch', props.order.id), {
        preserveScroll: true,
        onFinish: () => {
            isDispatching.value = false;
        }
    });
};

const handleReject = () => {
    isRejecting.value = true;
    rejectForm.post(route('admin.dist-orders.reject', props.order.id), {
        preserveScroll: true,
        onFinish: () => {
            isRejecting.value = false;
        }
    });
};

// Auto Allocate Batch (FIFO logic based on available_batches)
const autoAllocateBatches = () => {
    acceptForm.items.forEach(item => {
        if (!item.batch_no && item.available_batches && item.available_batches.length > 0) {
            // Find a batch that has enough stock
            const reqQty = Number(item.approved_qty) + Number(item.free_qty);
            const suitableBatch = item.available_batches.find(b => Number(b.stock) >= reqQty) 
                                 || item.available_batches[0]; // fallback to oldest batch
            if (suitableBatch) {
                item.batch_no = suitableBatch.batch_no;
            }
        }
    });
};

// Custom Computation for Live Order Grid Financials
const liveTotals = computed(() => {
    let subtotal = 0;
    let totalGst = 0;
    
    acceptForm.items.forEach(item => {
        let gross = Number(item.approved_qty) * Number(item.rate);
        let discount = gross * (Number(item.discount_percent) / 100);
        let taxable = gross - discount;
        let gst = taxable * (Number(item.gst_percent) / 100);
        
        subtotal += taxable;
        totalGst += gst;
    });
    
    let totalAmount = subtotal + totalGst;
    let roundOff = Math.round(totalAmount) - totalAmount;
    
    return {
        subtotal,
        totalGst,
        totalAmount: Math.round(totalAmount),
        roundOff
    };
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        minimumFractionDigits: 2
    }).format(amount || 0);
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-IN', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
};

// === F12 PIP CALCULATOR LOGIC ===
const calcExpression = ref('');

const toggleCalculator = (e) => {
    if (e.key === 'F12') {
        e.preventDefault();
        showCalculator.value = !showCalculator.value;
    }
};

const calcInput = (e) => {
    const validKeys = ['0','1','2','3','4','5','6','7','8','9','+','-','*','/','.'];
    if (showCalculator.value) {
        if (validKeys.includes(e.key)) {
            calcExpression.value += e.key;
        } else if (e.key === 'Enter' || e.key === '=') {
            try {
                // eslint-disable-next-line
                calcExpression.value = eval(calcExpression.value).toString();
            } catch {
                calcExpression.value = 'ERROR';
            }
        } else if (e.key === 'Backspace') {
            calcExpression.value = calcExpression.value.slice(0, -1);
        } else if (e.key === 'Escape') {
            showCalculator.value = false;
        }
    }
};

const pressCalc = (char) => {
    if (char === '=') {
        try {
            // eslint-disable-next-line
            calcExpression.value = eval(calcExpression.value).toString();
        } catch {
            calcExpression.value = 'ERROR';
        }
    } else if (char === 'C') {
        calcExpression.value = '';
    } else {
        calcExpression.value += char;
    }
};

onMounted(() => {
    window.addEventListener('keydown', toggleCalculator);
    window.addEventListener('keydown', calcInput);
});

onUnmounted(() => {
    window.removeEventListener('keydown', toggleCalculator);
    window.removeEventListener('keydown', calcInput);
});

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-800 border-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-400 dark:border-yellow-700',
    accepted: 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-700',
    dispatched: 'bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-400 dark:border-indigo-700',
    delivered: 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700',
    rejected: 'bg-red-100 text-red-800 border-red-200 dark:bg-red-900/30 dark:text-red-400 dark:border-red-700',
    cancelled: 'bg-gray-100 text-gray-800 border-gray-200 dark:bg-gray-800 dark:text-gray-400',
};
</script>

<template>
    <Head :title="`Order ${order.order_number}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex items-center gap-4">
                    <Link :href="route('admin.dist-orders.index')" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition-colors">
                        <ArrowLeftIcon class="w-6 h-6" />
                    </Link>
                    <h2 class="font-bold text-2xl text-gray-900 dark:text-gray-100 tracking-tight flex items-center gap-2">
                        <TruckIcon class="w-8 h-8 text-indigo-600 dark:text-indigo-400" />
                        Dispatch Engine: {{ order.order_number }}
                    </h2>
                </div>
                
                <div class="flex items-center gap-3">
                    <button @click="showCalculator = !showCalculator" class="flex items-center gap-1.5 px-3 py-1.5 bg-gray-800 text-white rounded-lg text-sm font-semibold hover:bg-gray-700 transition shadow-sm border border-gray-700">
                        <CalculatorIcon class="w-4 h-4 text-green-400" /> F12 Calc
                    </button>
                    <button class="flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg text-sm font-semibold hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                        <DocumentArrowDownIcon class="w-4 h-4 text-red-500" /> Print PDF
                    </button>
                    <div class="px-4 py-1.5 rounded-full border text-sm font-bold uppercase tracking-wider" :class="statusColors[order.status]">
                        {{ order.status }}
                    </div>
                </div>
            </div>
        </template>

        <div class="py-8 relative">
            <div class="max-w-[1400px] mx-auto sm:px-6 lg:px-8 space-y-8">
                
                <!-- Top Metrics Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700 relative overflow-hidden group">
                        <div class="absolute inset-0 bg-gradient-to-br from-indigo-50 to-transparent dark:from-indigo-900/20 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        <div class="relative">
                            <div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-2 flex items-center gap-1">
                                <BuildingStorefrontIcon class="w-4 h-4" /> Franchisee Detail
                            </div>
                            <div class="font-extrabold text-xl text-gray-900 dark:text-gray-100">{{ order.franchisee?.name }}</div>
                            <div class="text-sm text-indigo-600 dark:text-indigo-400 font-medium tracking-wide">{{ order.franchisee?.shop_code }}</div>
                            <div class="text-xs text-gray-400 mt-2">Ordered by: <span class="text-gray-600 dark:text-gray-300">{{ order.user?.name }}</span></div>
                        </div>
                    </div>

                    <div class="bg-gradient-to-br from-gray-900 to-gray-800 shadow-xl rounded-xl p-6 border border-gray-700 relative overflow-hidden">
                        <div class="absolute -right-10 -top-10 w-32 h-32 bg-indigo-500/20 rounded-full blur-2xl"></div>
                        <div class="relative">
                            <div class="text-sm font-medium text-gray-400 mb-2 flex items-center gap-1">
                                <DocumentTextIcon class="w-4 h-4" /> Live Financials
                            </div>
                            <div class="font-black text-3xl text-white tracking-tight">
                                {{ order.status === 'pending' ? formatCurrency(liveTotals.totalAmount) : formatCurrency(order.total_amount) }}
                            </div>
                            <div class="text-sm text-gray-300 mt-1 flex justify-between">
                                <span>Taxable: {{ order.status === 'pending' ? formatCurrency(liveTotals.subtotal) : formatCurrency(order.subtotal) }}</span>
                                <span class="text-indigo-300">GST: {{ order.status === 'pending' ? formatCurrency(liveTotals.totalGst) : formatCurrency(order.total_amount - order.subtotal) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700">
                        <div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-2">Timeline Journey</div>
                        <div class="space-y-1.5 mt-3">
                            <div class="text-sm flex justify-between"><span class="text-gray-500">Created:</span> <span class="font-medium dark:text-gray-200">{{ formatDate(order.created_at) }}</span></div>
                            <div v-if="order.accepted_at" class="text-sm flex justify-between"><span class="text-blue-500">Accepted:</span> <span class="font-medium dark:text-gray-200">{{ formatDate(order.accepted_at) }}</span></div>
                            <div v-if="order.dispatched_at" class="text-sm flex justify-between"><span class="text-indigo-500">Dispatched:</span> <span class="font-medium dark:text-gray-200">{{ formatDate(order.dispatched_at) }}</span></div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 shadow-sm rounded-xl p-6 border border-gray-100 dark:border-gray-700">
                         <div class="text-sm font-semibold text-gray-500 dark:text-gray-400 mb-2">Logistics Center</div>
                         <div v-if="order.status === 'dispatched' || order.status === 'delivered'">
                             <div class="font-bold text-gray-900 dark:text-white">{{ order.courier_name }}</div>
                             <div class="text-sm font-mono text-indigo-600 dark:text-indigo-400 mt-1">{{ order.tracking_number }}</div>
                             <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                                 <span class="text-xs text-gray-500">Invoice:</span>
                                 <span class="text-xs font-semibold ml-1 dark:text-gray-300">{{ order.invoice_number || 'N/A' }}</span>
                             </div>
                         </div>
                         <div v-else class="h-full flex items-center justify-center text-sm text-gray-400 italic">
                            Awaiting Dispatch
                         </div>
                    </div>
                </div>

                <!-- Step 1: HO Batch Allocation & Live Editor -->
                <div v-if="order.status === 'pending'" class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden border border-indigo-200 dark:border-indigo-900/50">
                    <div class="p-5 bg-gradient-to-r from-indigo-50 to-white dark:from-indigo-900/20 dark:to-gray-800 border-b border-indigo-100 dark:border-gray-700 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-extrabold text-indigo-900 dark:text-indigo-300 border-l-4 border-indigo-500 pl-3">Step 1: Batch Allocation & Live Billing</h3>
                            <p class="text-sm text-indigo-700 dark:text-gray-400 mt-1 pl-4">Live-edit quantities, discounts, and PTR. GST calculates dynamically. Select warehouse batches to reserve stock.</p>
                        </div>
                        <button type="button" @click="autoAllocateBatches" class="flex items-center gap-2 px-4 py-2 bg-indigo-100 text-indigo-700 hover:bg-indigo-200 dark:bg-indigo-900/50 dark:text-indigo-300 font-bold rounded-lg text-sm transition-colors shadow-sm">
                            <BoltIcon class="w-4 h-4" /> Auto-Allocate Batches
                        </button>
                    </div>
                    
                    <form @submit.prevent="handleAccept" class="p-0">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900/50">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Product ID</th>
                                        <th scope="col" class="px-4 py-3 text-center text-xs font-bold bg-indigo-50/50 dark:bg-indigo-900/20 text-indigo-700 dark:text-indigo-300 uppercase tracking-wider w-48">Select Batch</th>
                                        <th scope="col" class="px-3 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider w-24">Req Qty</th>
                                        <th scope="col" class="px-3 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider w-24">Stock</th>
                                        <th scope="col" class="px-3 py-3 text-right text-xs font-bold text-indigo-600 uppercase tracking-wider w-24">Appd Qty</th>
                                        <th scope="col" class="px-3 py-3 text-right text-xs font-bold text-green-600 uppercase tracking-wider w-24">Free Qty</th>
                                        <th scope="col" class="px-3 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider w-28">PTR (Rate)</th>
                                        <th scope="col" class="px-3 py-3 text-right text-xs font-bold text-blue-600 uppercase tracking-wider w-24">Disc %</th>
                                        <th scope="col" class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Net Row Amt</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
                                    <tr v-for="(item, index) in acceptForm.items" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                        
                                        <!-- PRODUCT -->
                                        <td class="px-4 py-3 align-top">
                                            <div class="text-sm font-bold text-gray-900 dark:text-gray-100 leading-tight">{{ item.product_name }}</div>
                                            <div class="text-xs text-gray-400 font-mono mt-1 mb-1">{{ item.sku }}</div>
                                            
                                            <!-- Stock Warning -->
                                            <div v-if="item.batch_no" class="mt-1">
                                                <span v-for="b in item.available_batches" :key="b.batch_no">
                                                    <div v-if="b.batch_no === item.batch_no" class="flex items-center gap-1 text-[11px] font-bold" 
                                                        :class="(Number(b.stock) < (Number(item.approved_qty) + Number(item.free_qty))) ? 'text-red-600 bg-red-50 dark:bg-red-900/30 px-2 py-0.5 rounded' : 'text-green-600'">
                                                        <ExclamationTriangleIcon v-if="(Number(b.stock) < (Number(item.approved_qty) + Number(item.free_qty)))" class="w-3 h-3" />
                                                        HO Stock: {{ b.stock }}
                                                    </div>
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <!-- BATCH SELECTION -->
                                        <td class="px-4 py-3 bg-indigo-50/20 dark:bg-indigo-900/10 align-top">
                                            <select 
                                                v-model="acceptForm.items[index].batch_no" 
                                                class="block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm font-bold text-indigo-900 dark:text-indigo-300 shadow-sm"
                                                required
                                            >
                                                <option value="" disabled>Select Batch</option>
                                                <option v-for="batch in item.available_batches" :key="batch.batch_no" :value="batch.batch_no">
                                                    {{ batch.batch_no }} ({{ batch.stock }})
                                                </option>
                                            </select>
                                        </td>

                                        <!-- REQ QTY -->
                                        <td class="px-3 py-3 whitespace-nowrap text-sm text-right font-medium text-gray-500 align-top pt-5">
                                            {{ order.items[index].request_qty }}
                                        </td>

                                        <!-- TOTAL STOCK FOR ITEM -->
                                        <td class="px-3 py-3 text-right align-top pt-5">
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-bold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                                {{ item.available_batches.reduce((total, b) => total + Number(b.stock), 0) }}
                                            </span>
                                        </td>

                                        <!-- APPD QTY -->
                                        <td class="px-3 py-3 align-top">
                                            <input 
                                                type="number" 
                                                v-model="acceptForm.items[index].approved_qty" 
                                                class="block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm text-center font-bold text-gray-900 dark:text-white shadow-sm"
                                                required
                                                min="0.1" step="0.1"
                                            >
                                        </td>

                                        <!-- FREE QTY -->
                                        <td class="px-3 py-3 align-top">
                                            <input 
                                                type="number" 
                                                v-model="acceptForm.items[index].free_qty" 
                                                class="block w-full border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 rounded-lg focus:ring-green-500 focus:border-green-500 sm:text-sm text-center font-bold shadow-sm"
                                                min="0" step="0.1"
                                            >
                                        </td>

                                        <!-- RATE (PTR) -->
                                        <td class="px-3 py-3 align-top">
                                            <input 
                                                type="number" 
                                                v-model="acceptForm.items[index].rate" 
                                                class="block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm text-right font-medium text-gray-900 dark:text-white shadow-sm"
                                                required min="0" step="0.01"
                                            >
                                        </td>

                                        <!-- DISCOUNT % -->
                                        <td class="px-3 py-3 align-top">
                                            <input 
                                                type="number" 
                                                v-model="acceptForm.items[index].discount_percent" 
                                                class="block w-full border-blue-300 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-400 rounded-lg focus:ring-blue-500 focus:border-blue-500 sm:text-sm text-center font-bold shadow-sm"
                                                min="0" max="100" step="0.1"
                                            >
                                        </td>

                                        <!-- LIVE ROW MATH -->
                                        <td class="px-4 py-3 align-top text-right">
                                            <div class="text-sm font-black text-gray-900 dark:text-white font-mono">
                                                {{ formatCurrency((item.approved_qty * item.rate * (1 - (item.discount_percent/100))) * (1 + (item.gst_percent/100))) }}
                                            </div>
                                            <div class="text-[10px] text-gray-400 font-bold uppercase mt-1">
                                                +{{ item.gst_percent }}% GST
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="px-6 py-5 bg-gray-50 dark:bg-gray-800/80 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-4">
                            <button 
                                type="button"
                                @click="isRejecting = true"
                                class="text-sm font-bold text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300 flex items-center gap-1.5 transition-colors"
                            >
                                <XCircleIcon class="w-5 h-5" /> Cancel / Reject Order
                            </button>

                            <button 
                                type="submit" 
                                :disabled="acceptForm.processing"
                                class="inline-flex justify-center items-center py-3 px-8 border border-transparent shadow-lg text-sm font-black rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transform hover:-translate-y-0.5 transition-all text-shadow"
                            >
                                <CheckCircleIcon class="w-5 h-5 mr-2" />
                                ACCEPT & LOCK ORDER: {{ formatCurrency(liveTotals.totalAmount) }}
                            </button>
                        </div>
                    </form>

                    <!-- Rejection Panel Hidden by Default -->
                    <div v-if="isRejecting" class="p-6 bg-red-50 dark:bg-red-900/20 border-t border-red-200 dark:border-red-900/50">
                        <form @submit.prevent="handleReject">
                            <label class="block text-sm font-bold text-red-800 dark:text-red-400 mb-2">Provide Reason for Rejection (Visible to Franchisee):</label>
                            <textarea 
                                v-model="rejectForm.rejection_reason" 
                                rows="3" 
                                class="block w-full border-red-300 dark:border-red-700 dark:bg-gray-800 rounded-lg focus:ring-red-500 focus:border-red-500 sm:text-sm mb-4 shadow-sm text-gray-900 dark:text-gray-100"
                                required
                            ></textarea>
                            <div class="flex justify-end gap-3">
                                <button type="button" @click="isRejecting = false" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm font-bold text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 transition-colors">Go Back</button>
                                <button type="submit" :disabled="rejectForm.processing" class="px-4 py-2 border border-transparent rounded-lg shadow-lg text-sm font-bold text-white bg-red-600 hover:bg-red-700 transition-colors tracking-wide">CONFIRM REJECTION</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Step 2: Distribution & Tracking (Only when Accepted) -->
                <div v-if="order.status === 'accepted'" class="bg-white dark:bg-gray-800 shadow-xl rounded-2xl overflow-hidden border border-green-200 dark:border-green-900/50">
                    <div class="p-6 bg-gradient-to-r from-green-50 to-white dark:from-green-900/20 dark:to-gray-800 border-b border-green-100 dark:border-gray-700">
                        <h3 class="text-lg font-extrabold text-green-900 dark:text-green-400 border-l-4 border-green-500 pl-3">Step 2: Dispatch Logistics</h3>
                        <p class="text-sm text-green-700 dark:text-gray-400 mt-1 pl-4">The order is billed. Providing tracking info and dispatching will officially transfer inventory from HO to Franchisee Ledger.</p>
                    </div>
                    
                    <form @submit.prevent="handleDispatch" class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Transporter / Courier Name</label>
                                <input type="text" v-model="dispatchForm.courier_name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" required>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">LR No. / Tracking ID</label>
                                <input type="text" v-model="dispatchForm.tracking_number" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm uppercase font-mono font-bold text-indigo-600 dark:text-indigo-400" required>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Tracking Link (URL)</label>
                                <input type="url" v-model="dispatchForm.tracking_link" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" placeholder="https://...">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Dispatch Date</label>
                                <input type="date" v-model="dispatchForm.dispatch_date" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" required>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Generated Invoice Number</label>
                                <input type="text" v-model="dispatchForm.invoice_number" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm font-mono font-bold">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">E-Way Bill / Permit No</label>
                                <input type="text" v-model="dispatchForm.ebill_number" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm uppercase font-mono">
                            </div>
                        </div>

                        <div class="mt-8 flex justify-end pt-5 border-t border-gray-100 dark:border-gray-700">
                            <button 
                                type="submit" 
                                :disabled="dispatchForm.processing"
                                class="inline-flex justify-center items-center py-3 px-8 border border-transparent shadow-lg text-sm font-black rounded-lg text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transform hover:-translate-y-0.5 transition-all"
                            >
                                <TruckIcon class="w-5 h-5 mr-2" />
                                CONFIRM DISPATCH & TRANSFER STOCK
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Read-Only Line Items (Visible in dispatched/delivered states) -->
                <div v-if="order.status !== 'pending'" class="bg-white dark:bg-gray-800 shadow-lg rounded-2xl overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <h3 class="text-lg font-black text-gray-900 dark:text-white">Order Manifest <span class="text-gray-500 font-medium text-sm ml-2">({{ order.items.length }} Items)</span></h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-white dark:bg-gray-800">
                                <tr>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Product Info</th>
                                    <th scope="col" class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Allocated Batch</th>
                                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Qty Data</th>
                                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">PTR / Disc</th>
                                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Taxable</th>
                                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">GST Amt</th>
                                    <th scope="col" class="px-6 py-4 text-right text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider">Net Amount</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700">
                                <tr v-for="item in order.items" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap align-top">
                                        <div class="text-sm font-bold text-gray-900 dark:text-white">{{ item.product?.product_name }}</div>
                                        <div class="text-xs text-gray-500 mt-1 font-mono">{{ item.product?.sku }}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300 align-top">
                                        <span v-if="item.batch_no" class="font-bold font-mono px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded">{{ item.batch_no }}</span>
                                        <span v-else class="text-gray-400 italic">Pending</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap align-top text-right">
                                        <div class="text-sm">
                                            <span class="text-gray-500 line-through text-xs mr-2">{{ item.request_qty }}</span>
                                            <span class="font-bold text-gray-900 dark:text-white text-base">{{ item.approved_qty }}</span>
                                        </div>
                                        <div v-if="item.free_qty > 0" class="text-xs font-bold text-green-600 mt-1 bg-green-50 dark:bg-green-900/20 px-2 py-0.5 rounded-full inline-block">+{{item.free_qty}} FREE</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap align-top text-right">
                                        <div class="text-sm font-bold text-gray-900 dark:text-gray-300">{{ formatCurrency(item.rate) }}</div>
                                        <div v-if="item.discount_percent > 0" class="text-xs font-bold text-blue-600 mt-1">{{ item.discount_percent }}% OFF</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-600 dark:text-gray-400 align-top pt-5">{{ formatCurrency(item.taxable_amount) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right align-top pt-5">
                                        <div class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ formatCurrency(item.gst_amount) }}</div>
                                        <div class="text-[10px] text-gray-400 font-bold uppercase">{{ item.gst_percent }}% GST</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right align-top pt-5">
                                        <span class="font-black text-lg text-gray-900 dark:text-white font-mono tracking-tight">{{ formatCurrency(item.total_amount) }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

        <!-- F12 PIP Floating Calculator Widget (Glassmorphism) -->
        <div v-if="showCalculator" class="fixed top-24 right-8 w-80 backdrop-blur-3xl border border-white/20 dark:border-gray-700 shadow-[0_30px_60px_-15px_rgba(0,0,0,0.5)] rounded-3xl overflow-hidden z-[99]" style="background: rgba(20, 25, 40, 0.85);">
            <div class="px-4 py-3 flex justify-between items-center bg-white/5 border-b border-white/10">
                <div class="flex items-center gap-2 text-white/90 font-bold text-sm tracking-widest uppercase">
                    <CalculatorIcon class="w-4 h-4 text-green-400" />
                    Calculator
                </div>
                <button @click="showCalculator = false" class="text-white/50 hover:text-white hover:bg-white/10 rounded-full p-1 transition">
                    <XCircleIcon class="w-5 h-5" />
                </button>
            </div>
            <div class="p-6">
                <!-- Calc Display -->
                <div class="w-full bg-black/40 shadow-inner rounded-xl p-4 text-right h-16 mb-4 flex items-center justify-end overflow-hidden border border-black/50">
                    <span class="text-3xl font-mono text-white font-light tracking-wider">{{ calcExpression || '0' }}</span>
                </div>
                <!-- Calc Keys -->
                <div class="grid grid-cols-4 gap-3">
                    <button @click="pressCalc('7')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">7</button>
                    <button @click="pressCalc('8')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">8</button>
                    <button @click="pressCalc('9')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">9</button>
                    <button @click="pressCalc('+')" class="aspect-square bg-indigo-500/20 text-indigo-300 hover:bg-indigo-500/40 rounded-xl text-2xl font-medium transition active:scale-95 shadow-lg border border-indigo-500/30">+</button>
                    
                    <button @click="pressCalc('4')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">4</button>
                    <button @click="pressCalc('5')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">5</button>
                    <button @click="pressCalc('6')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">6</button>
                    <button @click="pressCalc('-')" class="aspect-square bg-indigo-500/20 text-indigo-300 hover:bg-indigo-500/40 rounded-xl text-3xl font-medium transition active:scale-95 shadow-lg border border-indigo-500/30">-</button>
                    
                    <button @click="pressCalc('1')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">1</button>
                    <button @click="pressCalc('2')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">2</button>
                    <button @click="pressCalc('3')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">3</button>
                    <button @click="pressCalc('*')" class="aspect-square bg-indigo-500/20 text-indigo-300 hover:bg-indigo-500/40 rounded-xl text-xl font-medium transition active:scale-95 shadow-lg border border-indigo-500/30">&times;</button>
                    
                    <button @click="pressCalc('C')" class="aspect-square bg-red-500/20 text-red-400 hover:bg-red-500/40 rounded-xl text-xl font-bold transition active:scale-95 shadow-lg border border-red-500/30">C</button>
                    <button @click="pressCalc('0')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-xl font-medium transition active:scale-95 shadow-lg">0</button>
                    <button @click="pressCalc('.')" class="aspect-square bg-white/10 hover:bg-white/20 text-white rounded-xl text-2xl font-black transition active:scale-95 shadow-lg">.</button>
                    <button @click="pressCalc('/')" class="aspect-square bg-indigo-500/20 text-indigo-300 hover:bg-indigo-500/40 rounded-xl text-2xl font-medium transition active:scale-95 shadow-lg border border-indigo-500/30">&divide;</button>
                    
                    <button @click="pressCalc('=')" class="col-span-4 py-3 bg-green-500/80 hover:bg-green-400 text-black rounded-xl text-2xl font-black transition active:scale-95 shadow-[0_0_20px_rgba(34,197,94,0.3)] mt-2">=</button>
                </div>
                <div class="text-center mt-4 text-[10px] text-gray-500 uppercase tracking-widest font-bold">Press F12 to close</div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
