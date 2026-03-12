<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { BuildingStorefrontIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    franchisee: { type: Object, default: null },
    states: Array,
    districts: Array,
    cities: Array,
});

const isEdit = computed(() => !!props.franchisee?.id);
const pageTitle = computed(() => isEdit.value ? `Edit: ${props.franchisee.shop_name}` : 'Register New Franchisee');

const form = useForm({
    shop_name: props.franchisee?.shop_name || '',
    shop_type: props.franchisee?.shop_type || 'franchise',
    owner_name: props.franchisee?.owner_name || '',
    owner_title: props.franchisee?.owner_title || 'Mr',
    partner_name: props.franchisee?.partner_name || '',
    partner_title: props.franchisee?.partner_title || '',
    owner_dob: props.franchisee?.owner_dob || '',
    education: props.franchisee?.education || '',
    occupation: props.franchisee?.occupation || '',
    email: props.franchisee?.email || '',
    mobile: props.franchisee?.mobile || '',
    whatsapp: props.franchisee?.whatsapp || '',
    alternate_phone: props.franchisee?.alternate_phone || '',
    address: props.franchisee?.address || '',
    state_id: props.franchisee?.state_id || '',
    district_id: props.franchisee?.district_id || '',
    city_id: props.franchisee?.city_id || '',
    other_city: props.franchisee?.other_city || '',
    pincode: props.franchisee?.pincode || '',
    residence_address: props.franchisee?.residence_address || '',
    gst_number: props.franchisee?.gst_number || '',
    pan_number: props.franchisee?.pan_number || '',
    dl_number_20b: props.franchisee?.dl_number_20b || '',
    dl_number_21b: props.franchisee?.dl_number_21b || '',
    fssai_number: props.franchisee?.fssai_number || '',
    bank_name: props.franchisee?.bank_name || '',
    bank_account_holder: props.franchisee?.bank_account_holder || '',
    bank_account_number: props.franchisee?.bank_account_number || '',
    bank_ifsc: props.franchisee?.bank_ifsc || '',
    bank_branch: props.franchisee?.bank_branch || '',
    utr_number: props.franchisee?.utr_number || '',
    transaction_date: props.franchisee?.transaction_date || '',
    investment_amount: props.franchisee?.investment_amount || '',
    ready_to_invest: props.franchisee?.ready_to_invest || false,
});

const filteredDistricts = computed(() =>
    form.state_id ? props.districts.filter(d => d.state_id == form.state_id) : props.districts
);
const filteredCities = computed(() =>
    form.district_id ? props.cities.filter(c => c.district_id == form.district_id) : props.cities
);

watch(() => form.state_id, () => { form.district_id = ''; form.city_id = ''; });
watch(() => form.district_id, () => { form.city_id = ''; });

function submit() {
    if (isEdit.value) {
        form.put(route('admin.franchisees.update', props.franchisee.id));
    } else {
        form.post(route('admin.franchisees.store'));
    }
}

const sections = [
    { id: 'shop', label: 'Shop Details' },
    { id: 'owner', label: 'Owner Details' },
    { id: 'contact', label: 'Contact Info' },
    { id: 'address', label: 'Address' },
    { id: 'legal', label: 'Legal & Compliance' },
    { id: 'banking', label: 'Banking Details' },
];

const activeSection = ref('shop');
</script>

