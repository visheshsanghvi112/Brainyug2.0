<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    states: Array,
    districts: Array,
});

const form = useForm({
    shop_name: '',
    owner_name: '',
    mobile: '',
    whatsapp: '',
    email: '',
    state_id: '',
    district_id: '',
    address: '',
    gst_number: '',
    investment_amount: '',
    ready_to_invest: false,
});

const filteredDistricts = computed(() => {
    return form.state_id
        ? props.districts.filter((district) => district.state_id == form.state_id)
        : [];
});

watch(() => form.state_id, () => {
    form.district_id = '';
});

function submit() {
    form.post(route('franchise.apply.store'));
}
</script>

<template>
    <GuestLayout>
        <Head title="Franchise Apply" />

        <div class="space-y-6">
            <div class="space-y-2 text-center">
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Franchise Application</h1>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Submit an enquiry for review. Approval, owner provisioning, and activation happen later inside the ERP workflow.
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Shop Name</label>
                    <input v-model="form.shop_name" type="text" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                    <p v-if="form.errors.shop_name" class="mt-1 text-sm text-red-600">{{ form.errors.shop_name }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Owner Name</label>
                    <input v-model="form.owner_name" type="text" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                    <p v-if="form.errors.owner_name" class="mt-1 text-sm text-red-600">{{ form.errors.owner_name }}</p>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Mobile</label>
                        <input v-model="form.mobile" type="text" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                        <p v-if="form.errors.mobile" class="mt-1 text-sm text-red-600">{{ form.errors.mobile }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">WhatsApp</label>
                        <input v-model="form.whatsapp" type="text" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                    <input v-model="form.email" type="email" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">State</label>
                        <select v-model="form.state_id" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                            <option value="">Select state</option>
                            <option v-for="state in states" :key="state.id" :value="state.id">{{ state.name }}</option>
                        </select>
                        <p v-if="form.errors.state_id" class="mt-1 text-sm text-red-600">{{ form.errors.state_id }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">District</label>
                        <select v-model="form.district_id" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                            <option value="">Select district</option>
                            <option v-for="district in filteredDistricts" :key="district.id" :value="district.id">{{ district.name }}</option>
                        </select>
                        <p v-if="form.errors.district_id" class="mt-1 text-sm text-red-600">{{ form.errors.district_id }}</p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address</label>
                    <textarea v-model="form.address" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">GST Number</label>
                        <input v-model="form.gst_number" type="text" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Planned Investment</label>
                        <input v-model="form.investment_amount" type="number" min="0" step="0.01" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                </div>

                <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
                    <input v-model="form.ready_to_invest" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                    Ready to invest
                </label>

                <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500" :disabled="form.processing">
                    Submit Application
                </button>
            </form>
        </div>
    </GuestLayout>
</template>