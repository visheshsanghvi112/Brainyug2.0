<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    MagnifyingGlassIcon,
    TrashIcon,
    BanknotesIcon,
    CreditCardIcon,
    PrinterIcon,
    ShoppingCartIcon,
    XMarkIcon,
    CalculatorIcon,
    LockClosedIcon,
    LockOpenIcon,
    DocumentTextIcon,
    TableCellsIcon
} from '@heroicons/vue/24/outline';

const props = defineProps({
    franchisee_id: Number,
});

// ── Search ─────────────────────────────────────────
const searchInput = ref(null);
const searchQuery = ref('');
const searchResults = ref([]);
const isSearching = ref(false);
let searchTimeout = null;

const onSearchInput = () => {
    clearTimeout(searchTimeout);
    if (searchQuery.value.length < 2) { searchResults.value = []; return; }
    isSearching.value = true;
    searchTimeout = setTimeout(async () => {
        try {
            const res = await axios.post(route('pos.searchProduct'), { term: searchQuery.value });
            searchResults.value = res.data;
        } catch { searchResults.value = []; }
        finally { isSearching.value = false; }
    }, 250);
};

// ── Multi-Tab Cart System ─────────────────────────
const posTabsConfig = [
    { key: 'RCS', label: 'RCS', color: 'border-teal-400', bg: 'bg-teal-50', text: 'text-teal-700' },
    { key: 'F1', label: 'F1', color: 'border-blue-500', bg: 'bg-blue-50', text: 'text-blue-700' },
    { key: 'F2', label: 'F2', color: 'border-red-500', bg: 'bg-red-50', text: 'text-red-700' },
    { key: 'F3', label: 'F3', color: 'border-gray-400', bg: 'bg-gray-100', text: 'text-gray-700' },
    { key: 'F4', label: 'F4', color: 'border-yellow-500', bg: 'bg-yellow-50', text: 'text-yellow-700' },
    { key: 'F5', label: 'F5', color: 'border-rose-700', bg: 'bg-rose-50', text: 'text-rose-800' },
];

const emptyCustomerState = () => ({
    mobile: '', name: '', id: null, doctorSearch: '', selectedDoctor: null
});

// Holds all the carts mapped by Tab Name
const tabs = ref({ RCS: [], F1: [], F2: [], F3: [], F4: [], F5: [] });
const activeTab = ref('F1');

// Holds customer specific details for each tab so they don't leak between bills
const tabCustomers = ref({
    RCS: emptyCustomerState(), F1: emptyCustomerState(), F2: emptyCustomerState(),
    F3: emptyCustomerState(), F4: emptyCustomerState(), F5: emptyCustomerState()
});

// Holds meta-state for each tab (locked, custom notes, etc)
const tabMeta = ref({
    RCS: { locked: false }, F1: { locked: false }, F2: { locked: false },
    F3: { locked: false }, F4: { locked: false }, F5: { locked: false }
});

const currentMeta = computed(() => tabMeta.value[activeTab.value]);

const toggleLock = () => {
    tabMeta.value[activeTab.value].locked = !tabMeta.value[activeTab.value].locked;
};

// ── Calculator State ───────────────────────────────
const showCalculator = ref(false);
const calcInput = ref('');
const evaluateCalc = () => {
    try {
        // Safe evaluation of basic math
        calcInput.value = Function('"use strict";return (' + calcInput.value + ')')().toString();
    } catch {
        calcInput.value = 'Error';
        setTimeout(() => calcInput.value = '', 1000);
    }
};

const cart = computed(() => tabs.value[activeTab.value]);
const currentCustomer = computed(() => tabCustomers.value[activeTab.value]);

const getTabTotal = (tabKey) => {
    let total = 0;
    tabs.value[tabKey].forEach(i => total += i.total_amount);
    return Math.round(total).toFixed(2);
};

// ── Batch modal ────────────────────────────────────
const batchModal = ref({ open: false, product: null, batches: [], loading: false, manualBatch: '' });

const selectProduct = async (product) => {
    searchQuery.value = '';
    searchResults.value = [];
    batchModal.value = { open: true, product, batches: [], loading: true };
    try {
        const res = await axios.post(route('pos.getProductBatches'), { product_id: product.id });
        batchModal.value.batches = res.data;
    } catch {}
    batchModal.value.loading = false;
    // If no batches in system, allow manual entry with blank batch
    if (!batchModal.value.batches.length) {
        addItemFromBatch(product, { batch_no: '', expiry_date: null, stock: 0, mrp: product.mrp });
        batchModal.value.open = false;
    }
};

const addItemFromBatch = (product, batch) => {
    if (currentMeta.value.locked) {
        alert("This bill is currently locked! Please unlock it to add items.");
        return;
    }
    const gstPercent = parseFloat(product.gst_percent || 0);
    const existing = tabs.value[activeTab.value].find(i => i.product_id === product.id && i.batch_no === batch.batch_no);
    if (existing) {
        existing.qty++;
        calcLine(existing);
    } else {
        const item = {
            _id: Date.now(),
            product_id: product.id,
            product_name: product.product_name,
            free_schema: product.free_schema || null,
            batch_no: batch.batch_no || '',
            expiry_date: batch.expiry_date || null,
            available_stock: parseFloat(batch.stock || 0),
            qty: 1,
            free_qty: 0,
            mrp: parseFloat(batch.mrp || product.mrp),
            rate: parseFloat(product.rate_a || product.mrp),
            discount_percent: 0,
            discount_amount: 0,
            taxable_amount: 0,
            gst_percent: gstPercent,
            gst_amount: 0,
            total_amount: 0,
        };
        calcLine(item);
        tabs.value[activeTab.value].push(item);
    }
    batchModal.value.open = false;
    setTimeout(() => searchInput.value?.focus(), 50);
};

