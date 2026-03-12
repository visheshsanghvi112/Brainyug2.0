<script setup>
import { ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import axios from 'axios';

const user = usePage().props.auth.user;

const mfaEnabled = ref(user.is_2fa_enabled);
const processing = ref(false);
const showQr = ref(false);
const qrCodeSvg = ref('');
const secretKey = ref('');
const setupCode = ref('');
const setupError = ref('');

const requestMfa = async () => {
    processing.value = true;
    try {
        const response = await axios.post(route('profile.2fa.request'));
        qrCodeSvg.value = response.data.qrCodeSvg;
        secretKey.value = response.data.secret;
        showQr.value = true;
    } catch (e) {
        console.error(e);
    } finally {
        processing.value = false;
    }
};

const confirmMfa = async () => {
    processing.value = true;
    setupError.value = '';
    try {
        await axios.post(route('profile.2fa.confirm'), { code: setupCode.value });
        mfaEnabled.value = true;
        showQr.value = false;
        setupCode.value = '';
        router.reload(); // Refresh inertia props seamlessly
    } catch (e) {
        if (e.response && e.response.status === 422) {
            setupError.value = e.response.data.errors?.code?.[0] || 'Invalid code';
        } else {
            setupError.value = e.response.data.message || 'Error enforcing 2FA';
        }
    } finally {
        processing.value = false;
    }
};

const disableMfa = async () => {
    processing.value = true;
    try {
        await axios.post(route('profile.2fa.disable'));
        mfaEnabled.value = false;
        showQr.value = false;
        router.reload();
    } catch (e) {
        console.error(e);
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 flex items-center gap-2">
                Two-Factor Authentication (2FA)
                <span v-if="mfaEnabled" class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20">Enabled</span>
                <span v-else class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">Disabled</span>
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Add additional security to your account using two factor authentication.
            </p>
        </header>

        <div class="mt-6">
            <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                <template v-if="mfaEnabled">
                    You have enabled two factor authentication.
                </template>
                <template v-else-if="showQr">
                    Finish enabling two factor authentication.
                </template>
                <template v-else>
                    You have not enabled two factor authentication.
                </template>
            </h3>
            
            <p v-if="!showQr" class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                When two factor authentication is enabled, you will be prompted for a secure, random token during authentication. You may retrieve this token from your phone's Google Authenticator, Authy, or Microsoft Authenticator application. This helps protect sensitive ERP actions.
            </p>

            <div v-if="showQr" class="mt-4">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    To finish enabling two factor authentication, scan the following QR code using your phone's authenticator application or enter the setup key and provide the generated OTP code.
                </p>

                <div class="mt-4 p-2 inline-block bg-white rounded-lg" v-html="qrCodeSvg"></div>

                <div class="mt-4 max-w-xl text-sm text-gray-600 dark:text-gray-400">
                    <p class="font-semibold">Setup Key: <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">{{ secretKey }}</span></p>
                </div>

                <div class="mt-4">
                    <InputLabel for="code" value="Code" />
                    <TextInput
                        id="code"
                        type="text"
                        name="code"
                        class="block mt-1 w-1/2"
                        v-model="setupCode"
                        autofocus
                        autocomplete="one-time-code"
                        @keyup.enter="confirmMfa"
                    />
                    <InputError :message="setupError" class="mt-2" />
                </div>
            </div>

            <div class="mt-5">
                <PrimaryButton v-if="!mfaEnabled && !showQr" @click="requestMfa" :disabled="processing">
                    Enable 2FA
                </PrimaryButton>

                <PrimaryButton v-if="showQr" @click="confirmMfa" :disabled="processing" :class="{ 'opacity-25': processing }">
                    Confirm
                </PrimaryButton>

                <SecondaryButton v-if="showQr" class="ml-3" @click="showQr = false" :disabled="processing">
                    Cancel
                </SecondaryButton>

                <DangerButton v-if="mfaEnabled" @click="disableMfa" :disabled="processing">
                    Disable 2FA
                </DangerButton>
            </div>
        </div>
    </section>
</template>
