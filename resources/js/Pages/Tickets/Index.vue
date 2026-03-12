<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { LifebuoyIcon, MagnifyingGlassIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    tickets: Object,
    filters: Object,
    isAdmin: Boolean,
});

const search   = ref(props.filters?.search   || '');
const status   = ref(props.filters?.status   || '');
const priority = ref(props.filters?.priority || '');

function applyFilters() {
    router.get(route('tickets.index'), {
        search:   search.value   || undefined,
        status:   status.value   || undefined,
        priority: priority.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}
let timer;
watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 300); });
watch([status, priority], applyFilters);

const statusColors = {
    open:        'bg-blue-100 text-blue-800',
    in_progress: 'bg-amber-100 text-amber-800',
    resolved:    'bg-emerald-100 text-emerald-800',
    closed:      'bg-gray-100 text-gray-600',
};

const priorityColors = {
    low:    'bg-gray-100 text-gray-600',
    normal: 'bg-sky-100 text-sky-700',
    high:   'bg-orange-100 text-orange-700',
    urgent: 'bg-red-100 text-red-700',
};
</script>

<template>
    <Head title="Support Tickets" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <LifebuoyIcon class="h-7 w-7 text-indigo-500" />
                    <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">Support Tickets</h2>
                </div>
                <Link :href="route('tickets.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 transition-all"
                    v-if="!isAdmin">
                    <PlusIcon class="h-5 w-5" /> Raise a Ticket
                </Link>
            </div>
        </template>

        <div class="space-y-5">
            <!-- Filters -->
            <div class="flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-40">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                    <input v-model="search" type="text" placeholder="Search subject…"
                        class="w-full rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 pl-9 pr-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100" />
                </div>
                <select v-model="status"
                    class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 px-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                    <option value="">All Status</option>
                    <option value="open">Open</option>
                    <option value="in_progress">In Progress</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                </select>
                <select v-model="priority"
                    class="rounded-xl border-0 bg-white dark:bg-gray-800 py-2.5 px-3 text-sm shadow ring-1 ring-inset ring-gray-200 dark:ring-gray-700 focus:ring-2 focus:ring-indigo-500 dark:text-gray-100">
                    <option value="">All Priority</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="normal">Normal</option>
                    <option value="low">Low</option>
                </select>
            </div>

            <!-- Tickets List -->
            <div class="space-y-3">
                <div v-if="tickets.data.length === 0" class="rounded-xl bg-white dark:bg-gray-800 p-16 text-center text-gray-400 shadow ring-1 ring-gray-200 dark:ring-gray-700">
                    No tickets found.
                </div>

                <Link v-for="ticket in tickets.data" :key="ticket.id"
                    :href="route('tickets.show', ticket.id)"
                    class="block rounded-xl bg-white dark:bg-gray-800 p-5 shadow ring-1 ring-gray-200 dark:ring-gray-700 hover:shadow-md hover:ring-indigo-300 dark:hover:ring-indigo-700 transition-all">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <span :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', priorityColors[ticket.priority]]">
                                    {{ ticket.priority }}
                                </span>
                                <span :class="['inline-flex rounded-full px-2 py-0.5 text-xs font-medium', statusColors[ticket.status]]">
                                    {{ ticket.status.replace('_', ' ') }}
                                </span>
                            </div>
                            <p class="font-semibold text-gray-900 dark:text-white truncate">{{ ticket.subject }}</p>
                            <div class="mt-1 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
                                <span v-if="isAdmin && ticket.franchisee">{{ ticket.franchisee.shop_name }}</span>
                                <span>By: {{ ticket.user?.name }}</span>
                                <span>{{ new Date(ticket.created_at).toLocaleDateString('en-IN') }}</span>
                                <span v-if="ticket.assigned_to">Assigned: {{ ticket.assigned_to?.name }}</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-xs text-gray-500">{{ ticket.replies_count }} repl{{ ticket.replies_count === 1 ? 'y' : 'ies' }}</p>
                        </div>
                    </div>
                </Link>
            </div>

            <Pagination :data="tickets" />
        </div>
    </AuthenticatedLayout>
</template>
