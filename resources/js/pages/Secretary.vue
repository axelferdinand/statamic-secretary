<script setup>
import { Head, Link, router, usePoll } from '@statamic/cms/inertia';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    conversations: { type: Array, required: true },
    conversation: { type: Object, default: null },
    can_publish: { type: Boolean, required: true },
    configured: { type: Boolean, required: true },
    email_enabled: { type: Boolean, required: true },
    email_setup: { type: Object, required: true },
    relay_setup: { type: Object, required: true },
    success: { type: String, default: null },
    max_input_characters: { type: Number, required: true },
    endpoints: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});

const message = ref('');
const feed = ref(null);
const busy = ref(false);
const setupBusy = ref(false);
const emailConnected = computed(() => props.email_setup.connected || props.relay_setup.connected);
const showSetup = ref(!emailConnected.value);
const setupMode = ref(props.relay_setup.connected || (!props.email_setup.token_configured && props.relay_setup.pairing_available)
    ? 'relay'
    : 'postmark');
const emailAddress = ref(props.email_setup.from_address ?? '');
const publicUrl = ref(props.email_setup.suggested_public_url ?? '');
const pairingCode = ref('');
const relayPublicUrl = ref(props.relay_setup.suggested_public_url ?? '');
const error = computed(() => props.errors?.secretary ?? null);
const setupError = computed(() => props.errors?.relay_setup
    ?? props.errors?.pairing_code
    ?? props.errors?.postmark_setup
    ?? props.errors?.email
    ?? props.errors?.public_url
    ?? null);
const processing = computed(() => props.conversation?.processing === true);
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

function send() {
    if (!props.conversation || !message.value.trim() || busy.value || processing.value || !props.configured) return;

    router.post(props.conversation.send_url, { message: message.value.trim() }, {
        preserveScroll: true,
        onStart: () => busy.value = true,
        onSuccess: () => message.value = '',
        onFinish: () => busy.value = false,
    });
}

function publish(change) {
    if (!props.can_publish || change.status !== 'draft' || busy.value || processing.value) return;

    router.post(change.publish_url, {}, {
        preserveScroll: true,
        onStart: () => busy.value = true,
        onFinish: () => busy.value = false,
    });
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
    return ({ proposed: 'Foreslått', draft: 'Utkast', published: 'Publisert', failed: 'Feilet' })[status] ?? status;
}

function changedFields(change) {
    return change.resource_type === 'navigation'
        ? ['komplett navigasjonstre']
        : Object.keys(change.patch ?? {});
}

function resourceLabel(type) {
    return ({ entry: 'Side/innlegg', term: 'Taksonomibegrep', global: 'Globalt innhold', navigation: 'Navigasjon' })[type] ?? type;
}

function channelLabel(channel) {
    return channel === 'email' ? 'E-post' : 'CP';
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
});
watch(() => props.conversation?.messages?.length, scrollToLatest);
watch(processing, syncPolling);
watch(() => props.email_setup.connected, connected => {
    if (connected) showSetup.value = false;
});
watch(() => props.relay_setup.connected, connected => {
    if (connected) showSetup.value = false;
});
</script>

