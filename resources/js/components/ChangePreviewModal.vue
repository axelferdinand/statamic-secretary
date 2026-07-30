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
    if (value) active.value = props.preview?.live_url ? 'live' : 'draft';
});
</script>

<template>
    <ui-modal
        :open="open"
        :title="preview?.title ? `Forhåndsvisning · ${preview.title}` : 'Forhåndsvisning'"
        icon="eye"
        @update:open="value => { if (!value) emit('close') }"
    >
        <div v-if="loading" class="secretary-preview-loading" role="status">
            <ui-skeleton class="h-5 w-48" />
            <ui-skeleton class="mt-4 h-[30rem] w-full rounded-lg" />
        </div>

        <ui-alert
            v-else-if="error"
            variant="error"
            heading="Forhåndsvisningen kunne ikke åpnes"
            :text="error"
        />

        <div v-else-if="preview" class="secretary-preview">
            <div v-if="hasLive" class="secretary-preview-tabs" role="tablist" aria-label="Velg forhåndsvisning">
                <button
                    type="button"
                    role="tab"
                    :aria-selected="active === 'live'"
                    :class="{ 'is-active': active === 'live' }"
                    @click="active = 'live'"
                >
                    Publisert
                </button>
                <button
                    type="button"
                    role="tab"
                    :aria-selected="active === 'draft'"
                    :class="{ 'is-active': active === 'draft' }"
                    @click="active = 'draft'"
                >
                    Secretary-utkast
                </button>
            </div>

            <div class="secretary-preview-grid" :class="{ 'has-live': hasLive }">
                <section v-if="hasLive" :class="{ 'is-mobile-hidden': active !== 'live' }">
                    <header>
                        <strong>Publisert</strong>
                        <a :href="preview.live_url" target="_blank" rel="noopener">Åpne i ny fane</a>
                    </header>
                    <iframe :src="preview.live_url" title="Publisert side" />
                </section>
                <section :class="{ 'is-mobile-hidden': hasLive && active !== 'draft' }">
                    <header>
                        <strong>Secretary-utkast</strong>
                        <a :href="preview.draft_url" target="_blank" rel="noopener">Åpne i ny fane</a>
                    </header>
                    <iframe :src="preview.draft_url" title="Secretary-utkast" />
                </section>
            </div>
        </div>
    </ui-modal>
</template>
