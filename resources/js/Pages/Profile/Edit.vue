<script setup>
import { computed, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import PreferencesForm from './Partials/PreferencesForm.vue';
import TwoFactorAuthForm from './Partials/TwoFactorAuthForm.vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user ?? {});
const roles = computed(() => user.value.roles ?? []);
const userInitial = computed(() => user.value.name?.charAt(0)?.toUpperCase() || 'U');

const isDark = ref(typeof window !== 'undefined' ? localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches) : false);

const toggleTheme = (theme) => {
    isDark.value = theme === 'dark';
    if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    }
};

</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-2xl font-semibold leading-tight text-gray-900 dark:text-white">
                    Settings
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Manage your account details, security, and sign-in preferences.
                </p>
            </div>
        </template>

        <div class="py-10 bg-gray-50 dark:bg-gray-900 min-h-screen">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <section class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm">
                    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-indigo-900 dark:from-indigo-950 dark:via-indigo-900 dark:to-slate-900 px-6 py-8 sm:px-8">
                        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-4">
                                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/12 text-2xl font-bold text-white ring-1 ring-white/20">
                                    {{ userInitial }}
                                </div>
                                <div>
                                    <h3 class="text-xl font-semibold text-white">
                                        {{ user.name }}
                                    </h3>
                                    <p class="text-sm text-slate-200">
                                        {{ user.email }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="role in roles"
                                    :key="role"
                                    class="rounded-full bg-white/12 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-100 ring-1 ring-white/15"
                                >
                                    {{ role }}
                                </span>
                                <span
                                    v-if="!roles.length"
                                    class="rounded-full bg-white/12 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-100 ring-1 ring-white/15"
                                >
                                    User
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-4 border-t border-slate-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-800/50 px-6 py-4 text-sm text-slate-600 dark:text-slate-400 sm:grid-cols-4 sm:px-8">
                        <div>
                            <p class="font-medium text-slate-900 dark:text-slate-200">Profile</p>
                            <p class="mt-1">Update your personal and login identity information.</p>
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-slate-200">Security</p>
                            <p class="mt-1">Change your password regularly to protect billing and stock data.</p>
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-slate-200">Access</p>
                            <p class="mt-1">Your roles control which modules and reports you can open.</p>
                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-slate-200">Operations</p>
                            <p class="mt-1">Configure printer, receipt layout, and billing automations.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide">
                        <a href="#profile-account" class="rounded-md bg-slate-100 px-3 py-2 text-slate-700 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-100 dark:hover:bg-slate-600">Account</a>
                        <a href="#profile-preferences" class="rounded-md bg-slate-100 px-3 py-2 text-slate-700 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-100 dark:hover:bg-slate-600">Preferences</a>
                        <a href="#profile-password" class="rounded-md bg-slate-100 px-3 py-2 text-slate-700 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-100 dark:hover:bg-slate-600">Password</a>
                        <a href="#profile-2fa" class="rounded-md bg-slate-100 px-3 py-2 text-slate-700 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-100 dark:hover:bg-slate-600">2FA</a>
                        <a href="#profile-danger" class="rounded-md bg-rose-100 px-3 py-2 text-rose-700 hover:bg-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:hover:bg-rose-900/60">Danger Zone</a>
                    </div>
                </section>

                <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="space-y-6">
                        
                        <!-- Theme Toggle Section -->
                        <section class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm sm:p-8">
                            <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-600 dark:text-indigo-400">Appearance</p>
                                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">Interface Theme</h3>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <button
                                    @click="toggleTheme('light')"
                                    :class="[
                                        !isDark ? 'ring-2 ring-indigo-600 border-indigo-600 bg-indigo-50/50 dark:bg-gray-700 flex-col items-center justify-center' : 'border-gray-200 hover:border-gray-300 bg-white dark:bg-gray-900 dark:border-gray-700',
                                        'rounded-xl border p-4 text-center transition-all'
                                    ]"
                                >
                                    <div class="mb-3 h-12 w-full rounded-lg bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-600 shadow-sm flex items-center p-2">
                                        <div class="w-1/3 h-full bg-white dark:bg-gray-700 rounded mr-2 shadow-sm"></div>
                                        <div class="w-2/3 space-y-2">
                                            <div class="w-full h-2 bg-indigo-200 dark:bg-indigo-900/50 rounded"></div>
                                            <div class="w-3/4 h-2 bg-gray-200 dark:bg-gray-700 rounded"></div>
                                        </div>
                                    </div>
                                    <span :class="[!isDark ? 'text-indigo-900 dark:text-indigo-300 font-bold' : 'text-gray-900 dark:text-gray-300 font-medium', 'text-sm']">System Light</span>
                                </button>

                                <button
                                    @click="toggleTheme('dark')"
                                    :class="[
                                        isDark ? 'ring-2 ring-indigo-600 border-indigo-600 bg-indigo-50/50 dark:bg-indigo-900/20 flex-col items-center justify-center' : 'border-gray-200 hover:border-gray-300 bg-white dark:bg-gray-900 dark:border-gray-700',
                                        'rounded-xl border p-4 text-center transition-all'
                                    ]"
                                >
                                    <div class="mb-3 h-12 w-full rounded-lg bg-gray-900 border border-gray-700 shadow-inner flex items-center p-2">
                                        <div class="w-1/3 h-full bg-gray-800 rounded mr-2 shadow-sm border border-gray-700"></div>
                                        <div class="w-2/3 space-y-2">
                                            <div class="w-full h-2 bg-indigo-500 rounded"></div>
                                            <div class="w-3/4 h-2 bg-gray-700 rounded"></div>
                                        </div>
                                    </div>
                                    <span :class="[isDark ? 'text-indigo-900 dark:text-indigo-300 font-bold' : 'text-gray-900 dark:text-gray-300 font-medium', 'text-sm']">Enterprise Dark</span>
                                </button>
                            </div>
                        </section>

                        <section id="profile-account" class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm sm:p-8">
                            <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-600 dark:text-indigo-400">Account</p>
                                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">Profile information</h3>
                            </div>

                            <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Display Name</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ user.name || 'N/A' }}</p>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Email</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ user.email || 'N/A' }}</p>
                                </div>
                                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Roles</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ roles.length ? roles.join(', ') : 'User' }}</p>
                                </div>
                            </div>

                            <UpdateProfileInformationForm
                                :must-verify-email="mustVerifyEmail"
                                :status="status"
                                class="max-w-2xl"
                            />
                        </section>

                        <section id="profile-preferences" class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm sm:p-8">
                            <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-600 dark:text-indigo-400">Settings</p>
                                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">Preferences & Notifications</h3>
                            </div>

                            <PreferencesForm class="max-w-2xl" />
                        </section>

                        <section id="profile-password" class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm sm:p-8">
                            <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-600 dark:text-emerald-400">Security</p>
                                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">Password and sign-in</h3>
                            </div>

                            <UpdatePasswordForm class="max-w-2xl" />
                        </section>

                        <section id="profile-2fa" class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm sm:p-8">
                            <div class="mb-6 border-b border-gray-100 dark:border-gray-700 pb-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-600 dark:text-emerald-400">Security</p>
                                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">Multi-factor Authentication</h3>
                            </div>

                            <TwoFactorAuthForm class="max-w-2xl" />
                        </section>
                    </div>

                    <div class="space-y-6">
                        <section class="rounded-2xl border border-amber-200 dark:border-amber-900 bg-amber-50/70 dark:bg-amber-950/30 p-6 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-700 dark:text-amber-500">Operational Checklist</p>
                            <ul class="mt-4 space-y-3 text-sm text-amber-900 dark:text-amber-200/80">
                                <li>Set printer type and receipt layout before billing in a new branch.</li>
                                <li>Enable desktop alerts for live POS and support activity.</li>
                                <li>Use 2FA for users with order dispatch or stock adjustment access.</li>
                                <li>Turn on low stock daily digest for purchase decision owners.</li>
                            </ul>
                        </section>

                        <section class="rounded-2xl border border-cyan-200 dark:border-cyan-900 bg-cyan-50/60 dark:bg-cyan-950/30 p-6 shadow-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.22em] text-cyan-700 dark:text-cyan-400">Automation Notes</p>
                            <p class="mt-3 text-sm text-cyan-900 dark:text-cyan-100/80">
                                Smart batch suggestion and auto-print settings are profile scoped and tuned for high-volume pharmacy counters.
                            </p>
                        </section>

                        <section id="profile-danger" class="rounded-2xl border border-rose-200 dark:border-rose-900 bg-white dark:bg-gray-800 p-6 shadow-sm">
                            <div class="mb-6 border-b border-rose-100 dark:border-rose-900/50 pb-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-rose-600 dark:text-rose-400">Danger zone</p>
                                <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">Delete account</h3>
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                    This is permanent and should only be used when the account must be removed from the system.
                                </p>
                            </div>

                            <DeleteUserForm class="max-w-full" />
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>