<template>
    <Head title="Secretary" />

    <ui-header title="Secretary">
        <template #description>
            Be om innholdsendringer på vanlig norsk. Secretary klargjør utkast; du bestemmer når de publiseres.
        </template>
        <template #actions>
            <ui-button icon="plus" :disabled="busy" @click="newConversation">
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
                        Secretary svarer fra samme adresse og holder samtalen samlet.
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

                <form v-if="setupMode === 'relay' && relay_setup.pairing_available" class="space-y-5" @submit.prevent="connectRelay">
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                        Lim inn engangskoden du fikk fra Secretary. Nettstedet får en egen isolert adresse, uten at du trenger en Postmark-nøkkel.
                    </p>

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

        <ui-alert
            v-if="error"
            variant="error"
            heading="Secretary kunne ikke fullføre"
            :text="error"
        />

        <div class="grid min-h-[36rem] gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <ui-panel class="h-fit overflow-hidden lg:sticky lg:top-4">
                <div class="border-b px-4 py-3 dark:border-gray-700">
                    <div class="text-sm font-semibold">Samtaler</div>
                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ email_enabled ? 'CP og e-post er tilgjengelig' : 'Kontrollpanelet er aktivt' }}
                    </div>
                </div>

                <nav class="flex max-w-full gap-2 overflow-x-auto p-2 lg:block lg:max-h-[31rem] lg:space-y-1" aria-label="Secretary-samtaler">
                    <Link
                        v-for="item in conversations"
                        :key="item.id"
                        :href="item.url"
                        class="block min-w-56 rounded-lg px-3 py-2 text-sm transition lg:min-w-0"
                        :class="item.id === conversation?.id
                            ? 'bg-gray-100 font-semibold text-gray-950 dark:bg-gray-700 dark:text-white'
                            : 'text-gray-700 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-gray-200 dark:hover:bg-gray-800'"
                    >
                        <span class="flex items-start justify-between gap-2">
                            <span class="line-clamp-2 min-w-0">{{ item.title }}</span>
                            <span class="shrink-0 text-[0.6875rem] font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                {{ channelLabel(item.channel) }}
                            </span>
                        </span>
                    </Link>

                    <div v-if="!conversations.length" class="px-3 py-5 text-sm text-gray-500">
                        Ingen samtaler ennå.
                    </div>
                </nav>
            </ui-panel>

            <ui-panel class="flex min-w-0 flex-col overflow-hidden" :aria-busy="busy || processing">
                <template v-if="conversation">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4 dark:border-gray-700">
                        <div class="min-w-0">
                            <h2 class="truncate text-base font-bold">{{ conversation.title }}</h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ conversation.channel === 'email' ? 'E-postsamtale · ' : '' }}Endringer klargjøres uten å påvirke publisert innhold.
                            </p>
                        </div>
                        <ui-badge :variant="processing ? 'warning' : 'default'">
                            {{ processing ? 'Secretary arbeider …' : `${conversation.messages.length} meldinger` }}
                        </ui-badge>
                    </div>

                    <ui-alert
                        v-if="conversation.processing_error"
                        class="m-4 mb-0"
                        variant="error"
                        heading="Behandlingen stoppet"
                        :text="conversation.processing_error"
                    />

                    <div ref="feed" class="secretary-scrollbar flex-1 space-y-5 overflow-y-auto px-4 py-6 sm:px-6" aria-live="polite">
                        <div v-if="!conversation.messages.length" class="mx-auto max-w-lg py-12 text-center">
                            <div class="text-base font-bold">Hva vil du endre?</div>
                            <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                                Prøv for eksempel: «Endre ingressen på Om oss-siden til …» eller «Lag en ny kladd i sider-samlingen».
                            </p>
                        </div>

                        <article
                            v-for="item in conversation.messages"
                            :key="item.id"
                            class="flex"
                            :class="item.role === 'user' ? 'justify-end' : 'justify-start'"
                        >
                            <div
                                class="secretary-message max-w-[88%] rounded-2xl px-4 py-3 text-sm leading-6 shadow-sm sm:max-w-[75%]"
                                :class="item.role === 'user'
                                    ? 'rounded-br-md bg-blue-600 text-white'
                                    : 'rounded-bl-md border bg-white text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'"
                            >
                                <div v-if="item.channel === 'email'" class="mb-1 text-[0.6875rem] font-semibold uppercase tracking-wide opacity-60">E-post</div>
                                {{ item.body }}
                                <div v-if="item.pending" class="mt-1 text-xs font-medium opacity-70">Behandles …</div>
                            </div>
                        </article>

                        <section v-if="conversation.changes.length" class="space-y-3" aria-label="Secretary-endringer">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Endringer</h3>

                            <div
                                v-for="change in conversation.changes"
                                :key="change.id"
                                class="rounded-xl border bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="break-words font-semibold">{{ change.summary || `${change.operation} ${change.slug || change.entry_id}` }}</div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ resourceLabel(change.resource_type) }} · {{ change.collection }} · {{ change.site }}
                                            <span v-if="changedFields(change).length"> · {{ changedFields(change).join(', ') }}</span>
                                        </div>
                                    </div>
                                    <ui-badge :variant="statusVariant(change.status)">{{ statusLabel(change.status) }}</ui-badge>
                                </div>

                                <p v-if="change.failure" class="mt-3 text-sm text-red-700 dark:text-red-300">{{ change.failure }}</p>

                                <details v-if="changeValues(change).length" class="mt-4 rounded-lg border bg-white dark:border-gray-700 dark:bg-gray-800">
                                    <summary class="cursor-pointer px-3 py-2 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2">
                                        Se før og etter
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

                                <div v-if="change.native_url || (change.status === 'draft' && can_publish)" class="mt-4 flex flex-wrap items-center gap-3 border-t pt-3 dark:border-gray-700">
                                    <Link v-if="change.native_url" :href="change.native_url" class="text-sm font-semibold text-blue-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-blue-300">
                                        Åpne i Statamic
                                    </Link>
                                    <ui-button v-if="change.status === 'draft' && can_publish" variant="primary" size="sm" :disabled="busy || processing" @click="publish(change)">
                                        Publiser
                                    </ui-button>
                                </div>
                            </div>
                        </section>
                    </div>

                    <form class="border-t bg-white p-4 dark:border-gray-700 dark:bg-gray-800 sm:p-5" @submit.prevent="send">
                        <label for="secretary-message" class="sr-only">Melding til Secretary</label>
                        <textarea
                            id="secretary-message"
                            v-model="message"
                            rows="3"
                            class="input-text w-full resize-y"
                            placeholder="Beskriv endringen du ønsker …"
                            :maxlength="max_input_characters"
                            :disabled="busy || processing || !configured"
                            @keydown="onComposerKeydown"
                        />
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                ⌘/Ctrl + Enter · {{ message.length }}/{{ max_input_characters }} tegn
                            </span>
                            <ui-button variant="primary" type="submit" :disabled="busy || processing || !configured || !message.trim()">
                                {{ busy || processing ? 'Arbeider …' : 'Send til Secretary' }}
                            </ui-button>
                        </div>
                    </form>
                </template>

                <div v-else class="grid flex-1 place-items-center px-6 py-20 text-center">
                    <div class="max-w-md">
                        <h2 class="text-lg font-bold">Start en samtale</h2>
                        <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            Secretary undersøker nettstedets faktiske collections, innholdskilder og blueprints før den klargjør noe.
                        </p>
                        <ui-button class="mt-5" variant="primary" :disabled="busy" @click="newConversation">
                            Ny samtale
                        </ui-button>
                    </div>
                </div>
            </ui-panel>
        </div>
    </div>
</template>
