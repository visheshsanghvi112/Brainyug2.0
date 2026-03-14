<script setup>
import { ref, watch } from 'vue';
import { debounce } from 'lodash';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { PlusIcon, TrashIcon, PencilSquareIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    salts: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');

watch(search, debounce((val) => {
    router.get(route('admin.salt-masters.index'), { search: val }, { preserveState: true, replace: true });
}, 300));

const isEditing = ref(false);
const showForm = ref(false);
const currentId = ref(null);

const form = useForm({
    name: '',
    indication: '',
    dosage: '',
    side_effects: '',
    special_precaution: '',
    drug_interaction: '',
    is_narcotic: false,
    schedule_h: false,
    schedule_h1: false,
    note: '',
    maximum_rate: '',
    continued: '',
    prohibited: '',
    legacy_category_id: null,
    legacy_sub_category_id: null,
});

const openCreateForm = () => {
    isEditing.value = false;
    currentId.value = null;
    form.reset();
    showForm.value = true;
};

const openEditForm = (item) => {
    isEditing.value = true;
    currentId.value = item.id;
    form.name = item.name;
    form.indication = item.indication;
    form.dosage = item.dosage;
    form.side_effects = item.side_effects;
    form.special_precaution = item.special_precaution;
    form.drug_interaction = item.drug_interaction;
    form.is_narcotic = item.is_narcotic == 1;
    form.schedule_h = item.schedule_h == 1;
    form.schedule_h1 = item.schedule_h1 == 1;
    form.note = item.note ?? '';
    form.maximum_rate = item.maximum_rate ?? '';
    form.continued = item.continued ?? '';
    form.prohibited = item.prohibited ?? '';
    form.legacy_category_id = item.legacy_category_id ?? null;
    form.legacy_sub_category_id = item.legacy_sub_category_id ?? null;
    showForm.value = true;
};

const submit = () => {
    if (isEditing.value) {
        form.put(route('admin.salt-masters.update', currentId.value), {
            onSuccess: () => { showForm.value = false; form.reset(); }
        });
    } else {
        form.post(route('admin.salt-masters.store'), {
            onSuccess: () => { showForm.value = false; form.reset(); }
        });
    }
};

const deleteSalt = (id) => {
    if (confirm('Are you sure you want to delete this Salt/Drug composition?')) {
        form.delete(route('admin.salt-masters.destroy', id));
    }
};
</script>

<template>
    <Head title="Salt / Drug Composition Master" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">Salt & Drug Composition</h2>
                <button @click="openCreateForm" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">
                    <PlusIcon class="h-4 w-4 stroke-2" /> Add Salt
                </button>
            </div>
        </template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8">
            <!-- Inline Form -->
            <div v-if="showForm" class="mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ isEditing ? 'Edit' : 'Create' }} Drug Composition</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Define the pharmaceutical profile, clinical data, and regulatory flags.</p>
                </div>
                <div class="p-6">
                    <form @submit.prevent="submit" class="space-y-5">
                        <!-- Name -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Generic Name (INN) <span class="text-red-500">*</span></label>
                            <input type="text" v-model="form.name" required placeholder="e.g. Paracetamol 500mg" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 font-semibold" />
                            <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                        </div>

                        <!-- Clinical Data -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Clinical Indication</label>
                                <textarea v-model="form.indication" rows="3" placeholder="Condition for which this drug is approved..." class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Standard Dosage</label>
                                <textarea v-model="form.dosage" rows="3" placeholder="Dosage instructions..." class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Side Effects</label>
                                <textarea v-model="form.side_effects" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 resize-none"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Special Precautions</label>
                                <textarea v-model="form.special_precaution" rows="3" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 resize-none"></textarea>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Drug Interactions</label>
                            <textarea v-model="form.drug_interaction" rows="2" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 resize-none"></textarea>
                        </div>

                        <!-- Regulatory Checkboxes -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-5">
                            <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Regulatory Classification</label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <label class="flex items-center gap-3 cursor-pointer p-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 hover:bg-red-50 dark:hover:bg-red-900/10 hover:border-red-300 dark:hover:border-red-700 transition-colors select-none">
                                    <input type="checkbox" v-model="form.is_narcotic" class="h-4 w-4 rounded border-gray-300 dark:border-gray-500 bg-white dark:bg-gray-600 text-red-600 focus:ring-red-500 focus:ring-offset-0" />
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Narcotic / NDPS</span>
                                </label>
                                <label class="flex items-center gap-3 cursor-pointer p-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 hover:bg-amber-50 dark:hover:bg-amber-900/10 hover:border-amber-300 dark:hover:border-amber-700 transition-colors select-none">
                                    <input type="checkbox" v-model="form.schedule_h" class="h-4 w-4 rounded border-gray-300 dark:border-gray-500 bg-white dark:bg-gray-600 text-amber-600 focus:ring-amber-500 focus:ring-offset-0" />
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Schedule H</span>
                                </label>
                                <label class="flex items-center gap-3 cursor-pointer p-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 hover:bg-orange-50 dark:hover:bg-orange-900/10 hover:border-orange-300 dark:hover:border-orange-700 transition-colors select-none">
                                    <input type="checkbox" v-model="form.schedule_h1" class="h-4 w-4 rounded border-gray-300 dark:border-gray-500 bg-white dark:bg-gray-600 text-orange-600 focus:ring-orange-500 focus:ring-offset-0" />
                                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Schedule H1</span>
                                </label>
                            </div>
                        </div>

                        <!-- Legacy Overlays -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-5 mt-3">
                            <p class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wider">Legacy Settings (Data Migration Mapping)</p>
                            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Max Rate</label>
                                    <input v-model="form.maximum_rate" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Continued (Active)</label>
                                    <input v-model="form.continued" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" placeholder="Y / N" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Prohibited (Banned)</label>
                                    <input v-model="form.prohibited" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" placeholder="Y / N" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Legacy Note</label>
                                    <input v-model="form.note" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                                </div>
                            </div>
                            <!-- Assuming legacy category IDs don't need UI inputs frequently, but can be added if needed later. -->
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showForm = false" class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                            <button type="submit" :disabled="form.processing" class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-500 shadow-sm transition-colors disabled:opacity-50">Save Composition</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3">
                    <div class="relative flex-1 max-w-sm">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                        <input type="text" v-model="search" placeholder="Search by name..." class="w-full pl-9 pr-4 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20" />
                    </div>
                    <span class="text-xs text-gray-400 whitespace-nowrap">{{ salts.total?.toLocaleString() }} records</span>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Regulatory</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Indication</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                        <tr v-for="salt in salts.data" :key="salt.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ salt.name }}</div>
                                <div class="text-xs text-gray-400 dark:text-gray-500 font-mono mt-0.5">ID-{{ String(salt.id).padStart(5, '0') }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1.5">
                                    <span v-if="salt.is_narcotic" class="inline-flex items-center gap-1 rounded-md bg-red-50 dark:bg-red-900/30 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-400 ring-1 ring-inset ring-red-600/10 dark:ring-red-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> NDPS
                                    </span>
                                    <span v-if="salt.schedule_h" class="inline-flex items-center gap-1 rounded-md bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-400 ring-1 ring-inset ring-amber-600/10 dark:ring-amber-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Sch-H
                                    </span>
                                    <span v-if="salt.schedule_h1" class="inline-flex items-center gap-1 rounded-md bg-orange-50 dark:bg-orange-900/30 px-2 py-0.5 text-xs font-semibold text-orange-700 dark:text-orange-400 ring-1 ring-inset ring-orange-600/10 dark:ring-orange-400/20">
                                        <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span> Sch-H1
                                    </span>
                                    <span v-if="!salt.is_narcotic && !salt.schedule_h && !salt.schedule_h1" class="text-xs text-gray-400 italic">OTC</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300 max-w-xs truncate" :title="salt.indication">
                                {{ salt.indication || '—' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button @click="openEditForm(salt)" class="p-2 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <PencilSquareIcon class="w-4 h-4" />
                                    </button>
                                    <button @click="deleteSalt(salt.id)" class="p-2 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!salts.data.length">
                            <td colspan="4" class="px-6 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
                                No salt compositions defined yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->
            </div>

            <Pagination :data="salts" />
        </div>
    </AuthenticatedLayout>
</template>
