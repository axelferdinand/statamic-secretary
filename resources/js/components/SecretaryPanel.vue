<script setup>
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@statamic/cms/inertia';
import ChangePreviewModal from './ChangePreviewModal.vue';
import DeveloperTrace from './DeveloperTrace.vue';
import SecretaryChangeList from './SecretaryChangeList.vue';

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
const composerInput = ref(null);
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
const visibleMessages = computed(() => (
    conversation.value?.messages?.filter(item => item.presentation !== 'hidden')
    ?? []
));
const pendingMessages = computed(() => (
    visibleMessages.value.filter(item => item.pending)
));
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
const isSecretaryWorkspace = computed(() => {
    if (!pageUrl.value || typeof window === 'undefined') return false;

    try {
        const currentPath = new URL(pageUrl.value).pathname.replace(/\/+$/, '');
        const workspacePath = new URL(props.endpoint, window.location.origin)
            .pathname
            .replace(/\/panel\/data\/?$/, '')
            .replace(/\/+$/, '');

        return currentPath === workspacePath || currentPath.startsWith(`${workspacePath}/`);
    } catch {
        return false;
    }
});
function changesForMessage(item) {
    if (item?.role === 'user' || !item?.reply_to_message_id) return [];

    return conversation.value?.changes?.filter(
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
    conversation.value?.changes?.filter(change => !activeChangeIds.value.has(change.id))
    ?? []
));

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

    return activeContext.value ? [
        'Make the introduction clearer and shorter.',
        'Find language issues on this page.',
        'Suggest a better page title.',
    ] : [
        'Make the homepage introduction clearer.',
        'Find the “About us” page and suggest a better title.',
        'Draft a new contact page.',
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

        error.value = responseError(exception, 'Secretary could not load the conversation.');
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
            ? 'Secretary opened the email thread linked to this draft.'
            : 'Secretary opened the conversation linked to this draft.';
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
        error.value = responseError(exception, 'Secretary could not start a new conversation.');
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
        error.value = responseError(exception, 'Secretary could not send the message.');
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
            ? `${target.field} was removed from the draft.`
            : decision === 'accepted'
                ? `${target.field} remains in the draft.`
                : `${target.field} was reset for review.`;
    } catch (exception) {
        error.value = responseError(exception, 'Secretary could not update the field selection.');
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
    if (!conversation.value?.failed_message_body) return;

    message.value = conversation.value.failed_message_body;
    error.value = null;
    nextTick(() => {
        resizeComposer();
        composerInput.value?.focus();
    });
}

async function publish(change) {
    if (!change?.publish_url || publishingId.value || conversation.value?.processing || !canPublish.value) return;

    publishingId.value = change.id;
    error.value = null;

    try {
        const response = await axios.post(change.publish_url);
        applyPanel(response.data);
        announcement.value = 'The change has been published.';
        refreshVisibleContent();
    } catch (exception) {
        error.value = responseError(exception, 'Secretary could not publish the change.');
    } finally {
        publishingId.value = null;
    }
}

