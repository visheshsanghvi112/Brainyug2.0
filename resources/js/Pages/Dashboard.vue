<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
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

const alertSeverityClasses = {
    high: 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-100',
    medium: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100',
    low: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/30 dark:text-sky-100',
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

const trend = computed(() => props.dashboard.trend ?? null);

const trendBars = computed(() => {
    if (!trend.value || !Array.isArray(trend.value.series) || trend.value.series.length === 0) {
        return [];
    }

    const max = Math.max(...trend.value.series, 1);

    return trend.value.series.map((point, index) => ({
        label: trend.value.labels?.[index] ?? `D${index + 1}`,
        value: Number(point || 0),
        height: `${Math.max(8, (Number(point || 0) / max) * 100)}%`,
    }));
});

const pipeline = computed(() => props.dashboard.pipeline ?? null);

const pipelineRows = computed(() => {
    if (!pipeline.value) {
        return [];
    }

    const rows = [
        { key: 'pending', label: 'Pending', tone: 'bg-amber-500' },
        { key: 'accepted', label: 'Accepted', tone: 'bg-sky-500' },
        { key: 'dispatched', label: 'Dispatched', tone: 'bg-indigo-500' },
        { key: 'delivered', label: 'Delivered', tone: 'bg-emerald-500' },
        { key: 'rejected', label: 'Rejected', tone: 'bg-rose-500' },
        { key: 'cancelled', label: 'Cancelled', tone: 'bg-slate-500' },
    ];

    const total = rows.reduce((sum, row) => sum + Number(pipeline.value[row.key] || 0), 0);

    return rows.map((row) => {
        const count = Number(pipeline.value[row.key] || 0);
        return {
            ...row,
            count,
            percent: total > 0 ? Math.round((count / total) * 100) : 0,
        };
    });
});

const alerts = computed(() => (props.dashboard.alerts ?? []).map((item) => ({
    ...item,
    severityClass: alertSeverityClasses[item.severity] ?? alertSeverityClasses.low,
})));

const leaderboard = computed(() => props.dashboard.leaderboard ?? []);
const focus = computed(() => props.dashboard.focus ?? []);

const now = ref(new Date());
const activeMonth = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
let clockTimer = null;

const weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const timeLabel = computed(() => now.value.toLocaleTimeString('en-IN', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
}));

const dateLabel = computed(() => now.value.toLocaleDateString('en-IN', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
}));

const monthLabel = computed(() => activeMonth.value.toLocaleDateString('en-IN', {
    month: 'long',
    year: 'numeric',
}));

const calendarCells = computed(() => {
    const year = activeMonth.value.getFullYear();
    const month = activeMonth.value.getMonth();
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const cells = [];

    for (let i = 0; i < firstDay; i++) {
        cells.push({
            key: `empty-${i}`,
            empty: true,
        });
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const cellDate = new Date(year, month, day);
        const isToday =
            day === now.value.getDate() &&
            month === now.value.getMonth() &&
            year === now.value.getFullYear();

        cells.push({
            key: `${year}-${month + 1}-${day}`,
            empty: false,
            day,
            isToday,
            isWeekend: [0, 6].includes(cellDate.getDay()),
        });
    }

    while (cells.length % 7 !== 0) {
        cells.push({
            key: `tail-${cells.length}`,
            empty: true,
        });
    }

    return cells;
});

const gotoPrevMonth = () => {
    activeMonth.value = new Date(activeMonth.value.getFullYear(), activeMonth.value.getMonth() - 1, 1);
};

const gotoNextMonth = () => {
    activeMonth.value = new Date(activeMonth.value.getFullYear(), activeMonth.value.getMonth() + 1, 1);
};

const gotoCurrentMonth = () => {
    activeMonth.value = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
};

