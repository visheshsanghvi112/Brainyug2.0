<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';

const page = usePage();
const prefs = page.props.auth.user.preferences || {};

const form = useForm({
    email_orders: prefs.email_orders ?? true,
    email_tickets: prefs.email_tickets ?? true,
    email_reports: prefs.email_reports ?? false,
    sms_alerts: prefs.sms_alerts ?? false,
    language: prefs.language ?? 'English',
    timezone: prefs.timezone ?? 'Asia/Kolkata',
});

const submit = () => {
    form.patch(route('profile.update-preferences'), {
        preserveScroll: true,
        onSuccess: () => {
            form.recentlySuccessful = true;
            setTimeout(() => form.recentlySuccessful = false, 2000);
        }
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Preferences
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Manage your localization and notification settings.
            </p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-6">
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <InputLabel for="language" value="Language" />
                    <select
                        id="language"
                        v-model="form.language"
                        class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600"
                    >
                        <option>English</option>
                        <option>Hindi</option>
                        <option>Marathi</option>
                        <option>Gujarati</option>
                    </select>
                </div>
                <div>
                    <InputLabel for="timezone" value="Timezone" />
                    <select
                        id="timezone"
                        v-model="form.timezone"
                        class="mt-1 block w-full rounded-md border-gray-300 py-2 pl-3 pr-10 text-base focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:ring-indigo-600"
                    >
                        <option>Asia/Kolkata</option>
                        <option>America/New_York</option>
                        <option>Europe/London</option>
                        <option>Australia/Sydney</option>
                    </select>
                </div>
            </div>

            <hr class="border-gray-200 dark:border-gray-700" />

            <div class="space-y-4">
                <div class="flex items-start">
                    <div class="flex h-6 items-center">
                        <input id="email_orders" v-model="form.email_orders" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900 dark:checked:bg-indigo-600" />
                    </div>
                    <div class="ml-3 text-sm leading-6">
                        <label for="email_orders" class="font-medium text-gray-900 dark:text-gray-100">Order Updates</label>
                        <p class="text-gray-500 dark:text-gray-400">Get notified when an order is accepted, dispatched, or rejected.</p>
                    </div>
                </div>
                
                <div class="flex items-start">
                    <div class="flex h-6 items-center">
                        <input id="email_tickets" v-model="form.email_tickets" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900 dark:checked:bg-indigo-600" />
                    </div>
                    <div class="ml-3 text-sm leading-6">
                        <label for="email_tickets" class="font-medium text-gray-900 dark:text-gray-100">Support Tickets</label>
                        <p class="text-gray-500 dark:text-gray-400">Receive emails when there is a reply or status change on your tickets.</p>
                    </div>
                </div>

                <div class="flex items-start">
                    <div class="flex h-6 items-center">
                        <input id="email_reports" v-model="form.email_reports" type="checkbox" class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600 dark:border-gray-700 dark:bg-gray-900 dark:checked:bg-indigo-600" />
                    </div>
                    <div class="ml-3 text-sm leading-6">
                        <label for="email_reports" class="font-medium text-gray-900 dark:text-gray-100">Monthly Reports</label>
                        <p class="text-gray-500 dark:text-gray-400">Receive a monthly summary of your account's stock and financial ledger.</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Save Preferences</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600 dark:text-gray-400">Saved.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
