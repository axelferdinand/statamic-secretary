<script setup>
import { Head, Link, router, usePoll } from '@statamic/cms/inertia';
import { computed, getCurrentInstance, nextTick, onMounted, ref, watch } from 'vue';
import ChangePreviewModal from '../components/ChangePreviewModal.vue';
import ChangeReview from '../components/ChangeReview.vue';
import DeveloperTrace from '../components/DeveloperTrace.vue';

const props = defineProps({
    conversations: { type: Array, required: true },
    conversation: { type: Object, default: null },
    can_publish: { type: Boolean, required: true },
    configured: { type: Boolean, required: true },
    email_enabled: { type: Boolean, required: true },
    email_setup: { type: Object, required: true },
    relay_setup: { type: Object, required: true },
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
const busy = ref(false);
const setupBusy = ref(false);
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
const actionError = ref(null);
const error = computed(() => props.errors?.secretary ?? actionError.value ?? null);
const setupError = computed(() => props.errors?.relay_setup
    ?? props.errors?.pairing_code
    ?? props.errors?.relay_email
    ?? props.errors?.postmark_setup
    ?? props.errors?.email
    ?? props.errors?.public_url
    ?? null);
const processing = computed(() => props.conversation?.processing === true);
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
            `Forbedre teksten i ${field.set_type ? `${field.set_type}-modulen` : field.display}.`,
            'Gjør denne modulen kortere uten å endre resten av siden.',
            'Foreslå en bedre rekkefølge på innholdsmodulene.',
        ];
    }

    if (field) {
        return [
            `Gjør feltet «${field.display}» tydeligere.`,
            `Korrekturles bare «${field.display}».`,
            `Lag tre alternative forslag til «${field.display}».`,
        ];
    }

    return props.conversation?.context ? [
        'Gjør ingressen tydeligere og kortere.',
        'Finn språklige feil på denne siden.',
        'Lag et bedre forslag til sidetittel.',
    ] : [
    'Gjør ingressen på forsiden tydeligere.',
    'Finn siden «Om oss» og foreslå en bedre tittel.',
    'Lag et utkast til en ny kontaktside.',
    ];
});
const { start: startPolling, stop: stopPolling } = usePoll(2000, {
    only: ['conversation', 'conversations'],
    preserveScroll: true,
}, { autoStart: false });
let pollingMounted = false;

function newConversation() {
    router.post(props.endpoints.create, {}, { onStart: () => busy.value = true, onFinish: () => busy.value = false });
}

