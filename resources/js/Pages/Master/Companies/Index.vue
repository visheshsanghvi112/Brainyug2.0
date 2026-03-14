<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { PencilIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { computed, ref } from 'vue';

const props = defineProps({
    companies: Object,
});

const companyRows = computed(() => {
    const raw = Array.isArray(props.companies)
        ? props.companies
        : (props.companies?.data || []);

    return raw.filter((co) => co && co.id);
});

const showModal = ref(false);
const editing = ref(null);

const form = useForm({
    name: '',
    address: '',
    gst_no: '',
    dl_no: '',
    preference: '',
    dump_days: '',
    expiry_receive_upto: '',
    minimum_margin: '',
    sales_tax: '',
    purchase_tax: '',
});

function openCreate() {
    editing.value = null;
    form.reset();
    showModal.value = true;
}

function openEdit(co) {
    if (!co?.id) {
        return;
    }

    editing.value = co;
    form.name = co.name;
    form.address = co.address ?? '';
    form.gst_no = co.gst_no ?? '';
    form.dl_no = co.dl_no ?? '';
    form.preference = co.preference ?? '';
    form.dump_days = co.dump_days ?? '';
    form.expiry_receive_upto = co.expiry_receive_upto ?? '';
    form.minimum_margin = co.minimum_margin ?? '';
    form.sales_tax = co.sales_tax ?? '';
    form.purchase_tax = co.purchase_tax ?? '';
    showModal.value = true;
}

function save() {
    if (editing.value) {
        form.put(route('admin.companies.update', { companyMaster: editing.value.id }), {
            onSuccess: () => { showModal.value = false; form.reset(); },
        });
    } else {
        form.post(route('admin.companies.store'), {
            onSuccess: () => { showModal.value = false; form.reset(); },
        });
    }
}

function destroy(co) {
    if (!co?.id) {
        return;
    }

    if (co.products_count > 0) {
        alert(`Cannot delete "${co.name}" — it has ${co.products_count} product(s) assigned.`);
        return;
    }
    if (confirm(`Delete company "${co.name}"?`)) {
        router.delete(route('admin.companies.destroy', { companyMaster: co.id }));
    }
}
</script>

<template>
    <Head title="Company Masters" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Company Masters</h2>
                <button @click="openCreate"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    <PlusIcon class="h-4 w-4" /> Add Company
                </button>
            </div>
        </template>

        <div class="rounded-xl bg-white dark:bg-gray-800 shadow ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Company</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">GST No</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">D.L. No</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Products</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    <tr v-if="!companyRows.length">
                        <td colspan="5" class="text-center py-10 text-gray-400">No companies yet.</td>
                    </tr>
                    <tr v-for="co in companyRows" :key="co.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                        <td class="px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ co.name }}</p>
                            <p v-if="co.address" class="text-xs text-gray-500 truncate max-w-xs">{{ co.address }}</p>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 font-mono">{{ co.gst_no ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 font-mono">{{ co.dl_no ?? '—' }}</td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">{{ co.products_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <button @click="openEdit(co)" class="text-indigo-600 hover:text-indigo-500">
                                    <PencilIcon class="h-4 w-4" />
                                </button>
                                <button @click="destroy(co)" class="text-red-500 hover:text-red-400">
                                    <TrashIcon class="h-4 w-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div><!-- overflow-x-auto -->
        </div>

        <Pagination :data="companies" />

        <!-- Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="showModal = false" />
            <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 w-full max-w-md">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                    {{ editing ? 'Edit Company' : 'New Company' }}
                </h3>
                <form @submit.prevent="save" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Company Name <span class="text-red-500">*</span></label>
                        <input v-model="form.name" type="text" required autofocus
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                        <textarea v-model="form.address" rows="2"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">GST Number</label>
                            <input v-model="form.gst_no" type="text" maxlength="20"
                                class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Drug Licence No</label>
                            <input v-model="form.dl_no" type="text"
                                class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-3 mt-3">
                        <p class="text-xs font-semibold text-gray-500 mb-3 uppercase tracking-wider">Legacy Settings (Optional)</p>
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Preference</label>
                                <input v-model="form.preference" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Dump Days</label>
                                <input v-model="form.dump_days" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 mb-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Expiry Receive Upto</label>
                                <input v-model="form.expiry_receive_upto" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Min Margin / Target</label>
                                <input v-model="form.minimum_margin" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Sales Tax Mismatch Mapping</label>
                                <input v-model="form.sales_tax" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Purchase Tax Mapping</label>
                                <input v-model="form.purchase_tax" type="text" class="w-full rounded-lg border-0 bg-gray-50 dark:bg-gray-700 py-2 px-2 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600" />
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button type="button" @click="showModal = false"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" :disabled="form.processing"
                            class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
