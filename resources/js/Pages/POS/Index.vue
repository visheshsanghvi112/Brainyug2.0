<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import {
    MagnifyingGlassIcon,
    TrashIcon,
    BanknotesIcon,
    CreditCardIcon,
    ArchiveBoxIcon,
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
    pos_preferences: {
        type: Object,
        default: () => ({}),
    },
    active_shift: {
        type: Object,
        default: null,
    },
});

const smartBatchSuggestionEnabled = computed(() => props.pos_preferences?.smart_batch_suggestion !== false);

const activeShift = ref(props.active_shift ?? null);
const shiftModalOpen = ref(false);
const shiftMode = ref('open');
const shiftSubmitting = ref(false);
const shiftError = ref('');
const shiftOpeningCash = ref(0);
const shiftClosingCash = ref(0);
const shiftNote = ref('');

const hasActiveShift = computed(() => !!activeShift.value && activeShift.value.status === 'open');

const openShiftModal = (mode = 'open') => {
    shiftMode.value = mode;
    shiftError.value = '';
    shiftNote.value = '';

    if (mode === 'open') {
        shiftOpeningCash.value = 0;
    } else {
        const opening = Number(activeShift.value?.opening_cash || 0);
        const expected = Number(activeShift.value?.summary?.cash_sales || 0) + opening;
        shiftClosingCash.value = Number(expected.toFixed(2));
    }

    shiftModalOpen.value = true;
};

const fetchShiftStatus = async () => {
    try {
        const res = await axios.get(route('pos.shift.status'));
        activeShift.value = res.data?.active_shift || null;
    } catch {
        activeShift.value = null;
    }
};

const submitShiftAction = async () => {
    if (shiftSubmitting.value) return;
    shiftError.value = '';
    shiftSubmitting.value = true;

    try {
        if (shiftMode.value === 'open') {
            const res = await axios.post(route('pos.shift.open'), {
                opening_cash: Number(shiftOpeningCash.value || 0),
                opening_note: String(shiftNote.value || '').trim() || null,
            });
            activeShift.value = res.data?.active_shift || null;
            shiftModalOpen.value = false;
            alert(`Shift ${activeShift.value?.shift_no || ''} opened.`);
        } else {
            const res = await axios.post(route('pos.shift.close'), {
                closing_cash: Number(shiftClosingCash.value || 0),
                closing_note: String(shiftNote.value || '').trim() || null,
            });
            const closedShift = res.data?.closed_shift || null;
            activeShift.value = null;
            shiftModalOpen.value = false;
            if (closedShift) {
                const variance = Number(closedShift.cash_variance || 0).toFixed(2);
                alert(`Shift ${closedShift.shift_no} closed. Cash variance: Rs ${variance}`);
            }
        }
    } catch (e) {
        shiftError.value = e?.response?.data?.message || 'Unable to complete shift action right now.';
    } finally {
        shiftSubmitting.value = false;
    }
};

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

const resolvePreferredPrintFormat = () => {
    const layout = String(props.pos_preferences?.receipt_layout || '').toLowerCase();
    const paper = String(props.pos_preferences?.printer_paper_width || '').toLowerCase();
    if (layout === 'a4' || paper === 'a4') return 'a4';
    if (paper === '58mm') return 'thermal58';
    return 'thermal80';
};

const emptyTabCheckoutState = () => ({
    quotationId: null,
    quotationNo: '',
    paymentMode: 'cash',
    splitCashAmt: 0,
    splitBankAmt: 0,
    bankInstrument: 'UPI',
    billDiscountPercent: 0,
    otherCharges: 0,
    transactionNo: '',
    printFormat: resolvePreferredPrintFormat(),
    printAfterSave: props.pos_preferences?.auto_print_after_checkout !== false,
});

const tabCheckoutStates = ref({
    RCS: emptyTabCheckoutState(),
    F1: emptyTabCheckoutState(),
    F2: emptyTabCheckoutState(),
    F3: emptyTabCheckoutState(),
    F4: emptyTabCheckoutState(),
    F5: emptyTabCheckoutState(),
});

const emptyTabHoldState = () => ({
    holdId: null,
    holdNo: '',
    heldAt: null,
    lockExpiresAt: null,
});

const tabHoldStates = ref({
    RCS: emptyTabHoldState(),
    F1: emptyTabHoldState(),
    F2: emptyTabHoldState(),
    F3: emptyTabHoldState(),
    F4: emptyTabHoldState(),
    F5: emptyTabHoldState(),
});

const currentMeta = computed(() => tabMeta.value[activeTab.value]);
const activeQuotationId = ref(null);
const activeQuotationNo = ref('');

const toggleLock = () => {
    tabMeta.value[activeTab.value].locked = !tabMeta.value[activeTab.value].locked;
};

const tabPurpose = {
    RCS: 'RCS Counter',
    F1: 'Counter Bill 1',
    F2: 'Counter Bill 2',
    F3: 'Counter Bill 3',
    F4: 'Counter Bill 4',
    F5: 'Counter Bill 5',
};

