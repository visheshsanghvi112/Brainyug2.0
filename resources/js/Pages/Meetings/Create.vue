<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    users: Array, // list of users to invite (subordinates/team)
});

const form = useForm({
    title: '',
    description: '',
    start_time: '',
    end_time: '',
    location_or_link: '',
    attendee_ids: [],
});

function submit() {
    form.post(route('meetings.store'));
}
</script>

<template>
    <Head title="Schedule Meeting" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('meetings.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                    <ArrowLeftIcon class="h-5 w-5" />
                </Link>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Schedule a Meeting</h2>
            </div>
        </template>

        <div class="max-w-2xl">
            <form @submit.prevent="submit" class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700 space-y-5">

                <!-- Title -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Meeting Title <span class="text-red-500">*</span></label>
                    <input v-model="form.title" type="text" required
                        class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                        placeholder="e.g. Q3 Business Review" />
                    <p v-if="form.errors.title" class="mt-1 text-xs text-red-500">{{ form.errors.title }}</p>
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Agenda / Description</label>
                    <textarea v-model="form.description" rows="3"
                        class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                        placeholder="What will be discussed?" />
                </div>

                <!-- Date/Time -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start <span class="text-red-500">*</span></label>
                        <input v-model="form.start_time" type="datetime-local" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        <p v-if="form.errors.start_time" class="mt-1 text-xs text-red-500">{{ form.errors.start_time }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End <span class="text-red-500">*</span></label>
                        <input v-model="form.end_time" type="datetime-local" required
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        <p v-if="form.errors.end_time" class="mt-1 text-xs text-red-500">{{ form.errors.end_time }}</p>
                    </div>
                </div>

                <!-- Location / Link -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location or Meeting Link</label>
                    <input v-model="form.location_or_link" type="text"
                        class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100"
                        placeholder="Conference Room 1 or https://meet.google.com/..." />
                </div>

                <!-- Attendees -->
                <div v-if="users?.length">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Invite Attendees</label>
                    <div class="max-h-48 overflow-y-auto rounded-xl border border-gray-200 dark:border-gray-600 divide-y divide-gray-100 dark:divide-gray-700">
                        <label v-for="u in users" :key="u.id" class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <input type="checkbox" :value="u.id" v-model="form.attendee_ids"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ u.name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ u.email }}</p>
                            </div>
                        </label>
                    </div>
                    <p v-if="form.errors.attendee_ids" class="mt-1 text-xs text-red-500">{{ form.errors.attendee_ids }}</p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 pt-2">
                    <Link :href="route('meetings.index')"
                        class="rounded-lg px-5 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </Link>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-indigo-600 px-6 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                        Schedule Meeting
                    </button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
