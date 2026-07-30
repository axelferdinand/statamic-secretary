<script setup>
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@statamic/cms/inertia';
import ChangePreviewModal from './ChangePreviewModal.vue';
import ChangeReview from './ChangeReview.vue';
import DeveloperTrace from './DeveloperTrace.vue';

const props = defineProps({
    endpoint: { type: String, required: true },
});

const axios = getCurrentInstance()?.proxy?.$axios;
const open = ref(false);
const loading = ref(false);
const busy = ref(false);
const error = ref(null);
const panel = ref(null);
const selectedConversation = ref('');
const message = ref('');
const feed = ref(null);
const publishingId = ref(null);
const reviewingTarget = ref(null);
const announcement = ref('');
const fieldContext = ref(null);
const references = ref([]);
const referenceLoading = ref(false);
const previewOpen = ref(false);
const previewLoading = ref(false);
const previewData = ref(null);
const previewError = ref(null);
const pageUrl = ref(typeof window === 'undefined' ? '' : window.location.href);
let pollTimer = null;
let referenceTimer = null;
let contextRequest = 0;
let activeDraftKey = null;
let stopNavigationListener = null;

const conversation = computed(() => panel.value?.conversation ?? null);
const conversations = computed(() => panel.value?.conversations ?? []);
const contextConversations = computed(() => {
    const key = panel.value?.active_context_key;

    return key
        ? conversations.value.filter(item => item.current_context)
        : conversations.value.filter(item => !item.context_key);
});
const backgroundJobs = computed(() => panel.value?.background_jobs ?? []);
const configured = computed(() => panel.value?.configured !== false);
const maxCharacters = computed(() => panel.value?.max_input_characters ?? 20000);
const canPublish = computed(() => panel.value?.can_publish === true);
const developerMode = computed(() => panel.value?.developer_mode === true);
const anyProcessing = computed(() => conversation.value?.processing || backgroundJobs.value.length > 0);
const activeContext = computed(() => panel.value?.active_context ?? conversation.value?.context ?? null);

function currentEntryUrl() {
    if (!pageUrl.value) return null;

    const url = new URL(pageUrl.value);

    return /\/collections\/[^/]+\/entries\/[^/]+(?:\/[^/]*)?\/?$/.test(url.pathname)
        ? pageUrl.value
        : null;
}

function linkedConversationId(url = window.location.href) {
    const conversationId = new URL(url).searchParams.get('secretary')?.trim() ?? '';

    return /^[A-Za-z0-9_-]{10,64}$/.test(conversationId) ? conversationId : '';
}

const promptSuggestions = computed(() => {
    const field = activeContext.value?.field;

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

    return activeContext.value ? [
        'Gjør ingressen tydeligere og kortere.',
        'Finn språklige feil på denne siden.',
        'Lag et bedre forslag til sidetittel.',
    ] : [
    'Gjør ingressen på forsiden tydeligere.',
    'Finn siden «Om oss» og foreslå en bedre tittel.',
    'Lag et utkast til en ny kontaktside.',
    ];
});

function responseError(exception, fallback) {
    return exception?.response?.data?.message
        ?? Object.values(exception?.response?.data?.errors ?? {}).flat()[0]
        ?? fallback;
}

function draftKey(payload) {
    const scope = payload?.draft_scope;
    const context = payload?.active_context_key ?? payload?.conversation?.id ?? 'general';

    return scope ? `statamic-secretary:draft:${scope}:${context}` : null;
}

function readDraft(key) {
    if (!key || typeof window === 'undefined') return '';

    try {
        return window.localStorage.getItem(key) ?? '';
    } catch {
        return '';
    }
}

function persistDraft(key = activeDraftKey, value = message.value) {
    if (!key || typeof window === 'undefined') return;

    try {
        if (value) {
            window.localStorage.setItem(key, value);
        } else {
            window.localStorage.removeItem(key);
        }
    } catch {
        // Browsers may disable local storage. The composer still works in-memory.
    }
}

function switchDraft(payload) {
    const nextKey = draftKey(payload);

    if (nextKey === activeDraftKey) return;

    persistDraft();
    activeDraftKey = nextKey;
    message.value = readDraft(nextKey);
    references.value = [];
}

