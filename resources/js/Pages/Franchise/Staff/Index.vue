<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    staff: Object,
    filters: Object,
    canManage: Boolean,
    isSuperAdmin: Boolean,
    franchisees: Array,
    selectedFranchiseeId: Number,
});

const search = ref(props.filters?.search || '');
const franchiseeId = ref(props.selectedFranchiseeId || '');
const showForm = ref(false);
const editing = ref(null);

const form = ref({
    name: '',
    email: '',
    username: '',
    phone: '',
    designation: '',
    password: '',
    is_active: true,
});

let timer;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('franchise.staff.index'), {
            search: search.value || undefined,
            franchisee_id: franchiseeId.value || undefined,
        }, { preserveState: true, preserveScroll: true });
    }, 250);
});

function applyFranchiseeFilter() {
    router.get(route('franchise.staff.index'), {
        search: search.value || undefined,
        franchisee_id: franchiseeId.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}

function openCreate() {
    editing.value = null;
    form.value = {
        name: '',
        email: '',
        username: '',
        phone: '',
        designation: '',
        password: '',
        is_active: true,
        franchisee_id: franchiseeId.value || '',
    };
    showForm.value = true;
}

function openEdit(row) {
    editing.value = row;
    form.value = {
        name: row.user?.name || '',
        email: row.user?.email || '',
        username: row.user?.username || '',
        phone: row.user?.phone || '',
        designation: row.designation || '',
        password: '',
        is_active: !!row.is_active,
        franchisee_id: row.franchisee_id,
    };
    showForm.value = true;
}

function submit() {
    if (editing.value) {
        router.patch(route('franchise.staff.update', editing.value.id), form.value, {
            onSuccess: () => { showForm.value = false; },
        });
        return;
    }

    router.post(route('franchise.staff.store'), form.value, {
        onSuccess: () => { showForm.value = false; },
    });
}

function removeRow(row) {
    if (!confirm(`Delete staff user "${row.user?.name}"?`)) return;
    router.delete(route('franchise.staff.destroy', row.id));
}
</script>

<template>
    <Head title="Franchise Staff" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Franchise Staff Users</h2>
                <button v-if="canManage" type="button" @click="openCreate" :disabled="isSuperAdmin && !franchiseeId"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                    Add Staff User
                </button>
            </div>
        </template>

        <div class="space-y-4">
            <select v-if="isSuperAdmin" v-model="franchiseeId" @change="applyFranchiseeFilter"
                class="w-full max-w-md rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <option value="">Select franchise</option>
                <option v-for="f in franchisees" :key="f.id" :value="f.id">
                    {{ f.shop_name }} ({{ f.shop_code || 'NO-CODE' }})
                </option>
            </select>

            <input v-model="search" type="text" placeholder="Search by name, email, username, phone"
                class="w-full max-w-md rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-3 py-2 text-left">Name</th>
                                <th class="px-3 py-2 text-left">Username</th>
                                <th class="px-3 py-2 text-left">Contact</th>
                                <th class="px-3 py-2 text-left">Designation</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            <tr v-for="row in staff.data" :key="row.id">
                                <td class="px-3 py-2 text-gray-900 dark:text-gray-100">
                                    <div class="font-medium">{{ row.user?.name }}</div>
                                    <div class="text-xs text-gray-500">{{ row.user?.email }}</div>
                                </td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.user?.username }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.user?.phone || '-' }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ row.designation || '-' }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded-full px-2 py-1 text-xs" :class="row.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'">
                                        {{ row.is_active ? 'Active' : 'Disabled' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" class="mr-2 rounded bg-gray-200 px-2 py-1 text-xs hover:bg-gray-300" @click="openEdit(row)">Edit</button>
                                    <button type="button" class="rounded bg-rose-600 px-2 py-1 text-xs text-white hover:bg-rose-500" @click="removeRow(row)">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="!staff.data?.length">
                                <td colspan="6" class="px-3 py-8 text-center text-gray-500">No staff users found.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination :links="staff.links" />
        </div>

        <div v-if="showForm" class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl dark:bg-gray-800">
                <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ editing ? 'Edit Staff User' : 'Add Staff User' }}</h3>
                <form @submit.prevent="submit" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <input v-model="form.name" required type="text" placeholder="Full name" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    <input v-model="form.username" required type="text" placeholder="Username" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    <input v-model="form.email" required type="email" placeholder="Email" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    <input v-model="form.phone" type="text" placeholder="Phone" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    <input v-model="form.designation" type="text" placeholder="Designation" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />
                    <input v-model="form.password" :required="!editing" type="password" :placeholder="editing ? 'New password (optional)' : 'Password'" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" />

                    <label class="col-span-full inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300" />
                        Active user
                    </label>

                    <div class="col-span-full flex justify-end gap-2 pt-1">
                        <button type="button" @click="showForm = false" class="rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">Cancel</button>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ editing ? 'Save' : 'Create' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
