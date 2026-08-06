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

const selectedMode = ref(props.email.forwarding_confirmation_required
    ? 'postmark'
    : (props.relay.pending_sender ? 'relay' : null));
const busy = ref(false);
const copied = ref(false);
const openaiKey = ref('');
const postmarkKey = ref('');
const emailAddress = ref(props.email.from_address ?? '');
const publicUrl = ref(props.email.suggested_public_url ?? '');
const relayEmail = ref(props.relay.pending_sender ?? props.relay.suggested_sender ?? '');
const relayPublicUrl = ref(props.relay.pending_public_url ?? props.relay.suggested_public_url ?? '');
const pairingCode = ref('');
const emailReady = computed(() => props.email.connected || props.relay.connected);
const pairingCodeReady = computed(() => /^pc_[A-Za-z0-9_-]{43}$/.test(pairingCode.value.trim())
    && Boolean(relayPublicUrl.value.trim()));
const setupError = computed(() => props.errors?.openai_api_key
    ?? props.errors?.api_key
    ?? props.errors?.safe_drafting
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
        // The address is shown only after a valid site URL is available.
    }

    return null;
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

function enableSafeDrafting() {
    submit(props.onboarding.safe_drafting.setup_url, {});
}

function requestRelayCode() {
    if (!relayPublicUrl.value.trim() || !relayEmail.value.trim()) return;
    submit(props.relay.request_code_url, {
        public_url: relayPublicUrl.value.trim(),
        email: relayEmail.value.trim(),
    }, {
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

function confirmPostmarkForwarding() {
    submit(props.email.confirm_forwarding_url, {});
}

async function copyInboundAddress() {
    if (!props.email.inbound_address) return;

    try {
        await navigator.clipboard.writeText(props.email.inbound_address);
        copied.value = true;
    } catch {
        copied.value = false;
    }
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
                <h2 v-if="!configured" id="secretary-onboarding-title">Let’s get Secretary ready.</h2>
                <h2 v-else-if="!onboarding.safe_drafting.ready" id="secretary-onboarding-title">Protect the live site first.</h2>
                <h2 v-else-if="email.forwarding_confirmation_required" id="secretary-onboarding-title">One final email step.</h2>
                <h2 v-else id="secretary-onboarding-title">Now connect the best part: email.</h2>
                <p v-if="!configured">Connect AI, turn on safe drafts, then choose your Secretary email address.</p>
                <p v-else-if="!onboarding.safe_drafting.ready">Secretary needs a private working copy so live content never changes before you approve it.</p>
                <p v-else-if="email.forwarding_confirmation_required">Connect your public mailbox to the Postmark address below.</p>
                <p v-else>Send a normal email, receive a Statamic draft, review it, and publish when you are happy.</p>
            </div>
        </div>

        <ol class="secretary-onboarding-progress has-three" :class="{ 'has-four': email.forwarding_confirmation_required }" aria-label="Setup progress">
            <li :class="{ 'is-current': !configured, 'is-complete': configured }">
                <span>{{ configured ? '✓' : '1' }}</span>
                <div><strong>Connect OpenAI</strong><small>{{ configured ? 'Connected' : 'Required' }}</small></div>
            </li>
            <li :class="{ 'is-current': configured && !onboarding.safe_drafting.ready, 'is-complete': onboarding.safe_drafting.ready }">
                <span>{{ onboarding.safe_drafting.ready ? '✓' : '2' }}</span>
                <div><strong>Protect live content</strong><small>{{ onboarding.safe_drafting.ready ? 'Protected' : 'Required' }}</small></div>
            </li>
            <li :class="{ 'is-current': configured && onboarding.safe_drafting.ready && !emailReady, 'is-complete': emailReady }">
                <span>{{ emailReady ? '✓' : '3' }}</span>
                <div><strong>Connect email</strong><small>{{ emailReady ? 'Connected' : 'Recommended' }}</small></div>
            </li>
            <li v-if="email.forwarding_confirmation_required" class="is-current">
                <span>4</span>
                <div><strong>Forward email</strong><small>Required</small></div>
            </li>
        </ol>

        <div v-if="success || setupError" class="secretary-onboarding-notices" aria-live="polite">
            <ui-alert v-if="success" variant="success" heading="Done" :text="success" />
            <ui-alert v-if="setupError" variant="error" heading="Setup could not be completed" :text="setupError" />
        </div>

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

        <div v-else-if="!onboarding.safe_drafting.ready" class="secretary-onboarding-step">
            <div class="secretary-onboarding-step-heading">
                <span>2</span>
                <div>
                    <h3>Turn on safe drafts</h3>
                    <p>Secretary uses Statamic revisions to keep every proposed change separate from the published page.</p>
                </div>
            </div>

            <div class="secretary-safe-drafting-flow" aria-label="How Secretary protects published content">
                <span><ui-icon name="mail" aria-hidden="true" /><strong>1. Send an instruction</strong><small>Use email or Control Panel chat.</small></span>
                <ui-icon name="arrow-right" aria-hidden="true" />
                <span><ui-icon name="edit" aria-hidden="true" /><strong>2. Secretary makes a draft</strong><small>The live page stays unchanged.</small></span>
                <ui-icon name="arrow-right" aria-hidden="true" />
                <span><ui-icon name="checkmark" aria-hidden="true" /><strong>3. You decide</strong><small>Review, refine, then publish.</small></span>
            </div>

            <ui-alert
                v-if="!onboarding.safe_drafting.pro"
                variant="error"
                heading="Statamic Pro is required"
                text="Revisions are a Statamic Pro feature. Activate the site’s Pro license, then return here."
            />
            <div v-else class="secretary-onboarding-confirm">
                <ui-button type="button" variant="primary" :loading="busy" :disabled="busy" @click="enableSafeDrafting">
                    Enable safe drafts
                </ui-button>
                <small>This enables revisions for current collections. It does not change or publish any entry content.</small>
            </div>
        </div>

        <div v-else-if="email.forwarding_confirmation_required" class="secretary-onboarding-step">
            <div class="secretary-onboarding-step-heading">
                <span>4</span>
                <div>
                    <h3>Forward your public mailbox</h3>
                    <p>Postmark and the Secretary webhook are connected. Your mailbox now needs one forwarding rule.</p>
                </div>
            </div>

            <div class="secretary-forwarding-step">
                <span>
                    <small>People send instructions to</small>
                    <strong>{{ email.from_address }}</strong>
                </span>
                <ui-icon name="arrow-right" aria-hidden="true" />
                <span>
                    <small>Forward every message to</small>
                    <strong>{{ email.inbound_address }}</strong>
                </span>
                <button type="button" class="secretary-copy-address" @click="copyInboundAddress">
                    {{ copied ? 'Copied' : 'Copy address' }}
                </button>
            </div>

            <p class="secretary-onboarding-explanation">
                Create this forwarding rule with the email provider that hosts <strong>{{ email.from_address }}</strong>. Do not forward Secretary replies back into this mailbox.
            </p>

            <div class="secretary-onboarding-confirm">
                <ui-button type="button" variant="primary" :loading="busy" :disabled="busy" @click="confirmPostmarkForwarding">
                    I’ve set up forwarding
                </ui-button>
                <small>This confirms the external mailbox rule; Secretary cannot inspect your email provider.</small>
            </div>
        </div>

        <div v-else class="secretary-onboarding-step">
            <div class="secretary-onboarding-step-heading">
                <span>3</span>
                <div>
                    <h3>How should email work?</h3>
                    <p>This is Secretary’s core workflow: editors email instructions and receive a link to a safe Statamic draft.</p>
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
                <p class="secretary-onboarding-explanation">
                    Start with the live site address. Secretary uses its domain to create your unique <code>@statamic.no</code> address.
                </p>

                <form class="secretary-onboarding-inner-form" @submit.prevent="requestRelayCode">
                    <div class="secretary-onboarding-url-first">
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
                        <p v-if="!relay.suggested_public_url">Use the live HTTPS address. A local <code>.test</code> address cannot receive relay email.</p>
                    </div>
                    <div class="secretary-email-example">
                        <span>
                            <small>Your Secretary address</small>
                            <strong v-if="relayAddress">{{ relayAddress }}</strong>
                            <em v-else>Enter the public URL above</em>
                        </span>
                        <span class="text-xs text-gray-500">Included during beta</span>
                    </div>
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
                        <p>Prefilled from your signed-in Statamic user. You can choose another authorized user.</p>
                    </div>
                    <ui-button type="submit" :loading="busy" :disabled="busy || !relayPublicUrl.trim() || !relayEmail.trim()">
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
                    <ui-button
                        type="submit"
                        :variant="pairingCodeReady ? 'primary' : 'default'"
                        :loading="busy"
                        :disabled="busy || !pairingCodeReady"
                    >
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
