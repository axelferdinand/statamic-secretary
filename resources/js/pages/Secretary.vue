<script setup>
import { Head, Link, router, usePoll } from '@statamic/cms/inertia';
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ChangePreviewModal from '../components/ChangePreviewModal.vue';
import DeveloperTrace from '../components/DeveloperTrace.vue';
import SecretaryChangeList from '../components/SecretaryChangeList.vue';
import SecretaryOnboarding from '../components/SecretaryOnboarding.vue';

const props = defineProps({
    conversations: { type: Array, required: true },
    conversation: { type: Object, default: null },
    can_publish: { type: Boolean, required: true },
    configured: { type: Boolean, required: true },
    openai_setup: { type: Object, required: true },
    email_enabled: { type: Boolean, required: true },
    email_setup: { type: Object, required: true },
    relay_setup: { type: Object, required: true },
    onboarding: { type: Object, required: true },
    style_guides: { type: Object, required: true },
    diagnostics: { type: Object, required: true },
    developer_mode: { type: Boolean, required: true },
    success: { type: String, default: null },
    max_input_characters: { type: Number, required: true },
    endpoints: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});

const axios = getCurrentInstance()?.proxy?.$axios;
const message = ref('');
const feed = ref(null);
const composerInput = ref(null);
const references = ref([]);
const referenceLoading = ref(false);
const conversationQuery = ref('');
const conversationMenuOpen = ref(false);
const busy = ref(false);
const setupBusy = ref(false);
const postmarkApiKey = ref('');
const publishCandidate = ref(null);
const reviewingTarget = ref(null);
const previewOpen = ref(false);
const previewLoading = ref(false);
const previewData = ref(null);
const previewError = ref(null);
const guideBusy = ref(false);
const guideSite = ref(props.style_guides.sites?.[0]?.handle ?? '');
const guideForm = ref({
    audience: '',
    voice: '',
    terminology: '',
    avoid: '',
});
const emailConnected = computed(() => props.email_setup.connected || props.relay_setup.connected);
const activeEmailAddress = computed(() => {
    if (props.relay_setup.connected && props.relay_setup.address) {
        return props.relay_setup.address;
    }

    if (props.email_setup.connected && props.email_setup.from_address) {
        return props.email_setup.from_address;
    }

    return null;
});
const showSetup = ref(!emailConnected.value);
const setupMode = ref(props.relay_setup.connected || (!props.email_setup.token_configured && props.relay_setup.pairing_available)
    ? 'relay'
    : 'postmark');
const emailAddress = ref(props.email_setup.from_address ?? '');
const publicUrl = ref(props.email_setup.suggested_public_url ?? '');
const pairingCode = ref('');
const relayEmail = ref(props.relay_setup.pending_sender
    ?? props.relay_setup.sender
    ?? props.relay_setup.suggested_sender
    ?? '');
const relayPublicUrl = ref(props.relay_setup.suggested_public_url ?? '');
const sharedAddressPreview = computed(() => {
    if (props.relay_setup.address) {
        return props.relay_setup.address;
    }

    try {
        const hostname = new URL(relayPublicUrl.value).hostname.replace(/^www\./, '');

        if (hostname && !hostname.endsWith('.test')) {
            return `${hostname}@statamic.no`;
        }
    } catch {
        // Keep the example until a valid public URL has been entered.
    }

    return 'yourdomain.com@statamic.no';
});
const actionError = ref(null);
const onboardingActive = computed(() => props.openai_setup.can_configure
    && (!props.configured || (
        props.conversations.length === 0
        && !emailConnected.value
        && !props.onboarding.email_skipped
    )));
const error = computed(() => props.errors?.secretary ?? actionError.value ?? null);
const setupError = computed(() => props.errors?.relay_setup
    ?? props.errors?.pairing_code
    ?? props.errors?.relay_email
    ?? props.errors?.postmark_setup
    ?? props.errors?.email
    ?? props.errors?.public_url
    ?? null);
const processing = computed(() => props.conversation?.processing === true);
const visibleMessages = computed(() => (
    props.conversation?.messages?.filter(item => item.presentation !== 'hidden')
    ?? []
));
const pendingMessages = computed(() => visibleMessages.value.filter(item => item.pending));
const activePendingMessage = computed(() => pendingMessages.value[0] ?? null);
const processingStatus = computed(() => ({
    queued: 'Waiting for Secretary',
    understanding: 'Understanding your request',
    finding_content: 'Finding the right content',
    reading_content: 'Reading content and fields',
    reviewing_assets: 'Reviewing available images',
    saving_draft: 'Validating and saving the draft',
    writing_reply: 'Preparing the response',
    publishing: 'Publishing the approved change',
    working: 'Working on your request',
})[activePendingMessage.value?.processing_stage] ?? 'Working on your request');
const filteredConversations = computed(() => {
    const query = conversationQuery.value.trim().toLocaleLowerCase('en');

    if (!query) return props.conversations;

    return props.conversations.filter(item => [
        item.title,
        channelLabel(item.channel),
    ].some(value => String(value ?? '').toLocaleLowerCase('en').includes(query)));
});
const diagnosticSummary = computed(() => {
    const checks = props.diagnostics.checks ?? [];
    const blockers = checks.filter(check => check.required && !check.passed).length;
    const warnings = checks.filter(check => !check.required && !check.passed).length;

    return { blockers, warnings, ready: blockers === 0 };
});
const promptSuggestions = computed(() => {
    const field = props.conversation?.context?.field;

    if (field?.type === 'bard' || field?.type === 'replicator') {
        return [
            `Improve the copy in the ${field.set_type ? `${field.set_type} module` : field.display}.`,
            'Make this module shorter without changing the rest of the page.',
            'Suggest a better order for the content modules.',
        ];
    }

    if (field) {
        return [
            `Make the “${field.display}” field clearer.`,
            `Proofread only “${field.display}”.`,
            `Write three alternatives for “${field.display}”.`,
        ];
    }

    return props.conversation?.context ? [
        'Make the introduction clearer and shorter.',
        'Find language issues on this page.',
        'Suggest a better page title.',
    ] : [
        'Make the homepage introduction clearer.',
        'Find the “About us” page and suggest a better title.',
        'Draft a new contact page.',
    ];
});
const { start: startPolling, stop: stopPolling } = usePoll(2000, {
    only: ['conversation', 'conversations'],
    preserveScroll: true,
}, { autoStart: false });
let pollingMounted = false;
let referenceTimer = null;

