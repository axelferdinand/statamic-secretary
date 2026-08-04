<script setup>
import { computed } from 'vue';

const props = defineProps({
    change: { type: Object, required: true },
    busyTarget: { type: String, default: null },
    compact: { type: Boolean, default: false },
});

const emit = defineEmits(['decide']);
const targets = computed(() => props.change.review?.targets ?? []);

function label(target) {
    const field = target.field
        .replaceAll('_', ' ')
        .replace(/\b\p{L}/gu, letter => letter.toUpperCase());

    if (target.kind !== 'module') return field;

    const type = (target.module_type || `module ${target.module_index + 1}`)
        .replaceAll('_', ' ')
        .replace(/\b\p{L}/gu, letter => letter.toUpperCase());

    return `${field} · ${type}`;
}

function format(value) {
    if (value === null || value === undefined || value === '') return 'Empty';
    if (typeof value === 'string') return value;

    try {
        return JSON.stringify(value, null, 2);
    } catch {
        return String(value);
    }
}

function summary() {
    const review = props.change.review;

    if (!review) return 'Review the changes';
    if (review.rejected) return `${review.rejected} rejected · ${review.accepted} kept`;
    if (review.accepted) return `${review.accepted} reviewed`;

    return `${review.pending} ${review.pending === 1 ? 'change' : 'changes'} to review`;
}
</script>

<template>
    <details v-if="change.review?.available" class="secretary-review">
        <summary>
            <span>
                <strong>Review fields and modules</strong>
                <small>{{ summary() }}</small>
            </span>
            <ui-icon name="chevron-down" class="size-4" aria-hidden="true" />
        </summary>

        <div class="secretary-review-list">
            <article
                v-for="target in targets"
                :key="target.key"
                class="secretary-review-target"
                :class="`is-${target.decision}`"
            >
                <header>
                    <span class="secretary-review-decision" aria-hidden="true">
                        <ui-icon
                            :name="target.decision === 'accepted' ? 'checkmark' : target.decision === 'rejected' ? 'x' : 'checkbox-uncheck'"
                        />
                    </span>
                    <span class="min-w-0 flex-1">
                        <strong>{{ label(target) }}</strong>
                        <small v-if="target.kind === 'module'">Module {{ target.module_index + 1 }}</small>
                    </span>
                </header>

                <div v-if="!compact" class="secretary-review-values">
                    <div>
                        <span>Before</span>
                        <pre>{{ format(target.before) }}</pre>
                    </div>
                    <div>
                        <span>Secretary</span>
                        <pre>{{ format(target.after) }}</pre>
                    </div>
                </div>

                <div class="secretary-review-actions" role="group" :aria-label="`Options for ${label(target)}`">
                    <button
                        type="button"
                        class="secretary-review-button is-accept"
                        :class="{ 'is-active': target.decision === 'accepted' }"
                        :aria-pressed="target.decision === 'accepted'"
                        :disabled="Boolean(busyTarget)"
                        @click="emit('decide', target, target.decision === 'accepted' ? 'pending' : 'accepted')"
                    >
                        <ui-icon name="checkmark" class="size-3.5" aria-hidden="true" />
                        Keep
                    </button>
                    <button
                        type="button"
                        class="secretary-review-button is-reject"
                        :class="{ 'is-active': target.decision === 'rejected' }"
                        :aria-pressed="target.decision === 'rejected'"
                        :disabled="Boolean(busyTarget)"
                        @click="emit('decide', target, target.decision === 'rejected' ? 'pending' : 'rejected')"
                    >
                        <ui-icon name="x" class="size-3.5" aria-hidden="true" />
                        Reject
                    </button>
                    <span v-if="busyTarget === target.key" class="secretary-review-saving" role="status">
                        Saving …
                    </span>
                </div>
            </article>
        </div>
    </details>
</template>
