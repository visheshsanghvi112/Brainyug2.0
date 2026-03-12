<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { TruckIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    supplier: { type: Object, default: null },
    states: Array,
    districts: Array,
});

const isEdit = computed(() => !!props.supplier?.id);
const pageTitle = computed(() => isEdit.value ? `Edit Supplier: ${props.supplier.name}` : 'Add Supplier');

const form = useForm({
    name: props.supplier?.name || '',
    code: props.supplier?.code || '',
    contact_person: props.supplier?.contact_person || '',
    phone: props.supplier?.phone || '',
    email: props.supplier?.email || '',
    address: props.supplier?.address || '',
    state_id: props.supplier?.state_id || '',
    district_id: props.supplier?.district_id || '',
    pincode: props.supplier?.pincode || '',
    gst_number: props.supplier?.gst_number || '',
    pan_number: props.supplier?.pan_number || '',
    dl_number: props.supplier?.dl_number || '',
    bank_name: props.supplier?.bank_name || '',
    bank_account_number: props.supplier?.bank_account_number || '',
    bank_ifsc: props.supplier?.bank_ifsc || '',
    credit_limit: props.supplier?.credit_limit || 0,
    credit_days: props.supplier?.credit_days || 30,
    is_active: props.supplier?.is_active ?? true,
});

const filteredDistricts = computed(() =>
    form.state_id ? props.districts.filter(d => d.state_id == form.state_id) : props.districts
);

watch(() => form.state_id, () => { form.district_id = ''; });

function submit() {
    if (isEdit.value) {
        form.put(route('admin.suppliers.update', props.supplier.id));
    } else {
        form.post(route('admin.suppliers.store'));
    }
}
</script>

<template>
    <Head :title="pageTitle" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('admin.suppliers.index')" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-300 transition">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <div class="flex items-center gap-3">
                    <TruckIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        {{ pageTitle }}
                    </h2>
                </div>
            </div>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <form @submit.prevent="submit" class="space-y-6">
                    
                    <!-- Basic Info -->
                    <div class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Basic Info</h3>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Supplier Name *</label>
                                <input v-model="form.name" type="text" required class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-500">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Code</label>
                                <input v-model="form.code" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                                <p v-if="form.errors.code" class="mt-1 text-sm text-red-500">{{ form.errors.code }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contact Person</label>
                                <input v-model="form.contact_person" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                                <input v-model="form.phone" type="tel" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                            </div>
                        </div>
                    </div>

                    <!-- Location & Tax -->
                    <div class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Location & Compliance</h3>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">GST Number</label>
                                <input v-model="form.gst_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono uppercase" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">PAN Number</label>
                                <input v-model="form.pan_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white font-mono uppercase" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                                <textarea v-model="form.address" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">State</label>
                                <select v-model="form.state_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                    <option value="">Select State</option>
                                    <option v-for="s in states" :key="s.id" :value="s.id">{{ s.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">District</label>
                                <select v-model="form.district_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                    <option value="">Select District</option>
                                    <option v-for="d in filteredDistricts" :key="d.id" :value="d.id">{{ d.name }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Financial & Misc -->
                    <div class="rounded-xl bg-white p-6 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">Financial & Settings</h3>
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                             <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Credit Limit</label>
                                <input v-model="form.credit_limit" type="number" step="0.01" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Credit Days</label>
                                <input v-model="form.credit_days" type="number" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white" />
                            </div>
                            <div class="sm:col-span-2 flex items-center gap-3">
                                <input v-model="form.is_active" type="checkbox" id="isActive" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                                <label for="isActive" class="text-sm font-medium text-gray-700 dark:text-gray-300">Active Supplier</label>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <Link :href="route('admin.suppliers.index')" class="text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200">Cancel</Link>
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50 transition">
                            {{ form.processing ? 'Saving...' : 'Save Supplier' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
