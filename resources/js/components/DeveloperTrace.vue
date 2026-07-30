<script setup>
defineProps({
    trace: { type: Object, required: true },
});

function tokens(usage) {
    const input = Number(usage?.input_tokens ?? 0).toLocaleString('nb-NO');
    const output = Number(usage?.output_tokens ?? 0).toLocaleString('nb-NO');

    return `${input} inn · ${output} ut`;
}
</script>

<template>
    <details class="secretary-developer-trace">
        <summary>
            <span>Utviklerdetaljer</span>
            <span>{{ trace.duration_ms }} ms · {{ trace.rounds }} runder</span>
        </summary>
        <dl>
            <div>
                <dt>Modell</dt>
                <dd>{{ trace.model }}</dd>
            </div>
            <div>
                <dt>Tokens</dt>
                <dd>{{ tokens(trace.usage) }}</dd>
            </div>
            <div v-if="trace.estimated_cost_usd !== null">
                <dt>Estimert kostnad</dt>
                <dd>USD {{ trace.estimated_cost_usd }}</dd>
            </div>
            <div>
                <dt>Modus</dt>
                <dd>{{ trace.dry_run ? 'Dry-run' : 'Vanlig' }}</dd>
            </div>
        </dl>
        <ol v-if="trace.tools?.length">
            <li v-for="(tool, index) in trace.tools" :key="`${tool.name}-${index}`">
                <span>
                    <ui-icon :name="tool.ok ? 'checkmark' : 'warning-diamond'" class="size-3.5" aria-hidden="true" />
                    <strong>{{ tool.name }}</strong>
                </span>
                <span>{{ tool.duration_ms }} ms</span>
                <code>{{ JSON.stringify(tool.arguments) }}</code>
            </li>
        </ol>
        <p v-else>Ingen verktøykall i denne runden.</p>
    </details>
</template>
