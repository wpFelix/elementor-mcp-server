<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;
use ElementorMCP_Test_State;

use function ElementorMCP\Abilities\WordPress\wordpress_core_permission;
use function ElementorMCP\Abilities\WordPress\wordpress_core_permission_for;
use function ElementorMCP\Abilities\WordPress\wordpress_restore_revision;
use function ElementorMCP\Abilities\WordPress\wordpress_revision_is_autosave;
use function ElementorMCP\Abilities\WordPress\wordpress_taxonomy_is_agent_facing;
use function ElementorMCP\Abilities\WordPress\wordpress_unsafe_url_error;
use function ElementorMCP\Abilities\WordPress\wordpress_update_status_capability_error;

/**
 * The WordPress-core surface: what registers, and the gates around it.
 */
final class CoreSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        ElementorMCP_Test_State::reset();
    }

    protected function tearDown(): void
    {
        ElementorMCP_Test_State::reset();
    }

    // ------------------------------------------------------------ registration

    /**
     * The module came up during bootstrap against WordPress doubles only. No Pro
     * class, licence check, or entitlement service exists in that environment,
     * so a successful load is itself the proof that Free stands alone.
     */
    public function testCoreSurfaceRegistersWithFreeAlone(): void
    {
        self::assertNotEmpty(ELEMENTOR_MCP_TEST_BOOT_ABILITIES);
        self::assertCount(62, ELEMENTOR_MCP_TEST_BOOT_ABILITIES);
    }

    public function testNoAbilityIsRegisteredTwice(): void
    {
        $names = ELEMENTOR_MCP_TEST_BOOT_ABILITIES;

        self::assertSame(
            array_values(array_unique($names)),
            array_values($names),
            'Duplicate ability registration: ' . implode(
                ', ',
                array_keys(array_filter(array_count_values($names), static fn(int $n): bool => $n > 1)),
            ),
        );
    }

    /**
     * Every domain the WordPress-core requirement covers must be present.
     */
    #[DataProvider('requiredAbilities')]
    public function testRequiredAbilityIsRegistered(string $ability): void
    {
        self::assertContains($ability, ELEMENTOR_MCP_TEST_BOOT_ABILITIES);
    }

    /** @return array<string, array{0: string}> */
    public static function requiredAbilities(): array
    {
        $names = [
            // Content.
            'elementor-mcp/list-content', 'elementor-mcp/get-content', 'elementor-mcp/search-content',
            'elementor-mcp/create-post', 'elementor-mcp/update-post', 'elementor-mcp/delete-post', 'elementor-mcp/restore-post',
            // Taxonomies.
            'elementor-mcp/list-taxonomies', 'elementor-mcp/list-terms', 'elementor-mcp/get-term',
            'elementor-mcp/create-term', 'elementor-mcp/update-term', 'elementor-mcp/delete-term', 'elementor-mcp/assign-terms',
            // Media.
            'elementor-mcp/list-media', 'elementor-mcp/get-media', 'elementor-mcp/import-media-url',
            'elementor-mcp/update-media', 'elementor-mcp/delete-media',
            'elementor-mcp/set-featured-image', 'elementor-mcp/remove-featured-image',
            'elementor-mcp/attach-media', 'elementor-mcp/detach-media',
            // Comments.
            'elementor-mcp/list-comments', 'elementor-mcp/get-comment', 'elementor-mcp/create-comment',
            'elementor-mcp/update-comment', 'elementor-mcp/moderate-comment', 'elementor-mcp/delete-comment',
            // Menus.
            'elementor-mcp/list-menus', 'elementor-mcp/list-menu-items', 'elementor-mcp/upsert-menu-item',
            'elementor-mcp/delete-menu-item', 'elementor-mcp/create-menu', 'elementor-mcp/update-menu',
            'elementor-mcp/delete-menu', 'elementor-mcp/list-menu-locations', 'elementor-mcp/assign-menu-location',
            'elementor-mcp/reorder-menu-items',
            // Revisions.
            'elementor-mcp/list-revisions', 'elementor-mcp/get-revision', 'elementor-mcp/restore-revision',
            // Users and settings.
            'elementor-mcp/list-users', 'elementor-mcp/get-user',
            'elementor-mcp/get-site-settings', 'elementor-mcp/update-site-settings',
            // Plugins and themes.
            'elementor-mcp/list-extensions', 'elementor-mcp/search-extensions', 'elementor-mcp/get-extension',
            'elementor-mcp/activate-plugin', 'elementor-mcp/deactivate-plugin', 'elementor-mcp/update-plugin',
            'elementor-mcp/install-plugin', 'elementor-mcp/delete-plugin',
            'elementor-mcp/switch-theme', 'elementor-mcp/update-theme',
            'elementor-mcp/install-theme', 'elementor-mcp/delete-theme',
        ];

        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /**
     * Every registration must name the shared category, or it lands ungrouped in
     * the Abilities screen and cannot be switched off with the rest of the module.
     */
    public function testEveryCoreAbilityUsesTheWordPressCategory(): void
    {
        // Re-run one registration to inspect the shape the module produces.
        $wrong = [];
        foreach (ElementorMCP_Test_State::$registrations as $registration) {
            if (($registration['args']['category'] ?? null) !== 'wordpress') {
                $wrong[] = $registration['name'];
            }
        }

        self::assertSame([], $wrong);
    }

    // -------------------------------------------------------- permission gate

    public function testCorePermissionRequiresLoginAndEnabledPlugin(): void
    {
        self::assertTrue(wordpress_core_permission());
    }

    /**
     * Switching Elementor MCP off must close the surface. Without this the abilities
     * would keep executing after an administrator disables the plugin.
     */
    public function testCorePermissionIsClosedWhenElementorMcpIsDisabled(): void
    {
        ElementorMCP_Test_State::$elementor_mcp_enabled = false;

        self::assertFalse(wordpress_core_permission());
    }

    public function testCorePermissionIsClosedWhenLoggedOut(): void
    {
        ElementorMCP_Test_State::$logged_in = false;

        self::assertFalse(wordpress_core_permission());
    }

    public function testCapabilityScopedPermissionStillHonoursTheDisabledSwitch(): void
    {
        ElementorMCP_Test_State::$capabilities = ['moderate_comments'];
        self::assertTrue(wordpress_core_permission_for('moderate_comments'));

        ElementorMCP_Test_State::$elementor_mcp_enabled = false;
        self::assertFalse(wordpress_core_permission_for('moderate_comments'));
    }

    public function testCapabilityScopedPermissionRefusesMissingCapability(): void
    {
        self::assertFalse(wordpress_core_permission_for('moderate_comments'));
    }

    // ------------------------------------------------------- taxonomy exposure

    public function testPublicTaxonomyIsAgentFacing(): void
    {
        ElementorMCP_Test_State::add_taxonomy('category');

        self::assertNull(wordpress_taxonomy_is_agent_facing('category'));
    }

    #[DataProvider('internalTaxonomies')]
    public function testInternalTaxonomiesAreRejected(string $taxonomy): void
    {
        ElementorMCP_Test_State::add_taxonomy($taxonomy);

        $error = wordpress_taxonomy_is_agent_facing($taxonomy);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('taxonomy_not_agent_facing', $error->get_error_code());
    }

    /** @return array<string, array{0: string}> */
    public static function internalTaxonomies(): array
    {
        return [
            'nav_menu' => ['nav_menu'],
            'link_category' => ['link_category'],
            'wp_theme' => ['wp_theme'],
            'wp_template_part_area' => ['wp_template_part_area'],
            'wp_pattern_category' => ['wp_pattern_category'],
        ];
    }

    public function testPrivateTaxonomyIsRejected(): void
    {
        ElementorMCP_Test_State::add_taxonomy('acme_private', public: false, show_in_rest: false);

        $error = wordpress_taxonomy_is_agent_facing('acme_private');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('taxonomy_not_public', $error->get_error_code());
    }

    public function testUnregisteredTaxonomyIsRejected(): void
    {
        $error = wordpress_taxonomy_is_agent_facing('nope');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('invalid_taxonomy', $error->get_error_code());
    }

    // --------------------------------------------------------- URL safety

    /**
     * A menu item's URL is rendered into an href, so a script-bearing scheme
     * there is stored XSS.
     */
    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlSchemesAreRejected(string $url): void
    {
        $error = wordpress_unsafe_url_error($url);

        self::assertInstanceOf(WP_Error::class, $error, sprintf('Expected "%s" to be refused.', $url));
        self::assertSame(422, $error->get_error_data()['status']);
    }

    /** @return array<string, array{0: string}> */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript uppercase' => ['JavaScript:alert(1)'],
            'javascript mixed case' => ['JaVaScRiPt:alert(document.cookie)'],
            'data uri' => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function testSafeUrlsAreAccepted(string $url): void
    {
        self::assertNull(wordpress_unsafe_url_error($url), sprintf('Expected "%s" to be accepted.', $url));
    }

    /** @return array<string, array{0: string}> */
    public static function safeUrls(): array
    {
        return [
            'https' => ['https://example.com/page'],
            'http' => ['http://example.com/'],
            'mailto' => ['mailto:hello@example.com'],
            'tel' => ['tel:+15551234'],
            'root relative' => ['/about-us'],
            'relative' => ['about-us'],
            'anchor' => ['#section'],
            'empty' => [''],
        ];
    }

    // ------------------------------------------------ update publish gating

    private function post(string $status = 'draft', string $type = 'post'): WP_Post
    {
        $post = new WP_Post();
        $post->ID = 12;
        $post->post_type = $type;
        $post->post_status = $status;

        return $post;
    }

    public function testUpdateWithoutStatusChangeNeedsNoPublishCapability(): void
    {
        ElementorMCP_Test_State::add_post_type('post');
        ElementorMCP_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post(), ['title' => 'New title']));
    }

    public function testPublishingAnExistingDraftNeedsPublishCapability(): void
    {
        ElementorMCP_Test_State::add_post_type('post');
        ElementorMCP_Test_State::$capabilities = ['edit_posts'];

        $error = wordpress_update_status_capability_error($this->post(), ['status' => 'publish']);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_publish_post', $error->get_error_code());
    }

    public function testPublishingIsAllowedWithTheCapability(): void
    {
        ElementorMCP_Test_State::add_post_type('post');
        ElementorMCP_Test_State::$capabilities = ['edit_posts', 'publish_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post(), ['status' => 'publish']));
    }

    /**
     * Unpublishing is an ordinary edit, already covered by the edit_post check.
     */
    public function testUnpublishingDoesNotNeedPublishCapability(): void
    {
        ElementorMCP_Test_State::add_post_type('post');
        ElementorMCP_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post('publish'), ['status' => 'draft']));
    }

    public function testEditingAPublishedPostInPlaceNeedsNoPublishCapability(): void
    {
        ElementorMCP_Test_State::add_post_type('post');
        ElementorMCP_Test_State::$capabilities = ['edit_posts'];

        self::assertNull(wordpress_update_status_capability_error($this->post('publish'), ['status' => 'publish']));
    }

    public function testMalformedStatusOnUpdateCannotPublish(): void
    {
        ElementorMCP_Test_State::add_post_type('post');
        ElementorMCP_Test_State::$capabilities = ['edit_posts'];

        // Resolves to draft, which is not a publishing status, so no refusal and
        // no publish either.
        self::assertNull(wordpress_update_status_capability_error($this->post(), ['status' => 'PUBLISH NOW']));
    }

    public function testCustomTypePublishCapabilityIsUsed(): void
    {
        ElementorMCP_Test_State::add_post_type('portfolio', capabilities: ['publish_posts' => 'publish_portfolios']);
        ElementorMCP_Test_State::$capabilities = ['edit_posts', 'publish_posts'];

        $error = wordpress_update_status_capability_error(
            $this->post(type: 'portfolio'),
            ['status' => 'publish'],
        );

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('cannot_publish_post', $error->get_error_code());
    }

    // ----------------------------------------------------------- revisions

    public function testAutosaveIsIdentified(): void
    {
        $revision = new WP_Post();
        $revision->post_name = '12-autosave-v1';

        self::assertTrue(wordpress_revision_is_autosave($revision));
    }

    public function testOrdinaryRevisionIsNotAnAutosave(): void
    {
        $revision = new WP_Post();
        $revision->post_name = '12-revision-v1';

        self::assertFalse(wordpress_revision_is_autosave($revision));
    }

    /**
     * wp_restore_post_revision() answers false when the revision carried no
     * restorable fields. Nothing was written, so the ability has to say so:
     * reporting success here told an agent the post had been rolled back to a
     * revision it still does not match.
     */
    public function testRestoreRevisionRefusesWhenNoFieldsWereRestored(): void
    {
        ElementorMCP_Test_State::$capabilities = ['edit_post'];
        ElementorMCP_Test_State::add_revision(revision_id: 21, parent_id: 7);
        ElementorMCP_Test_State::$restore_result = false;

        $result = wordpress_restore_revision(['revision_id' => 21]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('restore_revision_failed', $result->get_error_code());
    }

    public function testRestoreRevisionRefusesWhenRestoreErrored(): void
    {
        ElementorMCP_Test_State::$capabilities = ['edit_post'];
        ElementorMCP_Test_State::add_revision(revision_id: 21, parent_id: 7);
        ElementorMCP_Test_State::$restore_result = null;

        $result = wordpress_restore_revision(['revision_id' => 21]);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('restore_revision_failed', $result->get_error_code());
    }

    /**
     * wp_update_post() answers 0 rather than an error when the write is refused,
     * and wp_restore_post_revision() passes that straight through. A zero id is
     * not a post.
     */
    public function testRestoreRevisionRefusesZeroPostId(): void
    {
        ElementorMCP_Test_State::$capabilities = ['edit_post'];
        ElementorMCP_Test_State::add_revision(revision_id: 21, parent_id: 7);
        ElementorMCP_Test_State::$restore_result = 0;

        $result = wordpress_restore_revision(['revision_id' => 21]);

        self::assertInstanceOf(WP_Error::class, $result);
    }

    public function testRestoreRevisionReportsTheParentPostOnSuccess(): void
    {
        ElementorMCP_Test_State::$capabilities = ['edit_post'];
        ElementorMCP_Test_State::add_revision(revision_id: 21, parent_id: 7);
        ElementorMCP_Test_State::$restore_result = 7;

        $result = wordpress_restore_revision(['revision_id' => 21]);

        self::assertIsArray($result);
        self::assertSame(7, $result['post_id']);
        self::assertSame(21, $result['revision_id']);
        self::assertFalse($result['was_autosave']);
    }

    /**
     * wp_get_post_revision() takes its first argument by reference, so handing it
     * anything that is not a variable is a fatal Error on PHP 8, not a notice.
     * The ledger captures a before-image on every ability call, so that fatal took
     * elementor-mcp/restore-revision down entirely. The double declares the parameter by
     * reference for exactly this reason: calling it is the assertion.
     */
    public function testRestoreRevisionBeforeImageCapturesTheParentPost(): void
    {
        ElementorMCP_Test_State::add_revision(revision_id: 21, parent_id: 7);

        $before = \elementor_mcp_capture_core_before_image('elementor-mcp/restore-revision', ['revision_id' => 21]);

        self::assertIsArray($before);
        self::assertSame('post', $before['type']);
    }

    public function testRestoreRevisionBeforeImageIsEmptyForAnUnknownRevision(): void
    {
        $before = \elementor_mcp_capture_core_before_image('elementor-mcp/restore-revision', ['revision_id' => 999]);

        self::assertNull($before);
    }

    /**
     * WordPress 7.1 warns from rest_validate_value_from_schema() when a
     * property omits its type. Atomic-widget defaults intentionally accept
     * several scalar kinds, so they need an explicit union rather than an
     * untyped schema entry.
     */
    public function testAtomicWidgetPropDefaultDeclaresItsScalarUnion(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/abilities/elementor/elementor-mcp-create-atomic-widget.php';

        foreach (ElementorMCP_Test_State::$registrations as $registration) {
            if ($registration['name'] !== 'elementor-mcp/elementor-create-atomic-widget') {
                continue;
            }

            $default = $registration['args']['input_schema']['properties']['props']['items']['properties']['default'];

            self::assertSame(['string', 'number', 'boolean', 'null'], $default['type']);
            return;
        }

        self::fail('Atomic widget ability was never registered.');
    }

    // ------------------------------------------------- core include integrity

    /**
     * The media importer requires three WordPress core files before it can run,
     * and one of them was `wp-admin/includes/elementor-mcp-media.php` — a rebrand
     * find-and-replace that renamed a core path. Every call to
     * elementor-mcp/import-media-url therefore died on a require_once before it
     * reached the download, and the ability was unusable in every shipped build
     * that carried it.
     *
     * A path that does not exist cannot be caught by a type checker or by
     * `php -l`, so the shape of the mistake is asserted here instead.
     */
    public function testNoCoreIncludePathIsPrefixedWithThePluginName(): void
    {
        $root = dirname(__DIR__, 2) . '/includes';
        $pattern = '#ABSPATH\s*\.\s*[\'"](?:wp-admin|wp-includes)/[^\'"]*elementor-mcp-#';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $body = (string) file_get_contents($file->getPathname());
            if (preg_match($pattern, $body) === 1) {
                $offenders[] = str_replace($root, '', $file->getPathname());
            }
        }

        self::assertSame([], $offenders, 'A WordPress core include path was prefixed with "elementor-mcp-".');
    }
}