<template>
    <Head :title="pageTitle" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('admin.franchisees.index')" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300 transition">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div class="flex items-center gap-3">
                    <BuildingStorefrontIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        {{ pageTitle }}
                    </h2>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <!-- Section Nav -->
                <div class="mb-6 flex flex-wrap gap-2">
                    <button
                        v-for="sec in sections"
                        :key="sec.id"
                        @click="activeSection = sec.id"
                        class="rounded-full px-4 py-2 text-sm font-medium transition-all duration-200"
                        :class="activeSection === sec.id
                            ? 'bg-indigo-600 text-white shadow-md'
                            : 'bg-white text-gray-600 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 ring-1 ring-gray-200 dark:ring-gray-700'"
                    >
                        {{ sec.label }}
                    </button>
                </div>

                <form @submit.prevent="submit">
                    <div class="space-y-6">
                        <!-- Shop Details -->
                        <div v-show="activeSection === 'shop'" class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Shop Details</h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shop Name *</label>
                                    <input v-model="form.shop_name" type="text" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                    <p v-if="form.errors.shop_name" class="mt-1 text-sm text-red-500">{{ form.errors.shop_name }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shop Type</label>
                                    <select v-model="form.shop_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                        <option value="franchise">Franchise</option>
                                        <option value="distributor">Distributor</option>
                                        <option value="sub_distributor">Sub-Distributor</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Owner Details -->
                        <div v-show="activeSection === 'owner'" class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Owner Details</h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                                    <select v-model="form.owner_title" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                        <option value="Mr">Mr</option>
                                        <option value="Mrs">Mrs</option>
                                        <option value="Ms">Ms</option>
                                        <option value="Dr">Dr</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Owner Name *</label>
                                    <input v-model="form.owner_name" type="text" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                    <p v-if="form.errors.owner_name" class="mt-1 text-sm text-red-500">{{ form.errors.owner_name }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Partner Name</label>
                                    <input v-model="form.partner_name" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Date of Birth</label>
                                    <input v-model="form.owner_dob" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Education</label>
                                    <input v-model="form.education" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Occupation</label>
                                    <input v-model="form.occupation" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                            </div>
                        </div>

                        <!-- Contact -->
                        <div v-show="activeSection === 'contact'" class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Contact Information</h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Mobile *</label>
                                    <input v-model="form.mobile" type="tel" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                    <p v-if="form.errors.mobile" class="mt-1 text-sm text-red-500">{{ form.errors.mobile }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">WhatsApp</label>
                                    <input v-model="form.whatsapp" type="tel" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                                    <input v-model="form.email" type="email" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Alternate Phone</label>
                                    <input v-model="form.alternate_phone" type="tel" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div v-show="activeSection === 'address'" class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Address</h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shop Address</label>
                                    <textarea v-model="form.address" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">State *</label>
                                    <select v-model="form.state_id" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                        <option value="">Select State</option>
                                        <option v-for="s in states" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </select>
                                    <p v-if="form.errors.state_id" class="mt-1 text-sm text-red-500">{{ form.errors.state_id }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">District *</label>
                                    <select v-model="form.district_id" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                        <option value="">Select District</option>
                                        <option v-for="d in filteredDistricts" :key="d.id" :value="d.id">{{ d.name }}</option>
                                    </select>
                                    <p v-if="form.errors.district_id" class="mt-1 text-sm text-red-500">{{ form.errors.district_id }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City</label>
                                    <select v-model="form.city_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                        <option value="">Select City</option>
                                        <option v-for="c in filteredCities" :key="c.id" :value="c.id">{{ c.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Pincode</label>
                                    <input v-model="form.pincode" type="text" maxlength="10" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Residence Address</label>
                                    <textarea v-model="form.residence_address" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Legal -->
                        <div v-show="activeSection === 'legal'" class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Legal & Compliance</h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">GST Number</label>
                                    <input v-model="form.gst_number" type="text" maxlength="20" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono uppercase" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">PAN Number</label>
                                    <input v-model="form.pan_number" type="text" maxlength="12" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono uppercase" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Drug License (20B)</label>
                                    <input v-model="form.dl_number_20b" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Drug License (21B)</label>
                                    <input v-model="form.dl_number_21b" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">FSSAI Number</label>
                                    <input v-model="form.fssai_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                            </div>
                        </div>

                        <!-- Banking -->
                        <div v-show="activeSection === 'banking'" class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                            <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Banking Details</h3>
                            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Bank Name</label>
                                    <input v-model="form.bank_name" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Account Holder</label>
                                    <input v-model="form.bank_account_holder" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Account Number</label>
                                    <input v-model="form.bank_account_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">IFSC Code</label>
                                    <input v-model="form.bank_ifsc" type="text" maxlength="15" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono uppercase" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Branch</label>
                                    <input v-model="form.bank_branch" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">UTR Number</label>
                                    <input v-model="form.utr_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Transaction Date</label>
                                    <input v-model="form.transaction_date" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Investment Amount (₹)</label>
                                    <input v-model="form.investment_amount" type="number" step="0.01" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                </div>
                                <div class="flex items-center gap-3 sm:col-span-2">
                                    <input v-model="form.ready_to_invest" type="checkbox" id="readyToInvest" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                    <label for="readyToInvest" class="text-sm font-medium text-gray-700 dark:text-gray-300">Ready to invest</label>
                                </div>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="flex items-center justify-end gap-3">
                            <Link
                                :href="route('admin.franchisees.index')"
                                class="rounded-lg px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700 transition"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 transition-all duration-200"
                            >
                                {{ form.processing ? 'Saving...' : (isEdit ? 'Update Franchisee' : 'Register Franchisee') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
