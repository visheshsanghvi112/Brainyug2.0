<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
    showEmailFallback: {
        type: Boolean,
        default: false,
    },
});

const directForm = useForm({
    identifier: '',
    password: '',
    password_confirmation: '',
});

const emailForm = useForm({
    email: '',
});

const submitDirect = () => {
    directForm.post(route('password.email'), {
        onFinish: () => {
            directForm.reset('password', 'password_confirmation');
        },
    });
};

const submitEmail = () => {
    emailForm.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <div class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900">
            First-time migrated login: enter your User ID and set a new password now.
        </div>

        <div
            v-if="status"
            class="mb-4 text-sm font-medium text-green-600 dark:text-green-400"
        >
            {{ props.status }}
        </div>

        <form @submit.prevent="submitDirect">
            <div>
                <InputLabel for="identifier" value="User ID" />

                <TextInput
                    id="identifier"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="directForm.identifier"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="Username / Legacy Username / Numeric ID"
                />

                <InputError class="mt-2" :message="directForm.errors.identifier" />
            </div>

            <div class="mt-4">
                <InputLabel for="password" value="New Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="directForm.password"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="directForm.errors.password" />
            </div>

            <div class="mt-4">
                <InputLabel for="password_confirmation" value="Confirm New Password" />

                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="directForm.password_confirmation"
                    required
                    autocomplete="new-password"
                />

                <InputError class="mt-2" :message="directForm.errors.password_confirmation" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton
                    :class="{ 'opacity-25': directForm.processing }"
                    :disabled="directForm.processing"
                >
                    Set Password Now
                </PrimaryButton>
            </div>
        </form>

        <div v-if="props.showEmailFallback" class="mt-8 border-t pt-6">
            <div class="mb-3 text-sm text-gray-600 dark:text-gray-300">
                This account has already completed first-time setup. Use email reset below.
            </div>

            <form @submit.prevent="submitEmail">
                <div>
                    <InputLabel for="email" value="Email" />

                    <TextInput
                        id="email"
                        type="email"
                        class="mt-1 block w-full"
                        v-model="emailForm.email"
                        required
                        autocomplete="email"
                    />

                    <InputError class="mt-2" :message="emailForm.errors.email" />
                </div>

                <div class="mt-4 flex items-center justify-end">
                    <PrimaryButton
                        :class="{ 'opacity-25': emailForm.processing }"
                        :disabled="emailForm.processing"
                    >
                        Email Password Reset Link
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
