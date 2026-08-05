<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    preview: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const emit = defineEmits(['close']);
const active = ref('draft');
const hasLive = computed(() => Boolean(props.preview?.live_url));

watch(() => props.open, value => {
    if (value) active.value = 'draft';
});
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

        <div v-if="loading" class="secretary-preview-loading" role="status">
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
            <div v-if="hasLive" class="secretary-preview-toolbar">
                <span class="secretary-preview-toolbar-label">View</span>
                <div class="secretary-preview-tabs" role="tablist" aria-label="Choose preview">
                    <button
                        type="button"
                        role="tab"
                        class="secretary-preview-compare-option"
                        :aria-selected="active === 'compare'"
                        :class="{ 'is-active': active === 'compare' }"
                        @click="active = 'compare'"
                    >
                        Side by side
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="active === 'live'"
                        :class="{ 'is-active': active === 'live' }"
                        @click="active = 'live'"
                    >
                        Published
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="active === 'draft'"
                        :class="{ 'is-active': active === 'draft' }"
                        @click="active = 'draft'"
                    >
                        Secretary draft
                    </button>
                </div>
            </div>

            <div
                class="secretary-preview-grid"
                :class="{ 'has-live': hasLive, 'is-comparing': hasLive && active === 'compare' }"
            >
                <section v-if="hasLive" v-show="active === 'compare' || active === 'live'">
                    <header>
                        <strong>Published</strong>
                        <a :href="preview.live_url" target="_blank" rel="noopener">Open in new tab</a>
                    </header>
                    <iframe :src="preview.live_url" title="Published page" />
                </section>
                <section v-show="!hasLive || active === 'compare' || active === 'draft'">
                    <header>
                        <strong>Secretary draft</strong>
                        <a :href="preview.draft_url" target="_blank" rel="noopener">Open in new tab</a>
                    </header>
                    <iframe :src="preview.draft_url" title="Secretary draft" />
                </section>
            </div>
        </div>
    </ui-modal>
</template>
