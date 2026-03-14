<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    user: Object,
    roles: Array,
    franchisees: Array,
    dashboardViewOptions: Object,
    canManageDashboardView: Boolean,
    selectedDashboardView: String,
    dashboardSectionOptions: Object,
    selectedDashboardSections: Array,
    dashboardLandingRouteOptions: Object,
    selectedDashboardLandingRoute: String,
    modulePermissionOptions: Array,
    selectedModulePermissions: Object,
    moduleOverrideEnabled: Boolean,
    roleModuleTemplates: Object,
});

const isEditing = !!props.user;

const modulePermissionOptions = computed(() => props.modulePermissionOptions || []);
const moduleActions = ['view', 'create', 'update', 'delete'];
const moduleSearch = ref('');

const buildModulePermissionMatrix = () => {
    const matrix = {};

    modulePermissionOptions.value.forEach((module) => {
        matrix[module.key] = {
            view: false,
            create: false,
            update: false,
            delete: false,
        };

        moduleActions.forEach((action) => {
            const selected = props.selectedModulePermissions?.[module.key]?.[action];
            matrix[module.key][action] = Boolean(selected);
        });
    });

    return matrix;
};

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
    dashboard_view: props.selectedDashboardView || 'auto',
    dashboard_visible_sections: props.selectedDashboardSections || [],
    dashboard_landing_route: props.selectedDashboardLandingRoute || '',
    module_override_enabled: Boolean(props.moduleOverrideEnabled),
    module_permissions: buildModulePermissionMatrix(),
});

const isFranchiseRole = computed(() => form.role === 'Franchisee');

watch(() => form.role, (role) => {
    if (role !== 'Franchisee') {
        form.franchisee_id = '';
    }
});

const submit = () => {
    if (isEditing) {
        form.put(route('admin.users.update', props.user.id));
    } else {
        form.post(route('admin.users.store'));
    }
};

const matrixRows = computed(() => {
    const term = moduleSearch.value.trim().toLowerCase();

    return modulePermissionOptions.value.filter((module) => {
        if (!term) {
            return true;
        }

        return [module.label, module.key, module.category, module.description]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(term);
    });
});

const groupedMatrixRows = computed(() => {
    const map = new Map();

    matrixRows.value.forEach((module) => {
        const category = module.category || 'Other';
        if (!map.has(category)) {
            map.set(category, []);
        }

        map.get(category).push(module);
    });

    return Array.from(map.entries()).map(([category, modules]) => ({
        category,
        modules,
    }));
});

const matrixSummary = computed(() => {
    let moduleCount = 0;
    let actionCount = 0;

    modulePermissionOptions.value.forEach((module) => {
        const row = form.module_permissions[module.key] || {};
        const hasAny = moduleActions.some((action) => Boolean(row[action]));

        if (hasAny) {
            moduleCount += 1;
        }

        actionCount += moduleActions.reduce((carry, action) => carry + (row[action] ? 1 : 0), 0);
    });

    return { moduleCount, actionCount };
});

const enforceModuleActionDependencies = (moduleKey) => {
    const row = form.module_permissions[moduleKey];
    if (!row) {
        return;
    }

    if (row.create || row.update || row.delete) {
        row.view = true;
        return;
    }

    if (!row.view) {
        row.create = false;
        row.update = false;
        row.delete = false;
    }
};

const setModuleAction = (moduleKey, action, checked) => {
    const row = form.module_permissions[moduleKey];
    if (!row || !form.module_override_enabled) {
        return;
    }

    row[action] = Boolean(checked);
    enforceModuleActionDependencies(moduleKey);
};

const setRowPreset = (moduleKey, preset) => {
    if (!form.module_override_enabled) {
        return;
    }

    const row = form.module_permissions[moduleKey];
    if (!row) {
        return;
    }

    if (preset === 'full') {
        row.view = true;
        row.create = true;
        row.update = true;
        row.delete = true;
        return;
    }

    if (preset === 'view') {
        row.view = true;
        row.create = false;
        row.update = false;
        row.delete = false;
        return;
    }

    row.view = false;
    row.create = false;
    row.update = false;
    row.delete = false;
};

