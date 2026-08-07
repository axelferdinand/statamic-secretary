<script setup>
import ChangeReview from './ChangeReview.vue';

const props = defineProps({
    changes: { type: Array, required: true },
    label: { type: String, default: 'Result' },
    canPublish: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    previewLoading: { type: Boolean, default: false },
    publishingId: { type: String, default: null },
    reviewingTarget: { type: String, default: null },
});

const emit = defineEmits(['review', 'preview', 'publish']);

function statusLabel(status) {
    return ({
        proposed: 'Proposed',
        draft: 'Draft ready',
        published: 'Published',
        failed: 'Could not be saved',
    })[status] ?? status;
}

function statusClass(status) {
    return ({
        draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200',
        published: 'bg-green-100 text-green-800 dark:bg-green-950/50 dark:text-green-200',
        failed: 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-200',
    })[status] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200';
}

function actionLabel(change) {
    if (change.status === 'draft') return 'Open draft';
    if (change.status === 'published') return 'Open content';

    return 'Open in Statamic';
}
</script>

<template>
    <section class="space-y-2.5" :aria-label="label">
        <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ label }}
        </h3>

        <article
            v-for="change in changes"
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
                @decide="(target, decision) => emit('review', change, target, decision)"
            />

            <div v-if="change.native_url || change.preview_available || (change.status === 'draft' && canPublish)" class="mt-3 flex flex-wrap items-center gap-2 border-t pt-3 dark:border-gray-700">
                <ui-button
                    v-if="change.native_url"
                    :href="change.native_url"
                    size="sm"
                    variant="default"
                    icon="entry"
                >
                    {{ actionLabel(change) }}
                </ui-button>
                <ui-button
                    v-if="change.preview_available"
                    size="sm"
                    variant="default"
                    icon="eye"
                    :disabled="previewLoading"
                    @click="emit('preview', change)"
                >
                    Compare
                </ui-button>
                <ui-button
                    v-if="change.status === 'draft' && canPublish"
                    class="secretary-change-publish"
                    size="sm"
                    variant="primary"
                    :loading="publishingId === change.id"
                    :disabled="Boolean(publishingId) || processing"
                    @click="emit('publish', change)"
                >
                    Publish now
                </ui-button>
            </div>
        </article>
    </section>
</template>