const calcLine = (item) => {
    const parseFreeSchema = (schema) => {
        if (!schema || typeof schema !== 'string') return null;
        const match = schema.trim().match(/^(\d+)\s*\+\s*(\d+)$/);
        if (!match) return null;
        const buyQty = Number(match[1]);
        const freeQty = Number(match[2]);
        if (buyQty <= 0 || freeQty <= 0) return null;
        return { buyQty, freeQty };
    };

    const base = parseFloat(item.rate) * parseFloat(item.qty);
    const disc = base * (parseFloat(item.discount_percent) / 100);
    item.discount_amount = disc;
    item.taxable_amount = base - disc;
    item.gst_amount = item.taxable_amount * (parseFloat(item.gst_percent) / 100);
    item.total_amount = item.taxable_amount + item.gst_amount;

    const schema = parseFreeSchema(item.free_schema);
    item.free_qty = schema ? Math.floor(Number(item.qty || 0) / schema.buyQty) * schema.freeQty : 0;
};

const updateItem = (item) => {
    if (currentMeta.value.locked) return;
    calcLine(item);
};
const removeItem = (idx) => {
    if (currentMeta.value.locked) {
        alert("This bill is locked! Please unlock it to remove items.");
        return;
    }
    tabs.value[activeTab.value].splice(idx, 1);
};

// ── Totals ──────────────────────────────────────────
const cartTotals = computed(() => {
    let sub = 0, disc = 0, tax = 0, total = 0;
    cart.value.forEach(i => {
        sub += parseFloat(i.rate) * parseFloat(i.qty);
        disc += i.discount_amount;
        tax += i.gst_amount;
        total += i.total_amount;
    });
    return {
        subTotal: sub.toFixed(2),
        discAmount: disc.toFixed(2),
        taxAmount: tax.toFixed(2),
        total: Math.round(total).toFixed(2),
    };
});

// ── Customer ───────────────────────────────────────
const lookingUpCustomer = ref(false);
const creditInfoLoading = ref(false);
const creditModalOpen = ref(false);
const customerCredit = ref({
    pending_credit: 0,
    recent_bills: [],
    recent_collections: [],
});
const creditCollectionForm = ref({
    amount: '',
    payment_mode: 'cash',
    payment_date: new Date().toISOString().slice(0, 10),
    transaction_no: '',
    narration: '',
});
const creditSubmitting = ref(false);

const lookupCustomer = async () => {
    if (currentCustomer.value.mobile.length < 8) return;
    lookingUpCustomer.value = true;
    try {
        const res = await axios.post(route('pos.lookupCustomer'), { mobile: currentCustomer.value.mobile });
        if (res.data) {
            currentCustomer.value.id = res.data.id;
            currentCustomer.value.name = res.data.name;
            await loadCustomerCreditInfo();
        } else {
            customerCredit.value = { pending_credit: 0, recent_bills: [], recent_collections: [] };
        }
    } catch {}
    lookingUpCustomer.value = false;
};

const loadCustomerCreditInfo = async () => {
    if (!currentCustomer.value.id) {
        customerCredit.value = { pending_credit: 0, recent_bills: [], recent_collections: [] };
        return;
    }

    creditInfoLoading.value = true;
    try {
        const res = await axios.post(route('pos.customerCreditInfo'), {
            customer_id: currentCustomer.value.id,
        });
        customerCredit.value = {
            pending_credit: Number(res.data?.pending_credit || 0),
            recent_bills: res.data?.recent_bills || [],
            recent_collections: res.data?.recent_collections || [],
        };
    } catch {
        customerCredit.value = { pending_credit: 0, recent_bills: [], recent_collections: [] };
    } finally {
        creditInfoLoading.value = false;
    }
};

const openCreditCollectionModal = async () => {
    if (!currentCustomer.value.id) {
        alert('Select a customer first to collect outstanding credit.');
        return;
    }

    await loadCustomerCreditInfo();

    if ((customerCredit.value.pending_credit || 0) <= 0) {
        alert('This customer has no pending credit balance.');
        return;
    }

    creditCollectionForm.value.amount = Number(customerCredit.value.pending_credit).toFixed(2);
    creditCollectionForm.value.payment_mode = 'cash';
    creditCollectionForm.value.payment_date = new Date().toISOString().slice(0, 10);
    creditCollectionForm.value.transaction_no = '';
    creditCollectionForm.value.narration = '';
    creditModalOpen.value = true;
};

const submitCreditCollection = async () => {
    if (creditSubmitting.value || !currentCustomer.value.id) return;

    creditSubmitting.value = true;
    try {
        const payload = {
            customer_id: currentCustomer.value.id,
            amount: Number(creditCollectionForm.value.amount || 0),
            payment_mode: creditCollectionForm.value.payment_mode,
            payment_date: creditCollectionForm.value.payment_date,
            transaction_no: creditCollectionForm.value.transaction_no || null,
            narration: creditCollectionForm.value.narration || null,
        };

        const res = await axios.post(route('pos.creditCollect'), payload);

        customerCredit.value.pending_credit = Number(res.data?.pending_credit || 0);
        customerCredit.value.recent_bills = res.data?.recent_bills || [];
        customerCredit.value.recent_collections = res.data?.recent_collections || [];
        creditModalOpen.value = false;
        alert('Credit collected successfully.');
    } catch (e) {
        const msg = e?.response?.data?.message || 'Unable to collect credit right now.';
        alert(msg);
    } finally {
        creditSubmitting.value = false;
    }
};

// ── Doctor ─────────────────────────────────────────
const doctorResults = ref([]);
let doctorTimeout = null;

const onDoctorSearch = () => {
    clearTimeout(doctorTimeout);
    if (currentCustomer.value.doctorSearch.length < 2) { doctorResults.value = []; return; }
    doctorTimeout = setTimeout(async () => {
        try {
            const res = await axios.post(route('pos.searchDoctors'), { term: currentCustomer.value.doctorSearch });
            doctorResults.value = res.data;
        } catch { doctorResults.value = []; }
    }, 300);
};

const selectDoctor = (d) => {
    currentCustomer.value.selectedDoctor = d;
    currentCustomer.value.doctorSearch = d.name;
    doctorResults.value = [];
};