// ── Calculator State ───────────────────────────────
const showCalculator = ref(false);
const calcInput = ref('');
const evaluateCalc = () => {
    try {
        const expr = String(calcInput.value || '').trim();
        if (!expr || !/^[0-9+\-*/().\s]+$/.test(expr)) {
            throw new Error('Invalid expression');
        }
        calcInput.value = Function('"use strict";return (' + expr + ')')().toString();
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
    batchModal.value = { open: true, product, batches: [], loading: true, manualBatch: '' };
    try {
        const res = await axios.post(route('pos.getProductBatches'), { product_id: product.id });
        const rows = Array.isArray(res.data) ? res.data : [];
        batchModal.value.batches = smartBatchSuggestionEnabled.value
            ? rows.sort((a, b) => {
                const stockA = Number(a?.stock || 0);
                const stockB = Number(b?.stock || 0);
                const inStockA = stockA > 0 ? 1 : 0;
                const inStockB = stockB > 0 ? 1 : 0;
                if (inStockA !== inStockB) return inStockB - inStockA;

                const expA = a?.expiry_date ? Date.parse(String(a.expiry_date)) : Number.POSITIVE_INFINITY;
                const expB = b?.expiry_date ? Date.parse(String(b.expiry_date)) : Number.POSITIVE_INFINITY;
                if (expA !== expB) return expA - expB;

                return stockB - stockA;
            })
            : rows;
    } catch {}
    batchModal.value.loading = false;
    // Keep modal open so user can enter an explicit manual batch when stock rows are missing.
    if (!batchModal.value.batches.length) {
        batchModal.value.manualBatch = `MANUAL-${String(Date.now()).slice(-6)}`;
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
            const rememberedDoctor = lastDoctorByCustomerId.value[res.data.id];
            if (rememberedDoctor?.id) {
                currentCustomer.value.selectedDoctor = rememberedDoctor;
                currentCustomer.value.doctorSearch = rememberedDoctor.name || '';
            }
            await loadCustomerCreditInfo();
            await fetchOldBills();
        } else {
            customerCredit.value = { pending_credit: 0, recent_bills: [], recent_collections: [] };
            oldBills.value = [];
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

// Legacy-style previous bill replay in main POS tab
const oldBillModalOpen = ref(false);
const oldBillsLoading = ref(false);
const oldBills = ref([]);
const holdsModalOpen = ref(false);
const holdsLoading = ref(false);
const holdSubmitting = ref(false);
const holds = ref([]);
const AUTO_HOLD_HEARTBEAT_MS = 45000;
let holdHeartbeatTimer = null;

const mergeItemIntoTab = (tabKey, candidate) => {
    const existing = tabs.value[tabKey].find(
        (i) => i.product_id === candidate.product_id && (i.batch_no || '') === (candidate.batch_no || '')
    );

    if (existing) {
        existing.qty = Number(existing.qty || 0) + Number(candidate.qty || 0);
        existing.discount_percent = Number(candidate.discount_percent || existing.discount_percent || 0);
        calcLine(existing);
        return;
    }

    tabs.value[tabKey].push(candidate);
};

const hydrateItemsFromInvoice = (items) => {
    return (items || []).map((item) => {
        const qty = Number(item.qty || 0);
        const rate = Number(item.rate || 0);
        const discountPercent = Number(item.discount_percent || 0);
        const gstPercent = Number(item.gst_percent || 0);
        const base = qty * rate;
        const discountAmount = base * (discountPercent / 100);
        const taxableAmount = base - discountAmount;
        const gstAmount = taxableAmount * (gstPercent / 100);

        return {
            _id: Date.now() + Math.random(),
            product_id: item.product_id,
            product_name: item.product_name,
            free_schema: null,
            batch_no: item.batch_no || '',
            expiry_date: item.expiry_date || item.exp_date || null,
            available_stock: 0,
            qty,
            free_qty: Number(item.free_qty || 0),
            mrp: Number(item.mrp || 0),
            rate,
            discount_percent: discountPercent,
            discount_amount: discountAmount,
            taxable_amount: taxableAmount,
            gst_percent: gstPercent,
            gst_amount: gstAmount,
            total_amount: taxableAmount + gstAmount,
        };
    });
};

const fetchOldBills = async () => {
    if (!currentCustomer.value.id) {
        oldBills.value = [];
        return;
    }

    oldBillsLoading.value = true;
    try {
        const res = await axios.post(route('pos.customerRecentBills'), {
            customer_id: currentCustomer.value.id,
            limit: 15,
        });
        oldBills.value = Array.isArray(res.data) ? res.data : [];
    } catch {
        oldBills.value = [];
    } finally {
        oldBillsLoading.value = false;
    }
};

const fetchHolds = async () => {
    holdsLoading.value = true;
    try {
        const res = await axios.get(route('pos.holds.index'));
        holds.value = Array.isArray(res.data) ? res.data : [];
    } catch {
        holds.value = [];
    } finally {
        holdsLoading.value = false;
    }
};

const releaseHoldLock = async (holdId, options = {}) => {
    const silent = options.silent === true;
    if (!holdId) return;

    try {
        await axios.post(route('pos.holds.releaseLock', Number(holdId)));
    } catch (e) {
        if (!silent) {
            const msg = e?.response?.data?.message || 'Unable to release hold lock right now.';
            alert(msg);
        }
    }
};

const openHoldsModal = async () => {
    await fetchHolds();
    holdsModalOpen.value = true;
};

const openOldBillsModal = async () => {
    if (!currentCustomer.value.id) {
        alert('Select a customer first to view previous bills.');
        return;
    }
    await fetchOldBills();
    oldBillModalOpen.value = true;
};

const loadBillToActiveTab = async (invoiceId, mode = 'replace') => {
    try {
        const res = await axios.get(route('pos.billItems', Number(invoiceId)));
        const payload = res.data || {};
        const invoice = payload.invoice || {};
        const incomingItems = hydrateItemsFromInvoice(payload.items || []);

        if (!incomingItems.length) {
            alert('Selected bill has no items.');
            return;
        }

        if (mode === 'replace') {
            tabs.value[activeTab.value] = [];
        }

        incomingItems.forEach((item) => mergeItemIntoTab(activeTab.value, item));

        if (invoice.customer?.id) {
            currentCustomer.value.id = invoice.customer.id;
            currentCustomer.value.name = invoice.customer.name || currentCustomer.value.name;
            currentCustomer.value.mobile = invoice.customer.mobile || currentCustomer.value.mobile;
        }

        oldBillModalOpen.value = false;
        tabHoldStates.value[activeTab.value] = emptyTabHoldState();
        activeQuotationId.value = null;
        activeQuotationNo.value = '';
        alert(`Loaded bill ${invoice.bill_no || invoiceId} into ${activeTab.value}.`);
    } catch (e) {
        const msg = e?.response?.data?.message || 'Unable to load old bill items.';
        alert(msg);
    }
};

const quickRebillLatest = async () => {
    if (!currentCustomer.value.id) {
        alert('Select a customer first.');
        return;
    }

    await fetchOldBills();
    if (!oldBills.value.length) {
        alert('No recent bills found for this customer.');
        return;
    }

    await loadBillToActiveTab(oldBills.value[0].id, 'replace');
};

const mapHoldSnapshotItem = (item) => {
    const mapped = {
        _id: Date.now() + Math.random(),
        product_id: item.product_id,
        product_name: item.product_name || 'Product',
        free_schema: item.free_schema || null,
        batch_no: item.batch_no || '',
        expiry_date: item.expiry_date || item.exp_date || null,
        available_stock: Number(item.available_stock || 0),
        qty: Number(item.qty || 0),
        free_qty: Number(item.free_qty || 0),
        mrp: Number(item.mrp || 0),
        rate: Number(item.rate || 0),
        discount_percent: Number(item.discount_percent || 0),
        discount_amount: Number(item.discount_amount || 0),
        taxable_amount: Number(item.taxable_amount || 0),
        gst_percent: Number(item.gst_percent || 0),
        gst_amount: Number(item.gst_amount || 0),
        total_amount: Number(item.total_amount || 0),
    };
    calcLine(mapped);
    return mapped;
};

const saveCurrentHold = async (options = {}) => {
    const silent = options.silent === true;

    if (holdSubmitting.value) return;
    if (!cart.value.length) {
        if (!silent) {
            alert('Cannot hold an empty bill. Add items first.');
        }
        return;
    }

    holdSubmitting.value = true;
    try {
        const totals = checkoutTotals.value;
        const activeHold = tabHoldStates.value[activeTab.value] || emptyTabHoldState();

        const payload = {
            hold_id: activeHold.holdId,
            tab_code: activeTab.value,
            is_locked: autoLockBillOnHold.value ? true : !!currentMeta.value.locked,
            subtotal: Number(totals.subTotal || 0),
            discount_amount: Number(totals.discountTotal || 0),
            tax_amount: Number(totals.taxAmount || 0),
            total_amount: Number(totals.total || 0),
            customer: {
                id: currentCustomer.value.id,
                mobile: currentCustomer.value.mobile,
                name: currentCustomer.value.name,
            },
            doctor: currentCustomer.value.selectedDoctor || null,
            items: cart.value.map((i) => ({
                product_id: i.product_id,
                batch_no: i.batch_no || '',
                qty: Number(i.qty || 0),
                free_qty: Number(i.free_qty || 0),
                rate: Number(i.rate || 0),
                mrp: Number(i.mrp || 0),
                discount_percent: Number(i.discount_percent || 0),
            })),
            meta: {
                quotation_id: activeQuotationId.value,
                quotation_no: activeQuotationNo.value,
                payment_mode: paymentMode.value,
                split_cash_amount: Number(splitCashAmt.value || 0),
                split_bank_amount: Number(splitBankAmt.value || 0),
                bank_instrument: bankInstrument.value,
                bill_discount_percent: Number(billDiscountPercent.value || 0),
                other_charges: Number(otherCharges.value || 0),
                transaction_no: transactionNo.value || '',
                print_format: printFormat.value,
                print_after_save: printAfterSave.value !== false,
                cart_snapshot: cart.value,
            },
        };

        const res = await axios.post(route('pos.holds.save'), payload);
        tabHoldStates.value[activeTab.value] = {
            holdId: Number(res.data?.hold_id || 0) || null,
            holdNo: res.data?.hold_no || '',
            heldAt: res.data?.held_at || null,
            lockExpiresAt: res.data?.lock_expires_at || null,
        };
        if (!silent) {
            alert(`Bill parked as hold ${res.data?.hold_no || ''}.`);
            await fetchHolds();
        }
    } catch (e) {
        if (e?.response?.status === 409) {
            await fetchHolds();
        }
        if (!silent) {
            const msg = e?.response?.data?.message || 'Unable to hold this bill right now.';
            alert(msg);
        }
    } finally {
        holdSubmitting.value = false;
    }
};

const loadHoldToTab = async (holdId, tabKey = activeTab.value) => {
    try {
        const existingHoldId = Number(tabHoldStates.value[tabKey]?.holdId || 0);
        if (existingHoldId > 0 && existingHoldId !== Number(holdId)) {
            await releaseHoldLock(existingHoldId, { silent: true });
        }

        const res = await axios.get(route('pos.holds.show', Number(holdId)));
        const payload = res.data || {};

        const snapshotItems = Array.isArray(payload?.meta?.cart_snapshot) ? payload.meta.cart_snapshot : payload.items;
        if (!Array.isArray(snapshotItems) || !snapshotItems.length) {
            alert('Selected hold has no items.');
            return;
        }

        tabs.value[tabKey] = snapshotItems.map(mapHoldSnapshotItem);

        const holdCustomer = payload.customer || {};
        const holdDoctor = payload.doctor || null;
        tabCustomers.value[tabKey] = {
            mobile: holdCustomer.mobile || '',
            name: holdCustomer.name || '',
            id: holdCustomer.id || null,
            doctorSearch: holdDoctor?.name || '',
            selectedDoctor: holdDoctor,
        };

        tabMeta.value[tabKey].locked = !!payload.is_locked;

        const meta = payload.meta || {};
        tabCheckoutStates.value[tabKey] = {
            quotationId: meta.quotation_id ?? null,
            quotationNo: meta.quotation_no || '',
            paymentMode: meta.payment_mode || 'cash',
            splitCashAmt: Number(meta.split_cash_amount || 0),
            splitBankAmt: Number(meta.split_bank_amount || 0),
            bankInstrument: meta.bank_instrument || 'UPI',
            billDiscountPercent: Number(meta.bill_discount_percent || 0),
            otherCharges: Number(meta.other_charges || 0),
            transactionNo: meta.transaction_no || '',
            printFormat: meta.print_format || 'thermal80',
            printAfterSave: meta.print_after_save !== false,
        };

        tabHoldStates.value[tabKey] = {
            holdId: Number(payload.id || 0) || null,
            holdNo: payload.hold_no || '',
            heldAt: payload.held_at || null,
            lockExpiresAt: payload.lock_expires_at || null,
        };

        activeTab.value = tabKey;
        hydrateCurrentTabCheckoutState(tabKey);
        holdsModalOpen.value = false;
        alert(`Hold ${payload.hold_no || holdId} loaded into ${tabKey}.`);
    } catch (e) {
        if (e?.response?.status === 409) {
            await fetchHolds();
        }
        const msg = e?.response?.data?.message || 'Unable to load selected hold.';
        alert(msg);
    }
};

const cancelHoldRecord = async (holdId) => {
    const ok = window.confirm('Cancel this hold permanently?');
    if (!ok) return;

    try {
        await axios.delete(route('pos.holds.cancel', Number(holdId)));
        Object.keys(tabHoldStates.value).forEach((tabKey) => {
            if (Number(tabHoldStates.value[tabKey]?.holdId || 0) === Number(holdId)) {
                tabHoldStates.value[tabKey] = emptyTabHoldState();
            }
        });
        await fetchHolds();
    } catch (e) {
        const msg = e?.response?.data?.message || 'Unable to cancel hold right now.';
        alert(msg);
    }
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

const saveQuotation = async () => {
    if (cart.value.length === 0) {
        alert('Add at least one item before saving quotation.');
        return;
    }
    if (!currentCustomer.value.id) {
        alert('Select a customer first. Quotation is customer-specific.');
        return;
    }

    const remarks = window.prompt('Quotation notes (optional):', '') || null;

    try {
        const totals = cartTotals.value;
        const res = await axios.post(route('pos.quotations.store'), {
            customer_id: currentCustomer.value.id,
            doctor_id: currentCustomer.value.selectedDoctor?.id ?? null,
            remarks,
            items: cart.value.map(i => ({
                product_id: i.product_id,
                batch_no: i.batch_no || null,
                qty: i.qty,
                free_qty: i.free_qty || 0,
                mrp: i.mrp,
                rate: i.rate,
                discount_percent: i.discount_percent || 0,
            })),
            sub_total: totals.subTotal,
            total_discount_amount: totals.discAmount,
            total_tax_amount: totals.taxAmount,
            total_amount: totals.total,
        });

        activeQuotationId.value = Number(res.data?.quotation_id || 0) || null;
        activeQuotationNo.value = res.data?.quotation_no || '';
        alert(`Quotation ${activeQuotationNo.value || 'saved'} successfully.`);
    } catch (e) {
        const msg = e?.response?.data?.message || 'Unable to save quotation right now.';
        alert(msg);
    }
};

const loadQuotation = async () => {
    if (!currentCustomer.value.id) {
        alert('Find/select customer first to load quotations.');
        return;
    }

    try {
        const listRes = await axios.post(route('pos.quotations.customer'), {
            customer_id: currentCustomer.value.id,
            status: 'active',
            limit: 10,
        });

        const quotations = listRes.data || [];
        if (!quotations.length) {
            alert('No active quotations found for this customer.');
            return;
        }

        const promptLines = quotations
            .map(q => `${q.id}: ${q.quotation_no} | Rs ${Number(q.total_amount || 0).toFixed(2)} | ${q.items_count} items`)
            .join('\n');

        const selectedId = window.prompt(`Enter quotation ID to load:\n${promptLines}`, String(quotations[0].id));
        if (!selectedId) {
            return;
        }

        const quoteRes = await axios.get(route('pos.quotations.show', Number(selectedId)));
        const payload = quoteRes.data;
        const quote = payload?.quotation;
        const items = payload?.items || [];

        if (!quote || !items.length) {
            alert('Selected quotation has no items.');
            return;
        }

        tabs.value[activeTab.value] = items.map((item) => {
            const qty = Number(item.qty || 0);
            const rate = Number(item.rate || 0);
            const discountPercent = Number(item.discount_percent || 0);
            const gstPercent = Number(item.gst_percent || 0);
            const base = qty * rate;
            const discountAmount = base * (discountPercent / 100);
            const taxableAmount = base - discountAmount;
            const gstAmount = taxableAmount * (gstPercent / 100);

            return {
                _id: Date.now() + Math.random(),
                product_id: item.product_id,
                product_name: item.product_name,
                free_schema: null,
                batch_no: item.batch_no || '',
                expiry_date: null,
                available_stock: 0,
                qty,
                free_qty: Number(item.free_qty || 0),
                mrp: Number(item.mrp || 0),
                rate,
                discount_percent: discountPercent,
                discount_amount: discountAmount,
                taxable_amount: taxableAmount,
                gst_percent: gstPercent,
                gst_amount: gstAmount,
                total_amount: taxableAmount + gstAmount,
            };
        });

        if (quote.customer?.id) {
            currentCustomer.value.id = quote.customer.id;
            currentCustomer.value.name = quote.customer.name || currentCustomer.value.name;
            currentCustomer.value.mobile = quote.customer.mobile || currentCustomer.value.mobile;
        }

        if (quote.doctor?.id) {
            currentCustomer.value.selectedDoctor = quote.doctor;
            currentCustomer.value.doctorSearch = quote.doctor.name || '';
        }

        activeQuotationId.value = Number(quote.id);
        activeQuotationNo.value = quote.quotation_no || '';
        alert(`Loaded quotation ${activeQuotationNo.value}.`);
    } catch (e) {
        const msg = e?.response?.data?.message || 'Unable to load quotation.';
        alert(msg);
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
    if (currentCustomer.value.id) {
        lastDoctorByCustomerId.value[currentCustomer.value.id] = d;
    }
};

const clearDoctor = () => {
    currentCustomer.value.selectedDoctor = null;
    currentCustomer.value.doctorSearch = '';
    doctorResults.value = [];
};

const lastDoctorByCustomerId = ref({});

// ── Checkout ────────────────────────────────────────
const isCheckoutModalOpen = ref(false);
const paymentMode = ref('cash');
const splitCashAmt = ref(0);
const splitBankAmt = ref(0);
const bankInstrument = ref('UPI');
const billDiscountPercent = ref(0);
const otherCharges = ref(0);
const transactionNo = ref('');
const isSubmitting = ref(false);
const billNo = ref('');
const checkoutRequestId = ref('');
const printAfterSave = ref(props.pos_preferences?.auto_print_after_checkout !== false);
const printFormat = ref(resolvePreferredPrintFormat());
const checkoutError = ref('');
const overrideModalOpen = ref(false);
const overrideSupervisorUsername = ref('');
const overrideSupervisorPassword = ref('');
const overrideReason = ref('');
const overrideError = ref('');
const overrideSubmitting = ref(false);
const checkoutOverrideApproval = ref({
    token: '',
    requestId: '',
    supervisorName: '',
    reason: '',
    expiresAt: null,
});

const roundOffEnabled = computed(() => props.pos_preferences?.round_off_enabled !== false);
const roundOffMode = computed(() => {
    const mode = String(props.pos_preferences?.round_off_mode || 'nearest').toLowerCase();
    return ['nearest', 'up', 'down', 'none'].includes(mode) ? mode : 'nearest';
});
const supervisorOverrideEnabled = computed(() => props.pos_preferences?.supervisor_override_enabled !== false);
const supervisorDiscountThreshold = computed(() => {
    const raw = Number(props.pos_preferences?.supervisor_override_discount_threshold ?? 15);
    if (!Number.isFinite(raw)) return 15;
    return Math.min(100, Math.max(0, Number(raw.toFixed(2))));
});
const autoPrintAfterCheckout = computed(() => props.pos_preferences?.auto_print_after_checkout !== false);
const autoOpenInvoiceAfterCheckout = computed(() => props.pos_preferences?.auto_open_invoice_after_checkout !== false);
const autoLockBillOnHold = computed(() => props.pos_preferences?.auto_lock_bill_on_hold === true);
const highestLineDiscountPercent = computed(() => {
    if (!cart.value.length) return 0;
    return Number(Math.max(...cart.value.map((item) => Number(item.discount_percent || 0))).toFixed(2));
});
const requiresSupervisorApproval = computed(() => {
    if (!supervisorOverrideEnabled.value) return false;
    const billDiscount = Number(billDiscountPercent.value || 0);
    const threshold = Number(supervisorDiscountThreshold.value || 0);
    return billDiscount > threshold || highestLineDiscountPercent.value > threshold;
});

const applyRoundOffByPreference = (grossAmount) => {
    const gross = Number(Number(grossAmount || 0).toFixed(2));

    if (!roundOffEnabled.value || roundOffMode.value === 'none') {
        return {
            gross,
            total: gross,
            roundOff: 0,
        };
    }

    let rounded = gross;
    if (roundOffMode.value === 'up') {
        rounded = Math.ceil(gross);
    } else if (roundOffMode.value === 'down') {
        rounded = Math.floor(gross);
    } else {
        rounded = Math.round(gross);
    }

    return {
        gross,
        total: Number(Number(rounded).toFixed(2)),
        roundOff: Number((rounded - gross).toFixed(2)),
    };
};

const persistCurrentTabCheckoutState = (tabKey) => {
    if (!tabKey || !tabCheckoutStates.value[tabKey]) return;
    tabCheckoutStates.value[tabKey] = {
        quotationId: activeQuotationId.value,
        quotationNo: activeQuotationNo.value,
        paymentMode: paymentMode.value,
        splitCashAmt: Number(splitCashAmt.value || 0),
        splitBankAmt: Number(splitBankAmt.value || 0),
        bankInstrument: bankInstrument.value || 'UPI',
        billDiscountPercent: Number(billDiscountPercent.value || 0),
        otherCharges: Number(otherCharges.value || 0),
        transactionNo: transactionNo.value || '',
        printFormat: printFormat.value || resolvePreferredPrintFormat(),
        printAfterSave: printAfterSave.value !== false,
    };
};

const hydrateCurrentTabCheckoutState = (tabKey) => {
    const state = tabCheckoutStates.value[tabKey] || emptyTabCheckoutState();
    activeQuotationId.value = state.quotationId ?? null;
    activeQuotationNo.value = state.quotationNo || '';
    paymentMode.value = state.paymentMode || 'cash';
    splitCashAmt.value = Number(state.splitCashAmt || 0);
    splitBankAmt.value = Number(state.splitBankAmt || 0);
    bankInstrument.value = state.bankInstrument || 'UPI';
    billDiscountPercent.value = Number(state.billDiscountPercent || 0);
    otherCharges.value = Number(state.otherCharges || 0);
    transactionNo.value = state.transactionNo || '';
    printFormat.value = state.printFormat || resolvePreferredPrintFormat();
    printAfterSave.value = typeof state.printAfterSave === 'boolean' ? state.printAfterSave : autoPrintAfterCheckout.value;
};

watch(activeTab, (nextTab, prevTab) => {
    const prevHoldId = Number(tabHoldStates.value[prevTab]?.holdId || 0);
    const nextHoldId = Number(tabHoldStates.value[nextTab]?.holdId || 0);
    if (prevHoldId > 0 && prevHoldId !== nextHoldId) {
        releaseHoldLock(prevHoldId, { silent: true });
    }

    persistCurrentTabCheckoutState(prevTab);
    hydrateCurrentTabCheckoutState(nextTab);
});

watch(
    [
        activeQuotationId,
        activeQuotationNo,
        paymentMode,
        splitCashAmt,
        splitBankAmt,
        bankInstrument,
        billDiscountPercent,
        otherCharges,
        transactionNo,
        printFormat,
        printAfterSave,
    ],
    () => persistCurrentTabCheckoutState(activeTab.value)
);

watch(
    [billDiscountPercent, highestLineDiscountPercent, checkoutRequestId],
    () => {
        const approval = checkoutOverrideApproval.value;
        if (!approval.token) {
            return;
        }

        if (approval.requestId !== checkoutRequestId.value || !requiresSupervisorApproval.value) {
            clearOverrideApproval();
        }
    }
);

const checkoutTotals = computed(() => {
    const sub = Number(cartTotals.value.subTotal || 0);
    const lineDisc = Number(cartTotals.value.discAmount || 0);
    const tax = Number(cartTotals.value.taxAmount || 0);

    const baseAfterLineDiscountAndTax = Math.max(0, sub - lineDisc + tax);
    const billDiscPct = Math.max(0, Math.min(100, Number(billDiscountPercent.value || 0)));
    const headerDisc = Number(((baseAfterLineDiscountAndTax * billDiscPct) / 100).toFixed(2));
    const charges = Math.max(0, Number(otherCharges.value || 0));

    const discountTotal = Number((lineDisc + headerDisc).toFixed(2));
    const gross = Number((baseAfterLineDiscountAndTax - headerDisc + charges).toFixed(2));
    const roundedSummary = applyRoundOffByPreference(gross);

    return {
        subTotal: Number(sub.toFixed(2)),
        taxAmount: Number(tax.toFixed(2)),
        lineDiscount: Number(lineDisc.toFixed(2)),
        headerDiscount: headerDisc,
        discountTotal,
        otherCharges: Number(charges.toFixed(2)),
        gross: roundedSummary.gross,
        roundOff: roundedSummary.roundOff,
        total: roundedSummary.total,
    };
});

const buildEstimatePayload = () => {
    const totals = checkoutTotals.value;

    return {
        tab_code: activeTab.value,
        customer_name: currentCustomer.value.name || null,
        customer_mobile: currentCustomer.value.mobile || null,
        items: cart.value.map((item) => ({
            product_name: item.product_name || '',
            batch_no: item.batch_no || '',
            qty: Number(item.qty || 0),
            rate: Number(item.rate || 0),
            gst_percent: Number(item.gst_percent || 0),
            total_amount: Number(item.total_amount || 0),
        })),
        totals: {
            sub_total: Number(totals.subTotal || 0),
            discount_total: Number(totals.discountTotal || 0),
            tax_amount: Number(totals.taxAmount || 0),
            other_charges: Number(totals.otherCharges || 0),
            round_off: Number(totals.roundOff || 0),
            total: Number(totals.total || 0),
        },
    };
};

const parseFilenameFromDisposition = (disposition, fallbackName) => {
    const match = disposition?.match(/filename="?([^";]+)"?/i);
    return match?.[1] || fallbackName;
};

const clearOverrideApproval = () => {
    checkoutOverrideApproval.value = {
        token: '',
        requestId: '',
        supervisorName: '',
        reason: '',
        expiresAt: null,
    };
};

const openOverrideModal = () => {
    overrideError.value = '';
    if (!overrideReason.value) {
        overrideReason.value = `High discount override for ${activeTab.value} checkout`;
    }
    overrideSupervisorUsername.value = '';
    overrideSupervisorPassword.value = '';
    overrideModalOpen.value = true;
};

const authorizeCheckoutOverride = async () => {
    if (overrideSubmitting.value) return;
    overrideError.value = '';

    const requestId = String(checkoutRequestId.value || '').trim();
    if (!requestId) {
        overrideError.value = 'Checkout reference missing. Close and reopen checkout, then retry.';
        return;
    }

    if (!String(overrideSupervisorUsername.value || '').trim() || !String(overrideSupervisorPassword.value || '').trim()) {
        overrideError.value = 'Supervisor username and password are required.';
        return;
    }

    if (String(overrideReason.value || '').trim().length < 5) {
        overrideError.value = 'Please provide a short reason for override approval.';
        return;
    }

    overrideSubmitting.value = true;
    try {
        const res = await axios.post(route('pos.override.authorize'), {
            action: 'checkout_discount_override',
            request_id: requestId,
            reason: String(overrideReason.value || '').trim(),
            supervisor_username: String(overrideSupervisorUsername.value || '').trim(),
            supervisor_password: String(overrideSupervisorPassword.value || ''),
            approval_snapshot: buildOverrideSnapshot(),
        });

        checkoutOverrideApproval.value = {
            token: String(res.data?.token || ''),
            requestId: String(res.data?.request_id || requestId),
            supervisorName: String(res.data?.supervisor_name || ''),
            reason: String(res.data?.reason || overrideReason.value || ''),
            expiresAt: res.data?.expires_at || null,
        };

        overrideModalOpen.value = false;
    } catch (e) {
        overrideError.value = e?.response?.data?.message || 'Unable to authorize supervisor override right now.';
    } finally {
        overrideSubmitting.value = false;
    }
};

const downloadBlobFile = (blob, filename) => {
    const link = document.createElement('a');
    const objectUrl = URL.createObjectURL(blob);
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(objectUrl);
};

const buildOverrideSnapshot = () => {
    const totals = checkoutTotals.value;
    return {
        item_count: Number(cart.value.length || 0),
        max_line_discount: Number(highestLineDiscountPercent.value || 0),
        bill_discount_percent: Number(billDiscountPercent.value || 0),
        total_amount: Number(totals.total || 0),
    };
};

const exportCartEstimateExcel = () => {
    if (!cart.value.length) {
        alert('Add at least one item to export estimate.');
        return;
    }

    const payload = buildEstimatePayload();
    axios.post(route('pos.estimate.export'), payload, {
        responseType: 'blob',
    }).then((res) => {
        const filename = parseFilenameFromDisposition(
            res.headers?.['content-disposition'],
            `pos_estimate_${new Date().toISOString().slice(0, 10)}.csv`
        );
        downloadBlobFile(res.data, filename);
    }).catch((e) => {
        const msg = e?.response?.data?.message || 'Unable to export estimate right now.';
        alert(msg);
    });
};

const printCartEstimate = () => {
    if (!cart.value.length) {
        alert('Add at least one item to print estimate.');
        return;
    }

    const preview = window.open('', '_blank', 'noopener,noreferrer,width=1000,height=760');
    if (!preview) {
        alert('Unable to open estimate preview window. Please allow popups.');
        return;
    }

    const payload = buildEstimatePayload();
    axios.post(route('pos.estimate.print'), payload, {
        headers: {
            Accept: 'text/html',
        },
        responseType: 'text',
    }).then((res) => {
        preview.document.open();
        preview.document.write(res.data);
        preview.document.close();
    }).catch((e) => {
        preview.close();
        const msg = e?.response?.data?.message || 'Unable to render estimate print right now.';
        alert(msg);
    });
};

const openPrintWindow = (invoiceId) => {
    if (!invoiceId) return;

    const queryByFormat = {
        thermal58: '?layout=thermal&paper=58mm',
        thermal80: '?layout=thermal&paper=80mm',
        a4: '?layout=a4&paper=a4',
    };

    const base = route('pos.invoices.print', Number(invoiceId));
    const query = queryByFormat[printFormat.value] || '';
    window.open(`${base}${query}`, '_blank', 'noopener,noreferrer');
};

const openCheckout = async () => {
    if (cart.value.length === 0) return;
    if (!hasActiveShift.value) {
        checkoutError.value = 'Open shift is required before checkout.';
        openShiftModal('open');
        return;
    }
    checkoutError.value = '';
    clearOverrideApproval();
    overrideReason.value = '';
    overrideModalOpen.value = false;
    try {
        const res = await axios.get(route('pos.nextBillNumber'));
        billNo.value = res.data.bill_no;
    } catch {
        billNo.value = 'BILL-' + Date.now();
    }
    checkoutRequestId.value = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    paymentMode.value = 'cash';
    billDiscountPercent.value = 0;
    otherCharges.value = 0;
    printAfterSave.value = autoPrintAfterCheckout.value;
    printFormat.value = resolvePreferredPrintFormat();
    splitCashAmt.value = checkoutTotals.value.total;
    splitBankAmt.value = 0;
    transactionNo.value = '';
    bankInstrument.value = 'UPI';
    onPaymentModeChange();
    persistCurrentTabCheckoutState(activeTab.value);
    isCheckoutModalOpen.value = true;
};

const onPaymentModeChange = () => {
    const t = Number(checkoutTotals.value.total || 0);
    if (paymentMode.value === 'cash') { splitCashAmt.value = t; splitBankAmt.value = 0; }
    else if (paymentMode.value === 'bank') { splitCashAmt.value = 0; splitBankAmt.value = t; }
    else if (paymentMode.value === 'credit') { splitCashAmt.value = 0; splitBankAmt.value = 0; }
    else if (paymentMode.value === 'cashBank') { splitCashAmt.value = t; splitBankAmt.value = 0; }
    else if (paymentMode.value === 'cashCredit') { splitCashAmt.value = t; splitBankAmt.value = 0; }
    else if (paymentMode.value === 'bankCredit') { splitCashAmt.value = 0; splitBankAmt.value = t; }
};

const submitSale = async () => {
    if (isSubmitting.value) return;
    checkoutError.value = '';
    const totals = checkoutTotals.value;
    const totalAmount = Number(totals.total || 0);

    let cashAmount = Number(splitCashAmt.value || 0);
    let bankAmount = Number(splitBankAmt.value || 0);

    if (paymentMode.value === 'cash') {
        cashAmount = totalAmount;
        bankAmount = 0;
    }
    if (paymentMode.value === 'bank') {
        cashAmount = 0;
        bankAmount = totalAmount;
    }
    if (paymentMode.value === 'credit') {
        cashAmount = 0;
        bankAmount = 0;
    }

    if (cashAmount < 0 || bankAmount < 0) {
        checkoutError.value = 'Cash/Bank amount cannot be negative.';
        return;
    }

    const creditAmt = Math.max(0, Number((totalAmount - cashAmount - bankAmount).toFixed(2)));
    const splitTotal = Number((cashAmount + bankAmount + creditAmt).toFixed(2));

    if (Math.abs(splitTotal - totalAmount) > 0.01) {
        checkoutError.value = `Payment split total ${splitTotal.toFixed(2)} must match bill total ${totalAmount.toFixed(2)}.`;
        return;
    }

    if (bankAmount > 0 && !String(transactionNo.value || '').trim()) {
        checkoutError.value = 'Transaction number is required when bank/UPI amount is used.';
        return;
    }

    if (creditAmt > 0 && !currentCustomer.value.id && String(currentCustomer.value.mobile || '').length < 8) {
        checkoutError.value = 'Customer mobile is required for credit amount.';
        return;
    }

    if (paymentMode.value === 'cashBank' && (cashAmount <= 0 || bankAmount <= 0)) {
        checkoutError.value = 'Cash+Bank mode requires both cash and bank amounts greater than zero.';
        return;
    }

    if (paymentMode.value === 'cashCredit' && (cashAmount <= 0 || creditAmt <= 0)) {
        checkoutError.value = 'Cash+Credit mode requires positive cash and credit amounts.';
        return;
    }

    if (paymentMode.value === 'bankCredit' && (bankAmount <= 0 || creditAmt <= 0)) {
        checkoutError.value = 'Bank+Credit mode requires positive bank and credit amounts.';
        return;
    }

    if (requiresSupervisorApproval.value) {
        const approval = checkoutOverrideApproval.value;
        const approvedForRequest = approval.token && approval.requestId === checkoutRequestId.value;
        if (!approvedForRequest) {
            checkoutError.value = `Supervisor approval is required for discounts above ${supervisorDiscountThreshold.value}% before checkout.`;
            openOverrideModal();
            return;
        }
    }

    isSubmitting.value = true;

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
            cash_amount: cashAmount,
            bank_amount: bankAmount,
            credit_amount: creditAmt,
            transaction_no: transactionNo.value || null,
            wallet_type: bankAmount > 0 ? bankInstrument.value : null,
            sub_total: totals.subTotal,
            total_discount_amount: totals.discountTotal,
            total_tax_amount: totals.taxAmount,
            other_charges: totals.otherCharges,
            bill_discount_percent: Number(billDiscountPercent.value || 0),
            round_off: Number(totals.roundOff || 0),
            total_amount: totals.total,
            request_id: checkoutRequestId.value,
            override_token: checkoutOverrideApproval.value.token || null,
            override_reason: checkoutOverrideApproval.value.reason || null,
            override_snapshot: buildOverrideSnapshot(),
            hold_id: tabHoldStates.value[activeTab.value]?.holdId || null,
            quotation_id: activeQuotationId.value,
        }, {
            timeout: 25000,
        });

        if (res.data.success) {
            if (printAfterSave.value) {
                openPrintWindow(res.data.invoice_id);
            }
            if (autoOpenInvoiceAfterCheckout.value && res.data.invoice_id) {
                window.open(route('pos.invoices.show', Number(res.data.invoice_id)), '_blank', 'noopener,noreferrer');
            }
            isCheckoutModalOpen.value = false;
            // Clear current tab
            tabs.value[activeTab.value] = [];
            tabCustomers.value[activeTab.value] = emptyCustomerState();
            tabMeta.value[activeTab.value].locked = false;
            customerCredit.value = { pending_credit: 0, recent_bills: [], recent_collections: [] };
            transactionNo.value = '';
            bankInstrument.value = 'UPI';
            activeQuotationId.value = null;
            activeQuotationNo.value = '';
            billDiscountPercent.value = 0;
            otherCharges.value = 0;
            paymentMode.value = 'cash';
            splitCashAmt.value = 0;
            splitBankAmt.value = 0;
            checkoutRequestId.value = '';
            clearOverrideApproval();
            overrideReason.value = '';
            tabHoldStates.value[activeTab.value] = emptyTabHoldState();
            persistCurrentTabCheckoutState(activeTab.value);
            alert(`Bill ${res.data.bill_no} saved successfully!`);
            searchInput.value?.focus();
        }
    } catch (e) {
        const msg = e?.response?.data?.message || 'Error processing sale. Please try again.';
        checkoutError.value = msg;
        if (String(msg).toLowerCase().includes('supervisor approval required')) {
            clearOverrideApproval();
            openOverrideModal();
        }
    } finally {
        isSubmitting.value = false;
    }
};

// ── Keyboard ────────────────────────────────────────
const handleKeyboard = (e) => {
    const target = e.target;
    const isEditable = target && (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.isContentEditable
    );

    if (showCalculator.value && !e.altKey && !e.ctrlKey && !e.metaKey) {
        const allowedChars = /[0-9+\-*/().]/;
        if (allowedChars.test(e.key)) {
            e.preventDefault();
            calcInput.value += e.key;
            return;
        }
        if (e.key === 'Backspace') {
            e.preventDefault();
            calcInput.value = calcInput.value.slice(0, -1);
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            evaluateCalc();
            return;
        }
    }

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

    if (e.key === 'F6') { e.preventDefault(); quickRebillLatest(); }
    if (e.key === 'F7') { e.preventDefault(); openOldBillsModal(); }
    if (e.key === 'F11') { e.preventDefault(); saveCurrentHold(); }

    if (isEditable && !['Escape', 'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', '`', '~'].includes(e.key)) {
        return;
    }
    
    if (e.key === 'Escape') {
        if (batchModal.value.open) batchModal.value.open = false;
        else if (shiftModalOpen.value) shiftModalOpen.value = false;
        else if (overrideModalOpen.value) overrideModalOpen.value = false;
        else if (creditModalOpen.value) creditModalOpen.value = false;
        else if (isCheckoutModalOpen.value) isCheckoutModalOpen.value = false;
        else searchQuery.value = '';
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeyboard);
    searchInput.value?.focus();
    fetchShiftStatus();
    printAfterSave.value = autoPrintAfterCheckout.value;
    printFormat.value = resolvePreferredPrintFormat();

    holdHeartbeatTimer = window.setInterval(() => {
        if (!cart.value.length || isCheckoutModalOpen.value || holdSubmitting.value) {
            return;
        }

        saveCurrentHold({ silent: true });
    }, AUTO_HOLD_HEARTBEAT_MS);
});
onUnmounted(() => {
    const activeHoldId = Number(tabHoldStates.value[activeTab.value]?.holdId || 0);
    if (activeHoldId > 0) {
        releaseHoldLock(activeHoldId, { silent: true });
    }

    window.removeEventListener('keydown', handleKeyboard);
    if (holdHeartbeatTimer) {
        window.clearInterval(holdHeartbeatTimer);
        holdHeartbeatTimer = null;
    }
});
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
                        F1-F5: Switch Bills <span class="text-gray-600 mx-1">|</span> F6: Quick Rebill <span class="text-gray-600 mx-1">|</span> F7: Old Bills <span class="text-gray-600 mx-1">|</span> F8: Lock <span class="text-gray-600 mx-1">|</span> F9: Checkout <span class="text-gray-600 mx-1">|</span> F10: Calculator <span class="text-gray-600 mx-1">|</span> ESC: Clear
                    </span>
                </div>
                <div class="flex gap-4 items-center">
                    <span
                        class="text-xs px-2 py-1 rounded border"
                        :class="hasActiveShift ? 'bg-emerald-900/40 border-emerald-500 text-emerald-300' : 'bg-rose-900/30 border-rose-500 text-rose-300'"
                    >
                        {{ hasActiveShift ? `Shift ${activeShift?.shift_no || 'Open'}` : 'Shift Closed' }}
                    </span>
                    <button
                        @click="openShiftModal(hasActiveShift ? 'close' : 'open')"
                        class="text-xs px-3 py-1.5 rounded border font-semibold"
                        :class="hasActiveShift ? 'bg-amber-500/20 border-amber-400 text-amber-200 hover:bg-amber-500/30' : 'bg-emerald-500/20 border-emerald-400 text-emerald-200 hover:bg-emerald-500/30'"
                    >
                        {{ hasActiveShift ? 'Close Shift' : 'Open Shift' }}
                    </button>
                    <a :href="route('pos.settings')" class="text-sm text-gray-300 hover:text-white transition-colors underline">POS Settings</a>
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
                    <div class="w-full text-left text-[10px] uppercase tracking-wider opacity-80" :class="activeTab === tab.key ? '' : 'hidden md:block'">
                        {{ tabPurpose[tab.key] }}
                    </div>
                    <!-- Indicator Strip matching legacy style exactly -->
                    <div v-if="activeTab === tab.key" class="absolute -bottom-[2px] left-0 w-full h-[3px] bg-gray-50 z-20"></div>
                </button>
            </div>
            
            <!-- Quick Entry Bar - Matches Active Tab BG -->
            <div class="bg-gray-50 border-b border-gray-300 px-4 py-3 flex flex-col xl:flex-row gap-4 shadow-inner">
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

                <div class="w-full xl:w-auto xl:flex-1 xl:max-w-[820px] bg-white/90 border border-gray-200 rounded-lg p-2.5 shadow-sm">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex items-center gap-2 pr-2 border-r border-gray-200">
                            <button @click="showCalculator = !showCalculator" class="p-2 border border-slate-300 bg-white rounded text-slate-600 hover:text-emerald-600 hover:bg-emerald-50 transition-colors shadow-sm" title="Calculator (F10)">
                                <CalculatorIcon class="w-5 h-5" />
                            </button>
                            <button @click="toggleLock" :class="currentMeta.locked ? 'bg-red-50 text-red-600 border-red-200' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'" class="p-2 border rounded transition-colors shadow-sm" :title="currentMeta.locked ? 'Unlock Bill (F8)' : 'Lock Bill (F8)'">
                                <LockClosedIcon v-if="currentMeta.locked" class="w-5 h-5" />
                                <LockOpenIcon v-else class="w-5 h-5" />
                            </button>
                        </div>

                        <div class="flex items-center gap-2 w-full sm:flex-1 sm:min-w-[260px]">
                            <input
                                v-model="currentCustomer.mobile"
                                @keyup.enter="lookupCustomer"
                                type="text"
                                maxlength="12"
                                placeholder="Customer Mobile..."
                                class="w-full min-w-0 border-gray-300 bg-white text-gray-900 focus:ring-emerald-500 py-2 sm:text-sm px-3 shadow-sm rounded-md"
                            >
                            <button @click="lookupCustomer" :disabled="lookingUpCustomer" class="px-3 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold text-sm rounded-md shrink-0 shadow-sm border border-slate-900">
                                {{ lookingUpCustomer ? '...' : 'Find' }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-2 pt-2 border-t border-gray-200">
                        <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                            <button
                                @click="openCreditCollectionModal"
                                :disabled="!currentCustomer.id || creditInfoLoading"
                                class="w-full sm:w-auto px-3 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-900 font-bold text-xs sm:text-sm rounded-md shadow-sm border border-amber-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Collect outstanding customer credit"
                            >
                                Collect
                            </button>
                            <button
                                @click="saveQuotation"
                                :disabled="cart.length === 0"
                                class="w-full sm:w-auto px-3 py-2.5 bg-violet-500 hover:bg-violet-600 text-white font-bold text-xs sm:text-sm rounded-md shadow-sm border border-violet-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Save current cart as quotation"
                            >
                                Save Quote
                            </button>
                            <button
                                @click="loadQuotation"
                                :disabled="!currentCustomer.id"
                                class="w-full sm:w-auto px-3 py-2.5 bg-sky-500 hover:bg-sky-600 text-white font-bold text-xs sm:text-sm rounded-md shadow-sm border border-sky-600 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Load active quotation for selected customer"
                            >
                                Load Quote
                            </button>
                            <button
                                @click="quickRebillLatest"
                                :disabled="!currentCustomer.id"
                                class="w-full sm:w-auto px-3 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs sm:text-sm rounded-md shadow-sm border border-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Load latest bill items into current tab (F6)"
                            >
                                Quick Rebill
                            </button>
                            <button
                                @click="openOldBillsModal"
                                :disabled="!currentCustomer.id"
                                class="w-full sm:w-auto px-3 py-2.5 bg-slate-600 hover:bg-slate-700 text-white font-bold text-xs sm:text-sm rounded-md shadow-sm border border-slate-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Browse previous bills and load into tab (F7)"
                            >
                                Old Bills
                            </button>
                            <button
                                @click="saveCurrentHold"
                                :disabled="cart.length === 0 || holdSubmitting"
                                class="w-full sm:w-auto px-3 py-2.5 bg-amber-500 hover:bg-amber-600 text-slate-900 font-bold text-xs sm:text-sm rounded-md shadow-sm border border-amber-600 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-1"
                                title="Park this bill as hold (F11)"
                            >
                                <ArchiveBoxIcon class="w-4 h-4" />
                                {{ holdSubmitting ? 'Holding...' : 'Hold Bill' }}
                            </button>
                            <button
                                @click="openHoldsModal"
                                class="w-full sm:w-auto px-3 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm rounded-md shadow-sm border border-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Browse and restore parked bills"
                            >
                                Holds
                            </button>
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <div v-if="currentCustomer.id" class="text-xs font-bold text-emerald-700 truncate max-w-[220px] bg-emerald-50 px-2 py-1 rounded border border-emerald-200">✓ {{ currentCustomer.name }}</div>
                        <div
                            v-if="tabHoldStates[activeTab]?.holdNo"
                            class="bg-indigo-50 text-indigo-800 text-xs px-3 py-1 rounded border border-indigo-200 font-bold"
                        >
                            Hold: {{ tabHoldStates[activeTab].holdNo }}
                        </div>
                        <div
                            v-if="activeQuotationNo"
                            class="bg-violet-50 text-violet-800 text-xs px-3 py-1 rounded border border-violet-200 font-bold"
                        >
                            Quote: {{ activeQuotationNo }}
                        </div>
                        <div
                            v-if="currentCustomer.id && Number(customerCredit.pending_credit || 0) > 0"
                            class="bg-amber-50 text-amber-800 text-xs px-3 py-1 rounded border border-amber-200 font-bold"
                        >
                            Pending Credit: ₹{{ Number(customerCredit.pending_credit).toFixed(2) }}
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ── Main Content ─────────────────────────────── -->
        <div class="flex-1 flex overflow-hidden">
            <!-- Cart Table -->
            <div class="flex-1 bg-white h-full flex flex-col">
                <div class="flex-1 overflow-y-auto overflow-x-auto bg-gray-50/50">
                    <div class="md:hidden p-3 space-y-3">
                        <div v-if="cart.length === 0" class="px-4 py-16 text-center text-gray-400 bg-white rounded-lg border border-gray-200">
                            <ShoppingCartIcon class="w-14 h-14 mx-auto mb-3 text-gray-200" />
                            <p class="text-gray-500 font-medium">Cart is empty for {{ activeTab }}.</p>
                            <p class="text-xs mt-1">Search or scan to add items.</p>
                        </div>
                        <div v-for="(item, idx) in cart" :key="`m-${item._id}`" class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
                            <div class="flex justify-between items-start gap-3">
                                <div>
                                    <div class="font-bold text-sm text-gray-900">{{ idx + 1 }}. {{ item.product_name }}</div>
                                    <div class="text-xs text-gray-500">GST {{ item.gst_percent }}% | Batch {{ item.batch_no || '-' }}</div>
                                </div>
                                <button @click="removeItem(idx)" :disabled="currentMeta.locked" class="text-red-500 hover:text-red-700 p-1 rounded disabled:opacity-30">
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3 text-xs">
                                <div>
                                    <label class="block text-gray-500 mb-1">Rate</label>
                                    <input type="number" step="0.01" v-model="item.rate" @input="updateItem(item)" :disabled="currentMeta.locked" class="w-full border-gray-300 rounded py-1.5 text-sm font-mono" />
                                </div>
                                <div>
                                    <label class="block text-gray-500 mb-1">Qty</label>
                                    <input type="number" min="1" v-model="item.qty" @input="updateItem(item)" :disabled="currentMeta.locked" class="w-full border-emerald-400 rounded py-1.5 text-sm font-bold" />
                                </div>
                                <div>
                                    <label class="block text-gray-500 mb-1">Disc %</label>
                                    <input type="number" min="0" step="0.5" v-model="item.discount_percent" @input="updateItem(item)" :disabled="currentMeta.locked" class="w-full border-gray-300 rounded py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-gray-500 mb-1">Total</label>
                                    <div class="w-full rounded py-1.5 px-2 text-sm font-bold bg-gray-100 text-right">₹{{ item.total_amount.toFixed(2) }}</div>
                                </div>
                            </div>
                            <div v-if="item.free_qty > 0" class="mt-2 text-[11px] font-bold text-amber-700">+{{ item.free_qty }} FREE</div>
                        </div>
                    </div>

                    <table class="hidden md:table w-full min-w-[900px] divide-y divide-gray-200">
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
                <div class="bg-slate-900 text-white p-3 sm:p-4 shrink-0 grid grid-cols-2 lg:grid-cols-6 gap-3 sm:gap-4 border-t-4 border-emerald-500 shadow-[0_-10px_15px_-3px_rgba(0,0,0,0.1)] relative">
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
                    <div class="col-span-1 border-r border-slate-700 pr-4 hidden lg:block">
                        <div class="text-xs text-emerald-400 uppercase mb-1 font-bold tracking-wider">Discount</div>
                        <div class="text-xl font-mono text-emerald-400">- ₹ {{ cartTotals.discAmount }}</div>
                    </div>
                    <div class="col-span-1 border-r border-slate-700 pr-4 hidden lg:block">
                        <div class="text-xs text-slate-400 uppercase mb-1 font-bold tracking-wider">GST</div>
                        <div class="text-xl font-mono text-slate-400">+ ₹ {{ cartTotals.taxAmount }}</div>
                    </div>
                    <div class="col-span-1 border-r border-slate-700 pr-4 hidden lg:flex flex-col gap-2 justify-center">
                        <button @click="printCartEstimate" class="w-full py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded border border-slate-700 flex items-center justify-center gap-1 transition" title="Print cart estimate">
                            <DocumentTextIcon class="w-4 h-4 text-rose-400" /> Est. PDF
                        </button>
                        <button @click="exportCartEstimateExcel" class="w-full py-1.5 px-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs rounded border border-slate-700 flex items-center justify-center gap-1 transition" title="Export cart estimate to Excel">
                            <TableCellsIcon class="w-4 h-4 text-emerald-400" /> Est. Excel
                        </button>
                    </div>
                    <div class="col-span-2 lg:col-span-1 flex flex-col justify-center">
                        <button
                            @click="openCheckout"
                            :disabled="cart.length === 0"
                            class="w-full h-full bg-emerald-500 hover:bg-emerald-600 disabled:opacity-40 text-black font-black py-2 px-4 rounded shadow-lg flex items-center justify-center gap-2 active:scale-95 transition-all outline-none focus:ring-4 focus:ring-emerald-500/50"
                        >
                            <CreditCardIcon class="w-6 h-6" />
                            <span class="lg:hidden">BILL ₹{{ cartTotals.total }}</span>
                            <span class="hidden lg:inline">BILL (F9)</span>
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

        <!-- ── Shift Modal ──────────────── -->
        <div v-show="shiftModalOpen" class="fixed inset-0 z-[69] overflow-y-auto">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" @click="shiftModalOpen = false"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border-t-8" :class="shiftMode === 'open' ? 'border-emerald-500' : 'border-amber-500'">
                    <div class="px-6 py-4 border-b" :class="shiftMode === 'open' ? 'border-emerald-100 bg-emerald-50' : 'border-amber-100 bg-amber-50'">
                        <h3 class="text-lg font-black text-slate-900">
                            {{ shiftMode === 'open' ? 'Open Shift' : 'Close Shift' }}
                        </h3>
                        <p class="text-xs text-slate-600 mt-1">
                            {{ shiftMode === 'open' ? 'Start cashier shift with opening cash.' : 'Close shift with cash declaration and variance check.' }}
                        </p>
                    </div>

                    <div class="px-6 py-4 space-y-3">
                        <div v-if="shiftError" class="rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-xs font-medium">
                            {{ shiftError }}
                        </div>

                        <div v-if="shiftMode === 'open'">
                            <label class="block text-xs font-bold text-slate-700 mb-1">Opening Cash</label>
                            <input v-model.number="shiftOpeningCash" type="number" min="0" step="0.01" class="w-full text-sm border-gray-300 rounded shadow-sm" />
                        </div>

                        <div v-else>
                            <div class="rounded border border-slate-200 bg-slate-50 text-xs p-2 space-y-1 mb-3">
                                <div class="flex justify-between"><span>Shift</span><span>{{ activeShift?.shift_no || '-' }}</span></div>
                                <div class="flex justify-between"><span>Opening Cash</span><span>Rs {{ Number(activeShift?.opening_cash || 0).toFixed(2) }}</span></div>
                                <div class="flex justify-between"><span>Cash Sales</span><span>Rs {{ Number(activeShift?.summary?.cash_sales || 0).toFixed(2) }}</span></div>
                                <div class="flex justify-between font-semibold border-t border-slate-200 pt-1"><span>Expected Cash</span><span>Rs {{ (Number(activeShift?.opening_cash || 0) + Number(activeShift?.summary?.cash_sales || 0)).toFixed(2) }}</span></div>
                            </div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Closing Cash</label>
                            <input v-model.number="shiftClosingCash" type="number" min="0" step="0.01" class="w-full text-sm border-gray-300 rounded shadow-sm" />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Note (optional)</label>
                            <textarea v-model="shiftNote" rows="2" class="w-full text-sm border-gray-300 rounded shadow-sm" placeholder="Shift note"></textarea>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-6 py-4 flex justify-end gap-3 border-t border-gray-200">
                        <button @click="shiftModalOpen = false" class="py-2 px-5 border border-gray-300 rounded font-bold text-gray-600 bg-white hover:bg-gray-50">Cancel</button>
                        <button
                            @click="submitShiftAction"
                            :disabled="shiftSubmitting"
                            class="py-2 px-6 font-black rounded shadow disabled:opacity-50"
                            :class="shiftMode === 'open' ? 'bg-emerald-500 hover:bg-emerald-600 text-white' : 'bg-amber-500 hover:bg-amber-600 text-slate-900'"
                        >
                            {{ shiftSubmitting ? 'Saving...' : (shiftMode === 'open' ? 'Open Shift' : 'Close Shift') }}
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
                <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full border-t-8 shadow-emerald-900/50 max-h-[92vh] flex flex-col" :class="posTabsConfig.find(t=>t.key === activeTab)?.color">
                    <div class="bg-slate-50 px-4 sm:px-6 py-4 sm:py-5 border-b flex justify-between items-center relative overflow-hidden gap-2">
                        <div class="absolute inset-0 opacity-10" :class="posTabsConfig.find(t=>t.key === activeTab)?.bg"></div>
                        <div class="relative z-10">
                            <h3 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Generate Bill ({{ activeTab }})</h3>
                            <p class="text-sm text-slate-500 font-mono mt-1 font-medium">Invoice No: {{ billNo }}</p>
                        </div>
                        <div class="text-2xl sm:text-4xl font-mono font-black text-emerald-600 relative z-10">₹ {{ checkoutTotals.total.toFixed(2) }}</div>
                    </div>

                    <div class="px-4 sm:px-6 py-4 sm:py-6 bg-white space-y-6 overflow-y-auto flex-1">
                        <div v-if="checkoutError" class="rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm font-medium">
                            {{ checkoutError }}
                        </div>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8">
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
                                            @keyup.enter.prevent="submitSale"
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
                                        <div class="mt-2 flex gap-2">
                                            <button type="button" @click="clearDoctor" class="text-xs px-2 py-1 border border-slate-300 rounded bg-white text-slate-700 hover:bg-slate-50">Clear Doctor</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Mode -->
                            <div class="border-t pt-5 lg:border-t-0 lg:border-l lg:pl-8 lg:pt-0">
                                <h4 class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-4">Payment Method</h4>
                                <div class="space-y-5">
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                        <label v-for="m in [
                                            {v:'cash',l:'Cash'},
                                            {v:'bank',l:'Bank'},
                                            {v:'credit',l:'Credit'},
                                            {v:'cashBank',l:'Cash+Bank'},
                                            {v:'cashCredit',l:'Cash+Credit'},
                                            {v:'bankCredit',l:'Bank+Credit'}
                                        ]" :key="m.v" class="cursor-pointer">
                                            <input type="radio" v-model="paymentMode" :value="m.v" @change="onPaymentModeChange" class="sr-only peer">
                                            <div class="text-center py-2 border border-gray-300 rounded shadow-sm font-bold text-sm bg-white text-gray-600 peer-checked:bg-emerald-600 peer-checked:border-emerald-600 peer-checked:text-white transition-all">{{ m.l }}</div>
                                        </label>
                                    </div>

                                    <div v-if="paymentMode === 'cashBank' || paymentMode === 'cashCredit'" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs font-bold text-slate-700 mb-1 block">Cash Amount</label>
                                            <input type="number" min="0" step="0.01" v-model.number="splitCashAmt" class="w-full text-base font-mono font-bold text-emerald-700 border-gray-300 rounded py-1 shadow-sm" />
                                        </div>
                                        <div class="p-2 rounded border border-amber-200 bg-amber-50 text-amber-800 text-xs font-medium">
                                            Credit auto-calculated: ₹{{ Math.max(0, Number(checkoutTotals.total || 0) - Number(splitCashAmt || 0) - Number(splitBankAmt || 0)).toFixed(2) }}
                                        </div>
                                    </div>

                                    <div v-if="paymentMode === 'cashBank' || paymentMode === 'bankCredit'" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs font-bold text-slate-700 mb-1 block">Bank/UPI Amount</label>
                                            <input type="number" min="0" step="0.01" v-model.number="splitBankAmt" class="w-full text-base font-mono font-bold text-emerald-700 border-gray-300 rounded py-1 shadow-sm" />
                                        </div>
                                        <div v-if="paymentMode === 'bankCredit'" class="p-2 rounded border border-amber-200 bg-amber-50 text-amber-800 text-xs font-medium">
                                            Credit auto-calculated: ₹{{ Math.max(0, Number(checkoutTotals.total || 0) - Number(splitCashAmt || 0) - Number(splitBankAmt || 0)).toFixed(2) }}
                                        </div>
                                    </div>

                                    <div v-if="paymentMode === 'bank' || paymentMode === 'cashBank' || paymentMode === 'bankCredit'" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label class="text-xs font-bold text-slate-700 mb-1 block">Transaction / UTR No.</label>
                                            <input type="text" v-model="transactionNo" class="w-full text-sm border-gray-300 rounded py-2 shadow-sm uppercase font-mono" placeholder="PhonePe / GPay Ref" />
                                        </div>
                                        <div>
                                            <label class="text-xs font-bold text-slate-700 mb-1 block">Bank Type</label>
                                            <select v-model="bankInstrument" class="w-full text-sm border-gray-300 rounded py-2 shadow-sm">
                                                <option value="UPI">UPI</option>
                                                <option value="CARD">Card</option>
                                                <option value="NEFT">NEFT</option>
                                                <option value="RTGS">RTGS</option>
                                                <option value="CHEQUE">Cheque</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div v-if="paymentMode === 'credit' || paymentMode === 'cashCredit' || paymentMode === 'bankCredit'" class="p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800 flex gap-3 shadow-inner">
                                        <svg class="w-6 h-6 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                        <div>
                                            <span class="font-bold block mb-1">Credit Sale Warning</span>
                                            Current customer ledger will reflect this outstanding amount automatically. Current credit amount: ₹{{ Math.max(0, Number(checkoutTotals.total || 0) - Number(splitCashAmt || 0) - Number(splitBankAmt || 0)).toFixed(2) }}
                                        </div>
                                    </div>

                                    <div class="pt-2 border-t border-slate-200">
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                            <div>
                                                <label class="text-xs font-bold text-slate-700 mb-1 block">Bill Discount %</label>
                                                <input type="number" min="0" max="100" step="0.01" v-model.number="billDiscountPercent" @input="onPaymentModeChange" class="w-full text-sm border-gray-300 rounded py-2 shadow-sm font-mono" />
                                            </div>
                                            <div>
                                                <label class="text-xs font-bold text-slate-700 mb-1 block">Other Charges</label>
                                                <input type="number" min="0" step="0.01" v-model.number="otherCharges" @input="onPaymentModeChange" class="w-full text-sm border-gray-300 rounded py-2 shadow-sm font-mono" />
                                            </div>
                                        </div>

                                        <div class="rounded border border-slate-200 bg-slate-50 text-xs p-2 space-y-1">
                                            <div class="flex justify-between"><span>Subtotal</span><span>₹{{ checkoutTotals.subTotal.toFixed(2) }}</span></div>
                                            <div class="flex justify-between text-emerald-700"><span>Discount Total</span><span>- ₹{{ checkoutTotals.discountTotal.toFixed(2) }}</span></div>
                                            <div class="flex justify-between"><span>Tax</span><span>₹{{ checkoutTotals.taxAmount.toFixed(2) }}</span></div>
                                            <div class="flex justify-between"><span>Other Charges</span><span>₹{{ checkoutTotals.otherCharges.toFixed(2) }}</span></div>
                                            <div class="flex justify-between"><span>Round Off</span><span>₹{{ checkoutTotals.roundOff.toFixed(2) }}</span></div>
                                            <div class="flex justify-between font-bold border-t border-slate-200 pt-1"><span>Rounded Payable</span><span>₹{{ checkoutTotals.total.toFixed(2) }}</span></div>
                                        </div>

                                        <div v-if="supervisorOverrideEnabled" class="mt-3 rounded border px-3 py-2 text-xs" :class="requiresSupervisorApproval ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-emerald-200 bg-emerald-50 text-emerald-800'">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-semibold">
                                                    {{ requiresSupervisorApproval
                                                        ? `Supervisor approval required (>${supervisorDiscountThreshold}% discount)`
                                                        : `Supervisor approval not required (threshold ${supervisorDiscountThreshold}%)` }}
                                                </span>
                                                <button
                                                    v-if="requiresSupervisorApproval"
                                                    type="button"
                                                    @click="openOverrideModal"
                                                    class="px-2 py-1 text-[11px] rounded bg-amber-600 hover:bg-amber-700 text-white font-bold"
                                                >
                                                    {{ checkoutOverrideApproval.token ? 'Re-Approve' : 'Approve' }}
                                                </button>
                                            </div>
                                            <div class="mt-1 text-[11px]">
                                                Bill discount: {{ Number(billDiscountPercent || 0).toFixed(2) }}% | Max line discount: {{ highestLineDiscountPercent.toFixed(2) }}%
                                            </div>
                                            <div v-if="checkoutOverrideApproval.token && checkoutOverrideApproval.requestId === checkoutRequestId" class="mt-1 text-[11px] font-medium text-emerald-700">
                                                Approved by {{ checkoutOverrideApproval.supervisorName || 'Supervisor' }}
                                            </div>
                                        </div>

                                        <label class="text-xs font-bold text-slate-700 mb-1 block">Print Format</label>
                                        <select v-model="printFormat" class="w-full text-sm border-gray-300 rounded py-2 shadow-sm">
                                            <option value="thermal58">Thermal 58mm</option>
                                            <option value="thermal80">Thermal 80mm</option>
                                            <option value="a4">A4 Invoice</option>
                                        </select>
                                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-slate-700 font-medium">
                                            <input v-model="printAfterSave" type="checkbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                                            Print after save
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-4 sm:px-6 py-4 sm:py-5 flex flex-col-reverse sm:flex-row justify-between items-stretch sm:items-center border-t border-gray-200 gap-3">
                        <button @click="isCheckoutModalOpen = false" class="py-2.5 px-6 border-2 border-gray-300 rounded font-bold text-gray-600 bg-white hover:bg-gray-50 hover:text-gray-900 transition-colors">
                            Cancel (ESC)
                        </button>
                        <button
                            @click="submitSale"
                            :disabled="isSubmitting"
                            class="py-3 px-6 sm:px-10 bg-emerald-600 hover:bg-emerald-700 text-white font-black rounded-lg shadow-lg shadow-emerald-600/30 disabled:opacity-50 text-base sm:text-lg flex items-center justify-center gap-3 transition-colors outline-none focus:ring-4 focus:ring-emerald-500/50"
                        >
                            {{ isSubmitting ? 'Verifying & Saving...' : 'Confirm Bill & Print' }}
                            <svg v-if="!isSubmitting" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-show="overrideModalOpen" class="fixed inset-0 z-[71] overflow-y-auto">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm" @click="overrideModalOpen = false"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border-t-8 border-amber-500">
                    <div class="px-6 py-4 border-b border-amber-100 bg-amber-50">
                        <h3 class="text-lg font-black text-slate-900">Supervisor Approval Required</h3>
                        <p class="text-xs text-amber-900/80 mt-1">Approve checkout discounts above {{ supervisorDiscountThreshold }}%.</p>
                    </div>

                    <div class="px-6 py-4 space-y-3">
                        <div v-if="overrideError" class="rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-xs font-medium">
                            {{ overrideError }}
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Supervisor Username</label>
                            <input v-model="overrideSupervisorUsername" type="text" class="w-full text-sm border-gray-300 rounded shadow-sm" placeholder="supervisor username" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Supervisor Password</label>
                            <input v-model="overrideSupervisorPassword" type="password" class="w-full text-sm border-gray-300 rounded shadow-sm" placeholder="password" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Reason</label>
                            <textarea v-model="overrideReason" rows="2" class="w-full text-sm border-gray-300 rounded shadow-sm" placeholder="Reason for this discount override"></textarea>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-6 py-4 flex justify-end gap-3 border-t border-gray-200">
                        <button @click="overrideModalOpen = false" class="py-2 px-5 border border-gray-300 rounded font-bold text-gray-600 bg-white hover:bg-gray-50">Cancel</button>
                        <button
                            @click="authorizeCheckoutOverride"
                            :disabled="overrideSubmitting"
                            class="py-2 px-6 bg-amber-500 hover:bg-amber-600 text-slate-900 font-black rounded shadow disabled:opacity-50"
                        >
                            {{ overrideSubmitting ? 'Authorizing...' : 'Authorize Checkout' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Old Bill Replay Modal ──────────────── -->
        <div v-show="oldBillModalOpen" class="fixed inset-0 z-[72] overflow-y-auto">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" @click="oldBillModalOpen = false"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full border-t-8 border-slate-700">
                    <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-black text-slate-900">Previous Bills</h3>
                            <p class="text-sm text-slate-500">Load old bill items into {{ activeTab }} tab.</p>
                        </div>
                        <button @click="oldBillModalOpen = false" class="text-slate-400 hover:text-slate-700"><XMarkIcon class="w-5 h-5" /></button>
                    </div>

                    <div class="px-6 py-4">
                        <div v-if="oldBillsLoading" class="py-8 text-center text-slate-500">Loading previous bills...</div>
                        <div v-else-if="!oldBills.length" class="py-8 text-center text-slate-500">No previous bills found for selected customer.</div>
                        <div v-else class="border rounded overflow-hidden max-h-[380px] overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100 text-xs uppercase tracking-wider text-slate-500 font-bold sticky top-0">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Bill No</th>
                                        <th class="px-3 py-2 text-left">Date</th>
                                        <th class="px-3 py-2 text-right">Amount</th>
                                        <th class="px-3 py-2 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="bill in oldBills" :key="bill.id">
                                        <td class="px-3 py-2 font-mono font-bold text-slate-800">{{ bill.bill_no }}</td>
                                        <td class="px-3 py-2 text-slate-600">{{ bill.date_time ? new Date(bill.date_time).toLocaleString() : '-' }}</td>
                                        <td class="px-3 py-2 text-right font-mono">₹{{ Number(bill.total_amount || 0).toFixed(2) }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <div class="inline-flex gap-2">
                                                <button @click="loadBillToActiveTab(bill.id, 'replace')" class="px-2 py-1 text-xs bg-emerald-600 hover:bg-emerald-700 text-white rounded">Replace</button>
                                                <button @click="loadBillToActiveTab(bill.id, 'append')" class="px-2 py-1 text-xs bg-slate-700 hover:bg-slate-800 text-white rounded">Append</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-6 py-4 flex justify-end gap-3 border-t border-gray-200">
                        <button @click="oldBillModalOpen = false" class="py-2 px-5 border border-gray-300 rounded font-bold text-gray-600 bg-white hover:bg-gray-50">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Holds Modal ──────────────── -->
        <div v-show="holdsModalOpen" class="fixed inset-0 z-[73] overflow-y-auto">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm" @click="holdsModalOpen = false"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full border-t-8 border-indigo-700">
                    <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100 flex items-center justify-between">
                        <div>
                            <h3 class="text-xl font-black text-slate-900">Parked Bills</h3>
                            <p class="text-sm text-slate-500">Resume a held bill into any active POS tab.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button @click="fetchHolds" class="px-3 py-1.5 text-xs font-bold bg-white border border-indigo-200 rounded text-indigo-700 hover:bg-indigo-100">Refresh</button>
                            <button @click="holdsModalOpen = false" class="text-slate-400 hover:text-slate-700"><XMarkIcon class="w-5 h-5" /></button>
                        </div>
                    </div>

                    <div class="px-6 py-4">
                        <div v-if="holdsLoading" class="py-8 text-center text-slate-500">Loading holds...</div>
                        <div v-else-if="!holds.length" class="py-8 text-center text-slate-500">No active holds available.</div>
                        <div v-else class="border rounded overflow-hidden max-h-[420px] overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-100 text-xs uppercase tracking-wider text-slate-500 font-bold sticky top-0">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Hold No</th>
                                        <th class="px-3 py-2 text-left">Tab</th>
                                        <th class="px-3 py-2 text-right">Amount</th>
                                        <th class="px-3 py-2 text-left">Held At</th>
                                        <th class="px-3 py-2 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="hold in holds" :key="hold.id">
                                        <td class="px-3 py-2 font-mono font-bold text-slate-800">{{ hold.hold_no }}</td>
                                        <td class="px-3 py-2 text-slate-700">{{ hold.tab_code }}</td>
                                        <td class="px-3 py-2 text-right font-mono">₹{{ Number(hold.total_amount || 0).toFixed(2) }}</td>
                                        <td class="px-3 py-2 text-slate-600">{{ hold.held_at ? new Date(hold.held_at).toLocaleString() : '-' }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <div class="inline-flex gap-2 items-center">
                                                <select
                                                    :value="activeTab"
                                                    @change="loadHoldToTab(hold.id, $event.target.value)"
                                                    class="text-xs border border-slate-300 rounded px-2 py-1 bg-white"
                                                >
                                                    <option v-for="tab in posTabsConfig" :key="tab.key" :value="tab.key">Load in {{ tab.key }}</option>
                                                </select>
                                                <button @click="cancelHoldRecord(hold.id)" class="px-2 py-1 text-xs bg-rose-600 hover:bg-rose-700 text-white rounded">Cancel</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-6 py-4 flex justify-end gap-3 border-t border-gray-200">
                        <button @click="holdsModalOpen = false" class="py-2 px-5 border border-gray-300 rounded font-bold text-gray-600 bg-white hover:bg-gray-50">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
