<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import { 
    ArrowLeftIcon, 
    CheckCircleIcon
} from '@heroicons/vue/24/solid';

const props = defineProps({
    product: { type: Object, default: null },
    companies: Array,
    categories: Array,
    salts: Array,
    hsn_codes: Array,
    box_sizes: Array,
    rack_sections: { type: Array, default: () => [] },
});

const isEdit = !!props.product?.id;

const currentStep = ref(1);

// AJAX state
const nameWarning      = ref(null);   // duplicate name message or null
const rackAreas        = ref([]);     // options for rack_area_id, loaded per section
let   nameCheckTimer   = null;        // debounce handle

const steps = [
    { id: 1, name: 'Identity & Links', description: 'Core product details and classifications' },
    { id: 2, name: 'Pricing Matrix', description: 'MRP, cost, and sales rates' },
    { id: 3, name: 'Discounts', description: 'Margins, B2B schemas, & free goods' },
    { id: 4, name: 'Taxes & Constraints', description: 'GST percentages and limits' },
    { id: 5, name: 'Inventory & Mapping', description: 'Stock rules and warehouse mapping' }
];

const form = useForm({
    // Step 1: Core Links
    company_id: props.product?.company_id || '',
    category_id: props.product?.category_id || '',
    salt_id: props.product?.salt_id || '',
    hsn_id: props.product?.hsn_id || '',
    product_name: props.product?.product_name || '',
    sku: props.product?.sku || '',
    barcode: props.product?.barcode || '',
    product_code: props.product?.product_code || '',
    unit_sms_code: props.product?.unit_sms_code || '',
    item_type: props.product?.item_type || '',
    color_item_type: props.product?.color_item_type || '',
    company_code: props.product?.company_code || '',
    product_type: props.product?.product_type || '',
    is_active: props.product ? !!props.product.is_active : true,
    
    // Step 2: Pricing
    mrp: props.product?.mrp || '',
    ptr: props.product?.ptr || '',
    pts: props.product?.pts || '',
    cost: props.product?.cost || '',
    rate_a: props.product?.rate_a || '',
    rate_b: props.product?.rate_b || '',
    rate_c: props.product?.rate_c || '',

    // Step 3: Discounts & Margins
    p_rate_discount: props.product?.p_rate_discount || '',
    item_special_discount: props.product?.item_special_discount || '',
    special_discount: props.product?.special_discount || '',
    quantity_discount: props.product?.quantity_discount || '',
    max_discount: props.product?.max_discount || '',
    min_margin_disc: props.product?.min_margin_disc || '',
    general_discount: props.product?.general_discount || '',
    free_schema: props.product?.free_schema || '',

    // Step 4: Tax 
    local_tax: props.product?.local_tax || '',
    central_tax: props.product?.central_tax || '',
    sgst: props.product?.sgst || '',
    cgst: props.product?.cgst || '',
    igst: props.product?.igst || '',
    csr: props.product?.csr || '',

    // Step 5: Physical/Inventory
    box_size_id: props.product?.box_size_id || '',
    unit: props.product?.unit || '',
    secondary_unit: props.product?.secondary_unit || '',
    packing_desc: props.product?.packing_desc || '',
    conversion_factor: props.product?.conversion_factor || 1,
    is_loose_sellable: props.product?.is_loose_sellable || false,
    min_stock_level: props.product?.min_stock_level || 0,
    max_stock_level: props.product?.max_stock_level || 0,
    reorder_quantity: props.product?.reorder_quantity || 0,
    shelflife: props.product?.shelflife || '',
    reorder_days: props.product?.reorder_days || '',
    rack_section_id: props.product?.rack_section_id || '',
    rack_area_id: props.product?.rack_area_id || '',
    ap_remark: props.product?.ap_remark || '',
    fast_search_index: props.product?.fast_search_index || '',
    hide: props.product ? !!props.product.hide : false,
    is_banned: props.product ? !!props.product.is_banned : false,
});

