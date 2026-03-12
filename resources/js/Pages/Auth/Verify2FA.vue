<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const form = useForm({
    code: '',
});

const submit = () => {
    form.post(route('2fa.verify'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Two-Factor Authentication" />

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            Please confirm access to your account by entering the authentication code provided by your authenticator application.
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="code" value="Code" />
                <TextInput
                    id="code"
                    type="text"
                    inputmode="numeric"
                    class="block mt-1 w-full"
                    v-model="form.code"
                    required
                    autofocus
                    autocomplete="one-time-code"
                />
                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div class="flex items-center justify-end mt-4">
                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Log in
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