function refreshVisibleContent() {
    router.visit(window.location.href, {
        method: 'get',
        preserveScroll: true,
        preserveState: false,
        replace: true,
    });
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

function channelLabel(channel) {
    return channel === 'email' ? 'Email' : 'Control Panel';
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
        await load(conversation.value?.id ?? '', {
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
watch(message, () => nextTick(resizeComposer));
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
                    v-show="!open && !isSecretaryWorkspace"
                    class="secretary-panel-launcher"
                    icon="ai-chat-spark"
                    variant="primary"
                    aria-label="Open Secretary"
                    title="Open Secretary"
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
                    <div class="secretary-conversation-picker min-w-0 flex-1">
                        <label for="secretary-panel-conversation" class="sr-only">
                            Conversation
                        </label>
                        <div class="secretary-conversation-control">
                            <select
                                id="secretary-panel-conversation"
                                v-model="selectedConversation"
                                class="secretary-conversation-select text-sm"
                                :disabled="loading || busy"
                                @change="changeConversation"
                            >
                                <option v-if="!conversation" value="" disabled>
                                    Continue a conversation …
                                </option>
                                <option v-for="item in contextConversations" :key="item.id" :value="item.id">
                                    {{ item.title }} · {{ channelLabel(item.channel) }}
                                </option>
                            </select>
                            <ui-icon name="chevron-down" class="secretary-conversation-chevron size-4" aria-hidden="true" />
                        </div>
                    </div>
                    <ui-button
                        v-if="conversation"
                        icon="plus"
                        variant="default"
                        size="sm"
                        :loading="busy && !publishingId"
                        :disabled="busy"
                        :title="currentEntryUrl() ? 'New conversation about this page' : 'Start a new conversation'"
                        :aria-label="currentEntryUrl() ? 'New conversation about this page' : 'Start a new conversation'"
                        @click="createConversation"
                    >
                        New
                    </ui-button>
                </div>

                <div class="sr-only" aria-live="polite">{{ announcement }}</div>

                <div v-if="error" class="mx-3 mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" role="alert">
                    {{ error }}
                </div>

                <div v-if="!configured && !loading" class="mx-3 mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100" role="status">
                    OpenAI is not configured. Ask an administrator to add <code>OPENAI_API_KEY</code>.
                </div>

                <div
                    v-if="backgroundJobs.length"
                    class="secretary-background-jobs"
                    role="status"
                    aria-live="polite"
                >
                    <ui-icon name="ai-spark" class="size-4 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <template v-if="backgroundJobs.length === 1 && backgroundJobs[0].context">
                            Working on <strong>{{ backgroundJobs[0].title }}</strong> in the background
                        </template>
                        <template v-else-if="backgroundJobs.length === 1">
                            One conversation is still running in the background
                        </template>
                        <template v-else>
                            {{ backgroundJobs.length }} conversations are still running in the background
                        </template>
                    </span>
                    <a
                        v-if="backgroundJobs.length === 1 && backgroundJobs[0].context?.edit_url"
                        :href="backgroundJobs[0].context.edit_url"
                        class="secretary-inline-link shrink-0"
                    >
                        Open
                    </a>
                </div>

                <div v-if="loading && !panel" class="secretary-panel-loading flex-1 p-5" role="status" aria-label="Loading Secretary">
                    <ui-skeleton class="h-4 w-2/3" />
                    <ui-skeleton class="mt-5 h-20 w-4/5 rounded-xl" />
                    <ui-skeleton class="ml-auto mt-4 h-16 w-3/4 rounded-xl" />
                    <ui-skeleton class="mt-6 h-28 w-full rounded-xl" />
                </div>

                <template v-else-if="conversation">
                    <component
                        :is="activeContext?.edit_url ? 'a' : 'div'"
                        v-if="activeContext"
                        :href="activeContext.edit_url || undefined"
                        class="secretary-context-bar secretary-panel-context"
                        :title="activeContext.edit_url ? `Open ${activeContext.title} in Statamic` : activeContext.title"
                    >
                        <ui-icon name="entry" class="size-4 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="sr-only">Working on: </span>
                            <span class="truncate font-semibold">{{ activeContext.title }}</span>
                            <span v-if="activeContext.field" class="truncate text-gray-500 dark:text-gray-400">
                                · {{ activeContext.field.display }}
                            </span>
                        </span>
                        <ui-icon v-if="activeContext.edit_url" name="arrow-up-right" class="size-4 shrink-0 text-gray-400" aria-hidden="true" />
                    </component>

                    <div
                        ref="feed"
                        class="secretary-panel-feed secretary-scrollbar flex-1 space-y-5 overflow-y-auto px-4 py-5"
                        role="log"
                        aria-label="Secretary conversation"
                    >
                        <div v-if="conversation.processing_error" class="secretary-action-error" role="alert">
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

                        <div v-if="!conversation.messages.length" class="mx-auto max-w-sm py-7 text-center">
                            <div class="secretary-empty-icon mx-auto">
                                <ui-icon name="ai-chat-spark" aria-hidden="true" />
                            </div>
                            <div class="mt-4 font-bold">What should we change?</div>
                            <p class="secretary-empty-lead text-sm leading-6 text-gray-500 dark:text-gray-400">
                                Write as you would to a colleague. Secretary checks the content and structure before making a draft.
                            </p>
                            <div class="secretary-empty-suggestions grid gap-2 text-left">
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
                                :can-publish="canPublish"
                                :processing="conversation.processing"
                                :preview-loading="previewLoading"
                                :publishing-id="publishingId"
                                :reviewing-target="reviewingTarget"
                                @review="(change, target, decision) => review(change, target, decision)"
                                @preview="openPreview"
                                @publish="publish"
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
                                <div class="min-w-0" :class="item.role === 'user' ? 'max-w-[86%]' : 'max-w-[88%]'">
                                    <div v-if="item.role !== 'user'" class="mb-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                        Secretary
                                        <span v-if="item.channel === 'email'" class="font-normal">· via email</span>
                                    </div>
                                    <div
                                        class="secretary-message rounded-xl px-3.5 py-2.5 text-sm leading-6"
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
                                            v-if="developerMode && item.developer_trace"
                                            :trace="item.developer_trace"
                                        />
                                    </div>
                                </div>
                            </article>

                            <SecretaryChangeList
                                v-if="item.id === latestChangeMessageId"
                                :changes="changesForMessage(item)"
                                :label="changesForMessage(item).length === 1 ? 'Result' : 'Results'"
                                :can-publish="canPublish"
                                :processing="conversation.processing"
                                :preview-loading="previewLoading"
                                :publishing-id="publishingId"
                                :reviewing-target="reviewingTarget"
                                @review="(change, target, decision) => review(change, target, decision)"
                                @preview="openPreview"
                                @publish="publish"
                            />
                        </template>

                        <div v-if="conversation.processing" class="secretary-processing-card" role="status">
                            <span class="secretary-working-dots" aria-hidden="true"><i /><i /><i /></span>
                            <span class="min-w-0">
                                <strong>{{ processingStatus }}</strong>
                                <span v-if="pendingMessages.length > 1" class="block text-xs opacity-75">
                                    {{ pendingMessages.length - 1 }} {{ pendingMessages.length === 2 ? 'request is' : 'requests are' }} waiting
                                </span>
                            </span>
                        </div>

                    </div>

                    <form class="secretary-composer relative" @submit.prevent="send">
                        <label for="secretary-panel-message" class="sr-only">Message to Secretary</label>
                        <textarea
                            id="secretary-panel-message"
                            ref="composerInput"
                            v-model="message"
                            rows="1"
                            class="input-text secretary-composer-input w-full resize-none text-sm"
                            placeholder="Ask Secretary to make a change …"
                            :maxlength="maxCharacters"
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
                                    <template v-if="message.length > maxCharacters * 0.8">{{ message.length }}/{{ maxCharacters }} · </template>
                                    Reference content · ⌘/Ctrl + Enter
                                </span>
                            </div>
                            <ui-button
                                type="submit"
                                variant="primary"
                                size="sm"
                                :loading="busy && !publishingId"
                                :disabled="busy || !configured || !message.trim()"
                            >
                                {{ conversation.processing ? `Queue #${pendingMessages.length + 1}` : 'Send' }}
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
                            {{ activeContext ? 'What would you like to change?' : 'What should we create?' }}
                        </div>
                        <p class="secretary-empty-lead text-sm leading-6 text-gray-500 dark:text-gray-400">
                            <template v-if="activeContext">
                                Start a conversation about this page. Secretary reads its content and structure before making a draft.
                            </template>
                            <template v-else>
                                Request a change, find content, or create a new draft.
                            </template>
                        </p>
                        <div class="secretary-empty-action">
                            <ui-button
                                variant="primary"
                                icon="plus"
                                :loading="busy"
                                :disabled="busy"
                                @click="createConversation"
                            >
                                {{ activeContext ? 'Start about this page' : 'Start a conversation' }}
                            </ui-button>
                        </div>
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