// ── Checkout ────────────────────────────────────────
const isCheckoutModalOpen = ref(false);
const paymentMode = ref('cash');
const splitCashAmt = ref(0);
const splitBankAmt = ref(0);
const transactionNo = ref('');
const isSubmitting = ref(false);
const billNo = ref('');

const openCheckout = async () => {
    if (cart.value.length === 0) return;
    try {
        const res = await axios.get(route('pos.nextBillNumber'));
        billNo.value = res.data.bill_no;
    } catch {
        billNo.value = 'BILL-' + Date.now();
    }
    splitCashAmt.value = parseFloat(cartTotals.value.total);
    splitBankAmt.value = 0;
    isCheckoutModalOpen.value = true;
};

const onPaymentModeChange = () => {
    const t = parseFloat(cartTotals.value.total);
    if (paymentMode.value === 'cash') { splitCashAmt.value = t; splitBankAmt.value = 0; }
    else if (paymentMode.value === 'bank') { splitCashAmt.value = 0; splitBankAmt.value = t; }
    else if (paymentMode.value === 'credit') { splitCashAmt.value = 0; splitBankAmt.value = 0; }
    else { splitCashAmt.value = 0; splitBankAmt.value = 0; } // cashCredit/bankCredit — user fills
};

const submitSale = async () => {
    if (isSubmitting.value) return;
    isSubmitting.value = true;
    const totals = cartTotals.value;
    const creditAmt = paymentMode.value === 'credit'
        ? parseFloat(totals.total)
        : Math.max(0, parseFloat(totals.total) - splitCashAmt.value - splitBankAmt.value);

    try {
        const res = await axios.post(route('pos.checkout'), {
            bill_no: billNo.value,
            customer_id: currentCustomer.value.id ?? null,
            customer_name: currentCustomer.value.name || null,
            customer_mobile: currentCustomer.value.mobile || null,
            doctor_id: currentCustomer.value.selectedDoctor?.id ?? null,
            items: cart.value.map(i => ({
                product_id: i.product_id,
                batch_no: i.batch_no,
                expiry_date: i.expiry_date || null,
                mrp: i.mrp,
                rate: i.rate,
                qty: i.qty,
                free_qty: i.free_qty || 0,
                discount_percent: i.discount_percent,
            })),
            payment_mode: paymentMode.value,
            cash_amount: splitCashAmt.value,
            bank_amount: splitBankAmt.value,
            credit_amount: creditAmt,
            transaction_no: transactionNo.value || null,
            wallet_type: paymentMode.value === 'bank' ? 'UPI' : null,
            sub_total: totals.subTotal,
            total_discount_amount: totals.discAmount,
            total_tax_amount: totals.taxAmount,
            total_amount: totals.total,
        });

        if (res.data.success) {
            isCheckoutModalOpen.value = false;
            // Clear current tab
            tabs.value[activeTab.value] = [];
            tabCustomers.value[activeTab.value] = emptyCustomerState();
            tabMeta.value[activeTab.value].locked = false;
            customerCredit.value = { pending_credit: 0, recent_bills: [], recent_collections: [] };
            transactionNo.value = '';
            alert(`Bill ${res.data.bill_no} saved successfully!`);
            searchInput.value?.focus();
        }
    } catch (e) {
        const msg = e?.response?.data?.message || 'Error processing sale. Please try again.';
        alert(msg);
    } finally {
        isSubmitting.value = false;
    }
};

// ── Keyboard ────────────────────────────────────────
const handleKeyboard = (e) => {
    // Multi-Tab Fast Switching
    if (e.key === 'F1') { e.preventDefault(); activeTab.value = 'F1'; }
    if (e.key === 'F2') { e.preventDefault(); activeTab.value = 'F2'; }
    if (e.key === 'F3') { e.preventDefault(); activeTab.value = 'F3'; }
    if (e.key === 'F4') { e.preventDefault(); activeTab.value = 'F4'; }
    if (e.key === 'F5') { e.preventDefault(); activeTab.value = 'F5'; }
    
    // `~` for RCS works better without shift
    if (e.key === '`' || e.key === '~') { e.preventDefault(); activeTab.value = 'RCS'; }

    // Bill Locks & Tools
    if (e.key === 'F8') { e.preventDefault(); toggleLock(); }
    if (e.key === 'F10') { e.preventDefault(); showCalculator.value = !showCalculator.value; }

    // F9 for Checkout!
    if (e.key === 'F9') { e.preventDefault(); openCheckout(); }
    
    if (e.key === 'Escape') {
        if (batchModal.value.open) batchModal.value.open = false;
        else if (creditModalOpen.value) creditModalOpen.value = false;
        else if (isCheckoutModalOpen.value) isCheckoutModalOpen.value = false;
        else searchQuery.value = '';
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeyboard);
    searchInput.value?.focus();
});
onUnmounted(() => window.removeEventListener('keydown', handleKeyboard));
</script>

