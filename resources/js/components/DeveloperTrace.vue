<script setup>
defineProps({
    trace: { type: Object, required: true },
});

function tokens(usage) {
    const input = Number(usage?.input_tokens ?? 0).toLocaleString('en-US');
    const output = Number(usage?.output_tokens ?? 0).toLocaleString('en-US');

    return `${input} in · ${output} out`;
}
</script>

<template>
    <details class="secretary-developer-trace">
        <summary>
            <span>Developer details</span>
            <span>{{ trace.duration_ms }} ms · {{ trace.rounds }} rounds</span>
        </summary>
        <dl>
            <div>
                <dt>Model</dt>
                <dd>{{ trace.model }}</dd>
            </div>
            <div>
                <dt>Tokens</dt>
                <dd>{{ tokens(trace.usage) }}</dd>
            </div>
            <div v-if="trace.estimated_cost_usd !== null">
                <dt>Estimated cost</dt>
                <dd>USD {{ trace.estimated_cost_usd }}</dd>
            </div>
            <div>
                <dt>Mode</dt>
                <dd>{{ trace.dry_run ? 'Dry run' : 'Standard' }}</dd>
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
        <p v-else>No tool calls in this round.</p>
    </details>
</template>
