<script setup>
import { ref, watch } from 'vue';
import { debounce } from 'lodash';
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { PlusIcon, TrashIcon, PencilSquareIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    hsnCodes: Object,
    filters: Object,
});

const search = ref(props.filters?.search ?? '');

watch(search, debounce((val) => {
    router.get(route('admin.hsn-masters.index'), { search: val }, { preserveState: true, replace: true });
}, 300));

const isEditing = ref(false);
const showForm = ref(false);
const currentId = ref(null);

const form = useForm({
    hsn_code: '',
    hsn_name: '',
    unit: '',
    cgst_percent: 0,
    sgst_percent: 0,
    igst_percent: 0,
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
    form.hsn_code = item.hsn_code;
    form.hsn_name = item.hsn_name ?? '';
    form.unit = item.unit ?? '';
    form.cgst_percent = item.cgst_percent;
    form.sgst_percent = item.sgst_percent;
    form.igst_percent = item.igst_percent;
    showForm.value = true;
};

const submit = () => {
    if (isEditing.value) {
        form.put(route('admin.hsn-masters.update', currentId.value), {
            onSuccess: () => { showForm.value = false; form.reset(); }
        });
    } else {
        form.post(route('admin.hsn-masters.store'), {
            onSuccess: () => { showForm.value = false; form.reset(); }
        });
    }
};

const deleteHsn = (id) => {
    if (confirm('Are you sure you want to delete this HSN Code?')) {
        form.delete(route('admin.hsn-masters.destroy', id));
    }
};
</script>

<template>
    <Head title="HSN Master" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">GST & HSN Master</h2>
                <button @click="openCreateForm" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">
                    <PlusIcon class="h-4 w-4 stroke-2" /> Add HSN Code
                </button>
            </div>
        </template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8">
            <!-- Inline Form -->
            <div v-if="showForm" class="mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ isEditing ? 'Edit' : 'Create' }} HSN Code</h3>
                </div>
                <div class="p-6">
                    <form @submit.prevent="submit" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">HSN Code <span class="text-red-500">*</span></label>
                            <input type="text" v-model="form.hsn_code" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 font-mono" />
                            <p v-if="form.errors.hsn_code" class="text-red-500 text-xs mt-1">{{ form.errors.hsn_code }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">HSN Name / Description</label>
                            <input type="text" v-model="form.hsn_name" placeholder="Optional legacy description" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Mapping Unit</label>
                            <input type="text" v-model="form.unit" placeholder="e.g. NOS, BOX" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 uppercase" />
                        </div>
                        
                        <!-- Percentages -->
                        <div class="md:col-span-4 border-t border-gray-100 dark:border-gray-700 pt-3 mt-1 grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">CGST (%)</label>
                                <input type="number" step="0.01" v-model="form.cgst_percent" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">SGST (%)</label>
                                <input type="number" step="0.01" v-model="form.sgst_percent" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">IGST (%)</label>
                                <input type="number" step="0.01" v-model="form.igst_percent" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3" />
                            </div>
                        </div>
                        <div class="md:col-span-4 flex justify-end gap-3 pt-2">
                            <button type="button" @click="showForm = false" class="px-4 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                            <button type="submit" :disabled="form.processing" class="px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-500 shadow-sm transition-colors disabled:opacity-50">Save</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3">
                    <div class="relative flex-1 max-w-sm">
                        <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                        <input type="text" v-model="search" placeholder="Search by HSN code or name..." class="w-full pl-9 pr-4 py-2 text-sm rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20" />
                    </div>
                    <span class="text-xs text-gray-400 whitespace-nowrap">{{ hsnCodes.total?.toLocaleString() }} records</span>
                </div>
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">HSN / SAC Code</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">CGST</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">SGST</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">IGST</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                        <tr v-for="hsn in hsnCodes.data" :key="hsn.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <td class="px-6 py-4">
                                <span class="inline-flex rounded-md bg-gray-100 dark:bg-gray-700 px-2.5 py-1 text-sm font-mono font-semibold text-gray-800 dark:text-gray-200">
                                    {{ hsn.hsn_code }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right text-sm font-medium text-gray-600 dark:text-gray-300 font-mono">{{ hsn.cgst_percent }}%</td>
                            <td class="px-6 py-4 text-right text-sm font-medium text-gray-600 dark:text-gray-300 font-mono">{{ hsn.sgst_percent }}%</td>
                            <td class="px-6 py-4 text-right text-sm font-medium text-gray-600 dark:text-gray-300 font-mono">{{ hsn.igst_percent }}%</td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button @click="openEditForm(hsn)" class="p-2 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <PencilSquareIcon class="w-4 h-4" />
                                    </button>
                                    <button @click="deleteHsn(hsn.id)" class="p-2 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!hsnCodes.data.length">
                            <td colspan="5" class="px-6 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
                                No HSN codes defined yet.
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->
            </div>

            <Pagination :data="hsnCodes" />
        </div>
    </AuthenticatedLayout>
</template>