const applyCategoryPreset = (category, preset) => {
    if (!form.module_override_enabled) {
        return;
    }

    groupedMatrixRows.value
        .find((group) => group.category === category)
        ?.modules.forEach((module) => setRowPreset(module.key, preset));
};

const applyRoleTemplate = () => {
    if (!form.module_override_enabled || !form.role) {
        return;
    }

    const template = props.roleModuleTemplates?.[form.role];
    if (!template) {
        return;
    }

    Object.keys(form.module_permissions || {}).forEach((moduleKey) => {
        moduleActions.forEach((action) => {
            form.module_permissions[moduleKey][action] = Boolean(template?.[moduleKey]?.[action]);
        });

        enforceModuleActionDependencies(moduleKey);
    });
};

const applyModulePreset = (preset) => {
    Object.keys(form.module_permissions || {}).forEach((moduleKey) => {
        if (preset === 'all') {
            form.module_permissions[moduleKey].view = true;
            form.module_permissions[moduleKey].create = true;
            form.module_permissions[moduleKey].update = true;
            form.module_permissions[moduleKey].delete = true;
            enforceModuleActionDependencies(moduleKey);
            return;
        }

        if (preset === 'view') {
            form.module_permissions[moduleKey].view = true;
            form.module_permissions[moduleKey].create = false;
            form.module_permissions[moduleKey].update = false;
            form.module_permissions[moduleKey].delete = false;
            enforceModuleActionDependencies(moduleKey);
            return;
        }

        form.module_permissions[moduleKey].view = false;
        form.module_permissions[moduleKey].create = false;
        form.module_permissions[moduleKey].update = false;
        form.module_permissions[moduleKey].delete = false;
        enforceModuleActionDependencies(moduleKey);
    });
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
                                <select
                                    v-model="form.franchisee_id"
                                    :disabled="!isFranchiseRole"
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <option value="">Head Office (Global)</option>
                                    <option v-for="franch in franchisees" :key="franch.id" :value="franch.id">[{{ franch.franch_id }}] {{ franch.name }}</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Franchise link is required only for `Franchisee` role.
                                </p>
                                <p v-if="form.errors.franchisee_id" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.franchisee_id }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="canManageDashboardView" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Dashboard View Control</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Super Admin can force which dashboard this user will see, or leave auto role-based routing.</p>
                    </div>
                    <div class="p-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Assigned Dashboard View</label>
                        <select v-model="form.dashboard_view" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3">
                            <option v-for="(label, value) in dashboardViewOptions" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Set `Auto` to let the system decide by role. Any other selection is a user-specific override.</p>
                        <p v-if="form.errors.dashboard_view" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.dashboard_view }}</p>

                        <div class="mt-5">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Visible Dashboard Sections</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <label v-for="(label, key) in dashboardSectionOptions" :key="key" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                    <input
                                        v-model="form.dashboard_visible_sections"
                                        :value="key"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    />
                                    <span>{{ label }}</span>
                                </label>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Unchecked sections are hidden from that user's dashboard.</p>
                            <p v-if="form.errors.dashboard_visible_sections" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.dashboard_visible_sections }}</p>
                        </div>

                        <div class="mt-5">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Post-Login Landing Route</label>
                            <select v-model="form.dashboard_landing_route" class="w-full rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3">
                                <option value="">Role Default</option>
                                <option v-for="(label, routeName) in dashboardLandingRouteOptions" :key="routeName" :value="routeName">{{ label }}</option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Overrides where this user lands after login.</p>
                            <p v-if="form.errors.dashboard_landing_route" class="text-red-500 text-xs mt-1 font-medium">{{ form.errors.dashboard_landing_route }}</p>
                        </div>
                    </div>
                </div>

                <div v-if="canManageDashboardView" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Module Permissions Matrix</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Legacy-style module rights per user. Enable this only when a user needs custom access different from role defaults.</p>
                    </div>
                    <div class="p-6 space-y-5">
                        <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                            <input
                                v-model="form.module_override_enabled"
                                type="checkbox"
                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <span>Enable per-user module override</span>
                        </label>

                        <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-xs text-sky-800 dark:border-sky-700/40 dark:bg-sky-900/20 dark:text-sky-200">
                            Active modules: {{ matrixSummary.moduleCount }} / {{ modulePermissionOptions.length }}. Enabled actions: {{ matrixSummary.actionCount }}.
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <input
                                v-model="moduleSearch"
                                type="text"
                                placeholder="Search module by name/category"
                                class="rounded-lg border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm py-2.5 px-3"
                            />
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                :disabled="!form.module_override_enabled || !form.role"
                                @click="applyRoleTemplate"
                            >
                                Apply {{ form.role || 'Role' }} Baseline
                            </button>
                            <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center justify-start md:justify-end">
                                Non-view permissions auto-enable View.
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                :disabled="!form.module_override_enabled"
                                @click="applyModulePreset('all')"
                            >
                                Grant All
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                :disabled="!form.module_override_enabled"
                                @click="applyModulePreset('view')"
                            >
                                View Only
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                :disabled="!form.module_override_enabled"
                                @click="applyModulePreset('none')"
                            >
                                Revoke All
                            </button>
                        </div>

                        <div v-if="modulePermissionOptions.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                            No module permission definitions found.
                        </div>

                        <div v-if="!matrixRows.length" class="text-sm text-gray-500 dark:text-gray-400 rounded-lg border border-dashed border-gray-300 dark:border-gray-600 px-4 py-8 text-center">
                            No modules match your search.
                        </div>

                        <div
                            v-for="group in groupedMatrixRows"
                            :key="group.category"
                            class="space-y-3"
                        >
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ group.category }}</h4>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                        :disabled="!form.module_override_enabled"
                                        @click="applyCategoryPreset(group.category, 'full')"
                                    >
                                        Category Full
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                        :disabled="!form.module_override_enabled"
                                        @click="applyCategoryPreset(group.category, 'view')"
                                    >
                                        Category View
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                        :disabled="!form.module_override_enabled"
                                        @click="applyCategoryPreset(group.category, 'none')"
                                    >
                                        Category Clear
                                    </button>
                                </div>
                            </div>

                            <div
                                v-for="module in group.modules"
                                :key="module.key"
                                class="rounded-xl border border-gray-200 dark:border-gray-600 p-4"
                            >
                                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ module.label }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ module.key }} - {{ module.description }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                            :disabled="!form.module_override_enabled"
                                            @click="setRowPreset(module.key, 'full')"
                                        >
                                            Full
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                            :disabled="!form.module_override_enabled"
                                            @click="setRowPreset(module.key, 'view')"
                                        >
                                            View
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-gray-300 dark:border-gray-600 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:text-gray-200 disabled:opacity-50"
                                            :disabled="!form.module_override_enabled"
                                            @click="setRowPreset(module.key, 'none')"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-3 grid grid-cols-2 md:grid-cols-4 gap-2" :class="!form.module_override_enabled ? 'opacity-50' : ''">
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input
                                            :checked="form.module_permissions[module.key].view"
                                            :disabled="!form.module_override_enabled"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @change="setModuleAction(module.key, 'view', $event.target.checked)"
                                        />
                                        <span>View</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input
                                            :checked="form.module_permissions[module.key].create"
                                            :disabled="!form.module_override_enabled"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @change="setModuleAction(module.key, 'create', $event.target.checked)"
                                        />
                                        <span>Create</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input
                                            :checked="form.module_permissions[module.key].update"
                                            :disabled="!form.module_override_enabled"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @change="setModuleAction(module.key, 'update', $event.target.checked)"
                                        />
                                        <span>Update</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 dark:border-gray-600 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input
                                            :checked="form.module_permissions[module.key].delete"
                                            :disabled="!form.module_override_enabled"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            @change="setModuleAction(module.key, 'delete', $event.target.checked)"
                                        />
                                        <span>Delete</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <p v-if="form.errors.module_permissions" class="text-red-500 text-xs font-medium">{{ form.errors.module_permissions }}</p>
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
