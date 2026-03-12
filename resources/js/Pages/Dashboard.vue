<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArchiveBoxIcon,
    BuildingStorefrontIcon,
    CheckCircleIcon,
    ClockIcon,
    CurrencyRupeeIcon,
    DocumentTextIcon,
    ShoppingBagIcon,
    TruckIcon,
    UsersIcon,
    CreditCardIcon,
    WalletIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({
    dashboard: {
        type: Object,
        required: true,
    },
});

const iconMap = {
    ArchiveBoxIcon,
    BuildingStorefrontIcon,
    CheckCircleIcon,
    ClockIcon,
    CurrencyRupeeIcon,
    DocumentTextIcon,
    ShoppingBagIcon,
    TruckIcon,
    UsersIcon,
    CreditCardIcon,
    WalletIcon,
};

const toneClasses = {
    amber: 'bg-amber-500',
    emerald: 'bg-emerald-500',
    indigo: 'bg-indigo-500',
    sky: 'bg-sky-500',
    slate: 'bg-slate-600',
    violet: 'bg-violet-500',
    rose: 'bg-rose-500',
};

const actionToneClasses = {
    amber: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100',
    emerald: 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-100',
    indigo: 'border-indigo-200 bg-indigo-50 text-indigo-900 dark:border-indigo-900/60 dark:bg-indigo-950/30 dark:text-indigo-100',
    sky: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/30 dark:text-sky-100',
    slate: 'border-slate-200 bg-slate-50 text-slate-900 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-100',
    violet: 'border-violet-200 bg-violet-50 text-violet-900 dark:border-violet-900/60 dark:bg-violet-950/30 dark:text-violet-100',
    rose: 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-100',
};

const workflowStatusClasses = {
    live: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
    active: 'bg-sky-100 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300',
    'build-next': 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
};

const stats = computed(() => props.dashboard.stats.map((item) => ({
    ...item,
    iconComponent: iconMap[item.icon] ?? ArchiveBoxIcon,
    toneClass: toneClasses[item.tone] ?? toneClasses.indigo,
})));

const actions = computed(() => (props.dashboard.actions ?? []).map((item) => ({
    ...item,
    toneClass: actionToneClasses[item.tone] ?? actionToneClasses.indigo,
})));

const workflows = computed(() => (props.dashboard.workflows ?? []).map((item) => ({
    ...item,
    statusClass: workflowStatusClasses[item.status] ?? workflowStatusClasses.active,
    statusLabel: item.status === 'build-next' ? 'Build Next' : item.status.charAt(0).toUpperCase() + item.status.slice(1),
})));
</script>

<template>
    <Head :title="dashboard.title" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-2xl font-bold leading-tight text-gray-900 dark:text-white tracking-tight">
                {{ dashboard.title }}
            </h2>
        </template>

        <div>
            <div class="rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-900 via-slate-800 to-indigo-900 px-6 py-6 text-white shadow-lg">
                <p class="text-sm font-medium uppercase tracking-[0.2em] text-slate-300">
                    {{ dashboard.role }}
                </p>
                <p class="mt-3 max-w-2xl text-sm text-slate-200 sm:text-base">
                    {{ dashboard.description }}
                </p>
            </div>

            <dl class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                <div v-for="item in stats" :key="item.name" class="relative overflow-hidden rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-5 pb-14 pt-5 shadow-sm transition-shadow duration-150 hover:shadow-md sm:px-6 sm:pt-6">
                    <dt>
                        <div :class="['absolute rounded-xl p-3 shadow-inner', item.toneClass]">
                            <component :is="item.iconComponent" class="h-6 w-6 text-white" aria-hidden="true" />
                        </div>
                        <p class="ml-16 truncate text-sm font-medium text-slate-500 dark:text-slate-400">{{ item.name }}</p>
                    </dt>
                    <dd class="ml-16 pb-2 pr-1">
                        <p class="text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ item.value }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">{{ item.context }}</p>
                        <div class="absolute inset-x-0 bottom-0 border-t border-slate-100 dark:border-gray-700 bg-slate-50 dark:bg-gray-800/50 px-5 py-4 sm:px-6">
                            <div class="text-sm">
                                <Link v-if="item.href" :href="item.href" class="font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500">
                                    Open {{ item.name.toLowerCase() }}
                                </Link>
                                <span v-else class="font-medium text-slate-500 dark:text-slate-400">No direct drill-down available</span>
                            </div>
                        </div>
                    </dd>
                </div>
            </dl>

            <div class="mt-8 grid grid-cols-1 gap-6 xl:grid-cols-[1.15fr_0.85fr]">
                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Priority Actions</h3>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">The highest-value screens for this role surface.</p>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Link
                            v-for="action in actions"
                            :key="action.title"
                            :href="action.href"
                            :class="['rounded-2xl border p-4 transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md', action.toneClass]"
                        >
                            <p class="text-sm font-black uppercase tracking-[0.18em] opacity-70">Action</p>
                            <p class="mt-2 text-lg font-bold">{{ action.title }}</p>
                            <p class="mt-2 text-sm leading-6 opacity-90">{{ action.description }}</p>
                        </Link>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Operational Backbone</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">The workflows this role should keep healthy every day.</p>
                    </div>

                    <div class="mt-5 space-y-4">
                        <div v-for="workflow in workflows" :key="workflow.name" class="rounded-2xl border border-slate-100 dark:border-gray-700 bg-slate-50/80 dark:bg-gray-900/30 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">{{ workflow.name }}</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600 dark:text-slate-400">{{ workflow.description }}</p>
                                </div>
                                <span :class="['inline-flex rounded-full px-2.5 py-1 text-xs font-bold uppercase tracking-wide', workflow.statusClass]">
                                    {{ workflow.statusLabel }}
                                </span>
                            </div>

                            <div v-if="workflow.href" class="mt-3">
                                <Link :href="workflow.href" class="text-sm font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500">
                                    Open workflow
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
