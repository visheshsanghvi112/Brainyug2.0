<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { PlusIcon, PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline';
import { ref, watch } from 'vue';

const props = defineProps({
    users: Object,
    roles: Array,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const role = ref(props.filters?.role || '');
const status = ref(props.filters?.status || '');

function applyFilters() {
    router.get(route('admin.users.index'), {
        search: search.value || undefined,
        role: role.value || undefined,
        status: status.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function impersonateUser(user) {
    const reason = window.prompt(`Support access reason for ${user.name} (required):`);
    if (reason === null) {
        return;
    }

    const trimmed = reason.trim();
    if (trimmed.length < 10) {
        window.alert('Please enter at least 10 characters for audit reason.');
        return;
    }

    router.post(route('admin.users.support-access', user.id), {
        reason: trimmed,
    });
}

let timer;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(applyFilters, 250);
});
</script>

<template>
    <Head title="User Management" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">User Management</h2>
                <Link :href="route('admin.users.create')" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-colors">
                    <PlusIcon class="h-4 w-4 stroke-2" /> Add User
                </Link>
            </div>
        </template>

        <div class="py-6 mx-auto max-w-7xl sm:px-6 lg:px-8">
            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search name, username, email, phone"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                />
                <select
                    v-model="role"
                    @change="applyFilters"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
                    <option value="">All Roles</option>
                    <option v-for="item in roles" :key="item.id" :value="item.name">{{ item.name }}</option>
                </select>
                <select
                    v-model="status"
                    @change="applyFilters"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Contact</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Role</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Franchise</th>
                            <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                        <tr v-for="user in users.data" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-9 w-9 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold text-sm flex-shrink-0">
                                        {{ user.name.charAt(0) }}
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ user.name }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 font-mono">@{{ user.username }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-700 dark:text-gray-300">{{ user.email }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ user.phone || '—' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md bg-indigo-50 dark:bg-indigo-900/30 px-2 py-1 text-xs font-semibold text-indigo-700 dark:text-indigo-300 ring-1 ring-inset ring-indigo-600/10 dark:ring-indigo-400/20">
                                    {{ user.roles[0]?.name || 'Unassigned' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                <span v-if="user.franchisee">{{ user.franchisee.shop_name }} ({{ user.franchisee.shop_code || 'NO-CODE' }})</span>
                                <span v-else>Head Office</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span :class="user.is_active
                                    ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400 ring-green-600/10 dark:ring-green-400/20'
                                    : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400 ring-red-600/10 dark:ring-red-400/20'"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full" :class="user.is_active ? 'bg-green-500' : 'bg-red-500'"></span>
                                    {{ user.is_active ? 'Active' : 'Suspended' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        v-if="$page.props.auth.user.roles?.includes('Super Admin') && $page.props.auth.user.id !== user.id"
                                        type="button"
                                        @click="impersonateUser(user)"
                                        class="rounded-lg border border-amber-300 px-2.5 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-900/20"
                                    >
                                        Support Access
                                    </button>
                                    <Link :href="route('admin.users.edit', user.id)" class="p-2 rounded-lg text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <PencilSquareIcon class="w-4 h-4" />
                                    </Link>
                                    <Link v-if="$page.props.auth.user.id !== user.id" :href="route('admin.users.destroy', user.id)" method="delete" as="button" class="p-2 rounded-lg text-gray-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                        <TrashIcon class="w-4 h-4"/>
                                    </Link>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!users.data?.length">
                            <td colspan="6" class="px-6 py-16 text-center text-sm text-gray-400 dark:text-gray-500">
                                No users found.
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div><!-- overflow-x-auto -->
            </div>

            <Pagination :data="users" />
        </div>
    </AuthenticatedLayout>
</template>
