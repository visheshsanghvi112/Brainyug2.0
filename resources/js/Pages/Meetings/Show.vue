<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ArrowLeftIcon, CalendarDaysIcon, MapPinIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    meeting: Object,
    isAdmin: Boolean,
    isCreator: Boolean,
    myRsvp: String,   // 'attending' | 'declined' | 'invited' | 'missed' | null
});

const page = usePage();
const me = page.props.auth.user;

const statusForm = useForm({ status: props.meeting.status });

function updateStatus() {
    statusForm.patch(route('meetings.update-status', props.meeting.id));
}

function rsvp(choice) {
    router.post(route('meetings.rsvp', props.meeting.id), { status: choice }, { preserveScroll: true });
}

const statusColors = {
    scheduled: 'bg-blue-100 text-blue-700',
    in_progress: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-700',
    cancelled: 'bg-red-100 text-red-700',
};
const rsvpColors = {
    attending: 'bg-emerald-100 text-emerald-700',
    declined: 'bg-red-100 text-red-700',
    invited: 'bg-blue-100 text-blue-700',
    missed: 'bg-gray-100 text-gray-500',
};

function fmt(d) {
    return new Date(d).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head :title="meeting.title" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('meetings.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ meeting.title }}</h2>
                        <p class="text-sm text-gray-500">Scheduled by {{ meeting.creator?.name }}</p>
                    </div>
                </div>
                <span :class="['inline-flex rounded-full px-3 py-1 text-sm font-semibold', statusColors[meeting.status]]">
                    {{ meeting.status.replace('_', ' ') }}
                </span>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 max-w-6xl">

            <!-- Main Content -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Meta -->
                <div class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700 space-y-4">
                    <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <CalendarDaysIcon class="h-4 w-4 flex-shrink-0" />
                        <span>{{ fmt(meeting.start_time) }} – {{ fmt(meeting.end_time) }}</span>
                    </div>
                    <div v-if="meeting.location_or_link" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <MapPinIcon class="h-4 w-4 flex-shrink-0" />
                        <a v-if="meeting.location_or_link.startsWith('http')"
                            :href="meeting.location_or_link" target="_blank" rel="noopener"
                            class="text-indigo-600 hover:underline truncate">
                            {{ meeting.location_or_link }}
                        </a>
                        <span v-else>{{ meeting.location_or_link }}</span>
                    </div>
                    <div v-if="meeting.description" class="pt-2 border-t border-gray-100 dark:border-gray-700">
                        <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed">{{ meeting.description }}</p>
                    </div>
                </div>

                <!-- RSVP buttons for invited attendees -->
                <div v-if="myRsvp !== null" class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Your RSVP</h4>
                    <div class="flex gap-3">
                        <button @click="rsvp('attending')"
                            :class="['flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold ring-1 transition',
                                myRsvp === 'attending' ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 ring-gray-300 dark:ring-gray-600 hover:bg-emerald-50']">
                            <CheckCircleIcon class="h-4 w-4" /> Attending
                        </button>
                        <button @click="rsvp('declined')"
                            :class="['flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold ring-1 transition',
                                myRsvp === 'declined' ? 'bg-red-600 text-white ring-red-600' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200 ring-gray-300 dark:ring-gray-600 hover:bg-red-50']">
                            <XCircleIcon class="h-4 w-4" /> Decline
                        </button>
                    </div>
                </div>

                <!-- Attendees list -->
                <div class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Attendees ({{ meeting.attendees?.length ?? 0 }})</h4>
                    <div v-if="meeting.attendees?.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                        <div v-for="att in meeting.attendees" :key="att.id" class="flex items-center justify-between py-2.5">
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ att.user?.name }}</p>
                                <p class="text-xs text-gray-500">{{ att.user?.email }}</p>
                            </div>
                            <span :class="['inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold', rsvpColors[att.status]]">
                                {{ att.status }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-sm text-gray-400">No attendees invited.</p>
                </div>
            </div>

            <!-- Creator / Admin Controls -->
            <div class="space-y-4">
                <div v-if="isCreator || isAdmin" class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Update Status</h4>
                    <form @submit.prevent="updateStatus" class="space-y-3">
                        <select v-model="statusForm.status"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                            <option value="scheduled">Scheduled</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <button type="submit" :disabled="statusForm.processing"
                            class="w-full rounded-lg bg-indigo-600 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                            Save
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