function nextStep() {
    if (currentStep.value < steps.length) {
        currentStep.value++;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function prevStep() {
    if (currentStep.value > 1) {
        currentStep.value--;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

function submit() {
    if (isEdit) {
        form.put(route('admin.products.update', props.product.id));
    } else {
        form.post(route('admin.products.store'));
    }
}

// ----- AJAX: HSN → auto-fill SGST / CGST / IGST -----
watch(() => form.hsn_id, (hsnId) => {
    if (!hsnId) return;
    axios.get(route('admin.products.hsnTax'), { params: { hsn_id: hsnId } })
        .then(({ data }) => {
            form.sgst = data.sgst ?? form.sgst;
            form.cgst = data.cgst ?? form.cgst;
            form.igst = data.igst ?? form.igst;
        })
        .catch(() => {}); // non-critical, user can still fill manually
});

// ----- AJAX: Rack Section → cascade load Rack Areas -----
watch(() => form.rack_section_id, (sectionId) => {
    form.rack_area_id = '';
    rackAreas.value   = [];
    if (!sectionId) return;
    axios.get(route('admin.products.rackAreas'), { params: { rack_section_id: sectionId } })
        .then(({ data }) => { rackAreas.value = data; })
        .catch(() => {});
});

// ----- AJAX: Product name duplicate check (debounced 600 ms) -----
watch(() => form.product_name, (name) => {
    clearTimeout(nameCheckTimer);
    nameWarning.value = null;
    if (!name || name.length < 3) return;
    nameCheckTimer = setTimeout(() => {
        axios.get(route('admin.products.checkName'), {
            params: { name, exclude_id: props.product?.id ?? null }
        })
        .then(({ data }) => {
            if (data.exists) {
                nameWarning.value = `Duplicate: SKU ${data.sku} already uses this name.`;
            }
        })
        .catch(() => {});
    }, 600);
});

// Seed rack areas when editing an existing product that already has a section
if (isEdit && props.product?.rack_section_id) {
    axios.get(route('admin.products.rackAreas'), { params: { rack_section_id: props.product.rack_section_id } })
        .then(({ data }) => { rackAreas.value = data; })
        .catch(() => {});
}
</script>

<template>
    <Head :title="isEdit ? 'Edit Product' : 'Add New Product'" />
    <AuthenticatedLayout>
        <div class="py-8 bg-gray-50 dark:bg-gray-900 min-h-screen font-sans selection:bg-indigo-500 selection:text-white">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                
                <!-- Header -->
                <div class="mb-10 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-4">
                            <Link :href="route('admin.products.index')" class="text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors p-2 hover:bg-white dark:hover:bg-gray-800 rounded-full shadow-sm border border-transparent hover:border-gray-200 dark:hover:border-gray-700">
                                <ArrowLeftIcon class="h-5 w-5" />
                            </Link>
                            <h2 class="text-2xl font-bold tracking-tight text-gray-800 dark:text-white">
                                {{ isEdit ? 'Edit Product' : 'Add New Product Form' }}
                            </h2>
                        </div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 ml-12 font-medium">Fill all form fields to go to the next step and complete the master setup.</p>
                    </div>
                </div>

                <!-- Main Form Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    
                    <!-- Progress Bar Top -->
                    <div class="px-8 py-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">
                        <!-- Decorative background element -->
                        <div class="absolute -top-24 -right-24 w-48 h-48 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
                        
                        <nav aria-label="Progress" class="relative z-10">
                            <ol role="list" class="flex items-center justify-between w-full">
                                <li v-for="(step, index) in steps" :key="step.id" class="relative flex-1">
                                    <div class="flex items-center justify-center">
                                        <div :class="[
                                            currentStep > step.id ? 'bg-indigo-600 dark:bg-indigo-500' : 'bg-gray-200 dark:bg-gray-700',
                                            'absolute inset-0 flex items-center transition-colors duration-500 ease-in-out'
                                        ]" aria-hidden="true">
                                            <div class="h-1.5 w-full rounded-full" :class="currentStep > step.id ? 'bg-indigo-600 dark:bg-indigo-500' : 'bg-gray-200 dark:bg-gray-700'"></div>
                                        </div>
                                        <div :class="[
                                            currentStep === step.id ? 'border-[6px] border-white dark:border-gray-800 bg-indigo-600 shadow-[0_0_15px_rgba(79,70,229,0.5)] text-white font-bold scale-110' : 
                                            currentStep > step.id ? 'bg-indigo-600 dark:bg-indigo-500 text-white shadow-md' : 'border-4 border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-400',
                                            'relative flex h-10 w-10 sm:h-14 sm:w-14 items-center justify-center rounded-full transition-all duration-300 z-10'
                                        ]">
                                            <CheckCircleIcon v-if="currentStep > step.id" class="h-6 w-6 text-white" />
                                            <span v-else class="text-sm sm:text-sidebar-active font-black">{{ step.id }}</span>
                                        </div>
                                    </div>
                                    <div class="mt-5 hidden sm:block text-center relative z-10 transition-all duration-300" :class="currentStep === step.id ? 'translate-y-0 opacity-100' : 'translate-y-1 opacity-70'">
                                        <span :class="[currentStep === step.id ? 'text-indigo-600 dark:text-indigo-400 font-extrabold' : 'text-gray-500 dark:text-gray-400', 'text-[11px] tracking-[0.2em] uppercase']">
                                            Step {{ step.id }}
                                        </span>
                                        <p class="text-xs text-gray-600 dark:text-gray-300 font-bold mt-1 max-w-[120px] mx-auto leading-tight">{{ step.name }}</p>
                                    </div>
                                </li>
                            </ol>
                        </nav>
                    </div>

                    <!-- Form Content -->
                    <div class="p-8 sm:p-14 relative z-0">
                        <!-- Decorative background glow for the active step -->
                        <div class="absolute inset-0 bg-gradient-to-b from-indigo-50/50 to-transparent dark:from-indigo-900/10 dark:to-transparent opacity-50 pointer-events-none"></div>
                        
                        <form @submit.prevent="submit" id="msform" class="relative z-10">
                            
                            <!-- STEP 1: IDENTITY -->
                            <div v-show="currentStep === 1" class="animate-in fade-in slide-in-from-right-4 duration-500">
                                <div class="mb-8">
                                    <h3 class="text-2xl font-black text-gray-900 dark:text-white">Step 1: Identity & Relationships</h3>
                                    <p class="text-gray-500 mt-1 text-sm">Configure the primary master tags and system linking.</p>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                                    <div class="lg:col-span-2">
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Product Name <span class="text-red-500">*</span></label>
                                        <input v-model="form.product_name" type="text" required class="w-full rounded-xl border-gray-300 focus:ring-4 focus:ring-indigo-500/20 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-lg p-3" placeholder="Enter full product name" />
                                        <p v-if="form.errors.product_name" class="text-red-500 text-xs mt-1 font-bold">{{ form.errors.product_name }}</p>
                                        <p v-if="nameWarning" class="text-amber-600 text-xs mt-1 font-bold">⚠ {{ nameWarning }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Status <span class="text-red-500">*</span></label>
                                        <select v-model="form.is_active" class="w-full rounded-xl border-gray-300 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white p-3">
                                            <option :value="true">Continue (Active)</option>
                                            <option :value="false">Close (Inactive)</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Company Link <span class="text-red-500">*</span></label>
                                        <select v-model="form.company_id" required class="w-full rounded-xl border-gray-300 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white p-3">
                                            <option value="">Please Select</option>
                                            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Category Link <span class="text-red-500">*</span></label>
                                        <select v-model="form.category_id" required class="w-full rounded-xl border-gray-300 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white p-3">
                                            <option value="">Please Select</option>
                                            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Clinical Salt Link <span class="text-red-500">*</span></label>
                                        <select v-model="form.salt_id" required class="w-full rounded-xl border-gray-300 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white p-3">
                                            <option value="">Please Select</option>
                                            <option v-for="s in salts" :key="s.id" :value="s.id">{{ s.name }}</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">SKU Core ID <span class="text-red-500">*</span></label>
                                        <input v-model="form.sku" type="text" required class="w-full rounded-xl border-gray-300 p-3 font-mono focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white uppercase" placeholder="SYS-SKU-001" />
                                        <p v-if="form.errors.sku" class="text-red-500 text-xs mt-1 font-bold">{{ form.errors.sku }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Product Code</label>
                                        <input v-model="form.product_code" type="text" class="w-full rounded-xl border-gray-300 p-3 font-mono focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Legacy Company Code</label>
                                        <input v-model="form.company_code" type="text" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                    </div>

                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Color Setup</label>
                                        <input v-model="form.color_item_type" type="text" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Item Type Setup</label>
                                        <input v-model="form.item_type" type="text" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Unit SMS Code</label>
                                        <input v-model="form.unit_sms_code" type="text" class="w-full rounded-xl border-gray-300 p-3 font-mono focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 2: PRICING -->
                            <div v-show="currentStep === 2" class="animate-in fade-in slide-in-from-right-4 duration-500">
                                <div class="mb-8">
                                    <h3 class="text-2xl font-black text-gray-900 dark:text-white">Step 2: Core Pricing Matrix</h3>
                                    <p class="text-gray-500 mt-1 text-sm">Define base landing costs, MRP, and primary wholesale boundaries.</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                    <!-- Primary Pricing -->
                                    <div class="bg-gray-50 dark:bg-gray-800/80 p-6 rounded-2xl border border-gray-100 dark:border-gray-700 space-y-6">
                                        <h4 class="text-sm font-black uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Primary Commercials</h4>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">MRP Constraint (Maximum Retail) <span class="text-red-500">*</span></label>
                                            <div class="relative">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4"><span class="text-gray-500">₹</span></div>
                                                <input v-model="form.mrp" type="number" step="0.01" required class="w-full rounded-xl border-gray-300 pl-10 p-4 font-mono text-lg focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">PTR System Rate (Retailer) <span class="text-red-500">*</span></label>
                                            <div class="relative">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4"><span class="text-gray-500">₹</span></div>
                                                <input v-model="form.ptr" type="number" step="0.01" required class="w-full rounded-xl border-gray-300 pl-10 p-4 font-mono text-lg focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">PTS System Rate (Stockist) <span class="text-red-500">*</span></label>
                                            <div class="relative">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4"><span class="text-gray-500">₹</span></div>
                                                <input v-model="form.pts" type="number" step="0.01" required class="w-full rounded-xl border-gray-300 pl-10 p-4 font-mono text-lg focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-emerald-700 dark:text-emerald-500 mb-2">Average Landing Cost</label>
                                            <div class="relative">
                                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4"><span class="text-emerald-500">₹</span></div>
                                                <input v-model="form.cost" type="number" step="0.01" class="w-full rounded-xl border-emerald-300 pl-10 p-4 font-mono text-lg focus:ring-4 focus:ring-emerald-500/20 bg-emerald-50 dark:bg-gray-700 dark:border-emerald-800 dark:text-white" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Legacy Pricing A/B/C -->
                                    <div class="bg-gray-50 dark:bg-gray-800/80 p-6 rounded-2xl border border-gray-100 dark:border-gray-700 space-y-6">
                                        <h4 class="text-sm font-black uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Rate Segmentation (A/B/C)</h4>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Rate A</label>
                                            <input v-model="form.rate_a" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 font-mono focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Rate B</label>
                                            <input v-model="form.rate_b" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 font-mono focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Rate C</label>
                                            <input v-model="form.rate_c" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 font-mono focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 3: DISCOUNTS -->
                            <div v-show="currentStep === 3" class="animate-in fade-in slide-in-from-right-4 duration-500">
                                <div class="mb-8">
                                    <h3 class="text-2xl font-black text-gray-900 dark:text-white">Step 3: Margins & Discounts</h3>
                                    <p class="text-gray-500 mt-1 text-sm">Fine-tune complex B2B incentive structures and schema rules.</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-6">
                                        <div class="col-span-full">
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Free Scheme (e.g., Buy X Get Y)</label>
                                            <input v-model="form.free_schema" type="text" placeholder="e.g. 10+1" class="w-full rounded-xl border-indigo-300 bg-indigo-50 text-indigo-900 font-black p-4 text-center focus:ring-4 focus:ring-indigo-500/20 dark:bg-indigo-900/20 dark:border-indigo-700 dark:text-indigo-100 placeholder:font-normal" />
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">General Discount (%)</label>
                                            <input v-model="form.general_discount" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Quantity Discount (%)</label>
                                            <input v-model="form.quantity_discount" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Special Discount (%)</label>
                                            <input v-model="form.special_discount" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Item Special Discount (%)</label>
                                            <input v-model="form.item_special_discount" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 dark:bg-gray-800/80 p-6 rounded-2xl border border-gray-100 dark:border-gray-700 space-y-6">
                                        <h4 class="text-sm font-black uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Hard Limits</h4>
                                        <div>
                                            <label class="block text-sm font-bold text-green-700 dark:text-green-500 mb-2">P-Rate Lock (%)</label>
                                            <input v-model="form.p_rate_discount" type="number" step="0.01" class="w-full rounded-xl border-green-300 bg-green-50 p-3 focus:ring-4 focus:ring-green-500/20 dark:bg-green-900/10 dark:border-green-800 dark:text-green-300" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-red-700 dark:text-red-500 mb-2">Max Expected Discount (%)</label>
                                            <input v-model="form.max_discount" type="number" step="0.01" class="w-full rounded-xl border-red-300 bg-red-50 p-3 focus:ring-4 focus:ring-red-500/20 dark:bg-red-900/10 dark:border-red-800 dark:text-red-300" />
                                        </div>
                                        <div>
                                            <label class="block text-sm font-bold text-amber-700 dark:text-amber-500 mb-2">Min Hardware Margin (%)</label>
                                            <input v-model="form.min_margin_disc" type="number" step="0.01" class="w-full rounded-xl border-amber-300 bg-amber-50 p-3 focus:ring-4 focus:ring-amber-500/20 dark:bg-amber-900/10 dark:border-amber-800 dark:text-amber-300" />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 4: TAX -->
                            <div v-show="currentStep === 4" class="animate-in fade-in slide-in-from-right-4 duration-500">
                                <div class="mb-8">
                                    <h3 class="text-2xl font-black text-gray-900 dark:text-white">Step 4: Taxes & Compliance</h3>
                                    <p class="text-gray-500 mt-1 text-sm">Assign structural taxes natively mapped to the item if it explicitly deviates from the HSN default.</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                                    <div class="lg:col-span-3">
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Link Default HSN/SAC <span class="text-red-500">*</span></label>
                                        <select v-model="form.hsn_id" required class="w-full rounded-xl border-gray-300 p-4 font-mono text-lg focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                            <option value="">Select HSN</option>
                                            <option v-for="h in hsn_codes" :key="h.id" :value="h.id">{{ h.hsn_code }}</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">SGST Override (%)</label>
                                        <input v-model="form.sgst" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-center" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">CGST Override (%)</label>
                                        <input v-model="form.cgst" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-center" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">IGST Override (%)</label>
                                        <input v-model="form.igst" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-center" />
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Local Tax Extra (%)</label>
                                        <input v-model="form.local_tax" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-center" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Central Tax Extra (%)</label>
                                        <input v-model="form.central_tax" type="number" step="0.01" class="w-full rounded-xl border-gray-300 p-4 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-center" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-bold text-blue-700 dark:text-blue-500 mb-2">CSR Duty (%)</label>
                                        <input v-model="form.csr" type="number" step="0.01" class="w-full rounded-xl border-blue-300 bg-blue-50 p-4 focus:ring-4 focus:ring-blue-500/20 dark:bg-blue-900/10 dark:border-blue-800 dark:text-blue-300 text-center font-bold" />
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 5: INVENTORY & WAREHOUSE -->
                            <div v-show="currentStep === 5" class="animate-in fade-in slide-in-from-right-4 duration-500">
                                <div class="mb-8">
                                    <h3 class="text-2xl font-black text-gray-900 dark:text-white">Step 5: Master Inventory Rules</h3>
                                    <p class="text-gray-500 mt-1 text-sm">Packing conversions, min/max alerts, and precise warehouse tracking.</p>
                                </div>

                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
                                    <!-- Physics -->
                                    <div class="space-y-6">
                                        <h4 class="text-base font-black uppercase tracking-widest text-gray-400 border-b border-gray-200 dark:border-gray-700 pb-2">Physical Rules</h4>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="col-span-2">
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Packing String</label>
                                                <input v-model="form.packing_desc" type="text" placeholder="10x10 Strip" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Primary Unit</label>
                                                <input v-model="form.unit" type="text" placeholder="Box" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Secondary Unit</label>
                                                <input v-model="form.secondary_unit" type="text" placeholder="Tablet" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                            <div class="col-span-2 bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-xl border border-indigo-100 dark:border-indigo-800 mt-2">
                                                <label class="block text-sm font-black text-indigo-900 dark:text-indigo-200 mb-2">Conversion Factor (Multiplier) <span class="text-red-500">*</span></label>
                                                <input v-model="form.conversion_factor" type="number" min="1" required class="w-full rounded-xl border-indigo-300 p-3 font-mono text-center text-lg focus:ring-4 focus:ring-indigo-500/20 dark:bg-indigo-950 dark:border-indigo-700 dark:text-indigo-100" />
                                            </div>
                                            <div class="col-span-2 flex items-center mt-2">
                                                <label class="flex items-center gap-3 cursor-pointer">
                                                    <input v-model="form.is_loose_sellable" type="checkbox" class="h-6 w-6 rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:bg-gray-800 dark:border-gray-600" />
                                                    <span class="text-sm font-bold text-gray-800 dark:text-gray-200">Allow Loose Sales (via Split)</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Alerts -->
                                    <div class="space-y-6">
                                        <h4 class="text-base font-black uppercase tracking-widest text-gray-400 border-b border-gray-200 dark:border-gray-700 pb-2">Tracking & Alerts</h4>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Min Stock Lock</label>
                                                <input v-model="form.min_stock_level" type="number" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Max Dump Hold</label>
                                                <input v-model="form.max_stock_level" type="number" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Reorder Dump Qty</label>
                                                <input v-model="form.reorder_quantity" type="number" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Reorder Span (Days)</label>
                                                <input v-model="form.reorder_days" type="number" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                            <div class="col-span-2">
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Shelf Life Limit (Months)</label>
                                                <input v-model="form.shelflife" type="number" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                            </div>
                                        </div>

                                        <h4 class="text-base font-black uppercase tracking-widest text-gray-400 border-b border-gray-200 dark:border-gray-700 pb-2 mt-8">Warehouse Location</h4>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Rack Section</label>
                                                <select v-model="form.rack_section_id" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                    <option value="">None</option>
                                                    <option v-for="s in rack_sections" :key="s.id" :value="s.id">{{ s.section_name }}</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Rack Area Zone</label>
                                                <select v-model="form.rack_area_id" :disabled="!form.rack_section_id" class="w-full rounded-xl border-gray-300 p-3 focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white disabled:opacity-50">
                                                    <option value="">None</option>
                                                    <option v-for="a in rackAreas" :key="a.id" :value="a.id">{{ a.name }}</option>
                                                </select>
                                                <p v-if="form.rack_section_id && rackAreas.length === 0" class="text-xs text-gray-400 mt-1">No areas for this section yet.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-8 border-t border-gray-200 dark:border-gray-700 pt-6">
                                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">App / Search Index</label>
                                    <input v-model="form.fast_search_index" type="text" class="w-full rounded-xl border-gray-300 p-3 font-mono focus:ring-4 focus:ring-indigo-500/20 dark:bg-gray-700 dark:border-gray-600 dark:text-white" />
                                    <div class="flex items-center gap-6 mt-6">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input v-model="form.hide" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-yellow-500" />
                                            <span class="text-sm font-bold text-gray-800 dark:text-gray-200">Hide from App Global Search</span>
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input v-model="form.is_banned" type="checkbox" class="h-5 w-5 rounded border-gray-300 text-red-600" />
                                            <span class="text-sm font-bold text-red-600 dark:text-red-400">Mark Banned (Global)</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                        </form>
                    </div>

                    <!-- Footer / Pagination Buttons -->
                    <div class="px-8 py-8 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/50 flex flex-col sm:flex-row items-center justify-between shadow-[inset_0_2px_10px_rgba(0,0,0,0.02)] gap-4 backdrop-blur-sm relative z-10">
                        <button v-if="currentStep > 1" type="button" @click="prevStep" 
                            class="w-full sm:w-auto px-8 py-3.5 rounded-2xl text-sm font-bold text-gray-600 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 hover:shadow-md focus:outline-none focus:ring-4 focus:ring-gray-100 dark:focus:ring-gray-700 transition-all duration-300 ease-[cubic-bezier(0.25,1,0.5,1)] hover:-translate-y-0.5">
                            <span class="mr-2 opacity-50 tracking-widest uppercase text-xs">←</span> Return Previous
                        </button>
                        <div v-else></div> <!-- Spacer -->

                        <button v-if="currentStep < steps.length" type="button" @click="nextStep"
                            class="w-full sm:w-auto px-10 py-3.5 rounded-2xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-indigo-500 shadow-lg shadow-indigo-500/30 hover:shadow-indigo-500/50 focus:outline-none focus:ring-4 focus:ring-indigo-500/30 transition-all duration-300 ease-[cubic-bezier(0.25,1,0.5,1)] hover:-translate-y-0.5 disabled:opacity-50 disabled:hover:translate-y-0 tracking-wide">
                            Proceed to Next <span class="ml-2 opacity-70 tracking-widest uppercase text-xs">→</span>
                        </button>
                        
                        <button v-if="currentStep === steps.length" type="button" @click="submit" :disabled="form.processing"
                            class="w-full sm:w-auto px-10 py-3.5 rounded-2xl text-sm font-bold text-white bg-gradient-to-r from-emerald-600 to-emerald-400 shadow-lg shadow-emerald-500/30 hover:shadow-emerald-500/50 focus:outline-none focus:ring-4 focus:ring-emerald-500/30 transition-all duration-300 ease-[cubic-bezier(0.25,1,0.5,1)] hover:-translate-y-0.5 flex items-center justify-center gap-3 disabled:opacity-50 disabled:hover:translate-y-0 group">
                            <span class="tracking-wide">{{ form.processing ? 'Synchronizing Model...' : (isEdit ? 'Save Enterprise Output' : 'Finish & Publish Product Model') }}</span>
                            <CheckCircleIcon v-if="!form.processing" class="h-5 w-5 group-hover:scale-110 transition-transform" />
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
