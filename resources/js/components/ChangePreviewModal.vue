<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    preview: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
    canPublish: { type: Boolean, default: false },
    publishing: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'publish']);
const active = ref('draft');
const draftMounted = ref(false);
const liveMounted = ref(false);
const draftLoaded = ref(false);
const liveLoaded = ref(false);
const draftSlow = ref(false);
const liveSlow = ref(false);
const draftKey = ref(0);
const liveKey = ref(0);
const hasLive = computed(() => Boolean(props.preview?.live_url));
let draftTimer = null;
let liveTimer = null;

function clearTimer(kind) {
    const timer = kind === 'draft' ? draftTimer : liveTimer;

    if (timer) window.clearTimeout(timer);
    if (kind === 'draft') draftTimer = null;
    else liveTimer = null;
}

function startTimer(kind) {
    clearTimer(kind);

    const timer = window.setTimeout(() => {
        if (kind === 'draft' && !draftLoaded.value) draftSlow.value = true;
        if (kind === 'live' && !liveLoaded.value) liveSlow.value = true;
    }, 8000);

    if (kind === 'draft') draftTimer = timer;
    else liveTimer = timer;
}

function mount(kind) {
    if (kind === 'draft' && !draftMounted.value) {
        draftMounted.value = true;
        startTimer('draft');
    }

    if (kind === 'live' && !liveMounted.value) {
        liveMounted.value = true;
        startTimer('live');
    }
}

function select(view) {
    active.value = view;

    if (view === 'compare') {
        mount('draft');
        mount('live');
        return;
    }

    mount(view);
}

function frameLoaded(kind) {
    clearTimer(kind);

    if (kind === 'draft') {
        draftLoaded.value = true;
        draftSlow.value = false;
    } else {
        liveLoaded.value = true;
        liveSlow.value = false;
    }
}

function reload(kind) {
    if (kind === 'draft') {
        draftLoaded.value = false;
        draftSlow.value = false;
        draftKey.value += 1;
    } else {
        liveLoaded.value = false;
        liveSlow.value = false;
        liveKey.value += 1;
    }

    startTimer(kind);
}

function reset() {
    clearTimer('draft');
    clearTimer('live');
    active.value = 'draft';
    draftMounted.value = false;
    liveMounted.value = false;
    draftLoaded.value = false;
    liveLoaded.value = false;
    draftSlow.value = false;
    liveSlow.value = false;
}

watch(() => props.open, value => {
    if (!value) {
        reset();
        return;
    }

    if (props.preview?.draft_url) mount('draft');
});

watch(() => props.preview?.draft_url, value => {
    if (!props.open || !value) return;

    reset();
    mount('draft');
});

onBeforeUnmount(reset);
</script>

<template>
    <ui-modal
        class="secretary-preview-modal"
        :open="open"
        :title="preview?.title ? `Preview · ${preview.title}` : 'Preview'"
        icon="eye"
        @update:open="value => { if (!value) emit('close') }"
    >
        <ui-modal-close class="secretary-preview-close">
            <ui-button
                variant="default"
                size="sm"
                icon="x"
                aria-label="Close preview"
            >
                <span class="secretary-preview-close-label">Close</span>
            </ui-button>
        </ui-modal-close>

        <div v-if="loading" class="secretary-preview-loading" role="status" aria-label="Preparing preview">
            <ui-skeleton class="h-5 w-48" />
            <ui-skeleton class="mt-4 h-[30rem] w-full rounded-lg" />
        </div>

        <ui-alert
            v-else-if="error"
            variant="error"
            heading="The preview could not be opened"
            :text="error"
        />

        <div v-else-if="preview" class="secretary-preview">
            <div class="secretary-preview-toolbar">
                <template v-if="hasLive">
                    <span class="secretary-preview-toolbar-label">View</span>
                    <div class="secretary-preview-tabs" role="tablist" aria-label="Choose preview">
                        <button
                            type="button"
                            role="tab"
                            class="secretary-preview-compare-option"
                            :aria-selected="active === 'compare'"
                            :class="{ 'is-active': active === 'compare' }"
                            @click="select('compare')"
                        >
                            Side by side
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="active === 'live'"
                            :class="{ 'is-active': active === 'live' }"
                            @click="select('live')"
                        >
                            Published
                        </button>
                        <button
                            type="button"
                            role="tab"
                            :aria-selected="active === 'draft'"
                            :class="{ 'is-active': active === 'draft' }"
                            @click="select('draft')"
                        >
                            Secretary draft
                        </button>
                    </div>
                </template>

                <ui-button
                    v-if="canPublish && active !== 'live'"
                    class="secretary-preview-publish"
                    variant="primary"
                    size="sm"
                    icon="checkmark"
                    :loading="publishing"
                    :disabled="publishing"
                    @click="emit('publish')"
                >
                    Publish this
                </ui-button>
            </div>

            <div
                class="secretary-preview-grid"
                :class="{ 'has-live': hasLive, 'is-comparing': hasLive && active === 'compare' }"
            >
                <section v-if="hasLive && liveMounted" v-show="active === 'compare' || active === 'live'">
                    <header>
                        <strong>Published</strong>
                        <a :href="preview.live_url" target="_blank" rel="noopener">Open in new tab</a>
                    </header>
                    <div class="secretary-preview-frame" :aria-busy="!liveLoaded">
                        <div v-if="!liveLoaded" class="secretary-preview-frame-loading" role="status">
                            <span class="secretary-preview-spinner" aria-hidden="true" />
                            <span>{{ liveSlow ? 'This page is taking longer than usual.' : 'Loading published page …' }}</span>
                            <button v-if="liveSlow" type="button" @click="reload('live')">Try again</button>
                        </div>
                        <iframe
                            :key="`live-${liveKey}`"
                            :src="preview.live_url"
                            title="Published page"
                            @load="frameLoaded('live')"
                        />
                    </div>
                </section>
                <section v-if="draftMounted" v-show="!hasLive || active === 'compare' || active === 'draft'">
                    <header>
                        <strong>Secretary draft</strong>
                        <a :href="preview.draft_url" target="_blank" rel="noopener">Open in new tab</a>
                    </header>
                    <div class="secretary-preview-frame" :aria-busy="!draftLoaded">
                        <div v-if="!draftLoaded" class="secretary-preview-frame-loading" role="status">
                            <span class="secretary-preview-spinner" aria-hidden="true" />
                            <span>{{ draftSlow ? 'The draft is taking longer than usual.' : 'Loading Secretary draft …' }}</span>
                            <button v-if="draftSlow" type="button" @click="reload('draft')">Try again</button>
                        </div>
                        <iframe
                            :key="`draft-${draftKey}`"
                            :src="preview.draft_url"
                            title="Secretary draft"
                            @load="frameLoaded('draft')"
                        />
                    </div>
                </section>
            </div>
        </div>
    </ui-modal>
</template>
