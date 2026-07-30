<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ControlPanelAssetsTest extends TestCase
{
    public function test_secretary_launcher_is_teleported_and_fixed_to_the_viewport(): void
    {
        $root = dirname(__DIR__, 2);
        $component = file_get_contents($root.'/resources/js/components/SecretaryPanel.vue');
        $stylesheet = file_get_contents($root.'/resources/css/addon.css');

        $this->assertIsString($component);
        $this->assertStringContainsString('<Teleport to="body">', $component);
        $this->assertStringContainsString('class="secretary-panel-launcher"', $component);
        $this->assertStringContainsString('aria-label="Åpne Secretary-chat"', $component);

        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('position: fixed !important', $stylesheet);
        $this->assertStringContainsString('right: max(1rem, env(safe-area-inset-right)) !important', $stylesheet);
        $this->assertStringContainsString('bottom: max(1rem, env(safe-area-inset-bottom)) !important', $stylesheet);
        $this->assertStringContainsString('@media (max-width: 39.999rem)', $stylesheet);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $stylesheet);
    }

    public function test_an_email_link_can_open_its_conversation_in_the_contextual_panel(): void
    {
        $component = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SecretaryPanel.vue',
        );

        $this->assertIsString($component);
        $this->assertStringContainsString("searchParams.get('secretary')", $component);
        $this->assertStringContainsString('open.value = true', $component);
        $this->assertStringContainsString('load(conversationId, {', $component);
        $this->assertStringContainsString('const conversationId = linkedConversationId(pageUrl.value)', $component);
        $this->assertStringContainsString('const conversationId = linkedConversationId(nextUrl)', $component);
        $this->assertStringContainsString('allowAutoOpen: true', $component);
        $this->assertStringContainsString('payload.auto_open === true', $component);
        $this->assertStringContainsString('Secretary åpnet e-posttråden', $component);
        $this->assertStringContainsString('Fortsett e-posttråden her', $component);
        $this->assertStringContainsString('Samme samtale, nå i Statamic', $component);
    }

    public function test_the_panel_follows_inertia_navigation_and_keeps_page_scoped_drafts(): void
    {
        $component = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SecretaryPanel.vue',
        );

        $this->assertIsString($component);
        $this->assertStringContainsString("router.on('navigate', syncPageContext)", $component);
        $this->assertStringContainsString("window.addEventListener('popstate', syncPageContext)", $component);
        $this->assertStringContainsString('active_context_key', $component);
        $this->assertStringContainsString('window.localStorage.setItem', $component);
        $this->assertStringContainsString('backgroundJobs', $component);
        $this->assertStringContainsString("conversation.processing ? 'Sett i kø' : 'Send'", $component);
        $this->assertStringNotContainsString(
            ':disabled="busy || conversation.processing || !configured"',
            $component,
        );
    }

    public function test_follow_up_messages_can_be_queued_in_the_full_view(): void
    {
        $component = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/pages/Secretary.vue',
        );

        $this->assertIsString($component);
        $this->assertStringContainsString("processing ? 'Sett i kø' : 'Send'", $component);
        $this->assertStringContainsString('item.queue_position', $component);
        $this->assertStringNotContainsString(
            ':disabled="busy || processing || !configured"',
            $component,
        );
    }

    public function test_the_contextual_panel_does_not_link_to_the_full_view(): void
    {
        $component = file_get_contents(
            dirname(__DIR__, 2).'/resources/js/components/SecretaryPanel.vue',
        );

        $this->assertIsString($component);
        $this->assertStringNotContainsString('Full visning', $component);
        $this->assertStringNotContainsString('Se før og etter', $component);
        $this->assertStringNotContainsString('conversation.full_url', $component);
    }

    public function test_compiled_control_panel_assets_match_the_manifest(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents($root.'/resources/dist/build/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $cssFile = $root.'/resources/dist/build/'.$manifest['resources/css/addon.css']['file'];
        $scriptFile = $root.'/resources/dist/build/'.$manifest['resources/js/addon.js']['file'];

        $this->assertFileExists($cssFile);
        $this->assertFileExists($scriptFile);
        $this->assertStringContainsString(
            'position:fixed!important',
            (string) file_get_contents($cssFile),
        );
        $this->assertStringContainsString(
            'secretary-panel-launcher',
            (string) file_get_contents($scriptFile),
        );
    }
}
