<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Content\BlueprintDescriber;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Statamic\Facades\Blink;
use Statamic\Facades\Fieldset;
use Statamic\Fields\Blueprint;

class BlueprintDescriberTest extends TestCase
{
    public function test_it_resolves_imported_bard_set_fields_before_the_set_is_used(): void
    {
        $settings = Fieldset::make('module-settings')->setContents([
            'fields' => [
                [
                    'handle' => 'theme',
                    'field' => [
                        'type' => 'select',
                        'options' => ['light' => 'Light', 'dark' => 'Dark'],
                        'validate' => ['required'],
                    ],
                ],
            ],
        ]);
        $faq = Fieldset::make('faq-content')->setContents([
            'fields' => [
                [
                    'handle' => 'heading',
                    'field' => ['type' => 'text', 'validate' => ['required']],
                ],
                [
                    'handle' => 'items',
                    'field' => [
                        'type' => 'grid',
                        'fields' => [
                            [
                                'handle' => 'question',
                                'field' => ['type' => 'text', 'validate' => ['required']],
                            ],
                            [
                                'handle' => 'answer',
                                'field' => ['type' => 'bard', 'save_html' => true, 'validate' => ['required']],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        Fieldset::shouldReceive('find')->andReturnUsing(
            static fn (string $handle) => match ($handle) {
                'module-settings' => $settings,
                'faq-content' => $faq,
                default => null,
            },
        );
        Blink::flush();

        $describer = app(BlueprintDescriber::class);
        $overview = $describer->describe($this->blueprint());
        $modules = collect($overview['fields'])->firstWhere('handle', 'modules');

        $this->assertSame(['theme', 'heading', 'items'], $modules['sets']['faq']['field_handles']);
        $this->assertTrue($modules['sets']['faq']['exact_schema_required']);
        $this->assertArrayNotHasKey('fields', $modules['sets']['faq']);

        $schema = $describer->describeSet($this->blueprint(), 'modules', 'faq');

        $this->assertSame(['theme', 'heading', 'items'], array_column($schema['fields'], 'handle'));
        $this->assertSame('select', $schema['fields'][0]['type']);
        $this->assertSame(['required'], $schema['fields'][0]['validation']);
        $this->assertSame(['question', 'answer'], array_column($schema['fields'][2]['fields'], 'handle'));
        $this->assertSame('bard', $schema['fields'][2]['fields'][1]['type']);
        $this->assertStringNotContainsString('"import"', json_encode($schema, JSON_THROW_ON_ERROR));
        $this->assertSame('faq', $schema['value_shape']['attrs']['values']['type']);
    }

    public function test_it_identifies_every_bard_set_that_must_be_inspected_before_saving(): void
    {
        $references = app(BlueprintDescriber::class)->structuredSetReferences($this->blueprint(), [
            'title' => 'Agreement requirements',
            'modules' => [
                ['type' => 'heading', 'attrs' => ['level' => 2]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'fact_box']]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'faq']]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'promo']]],
                ['type' => 'set', 'attrs' => ['values' => ['type' => 'faq']]],
            ],
        ]);

        $this->assertSame([
            ['field' => 'modules', 'set' => 'fact_box'],
            ['field' => 'modules', 'set' => 'faq'],
            ['field' => 'modules', 'set' => 'promo'],
        ], $references);
    }

    private function blueprint(): Blueprint
    {
        return (new Blueprint)
            ->setHandle('page')
            ->setContents([
                'tabs' => [
                    'main' => [
                        'sections' => [[
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => ['type' => 'text', 'validate' => ['required']],
                                ],
                                [
                                    'handle' => 'modules',
                                    'field' => [
                                        'type' => 'bard',
                                        'sets' => [
                                            'content' => [
                                                'sets' => [
                                                    'fact_box' => [
                                                        'fields' => [[
                                                            'handle' => 'heading',
                                                            'field' => ['type' => 'text'],
                                                        ]],
                                                    ],
                                                    'faq' => [
                                                        'display' => 'FAQ',
                                                        'fields' => [
                                                            ['import' => 'module-settings'],
                                                            ['import' => 'faq-content'],
                                                        ],
                                                    ],
                                                    'promo' => [
                                                        'fields' => [[
                                                            'handle' => 'heading',
                                                            'field' => ['type' => 'text'],
                                                        ]],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ]],
                    ],
                ],
            ]);
    }
}
