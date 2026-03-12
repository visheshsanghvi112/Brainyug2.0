<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CalendarDaysIcon, MapPinIcon, UsersIcon } from '@heroicons/vue/24/outline';
import { ref } from 'vue';

const props = defineProps({
    meetings: Object,
    canCreate: Boolean,
    filters: Object,
});

const page = usePage();
const me = page.props.auth.user;

const statusColors = {
    scheduled: 'bg-blue-100 text-blue-700',
    in_progress: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-700',
    cancelled: 'bg-red-100 text-red-700',
};

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');

function applyFilters() {
    window.location.href = route('meetings.index', { search: search.value, status: status.value });
}

function formatDateRange(start, end) {
    const s = new Date(start);
    const e = new Date(end);
    const dateStr = s.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    const startTime = s.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    const endTime = e.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    return `${dateStr}, ${startTime} – ${endTime}`;
}

function myRsvp(meeting) {
    const att = meeting.attendees?.find(a => a.user_id === me.id);
    return att?.status ?? null;
}

const rsvpBadge = { attending: 'bg-emerald-100 text-emerald-700', declined: 'bg-red-100 text-red-700', invited: 'bg-blue-100 text-blue-700', missed: 'bg-gray-100 text-gray-500' };
</script>

<template>
    <Head title="Meetings" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Meetings</h2>
                <Link v-if="canCreate" :href="route('meetings.create')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    + Schedule Meeting
                </Link>
            </div>
        </template>

        <!-- Filters -->
        <div class="flex flex-wrap gap-3 mb-6">
            <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Search meetings…"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100 w-60" />
            <select v-model="status" @change="applyFilters"
                class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                <option value="">All Statuses</option>
                <option value="scheduled">Scheduled</option>
                <option value="in_progress">In Progress</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>

        <!-- Meeting Cards -->
        <div v-if="meetings.data.length" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <Link v-for="m in meetings.data" :key="m.id" :href="route('meetings.show', m.id)"
                class="rounded-xl bg-white dark:bg-gray-800 p-5 ring-1 ring-gray-200 dark:ring-gray-700 hover:ring-indigo-400 hover:shadow-sm transition-all block">
                <div class="flex items-start justify-between mb-2">
                    <h3 class="font-semibold text-gray-900 dark:text-white text-sm leading-snug flex-1 pr-2">{{ m.title }}</h3>
                    <span :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap', statusColors[m.status]]">
                        {{ m.status.replace('_', ' ') }}
                    </span>
                </div>
                <div class="space-y-1.5 mt-3">
                    <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <CalendarDaysIcon class="h-3.5 w-3.5 flex-shrink-0" />
                        <span>{{ formatDateRange(m.start_time, m.end_time) }}</span>
                    </div>
                    <div v-if="m.location_or_link" class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <MapPinIcon class="h-3.5 w-3.5 flex-shrink-0" />
                        <span class="truncate">{{ m.location_or_link }}</span>
                    </div>
                    <div class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                        <UsersIcon class="h-3.5 w-3.5 flex-shrink-0" />
                        <span>{{ m.attendees_count ?? m.attendees?.length ?? 0 }} attendees</span>
                    </div>
                </div>
                <!-- My RSVP indicator -->
                <div v-if="myRsvp(m)" class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700">
                    <span :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', rsvpBadge[myRsvp(m)]]">
                        You: {{ myRsvp(m) }}
                    </span>
                </div>
                <div class="mt-3 text-xs text-gray-400">By {{ m.creator?.name }}</div>
            </Link>
        </div>

        <div v-else class="text-center py-16 text-gray-400">
            <CalendarDaysIcon class="h-12 w-12 mx-auto mb-3 opacity-40" />
            <p>No meetings found.</p>
        </div>

        <Pagination :data="meetings" />
    </AuthenticatedLayout>
</template>