async function load(
    conversationId = '',
    {
        quiet = false,
        contextUrl = pageUrl.value,
        allowAutoOpen = false,
    } = {},
) {
    if (!axios) return;

    const request = ++contextRequest;
    if (!quiet) loading.value = true;
    error.value = null;

    try {
        const params = {};

        if (conversationId) params.conversation_id = conversationId;
        if (contextUrl) params.context_url = contextUrl;

        const response = await axios.get(props.endpoint, {
            params,
        });

        if (request !== contextRequest) return;

        applyPanel(response.data, { allowAutoOpen });
    } catch (exception) {
        if (request !== contextRequest) return;

        error.value = responseError(exception, 'Secretary kunne ikke hente samtalen.');
        stopPolling();
    } finally {
        if (request === contextRequest) loading.value = false;
    }
}

function applyPanel(payload, { allowAutoOpen = false } = {}) {
    switchDraft(payload);
    panel.value = payload;
    selectedConversation.value = payload.conversation?.id ?? '';

    if (allowAutoOpen && payload.auto_open === true) {
        open.value = true;
        announcement.value = payload.conversation?.has_email_messages
            ? 'Secretary åpnet e-posttråden som hører til dette utkastet.'
            : 'Secretary åpnet samtalen som hører til dette utkastet.';
    }

    scrollToLatest();
    syncPolling();
}

async function createConversation({ focus = true } = {}) {
    if (!panel.value?.create_url || busy.value) return;

    busy.value = true;
    error.value = null;

    try {
        const response = await axios.post(panel.value.create_url, {
            context_url: currentEntryUrl(),
            field_context: fieldContext.value,
        });
        applyPanel(response.data);

        if (focus) {
            await nextTick();
            document.getElementById('secretary-panel-message')?.focus();
        }
    } catch (exception) {
        error.value = responseError(exception, 'Secretary kunne ikke starte en ny samtale.');
    } finally {
        busy.value = false;
    }
}

async function changeConversation() {
    stopPolling();
    await load(selectedConversation.value, { contextUrl: pageUrl.value });
}

async function send() {
    const text = message.value.trim();

    if (!conversation.value?.send_url || !text || busy.value || !configured.value) return;

    busy.value = true;
    error.value = null;

    try {
        const response = await axios.post(conversation.value.send_url, {
            message: text,
            context_url: currentEntryUrl(),
            field_context: fieldContext.value,
        });
        persistDraft(activeDraftKey, '');
        message.value = '';
        references.value = [];
        applyPanel(response.data);
    } catch (exception) {
        error.value = responseError(exception, 'Secretary kunne ikke sende meldingen.');
    } finally {
        busy.value = false;
    }
}

async function review(change, target, decision) {
    if (!change?.review_url || reviewingTarget.value || conversation.value?.processing) return;

    reviewingTarget.value = target.key;
    error.value = null;

    try {
        const response = await axios.post(change.review_url, {
            target: target.key,
            decision,
        });
        applyPanel(response.data);
        announcement.value = decision === 'rejected'
            ? `${target.field} er tatt ut av utkastet.`
            : decision === 'accepted'
                ? `${target.field} er beholdt i utkastet.`
                : `${target.field} er satt tilbake til ukontrollert.`;
    } catch (exception) {
        error.value = responseError(exception, 'Secretary kunne ikke oppdatere feltvalget.');
    } finally {
        reviewingTarget.value = null;
    }
}

