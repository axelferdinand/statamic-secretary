<?php

namespace AxelFerdinand\StatamicSecretary\Tests\Unit;

use AxelFerdinand\StatamicSecretary\Content\BlueprintValues;
use AxelFerdinand\StatamicSecretary\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use Statamic\Fields\Blueprint;

class BlueprintValuesTest extends TestCase
{
    public function test_it_validates_stored_bard_html_without_rewriting_unchanged_modules(): void
    {
        $existing = [
            'title' => 'Bard testside',
            'modules' => $this->modules(),
        ];
        $changedModules = $existing['modules'];
        $changedModules[0]['attrs']['values']['heading'] = 'Kan en AI redigere en ekte Statamic-side? Ja, det kan den!';

        $result = app(BlueprintValues::class)->mergeAndValidate(
            $this->blueprint(),
            $existing,
            ['modules' => $changedModules],
            storageExisting: $existing,
        );

        $this->assertSame($changedModules, $result['modules']);
        $this->assertSame(
            '<p>Secretary skal bevare <strong>rik tekst</strong>.</p>',
            $result['modules'][1]['attrs']['values']['body'],
        );
        $this->assertArrayNotHasKey('id', $result['modules'][2]['attrs']['values']['items'][0]);
    }

    public function test_it_still_rejects_an_empty_required_nested_bard_field(): void
    {
        $existing = [
            'title' => 'Bard testside',
            'modules' => $this->modules(),
        ];
        $changedModules = $existing['modules'];
        $changedModules[1]['attrs']['values']['body'] = '';

        $this->expectException(ValidationException::class);

        app(BlueprintValues::class)->mergeAndValidate(
            $this->blueprint(),
            $existing,
            ['modules' => $changedModules],
            storageExisting: $existing,
        );
    }

    private function blueprint(): Blueprint
    {
        return (new Blueprint)
            ->setHandle('page')
            ->setContents([
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    [
                                        'handle' => 'title',
                                        'field' => [
                                            'type' => 'text',
                                            'validate' => ['required'],
                                        ],
                                    ],
                                    [
                                        'handle' => 'modules',
                                        'field' => [
                                            'type' => 'bard',
                                            'sets' => [
                                                'editorial' => [
                                                    'sets' => [
                                                        'hero' => [
                                                            'fields' => [
                                                                [
                                                                    'handle' => 'heading',
                                                                    'field' => [
                                                                        'type' => 'text',
                                                                        'validate' => ['required'],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                        'rich_text' => [
                                                            'fields' => [
                                                                [
                                                                    'handle' => 'body',
                                                                    'field' => [
                                                                        'type' => 'bard',
                                                                        'save_html' => true,
                                                                        'validate' => ['required'],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                        'feature_grid' => [
                                                            'fields' => [
                                                                [
                                                                    'handle' => 'items',
                                                                    'field' => [
                                                                        'type' => 'grid',
                                                                        'fields' => [
                                                                            [
                                                                                'handle' => 'title',
                                                                                'field' => [
                                                                                    'type' => 'text',
                                                                                    'validate' => ['required'],
                                                                                ],
                                                                            ],
                                                                        ],
                                                                    ],
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function modules(): array
    {
        return [
            [
                'type' => 'set',
                'attrs' => [
                    'id' => 'demohero',
                    'values' => [
                        'type' => 'hero',
                        'heading' => 'Kan en AI redigere en ekte Statamic-side?',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => 'demotext',
                    'values' => [
                        'type' => 'rich_text',
                        'body' => '<p>Secretary skal bevare <strong>rik tekst</strong>.</p>',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => 'demofeatures',
                    'values' => [
                        'type' => 'feature_grid',
                        'items' => [
                            ['title' => 'Mikroendring'],
                            ['title' => 'Strukturert oppdatering'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