onMounted(() => {
    clockTimer = setInterval(() => {
        now.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    if (clockTimer) {
        clearInterval(clockTimer);
    }
});

const formatMoney = (value) => Number(value || 0).toLocaleString('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});
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

            <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[0.72fr_1.28fr]">
                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Command Clock</h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Live server-side operations context for your workday.</p>

                    <div class="mt-5 rounded-2xl border border-slate-100 dark:border-gray-700 bg-gradient-to-br from-slate-900 to-indigo-900 p-5 text-white shadow-inner">
                        <p class="text-xs uppercase tracking-[0.22em] text-slate-300">Current Time</p>
                        <p class="mt-2 text-3xl font-black tracking-wide">{{ timeLabel }}</p>
                        <p class="mt-2 text-sm text-slate-200">{{ dateLabel }}</p>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Ops Calendar</h3>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Month planning surface for reviews, dispatches, and closes.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="gotoPrevMonth" class="rounded-lg border border-slate-200 dark:border-gray-600 px-3 py-1.5 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-gray-700">Prev</button>
                            <button type="button" @click="gotoCurrentMonth" class="rounded-lg border border-indigo-200 dark:border-indigo-700 bg-indigo-50 dark:bg-indigo-950/40 px-3 py-1.5 text-xs font-bold text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-950/70">Today</button>
                            <button type="button" @click="gotoNextMonth" class="rounded-lg border border-slate-200 dark:border-gray-600 px-3 py-1.5 text-xs font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-gray-700">Next</button>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-100 dark:border-gray-700 overflow-hidden">
                        <div class="flex items-center justify-between border-b border-slate-100 dark:border-gray-700 bg-slate-50 dark:bg-gray-900/40 px-4 py-3">
                            <p class="text-sm font-black tracking-wide text-slate-900 dark:text-white">{{ monthLabel }}</p>
                        </div>

                        <div class="grid grid-cols-7 border-b border-slate-100 dark:border-gray-700 bg-slate-50 dark:bg-gray-900/20">
                            <div v-for="dayName in weekDays" :key="dayName" class="py-2 text-center text-[11px] font-black uppercase tracking-[0.18em] text-slate-500">{{ dayName }}</div>
                        </div>

                        <div class="grid grid-cols-7">
                            <div
                                v-for="cell in calendarCells"
                                :key="cell.key"
                                class="h-12 border-b border-r border-slate-100 dark:border-gray-700 p-2 text-sm"
                                :class="[
                                    cell.empty ? 'bg-slate-50/50 dark:bg-gray-900/20' : 'bg-white dark:bg-gray-800',
                                    cell.isWeekend ? 'text-rose-500' : 'text-slate-700 dark:text-slate-200',
                                    cell.isToday ? 'bg-indigo-50 dark:bg-indigo-950/30 text-indigo-700 dark:text-indigo-300 font-black' : ''
                                ]"
                            >
                                <span v-if="!cell.empty">{{ cell.day }}</span>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[1.2fr_0.8fr]" v-if="trend || pipelineRows.length">
                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm" v-if="trend && trendBars.length">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white">7-Day Revenue Pulse</h3>
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Real sales movement over the last week.</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Weekly Total</p>
                            <p class="text-2xl font-black text-indigo-600 dark:text-indigo-400">INR {{ formatMoney(trend.total) }}</p>
                            <p class="text-xs text-slate-500">Avg/day INR {{ formatMoney(trend.avg) }}</p>
                        </div>
                    </div>

                    <div class="mt-5 h-44 rounded-xl border border-slate-100 dark:border-gray-700 bg-slate-50 dark:bg-gray-900/40 p-4">
                        <div class="flex h-full items-end gap-2">
                            <div v-for="bar in trendBars" :key="bar.label" class="flex flex-1 flex-col items-center justify-end gap-2">
                                <div class="w-full rounded-md bg-gradient-to-t from-indigo-600 to-sky-400" :style="{ height: bar.height }"></div>
                                <p class="text-[10px] font-semibold text-slate-500">{{ bar.label }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm" v-if="pipelineRows.length">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Order Pipeline Matrix</h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Current distribution flow by stage.</p>

                    <div class="mt-5 space-y-3">
                        <div v-for="row in pipelineRows" :key="row.key" class="rounded-xl border border-slate-100 dark:border-gray-700 bg-slate-50 dark:bg-gray-900/40 p-3">
                            <div class="flex items-center justify-between text-sm">
                                <p class="font-bold text-slate-800 dark:text-slate-100">{{ row.label }}</p>
                                <p class="font-semibold text-slate-600 dark:text-slate-300">{{ row.count }} ({{ row.percent }}%)</p>
                            </div>
                            <div class="mt-2 h-2 rounded-full bg-slate-200 dark:bg-slate-700">
                                <div class="h-2 rounded-full" :class="row.tone" :style="{ width: `${row.percent}%` }"></div>
                            </div>
                        </div>
                    </div>
                </section>
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

            <div class="mt-8 grid grid-cols-1 gap-6 xl:grid-cols-[0.85fr_1.15fr]" v-if="alerts.length || leaderboard.length || focus.length">
                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm" v-if="alerts.length">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Risk Alerts</h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Events requiring immediate attention.</p>

                    <div class="mt-4 space-y-3">
                        <div v-for="alert in alerts" :key="alert.title + alert.message" :class="['rounded-xl border p-3', alert.severityClass]">
                            <p class="text-sm font-black uppercase tracking-[0.18em]">{{ alert.severity }}</p>
                            <p class="mt-1 text-sm font-bold">{{ alert.title }}</p>
                            <p class="mt-1 text-sm opacity-90">{{ alert.message }}</p>
                            <Link v-if="alert.href" :href="alert.href" class="mt-2 inline-block text-sm font-bold underline">Open</Link>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 shadow-sm" v-if="leaderboard.length || focus.length">
                    <div v-if="leaderboard.length">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Performance Leaderboard</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Top contributors in your current scope.</p>

                        <div class="mt-4 space-y-2">
                            <div v-for="(item, idx) in leaderboard" :key="item.name + idx" class="flex items-center justify-between rounded-xl border border-slate-100 dark:border-gray-700 bg-slate-50 dark:bg-gray-900/40 px-3 py-2">
                                <div>
                                    <p class="text-sm font-bold text-slate-900 dark:text-white">{{ idx + 1 }}. {{ item.name }}</p>
                                    <p class="text-xs text-slate-500">{{ item.code }} • {{ item.meta }}</p>
                                </div>
                                <p class="text-sm font-black text-indigo-600 dark:text-indigo-400">{{ item.value }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="focus.length" :class="leaderboard.length ? 'mt-5 pt-5 border-t border-slate-100 dark:border-gray-700' : ''">
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white">Command Focus</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">Three things that matter most right now.</p>

                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-1">
                            <div v-for="point in focus" :key="point.label" class="rounded-xl border border-slate-100 dark:border-gray-700 bg-slate-50 dark:bg-gray-900/40 p-3">
                                <p class="text-xs uppercase tracking-[0.18em] text-slate-500">{{ point.label }}</p>
                                <p class="mt-1 text-sm font-bold text-slate-900 dark:text-white">{{ point.value }}</p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

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