async function openPreview(change) {
    if (!change?.preview_url || previewLoading.value) return;

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

function captureFieldContext(event) {
    const target = event.target;

    if (!(target instanceof Element)
        || target.closest('.secretary-panel-shell, .secretary-panel-launcher')) return;

    const input = target.closest('input[name], textarea[name], select[name], [contenteditable="true"]');
    const wrapper = target.closest('[data-field-handle], [data-fieldtype], .publish-field');
    const rawName = input?.getAttribute('name') ?? input?.getAttribute('data-field-handle') ?? '';
    const handle = wrapper?.getAttribute('data-field-handle')
        ?? rawName.replace(/\[.*$/, '').split('.').filter(Boolean).at(-1)
        ?? '';

    if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(handle)) return;

    const set = target.closest('[data-set-type], [data-set-index], .replicator-set, .bard-set');
    const setType = set?.getAttribute('data-set-type')
        ?? set?.getAttribute('data-type')
        ?? null;
    const setIndexValue = set?.getAttribute('data-set-index');
    fieldContext.value = {
        handle,
        ...(setType && /^[A-Za-z][A-Za-z0-9_-]*$/.test(setType) ? { set_type: setType } : {}),
        ...(setIndexValue && /^\d+$/.test(setIndexValue) ? { set_index: Number(setIndexValue) } : {}),
    };
}

function referenceMatch(value) {
    return value.match(/(?:^|\s)@([\p{L}\p{N}_ -]{2,80})$/u);
}

async function loadReferences(query) {
    if (!panel.value?.references_url || !query || !axios) return;

    referenceLoading.value = true;

    try {
        const response = await axios.get(panel.value.references_url, { params: { q: query } });
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
    nextTick(() => document.getElementById('secretary-panel-message')?.focus());
}

async function publish(change) {
    if (!change?.publish_url || publishingId.value || conversation.value?.processing || !canPublish.value) return;

    publishingId.value = change.id;
    error.value = null;

    try {
        const response = await axios.post(change.publish_url);
        applyPanel(response.data);
        announcement.value = 'Endringen er publisert.';
    } catch (exception) {
        error.value = responseError(exception, 'Secretary kunne ikke publisere endringen.');
    } finally {
        publishingId.value = null;
    }
}

function useSuggestion(suggestion) {
    message.value = suggestion;
    nextTick(() => document.getElementById('secretary-panel-message')?.focus());
}

function onComposerKeydown(event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        send();
    }
}

function statusLabel(status) {
    return ({
        proposed: 'Foreslått',
        draft: 'Klar som utkast',
        published: 'Publisert',
        failed: 'Kunne ikke lagres',
    })[status] ?? status;
}

function statusClass(status) {
    return ({
        draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200',
        published: 'bg-green-100 text-green-800 dark:bg-green-950/50 dark:text-green-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200',
    })[status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
}

function channelLabel(channel) {
    return channel === 'email' ? 'E-post' : 'Kontrollpanel';
}

function conversationChannelLabel(item) {
    if (item?.has_email_messages && item?.has_cp_messages) {
        return 'E-post + kontrollpanel';
    }

    return channelLabel(item?.channel);
}

function changeActionLabel(change) {
    if (change.status === 'draft') return 'Åpne utkast';
    if (change.status === 'published') return 'Åpne innhold';

    return 'Åpne i Statamic';
}

function scrollToLatest() {
    nextTick(() => {
        if (feed.value) feed.value.scrollTop = feed.value.scrollHeight;
    });
}

function stopPolling() {
    if (pollTimer) window.clearTimeout(pollTimer);
    pollTimer = null;
}

function syncPolling() {
    stopPolling();

    if (!anyProcessing.value) return;

    pollTimer = window.setTimeout(async () => {
        await load('', {
            quiet: true,
            contextUrl: pageUrl.value,
        });
    }, open.value ? 1500 : 4000);
}

async function ensureContextConversation() {
    if (!open.value
        || conversation.value
        || !panel.value?.active_context
        || busy.value) return;

    await createConversation({ focus: false });
}

async function opened() {
    await load('', { contextUrl: pageUrl.value });
    await ensureContextConversation();
    syncPolling();
}

function closed() {
    syncPolling();
}

async function syncPageContext() {
    const nextUrl = window.location.href;
    const conversationId = linkedConversationId(nextUrl);

    if (nextUrl === pageUrl.value && panel.value) return;

    persistDraft();
    pageUrl.value = nextUrl;
    fieldContext.value = null;
    selectedConversation.value = '';
    previewOpen.value = false;
    previewData.value = null;
    previewError.value = null;
    if (conversationId) open.value = true;
    await load(conversationId, {
        quiet: panel.value !== null,
        contextUrl: nextUrl,
        allowAutoOpen: true,
    });
    await ensureContextConversation();
}

watch(() => conversation.value?.messages?.length, scrollToLatest);
watch(anyProcessing, syncPolling);
watch(message, scheduleReferences);
watch(message, value => persistDraft(activeDraftKey, value));
onMounted(() => {
    document.addEventListener('focusin', captureFieldContext, true);
    stopNavigationListener = router.on('navigate', syncPageContext);
    window.addEventListener('popstate', syncPageContext);
    window.addEventListener('hashchange', syncPageContext);
    pageUrl.value = window.location.href;
    const conversationId = linkedConversationId(pageUrl.value);
    if (conversationId) open.value = true;
    load(conversationId, {
        quiet: true,
        contextUrl: pageUrl.value,
        allowAutoOpen: true,
    });
});
onBeforeUnmount(() => {
    persistDraft();
    stopPolling();
    if (referenceTimer) window.clearTimeout(referenceTimer);
    stopNavigationListener?.();
    window.removeEventListener('popstate', syncPageContext);
    window.removeEventListener('hashchange', syncPageContext);
    document.removeEventListener('focusin', captureFieldContext, true);
});
</script>

<template>
    <Teleport to="body">
        <ui-stack
            v-model:open="open"
            title="Secretary"
            icon="ai-chat-spark"
            size="narrow"
            inset
            @opened="opened"
            @closed="closed"
        >
            <template #trigger>
                <ui-button
                    v-show="!open"
                    class="secretary-panel-launcher"
                    icon="ai-chat-spark"
                    variant="primary"
                    aria-label="Åpne Secretary-chat"
                    title="Åpne Secretary-chat"
                >
                    <span class="secretary-panel-launcher-label">Secretary</span>
                    <span
                        v-if="anyProcessing"
                        class="secretary-panel-launcher-status"
                        aria-hidden="true"
                    />
                </ui-button>
            </template>

            <div class="secretary-panel-shell flex min-h-0 flex-col bg-white dark:bg-gray-900">
                <div v-if="contextConversations.length" class="secretary-panel-toolbar">
                    <label for="secretary-panel-conversation" class="sr-only">Velg Secretary-samtale</label>
                    <select
                        id="secretary-panel-conversation"
                        v-model="selectedConversation"
                        class="input-text secretary-conversation-select min-w-0 flex-1 text-sm"
                        :disabled="loading || busy"
                        @change="changeConversation"
                    >
                        <option v-if="!conversation" value="" disabled>
                            Fortsett en tidligere samtale …
                        </option>
                        <option v-for="item in contextConversations" :key="item.id" :value="item.id">
                            {{ item.title }} · {{ channelLabel(item.channel) }}
                        </option>
                    </select>
                    <ui-button
                        v-if="conversation"
                        icon="plus"
                        variant="default"
                        size="sm"
                        :loading="busy && !publishingId"
                        :disabled="busy"
                        :title="currentEntryUrl() ? 'Ny samtale om denne siden' : 'Start ny samtale'"
                        :aria-label="currentEntryUrl() ? 'Ny samtale om denne siden' : 'Start ny samtale'"
                        @click="createConversation"
                    >
                        Ny samtale
                    </ui-button>
                </div>

                <div class="sr-only" aria-live="polite">{{ announcement }}</div>

                <div v-if="error" class="mx-3 mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" role="alert">
                    {{ error }}
                </div>

                <div v-if="!configured && !loading" class="mx-3 mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100" role="status">
                    OpenAI er ikke konfigurert. Be en administrator legge inn <code>OPENAI_API_KEY</code>.
                </div>

                <div
                    v-if="backgroundJobs.length"
                    class="secretary-background-jobs"
                    role="status"
                    aria-live="polite"
                >
                    <ui-icon name="ai-spark" class="size-4 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <strong v-if="backgroundJobs.length === 1 && backgroundJobs[0].context">
                            Secretary jobber på {{ backgroundJobs[0].title }}.
                        </strong>
                        <strong v-else-if="backgroundJobs.length === 1">
                            Secretary jobber i en annen samtale.
                        </strong>
                        <strong v-else>
                            Secretary jobber på {{ backgroundJobs.length }} andre sider.
                        </strong>
                        <span class="block text-xs opacity-75">Jobben fortsetter trygt i bakgrunnen.</span>
                    </span>
                    <a
                        v-if="backgroundJobs.length === 1 && backgroundJobs[0].context?.edit_url"
                        :href="backgroundJobs[0].context.edit_url"
                        class="secretary-inline-link shrink-0"
                    >
                        Vis
                    </a>
                </div>

                <div v-if="loading && !panel" class="secretary-panel-loading flex-1 p-5" role="status" aria-label="Henter Secretary">
                    <ui-skeleton class="h-4 w-2/3" />
                    <ui-skeleton class="mt-5 h-20 w-4/5 rounded-xl" />
                    <ui-skeleton class="ml-auto mt-4 h-16 w-3/4 rounded-xl" />
                    <ui-skeleton class="mt-6 h-28 w-full rounded-xl" />
                </div>

                <template v-else-if="conversation">
                    <header class="secretary-conversation-header">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-bold">{{ conversation.title }}</div>
                            <div class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ conversationChannelLabel(conversation) }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ conversation.messages.length }} {{ conversation.messages.length === 1 ? 'melding' : 'meldinger' }}</span>
                            </div>
                        </div>
                        <span
                            class="secretary-status-pill"
                            :class="conversation.processing ? 'is-processing' : 'is-ready'"
                        >
                            <span class="secretary-status-dot" aria-hidden="true" />
                            {{ conversation.processing ? 'Jobber' : 'Klar' }}
                        </span>
                    </header>

                    <a
                        v-if="activeContext?.edit_url"
                        :href="activeContext.edit_url"
                        class="secretary-context-bar"
                    >
                        <ui-icon name="entry" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.6875rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Denne samtalen gjelder</span>
                            <span class="block truncate text-sm font-semibold">{{ activeContext.title }}</span>
                            <span v-if="activeContext.field" class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ activeContext.field.display }}
                                <template v-if="activeContext.field.set_type"> · {{ activeContext.field.set_type }}</template>
                            </span>
                        </span>
                        <ui-icon name="arrow-up-right" class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                    </a>

                    <div
                        v-if="activeContext && conversation.has_email_messages"
                        class="secretary-channel-handoff"
                        role="status"
                    >
                        <ui-icon name="mail" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="min-w-0">
                            <strong class="block">
                                {{ conversation.has_cp_messages ? 'Samme samtale, nå i Statamic' : 'Fortsett e-posttråden her' }}
                            </strong>
                            <span class="block text-xs opacity-75">
                                Hele dialogen følger med. Finpuss videre uten å starte på nytt.
                            </span>
                        </span>
                    </div>

                    <div
                        ref="feed"
                        class="secretary-panel-feed secretary-scrollbar flex-1 space-y-5 overflow-y-auto px-4 py-5"
                        role="log"
                        aria-label="Secretary-samtale"
                    >
                        <div v-if="conversation.processing_error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" role="alert">
                            <div class="font-semibold">Secretary stoppet underveis</div>
                            <div class="mt-1">{{ conversation.processing_error }}</div>
                        </div>

                        <div v-if="!conversation.messages.length" class="mx-auto max-w-sm py-7 text-center">
                            <div class="secretary-empty-icon mx-auto">
                                <ui-icon name="ai-chat-spark" aria-hidden="true" />
                            </div>
                            <div class="mt-4 font-bold">Hva skal vi fikse?</div>
                            <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                                Skriv som du ville gjort til en kollega. Secretary undersøker innhold og struktur før noe endres.
                            </p>
                            <div class="mt-5 grid gap-2 text-left">
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
                            <div class="min-w-0" :class="item.role === 'user' ? 'max-w-[86%]' : 'max-w-[88%]'">
                                <div v-if="item.role !== 'user'" class="mb-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                    Secretary
                                    <span v-if="item.channel === 'email'" class="font-normal">· via e-post</span>
                                </div>
                                <div
                                    class="secretary-message rounded-xl px-3.5 py-2.5 text-sm leading-6"
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
                                        v-if="developerMode && item.developer_trace"
                                        :trace="item.developer_trace"
                                    />
                                </div>
                            </div>
                        </article>

                        <div v-if="conversation.processing" class="secretary-processing-card" role="status">
                            <span class="secretary-working-dots" aria-hidden="true"><i /><i /><i /></span>
                            <span>
                                <strong>Secretary jobber.</strong>
                                Leser innhold og kontrollerer strukturen før utkastet lagres.
                            </span>
                        </div>

                        <section v-if="conversation.changes.length" class="space-y-2.5" aria-label="Klargjorte endringer">
                            <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ conversation.changes.length === 1 ? 'Endring' : `${conversation.changes.length} endringer` }}
                            </h3>

                            <article
                                v-for="change in conversation.changes"
                                :key="change.id"
                                class="secretary-change-card"
                            >
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0 flex-1">
                                        <div class="break-words text-sm font-semibold leading-5">
                                            {{ change.summary }}
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ change.collection }} · {{ change.site }}
                                        </div>
                                    </div>
                                    <span class="secretary-change-status" :class="statusClass(change.status)">
                                        {{ statusLabel(change.status) }}
                                    </span>
                                </div>

                                <p v-if="change.failure" class="mt-3 text-sm text-red-700 dark:text-red-300">
                                    {{ change.failure }}
                                </p>

                                <ChangeReview
                                    :change="change"
                                    :busy-target="reviewingTarget"
                                    compact
                                    @decide="(target, decision) => review(change, target, decision)"
                                />

                                <div v-if="change.native_url || change.preview_available || (change.status === 'draft' && canPublish)" class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3 dark:border-gray-700">
                                    <ui-button
                                        v-if="change.native_url"
                                        :href="change.native_url"
                                        size="sm"
                                        variant="default"
                                        icon="entry"
                                    >
                                        {{ changeActionLabel(change) }}
                                    </ui-button>
                                    <ui-button
                                        v-if="change.preview_available"
                                        size="sm"
                                        variant="default"
                                        icon="eye"
                                        :disabled="previewLoading"
                                        @click="openPreview(change)"
                                    >
                                        Sammenlign
                                    </ui-button>
                                    <ui-button
                                        v-if="change.status === 'draft' && canPublish"
                                        size="sm"
                                        variant="primary"
                                        :loading="publishingId === change.id"
                                        :disabled="Boolean(publishingId) || conversation.processing"
                                        @click="publish(change)"
                                    >
                                        Publiser nå
                                    </ui-button>
                                </div>
                            </article>
                        </section>
                    </div>

                    <form class="secretary-composer relative" @submit.prevent="send">
                        <label for="secretary-panel-message" class="sr-only">Melding til Secretary</label>
                        <textarea
                            id="secretary-panel-message"
                            v-model="message"
                            rows="3"
                            class="input-text secretary-composer-input w-full resize-none text-sm"
                            placeholder="Be Secretary om en endring …"
                            :maxlength="maxCharacters"
                            :disabled="busy || !configured"
                            @keydown="onComposerKeydown"
                        />
                        <div
                            v-if="references.length || referenceLoading"
                            class="secretary-reference-menu"
                            role="listbox"
                            aria-label="Referer til innhold"
                        >
                            <div v-if="referenceLoading && !references.length" class="px-3 py-2 text-xs text-gray-500" role="status">
                                Finner innhold …
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
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                <template v-if="message.length > maxCharacters * 0.8">{{ message.length }}/{{ maxCharacters }} · </template>
                                ⌘/Ctrl + Enter
                            </span>
                            <ui-button
                                type="submit"
                                variant="primary"
                                size="sm"
                                :loading="busy && !publishingId"
                                :disabled="busy || !configured || !message.trim()"
                            >
                                {{ conversation.processing ? 'Sett i kø' : 'Send' }}
                            </ui-button>
                        </div>
                    </form>
                </template>

                <div v-else class="secretary-panel-empty">
                    <div class="secretary-panel-empty-inner">
                        <div v-if="activeContext" class="secretary-empty-context">
                            <ui-icon name="entry" class="size-4 shrink-0" aria-hidden="true" />
                            <span class="truncate">{{ activeContext.title }}</span>
                        </div>
                        <div class="secretary-empty-icon mx-auto">
                            <ui-icon name="ai-chat-spark" aria-hidden="true" />
                        </div>
                        <div class="mt-4 text-lg font-bold">
                            {{ activeContext ? 'Hva vil du endre?' : 'Hva skal vi lage?' }}
                        </div>
                        <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            <template v-if="activeContext">
                                Start en samtale om denne siden. Secretary leser innholdet og strukturen før noe endres.
                            </template>
                            <template v-else>
                                Be om en endring, finn innhold eller lag et nytt utkast.
                            </template>
                        </p>
                        <ui-button
                            class="mt-5"
                            variant="primary"
                            icon="plus"
                            :loading="busy"
                            :disabled="busy"
                            @click="createConversation"
                        >
                            {{ activeContext ? 'Start om denne siden' : 'Start en samtale' }}
                        </ui-button>
                    </div>
                </div>
            </div>
        </ui-stack>

        <ChangePreviewModal
            :open="previewOpen"
            :preview="previewData"
            :loading="previewLoading"
            :error="previewError"
            @close="closePreview"
        />
    </Teleport>
</template>