function connectPostmark() {
    if (!props.email_setup.token_configured || !emailAddress.value.trim() || !publicUrl.value.trim() || setupBusy.value) return;

    router.post(props.email_setup.setup_url, {
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
        actionError.value = responseError(exception, 'Secretary kunne ikke oppdatere feltvalget.');
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
        previewError.value = responseError(exception, 'Forhåndsvisningen kunne ikke åpnes.');
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

function statusVariant(status) {
    return ({ draft: 'warning', published: 'success', failed: 'error' })[status] ?? 'default';
}

function statusLabel(status) {
    return ({
        proposed: 'Foreslått',
        draft: 'Klar som utkast',
        published: 'Publisert',
        failed: 'Kunne ikke lagres',
    })[status] ?? status;
}

function changedFields(change) {
    return change.resource_type === 'navigation'
        ? ['komplett navigasjonstre']
        : Object.keys(change.patch ?? {});
}

function resourceLabel(type) {
    return ({
        entry: 'Side eller innlegg',
        term: 'Taksonomibegrep',
        global: 'Globalt innhold',
        navigation: 'Navigasjon',
    })[type] ?? type;
}

function channelLabel(channel) {
    return channel === 'email' ? 'E-post' : 'Kontrollpanel';
}

function fieldLabel(handle) {
    const known = {
        title: 'Tittel',
        content: 'Innhold',
        intro: 'Ingress',
        description: 'Beskrivelse',
        bard: 'Innhold',
        slug: 'URL-segment',
    };

    if (known[handle]) return known[handle];

    const label = String(handle).replaceAll('_', ' ');

    return label.charAt(0).toUpperCase() + label.slice(1);
}

function relativeTime(value) {
    if (!value) return '';

    const date = new Date(value);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat('nb', { numeric: 'auto' });
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

function nodeCount(branches = []) {
    return branches.reduce((total, branch) => total + 1 + nodeCount(branch.children ?? []), 0);
}

function changeValues(change) {
    if (change.resource_type === 'navigation') {
        return [{
            field: `tree (${nodeCount(change.before?.tree)} → ${nodeCount(change.after?.tree)} navigasjonspunkter)`,
            before: change.before?.tree ?? [],
            after: change.after?.tree ?? [],
        }];
    }

    const before = change.before?.data ?? {};
    const after = change.after?.data ?? {};

    return Object.keys(change.patch ?? {}).map(field => ({
        field,
        before: before[field],
        after: after[field],
    }));
}

function formatValue(value) {
    if (value === undefined || value === null || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Ja' : 'Nei';
    if (typeof value === 'string') return value.length > 4000 ? `${value.slice(0, 4000)}…` : value;

    const json = JSON.stringify(value, null, 2);
    return json.length > 4000 ? `${json.slice(0, 4000)}…` : json;
}

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
watch(() => props.email_setup.connected, connected => {
    if (connected) showSetup.value = false;
});
watch(() => props.relay_setup.connected, connected => {
    if (connected) showSetup.value = false;
});
watch(guideSite, loadGuide);
</script>

<template>
    <Head title="Secretary" />

    <ui-header title="Secretary">
        <template #description>
            Be om en endring. Secretary gjør grunnarbeidet; du ser over og publiserer.
        </template>
        <template #actions>
            <ui-button icon="plus" :loading="busy" :disabled="busy" @click="newConversation">
                Ny samtale
            </ui-button>
        </template>
    </ui-header>

    <div class="space-y-4">
        <ui-alert
            v-if="!configured"
            variant="warning"
            heading="OpenAI er ikke konfigurert"
            text="Legg OPENAI_API_KEY i miljøet før Secretary kan behandle meldinger. Nøkkelen vises eller lagres aldri her."
        />

        <ui-alert
            v-if="success"
            variant="success"
            heading="Klart"
            :text="success"
        />

        <ui-alert
            v-if="!emailConnected && !email_setup.token_configured && !relay_setup.pairing_available"
            variant="warning"
            heading="E-post er ikke koblet til"
            text="Legg POSTMARK_API_KEY i miljøet, eller aktiver Secretary-fellesadressen. Deretter kan en administrator fullføre oppsettet her."
        />

        <ui-panel v-if="relay_setup.connected && !showSetup" class="overflow-hidden">
            <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <ui-badge variant="success">E-post klar</ui-badge>
                        <span class="truncate text-sm text-gray-500 dark:text-gray-400">Fellesadresse</span>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200">
                        Send instruksjoner til
                        <strong class="break-all">{{ relay_setup.address }}</strong>.
                        Secretary svarer fra samme adresse og holder samtalen samlet
                        <template v-if="relay_setup.sender">
                            for <strong>{{ relay_setup.sender }}</strong>
                        </template>.
                    </p>
                    <p v-if="relay_setup.route_address" class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                        Hvis samme avsender senere kobles til flere nettsteder, kan du bruke nettstedets unike adresse:
                        <code class="break-all">{{ relay_setup.route_address }}</code>.
                    </p>
                </div>
                <ui-button
                    v-if="relay_setup.can_configure"
                    variant="ghost"
                    size="sm"
                    @click="setupMode = 'relay'; showSetup = true"
                >
                    Endre oppsett
                </ui-button>
            </div>
        </ui-panel>

        <ui-panel v-else-if="email_setup.connected && !showSetup" class="overflow-hidden">
            <div class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <ui-badge variant="success">E-post klar</ui-badge>
                        <span v-if="email_setup.server_name" class="truncate text-sm text-gray-500 dark:text-gray-400">
                            {{ email_setup.server_name }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-200">
                        Videresend
                        <strong>{{ email_setup.from_address }}</strong>
                        til
                        <code class="break-all rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-800">{{ email_setup.inbound_address }}</code>.
                    </p>
                </div>
                <ui-button
                    v-if="email_setup.can_configure"
                    variant="ghost"
                    size="sm"
                    @click="setupMode = 'postmark'; showSetup = true"
                >
                    Endre oppsett
                </ui-button>
            </div>
        </ui-panel>

        <ui-panel v-else-if="email_setup.can_configure" class="overflow-hidden">
            <div class="space-y-5 px-5 py-5">
                <div>
                    <h2 class="text-base font-bold">Koble e-post til Secretary</h2>
                    <p class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">
                        Velg fellesadressen for kortest oppsett, eller bruk din egen Postmark-server.
                    </p>
                </div>

                <div
                    v-if="relay_setup.pairing_available"
                    class="secretary-setup-tabs grid gap-2 rounded-lg bg-gray-100 p-1 dark:bg-gray-800"
                    role="group"
                    aria-label="Velg e-postoppsett"
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
                        Fellesadresse
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
                        Egen Postmark-server
                    </button>
                </div>

                <ui-alert
                    v-if="setupError"
                    variant="error"
                    heading="E-post kunne ikke kobles til"
                    :text="setupError"
                />

                <div v-if="setupMode === 'relay' && relay_setup.pairing_available" class="space-y-5">
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Verifiser en eksisterende Statamic-bruker. Deretter kan brukeren sende instruksjoner direkte til
                        <strong>secretary@statamic.no</strong>, uten egen Postmark-server.
                    </p>

                    <form class="rounded-lg border p-4 dark:border-gray-700" @submit.prevent="requestRelayCode">
                        <div class="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                            <div>
                                <label for="secretary-relay-email" class="mb-1.5 block text-sm font-semibold">Godkjent avsender</label>
                                <input
                                    id="secretary-relay-email"
                                    v-model="relayEmail"
                                    class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-800 dark:focus:ring-blue-900"
                                    type="email"
                                    autocomplete="email"
                                    placeholder="redaktor@example.com"
                                    required
                                >
                                <p class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                    Adressen må tilhøre en Statamic-bruker med tilgang til Secretary.
                                </p>
                            </div>
                            <ui-button type="submit" :disabled="setupBusy || !relayEmail.trim()">
                                {{ setupBusy ? 'Sender …' : 'Send engangskode' }}
                            </ui-button>
                        </div>
                    </form>

                    <form class="space-y-4" @submit.prevent="connectRelay">
                        <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="secretary-pairing-code" class="mb-1.5 block text-sm font-semibold">Engangskode</label>
                            <input
                                id="secretary-pairing-code"
                                v-model="pairingCode"
                                class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-800 dark:focus:ring-blue-900"
                                type="text"
                                autocomplete="one-time-code"
                                autocapitalize="none"
                                spellcheck="false"
                                placeholder="pc_…"
                                required
                            >
                            <p v-if="relay_setup.pending_sender" class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Koden ble sendt til {{ relay_setup.pending_sender }} og er gyldig i 15 minutter.
                            </p>
                        </div>

                        <div>
                            <label for="secretary-relay-public-url" class="mb-1.5 block text-sm font-semibold">Nettstedets offentlige HTTPS-adresse</label>
                            <input
                                id="secretary-relay-public-url"
                                v-model="relayPublicUrl"
                                class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-800 dark:focus:ring-blue-900"
                                type="url"
                                inputmode="url"
                                placeholder="https://example.com"
                                required
                            >
                            <p v-if="!relay_setup.suggested_public_url" class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Lokal test: lim inn HTTPS-adressen fra Herd Share. Relayet kan ikke nå en <code>.test</code>-adresse.
                            </p>
                        </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <ui-button type="submit" :disabled="setupBusy || !pairingCode.trim() || !relayPublicUrl.trim()">
                                {{ setupBusy ? 'Kobler til …' : 'Koble til fellesadressen' }}
                            </ui-button>
                            <ui-button
                                v-if="emailConnected"
                                type="button"
                                variant="ghost"
                                @click="showSetup = false"
                            >
                                Avbryt
                            </ui-button>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Engangskoden lagres aldri.</span>
                        </div>
                    </form>
                </div>

                <form v-else class="space-y-5" @submit.prevent="connectPostmark">
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Secretary finner inbound-adressen og registrerer webhooken automatisk i din Postmark-server.
                    </p>

                    <ui-alert
                        v-if="!email_setup.token_configured"
                        variant="warning"
                        heading="Postmark-nøkkelen mangler"
                        text="Legg POSTMARK_API_KEY i miljøet før du kobler til din egen server."
                    />

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="secretary-email" class="mb-1.5 block text-sm font-semibold">Secretary-adresse</label>
                            <input
                                id="secretary-email"
                                v-model="emailAddress"
                                class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-800 dark:focus:ring-blue-900"
                                type="email"
                                autocomplete="email"
                                placeholder="secretary@example.com"
                                required
                            >
                        </div>

                        <div>
                            <label for="secretary-public-url" class="mb-1.5 block text-sm font-semibold">Nettstedets offentlige HTTPS-adresse</label>
                            <input
                                id="secretary-public-url"
                                v-model="publicUrl"
                                class="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 dark:border-gray-600 dark:bg-gray-800 dark:focus:ring-blue-900"
                                type="url"
                                inputmode="url"
                                placeholder="https://example.com"
                                required
                            >
                            <p v-if="!email_setup.suggested_public_url" class="mt-1.5 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Lokal test: lim inn HTTPS-adressen fra Herd Share. Postmark kan ikke nå en <code>.test</code>-adresse.
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <ui-button
                            type="submit"
                            :disabled="setupBusy || !email_setup.token_configured || !emailAddress.trim() || !publicUrl.trim()"
                        >
                            {{ setupBusy ? 'Kobler til …' : 'Koble til Postmark' }}
                        </ui-button>
                        <ui-button
                            v-if="emailConnected"
                            type="button"
                            variant="ghost"
                            @click="showSetup = false"
                        >
                            Avbryt
                        </ui-button>
                        <span class="text-xs text-gray-500 dark:text-gray-400">API-nøkkelen vises eller lagres aldri her.</span>
                    </div>
                </form>
            </div>
        </ui-panel>

        <ui-alert
            v-else-if="!emailConnected"
            variant="warning"
            heading="E-post venter på oppsett"
            text="En administrator med «configure secretary» må koble til e-post."
        />

        <div class="grid gap-4 xl:grid-cols-2">
            <ui-panel class="overflow-hidden">
                <details class="secretary-tools-panel">
                    <summary>
                        <span class="secretary-tools-panel-icon"><ui-icon name="edit" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <strong>Redaksjonell guide</strong>
                            <small>Fast tone, målgruppe og terminologi per nettsted</small>
                        </span>
                        <ui-badge variant="default">{{ style_guides.sites.length }} {{ style_guides.sites.length === 1 ? 'nettsted' : 'nettsteder' }}</ui-badge>
                        <ui-icon name="chevron-down" class="secretary-tools-chevron size-4" aria-hidden="true" />
                    </summary>

                    <form class="secretary-tools-panel-content space-y-4" @submit.prevent="saveGuide">
                        <div>
                            <label for="secretary-guide-site" class="mb-1.5 block text-sm font-semibold">Nettsted</label>
                            <select id="secretary-guide-site" v-model="guideSite" class="input-text w-full" :disabled="guideBusy">
                                <option v-for="site in style_guides.sites" :key="site.handle" :value="site.handle">
                                    {{ site.name }} · {{ site.handle }}
                                </option>
                            </select>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="secretary-guide-audience" class="mb-1.5 block text-sm font-semibold">Målgruppe</label>
                                <textarea
                                    id="secretary-guide-audience"
                                    v-model="guideForm.audience"
                                    class="input-text min-h-24 w-full resize-y"
                                    maxlength="1000"
                                    placeholder="Hvem skriver nettstedet for?"
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                            <div>
                                <label for="secretary-guide-voice" class="mb-1.5 block text-sm font-semibold">Stemme og tone</label>
                                <textarea
                                    id="secretary-guide-voice"
                                    v-model="guideForm.voice"
                                    class="input-text min-h-24 w-full resize-y"
                                    maxlength="2000"
                                    placeholder="For eksempel: varm, tydelig og direkte."
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                            <div>
                                <label for="secretary-guide-terminology" class="mb-1.5 block text-sm font-semibold">Foretrukne ord</label>
                                <textarea
                                    id="secretary-guide-terminology"
                                    v-model="guideForm.terminology"
                                    class="input-text min-h-24 w-full resize-y"
                                    maxlength="3000"
                                    placeholder="Produktnavn, skrivemåter og faguttrykk."
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                            <div>
                                <label for="secretary-guide-avoid" class="mb-1.5 block text-sm font-semibold">Unngå</label>
                                <textarea
                                    id="secretary-guide-avoid"
                                    v-model="guideForm.avoid"
                                    class="input-text min-h-24 w-full resize-y"
                                    maxlength="3000"
                                    placeholder="Klisjeer, sjargong eller formuleringer Secretary ikke skal bruke."
                                    :readonly="!style_guides.can_configure"
                                />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                Config-verdier er standard; CP-verdier overstyrer dem for dette nettstedet.
                            </p>
                            <ui-button
                                v-if="style_guides.can_configure"
                                type="submit"
                                size="sm"
                                :loading="guideBusy"
                                :disabled="guideBusy || !guideSite"
                            >
                                Lagre guide
                            </ui-button>
                        </div>
                    </form>
                </details>
            </ui-panel>

            <ui-panel class="overflow-hidden">
                <details class="secretary-tools-panel">
                    <summary>
                        <span class="secretary-tools-panel-icon"><ui-icon name="dashboard" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <strong>Systemstatus</strong>
                            <small>Samme kontroller som <code>secretary:doctor</code></small>
                        </span>
                        <ui-badge :variant="diagnosticSummary.ready ? (diagnosticSummary.warnings ? 'warning' : 'success') : 'error'">
                            {{ diagnosticSummary.ready ? (diagnosticSummary.warnings ? `${diagnosticSummary.warnings} varsler` : 'Alt klart') : `${diagnosticSummary.blockers} feil` }}
                        </ui-badge>
                        <ui-icon name="chevron-down" class="secretary-tools-chevron size-4" aria-hidden="true" />
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
            </ui-panel>
        </div>

        <ui-alert
            v-if="error"
            variant="error"
            heading="Secretary kunne ikke fullføre"
            :text="error"
        />

        <div class="secretary-workspace grid min-h-[38rem] gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <ui-panel class="h-fit overflow-hidden lg:sticky lg:top-4">
                <div class="border-b px-4 py-3.5 dark:border-gray-700">
                    <div class="flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold">Samtaler</div>
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ conversations.length }}</span>
                    </div>
                    <div class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                        {{ email_enabled ? 'Samme historikk i kontrollpanelet og på e-post.' : 'Chat i kontrollpanelet er klar.' }}
                    </div>
                </div>

                <nav class="secretary-conversation-list flex max-w-full gap-2 overflow-x-auto p-2 lg:block lg:max-h-[34rem] lg:space-y-1" aria-label="Secretary-samtaler">
                    <Link
                        v-for="item in conversations"
                        :key="item.id"
                        :href="item.url"
                        class="secretary-conversation-link block min-w-64 rounded-lg px-3 py-2.5 text-sm transition lg:min-w-0"
                        :class="{ 'is-active': item.id === conversation?.id }"
                    >
                        <span class="line-clamp-2 min-w-0 font-semibold leading-5">{{ item.title }}</span>
                        <span class="mt-1 flex items-center gap-1.5 text-[0.6875rem] text-gray-500 dark:text-gray-400">
                            <span>{{ channelLabel(item.channel) }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ relativeTime(item.updated_at) }}</span>
                        </span>
                    </Link>

                    <div v-if="!conversations.length" class="px-3 py-6 text-center text-sm text-gray-500">
                        Ingen samtaler ennå.
                    </div>
                </nav>
            </ui-panel>

            <ui-panel class="secretary-main-panel flex min-w-0 flex-col overflow-hidden" :aria-busy="busy || processing">
                <template v-if="conversation">
                    <header class="secretary-main-header">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-bold">{{ conversation.title }}</h2>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ channelLabel(conversation.channel) }} · Publisert innhold endres først når du godkjenner.
                            </p>
                        </div>
                        <span
                            class="secretary-status-pill"
                            :class="processing ? 'is-processing' : 'is-ready'"
                        >
                            <span class="secretary-status-dot" aria-hidden="true" />
                            {{ processing ? 'Secretary jobber' : 'Klar' }}
                        </span>
                    </header>

                    <Link
                        v-if="conversation.context?.edit_url"
                        :href="conversation.context.edit_url"
                        class="secretary-context-bar"
                    >
                        <ui-icon name="entry" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.6875rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Denne samtalen gjelder</span>
                            <span class="block truncate text-sm font-semibold">
                                {{ conversation.context.title }}
                                <span v-if="conversation.context.uri" class="font-normal text-gray-500 dark:text-gray-400">· {{ conversation.context.uri }}</span>
                            </span>
                            <span v-if="conversation.context.field" class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                Aktivt felt: {{ conversation.context.field.display }}
                                <template v-if="conversation.context.field.set_type"> · {{ conversation.context.field.set_type }}</template>
                            </span>
                        </span>
                        <ui-icon name="arrow-up-right" class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                    </Link>

                    <ui-alert
                        v-if="conversation.processing_error"
                        class="m-4 mb-0"
                        variant="error"
                        heading="Secretary stoppet underveis"
                        :text="conversation.processing_error"
                    />

                    <div ref="feed" class="secretary-scrollbar flex-1 space-y-6 overflow-y-auto px-4 py-6 sm:px-6" role="log" aria-label="Secretary-samtale">
                        <div v-if="!conversation.messages.length" class="mx-auto max-w-xl py-10 text-center">
                            <div class="secretary-empty-icon mx-auto">
                                <ui-icon name="ai-chat-spark" aria-hidden="true" />
                            </div>
                            <div class="mt-4 text-base font-bold">Hva skal vi fikse?</div>
                            <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500 dark:text-gray-400">
                                Skriv som du ville gjort til en kollega. Secretary leser innholdsstrukturen og eksisterende innhold før den lager et utkast.
                            </p>
                            <div class="mx-auto mt-6 grid max-w-lg gap-2 text-left">
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

                        <article
                            v-for="item in conversation.messages"
                            :key="item.id"
                            class="secretary-message-row"
                            :class="{ 'is-user': item.role === 'user' }"
                        >
                            <div v-if="item.role !== 'user'" class="secretary-assistant-mark" aria-hidden="true">
                                <ui-icon name="ai-spark" />
                            </div>
                            <div class="min-w-0" :class="item.role === 'user' ? 'max-w-[82%] sm:max-w-[72%]' : 'max-w-[88%] sm:max-w-[78%]'">
                                <div v-if="item.role !== 'user'" class="mb-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                    Secretary
                                    <span v-if="item.channel === 'email'" class="font-normal">· via e-post</span>
                                </div>
                                <div
                                    class="secretary-message rounded-xl px-4 py-3 text-sm leading-6"
                                    :class="item.role === 'user'
                                        ? 'rounded-br-sm bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-950'
                                        : 'rounded-bl-sm border bg-white text-gray-900 shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'"
                                >
                                    {{ item.body }}
                                    <div v-if="item.pending" class="mt-2 flex items-center gap-2 text-xs font-semibold opacity-70">
                                        <span class="secretary-working-dots" aria-hidden="true"><i /><i /><i /></span>
                                        {{ item.queue_position > 1 ? `I kø · nummer ${item.queue_position}` : 'Sendt til Secretary' }}
                                    </div>
                                    <DeveloperTrace
                                        v-if="developer_mode && item.metadata?.developer_trace"
                                        :trace="item.metadata.developer_trace"
                                    />
                                </div>
                            </div>
                        </article>

                        <div v-if="processing" class="secretary-processing-card" role="status">
                            <span class="secretary-working-dots" aria-hidden="true"><i /><i /><i /></span>
                            <span>
                                <strong>Secretary jobber.</strong>
                                Leser innhold, kontrollerer feltene og klargjør et trygt utkast.
                            </span>
                        </div>

                        <section v-if="conversation.changes.length" class="space-y-3 pt-2" aria-label="Secretary-endringer">
                            <div>
                                <h3 class="text-sm font-bold">
                                    {{ conversation.changes.length === 1 ? 'Klargjort endring' : `${conversation.changes.length} klargjorte endringer` }}
                                </h3>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Kontroller detaljene. Publiser bare når du er fornøyd.</p>
                            </div>

                            <article
                                v-for="change in conversation.changes"
                                :key="change.id"
                                class="secretary-change-card p-4 sm:p-5"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="break-words font-semibold">{{ change.summary || `${change.operation} ${change.slug || change.entry_id}` }}</div>
                                        <div class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            {{ resourceLabel(change.resource_type) }} · {{ change.collection }} · {{ change.site }}
                                            <span v-if="changedFields(change).length"> · {{ changedFields(change).map(fieldLabel).join(', ') }}</span>
                                        </div>
                                    </div>
                                    <ui-badge :variant="statusVariant(change.status)">{{ statusLabel(change.status) }}</ui-badge>
                                </div>

                                <p v-if="change.failure" class="mt-3 text-sm text-red-700 dark:text-red-300">{{ change.failure }}</p>

                                <details v-if="changeValues(change).length" class="secretary-diff mt-4">
                                    <summary class="cursor-pointer px-3 py-2.5 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2">
                                        Se hva som ble endret
                                    </summary>
                                    <dl class="space-y-4 border-t p-3 dark:border-gray-700">
                                        <div v-for="item in changeValues(change)" :key="item.field" class="min-w-0">
                                            <dt class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ item.field }}</dt>
                                            <dd class="grid gap-2 md:grid-cols-2">
                                                <div class="min-w-0 rounded-md bg-gray-100 p-3 dark:bg-gray-900">
                                                    <div class="mb-1 text-xs font-semibold text-gray-500">Før</div>
                                                    <pre class="secretary-value">{{ formatValue(item.before) }}</pre>
                                                </div>
                                                <div class="min-w-0 rounded-md bg-blue-50 p-3 dark:bg-blue-950/30">
                                                    <div class="mb-1 text-xs font-semibold text-gray-500">Etter</div>
                                                    <pre class="secretary-value">{{ formatValue(item.after) }}</pre>
                                                </div>
                                            </dd>
                                        </div>
                                    </dl>
                                </details>

                                <ChangeReview
                                    :change="change"
                                    :busy-target="reviewingTarget"
                                    @decide="(target, decision) => review(change, target, decision)"
                                />

                                <div v-if="change.native_url || change.preview_available || (change.status === 'draft' && can_publish)" class="mt-4 flex flex-wrap items-center gap-2 border-t pt-4 dark:border-gray-700">
                                    <ui-button
                                        v-if="change.native_url"
                                        :href="change.native_url"
                                        icon="entry"
                                        size="sm"
                                    >
                                        {{ change.status === 'draft' ? 'Åpne utkast' : 'Åpne i Statamic' }}
                                    </ui-button>
                                    <ui-button
                                        v-if="change.preview_available"
                                        icon="eye"
                                        size="sm"
                                        variant="default"
                                        :disabled="previewLoading"
                                        @click="openPreview(change)"
                                    >
                                        Sammenlign live og utkast
                                    </ui-button>
                                    <ui-button
                                        v-if="change.status === 'draft' && can_publish"
                                        variant="primary"
                                        size="sm"
                                        :disabled="busy || processing"
                                        @click="requestPublish(change)"
                                    >
                                        Publiser
                                    </ui-button>
                                </div>
                            </article>
                        </section>
                    </div>

                    <form class="secretary-composer p-4 sm:p-5" @submit.prevent="send">
                        <label for="secretary-message" class="sr-only">Melding til Secretary</label>
                        <textarea
                            id="secretary-message"
                            v-model="message"
                            rows="3"
                            class="input-text secretary-composer-input w-full resize-y"
                            placeholder="Be Secretary om en endring …"
                            :maxlength="max_input_characters"
                            :disabled="busy || !configured"
                            @keydown="onComposerKeydown"
                        />
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                <template v-if="message.length > max_input_characters * 0.8">{{ message.length }}/{{ max_input_characters }} · </template>
                                ⌘/Ctrl + Enter
                            </span>
                            <ui-button
                                variant="primary"
                                type="submit"
                                :loading="busy && !publishCandidate"
                                :disabled="busy || !configured || !message.trim()"
                            >
                                {{ processing ? 'Sett i kø' : 'Send' }}
                            </ui-button>
                        </div>
                    </form>
                </template>

                <div v-else class="grid flex-1 place-items-center px-6 py-20 text-center">
                    <div class="max-w-md">
                        <div class="secretary-empty-icon mx-auto">
                            <ui-icon name="ai-chat-spark" aria-hidden="true" />
                        </div>
                        <h2 class="mt-4 text-lg font-bold">Secretary er klar</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            Start med en helt vanlig beskjed. Secretary undersøker nettstedets faktiske struktur før noe klargjøres.
                        </p>
                        <ui-button class="mt-5" variant="primary" icon="plus" :loading="busy" :disabled="busy" @click="newConversation">
                            Start en samtale
                        </ui-button>
                    </div>
                </div>
            </ui-panel>
        </div>
    </div>

    <ui-modal
        :open="Boolean(publishCandidate)"
        title="Publisere denne endringen?"
        icon="checkmark"
        @update:open="value => { if (!value && !busy) publishCandidate = null }"
    >
        <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
            <strong class="text-gray-950 dark:text-white">{{ publishCandidate?.summary }}</strong>
            blir synlig på nettstedet. Dette er den eneste handlingen her som påvirker publisert innhold.
        </p>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <ui-button variant="ghost" :disabled="busy" @click="publishCandidate = null">Ikke ennå</ui-button>
                <ui-button variant="primary" :loading="busy" :disabled="busy" @click="publish">Ja, publiser</ui-button>
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