function newConversation() {
    router.post(props.endpoints.create, {}, { onStart: () => busy.value = true, onFinish: () => busy.value = false });
}

function connectPostmark() {
    if ((!props.email_setup.token_configured && !postmarkApiKey.value.trim()) || !emailAddress.value.trim() || !publicUrl.value.trim() || setupBusy.value) return;

    router.post(props.email_setup.setup_url, {
        api_key: postmarkApiKey.value.trim() || null,
        email: emailAddress.value.trim(),
        public_url: publicUrl.value.trim(),
    }, {
        preserveScroll: true,
        onStart: () => setupBusy.value = true,
        onSuccess: () => showSetup.value = false,
        onFinish: () => setupBusy.value = false,
    });
}

function connectRelay() {
    if (!props.relay_setup.pairing_available || !pairingCode.value.trim() || !relayPublicUrl.value.trim() || setupBusy.value) return;

    router.post(props.relay_setup.setup_url, {
        pairing_code: pairingCode.value.trim(),
        public_url: relayPublicUrl.value.trim(),
    }, {
        preserveScroll: true,
        onStart: () => setupBusy.value = true,
        onSuccess: () => {
            pairingCode.value = '';
            showSetup.value = false;
        },
        onFinish: () => setupBusy.value = false,
    });
}

function requestRelayCode() {
    if (!props.relay_setup.pairing_available || !relayEmail.value.trim() || setupBusy.value) return;

    router.post(props.relay_setup.request_code_url, {
        email: relayEmail.value.trim(),
    }, {
        preserveScroll: true,
        onStart: () => setupBusy.value = true,
        onSuccess: () => nextTick(() => document.getElementById('secretary-pairing-code')?.focus()),
        onFinish: () => setupBusy.value = false,
    });
}

function send() {
    if (!props.conversation || !message.value.trim() || busy.value || !props.configured) return;

    router.post(props.conversation.send_url, { message: message.value.trim() }, {
        preserveScroll: true,
        onStart: () => busy.value = true,
        onSuccess: () => message.value = '',
        onFinish: () => busy.value = false,
    });
}

function requestPublish(change) {
    if (!props.can_publish || change.status !== 'draft' || busy.value || processing.value) return;

    publishCandidate.value = change;
}

function publish() {
    const change = publishCandidate.value;

    if (!change || !props.can_publish || change.status !== 'draft' || busy.value || processing.value) return;

    router.post(change.publish_url, {}, {
        preserveScroll: true,
        onStart: () => busy.value = true,
        onSuccess: () => publishCandidate.value = null,
        onFinish: () => busy.value = false,
    });
}

function responseError(exception, fallback) {
    return exception?.response?.data?.message
        ?? Object.values(exception?.response?.data?.errors ?? {}).flat()[0]
        ?? fallback;
}

function referenceMatch(value) {
    return value.match(/(?:^|\s)@([\p{L}\p{N}_ -]{2,80})$/u);
}

async function loadReferences(query) {
    if (!props.endpoints.references || !query || !axios) return;

    referenceLoading.value = true;

    try {
        const response = await axios.get(props.endpoints.references, { params: { q: query } });
        references.value = response.data.references ?? [];
    } catch {
        references.value = [];
    } finally {
        referenceLoading.value = false;
    }
}

function scheduleReferences(value) {
    if (referenceTimer) window.clearTimeout(referenceTimer);
    const match = referenceMatch(value);

    if (!match) {
        references.value = [];
        return;
    }

    referenceTimer = window.setTimeout(() => loadReferences(match[1].trim()), 220);
}

function insertReference(reference) {
    const match = referenceMatch(message.value);

    if (!match) return;

    const start = (match.index ?? 0) + match[0].lastIndexOf('@');
    message.value = `${message.value.slice(0, start)}${reference.token} `;
    references.value = [];
    nextTick(() => composerInput.value?.focus());
}

function startReference() {
    const spacer = message.value && !/\s$/.test(message.value) ? ' ' : '';
    message.value = `${message.value}${spacer}@`;
    nextTick(() => composerInput.value?.focus());
}

