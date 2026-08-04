<script setup>
import { router } from '@statamic/cms/inertia';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    configured: { type: Boolean, required: true },
    openai: { type: Object, required: true },
    email: { type: Object, required: true },
    relay: { type: Object, required: true },
    onboarding: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    success: { type: String, default: null },
});

const selectedMode = ref(props.relay.pending_sender ? 'relay' : null);
const busy = ref(false);
const openaiKey = ref('');
const postmarkKey = ref('');
const emailAddress = ref(props.email.from_address ?? '');
const publicUrl = ref(props.email.suggested_public_url ?? '');
const relayEmail = ref(props.relay.pending_sender ?? props.relay.suggested_sender ?? '');
const relayPublicUrl = ref(props.relay.suggested_public_url ?? '');
const pairingCode = ref('');
const setupError = computed(() => props.errors?.openai_api_key
    ?? props.errors?.api_key
    ?? props.errors?.relay_setup
    ?? props.errors?.pairing_code
    ?? props.errors?.relay_email
    ?? props.errors?.postmark_setup
    ?? props.errors?.postmark_api_key
    ?? props.errors?.email
    ?? props.errors?.public_url
    ?? null);
const relayAddress = computed(() => {
    try {
        const hostname = new URL(relayPublicUrl.value).hostname.replace(/^www\./, '');

        if (hostname && !hostname.endsWith('.test')) return `${hostname}@statamic.no`;
    } catch {
        // The example remains visible until a public URL is available.
    }

    return 'yourdomain.com@statamic.no';
});

function submit(url, payload, options = {}) {
    if (busy.value) return;

    router.post(url, payload, {
        preserveScroll: true,
        onStart: () => busy.value = true,
        onSuccess: options.onSuccess,
        onFinish: () => busy.value = false,
    });
}

function saveOpenAI() {
    if (!openaiKey.value.trim()) return;
    submit(props.openai.setup_url, { api_key: openaiKey.value.trim() });
}

function requestRelayCode() {
    if (!relayEmail.value.trim()) return;
    submit(props.relay.request_code_url, { email: relayEmail.value.trim() }, {
        onSuccess: () => nextTick(() => document.getElementById('secretary-onboarding-code')?.focus()),
    });
}

function connectRelay() {
    if (!pairingCode.value.trim() || !relayPublicUrl.value.trim()) return;
    submit(props.relay.setup_url, {
        pairing_code: pairingCode.value.trim(),
        public_url: relayPublicUrl.value.trim(),
    });
}

function connectPostmark() {
    if ((!props.email.token_configured && !postmarkKey.value.trim())
        || !emailAddress.value.trim()
        || !publicUrl.value.trim()) return;

    submit(props.email.setup_url, {
        api_key: postmarkKey.value.trim() || null,
        email: emailAddress.value.trim(),
        public_url: publicUrl.value.trim(),
    });
}

function skipEmail() {
    submit(props.onboarding.skip_email_url, {});
}
</script>

