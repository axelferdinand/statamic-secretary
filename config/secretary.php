<?php

return [
    'retention_days' => (int) env('SECRETARY_RETENTION_DAYS', 90),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'project' => env('SECRETARY_OPENAI_PROJECT'),
        'model' => env('SECRETARY_OPENAI_MODEL', 'gpt-5.5'),
        'base_url' => env('SECRETARY_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'reasoning_effort' => env('SECRETARY_OPENAI_REASONING_EFFORT', 'medium'),
        'max_output_tokens' => (int) env('SECRETARY_OPENAI_MAX_OUTPUT_TOKENS', 4096),
        'timeout' => (int) env('SECRETARY_OPENAI_TIMEOUT', 120),
        'store' => (bool) env('SECRETARY_OPENAI_STORE', true),
    ],

    'content' => [
        'root' => env('SECRETARY_CONTENT_ROOT'),
        'collections' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SECRETARY_COLLECTIONS', ''))
        ))),
        'taxonomies' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SECRETARY_TAXONOMIES', ''))
        ))),
        'global_sets' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SECRETARY_GLOBAL_SETS', ''))
        ))),
        'navigations' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SECRETARY_NAVIGATIONS', ''))
        ))),
        'max_search_results' => (int) env('SECRETARY_MAX_SEARCH_RESULTS', 20),
        'require_revisions_for_published_entries' => true,
    ],

    'email' => [
        // Null enables the zero-config Postmark onboarding flow. Existing
        // installations may still explicitly opt email in or out.
        'enabled' => env('SECRETARY_EMAIL_ENABLED'),
        'address' => env('SECRETARY_EMAIL_ADDRESS'),
        'from_address' => env('SECRETARY_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
        'from_name' => env('SECRETARY_FROM_NAME', 'Secretary'),
        'mailer' => env('SECRETARY_MAILER'),
        'allowed_senders' => array_values(array_filter(array_map(
            static fn (string $email) => mb_strtolower(trim($email)),
            explode(',', (string) env('SECRETARY_ALLOWED_SENDERS', ''))
        ))),
        'allow_publishing' => (bool) env('SECRETARY_EMAIL_ALLOW_PUBLISHING', false),
        'require_sender_authentication' => (bool) env('SECRETARY_EMAIL_REQUIRE_AUTHENTICATION', true),
        'max_spam_score' => (float) env('SECRETARY_EMAIL_MAX_SPAM_SCORE', 5.0),
        'postmark' => [
            'api_key' => env('POSTMARK_API_KEY'),
            'base_url' => env('SECRETARY_POSTMARK_BASE_URL', 'https://api.postmarkapp.com'),
            'message_stream' => env('SECRETARY_POSTMARK_MESSAGE_STREAM', 'outbound'),
            'username' => env('SECRETARY_POSTMARK_WEBHOOK_USERNAME'),
            'password' => env('SECRETARY_POSTMARK_WEBHOOK_PASSWORD'),
        ],
    ],

    'relay' => [
        'enabled' => env('SECRETARY_RELAY_ENABLED'),
        'pairing_enabled' => (bool) env('SECRETARY_RELAY_PAIRING_ENABLED', false),
        'installation_id' => env('SECRETARY_RELAY_INSTALLATION_ID'),
        'route_token' => env('SECRETARY_RELAY_ROUTE_TOKEN'),
        'signing_secret' => env('SECRETARY_RELAY_SIGNING_SECRET'),
        'base_url' => env('SECRETARY_RELAY_BASE_URL', 'https://secretary.statamic.no'),
        'max_clock_skew' => (int) env('SECRETARY_RELAY_MAX_CLOCK_SKEW', 300),
        'cache_store' => env('SECRETARY_RELAY_CACHE_STORE'),
    ],

    'limits' => [
        'max_input_characters' => (int) env('SECRETARY_MAX_INPUT_CHARACTERS', 20000),
        'max_history_messages' => (int) env('SECRETARY_MAX_HISTORY_MESSAGES', 30),
        'max_tool_rounds' => (int) env('SECRETARY_MAX_TOOL_ROUNDS', 12),
        'max_navigation_nodes' => (int) env('SECRETARY_MAX_NAVIGATION_NODES', 500),
        'max_resource_characters' => (int) env('SECRETARY_MAX_RESOURCE_CHARACTERS', 250000),
        'max_webhook_bytes' => (int) env('SECRETARY_MAX_WEBHOOK_BYTES', 2_000_000),
        'job_timeout' => (int) env('SECRETARY_JOB_TIMEOUT', 1200),
    ],
];
