<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { PencilIcon, TrashIcon, PlusIcon } from '@heroicons/vue/24/outline';
import { ref } from 'vue';

const props = defineProps({
    categories: Object,
    filters: Object,
});

const showModal = ref(false);
const editing = ref(null); // null = create, object = edit

const form = useForm({ name: '' });

function openCreate() {
    editing.value = null;
    form.reset();
    showModal.value = true;
}

function openEdit(cat) {
    editing.value = cat;
    form.name = cat.name;
    showModal.value = true;
}

function save() {
    if (editing.value) {
        form.put(route('admin.categories.update', editing.value.id), {
            onSuccess: () => { showModal.value = false; form.reset(); },
        });
    } else {
        form.post(route('admin.categories.store'), {
            onSuccess: () => { showModal.value = false; form.reset(); },
        });
    }
}

function destroy(cat) {
    if (cat.products_count > 0) {
        alert(`Cannot delete "${cat.name}" — it has ${cat.products_count} product(s) assigned.`);
        return;
    }
    if (confirm(`Delete category "${cat.name}"?`)) {
        router.delete(route('admin.categories.destroy', cat.id));
    }
}
</script>

<template>
    <Head title="Item Categories" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Item Categories</h2>
                <button @click="openCreate"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    <PlusIcon class="h-4 w-4" /> Add Category
                </button>
            </div>
        </template>

        <div class="max-w-2xl">
            <div class="rounded-xl bg-white dark:bg-gray-800 shadow ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Category Name</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wider">Products</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <tr v-if="!categories || !categories.data.length">
                            <td colspan="3" class="text-center py-10 text-gray-400">No categories yet.</td>
                        </tr>
                        <tr v-for="cat in categories.data" :key="cat.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ cat.name }}</td>
                            <td class="px-4 py-3 text-center text-sm text-gray-500">{{ cat.products_count }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex justify-end gap-2">
                                    <button @click="openEdit(cat)" class="text-indigo-600 hover:text-indigo-500">
                                        <PencilIcon class="h-4 w-4" />
                                    </button>
                                    <button @click="destroy(cat)" class="text-red-500 hover:text-red-400">
                                        <TrashIcon class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->
            </div>

            <Pagination :data="categories" />
        </div>

        <!-- Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="showModal = false" />
            <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl p-6 w-full max-w-sm">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">
                    {{ editing ? 'Edit Category' : 'New Category' }}
                </h3>
                <form @submit.prevent="save" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name <span class="text-red-500">*</span></label>
                        <input v-model="form.name" type="text" required autofocus
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                            placeholder="e.g. Antibiotics" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
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