function resizeComposer() {
    const input = composerInput.value;

    if (!input) return;

    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 224)}px`;
}

function reuseFailedMessage() {
    if (!props.conversation?.failed_message_body) return;

    message.value = props.conversation.failed_message_body;
    actionError.value = null;
    nextTick(() => {
        resizeComposer();
        composerInput.value?.focus();
    });
}

async function review(change, target, decision) {
    if (!axios || !change?.review_url || reviewingTarget.value || processing.value) return;

    reviewingTarget.value = target.key;
    actionError.value = null;

    try {
        const response = await axios.post(change.review_url, {
            target: target.key,
            decision,
        });
        Object.assign(change, response.data.change);
    } catch (exception) {
        actionError.value = responseError(exception, 'Secretary could not update the field selection.');
    } finally {
        reviewingTarget.value = null;
    }
}

async function openPreview(change) {
    if (!axios || !change?.preview_url || previewLoading.value) return;

    previewOpen.value = true;
    previewLoading.value = true;
    previewData.value = null;
    previewError.value = null;

    try {
        const response = await axios.get(change.preview_url);
        previewData.value = response.data;
    } catch (exception) {
        previewError.value = responseError(exception, 'The preview could not be opened.');
    } finally {
        previewLoading.value = false;
    }
}

function closePreview() {
    previewOpen.value = false;
    previewData.value = null;
    previewError.value = null;
}

function loadGuide() {
    const site = props.style_guides.sites?.find(item => item.handle === guideSite.value);
    guideForm.value = {
        audience: site?.guide?.audience ?? '',
        voice: site?.guide?.voice ?? '',
        terminology: site?.guide?.terminology ?? '',
        avoid: site?.guide?.avoid ?? '',
    };
}

function saveGuide() {
    if (!props.style_guides.can_configure || !guideSite.value || guideBusy.value) return;

    router.post(props.style_guides.save_url, {
        site: guideSite.value,
        ...guideForm.value,
    }, {
        preserveScroll: true,
        onStart: () => guideBusy.value = true,
        onFinish: () => guideBusy.value = false,
    });
}

function useSuggestion(suggestion) {
    message.value = suggestion;
    nextTick(() => document.getElementById('secretary-message')?.focus());
}

function onComposerKeydown(event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        send();
    }
}

function channelLabel(channel) {
    return channel === 'email' ? 'Email' : 'Control Panel';
}

function relativeTime(value) {
    if (!value) return '';

    const date = new Date(value);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    const units = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size || unit === 'minute') {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return '';
}

function changesForMessage(item) {
    if (item?.role === 'user' || !item?.reply_to_message_id) return [];

    return props.conversation?.changes?.filter(
        change => change.proposed_by_message_id === item.reply_to_message_id,
    ) ?? [];
}

const latestChangeMessageId = computed(() => {
    for (let index = visibleMessages.value.length - 1; index >= 0; index -= 1) {
        const item = visibleMessages.value[index];

        if (!changesForMessage(item).length) continue;

        const newerUserRequest = visibleMessages.value
            .slice(index + 1)
            .some(messageItem => messageItem.presentation === 'user');

        return newerUserRequest ? null : item.id;
    }

    return null;
});
const activeChangeIds = computed(() => new Set(
    latestChangeMessageId.value
        ? changesForMessage(visibleMessages.value.find(item => item.id === latestChangeMessageId.value))
            .map(change => change.id)
        : [],
));
const previousChanges = computed(() => (
    props.conversation?.changes?.filter(change => !activeChangeIds.value.has(change.id))
    ?? []
));

function scrollToLatest() {
    nextTick(() => {
        if (feed.value) feed.value.scrollTop = feed.value.scrollHeight;
    });
}

function syncPolling() {
    if (!pollingMounted) return;
    processing.value ? startPolling() : stopPolling();
}

onMounted(() => {
    pollingMounted = true;
    scrollToLatest();
    syncPolling();
    loadGuide();
});
watch(() => props.conversation?.messages?.length, scrollToLatest);
watch(processing, syncPolling);
watch(message, scheduleReferences);
watch(message, () => nextTick(resizeComposer));
watch(() => props.email_setup.connected, connected => {
    if (connected) showSetup.value = false;
});
watch(() => props.relay_setup.connected, connected => {
    if (connected) showSetup.value = false;
});
onBeforeUnmount(() => {
    if (referenceTimer) window.clearTimeout(referenceTimer);
});
watch(guideSite, loadGuide);
</script>

<template>
    <Head title="Secretary" />

    <ui-header title="Secretary">
        <template #actions>
            <ui-button v-if="!onboardingActive" icon="plus" :loading="busy" :disabled="busy" @click="newConversation">
                New conversation
            </ui-button>
        </template>
    </ui-header>

    <p v-if="!onboardingActive" class="secretary-page-lead">
        Ask for a change. Secretary prepares the draft; you review and publish.
    </p>

    <SecretaryOnboarding
        v-if="onboardingActive"
        :configured="configured"
        :openai="openai_setup"
        :email="email_setup"
        :relay="relay_setup"
        :onboarding="onboarding"
        :errors="errors"
        :success="success"
    />

    <div v-else class="space-y-4">
        <ui-alert
            v-if="!configured"
            variant="warning"
            heading="OpenAI is not configured"
            text="An administrator can connect OpenAI from Settings. Environment configuration is also supported."
        />

        <ui-alert
            v-if="success"
            variant="success"
            heading="Done"
            :text="success"
        />

        <ui-alert
            v-if="!emailConnected && !email_setup.token_configured && !relay_setup.pairing_available"
            variant="warning"
            heading="Email is not connected"
            text="An administrator can connect Secretary Relay or a private Postmark server from Settings. Control Panel chat remains available."
        />

        <section class="secretary-page-settings">
            <details :open="!emailConnected || Boolean(setupError)">
                <summary class="secretary-page-settings-summary">
                    <span class="secretary-tools-panel-icon"><ui-icon name="cog" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <strong>Settings and status</strong>
                        <small v-if="activeEmailAddress" class="secretary-settings-email-preview">
                            {{ activeEmailAddress }}
                        </small>
                        <small v-else>Email, editorial guide, and system status</small>
                    </span>
                    <span class="secretary-page-settings-badges">
                        <ui-badge :variant="emailConnected ? 'success' : 'warning'">
                            {{ emailConnected ? 'Email ready' : 'Email missing' }}
                        </ui-badge>
                        <ui-badge :variant="diagnosticSummary.ready ? (diagnosticSummary.warnings ? 'warning' : 'success') : 'error'">
                            {{ diagnosticSummary.ready ? (diagnosticSummary.warnings ? `${diagnosticSummary.warnings} warnings` : 'System ready') : `${diagnosticSummary.blockers} errors` }}
                        </ui-badge>
                    </span>
                    <span class="secretary-settings-summary-action">
                        <span class="secretary-settings-action-open">Show details</span>
                        <span class="secretary-settings-action-close">Hide</span>
                        <ui-icon name="chevron-down" class="secretary-page-settings-chevron size-4" aria-hidden="true" />
                    </span>
                </summary>

                <div class="secretary-page-settings-content">
        <section v-if="relay_setup.connected && !showSetup" class="secretary-settings-card secretary-email-card">
            <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="secretary-settings-eyebrow">Secretary address</span>
                        <ui-badge variant="success">Recommended setup</ui-badge>
                    </div>
                    <a :href="`mailto:${relay_setup.address}`" class="secretary-email-address">
                        {{ relay_setup.address }}
                    </a>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Send instructions here. Secretary replies from the same address and keeps the conversation together
                        <template v-if="relay_setup.sender">
                            for <strong>{{ relay_setup.sender }}</strong>
                        </template>.
                    </p>
                    <details v-if="relay_setup.route_address && relay_setup.route_address !== relay_setup.address" class="secretary-technical-address">
                        <summary>Show technical fallback address</summary>
                        <code>{{ relay_setup.route_address }}</code>
                    </details>
                </div>
                <ui-button
                    v-if="relay_setup.can_configure"
                    size="sm"
                    @click="setupMode = 'relay'; showSetup = true"
                >
                    Change setup
                </ui-button>
            </div>
        </section>

        <section v-else-if="email_setup.connected && !showSetup" class="secretary-settings-card secretary-email-card">
            <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="secretary-settings-eyebrow">Secretary address</span>
                        <ui-badge variant="default">Your Postmark server</ui-badge>
                    </div>
                    <a :href="`mailto:${email_setup.from_address}`" class="secretary-email-address">
                        {{ email_setup.from_address }}
                    </a>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        <template v-if="email_setup.server_name">{{ email_setup.server_name }} · </template>
                        Forwarded to the Postmark address below.
                    </p>
                    <details class="secretary-technical-address">
                        <summary>Show technical Postmark address</summary>
                        <code>{{ email_setup.inbound_address }}</code>
                    </details>
                </div>
                <ui-button
                    v-if="email_setup.can_configure"
                    size="sm"
                    @click="setupMode = 'postmark'; showSetup = true"
                >
                    Change setup
                </ui-button>
            </div>
        </section>

        <section v-else-if="email_setup.can_configure" class="secretary-settings-card">
            <div class="space-y-5 px-5 py-5">
                <div>
                    <h2 class="text-base font-bold">Connect email to Secretary</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">
                        Choose the shared address for the shortest setup, or use your own Postmark server.
                    </p>
                </div>

                <div
                    v-if="relay_setup.pairing_available"
                    class="secretary-setup-tabs grid gap-2 rounded-lg bg-gray-100 p-1 dark:bg-gray-800"
                    role="group"
                    aria-label="Choose email setup"
                >
                    <button
                        type="button"
                        :aria-pressed="setupMode === 'relay'"
                        class="secretary-setup-tab rounded-md px-3 py-2 text-sm font-semibold transition"
                        :class="setupMode === 'relay'
                            ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white'
                            : 'text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white'"
                        @click="setupMode = 'relay'"
                    >
                        Shared address
                        <span class="secretary-setup-recommended">Recommended</span>
                    </button>
                    <button
                        type="button"
                        :aria-pressed="setupMode === 'postmark'"
                        class="secretary-setup-tab rounded-md px-3 py-2 text-sm font-semibold transition"
                        :class="setupMode === 'postmark'
                            ? 'bg-white text-gray-950 shadow-sm dark:bg-gray-700 dark:text-white'
                            : 'text-gray-600 hover:text-gray-950 dark:text-gray-300 dark:hover:text-white'"
                        @click="setupMode = 'postmark'"
                    >
                        Your Postmark server
                    </button>
                </div>

                <ui-alert
                    v-if="setupError"
                    variant="error"
                    heading="Email could not be connected"
                    :text="setupError"
                />

                <div v-if="setupMode === 'relay' && relay_setup.pairing_available" class="space-y-5">
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Fastest setup: verify an existing Statamic user without creating your own Postmark server.
                    </p>
                    <div class="secretary-email-example">
                        <span>
                            <small>Your Secretary address</small>
                            <strong>{{ sharedAddressPreview }}</strong>
                        </span>
                        <ui-badge variant="success">Recommended</ui-badge>
                    </div>
                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                        You can also use <strong>secretary@statamic.no</strong> when the sender is connected to only one site.
                    </p>

                    <form class="rounded-lg border p-4 dark:border-gray-700" @submit.prevent="requestRelayCode">
                        <div class="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div>
                                <label for="secretary-relay-email" class="mb-1.5 block text-sm font-semibold">Authorized sender</label>
                                <input
                                    id="secretary-relay-email"
                                    v-model="relayEmail"
                                    class="secretary-settings-input w-full"
                                    type="email"
                                    autocomplete="email"
                                    placeholder="redaktor@example.com"
                                    required
                                >
                                <p class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                    The address must belong to a Statamic user with access to Secretary.
                                </p>
                            </div>
                            <ui-button type="submit" :disabled="setupBusy || !relayEmail.trim()">
                                {{ setupBusy ? 'Sending …' : 'Send one-time code' }}
                            </ui-button>
                        </div>
                    </form>

                    <form class="space-y-4" @submit.prevent="connectRelay">
                        <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="secretary-pairing-code" class="mb-1.5 block text-sm font-semibold">One-time code</label>
                            <input
                                id="secretary-pairing-code"
                                v-model="pairingCode"
                                class="secretary-settings-input w-full font-mono"
                                type="text"
                                autocomplete="one-time-code"
                                autocapitalize="none"
                                spellcheck="false"
                                placeholder="pc_…"
                                required
                            >
                            <p v-if="relay_setup.pending_sender" class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                The code was sent to {{ relay_setup.pending_sender }} and is valid for 15 minutes.
                            </p>
                        </div>

                        <div>
                            <label for="secretary-relay-public-url" class="mb-1.5 block text-sm font-semibold">Site’s public HTTPS URL</label>
                            <input
                                id="secretary-relay-public-url"
                                v-model="relayPublicUrl"
                                class="secretary-settings-input w-full"
                                type="url"
                                inputmode="url"
                                placeholder="https://example.com"
                                required
                            >
                            <p v-if="!relay_setup.suggested_public_url" class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Local testing: paste the HTTPS URL from Herd Share. The relay cannot reach a <code>.test</code> address.
                            </p>
                        </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <ui-button type="submit" :disabled="setupBusy || !pairingCode.trim() || !relayPublicUrl.trim()">
                                {{ setupBusy ? 'Connecting …' : 'Connect shared address' }}
                            </ui-button>
                            <ui-button
                                v-if="emailConnected"
                                type="button"
                                variant="ghost"
                                @click="showSetup = false"
                            >
                                Cancel
                            </ui-button>
                            <span class="text-xs text-gray-500 dark:text-gray-400">The one-time code is never stored.</span>
                        </div>
                    </form>
                </div>

                <form v-else class="space-y-5" @submit.prevent="connectPostmark">
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Secretary finds the inbound address and registers the webhook automatically in your Postmark server.
                    </p>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div v-if="!email_setup.token_configured" class="md:col-span-2">
                            <label for="secretary-postmark-api-key" class="mb-1.5 block text-sm font-semibold">Postmark Server API Token</label>
                            <input
                                id="secretary-postmark-api-key"
                                v-model="postmarkApiKey"
                                class="secretary-settings-input w-full font-mono"
                                type="password"
                                autocomplete="off"
                                placeholder="Paste the token from your Postmark server"
                                required
                            >
                            <p class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Stored encrypted in this site’s database. It is never shown again.
                            </p>
                        </div>
                        <div>
                            <label for="secretary-email" class="mb-1.5 block text-sm font-semibold">Public email address</label>
                            <input
                                id="secretary-email"
                                v-model="emailAddress"
                                class="secretary-settings-input w-full"
                                type="email"
                                autocomplete="email"
                                placeholder="secretary@example.com"
                                required
                            >
                            <p class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                The address people will send instructions to. You create or forward this mailbox.
                            </p>
                        </div>

                        <div>
                            <label for="secretary-public-url" class="mb-1.5 block text-sm font-semibold">Site’s public HTTPS URL</label>
                            <input
                                id="secretary-public-url"
                                v-model="publicUrl"
                                class="secretary-settings-input w-full"
                                type="url"
                                inputmode="url"
                                placeholder="https://example.com"
                                required
                            >
                            <p v-if="!email_setup.suggested_public_url" class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Local testing: paste the HTTPS URL from Herd Share. Postmark cannot reach a <code>.test</code> address.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <ui-button
                            type="submit"
                            :disabled="setupBusy || (!email_setup.token_configured && !postmarkApiKey.trim()) || !emailAddress.trim() || !publicUrl.trim()"
                        >
                            {{ setupBusy ? 'Connecting …' : 'Connect Postmark' }}
                        </ui-button>
                        <ui-button
                            v-if="emailConnected"
                            type="button"
                            variant="ghost"
                            @click="showSetup = false"
                        >
                            Cancel
                        </ui-button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Environment variables override a key saved here.</span>
                    </div>
                </form>
            </div>
        </section>

        <ui-alert
            v-else-if="!emailConnected"
            variant="warning"
            heading="Email setup required"
            text="An administrator with “configure secretary” permission must connect email."
        />

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="secretary-settings-card secretary-settings-disclosure">
                <details class="secretary-tools-panel">
                    <summary>
                        <span class="secretary-tools-panel-icon"><ui-icon name="edit" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <strong>Editorial guide</strong>
                            <small>Voice, audience, and terminology for each site</small>
                        </span>
                        <ui-badge class="secretary-disclosure-badge" variant="default">{{ style_guides.sites.length }} {{ style_guides.sites.length === 1 ? 'site' : 'sites' }}</ui-badge>
                        <span class="secretary-disclosure-action">
                            <span class="secretary-settings-action-open">Open</span>
                            <span class="secretary-settings-action-close">Close</span>
                            <ui-icon name="chevron-down" class="secretary-tools-chevron size-4" aria-hidden="true" />
                        </span>
                    </summary>

                    <form class="secretary-tools-panel-content space-y-4" @submit.prevent="saveGuide">
                        <div>
                            <label for="secretary-guide-site" class="mb-1.5 block text-sm font-semibold">Site</label>
                            <select id="secretary-guide-site" v-model="guideSite" class="secretary-settings-input w-full" :disabled="guideBusy">
                                <option v-for="site in style_guides.sites" :key="site.handle" :value="site.handle">
                                    {{ site.name }} · {{ site.handle }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="secretary-guide-audience" class="mb-1.5 block text-sm font-semibold">Audience</label>
                                <textarea
                                    id="secretary-guide-audience"
                                    v-model="guideForm.audience"
                                    class="secretary-settings-input min-h-24 w-full resize-y"
                                    maxlength="1000"
                                    placeholder="Who is this site for?"
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                            <div>
                                <label for="secretary-guide-voice" class="mb-1.5 block text-sm font-semibold">Voice and tone</label>
                                <textarea
                                    id="secretary-guide-voice"
                                    v-model="guideForm.voice"
                                    class="secretary-settings-input min-h-24 w-full resize-y"
                                    maxlength="2000"
                                    placeholder="For example: warm, clear, and direct."
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                            <div>
                                <label for="secretary-guide-terminology" class="mb-1.5 block text-sm font-semibold">Preferred terminology</label>
                                <textarea
                                    id="secretary-guide-terminology"
                                    v-model="guideForm.terminology"
                                    class="secretary-settings-input min-h-24 w-full resize-y"
                                    maxlength="3000"
                                    placeholder="Product names, preferred spellings, and specialist terms."
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                            <div>
                                <label for="secretary-guide-avoid" class="mb-1.5 block text-sm font-semibold">Avoid</label>
                                <textarea
                                    id="secretary-guide-avoid"
                                    v-model="guideForm.avoid"
                                    class="secretary-settings-input min-h-24 w-full resize-y"
                                    maxlength="3000"
                                    placeholder="Clichés, jargon, or phrases Secretary should not use."
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Config values are the defaults; Control Panel values override them for this site.
                            </p>
                            <ui-button
                                v-if="style_guides.can_configure"
                                type="submit"
                                size="sm"
                                :loading="guideBusy"
                                :disabled="guideBusy || !guideSite"
                            >
                                Save guide
                            </ui-button>
                        </div>
                    </form>
                </details>
            </section>

            <section class="secretary-settings-card secretary-settings-disclosure">
                <details class="secretary-tools-panel">
                    <summary>
                        <span class="secretary-tools-panel-icon"><ui-icon name="dashboard" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <strong>System status</strong>
                            <small>The same checks as <code>secretary:doctor</code></small>
                        </span>
                        <ui-badge class="secretary-disclosure-badge" :variant="diagnosticSummary.ready ? (diagnosticSummary.warnings ? 'warning' : 'success') : 'error'">
                            {{ diagnosticSummary.ready ? (diagnosticSummary.warnings ? `${diagnosticSummary.warnings} warnings` : 'All clear') : `${diagnosticSummary.blockers} errors` }}
                        </ui-badge>
                        <span class="secretary-disclosure-action">
                            <span class="secretary-settings-action-open">Open</span>
                            <span class="secretary-settings-action-close">Close</span>
                            <ui-icon name="chevron-down" class="secretary-tools-chevron size-4" aria-hidden="true" />
                        </span>
                    </summary>

                    <div class="secretary-tools-panel-content">
                        <ul class="secretary-diagnostics">
                            <li v-for="check in diagnostics.checks" :key="check.key">
                                <span class="secretary-diagnostic-icon" :class="check.passed ? 'is-ok' : check.required ? 'is-error' : 'is-warning'">
                                    <ui-icon :name="check.passed ? 'checkmark' : check.required ? 'x' : 'warning-diamond'" aria-hidden="true" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <strong>{{ check.label }}</strong>
                                    <small>{{ check.passed ? check.success_details : check.details }}</small>
                                </span>
                            </li>
                        </ul>
                        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                            CLI: <code>php please secretary:doctor --json</code>
                        </p>
                    </div>
                </details>
            </section>
        </div>
                </div>
            </details>
        </section>

        <ui-alert
            v-if="error"
            variant="error"
            heading="Secretary could not complete the request"
            :text="error"
        />

        <div class="secretary-workspace">
            <ui-panel class="secretary-conversation-panel overflow-hidden">
                <div class="secretary-conversation-panel-header">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold">Conversations</div>
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ conversations.length }}</span>
                    </div>
                    <div class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                        {{ email_enabled ? 'The same history in the Control Panel and email.' : 'Control Panel chat is ready.' }}
                    </div>
                    <button
                        type="button"
                        class="secretary-conversation-toggle"
                        :aria-expanded="conversationMenuOpen"
                        aria-controls="secretary-conversation-list"
                        @click="conversationMenuOpen = !conversationMenuOpen"
                    >
                        <span class="min-w-0 flex-1 text-left">
                            <span class="block text-[0.6875rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Selected conversation</span>
                            <span class="mt-0.5 block truncate text-sm font-semibold">{{ conversation?.title ?? 'Choose a conversation' }}</span>
                        </span>
                        <ui-icon
                            name="chevron-down"
                            class="size-4 shrink-0 transition-transform"
                            :class="{ 'rotate-180': conversationMenuOpen }"
                            aria-hidden="true"
                        />
                    </button>
                </div>

                <div
                    v-if="conversations.length > 5"
                    class="secretary-conversation-search"
                    :class="{ 'is-open': conversationMenuOpen }"
                >
                    <label for="secretary-conversation-search" class="sr-only">Search conversations</label>
                    <ui-icon name="search-magnifying-glass" class="size-4" aria-hidden="true" />
                    <input
                        id="secretary-conversation-search"
                        v-model="conversationQuery"
                        type="search"
                        class="secretary-conversation-search-input secretary-active-input"
                        placeholder="Search conversations"
                        autocomplete="off"
                    >
                </div>

                <nav
                    id="secretary-conversation-list"
                    class="secretary-conversation-list"
                    :class="{ 'is-open': conversationMenuOpen }"
                    aria-label="Secretary conversations"
                >
                    <Link
                        v-for="item in filteredConversations"
                        :key="item.id"
                        :href="item.url"
                        class="secretary-conversation-link"
                        :class="{ 'is-active': item.id === conversation?.id }"
                        :aria-current="item.id === conversation?.id ? 'page' : undefined"
                    >
                        <span class="line-clamp-2 min-w-0 text-sm font-semibold leading-5">{{ item.title }}</span>
                        <span class="secretary-conversation-meta">
                            <span>{{ channelLabel(item.channel) }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ relativeTime(item.updated_at) }}</span>
                        </span>
                    </Link>

                    <div v-if="!filteredConversations.length" class="px-3 py-8 text-center text-sm text-gray-500">
                        {{ conversations.length ? 'No conversations match your search.' : 'No conversations yet.' }}
                    </div>
                </nav>
            </ui-panel>

            <ui-panel class="secretary-main-panel flex min-w-0 flex-col overflow-hidden" :aria-busy="busy || processing">
                <template v-if="conversation">
                    <header class="secretary-main-header">
                        <div class="min-w-0">
                            <h2 class="line-clamp-2 text-base font-bold leading-6">{{ conversation.title }}</h2>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ channelLabel(conversation.channel) }} ·
                                {{ visibleMessages.length }} {{ visibleMessages.length === 1 ? 'message' : 'messages' }}
                            </p>
                        </div>
                        <span
                            class="secretary-status-pill"
                            :class="processing ? 'is-processing' : 'is-ready'"
                        >
                            <span class="secretary-status-dot" aria-hidden="true" />
                            {{ processing ? 'Secretary is working' : 'Ready' }}
                        </span>
                    </header>

                    <Link
                        v-if="conversation.context?.edit_url"
                        :href="conversation.context.edit_url"
                        class="secretary-context-bar"
                    >
                        <ui-icon name="entry" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.6875rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Working on</span>
                            <span class="block truncate text-sm font-semibold">
                                {{ conversation.context.title }}
                                <span v-if="conversation.context.uri" class="font-normal text-gray-500 dark:text-gray-400">· {{ conversation.context.uri }}</span>
                            </span>
                            <span v-if="conversation.context.field" class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                Active field: {{ conversation.context.field.display }}
                                <template v-if="conversation.context.field.set_type"> · {{ conversation.context.field.set_type }}</template>
                            </span>
                        </span>
                        <ui-icon name="arrow-up-right" class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                    </Link>

                    <div v-if="conversation.processing_error" class="secretary-action-error m-4 mb-0" role="alert">
                        <div class="font-semibold">Secretary stopped</div>
                        <div class="mt-1">{{ conversation.processing_error }}</div>
                        <button
                            v-if="conversation.failed_message_body"
                            type="button"
                            class="secretary-error-action"
                            @click="reuseFailedMessage"
                        >
                            Put the request back in the composer
                        </button>
                    </div>

                    <div ref="feed" class="secretary-scrollbar flex-1 space-y-6 overflow-y-auto px-4 py-6 sm:px-6" role="log" aria-label="Secretary conversation">
                        <div v-if="!visibleMessages.length" class="mx-auto max-w-xl py-10 text-center">
                            <div class="secretary-empty-icon mx-auto">
                                <ui-icon name="ai-chat-spark" aria-hidden="true" />
                            </div>
                            <div class="mt-4 text-base font-bold">What should we change?</div>
                            <p class="secretary-empty-lead mx-auto max-w-md text-sm leading-6 text-gray-500 dark:text-gray-400">
                                Write as you would to a colleague. Secretary reads the content model and existing content before preparing a draft.
                            </p>
                            <div class="secretary-empty-suggestions mx-auto grid max-w-lg gap-2 text-left">
                                <button
                                    v-for="suggestion in promptSuggestions"
                                    :key="suggestion"
                                    type="button"
                                    class="secretary-prompt-suggestion"
                                    @click="useSuggestion(suggestion)"
                                >
                                    <span>{{ suggestion }}</span>
                                    <ui-icon name="arrow-right" class="size-4 shrink-0" aria-hidden="true" />
                                </button>
                            </div>
                        </div>

                        <details v-if="previousChanges.length" class="secretary-history">
                            <summary>
                                Previous changes
                                <span>{{ previousChanges.length }}</span>
                            </summary>
                            <SecretaryChangeList
                                class="mt-3"
                                :changes="previousChanges"
                                label="History"
                                :can-publish="can_publish"
                                :processing="processing"
                                :preview-loading="previewLoading"
                                :reviewing-target="reviewingTarget"
                                @review="review"
                                @preview="openPreview"
                                @publish="requestPublish"
                            />
                        </details>

                        <template v-for="item in visibleMessages" :key="item.id">
                            <div
                                v-if="item.presentation === 'system'"
                                class="secretary-system-event"
                                role="status"
                            >
                                <ui-icon name="checkmark" class="size-4 shrink-0" aria-hidden="true" />
                                <span>{{ item.body }}</span>
                            </div>
                            <article
                                v-else
                                class="secretary-message-row"
                                :class="{ 'is-user': item.role === 'user' }"
                            >
                                <div v-if="item.role !== 'user'" class="secretary-assistant-mark" aria-hidden="true">
                                    <ui-icon name="ai-spark" />
                                </div>
                                <div class="min-w-0" :class="item.role === 'user' ? 'max-w-[82%] sm:max-w-[72%]' : 'max-w-[88%] sm:max-w-[78%]'">
                                    <div v-if="item.role !== 'user'" class="mb-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                        Secretary
                                        <span v-if="item.channel === 'email'" class="font-normal">· via email</span>
                                    </div>
                                    <div
                                        class="secretary-message rounded-xl px-4 py-3 text-sm leading-6"
                                        :class="item.role === 'user'
                                            ? 'rounded-br-sm bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-950'
                                            : 'rounded-bl-sm border bg-white text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'"
                                    >
                                        {{ item.body }}
                                        <div v-if="item.pending" class="mt-2 flex items-center gap-2 text-xs font-semibold opacity-70">
                                            <span v-if="item.queue_position > 1" class="secretary-queue-position" aria-hidden="true">
                                                {{ item.queue_position }}
                                            </span>
                                            <ui-icon v-else name="checkmark" class="size-3.5" aria-hidden="true" />
                                            {{ item.queue_position > 1 ? `Queued · #${item.queue_position}` : 'Sent' }}
                                        </div>
                                        <DeveloperTrace
                                            v-if="developer_mode && item.metadata?.developer_trace"
                                            :trace="item.metadata.developer_trace"
                                        />
                                    </div>
                                </div>
                            </article>

                            <SecretaryChangeList
                                v-if="item.id === latestChangeMessageId"
                                :changes="changesForMessage(item)"
                                label="Result"
                                :can-publish="can_publish"
                                :processing="processing"
                                :preview-loading="previewLoading"
                                :reviewing-target="reviewingTarget"
                                @review="review"
                                @preview="openPreview"
                                @publish="requestPublish"
                            />
                        </template>

                        <div v-if="processing" class="secretary-processing-card" role="status">
                            <span class="secretary-working-dots" aria-hidden="true"><i /><i /><i /></span>
                            <span class="min-w-0">
                                <strong>{{ processingStatus }}</strong>
                                <span v-if="pendingMessages.length > 1" class="block text-xs opacity-75">
                                    {{ pendingMessages.length - 1 }} {{ pendingMessages.length === 2 ? 'request is' : 'requests are' }} waiting
                                </span>
                            </span>
                        </div>

                    </div>

                    <form class="secretary-composer relative p-4 sm:p-5" @submit.prevent="send">
                        <label for="secretary-message" class="sr-only">Message to Secretary</label>
                        <textarea
                            id="secretary-message"
                            ref="composerInput"
                            v-model="message"
                            rows="1"
                            class="secretary-composer-input secretary-active-input w-full resize-none"
                            placeholder="Ask Secretary to make a change …"
                            :maxlength="max_input_characters"
                            :disabled="busy || !configured"
                            @keydown="onComposerKeydown"
                            @input="resizeComposer"
                        />
                        <div
                            v-if="references.length || referenceLoading"
                            class="secretary-reference-menu"
                            role="listbox"
                            aria-label="Reference content"
                        >
                            <div v-if="referenceLoading && !references.length" class="px-3 py-2 text-xs text-gray-500" role="status">
                                Finding content …
                            </div>
                            <button
                                v-for="reference in references"
                                :key="reference.id"
                                type="button"
                                role="option"
                                @click="insertReference(reference)"
                            >
                                <ui-icon name="entry" class="size-4 shrink-0" aria-hidden="true" />
                                <span class="min-w-0 flex-1">
                                    <strong>{{ reference.title || reference.slug }}</strong>
                                    <small>{{ reference.uri }} · {{ reference.collection }}</small>
                                </span>
                            </button>
                        </div>
                        <div class="secretary-composer-footer">
                            <div class="flex min-w-0 items-center gap-2">
                                <button
                                    type="button"
                                    class="secretary-composer-tool"
                                    title="Reference Statamic content"
                                    aria-label="Reference Statamic content"
                                    :disabled="busy || !configured"
                                    @click="startReference"
                                >
                                    @
                                </button>
                                <span class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    <template v-if="message.length > max_input_characters * 0.8">{{ message.length }}/{{ max_input_characters }} · </template>
                                    Draft first · Reference content · ⌘/Ctrl + Enter
                                </span>
                            </div>
                            <ui-button
                                variant="primary"
                                type="submit"
                                :loading="busy && !publishCandidate"
                                :disabled="busy || !configured || !message.trim()"
                            >
                                {{ processing ? `Queue #${pendingMessages.length + 1}` : 'Send' }}
                            </ui-button>
                        </div>
                    </form>
                </template>

                <div v-else class="grid flex-1 place-items-center px-6 py-20 text-center">
                    <div class="max-w-md">
                        <div class="secretary-empty-icon mx-auto">
                            <ui-icon name="ai-chat-spark" aria-hidden="true" />
                        </div>
                        <h2 class="mt-4 text-lg font-bold">Secretary is ready</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            Start with a plain-language request. Secretary checks the site’s actual structure before preparing anything.
                        </p>
                        <ui-button class="mt-5" variant="primary" icon="plus" :loading="busy" :disabled="busy" @click="newConversation">
                            Start a conversation
                        </ui-button>
                    </div>
                </div>
            </ui-panel>
        </div>
    </div>

    <ui-modal
        :open="Boolean(publishCandidate)"
        title="Publish this change?"
        icon="checkmark"
        @update:open="value => { if (!value && !busy) publishCandidate = null }"
    >
        <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
            <strong class="text-gray-950 dark:text-white">{{ publishCandidate?.summary }}</strong>
            will become visible on the site. This is the only action here that affects published content.
        </p>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <ui-button variant="ghost" :disabled="busy" @click="publishCandidate = null">Not yet</ui-button>
                <ui-button variant="primary" :loading="busy" :disabled="busy" @click="publish">Publish</ui-button>
            </div>
        </template>
    </ui-modal>

    <ChangePreviewModal
        :open="previewOpen"
        :preview="previewData"
        :loading="previewLoading"
        :error="previewError"
        @close="closePreview"
    />
</template>
