<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    franchisees: Array,
});

const checklistKeys = [
    { key: 'shop_cleanliness', label: 'Shop Cleanliness' },
    { key: 'product_display', label: 'Product Display' },
    { key: 'stock_adequacy', label: 'Stock Adequacy' },
    { key: 'staff_in_uniform', label: 'Staff in Uniform' },
    { key: 'pos_system_working', label: 'POS System Working' },
    { key: 'license_displayed', label: 'License Displayed' },
    { key: 'fridge_maintained', label: 'Fridge Maintained' },
    { key: 'feedback_register', label: 'Feedback Register Updated' },
];

const defaultChecklist = Object.fromEntries(checklistKeys.map(c => [c.key, false]));

const form = useForm({
    franchisee_id: '',
    visit_date: new Date().toISOString().split('T')[0],
    inspection_score: 80,
    notes: '',
    checklist: { ...defaultChecklist },
    status: 'completed',
});

function submit() {
    form.post(route('shop-visits.store'));
}
</script>

<template>
    <Head title="New Shop Audit" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('shop-visits.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">New Shop Visit Audit</h2>
            </div>
        </template>

        <div class="max-w-2xl">
            <form @submit.prevent="submit" class="space-y-5">
                <div class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700 space-y-5">

                    <!-- Franchisee -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Shop (Franchisee) <span class="text-red-500">*</span></label>
                        <select v-model="form.franchisee_id" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                            <option value="">Select shop…</option>
                            <option v-for="f in franchisees" :key="f.id" :value="f.id">{{ f.shop_name }} — {{ f.district }}</option>
                        </select>
                        <p v-if="form.errors.franchisee_id" class="mt-1 text-xs text-red-500">{{ form.errors.franchisee_id }}</p>
                    </div>

                    <!-- Visit Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Visit Date <span class="text-red-500">*</span></label>
                        <input v-model="form.visit_date" type="date" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                    </div>

                    <!-- Score -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Inspection Score: <span class="text-indigo-600 font-bold">{{ form.inspection_score }}/100</span>
                        </label>
                        <input v-model.number="form.inspection_score" type="range" min="0" max="100"
                            class="w-full accent-indigo-600" />
                        <p v-if="form.errors.inspection_score" class="mt-1 text-xs text-red-500">{{ form.errors.inspection_score }}</p>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Observations / Notes</label>
                        <textarea v-model="form.notes" rows="3"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                            placeholder="Describe what was observed during the visit…" />
                    </div>

                    <!-- Status -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Save as</label>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="form.status" value="draft" class="text-indigo-600" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Draft</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" v-model="form.status" value="completed" class="text-indigo-600" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Completed</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Checklist -->
                <div class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Inspection Checklist</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label v-for="item in checklistKeys" :key="item.key"
                            class="flex items-center gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <input type="checkbox" :id="item.key" v-model="form.checklist[item.key]"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4" />
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ item.label }}</span>
                        </label>
                    </div>
                </div>

                <!-- Submit -->
                <div class="flex justify-end gap-3">
                    <Link :href="route('shop-visits.index')"
                        class="rounded-lg px-5 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                        Save Audit
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
