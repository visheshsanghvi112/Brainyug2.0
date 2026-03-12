<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ArrowLeftIcon, UserCircleIcon } from '@heroicons/vue/24/outline';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    ticket: Object,
    adminUsers: Array,
    isAdmin: Boolean,
});

const page = usePage();
const me = page.props.auth.user;

const replyForm = useForm({ reply_text: '' });
const statusForm = useForm({ status: props.ticket.status, assigned_to: props.ticket.assigned_to ?? '' });

function sendReply() {
    replyForm.post(route('tickets.reply', props.ticket.id), {
        onSuccess: () => { replyForm.reply_text = ''; },
    });
}

function updateStatus() {
    statusForm.patch(route('tickets.update-status', props.ticket.id));
}

const statusColors = {
    open: 'bg-blue-100 text-blue-800',
    in_progress: 'bg-amber-100 text-amber-800',
    resolved: 'bg-emerald-100 text-emerald-800',
    closed: 'bg-gray-100 text-gray-600',
};
const priorityColors = {
    low: 'text-gray-500',
    normal: 'text-sky-600',
    high: 'text-orange-600',
    urgent: 'text-red-600 font-bold',
};
</script>

<template>
    <Head :title="`Ticket #${ticket.id}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <Link :href="route('tickets.index')" class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200">
                        <ArrowLeftIcon class="h-5 w-5" />
                    </Link>
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ ticket.subject }}</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Ticket #{{ ticket.id }} &bull;
                            <span :class="priorityColors[ticket.priority]">{{ ticket.priority }}</span> priority &bull;
                            Opened {{ new Date(ticket.created_at).toLocaleDateString('en-IN') }}
                        </p>
                    </div>
                </div>
                <span :class="['inline-flex rounded-full px-3 py-1 text-sm font-semibold', statusColors[ticket.status]]">
                    {{ ticket.status.replace('_', ' ') }}
                </span>
            </div>
        </template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 max-w-6xl">

            <!-- Main Conversation -->
            <div class="lg:col-span-2 space-y-4">
                <!-- Original Message -->
                <div class="rounded-xl bg-white dark:bg-gray-800 p-6 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <div class="flex items-center gap-2 mb-3">
                        <UserCircleIcon class="h-6 w-6 text-indigo-400" />
                        <span class="font-semibold text-gray-900 dark:text-white">{{ ticket.user?.name }}</span>
                        <span class="text-xs text-gray-400">{{ new Date(ticket.created_at).toLocaleString('en-IN') }}</span>
                        <span v-if="ticket.franchisee" class="ml-auto text-xs text-gray-500">{{ ticket.franchisee.shop_name }}</span>
                    </div>
                    <p class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed">{{ ticket.description }}</p>
                </div>

                <!-- Replies -->
                <div v-for="reply in ticket.replies" :key="reply.id"
                    :class="[
                        'rounded-xl p-5 ring-1',
                        reply.user_id === me.id
                            ? 'bg-indigo-50 dark:bg-indigo-900/20 ring-indigo-200 dark:ring-indigo-800 ml-8'
                            : 'bg-white dark:bg-gray-800 ring-gray-200 dark:ring-gray-700'
                    ]">
                    <div class="flex items-center gap-2 mb-2">
                        <UserCircleIcon class="h-5 w-5 text-gray-400" />
                        <span class="font-semibold text-sm text-gray-900 dark:text-white">{{ reply.user?.name }}</span>
                        <span class="text-xs text-gray-400">{{ new Date(reply.created_at).toLocaleString('en-IN') }}</span>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed">{{ reply.reply_text }}</p>
                </div>

                <!-- Reply Box -->
                <div v-if="ticket.status !== 'closed'" class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Add a Reply</h4>
                    <form @submit.prevent="sendReply" class="space-y-3">
                        <textarea v-model="replyForm.reply_text" required rows="4"
                            placeholder="Type your reply here…"
                            class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2.5 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                        <div class="flex justify-end">
                            <button type="submit" :disabled="replyForm.processing"
                                class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                                Send Reply
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Admin Sidebar Panel -->
            <div class="space-y-4">
                <div class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Ticket Details</h4>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Shop</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">{{ ticket.franchisee?.shop_name ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Opened by</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">{{ ticket.user?.name }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Assigned to</dt>
                            <dd class="font-medium text-gray-900 dark:text-white">{{ ticket.assigned_to?.name ?? 'Unassigned' }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Admin status + assignment control -->
                <div v-if="isAdmin" class="rounded-xl bg-white dark:bg-gray-800 p-5 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Manage Ticket</h4>
                    <form @submit.prevent="updateStatus" class="space-y-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">Status</label>
                            <select v-model="statusForm.status"
                                class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                                <option value="open">Open</option>
                                <option value="in_progress">In Progress</option>
                                <option value="resolved">Resolved</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <div v-if="adminUsers?.length">
                            <label class="block text-xs text-gray-500 mb-1">Assign To</label>
                            <select v-model="statusForm.assigned_to"
                                class="w-full rounded-xl border-0 bg-gray-50 dark:bg-gray-700 py-2 px-3 text-sm ring-1 ring-inset ring-gray-200 dark:ring-gray-600 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                                <option value="">Unassigned</option>
                                <option v-for="u in adminUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                        </div>
                        <button type="submit" :disabled="statusForm.processing"
                            class="w-full rounded-lg bg-indigo-600 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                            Update
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
