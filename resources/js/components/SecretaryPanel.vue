<script setup>
import { computed, getCurrentInstance, nextTick, onBeforeUnmount, ref, watch } from 'vue';

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
let pollTimer = null;

const conversation = computed(() => panel.value?.conversation ?? null);
const conversations = computed(() => panel.value?.conversations ?? []);
const configured = computed(() => panel.value?.configured !== false);
const maxCharacters = computed(() => panel.value?.max_input_characters ?? 20000);

function responseError(exception, fallback) {
    return exception?.response?.data?.message
        ?? Object.values(exception?.response?.data?.errors ?? {}).flat()[0]
        ?? fallback;
}

async function load(conversationId = selectedConversation.value, { quiet = false } = {}) {
    if (!axios || loading.value) return;

    if (!quiet) loading.value = true;
    error.value = null;

    try {
        const response = await axios.get(props.endpoint, {
            params: conversationId ? { conversation_id: conversationId } : {},
        });
        applyPanel(response.data);
    } catch (exception) {
        error.value = responseError(exception, 'Secretary kunne ikke hente samtalen.');
        stopPolling();
    } finally {
        loading.value = false;
    }
}

function applyPanel(payload) {
    panel.value = payload;
    selectedConversation.value = payload.conversation?.id ?? '';
    scrollToLatest();
    syncPolling();
}

async function createConversation() {
    if (!panel.value?.create_url || busy.value) return;

    busy.value = true;
    error.value = null;

    try {
        const response = await axios.post(panel.value.create_url);
        applyPanel(response.data);
        await nextTick();
        document.getElementById('secretary-panel-message')?.focus();
    } catch (exception) {
        error.value = responseError(exception, 'Secretary kunne ikke starte en ny samtale.');
    } finally {
        busy.value = false;
    }
}

async function changeConversation() {
    stopPolling();
    await load(selectedConversation.value);
}

async function send() {
    const text = message.value.trim();

    if (!conversation.value?.send_url || !text || busy.value || conversation.value.processing || !configured.value) return;

    busy.value = true;
    error.value = null;

    try {
        const response = await axios.post(conversation.value.send_url, { message: text });
        message.value = '';
        applyPanel(response.data);
    } catch (exception) {
        error.value = responseError(exception, 'Secretary kunne ikke sende meldingen.');
    } finally {
        busy.value = false;
    }
}

function onComposerKeydown(event) {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        send();
    }
}

function statusLabel(status) {
    return ({ proposed: 'Foreslått', draft: 'Utkast', published: 'Publisert', failed: 'Feilet' })[status] ?? status;
}

