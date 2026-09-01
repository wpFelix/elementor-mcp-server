<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Tests\Unit;

use PHPUnit\Framework\TestCase;

use function ElementorMCP\PromptLibrary\packs;

final class PromptLibraryTest extends TestCase
{
    public function test_free_ships_exactly_ten_industry_prompts(): void
    {
        $packs = packs();
        $prompts = array_merge(...array_column($packs, 'prompts'));

        self::assertCount(10, $packs);
        self::assertCount(10, $prompts);
        self::assertCount(10, array_unique(array_column($packs, 'slug')));
        self::assertCount(10, array_unique(array_column($prompts, 'title')));

        foreach ($packs as $pack) {
            self::assertSame('industry', $pack['group']);
            self::assertFalse($pack['pro']);
            self::assertCount(1, $pack['prompts']);
            self::assertStringContainsString('[CONFIRM', $pack['prompts'][0]['prompt']);
            self::assertStringContainsString('draft', mb_strtolower($pack['prompts'][0]['prompt']));
            self::assertStringContainsString('DESIGN SYSTEM', $pack['prompts'][0]['prompt']);
            self::assertStringContainsString('SAFE BUILD, VERIFICATION, AND HANDOFF', $pack['prompts'][0]['prompt']);
            self::assertGreaterThanOrEqual(1000, str_word_count($pack['prompts'][0]['prompt']));
        }
    }
}