<template>
    <Head title="Retail POS" />

    <div class="h-screen flex flex-col bg-gray-100 overflow-hidden font-sans">

        <!-- ── Top Nav ─────────────────────────────────── -->
        <header class="bg-gray-900 shadow text-white shrink-0">
            <div class="px-4 py-2 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <h1 class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                        <BanknotesIcon class="w-6 h-6 text-emerald-400" />
                        BrainYug POS
                    </h1>
                    <span class="text-xs px-2 py-1 bg-gray-800 rounded text-gray-400 border border-gray-700 hidden md:inline-block">
                        F1-F5: Switch Bills <span class="text-gray-600 mx-1">|</span> F8: Lock <span class="text-gray-600 mx-1">|</span> F9: Checkout <span class="text-gray-600 mx-1">|</span> F10: Calculator <span class="text-gray-600 mx-1">|</span> ESC: Clear
                    </span>
                </div>
                <div class="flex gap-4 items-center">
                    <a :href="route('dashboard')" class="text-sm text-gray-300 hover:text-white transition-colors underline">Exit to ERP</a>
                </div>
            </div>

            <!-- MULTI-TAB ROW (Mimicking Legacy) -->
            <div class="flex items-end px-2 pt-1 mt-1 gap-1 overflow-x-auto custom-scrollbar">
                <button 
                    v-for="tab in posTabsConfig"
                    :key="tab.key"
                    @click="activeTab = tab.key"
                    class="px-5 py-2 rounded-t-lg transition-all duration-200 flex flex-col min-w-[125px] border-b-0 border-t-2 relative flex-1"
                    :class="[
                        activeTab === tab.key 
                            ? `bg-gray-50 border-x border-gray-300 z-10 scale-100 ${tab.color} ${tab.text} shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.1)]` 
                            : 'bg-gray-800 border-gray-700 text-gray-400 hover:bg-gray-700 scale-[0.98] origin-bottom'
                    ]"
                >
                    <div class="w-full flex justify-between items-center mb-0.5">
                        <div class="flex items-center gap-1">
                            <span class="font-bold text-sm">{{ tab.label }}</span>
                            <LockClosedIcon v-if="tabMeta[tab.key].locked" class="w-3 h-3 text-red-500" title="Bill Locked" />
                        </div>
                        <span v-if="tabs[tab.key].length > 0" class="flex h-4 w-4 rounded-full bg-red-500 text-[10px] text-white items-center justify-center font-bold">
                            {{ tabs[tab.key].length }}
                        </span>
                    </div>
                    <div class="w-full text-left font-mono" :class="activeTab === tab.key ? 'text-xl font-black' : 'text-sm'">
                        ₹{{ getTabTotal(tab.key) }}
                    </div>
                    <!-- Indicator Strip matching legacy style exactly -->
                    <div v-if="activeTab === tab.key" class="absolute -bottom-[2px] left-0 w-full h-[3px] bg-gray-50 z-20"></div>
                </button>
            </div>
            
            <!-- Quick Entry Bar - Matches Active Tab BG -->
            <div class="bg-gray-50 border-b border-gray-300 px-4 py-3 flex flex-col md:flex-row gap-4 shadow-inner">
                <!-- Product Search -->
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <MagnifyingGlassIcon class="h-5 w-5 text-gray-400" />
                    </div>
                    <input
                        ref="searchInput"
                        v-model="searchQuery"
                        @input="onSearchInput"
                        type="text"
                        :disabled="currentMeta.locked"
                        class="block w-full pl-10 border-gray-300 bg-white text-gray-900 focus:ring-emerald-500 focus:border-emerald-500 text-base py-2 font-mono shadow-sm rounded-md disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed"
                        :placeholder="currentMeta.locked ? `[${activeTab}] Bill is Locked (F8 to Unlock)` : `[${activeTab}] Scan Barcode or Search Product... (min 2 chars)`"
                        autocomplete="off"
                    >
                    <!-- Search Dropdown -->
                    <div v-if="searchResults.length > 0" class="absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-2xl max-h-80 overflow-y-auto">
                        <ul class="py-1">
                            <li
                                v-for="product in searchResults"
                                :key="product.id"
                                @click="selectProduct(product)"
                                class="px-4 py-2 hover:bg-emerald-50 cursor-pointer flex justify-between items-center text-gray-900 border-b border-gray-100 last:border-0"
                            >
                                <div>
                                    <div class="font-bold text-sm">{{ product.product_name }}</div>
                                    <div class="text-xs text-gray-500 font-mono">{{ product.sku }} | GST: {{ Number(product.gst_percent || 0).toFixed(2) }}%</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-emerald-700">₹{{ Number(product.rate_a).toFixed(2) }}</div>
                                    <div class="text-xs text-gray-400">MRP: ₹{{ Number(product.mrp).toFixed(2) }}</div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div v-if="isSearching" class="absolute z-50 w-full mt-1 bg-white border rounded-md shadow p-3 text-sm text-gray-500">Searching...</div>
                </div>

                <div class="flex gap-2 items-center" style="max-width:340px;width:100%">
                    <!-- Toolbar replacing the legacy "under bills" features -->
                    <button @click="showCalculator = !showCalculator" class="p-2 border border-slate-300 bg-white rounded text-slate-600 hover:text-emerald-600 hover:bg-emerald-50 transition-colors shadow-sm" title="Calculator (F10)">
                        <CalculatorIcon class="w-5 h-5" />
                    </button>
                    <button @click="toggleLock" :class="currentMeta.locked ? 'bg-red-50 text-red-600 border-red-200' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'" class="p-2 border rounded transition-colors shadow-sm" :title="currentMeta.locked ? 'Unlock Bill (F8)' : 'Lock Bill (F8)'">
                        <LockClosedIcon v-if="currentMeta.locked" class="w-5 h-5" />
                        <LockOpenIcon v-else class="w-5 h-5" />
                    </button>
                    <!-- Small divider -->
                    <span class="w-px h-6 bg-gray-300 mx-1"></span>

                    <input
                        v-model="currentCustomer.mobile"
                        @keyup.enter="lookupCustomer"
                        type="text"
                        maxlength="12"
                        placeholder="Customer Mobile..."
                        class="flex-1 w-full border-gray-300 bg-white text-gray-900 focus:ring-emerald-500 py-2 sm:text-sm px-3 shadow-sm rounded-md min-w-[120px]"
                    >
                    <button @click="lookupCustomer" :disabled="lookingUpCustomer" class="px-3 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold text-sm rounded-md shrink-0 shadow-sm border border-slate-900">
                        {{ lookingUpCustomer ? '...' : 'Find' }}
                    </button>
                    <button
                        @click="openCreditCollectionModal"
                        :disabled="!currentCustomer.id || creditInfoLoading"
                        class="px-3 py-2 bg-amber-500 hover:bg-amber-600 text-slate-900 font-bold text-sm rounded-md shrink-0 shadow-sm border border-amber-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        title="Collect outstanding customer credit"
                    >
                        Collect
                    </button>
                    <div v-if="currentCustomer.id" class="text-xs font-bold text-emerald-700 truncate max-w-[120px] fixed top-16 right-4 bg-white px-2 py-1 rounded shadow border border-emerald-100 z-50">✓ {{ currentCustomer.name }}</div>
                    <div
                        v-if="currentCustomer.id && Number(customerCredit.pending_credit || 0) > 0"
                        class="fixed top-24 right-4 bg-amber-50 text-amber-800 text-xs px-3 py-1 rounded border border-amber-200 z-50 font-bold shadow"
                    >
                        Pending Credit: ₹{{ Number(customerCredit.pending_credit).toFixed(2) }}
                    </div>
                </div>
            </div>
        </header>

        <!-- ── Main Content ─────────────────────────────── -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Cart Table -->
            <div class="flex-1 bg-white h-full flex flex-col">
                <div class="flex-1 overflow-y-auto overflow-x-auto bg-gray-50/50">
                    <table class="w-full min-w-[800px] divide-y divide-gray-200">
                        <thead class="bg-gray-100 sticky top-0 z-10 shadow-sm">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-bold text-gray-600 uppercase w-8 border-b">#</th>
                                <th class="px-3 py-3 text-left text-xs font-bold text-gray-600 uppercase border-b">Product</th>
                                <th class="px-3 py-3 text-center text-xs font-bold text-gray-600 uppercase w-28 border-b">Batch</th>
                                <th class="px-3 py-3 text-right text-xs font-bold text-gray-600 uppercase w-20 border-b">MRP</th>
                                <th class="px-3 py-3 text-right text-xs font-bold text-gray-600 uppercase w-24 border-b">Rate</th>
                                <th class="px-3 py-3 text-center text-xs font-bold text-gray-600 uppercase w-20 border-b">Qty</th>
                                <th class="px-3 py-3 text-center text-xs font-bold text-gray-600 uppercase w-20 border-b">Disc%</th>
                                <th class="px-3 py-3 text-right text-xs font-bold text-gray-600 uppercase w-28 border-b">GST</th>
                                <th class="px-3 py-3 text-right text-xs font-bold text-gray-900 uppercase w-28 border-b">Total</th>
                                <th class="px-3 py-3 w-10 border-b"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <tr v-if="cart.length === 0">
                                <td colspan="10" class="px-4 py-24 text-center text-gray-400">
                                    <ShoppingCartIcon class="w-16 h-16 mx-auto mb-4 text-gray-200" />
                                    <p class="text-gray-500 font-medium text-lg">Cart is empty for {{ activeTab }}.</p>
                                    <p class="text-sm mt-1">Search or scan a barcode to add items to this bill.</p>
                                </td>
                            </tr>
                            <tr v-for="(item, idx) in cart" :key="item._id" :class="posTabsConfig.find(t=>t.key === activeTab)?.bg" class="hover:brightness-95 transition-all">
                                <td class="px-3 py-2 text-sm text-gray-500 font-mono">{{ idx + 1 }}</td>
                                <td class="px-3 py-2">
                                    <div class="font-bold text-gray-900 text-sm">{{ item.product_name }}</div>
                                    <div class="text-xs text-gray-500">GST {{ item.gst_percent }}%</div>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" v-model="item.batch_no" :disabled="currentMeta.locked" class="w-full text-center border-gray-300 rounded text-xs py-1 uppercase shadow-sm focus:ring-emerald-500 disabled:opacity-50" />
                                    <div v-if="item.expiry_date" class="text-[10px] text-center font-bold text-orange-600 mt-0.5">Exp: {{ item.expiry_date?.substring(0,7) }}</div>
                                </td>
                                <td class="px-3 py-2 text-right text-sm text-gray-600 font-mono">{{ Number(item.mrp).toFixed(2) }}</td>
                                <td class="px-3 py-2">
                                    <input type="number" step="0.01" v-model="item.rate" @input="updateItem(item)" :disabled="currentMeta.locked" class="w-20 text-right border-gray-300 rounded text-sm py-1 shadow-sm focus:ring-emerald-500 font-mono font-bold disabled:opacity-50" />
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" min="1" v-model="item.qty" @input="updateItem(item)" :disabled="currentMeta.locked" class="w-16 text-center border-emerald-500 rounded text-base font-bold text-emerald-900 bg-emerald-50 py-1 shadow-sm focus:ring-emerald-500 disabled:opacity-50" />
                                    <div v-if="item.free_qty > 0" class="text-[10px] text-center font-bold text-amber-600 mt-0.5">+{{ item.free_qty }} FREE</div>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="number" min="0" step="0.5" v-model="item.discount_percent" @input="updateItem(item)" :disabled="currentMeta.locked" class="w-16 text-center border-gray-300 rounded text-sm py-1 shadow-sm focus:ring-emerald-500 disabled:opacity-50" />
                                </td>
                                <td class="px-3 py-2 text-right text-sm text-gray-500 font-mono">{{ item.gst_amount.toFixed(2) }}</td>
                                <td class="px-3 py-2 text-right text-base font-bold text-gray-900 font-mono">{{ item.total_amount.toFixed(2) }}</td>
                                <td class="px-3 py-2">
                                    <button @click="removeItem(idx)" :disabled="currentMeta.locked" class="text-red-500 hover:text-red-700 p-1.5 rounded transition hover:bg-red-100 disabled:opacity-20 disabled:cursor-not-allowed">
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Footer Totals + Pay Button -->
                <div class="bg-slate-900 text-white p-3 sm:p-4 shrink-0 grid grid-cols-2 sm:grid-cols-6 gap-3 sm:gap-4 border-t-4 border-emerald-500 shadow-[0_-10px_15px_-3px_rgba(0,0,0,0.1)] relative">
                    <!-- Calculator Widget (Absolute positioned above footer when active) -->
                    <div v-if="showCalculator" class="absolute bottom-[100%] right-4 mb-2 w-64 bg-slate-800 border border-slate-700 rounded-lg shadow-2xl overflow-hidden z-50">
                        <div class="p-3 bg-slate-900 border-b border-slate-700 flex justify-between items-center text-emerald-400 font-mono">
                            <span class="font-bold text-sm tracking-widest uppercase">Calculator</span>
                            <button @click="showCalculator = false" class="text-slate-500 hover:text-white"><XMarkIcon class="w-4 h-4" /></button>
                        </div>
                        <div class="p-3">
                            <input v-model="calcInput" @keyup.enter="evaluateCalc" type="text" class="w-full bg-slate-950 text-white border-0 focus:ring-1 focus:ring-emerald-500 rounded font-mono text-right text-lg py-2 mb-2" placeholder="0">
                            <div class="grid grid-cols-4 gap-1">
                                <button @click="calcInput += '7'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">7</button>
                                <button @click="calcInput += '8'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">8</button>
                                <button @click="calcInput += '9'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">9</button>
                                <button @click="calcInput += '/'" class="bg-emerald-700/50 hover:bg-emerald-600 rounded p-2 text-center font-bold text-emerald-300">/</button>

                                <button @click="calcInput += '4'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">4</button>
                                <button @click="calcInput += '5'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">5</button>
                                <button @click="calcInput += '6'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">6</button>
                                <button @click="calcInput += '*'" class="bg-emerald-700/50 hover:bg-emerald-600 rounded p-2 text-center font-bold text-emerald-300">*</button>

                                <button @click="calcInput += '1'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">1</button>
                                <button @click="calcInput += '2'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">2</button>
                                <button @click="calcInput += '3'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">3</button>
                                <button @click="calcInput += '-'" class="bg-emerald-700/50 hover:bg-emerald-600 rounded p-2 text-center font-bold text-emerald-300">-</button>

                                <button @click="calcInput = ''" class="bg-red-900/50 hover:bg-red-800 rounded p-2 text-center font-bold text-red-300">C</button>
                                <button @click="calcInput += '0'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">0</button>
                                <button @click="calcInput += '.'" class="bg-slate-700 hover:bg-slate-600 rounded p-2 text-center font-bold">.</button>
                                <button @click="calcInput += '+'" class="bg-emerald-700/50 hover:bg-emerald-600 rounded p-2 text-center font-bold text-emerald-300">+</button>
                                
                                <button @click="evaluateCalc" class="bg-emerald-500 hover:bg-emerald-400 text-slate-900 rounded p-2 text-center font-black col-span-4 mt-1">=</button>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1 border-r border-slate-700 pr-3 sm:pr-4">
                        <div class="text-xs text-slate-400 uppercase mb-1 font-bold tracking-wider">Tab Info</div>
                        <div class="text-xl font-bold text-white flex gap-2 items-center">
                            <span class="px-2 bg-slate-800 border border-slate-600 rounded" :class="posTabsConfig.find(t=>t.key === activeTab)?.text">{{ activeTab }}</span>
                            <span class="text-sm font-mono text-slate-300">{{ cart.length }} items</span>
                        </div>
                    </div>
                    <div class="col-span-1 border-r border-slate-700 sm:pr-4">
                        <div class="text-xs text-slate-400 uppercase mb-1 font-bold tracking-wider">Subtotal</div>
                        <div class="text-xl font-mono text-slate-300">₹ {{ cartTotals.subTotal }}</div>
                    </div>
                    <div class="col-span-1 border-r border-slate-700 pr-4 hidden sm:block">
                        <div class="text-xs text-emerald-400 uppercase mb-1 font-bold tracking-wider">Discount</div>
                        <div class="text-xl font-mono text-emerald-400">- ₹ {{ cartTotals.discAmount }}</div>
                    </div>
                    <div class="col-span-1 border-r border-slate-700 pr-4 hidden sm:block">
                        <div class="text-xs text-slate-400 uppercase mb-1 font-bold tracking-wider">GST</div>
                        <div class="text-xl font-mono text-slate-400">+ ₹ {{ cartTotals.taxAmount }}</div>
                    </div>
                    <div class="col-span-1 border-r border-slate-700 pr-4 hidden sm:flex flex-col gap-2 justify-center">
                        <button class="w-full py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded border border-slate-700 flex items-center justify-center gap-1 transition" title="Save as PDF">
                            <DocumentTextIcon class="w-4 h-4 text-rose-400" /> PDF
                        </button>
                        <button class="w-full py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded border border-slate-700 flex items-center justify-center gap-1 transition" title="Export to Excel">
                            <TableCellsIcon class="w-4 h-4 text-emerald-400" /> Excel
                        </button>
                    </div>
                    <div class="col-span-2 sm:col-span-1 flex flex-col justify-center">
                        <button
                            @click="openCheckout"
                            :disabled="cart.length === 0"
                            class="w-full h-full bg-emerald-500 hover:bg-emerald-600 disabled:opacity-40 text-black font-black py-2 px-4 rounded shadow-lg flex items-center justify-center gap-2 active:scale-95 transition-all outline-none focus:ring-4 focus:ring-emerald-500/50"
                        >
                            <CreditCardIcon class="w-6 h-6" />
                            <span class="sm:hidden">BILL ₹{{ cartTotals.total }}</span>
                            <span class="hidden sm:inline">BILL (F9)</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Batch Selection Modal ──────────────────── -->
        <div v-if="batchModal.open" class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/80 backdrop-blur-sm p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200">
                <div class="flex items-center justify-between px-6 py-4 border-b bg-gray-50">
                    <div>
                        <h3 class="text-lg font-black text-gray-900 leading-tight">{{ batchModal.product?.product_name }}</h3>
                        <p class="text-xs text-gray-500 font-mono mt-0.5">Select a batch to add to {{ activeTab }}</p>
                    </div>
                    <button @click="batchModal.open = false" class="text-gray-400 hover:text-gray-600 bg-white shadow-sm border p-1 rounded transition"><XMarkIcon class="w-5 h-5" /></button>
                </div>
                <div class="p-4 bg-white">
                    <div v-if="batchModal.loading" class="py-8 text-center text-gray-500 font-medium">Scanning warehouse for batches...</div>
                    <div v-else-if="!batchModal.batches.length" class="py-8 text-center text-gray-500">
                        <p class="mb-4 font-medium">No stock directly found. You can add a manual batch.</p>
                        <div class="flex items-center justify-center gap-2">
                            <input
                                v-model="batchModal.manualBatch"
                                type="text"
                                placeholder="Enter Manual Batch No."
                                class="border-gray-300 shadow-sm rounded text-sm w-48 text-center uppercase focus:ring-emerald-500 focus:border-emerald-500"
                            >
                            <button
                                @click="addItemFromBatch(batchModal.product, { batch_no: batchModal.manualBatch || '--', expiry_date: null, stock: 0, mrp: batchModal.product.mrp })"
                                class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm rounded transition"
                            >Add Item</button>
                        </div>
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-100 text-[11px] font-bold uppercase text-gray-600 tracking-wider">
                                <th class="px-4 py-3 text-left rounded-tl">Batch No.</th>
                                <th class="px-4 py-3 text-center">Expiry</th>
                                <th class="px-4 py-3 text-right">MRP</th>
                                <th class="px-4 py-3 text-right">Stock</th>
                                <th class="px-4 py-3 rounded-tr"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="batch in batchModal.batches"
                                :key="batch.batch_no"
                                class="hover:bg-emerald-50/50 cursor-pointer transition-colors"
                                @click="addItemFromBatch(batchModal.product, batch)"
                            >
                                <td class="px-4 py-3 font-mono font-bold text-slate-800">{{ batch.batch_no }}</td>
                                <td class="px-4 py-3 text-center" :class="batch.expiry_date && new Date(batch.expiry_date) < new Date(Date.now() + 90*86400000) ? 'text-red-600 font-bold bg-red-50 rounded' : 'text-slate-500'">
                                    {{ batch.expiry_date?.substring(0,7) || '—' }}
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-slate-600">₹{{ Number(batch.mrp).toFixed(2) }}</td>
                                <td class="px-4 py-3 text-right font-black" :class="parseFloat(batch.stock) <= 0 ? 'text-red-500' : 'text-emerald-600'">
                                    {{ batch.stock }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button class="text-xs bg-slate-900 hover:bg-emerald-600 font-bold text-white px-3 py-1.5 rounded transition">Add</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ── Credit Collection Modal ──────────────── -->
        <div v-show="creditModalOpen" class="fixed inset-0 z-[70] overflow-y-auto">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" @click="creditModalOpen = false"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full border-t-8 border-amber-500">
                    <div class="bg-amber-50 px-6 py-5 border-b border-amber-100 flex items-center justify-between">
                        <div>
                            <h3 class="text-2xl font-black text-slate-900 tracking-tight">Collect Customer Credit</h3>
                            <p class="text-sm text-slate-600 mt-1">{{ currentCustomer.name || 'Customer' }} • Pending: ₹{{ Number(customerCredit.pending_credit || 0).toFixed(2) }}</p>
                        </div>
                        <button @click="creditModalOpen = false" class="text-slate-400 hover:text-slate-700"><XMarkIcon class="w-6 h-6" /></button>
                    </div>

                    <div class="px-6 py-5 grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-3">Collection Entry</h4>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Amount</label>
                                    <input v-model="creditCollectionForm.amount" type="number" min="0.01" step="0.01" class="w-full border-gray-300 rounded shadow-sm focus:ring-amber-500 font-mono text-lg font-bold" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Mode</label>
                                        <select v-model="creditCollectionForm.payment_mode" class="w-full border-gray-300 rounded shadow-sm focus:ring-amber-500 text-sm">
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank/UPI</option>
                                            <option value="upi">UPI</option>
                                            <option value="card">Card</option>
                                            <option value="cheque">Cheque</option>
                                            <option value="neft">NEFT</option>
                                            <option value="rtgs">RTGS</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Date</label>
                                        <input v-model="creditCollectionForm.payment_date" type="date" class="w-full border-gray-300 rounded shadow-sm focus:ring-amber-500 text-sm" />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Transaction No (optional)</label>
                                    <input v-model="creditCollectionForm.transaction_no" type="text" class="w-full border-gray-300 rounded shadow-sm focus:ring-amber-500 font-mono text-sm uppercase" placeholder="UPI / UTR / cheque ref" />
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Narration (optional)</label>
                                    <textarea v-model="creditCollectionForm.narration" rows="3" class="w-full border-gray-300 rounded shadow-sm focus:ring-amber-500 text-sm" placeholder="Collection notes"></textarea>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h4 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-3">Outstanding Bills (Latest 10)</h4>
                            <div class="border rounded-lg overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-100 text-xs uppercase tracking-wider text-slate-500 font-bold">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Bill</th>
                                            <th class="px-3 py-2 text-right">Credit</th>
                                            <th class="px-3 py-2 text-right">Collected</th>
                                            <th class="px-3 py-2 text-right">Outstanding</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <tr v-if="!customerCredit.recent_bills?.length">
                                            <td colspan="4" class="px-3 py-6 text-center text-slate-400">No bill history found.</td>
                                        </tr>
                                        <tr v-for="bill in customerCredit.recent_bills" :key="bill.id">
                                            <td class="px-3 py-2 text-slate-700 font-mono">{{ bill.bill_no }}</td>
                                            <td class="px-3 py-2 text-right text-slate-600">₹{{ Number(bill.credit_amount || 0).toFixed(2) }}</td>
                                            <td class="px-3 py-2 text-right text-emerald-700">₹{{ Number(bill.collected_amount || 0).toFixed(2) }}</td>
                                            <td class="px-3 py-2 text-right font-bold" :class="Number(bill.outstanding_credit || 0) > 0 ? 'text-amber-700' : 'text-slate-400'">
                                                ₹{{ Number(bill.outstanding_credit || 0).toFixed(2) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-6 py-4 flex justify-end gap-3 border-t border-gray-200">
                        <button @click="creditModalOpen = false" class="py-2 px-5 border border-gray-300 rounded font-bold text-gray-600 bg-white hover:bg-gray-50">Cancel</button>
                        <button
                            @click="submitCreditCollection"
                            :disabled="creditSubmitting"
                            class="py-2 px-6 bg-amber-500 hover:bg-amber-600 text-slate-900 font-black rounded shadow disabled:opacity-50"
                        >
                            {{ creditSubmitting ? 'Saving...' : 'Collect Credit' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Checkout / Payment Modal ──────────────── -->
        <div v-show="isCheckoutModalOpen" class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" @click="isCheckoutModalOpen = false"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border-t-8 shadow-emerald-900/50" :class="posTabsConfig.find(t=>t.key === activeTab)?.color">
                    <div class="bg-slate-50 px-6 py-5 border-b flex justify-between items-center relative overflow-hidden">
                        <div class="absolute inset-0 opacity-10" :class="posTabsConfig.find(t=>t.key === activeTab)?.bg"></div>
                        <div class="relative z-10">
                            <h3 class="text-2xl font-black text-slate-900 tracking-tight">Generate Bill ({{ activeTab }})</h3>
                            <p class="text-sm text-slate-500 font-mono mt-1 font-medium">Invoice No: {{ billNo }}</p>
                        </div>
                        <div class="text-4xl font-mono font-black text-emerald-600 relative z-10">₹ {{ cartTotals.total }}</div>
                    </div>

                    <div class="px-6 py-6 bg-white space-y-6">
                        <div class="grid grid-cols-2 gap-8">
                            <!-- Customer / Doctor -->
                            <div>
                                <h4 class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Customer Details</h4>
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Mobile Number</label>
                                        <input type="text" v-model="currentCustomer.mobile" class="w-full text-base font-mono border-gray-300 rounded shadow-sm focus:ring-emerald-500" placeholder="9876543210" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Customer Name</label>
                                        <input type="text" v-model="currentCustomer.name" class="w-full text-sm border-gray-300 rounded shadow-sm focus:ring-emerald-500 font-semibold text-slate-900" placeholder="Walk-in Customer" />
                                    </div>
                                    <div class="relative">
                                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Consulting Doctor</label>
                                        <input
                                            type="text"
                                            v-model="currentCustomer.doctorSearch"
                                            @input="onDoctorSearch"
                                            class="w-full text-sm border-gray-300 rounded shadow-sm focus:ring-emerald-500"
                                            placeholder="Search doctor name..."
                                        >
                                        <div v-if="doctorResults.length" class="absolute z-50 w-full bg-white border rounded shadow-xl mt-1 max-h-48 overflow-y-auto">
                                            <div
                                                v-for="d in doctorResults"
                                                :key="d.id"
                                                @click="selectDoctor(d)"
                                                class="px-4 py-3 hover:bg-emerald-50 cursor-pointer border-b last:border-0"
                                            >
                                                <div class="text-sm font-bold text-slate-900">Dr. {{ d.name }}</div>
                                                <div class="text-xs text-slate-500 font-mono mt-0.5">{{ d.reg_no }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Mode -->
                            <div class="border-l pl-8">
                                <h4 class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Payment Method</h4>
                                <div class="space-y-5">
                                    <div class="flex gap-2">
                                        <label v-for="m in [{v:'cash',l:'Cash'},{v:'bank',l:'UPI/Card'},{v:'credit',l:'Credit'}]" :key="m.v" class="flex-1 cursor-pointer">
                                            <input type="radio" v-model="paymentMode" :value="m.v" @change="onPaymentModeChange" class="sr-only peer">
                                            <div class="text-center py-2 border border-gray-300 rounded shadow-sm font-bold text-sm bg-white text-gray-600 peer-checked:bg-emerald-600 peer-checked:border-emerald-600 peer-checked:text-white transition-all">{{ m.l }}</div>
                                        </label>
                                    </div>
                                    <div v-if="paymentMode === 'cashBank'" class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs font-bold text-slate-700 mb-1 block">Cash Portion</label>
                                            <input type="number" v-model="splitCashAmt" class="w-full text-base font-mono font-bold text-emerald-700 border-gray-300 rounded py-1 shadow-sm" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-bold text-slate-700 mb-1 block">UPI Portion</label>
                                            <input type="number" v-model="splitBankAmt" class="w-full text-base font-mono font-bold text-emerald-700 border-gray-300 rounded py-1 shadow-sm" />
                                        </div>
                                    </div>
                                    <div v-if="paymentMode === 'bank'">
                                        <label class="text-xs font-bold text-slate-700 mb-1 block">Transaction / UTR No.</label>
                                        <input type="text" v-model="transactionNo" class="w-full text-sm border-gray-300 rounded py-2 shadow-sm uppercase font-mono" placeholder="PhonePe / GPay Ref" />
                                    </div>
                                    <div v-if="paymentMode === 'credit'" class="p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800 flex gap-3 shadow-inner">
                                        <svg class="w-6 h-6 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                        <div>
                                            <span class="font-bold block mb-1">Credit Sale Warning</span>
                                            Current customer ledger will reflect this outstanding amount automatically.
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-6 py-5 flex justify-between items-center border-t border-gray-200">
                        <button @click="isCheckoutModalOpen = false" class="py-2.5 px-6 border-2 border-gray-300 rounded font-bold text-gray-600 bg-white hover:bg-gray-50 hover:text-gray-900 transition-colors">
                            Cancel (ESC)
                        </button>
                        <button
                            @click="submitSale"
                            :disabled="isSubmitting"
                            class="py-3 px-10 bg-emerald-600 hover:bg-emerald-700 text-white font-black rounded-lg shadow-lg shadow-emerald-600/30 disabled:opacity-50 text-lg flex items-center gap-3 transition-colors outline-none focus:ring-4 focus:ring-emerald-500/50"
                        >
                            {{ isSubmitting ? 'Verifying & Saving...' : 'Confirm Bill & Print' }}
                            <svg v-if="!isSubmitting" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