function statusClass(status) {
    return ({
        draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200',
        published: 'bg-green-100 text-green-800 dark:bg-green-950/50 dark:text-green-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200',
    })[status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
}

function channelLabel(channel) {
    return channel === 'email' ? 'E-post' : 'CP';
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

    if (!open.value || !conversation.value?.processing) return;

    pollTimer = window.setTimeout(async () => {
        await load(selectedConversation.value, { quiet: true });
    }, 1500);
}

function opened() {
    load();
}

function closed() {
    stopPolling();
}

watch(() => conversation.value?.messages?.length, scrollToLatest);
watch(() => conversation.value?.processing, syncPolling);
onBeforeUnmount(stopPolling);
</script>

<template>
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
            >
                Secretary
            </ui-button>
        </template>

        <template #header-actions>
            <a
                v-if="panel?.home_url"
                :href="conversation?.full_url ?? panel.home_url"
                class="rounded px-2 py-1 text-xs font-semibold text-blue-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-blue-300"
            >
                Full visning
            </a>
        </template>

        <div class="secretary-panel-shell flex min-h-0 flex-col bg-white dark:bg-gray-900">
            <div class="flex items-center gap-2 border-b bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                <label for="secretary-panel-conversation" class="sr-only">Velg Secretary-samtale</label>
                <select
                    id="secretary-panel-conversation"
                    v-model="selectedConversation"
                    class="input-text min-w-0 flex-1 text-sm"
                    :disabled="loading || busy || !conversations.length"
                    @change="changeConversation"
                >
                    <option v-if="!conversations.length" value="">Ingen samtaler ennå</option>
                    <option v-for="item in conversations" :key="item.id" :value="item.id">
                        {{ item.title }} · {{ channelLabel(item.channel) }}
                    </option>
                </select>
                <ui-button
                    icon="plus"
                    variant="ghost"
                    size="sm"
                    :disabled="busy"
                    aria-label="Start ny Secretary-samtale"
                    @click="createConversation"
                />
            </div>

            <div v-if="error" class="m-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" role="alert">
                {{ error }}
            </div>

            <div v-if="!configured && !loading" class="m-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100" role="status">
                OpenAI er ikke konfigurert. En administrator må legge inn <code>OPENAI_API_KEY</code>.
            </div>

            <div v-if="loading && !panel" class="grid flex-1 place-items-center p-8 text-center text-sm text-gray-500" role="status">
                Henter Secretary …
            </div>

            <template v-else-if="conversation">
                <div class="flex items-center justify-between gap-3 border-b px-4 py-3 dark:border-gray-700">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-bold">{{ conversation.title }}</div>
                        <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ conversation.channel === 'email' ? 'E-postsamtale' : 'Kontrollpanel' }}
                        </div>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2 py-1 text-[0.6875rem] font-semibold"
                        :class="conversation.processing
                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200'
                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'"
                    >
                        {{ conversation.processing ? 'Arbeider …' : 'Klar' }}
                    </span>
                </div>

                <div
                    ref="feed"
                    class="secretary-panel-feed secretary-scrollbar flex-1 space-y-4 overflow-y-auto px-4 py-5"
                    aria-live="polite"
                    aria-label="Secretary-samtale"
                >
                    <div v-if="conversation.processing_error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200" role="alert">
                        {{ conversation.processing_error }}
                    </div>

                    <div v-if="!conversation.messages.length" class="mx-auto max-w-xs py-12 text-center">
                        <div class="font-bold">Hva vil du endre?</div>
                        <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                            Beskriv oppgaven på samme måte som du ville gjort til en kollega.
                        </p>
                    </div>

                    <article
                        v-for="item in conversation.messages"
                        :key="item.id"
                        class="flex"
                        :class="item.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="secretary-message max-w-[88%] rounded-2xl px-3.5 py-2.5 text-sm leading-6 shadow-sm"
                            :class="item.role === 'user'
                                ? 'rounded-br-md bg-blue-600 text-white'
                                : 'rounded-bl-md border bg-white text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100'"
                        >
                            <div v-if="item.channel === 'email'" class="mb-1 text-[0.6875rem] font-semibold uppercase tracking-wide opacity-60">E-post</div>
                            {{ item.body }}
                            <div v-if="item.pending" class="mt-1 text-xs font-semibold opacity-70">Behandles …</div>
                        </div>
                    </article>

                    <section v-if="conversation.changes.length" class="space-y-2" aria-label="Klargjorte endringer">
                        <div class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Endringer</div>
                        <div
                            v-for="change in conversation.changes"
                            :key="change.id"
                            class="rounded-lg border bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="break-words text-sm font-semibold">{{ change.summary }}</div>
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ change.collection }} · {{ change.site }}
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-1 text-[0.6875rem] font-semibold" :class="statusClass(change.status)">
                                    {{ statusLabel(change.status) }}
                                </span>
                            </div>
                        </div>
                        <a
                            :href="conversation.full_url"
                            class="inline-flex rounded text-sm font-semibold text-blue-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 dark:text-blue-300"
                        >
                            Se før/etter og publiser
                        </a>
                    </section>
                </div>

                <form class="border-t bg-white p-3 dark:border-gray-700 dark:bg-gray-800" @submit.prevent="send">
                    <label for="secretary-panel-message" class="sr-only">Melding til Secretary</label>
                    <textarea
                        id="secretary-panel-message"
                        v-model="message"
                        rows="3"
                        class="input-text w-full resize-y text-sm"
                        placeholder="Beskriv endringen du ønsker …"
                        :maxlength="maxCharacters"
                        :disabled="busy || conversation.processing || !configured"
                        @keydown="onComposerKeydown"
                    />
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ message.length }}/{{ maxCharacters }}</span>
                        <ui-button
                            type="submit"
                            variant="primary"
                            size="sm"
                            :disabled="busy || conversation.processing || !configured || !message.trim()"
                        >
                            {{ busy || conversation.processing ? 'Arbeider …' : 'Send' }}
                        </ui-button>
                    </div>
                </form>
            </template>

            <div v-else class="grid flex-1 place-items-center p-8 text-center">
                <div class="max-w-xs">
                    <div class="font-bold">Start en samtale</div>
                    <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                        Secretary kan klargjøre innhold mens du fortsetter å arbeide i Statamic.
                    </p>
                    <ui-button class="mt-4" variant="primary" :disabled="busy" @click="createConversation">
                        Ny samtale
                    </ui-button>
                </div>
            </div>
        </div>
    </ui-stack>
</template>
