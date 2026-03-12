<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    user: Object,
    roles: Array,
    franchisees: Array,
});

const isEditing = !!props.user;

const form = useForm({
    name: props.user?.name || '',
    username: props.user?.username || '',
    email: props.user?.email || '',
    phone: props.user?.phone || '',
    password: '',
    password_confirmation: '',
    role: props.user?.roles?.[0]?.name || '',
    franchisee_id: props.user?.franchisee_id || '',
    is_active: props.user ? props.user.is_active : true,
});

const submit = () => {
    if (isEditing) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
};
</script>

<template>
    <Head :title="isEditing ? 'Edit User' : 'Create User'" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-4">
                <Link :href="route('admin.users.index')" class="p-2 rounded-lg text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <h2 class="text-xl font-bold text-gray-800 dark:text-white">
                    {{ isEditing ? 'Edit User Identity' : 'Create New User' }}
                </h2>
            </div>
        </template>

        <div class="py-8 mx-auto max-w-4xl sm:px-6 lg:px-8">
            <form @submit.prevent="submit" class="space-y-6">

                <!-- Section 1: Personal Info -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Personal Information</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">User's name, contact details, and login credentials.</p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Full Name <span class="text-red-500">*</span></label>
                                <input type="text" v-model="form.name" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 placeholder:text-gray-400" placeholder="Enter full legal name" />
                                <p v-if="form.errors.name" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Username <span class="text-red-500">*</span></label>
                                <input type="text" v-model="form.username" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 font-mono placeholder:text-gray-400 placeholder:font-sans" placeholder="unique_username" />
                                <p v-if="form.errors.username" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.username }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email Address <span class="text-red-500">*</span></label>
                                <input type="email" v-model="form.email" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 placeholder:text-gray-400" placeholder="user@company.com" />
                                <p v-if="form.errors.email" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.email }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Phone Number</label>
                                <input type="text" v-model="form.phone" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 placeholder:text-gray-400" placeholder="+91 98765 43210" />
                                <p v-if="form.errors.phone" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.phone }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Role & Access -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Role & Access Control</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Assign system role and optionally link to a franchise branch.</p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">System Role <span class="text-red-500">*</span></label>
                                <select v-model="form.role" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3">
                                    <option value="" disabled>Select a role...</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.name">{{ role.name }}</option>
                                </select>
                                <p v-if="form.errors.role" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.role }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Linked Franchise</label>
                                <select v-model="form.franchisee_id" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3">
                                    <option value="">Head Office (Global)</option>
                                    <option v-for="franch in franchisees" :key="franch.id" :value="franch.id">[{{ franch.franch_id }}] {{ franch.name }}</option>
                                </select>
                                <p v-if="form.errors.franchisee_id" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.franchisee_id }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Password -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Security Credentials</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ isEditing ? 'Leave blank to keep the existing password.' : 'Set a strong password for the new user.' }}</p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Password <span v-if="!isEditing" class="text-red-500">*</span></label>
                                <input type="password" v-model="form.password" :required="!isEditing" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 font-mono" />
                                <p v-if="form.errors.password" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.password }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Confirm Password</label>
                                <input type="password" v-model="form.password_confirmation" :required="!isEditing && form.password" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 font-mono" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Actions -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 px-6 py-4">
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <input type="checkbox" v-model="form.is_active" class="h-4 w-4 rounded border-gray-300 dark:border-gray-500 bg-white dark:bg-gray-700 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-0" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Account is active</span>
                        </label>

                        <div class="flex gap-3 w-full sm:w-auto">
                            <Link :href="route('admin.users.index')" class="flex-1 sm:flex-none px-5 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors text-center">
                                Cancel
                            </Link>
                            <button type="submit" :disabled="form.processing" class="flex-1 sm:flex-none px-6 py-2.5 rounded-lg text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-500 shadow-sm transition-colors disabled:opacity-50 text-center">
                                {{ isEditing ? 'Save Changes' : 'Create User' }}
                            </button>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </AuthenticatedLayout>
</template>
