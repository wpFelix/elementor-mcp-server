<?php

// SPDX-FileCopyrightText: 2026 Elementor MCP <dev@elementormcp.com>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace ElementorMCP\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;
use ElementorMCP_Test_State;

use function ElementorMCP\Abilities\WordPress\wordpress_directory_summary;
use function ElementorMCP\Abilities\WordPress\wordpress_extension_self_error;
use function ElementorMCP\Abilities\WordPress\wordpress_extension_slug;
use function ElementorMCP\Abilities\WordPress\wordpress_extension_to_array;
use function ElementorMCP\Abilities\WordPress\wordpress_network_scope_error;
use function ElementorMCP\Abilities\WordPress\wordpress_plugin_file;

/**
 * The plugin and theme lifecycle surface: what registers, and the gates that
 * decide whether a call is allowed to touch the site's code.
 */
final class ExtensionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        ElementorMCP_Test_State::reset();
        require_once dirname(__DIR__, levels: 2) . '/includes/safety.php';
    }

    protected function tearDown(): void
    {
        ElementorMCP_Test_State::reset();
    }

    // ------------------------------------------------------------ registration

    #[DataProvider('lifecycleAbilities')]
    public function testLifecycleAbilityIsRegistered(string $ability): void
    {
        self::assertContains($ability, ELEMENTOR_MCP_TEST_BOOT_ABILITIES);
    }

    /** @return array<string, array{0: string}> */
    public static function lifecycleAbilities(): array
    {
        $names = [
            'elementor-mcp/search-extensions', 'elementor-mcp/get-extension',
            'elementor-mcp/activate-plugin', 'elementor-mcp/deactivate-plugin',
            'elementor-mcp/update-plugin', 'elementor-mcp/update-theme', 'elementor-mcp/switch-theme',
            'elementor-mcp/install-plugin', 'elementor-mcp/install-theme',
            'elementor-mcp/delete-plugin', 'elementor-mcp/delete-theme',
        ];

        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /**
     * Discovery must stay readable on the Read Only profile, which admits an
     * ability only when it is annotated read-only.
     */
    #[DataProvider('readOnlyLifecycleAbilities')]
    public function testDiscoveryAbilitiesAreAnnotatedReadOnly(string $ability): void
    {
        self::assertTrue($this->annotations($ability)['readonly'] ?? false);
    }

    /** @return array<string, array{0: string}> */
    public static function readOnlyLifecycleAbilities(): array
    {
        return [
            'search-extensions' => ['elementor-mcp/search-extensions'],
            'get-extension' => ['elementor-mcp/get-extension'],
        ];
    }

    /**
     * Every mutation here can take a live site down, so none of them may be
     * annotated as an ordinary write: the annotation is half of what makes
     * Elementor MCP demand a confirmation flag.
     */
    #[DataProvider('mutatingLifecycleAbilities')]
    public function testMutationsAreAnnotatedDestructive(string $ability): void
    {
        $annotations = $this->annotations($ability);

        self::assertFalse($annotations['readonly'] ?? true);
        self::assertTrue($annotations['destructive'] ?? false);
    }

    /** @return array<string, array{0: string}> */
    public static function mutatingLifecycleAbilities(): array
    {
        $names = [
            'elementor-mcp/activate-plugin', 'elementor-mcp/deactivate-plugin',
            'elementor-mcp/update-plugin', 'elementor-mcp/update-theme', 'elementor-mcp/switch-theme',
            'elementor-mcp/install-plugin', 'elementor-mcp/install-theme',
            'elementor-mcp/delete-plugin', 'elementor-mcp/delete-theme',
        ];

        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /** @return array<string, mixed> */
    private function annotations(string $ability): array
    {
        foreach (ELEMENTOR_MCP_TEST_BOOT_REGISTRATIONS as $registration) {
            if ($registration['name'] !== $ability) {
                continue;
            }
            $meta = is_array($registration['args']['meta'] ?? null) ? $registration['args']['meta'] : [];

            return is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [];
        }

        self::fail(sprintf('Ability "%s" was never registered.', $ability));
    }

    // -------------------------------------------------------- risk classification

    /**
     * Installing and deleting write executable code to the server. They must
     * classify as critical, which is what keeps them off Production Safe.
     */
    #[DataProvider('criticalNames')]
    public function testFileWritingOperationsAreCritical(string $name): void
    {
        self::assertTrue(\elementor_mcp_ability_name_is_critical($name));
    }

    /** @return array<string, array{0: string}> */
    public static function criticalNames(): array
    {
        $names = [
            'elementor-mcp/install-plugin', 'elementor-mcp/install-theme',
            'elementor-mcp/delete-plugin', 'elementor-mcp/delete-theme',
        ];

        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /**
     * Activation, updates and theme switches fetch no code, so they stay
     * available on a production site — but each one is confirmation-gated,
     * which follows from the destructive classification.
     */
    #[DataProvider('destructiveNames')]
    public function testStateChangingOperationsAreDestructiveButNotCritical(string $name): void
    {
        self::assertTrue(\elementor_mcp_ability_name_is_destructive($name));
        self::assertFalse(\elementor_mcp_ability_name_is_critical($name));
    }

    /** @return array<string, array{0: string}> */
    public static function destructiveNames(): array
    {
        $names = [
            'elementor-mcp/activate-plugin', 'elementor-mcp/deactivate-plugin',
            'elementor-mcp/update-plugin', 'elementor-mcp/update-theme', 'elementor-mcp/switch-theme',
        ];

        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /**
     * The lifecycle fragments are deliberately specific. A bare `update-` or
     * `activate-` prefix would sweep in routine content work and force a
     * confirmation round trip on every ordinary edit.
     */
    #[DataProvider('ordinaryWriteNames')]
    public function testOrdinaryWritesAreNotDraggedIn(string $name): void
    {
        self::assertFalse(\elementor_mcp_ability_name_is_destructive($name));
        self::assertFalse(\elementor_mcp_ability_name_is_critical($name));
    }

    /** @return array<string, array{0: string}> */
    public static function ordinaryWriteNames(): array
    {
        $names = [
            'elementor-mcp/update-post', 'elementor-mcp/update-media', 'elementor-mcp/update-term',
            'elementor-mcp/update-comment', 'elementor-mcp/update-menu', 'elementor-mcp/update-site-settings',
            'elementor-mcp/activate-design', 'elementor-mcp/create-post', 'elementor-mcp/assign-terms',
        ];

        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    // ------------------------------------------------------------ slug safety

    #[DataProvider('acceptableSlugs')]
    public function testPlainDirectoryNamesAreAccepted(string $slug): void
    {
        self::assertSame($slug, wordpress_extension_slug($slug));
    }

    /** @return array<string, array{0: string}> */
    public static function acceptableSlugs(): array
    {
        $slugs = ['akismet', 'twentytwentyfive', 'wp-super-cache', 'my_theme', 'theme.child', 'a'];

        return array_combine($slugs, array_map(static fn(string $s): array => [$s], $slugs));
    }

    /**
     * A stylesheet is concatenated into a filesystem path by WordPress itself,
     * so anything that is not a plain directory name is refused here.
     */
    #[DataProvider('rejectedSlugs')]
    public function testTraversalAndPathSegmentsAreRejected(string $slug): void
    {
        self::assertSame('', wordpress_extension_slug($slug));
    }

    /** @return array<string, array{0: string}> */
    public static function rejectedSlugs(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'parent traversal' => ['../evil'],
            'nested traversal' => ['themes/../../wp-config'],
            'path segment' => ['akismet/akismet.php'],
            'absolute' => ['/etc/passwd'],
            'windows drive' => ['C:\\windows'],
            'leading dot' => ['.hidden'],
            'leading dash' => ['-flag'],
            'null byte' => ["twenty\0two"],
        ];
    }

    // ----------------------------------------------------- plugin file safety

    public function testInstalledPluginFileIsAccepted(): void
    {
        ElementorMCP_Test_State::$plugins = ['akismet/akismet.php' => ['Name' => 'Akismet']];

        self::assertSame('akismet/akismet.php', wordpress_plugin_file('akismet/akismet.php'));
    }

    public function testUnknownPluginFileIsRejected(): void
    {
        $error = wordpress_plugin_file('nope/nope.php');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('elementor_mcp_plugin_not_found', $error->get_error_code());
    }

    #[DataProvider('malformedPluginFiles')]
    public function testMalformedPluginFileIsRejectedBeforeAnyLookup(string $file): void
    {
        ElementorMCP_Test_State::$plugins = ['akismet/akismet.php' => ['Name' => 'Akismet']];

        $error = wordpress_plugin_file($file);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('elementor_mcp_invalid_plugin_file', $error->get_error_code());
        self::assertSame(422, $error->get_error_data()['status']);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedPluginFiles(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../../wp-config.php'],
            'traversal mid path' => ['akismet/../../wp-config.php'],
            'not php' => ['akismet/readme.txt'],
            'directory only' => ['akismet'],
            'windows drive' => ['C:/wp-config.php'],
        ];
    }

    // ---------------------------------------------------------- self-protection

    /**
     * Deactivating Elementor MCP mid-call would sever the connection carrying the
     * call, leaving the agent with no way to undo it and, on a remote site, no
     * way back in.
     */
    public function testElementorMcpRefusesToDeactivateItself(): void
    {
        $error = wordpress_extension_self_error('elementor-mcp/elementor-mcp.php');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('elementor_mcp_cannot_target_self', $error->get_error_code());
    }

    public function testElementorMcpProIsProtectedToo(): void
    {
        self::assertInstanceOf(WP_Error::class, wordpress_extension_self_error('elementor-mcp-pro/elementor-mcp-pro.php'));
    }

    public function testOtherPluginsAreNotSelfProtected(): void
    {
        self::assertNull(wordpress_extension_self_error('akismet/akismet.php'));
    }

    // ------------------------------------------------------------ network scope

    public function testNetworkWideIsRefusedOnASingleSite(): void
    {
        $error = wordpress_network_scope_error(true);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('elementor_mcp_not_multisite', $error->get_error_code());
    }

    public function testNetworkWideNeedsNetworkRights(): void
    {
        ElementorMCP_Test_State::$multisite = true;

        $error = wordpress_network_scope_error(true);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('elementor_mcp_cannot_manage_network_plugins', $error->get_error_code());
    }

    public function testNetworkWideIsAllowedForANetworkAdministrator(): void
    {
        ElementorMCP_Test_State::$multisite = true;
        ElementorMCP_Test_State::$capabilities = ['manage_network_plugins'];

        self::assertNull(wordpress_network_scope_error(true));
    }

    public function testSiteScopedCallsSkipTheNetworkCheck(): void
    {
        self::assertNull(wordpress_network_scope_error(false));
    }

    // --------------------------------------------------------- directory records

    /**
     * WordPress.org returns author and name as markup. They are rendered into
     * an agent's context and, from there, often straight into page content.
     */
    public function testDirectoryRecordsAreStrippedOfMarkup(): void
    {
        $summary = wordpress_directory_summary([
            'slug' => 'akismet',
            'name' => 'Akismet <b>Anti-spam</b>',
            'author' => '<a href="https://example.com">Automattic</a>',
            'short_description' => '<script>alert(1)</script>Stops spam.',
            'active_installs' => '5000000',
            'rating' => '96',
        ]);

        self::assertSame('Akismet Anti-spam', $summary['name']);
        self::assertSame('Automattic', $summary['author']);
        self::assertSame('Stops spam.', $summary['short_description']);
        self::assertSame(5_000_000, $summary['active_installs']);
        self::assertSame(96.0, $summary['rating']);
    }

    /**
     * The themes endpoint returns the author as a profile record where the
     * plugins endpoint returns an HTML link. Casting that to string emitted
     * "Array to string conversion" and handed the agent the word "Array" as
     * the author of every theme.
     */
    public function testThemeAuthorRecordIsFlattenedToAName(): void
    {
        $summary = wordpress_directory_summary([
            'slug' => 'twentytwenty',
            'author' => [
                'user_nicename' => 'wordpressdotorg',
                'display_name' => 'WordPress.org',
                'profile' => 'https://profiles.wordpress.org/wordpressdotorg/',
            ],
        ]);

        self::assertSame('WordPress.org', $summary['author']);
    }

    public function testAuthorFallsBackThroughTheProfileKeys(): void
    {
        self::assertSame(
            'wordpressdotorg',
            wordpress_directory_summary(['author' => ['user_nicename' => 'wordpressdotorg']])['author'],
        );
        self::assertSame('', wordpress_directory_summary(['author' => []])['author']);
    }

    /**
     * `requires` is `false`, not a string, when an extension declares no
     * minimum. A bool cast would turn that into "1" or "".
     */
    #[DataProvider('nonStringDirectoryFields')]
    public function testNonStringFieldsBecomeEmptyStrings(mixed $value): void
    {
        $summary = wordpress_directory_summary(['requires' => $value, 'requires_php' => $value]);

        self::assertSame('', $summary['requires']);
        self::assertSame('', $summary['requires_php']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonStringDirectoryFields(): array
    {
        return [
            'false' => [false],
            'true' => [true],
            'null' => [null],
            'array' => [['6.0']],
        ];
    }

    /**
     * Themes report `downloaded` where plugins report `active_installs`.
     */
    public function testThemeDownloadCountFillsTheInstallField(): void
    {
        self::assertSame(9_000, wordpress_directory_summary(['downloaded' => 9_000])['active_installs']);
        self::assertSame(
            5_000,
            wordpress_directory_summary(['active_installs' => 5_000, 'downloaded' => 9_000])['active_installs'],
        );
    }

    /**
     * The directory API answers with objects on one endpoint and arrays on
     * another, depending on the requested fields.
     */
    public function testDirectoryRecordsNormalizeFromObjectsAndArrays(): void
    {
        self::assertSame(['slug' => 'akismet'], wordpress_extension_to_array((object) ['slug' => 'akismet']));
        self::assertSame(['slug' => 'akismet'], wordpress_extension_to_array(['slug' => 'akismet']));
        self::assertSame([], wordpress_extension_to_array(null));
    }
}