<template>
    <section class="secretary-onboarding" aria-labelledby="secretary-onboarding-title">
        <div class="secretary-onboarding-intro">
            <div class="secretary-onboarding-icon">
                <ui-icon name="ai-chat-spark" aria-hidden="true" />
            </div>
            <div>
                <span class="secretary-onboarding-kicker">Welcome to Secretary</span>
                <h2 id="secretary-onboarding-title">Two choices, then you’re ready.</h2>
                <p>No terminal knowledge required. You can change everything later.</p>
            </div>
        </div>

        <ol class="secretary-onboarding-progress" aria-label="Setup progress">
            <li :class="{ 'is-current': !configured, 'is-complete': configured }">
                <span>{{ configured ? '✓' : '1' }}</span>
                <div><strong>Connect OpenAI</strong><small>{{ configured ? 'Connected' : 'Required' }}</small></div>
            </li>
            <li :class="{ 'is-current': configured }">
                <span>2</span>
                <div><strong>Choose email setup</strong><small>Optional</small></div>
            </li>
        </ol>

        <ui-alert v-if="success" variant="success" heading="Done" :text="success" />
        <ui-alert v-if="setupError" variant="error" heading="Setup could not be completed" :text="setupError" />

        <form v-if="!configured" class="secretary-onboarding-step" @submit.prevent="saveOpenAI">
            <div class="secretary-onboarding-step-heading">
                <span>1</span>
                <div>
                    <h3>Connect your OpenAI account</h3>
                    <p>Secretary uses your API account. The key is encrypted before it is stored in this site’s database.</p>
                </div>
            </div>
            <div class="secretary-onboarding-form-row">
                <div>
                    <label for="secretary-onboarding-openai">OpenAI API key</label>
                    <input
                        id="secretary-onboarding-openai"
                        v-model="openaiKey"
                        class="secretary-settings-input w-full font-mono"
                        type="password"
                        autocomplete="off"
                        placeholder="sk-…"
                        required
                    >
                    <p>You can alternatively set <code>OPENAI_API_KEY</code> in the environment.</p>
                </div>
                <ui-button type="submit" variant="primary" :loading="busy" :disabled="busy || !openaiKey.trim()">
                    Save and continue
                </ui-button>
            </div>
        </form>

        <div v-else class="secretary-onboarding-step">
            <div class="secretary-onboarding-step-heading">
                <span>2</span>
                <div>
                    <h3>How should email work?</h3>
                    <p>Choose the easy hosted setup or connect infrastructure you already control.</p>
                </div>
            </div>

            <div v-if="!selectedMode" class="secretary-onboarding-choices">
                <button type="button" class="secretary-onboarding-choice is-recommended" @click="selectedMode = 'relay'">
                    <span class="secretary-choice-badge">Recommended</span>
                    <ui-icon name="mail" aria-hidden="true" />
                    <strong>Easy setup</strong>
                    <small>Use Secretary Relay</small>
                    <p>No Postmark account, mailbox, webhook, or server configuration.</p>
                    <span class="secretary-choice-action">Choose easy setup <ui-icon name="arrow-right" aria-hidden="true" /></span>
                </button>
                <button type="button" class="secretary-onboarding-choice" @click="selectedMode = 'postmark'">
                    <ui-icon name="cog" aria-hidden="true" />
                    <strong>Advanced setup</strong>
                    <small>Use your own Postmark server</small>
                    <p>For teams that want to own the email infrastructure and public mailbox.</p>
                    <span class="secretary-choice-action">Choose advanced setup <ui-icon name="arrow-right" aria-hidden="true" /></span>
                </button>
            </div>

            <div v-else-if="selectedMode === 'relay'" class="secretary-onboarding-method">
                <div class="secretary-onboarding-method-header">
                    <button type="button" @click="selectedMode = null"><ui-icon name="arrow-left" aria-hidden="true" /> Back</button>
                    <ui-badge variant="success">Easy setup</ui-badge>
                </div>
                <div class="secretary-email-example">
                    <span><small>Your email address</small><strong>{{ relayAddress }}</strong></span>
                    <span class="text-xs text-gray-500">Included during beta</span>
                </div>
                <p class="secretary-onboarding-explanation">
                    Verify an existing Statamic user. We send a one-time code, then connect this site automatically.
                </p>

                <form class="secretary-onboarding-inner-form" @submit.prevent="requestRelayCode">
                    <div>
                        <label for="secretary-onboarding-relay-email">Who will send instructions?</label>
                        <input
                            id="secretary-onboarding-relay-email"
                            v-model="relayEmail"
                            class="secretary-settings-input w-full"
                            type="email"
                            autocomplete="email"
                            placeholder="editor@example.com"
                            required
                        >
                        <p>This must be an existing Statamic user with access to Secretary.</p>
                    </div>
                    <ui-button type="submit" :loading="busy" :disabled="busy || !relayEmail.trim()">
                        Send verification code
                    </ui-button>
                </form>

                <form v-if="relay.pending_sender" class="secretary-onboarding-inner-form is-code" @submit.prevent="connectRelay">
                    <div>
                        <label for="secretary-onboarding-code">Verification code</label>
                        <input
                            id="secretary-onboarding-code"
                            v-model="pairingCode"
                            class="secretary-settings-input w-full font-mono"
                            type="text"
                            autocomplete="one-time-code"
                            autocapitalize="none"
                            spellcheck="false"
                            placeholder="pc_…"
                            required
                        >
                        <p>Sent to {{ relay.pending_sender }}. Valid for 15 minutes.</p>
                    </div>
                    <div>
                        <label for="secretary-onboarding-relay-url">This site’s public URL</label>
                        <input
                            id="secretary-onboarding-relay-url"
                            v-model="relayPublicUrl"
                            class="secretary-settings-input w-full"
                            type="url"
                            inputmode="url"
                            placeholder="https://example.com"
                            required
                        >
                        <p v-if="!relay.suggested_public_url">The relay needs a public HTTPS URL; a local <code>.test</code> address cannot receive email.</p>
                    </div>
                    <ui-button type="submit" variant="primary" :loading="busy" :disabled="busy || !pairingCode.trim() || !relayPublicUrl.trim()">
                        Connect email
                    </ui-button>
                </form>
            </div>

            <form v-else class="secretary-onboarding-method" @submit.prevent="connectPostmark">
                <div class="secretary-onboarding-method-header">
                    <button type="button" @click="selectedMode = null"><ui-icon name="arrow-left" aria-hidden="true" /> Back</button>
                    <ui-badge variant="default">Advanced setup</ui-badge>
                </div>
                <p class="secretary-onboarding-explanation">
                    Secretary will configure the inbound webhook on your Postmark server. You still control the server and public mailbox.
                </p>
                <div class="secretary-onboarding-fields">
                    <div v-if="!email.token_configured" class="md:col-span-2">
                        <label for="secretary-onboarding-postmark-key">Postmark Server API Token</label>
                        <input
                            id="secretary-onboarding-postmark-key"
                            v-model="postmarkKey"
                            class="secretary-settings-input w-full font-mono"
                            type="password"
                            autocomplete="off"
                            placeholder="Paste the token from your Postmark server"
                            required
                        >
                        <p>Stored encrypted in this site’s database. It is never shown again.</p>
                    </div>
                    <div>
                        <label for="secretary-onboarding-email">Public email address</label>
                        <input
                            id="secretary-onboarding-email"
                            v-model="emailAddress"
                            class="secretary-settings-input w-full"
                            type="email"
                            autocomplete="email"
                            placeholder="secretary@example.com"
                            required
                        >
                        <p>The address people will send instructions to. You create or forward this mailbox.</p>
                    </div>
                    <div>
                        <label for="secretary-onboarding-public-url">This site’s public URL</label>
                        <input
                            id="secretary-onboarding-public-url"
                            v-model="publicUrl"
                            class="secretary-settings-input w-full"
                            type="url"
                            inputmode="url"
                            placeholder="https://example.com"
                            required
                        >
                        <p v-if="!email.suggested_public_url">Postmark needs a public HTTPS URL; a local <code>.test</code> address cannot receive email.</p>
                    </div>
                </div>
                <ui-button type="submit" variant="primary" :loading="busy" :disabled="busy || (!email.token_configured && !postmarkKey.trim()) || !emailAddress.trim() || !publicUrl.trim()">
                    Connect Postmark
                </ui-button>
            </form>

            <div class="secretary-onboarding-skip">
                <button type="button" :disabled="busy" @click="skipEmail">Use Control Panel chat only for now</button>
                <small>Email can be connected later from Settings.</small>
            </div>
        </div>
    </section>
</template>
