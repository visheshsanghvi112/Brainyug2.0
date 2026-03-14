<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
        default: null,
    },
});

const form = useForm({
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('password.force.update'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Reset Password" />

        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100">Reset Password</h2>
        </template>

        <div class="mx-auto max-w-xl py-8">
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                For security, this migrated account requires a new password before you can use the ERP.
            </div>

            <div v-if="status" class="mt-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
                {{ status }}
            </div>

            <form class="mt-6 space-y-4 rounded-xl bg-white p-6 shadow" @submit.prevent="submit">
                <div>
                    <InputLabel for="password" value="New Password" />
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autofocus
                        autocomplete="new-password"
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <InputLabel for="password_confirmation" value="Confirm New Password" />
                    <TextInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="new-password"
                    />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>

                <div class="flex justify-end">
                    <PrimaryButton :disabled="form.processing" :class="{ 'opacity-25': form.processing }">
                        Save Password
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
