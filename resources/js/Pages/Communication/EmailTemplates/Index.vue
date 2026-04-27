<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    emailTemplates: Object,
    filters: Object,
    moduleOptions: Array,
});

const search = ref(props.filters?.search ?? '');
const moduleFilter = ref(props.filters?.module ?? '');
const statusFilter = ref(props.filters?.status ?? '');

const deletingId = ref(null);
const deleteForm = useForm({});

function applyFilters() {
    router.get(route('admin.email-templates.index'), {
        search: search.value || undefined,
        module: moduleFilter.value || undefined,
        status: statusFilter.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function resetFilters() {
    search.value = '';
    moduleFilter.value = '';
    statusFilter.value = '';
    applyFilters();
}

function destroyTemplate(id) {
    deletingId.value = id;
    deleteForm.delete(route('admin.email-templates.destroy', id), {
        preserveScroll: true,
        onFinish: () => {
            deletingId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Email Templates" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Email Templates</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Operational communication templates used across ERP flows.</p>
                </div>
                <Link
                    :href="route('admin.email-templates.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                >
                    New Template
                </Link>
            </div>
        </template>

        <div class="rounded-2xl bg-white p-4 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 mb-6">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search name, subject, module"
                    class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                    @keyup.enter="applyFilters"
                />
                <select
                    v-model="moduleFilter"
                    class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                >
                    <option value="">All modules</option>
                    <option v-for="option in moduleOptions" :key="option" :value="option">{{ option }}</option>
                </select>
                <select
                    v-model="statusFilter"
                    class="rounded-xl border-0 bg-gray-50 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <div class="flex gap-2">
                    <button
                        class="w-full rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                        @click="applyFilters"
                    >
                        Apply
                    </button>
                    <button
                        class="w-full rounded-xl bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                        @click="resetFilters"
                    >
                        Reset
                    </button>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-2 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50 dark:bg-gray-900/60">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Template</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Module</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Updated</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="template in emailTemplates.data" :key="template.id" class="border-t border-gray-100 dark:border-gray-700/70">
                        <td class="px-4 py-3 align-top">
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ template.name }}</div>
                            <div class="text-sm text-gray-600 dark:text-gray-300">{{ template.subject }}</div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Variables: {{ (template.variables || []).join(', ') || 'None' }}</div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{{ template.module || 'General' }}</td>
                        <td class="px-4 py-3">
                            <span
                                :class="template.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700'"
                                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            >
                                {{ template.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ new Date(template.updated_at).toLocaleString('en-IN') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <Link
                                    :href="route('admin.email-templates.edit', template.id)"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-100 dark:ring-gray-700"
                                >
                                    <PencilSquareIcon class="h-4 w-4" />
                                    Edit
                                </Link>
                                <button
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-100 disabled:opacity-50"
                                    :disabled="deleteForm.processing && deletingId === template.id"
                                    @click="destroyTemplate(template.id)"
                                >
                                    <TrashIcon class="h-4 w-4" />
                                    Archive
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!emailTemplates.data.length">
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400">No email templates found.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :data="emailTemplates" class="mt-6" />
    </AuthenticatedLayout>
</template>
