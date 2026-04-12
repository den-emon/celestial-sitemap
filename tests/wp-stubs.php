<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/stubs/wp-root/');
}

if (! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/celestial-sitemap-tests/wp-content');
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (! defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (! is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0777, true);
}

final class CelestialSitemapPreparedQuery
{
    public function __construct(
        public string $query,
        public array $args
    ) {
    }
}

final class CelestialSitemapFakeWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $termmeta = 'wp_termmeta';

    /** @var array<string, array<int|string, mixed>> */
    private array $tables = [];

    /** @var array<string, string[]> */
    private array $schemas = [];

    /** @var array<string, int> */
    private array $autoIncrement = [];

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->tables = [
            $this->options => [],
        ];

        $this->schemas = [
            $this->options => ['option_name', 'option_value', 'autoload'],
        ];

        $this->autoIncrement = [
            $this->options => 1,
        ];
    }

    public function get_charset_collate(): string
    {
        return '';
    }

    public function prepare(string $query, mixed ...$args): CelestialSitemapPreparedQuery
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return new CelestialSitemapPreparedQuery($query, $args);
    }

    /**
     * Minimal dbDelta emulation for the plugin tables used in tests.
     *
     * @return array<string, string>
     */
    public function dbDelta(string $sql): array
    {
        if (str_contains($sql, $this->prefix . 'cel_redirects')) {
            $table = $this->prefix . 'cel_redirects';
            $this->tables[$table] ??= [];
            $this->schemas[$table] = [
                'id',
                'source_url',
                'target_url',
                'status_code',
                'match_type',
                'hit_count',
                'created_at',
                'updated_at',
            ];
            $this->autoIncrement[$table] ??= 1;
        }

        if (str_contains($sql, $this->prefix . 'cel_404_log')) {
            $table = $this->prefix . 'cel_404_log';
            $this->tables[$table] ??= [];
            $this->schemas[$table] = [
                'id',
                'url',
                'referrer',
                'user_agent',
                'hit_count',
                'first_seen',
                'last_seen',
            ];
            $this->autoIncrement[$table] ??= 1;
        }

        return [];
    }

    public function hasTable(string $table): bool
    {
        return array_key_exists($table, $this->tables);
    }

    public function get_var(mixed $query): mixed
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if ($template === 'SHOW TABLES LIKE %s') {
            $table = (string) ($args[0] ?? '');
            return $this->hasTable($table) ? $table : null;
        }

        if ($template === "SELECT option_value FROM {$this->options} WHERE option_name = %s LIMIT 1") {
            $name = (string) ($args[0] ?? '');
            return $this->tables[$this->options][$name]['option_value'] ?? null;
        }

        if ($sql === "SELECT COUNT(*) FROM {$this->prefix}cel_redirects") {
            return count($this->tables[$this->prefix . 'cel_redirects'] ?? []);
        }

        if ($sql === "SELECT COUNT(*) FROM {$this->prefix}cel_404_log") {
            return count($this->tables[$this->prefix . 'cel_404_log'] ?? []);
        }

        if ($template === "SELECT id FROM {$this->prefix}cel_redirects WHERE source_url = %s LIMIT 1") {
            $source = (string) ($args[0] ?? '');
            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source) {
                    return $row['id'];
                }
            }

            return null;
        }

        if ($template === "SELECT target_url FROM {$this->prefix}cel_redirects WHERE source_url = %s LIMIT 1") {
            $source = (string) ($args[0] ?? '');
            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source) {
                    return $row['target_url'];
                }
            }

            return null;
        }

        if ($template === "SELECT 1 FROM {$this->prefix}cel_404_log WHERE url = %s LIMIT 1") {
            $url = (string) ($args[0] ?? '');
            foreach ($this->tables[$this->prefix . 'cel_404_log'] ?? [] as $row) {
                if (($row['url'] ?? null) === $url) {
                    return 1;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>|object>
     */
    public function get_results(mixed $query, string|int $output = OBJECT): array
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if ($sql === "SELECT option_name, option_value FROM {$this->options} WHERE option_name LIKE 'cel\\_%'") {
            $rows = [];
            foreach ($this->tables[$this->options] as $name => $row) {
                if ($this->likeMatches('cel\\_%', (string) $name)) {
                    $rows[] = [
                        'option_name'  => $name,
                        'option_value' => $row['option_value'],
                    ];
                }
            }

            return $this->formatRows($rows, $output);
        }

        if ($sql === "SELECT * FROM {$this->prefix}cel_redirects") {
            return $this->formatRows(array_values($this->tables[$this->prefix . 'cel_redirects'] ?? []), $output);
        }

        if ($template === "SELECT * FROM {$this->prefix}cel_redirects ORDER BY updated_at DESC LIMIT %d OFFSET %d") {
            $rows = array_values($this->tables[$this->prefix . 'cel_redirects'] ?? []);
            usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

            return $this->formatRows(
                array_slice($rows, (int) ($args[1] ?? 0), (int) ($args[0] ?? count($rows))),
                $output
            );
        }

        if ($template === "SELECT * FROM {$this->prefix}cel_404_log ORDER BY hit_count DESC, last_seen DESC LIMIT %d OFFSET %d") {
            $rows = array_values($this->tables[$this->prefix . 'cel_404_log'] ?? []);
            usort($rows, static function (array $a, array $b): int {
                $hitDiff = ((int) $b['hit_count']) <=> ((int) $a['hit_count']);
                if ($hitDiff !== 0) {
                    return $hitDiff;
                }

                return strcmp((string) $b['last_seen'], (string) $a['last_seen']);
            });

            return $this->formatRows(
                array_slice($rows, (int) ($args[1] ?? 0), (int) ($args[0] ?? count($rows))),
                $output
            );
        }

        if ($sql === "SELECT id, source_url, target_url, status_code FROM {$this->prefix}cel_redirects WHERE match_type = 'regex' ORDER BY id ASC") {
            $rows = array_values(array_filter(
                $this->tables[$this->prefix . 'cel_redirects'] ?? [],
                static fn(array $row): bool => ($row['match_type'] ?? 'exact') === 'regex'
            ));

            usort($rows, static fn(array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));

            return $this->formatRows(array_map(static fn(array $row): array => [
                'id'          => $row['id'],
                'source_url'  => $row['source_url'],
                'target_url'  => $row['target_url'],
                'status_code' => $row['status_code'],
            ], $rows), $output);
        }

        return [];
    }

    public function get_row(mixed $query, string|int $output = OBJECT): array|object|null
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if ($sql === "SELECT * FROM {$this->prefix}cel_404_log LIMIT 1") {
            $rows = array_values($this->tables[$this->prefix . 'cel_404_log'] ?? []);
            $row = $rows[0] ?? null;

            return $row === null ? null : $this->formatRow($row, $output);
        }

        if ($template === "SELECT id, target_url, status_code FROM {$this->prefix}cel_redirects WHERE source_url = %s AND (match_type = 'exact' OR match_type IS NULL) LIMIT 1") {
            $source = (string) ($args[0] ?? '');

            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source && (($row['match_type'] ?? 'exact') === 'exact')) {
                    return $this->formatRow([
                        'id'          => $row['id'],
                        'target_url'  => $row['target_url'],
                        'status_code' => $row['status_code'],
                    ], $output);
                }
            }

            return null;
        }

        if ($template === "SELECT id, target_url, status_code FROM {$this->prefix}cel_redirects WHERE source_url = %s LIMIT 1") {
            $source = (string) ($args[0] ?? '');

            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['source_url'] ?? null) === $source) {
                    return $this->formatRow([
                        'id'          => $row['id'],
                        'target_url'  => $row['target_url'],
                        'status_code' => $row['status_code'],
                    ], $output);
                }
            }

            return null;
        }

        if ($template === "SELECT id, source_url, target_url, status_code FROM {$this->prefix}cel_redirects WHERE match_type = 'prefix' AND %s LIKE CONCAT(source_url, '%%') ORDER BY CHAR_LENGTH(source_url) DESC, id ASC LIMIT 1") {
            $path = (string) ($args[0] ?? '');
            $matches = [];

            foreach ($this->tables[$this->prefix . 'cel_redirects'] ?? [] as $row) {
                if (($row['match_type'] ?? 'exact') !== 'prefix') {
                    continue;
                }

                $source = (string) ($row['source_url'] ?? '');
                if ($source !== '' && str_starts_with($path, $source)) {
                    $matches[] = [
                        'id'          => $row['id'],
                        'source_url'  => $source,
                        'target_url'  => $row['target_url'],
                        'status_code' => $row['status_code'],
                    ];
                }
            }

            usort($matches, static function (array $a, array $b): int {
                $lengthDiff = strlen((string) $b['source_url']) <=> strlen((string) $a['source_url']);
                if ($lengthDiff !== 0) {
                    return $lengthDiff;
                }

                return ((int) $a['id']) <=> ((int) $b['id']);
            });

            $row = $matches[0] ?? null;

            return $row === null ? null : $this->formatRow($row, $output);
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    public function get_col(mixed $query): array
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if ($sql === "SHOW COLUMNS FROM {$this->prefix}cel_redirects") {
            return $this->schemas[$this->prefix . 'cel_redirects'] ?? [];
        }

        if ($template === "SELECT ID FROM {$this->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_date_gmt >= %s ORDER BY post_date_gmt DESC LIMIT %d") {
            $cutoff = (string) ($args[0] ?? '');
            $limit  = (int) ($args[1] ?? 0);
            $rows   = [];

            foreach ($GLOBALS['cel_test_posts'] as $post) {
                if (($post->post_type ?? '') !== 'post' || ($post->post_status ?? '') !== 'publish') {
                    continue;
                }
                if (($post->post_date_gmt ?? '') < $cutoff) {
                    continue;
                }
                $rows[] = $post;
            }

            usort($rows, static fn(object $a, object $b): int => strcmp((string) $b->post_date_gmt, (string) $a->post_date_gmt));

            return array_map(
                static fn(object $post): int => (int) $post->ID,
                $limit > 0 ? array_slice($rows, 0, $limit) : $rows
            );
        }

        if ($template === "SELECT ID FROM {$this->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY post_date_gmt DESC LIMIT %d") {
            $limit = (int) ($args[0] ?? 0);
            $rows = [];

            foreach ($GLOBALS['cel_test_posts'] as $post) {
                if (($post->post_type ?? '') !== 'post' || ($post->post_status ?? '') !== 'publish') {
                    continue;
                }

                $rows[] = $post;
            }

            usort($rows, static fn(object $a, object $b): int => strcmp((string) $b->post_date_gmt, (string) $a->post_date_gmt));

            if ($rows === []) {
                return [];
            }

            return array_map(
                static fn(object $post): int => (int) $post->ID,
                $limit > 0 ? array_slice($rows, 0, $limit) : $rows
            );
        }

        return [];
    }

    public function query(mixed $query): int|false
    {
        [$template, $args, $sql] = $this->normalizeQuery($query);

        if (str_starts_with($sql, "DELETE FROM {$this->options} WHERE")) {
            preg_match_all("/option_name LIKE '([^']+)'/", $sql, $matches);
            $patterns = $matches[1] ?? [];
            $deleted = 0;

            foreach (array_keys($this->tables[$this->options]) as $name) {
                foreach ($patterns as $pattern) {
                    if ($this->likeMatches($pattern, (string) $name)) {
                        unset($this->tables[$this->options][$name]);
                        $deleted++;
                        break;
                    }
                }
            }

            return $deleted;
        }

        if ($sql === "DELETE FROM {$this->prefix}cel_redirects") {
            $count = count($this->tables[$this->prefix . 'cel_redirects'] ?? []);
            $this->tables[$this->prefix . 'cel_redirects'] = [];
            $this->autoIncrement[$this->prefix . 'cel_redirects'] = 1;

            return $count;
        }

        if ($sql === "DELETE FROM {$this->prefix}cel_404_log") {
            $count = count($this->tables[$this->prefix . 'cel_404_log'] ?? []);
            $this->tables[$this->prefix . 'cel_404_log'] = [];
            $this->autoIncrement[$this->prefix . 'cel_404_log'] = 1;

            return $count;
        }

        if ($sql === "DELETE FROM {$this->prefix}cel_404_log WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY)") {
            $threshold = time() - (90 * DAY_IN_SECONDS);
            $remaining = [];
            $deleted = 0;

            foreach ($this->tables[$this->prefix . 'cel_404_log'] ?? [] as $row) {
                $lastSeen = strtotime((string) ($row['last_seen'] ?? 'now'));
                if ($lastSeen !== false && $lastSeen < $threshold) {
                    $deleted++;
                    continue;
                }

                $remaining[] = $row;
            }

            $this->tables[$this->prefix . 'cel_404_log'] = $remaining;

            return $deleted;
        }

        if ($template === "UPDATE {$this->prefix}cel_redirects SET hit_count = hit_count + 1 WHERE id = %d") {
            $id = (int) ($args[0] ?? 0);

            if (! isset($this->tables[$this->prefix . 'cel_redirects'])) {
                return 0;
            }

            foreach ($this->tables[$this->prefix . 'cel_redirects'] as &$row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    $row['hit_count'] = (int) ($row['hit_count'] ?? 0) + 1;
                    $row['updated_at'] = gmdate('Y-m-d H:i:s');
                    return 1;
                }
            }
            unset($row);

            return 0;
        }

        if (str_starts_with($template, "INSERT INTO {$this->prefix}cel_404_log ")) {
            $url = (string) ($args[0] ?? '');
            $referrer = (string) ($args[1] ?? '');
            $userAgent = (string) ($args[2] ?? '');
            $now = gmdate('Y-m-d H:i:s');

            $this->tables[$this->prefix . 'cel_404_log'] ??= [];

            foreach ($this->tables[$this->prefix . 'cel_404_log'] as &$row) {
                if (($row['url'] ?? null) === $url) {
                    $row['hit_count'] = (int) ($row['hit_count'] ?? 0) + 1;
                    $row['last_seen'] = $now;
                    $row['referrer'] = $referrer;
                    $row['user_agent'] = $userAgent;
                    return 1;
                }
            }
            unset($row);

            $this->tables[$this->prefix . 'cel_404_log'][] = [
                'id'         => $this->nextId($this->prefix . 'cel_404_log'),
                'url'        => $url,
                'referrer'   => $referrer,
                'user_agent' => $userAgent,
                'hit_count'  => 1,
                'first_seen' => $now,
                'last_seen'  => $now,
            ];

            return 1;
        }

        if ($sql === "ALTER TABLE {$this->prefix}cel_404_log AUTO_INCREMENT = 1") {
            $rows = $this->tables[$this->prefix . 'cel_404_log'] ?? [];
            $max = 0;
            foreach ($rows as $row) {
                $max = max($max, (int) ($row['id'] ?? 0));
            }

            $this->autoIncrement[$this->prefix . 'cel_404_log'] = $max + 1;
            return 0;
        }

        return false;
    }

    public function insert(string $table, array $data, array $formats = []): int|false
    {
        if ($table === $this->prefix . 'cel_redirects') {
            $now = gmdate('Y-m-d H:i:s');
            $row = [
                'id'          => $this->nextId($table),
                'source_url'  => (string) ($data['source_url'] ?? ''),
                'target_url'  => (string) ($data['target_url'] ?? ''),
                'status_code' => (int) ($data['status_code'] ?? 301),
                'match_type'  => $data['match_type'] ?? 'exact',
                'hit_count'   => (int) ($data['hit_count'] ?? 0),
                'created_at'  => (string) ($data['created_at'] ?? $now),
                'updated_at'  => (string) ($data['updated_at'] ?? $now),
            ];

            $this->tables[$table][] = $row;
            return 1;
        }

        if ($table === $this->prefix . 'cel_404_log') {
            $now = gmdate('Y-m-d H:i:s');
            $row = [
                'id'         => $this->nextId($table),
                'url'        => (string) ($data['url'] ?? ''),
                'referrer'   => (string) ($data['referrer'] ?? ''),
                'user_agent' => (string) ($data['user_agent'] ?? ''),
                'hit_count'  => (int) ($data['hit_count'] ?? 1),
                'first_seen' => (string) ($data['first_seen'] ?? $now),
                'last_seen'  => (string) ($data['last_seen'] ?? $now),
            ];

            $this->tables[$table][] = $row;
            return 1;
        }

        return false;
    }

    public function delete(string $table, array $where, array $whereFormats = []): int|false
    {
        $rows = $this->tables[$table] ?? null;
        if ($rows === null) {
            return false;
        }

        $remaining = [];
        $deleted = 0;

        foreach ($rows as $row) {
            $matches = true;
            foreach ($where as $column => $value) {
                if (($row[$column] ?? null) != $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                $deleted++;
                continue;
            }

            $remaining[] = $row;
        }

        $this->tables[$table] = $remaining;

        return $deleted;
    }

    public function getOption(string $name): mixed
    {
        return $this->tables[$this->options][$name]['option_value'] ?? null;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->tables[$this->options]);
    }

    public function addOption(string $name, mixed $value, bool $autoload = false): bool
    {
        if ($this->hasOption($name)) {
            return false;
        }

        $this->tables[$this->options][$name] = [
            'option_name'  => $name,
            'option_value' => $value,
            'autoload'     => $autoload ? 'yes' : 'no',
        ];

        return true;
    }

    public function updateOption(string $name, mixed $value, bool|string|null $autoload = null): bool
    {
        $existingAutoload = $this->tables[$this->options][$name]['autoload'] ?? 'no';

        $this->tables[$this->options][$name] = [
            'option_name'  => $name,
            'option_value' => $value,
            'autoload'     => is_bool($autoload) ? ($autoload ? 'yes' : 'no') : $existingAutoload,
        ];

        return true;
    }

    public function deleteOption(string $name): bool
    {
        if (! $this->hasOption($name)) {
            return false;
        }

        unset($this->tables[$this->options][$name]);

        return true;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>, 2: string}
     */
    private function normalizeQuery(mixed $query): array
    {
        if ($query instanceof CelestialSitemapPreparedQuery) {
            return [
                $this->normalizeSql($query->query),
                $query->args,
                $this->normalizeSql($query->query),
            ];
        }

        return [
            $this->normalizeSql((string) $query),
            [],
            $this->normalizeSql((string) $query),
        ];
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }

    private function nextId(string $table): int
    {
        $next = $this->autoIncrement[$table] ?? 1;
        $this->autoIncrement[$table] = $next + 1;

        return $next;
    }

    private function likeMatches(string $pattern, string $value): bool
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && ($i + 1) < $length) {
                $regex .= preg_quote($pattern[$i + 1], '/');
                $i++;
                continue;
            }

            if ($char === '%') {
                $regex .= '.*';
                continue;
            }

            if ($char === '_') {
                $regex .= '.';
                continue;
            }

            $regex .= preg_quote($char, '/');
        }

        return (bool) preg_match('/^' . $regex . '$/', $value);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>|object>
     */
    private function formatRows(array $rows, string|int $output): array
    {
        return array_map(fn(array $row): array|object => $this->formatRow($row, $output), $rows);
    }

    private function formatRow(array $row, string|int $output): array|object
    {
        if ($output === ARRAY_A) {
            return $row;
        }

        return (object) $row;
    }
}

final class CelestialSitemapFakeWpQuery
{
    public bool $is_404 = false;

    public function set_404(): void
    {
        $this->is_404 = true;
    }
}

final class WP_Error
{
    public function __construct(
        public string $code = '',
        public string $message = ''
    ) {
    }
}

final class CelestialSitemapAjaxExit extends \RuntimeException
{
}

abstract class WP_UnitTestCase extends TestCase
{
    public static function set_up_before_class(): void
    {
    }

    public static function tear_down_after_class(): void
    {
    }

    protected function set_up(): void
    {
    }

    protected function tear_down(): void
    {
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::set_up_before_class();
    }

    public static function tearDownAfterClass(): void
    {
        static::tear_down_after_class();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->set_up();
    }

    protected function tearDown(): void
    {
        try {
            $this->tear_down();
        } finally {
            parent::tearDown();
        }
    }

    protected function go_to(string $uri): void
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $GLOBALS['wp_query'] = new CelestialSitemapFakeWpQuery();
    }
}

function celestial_sitemap_test_bootstrap(): void
{
    global $wpdb;

    if (! isset($wpdb) || ! ($wpdb instanceof CelestialSitemapFakeWpdb)) {
        $wpdb = new CelestialSitemapFakeWpdb();
    }

    $GLOBALS['wp_query'] = new CelestialSitemapFakeWpQuery();
    $GLOBALS['cel_wp_actions'] = [];
    $GLOBALS['cel_wp_filters'] = [];
    $GLOBALS['cel_wp_activation_hooks'] = [];
    $GLOBALS['cel_wp_deactivation_hooks'] = [];
    $GLOBALS['cel_wp_cron'] = [];
    $GLOBALS['cel_last_redirect'] = null;
    $GLOBALS['cel_wp_enqueued_styles'] = [];
    $GLOBALS['cel_wp_enqueued_scripts'] = [];
    $GLOBALS['cel_wp_localized_scripts'] = [];
    $GLOBALS['cel_test_term_counts'] = [];
    $GLOBALS['cel_test_terms'] = [];
    $GLOBALS['cel_test_term_meta'] = [];
    $GLOBALS['cel_test_posts'] = [];
    $GLOBALS['cel_test_post_meta'] = [];
    $GLOBALS['cel_test_taxonomies'] = [];
    $GLOBALS['cel_test_user_caps'] = [];
    $GLOBALS['cel_test_conditionals'] = [];
    $GLOBALS['cel_test_query_vars'] = [];
    $GLOBALS['cel_test_queried_object'] = null;
    $GLOBALS['cel_test_current_post'] = null;
    $GLOBALS['cel_test_current_post_id'] = 0;
    $GLOBALS['cel_test_document_title'] = '';
    $GLOBALS['cel_test_registered_post_types'] = [];
    $GLOBALS['cel_last_json_response'] = null;
    $GLOBALS['cel_last_status_header'] = null;
}

function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/\\') . DIRECTORY_SEPARATOR;
}

function plugin_dir_url(string $file): string
{
    return 'http://example.org/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function register_activation_hook(string $file, callable $callback): void
{
    $GLOBALS['cel_wp_activation_hooks'][$file] = $callback;
}

function register_deactivation_hook(string $file, callable $callback): void
{
    $GLOBALS['cel_wp_deactivation_hooks'][$file] = $callback;
}

function add_action(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['cel_wp_actions'][$hook][$priority][] = [$callback, $acceptedArgs];
}

function add_filter(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['cel_wp_filters'][$hook][$priority][] = [$callback, $acceptedArgs];
}

function tests_add_filter(string $hook, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    add_filter($hook, $callback, $priority, $acceptedArgs);
}

function load_plugin_textdomain(string $domain, bool $deprecated = false, string $pluginRelPath = ''): bool
{
    return true;
}

function is_admin(): bool
{
    return false;
}

function __return_false(): bool
{
    return false;
}

function __return_true(): bool
{
    return true;
}

function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void
{
}

function flush_rewrite_rules(bool $hard = true): void
{
}

function wp_cache_flush(): void
{
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    $GLOBALS['cel_wp_cron'][$hook][$timestamp] = ['recurrence' => $recurrence];
    ksort($GLOBALS['cel_wp_cron'][$hook]);

    return true;
}

function wp_schedule_single_event(int $timestamp, string $hook): bool
{
    $GLOBALS['cel_wp_cron'][$hook][$timestamp] = ['recurrence' => false];
    ksort($GLOBALS['cel_wp_cron'][$hook]);

    return true;
}

function wp_next_scheduled(string $hook): int|false
{
    if (empty($GLOBALS['cel_wp_cron'][$hook])) {
        return false;
    }

    $timestamps = array_keys($GLOBALS['cel_wp_cron'][$hook]);
    sort($timestamps);

    return $timestamps[0] ?? false;
}

function wp_unschedule_event(int $timestamp, string $hook): bool
{
    if (! isset($GLOBALS['cel_wp_cron'][$hook][$timestamp])) {
        return false;
    }

    unset($GLOBALS['cel_wp_cron'][$hook][$timestamp]);

    if ($GLOBALS['cel_wp_cron'][$hook] === []) {
        unset($GLOBALS['cel_wp_cron'][$hook]);
    }

    return true;
}

function add_option(string $option, mixed $value = '', string $deprecated = '', bool $autoload = true): bool
{
    global $wpdb;

    return $wpdb->addOption($option, $value, $autoload);
}

function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
{
    global $wpdb;

    return $wpdb->updateOption($option, $value, $autoload);
}

function get_option(string $option, mixed $default = false): mixed
{
    global $wpdb;

    return $wpdb->hasOption($option) ? $wpdb->getOption($option) : $default;
}

function delete_option(string $option): bool
{
    global $wpdb;

    return $wpdb->deleteOption($option);
}

function set_transient(string $transient, mixed $value, int $expiration = 0): bool
{
    update_option('_transient_' . $transient, $value);

    if ($expiration > 0) {
        update_option('_transient_timeout_' . $transient, time() + $expiration);
    } else {
        delete_option('_transient_timeout_' . $transient);
    }

    return true;
}

function get_transient(string $transient): mixed
{
    $timeout = get_option('_transient_timeout_' . $transient, null);

    if (is_int($timeout) && $timeout > 0 && $timeout < time()) {
        delete_transient($transient);
        return false;
    }

    return get_option('_transient_' . $transient, false);
}

function delete_transient(string $transient): bool
{
    $deleted = delete_option('_transient_' . $transient);
    delete_option('_transient_timeout_' . $transient);

    return $deleted;
}

function wp_parse_url(string $url, int $component = -1): mixed
{
    if (func_num_args() === 1) {
        return parse_url($url);
    }

    return parse_url($url, $component);
}

function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function sanitize_text_field(mixed $value): string
{
    $string = is_scalar($value) ? (string) $value : '';
    $string = strip_tags($string);

    return trim(preg_replace('/[\r\n\t ]+/', ' ', $string) ?? $string);
}

function sanitize_textarea_field(mixed $value): string
{
    $string = is_scalar($value) ? (string) $value : '';
    $string = str_replace("\r", '', $string);

    return trim(strip_tags($string));
}

function sanitize_key(string $key): string
{
    $key = strtolower($key);
    return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
}

function absint(mixed $maybeint): int
{
    return abs((int) $maybeint);
}

function esc_url_raw(string $url): string
{
    return trim($url);
}

function esc_url(string $url): string
{
    return trim($url);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function checked(mixed $checked, mixed $current = true, bool $display = true): string
{
    return (string) $checked === (string) $current ? 'checked="checked"' : '';
}

function selected(mixed $selected, mixed $current = true, bool $display = true): string
{
    return (string) $selected === (string) $current ? 'selected="selected"' : '';
}

function number_format_i18n(int|float $number, int $decimals = 0): string
{
    return number_format($number, $decimals, '.', ',');
}

function esc_xml(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . ltrim($path, '/');
}

function wp_create_nonce(string $action = '-1'): string
{
    return 'nonce-' . $action;
}

function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false): void
{
    $GLOBALS['cel_wp_enqueued_styles'][$handle] = [
        'src'  => $src,
        'deps' => $deps,
        'ver'  => $ver,
    ];
}

function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $inFooter = false): void
{
    $GLOBALS['cel_wp_enqueued_scripts'][$handle] = [
        'src'       => $src,
        'deps'      => $deps,
        'ver'       => $ver,
        'in_footer' => $inFooter,
    ];
}

function wp_localize_script(string $handle, string $objectName, array $l10n): bool
{
    $GLOBALS['cel_wp_localized_scripts'][$handle] = [
        'object_name' => $objectName,
        'data'        => $l10n,
    ];

    return true;
}

function check_ajax_referer(string $action = '-1', string|false $queryArg = false, bool $stop = true): int|false
{
    $key = $queryArg ?: '_ajax_nonce';
    $nonce = $_POST[$key] ?? $_REQUEST[$key] ?? '';
    $valid = wp_verify_nonce((string) $nonce, $action);

    if (! $valid && $stop) {
        throw new CelestialSitemapAjaxExit('Invalid AJAX nonce.');
    }

    return $valid;
}

function wp_send_json_success(mixed $data = null, int $statusCode = 200): void
{
    $GLOBALS['cel_last_json_response'] = [
        'success'     => true,
        'data'        => $data,
        'status_code' => $statusCode,
    ];

    throw new CelestialSitemapAjaxExit('AJAX success');
}

function wp_send_json_error(mixed $data = null, int $statusCode = 200): void
{
    $GLOBALS['cel_last_json_response'] = [
        'success'     => false,
        'data'        => $data,
        'status_code' => $statusCode,
    ];

    throw new CelestialSitemapAjaxExit('AJAX error');
}

function wp_die(string $message = ''): never
{
    throw new \RuntimeException($message);
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (empty($GLOBALS['cel_wp_filters'][$hook])) {
        return $value;
    }

    ksort($GLOBALS['cel_wp_filters'][$hook]);

    foreach ($GLOBALS['cel_wp_filters'][$hook] as $callbacks) {
        foreach ($callbacks as [$callback, $acceptedArgs]) {
            $callbackArgs = array_slice([$value, ...$args], 0, $acceptedArgs);
            $value = $callback(...$callbackArgs);
        }
    }

    return $value;
}

function home_url(string $path = ''): string
{
    return 'https://example.org' . ($path !== '' ? $path : '/');
}

function get_bloginfo(string $show = '', string $filter = 'raw'): string
{
    return match ($show) {
        'name' => 'Example Site',
        'description' => 'Example Description',
        default => '',
    };
}

function get_locale(): string
{
    return 'en_US';
}

function get_taxonomies(array $args = [], string $output = 'names'): array
{
    $taxonomies = $GLOBALS['cel_test_taxonomies'];

    if ($output === 'names') {
        return array_keys($taxonomies);
    }

    return array_values($taxonomies);
}

function get_taxonomy(string $taxonomy): object|false
{
    return $GLOBALS['cel_test_taxonomies'][$taxonomy] ?? false;
}

function post_type_exists(string $postType): bool
{
    return in_array($postType, $GLOBALS['cel_test_registered_post_types'], true);
}

function taxonomy_exists(string $taxonomy): bool
{
    return isset($GLOBALS['cel_test_term_counts'][$taxonomy])
        || isset($GLOBALS['cel_test_terms'][$taxonomy]);
}

function wp_count_posts(string $type): object
{
    return (object) ['publish' => 0];
}

function wp_count_terms(array|string $args = [], array $deprecated = []): int
{
    if (is_string($args)) {
        $taxonomy = $args;
    } else {
        $taxonomy = (string) ($args['taxonomy'] ?? '');
    }

    return (int) ($GLOBALS['cel_test_term_counts'][$taxonomy] ?? 0);
}

function get_terms(array $args = []): array
{
    $taxonomy = (string) ($args['taxonomy'] ?? '');
    $terms    = $GLOBALS['cel_test_terms'][$taxonomy] ?? [];
    $offset   = (int) ($args['offset'] ?? 0);
    $number   = (int) ($args['number'] ?? 0);
    $fields   = (string) ($args['fields'] ?? 'all');

    if (! empty($args['meta_query']) && is_array($args['meta_query'])) {
        $terms = array_values(array_filter($terms, static function (object $term) use ($args): bool {
            $metaQuery = $args['meta_query'];
            $noindex = $GLOBALS['cel_test_term_meta'][(int) ($term->term_id ?? 0)]['_cel_noindex'] ?? null;

            foreach ($metaQuery as $clause) {
                if (! is_array($clause)) {
                    continue;
                }

                $key = (string) ($clause['key'] ?? '');
                $compare = (string) ($clause['compare'] ?? '=');
                $value = $clause['value'] ?? null;

                if ($key !== '_cel_noindex') {
                    continue;
                }

                if ($compare === 'NOT EXISTS' && $noindex === null) {
                    return true;
                }

                if ($compare === '!=' && (string) $noindex !== (string) $value) {
                    return true;
                }
            }

            return false;
        }));
    }

    if ($number <= 0) {
        $terms = array_slice($terms, $offset);
    } else {
        $terms = array_slice($terms, $offset, $number);
    }

    if ($fields === 'ids') {
        return array_map(static fn(object $term): int => (int) $term->term_id, $terms);
    }

    return $terms;
}

function get_term_meta(int $termId, string $key, bool $single = false): mixed
{
    return $GLOBALS['cel_test_term_meta'][$termId][$key] ?? '';
}

function update_term_meta(int $termId, string $metaKey, mixed $metaValue): bool
{
    $GLOBALS['cel_test_term_meta'][$termId][$metaKey] = $metaValue;

    return true;
}

function delete_term_meta(int $termId, string $metaKey): bool
{
    unset($GLOBALS['cel_test_term_meta'][$termId][$metaKey]);

    return true;
}

function get_term_link(object|int|string $term): string
{
    $termId = is_object($term) ? (int) ($term->term_id ?? 0) : (int) $term;

    return 'https://example.org/term-' . $termId . '/';
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function update_postmeta_cache(array $postIds): void
{
}

function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
{
}

function get_post(int|object|null $post = null): object|null
{
    if (is_object($post)) {
        return $post;
    }

    if ($post === null) {
        return $GLOBALS['cel_test_current_post'];
    }

    return $GLOBALS['cel_test_posts'][(int) $post] ?? null;
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    return $GLOBALS['cel_test_post_meta'][$postId][$key] ?? '';
}

function get_permalink(int|object|null $post = null): string|false
{
    $postObject = is_object($post) ? $post : get_post($post);
    if (! $postObject) {
        return false;
    }

    return $postObject->permalink ?? ('https://example.org/post-' . (int) $postObject->ID . '/');
}

function get_the_title(int|object $post): string
{
    $postObject = is_object($post) ? $post : get_post($post);

    return $postObject->post_title ?? '';
}

function get_the_ID(): int
{
    if ($GLOBALS['cel_test_current_post_id']) {
        return (int) $GLOBALS['cel_test_current_post_id'];
    }

    return (int) (($GLOBALS['cel_test_current_post']->ID ?? 0));
}

function wp_get_document_title(): string
{
    if ($GLOBALS['cel_test_document_title'] !== '') {
        return (string) $GLOBALS['cel_test_document_title'];
    }

    $post = get_post();
    return $post ? (string) ($post->post_title ?? '') : '';
}

function wp_get_canonical_url(int $postId = 0): string
{
    $link = get_permalink($postId);
    return $link ? (string) $link : '';
}

function has_post_thumbnail(int|object|null $post = null): bool
{
    $postObject = is_object($post) ? $post : get_post($post);
    if (! $postObject) {
        return false;
    }

    return (int) ($GLOBALS['cel_test_post_meta'][(int) $postObject->ID]['_thumbnail_id'] ?? 0) > 0;
}

function get_post_thumbnail_id(int|object $post): int
{
    $postObject = is_object($post) ? $post : get_post($post);
    if (! $postObject) {
        return 0;
    }

    return (int) ($GLOBALS['cel_test_post_meta'][(int) $postObject->ID]['_thumbnail_id'] ?? 0);
}

function wp_get_attachment_image_src(int $attachmentId, string $size = 'thumbnail'): array|false
{
    $url = $GLOBALS['cel_test_post_meta'][$attachmentId]['_attachment_url'] ?? '';
    if ($url === '') {
        return false;
    }

    return [$url, 1200, 630];
}

function get_the_date(string $format = '', int|object|null $post = null): string
{
    $postObject = is_object($post) ? $post : get_post($post);
    $date = $postObject->post_date_gmt ?? gmdate('Y-m-d H:i:s');
    $timestamp = strtotime((string) $date) ?: time();

    return $format !== '' ? gmdate($format, $timestamp) : gmdate('Y-m-d', $timestamp);
}

function get_the_modified_date(string $format = '', int|object|null $post = null): string
{
    $postObject = is_object($post) ? $post : get_post($post);
    $date = $postObject->post_modified_gmt ?? $postObject->post_date_gmt ?? gmdate('Y-m-d H:i:s');
    $timestamp = strtotime((string) $date) ?: time();

    return $format !== '' ? gmdate($format, $timestamp) : gmdate('Y-m-d', $timestamp);
}

function get_query_var(string $var, mixed $default = ''): mixed
{
    return $GLOBALS['cel_test_query_vars'][$var] ?? $default;
}

function get_pagenum_link(int $pagenum = 1): string
{
    return 'https://example.org/page/' . $pagenum . '/';
}

function get_queried_object(): mixed
{
    return $GLOBALS['cel_test_queried_object'];
}

function is_front_page(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_front_page'] ?? false);
}

function is_home(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_home'] ?? false);
}

function is_singular(string $postType = ''): bool
{
    $isSingular = (bool) ($GLOBALS['cel_test_conditionals']['is_singular'] ?? false);
    if (! $isSingular) {
        return false;
    }

    if ($postType === '') {
        return true;
    }

    $post = get_post();
    return $post !== null && ($post->post_type ?? '') === $postType;
}

function is_category(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_category'] ?? false);
}

function is_tag(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_tag'] ?? false);
}

function is_tax(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_tax'] ?? false);
}

function is_post_type_archive(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_post_type_archive'] ?? false);
}

function is_author(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_author'] ?? false);
}

function is_archive(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_archive'] ?? false);
}

function is_search(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_search'] ?? false);
}

function is_date(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_date'] ?? false);
}

function is_paged(): bool
{
    return (bool) ($GLOBALS['cel_test_conditionals']['is_paged'] ?? false);
}

function post_password_required(object|null $post = null): bool
{
    return false;
}

function strip_shortcodes(string $content): string
{
    return $content;
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags($text));
}

function current_user_can(string $capability, mixed ...$args): bool
{
    if ($GLOBALS['cel_test_user_caps'] === []) {
        return true;
    }

    return (bool) ($GLOBALS['cel_test_user_caps'][$capability] ?? false);
}

function wp_verify_nonce(string $nonce, string|int $action = -1): bool|int
{
    return $nonce === 'nonce-' . $action ? 1 : false;
}

function wp_get_referer(): string|false
{
    return $_SERVER['HTTP_REFERER'] ?? false;
}

function current_time(string $type, bool $gmt = false): string|int
{
    if ($type === 'mysql') {
        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }

    return time();
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'celestial-sitemap-test-salt-' . $scheme;
}

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return $text;
}

function get_current_blog_id(): int
{
    return 1;
}

function wp_mkdir_p(string $target): bool
{
    return is_dir($target) || mkdir($target, 0777, true);
}

function is_404(): bool
{
    return ! empty($GLOBALS['wp_query']) && $GLOBALS['wp_query'] instanceof CelestialSitemapFakeWpQuery
        && $GLOBALS['wp_query']->is_404;
}

function wp_redirect(string $location, int $status = 302): bool
{
    $GLOBALS['cel_last_redirect'] = [
        'location' => $location,
        'status'   => $status,
    ];

    return true;
}

function maybe_unserialize(mixed $value): mixed
{
    if (! is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);
    if ($trimmed === 'b:0;' || preg_match('/^(?:a|O|s|i|d|b):/', $trimmed)) {
        $unserialized = @unserialize($trimmed);
        if ($unserialized !== false || $trimmed === 'b:0;') {
            return $unserialized;
        }
    }

    return $value;
}

function dbDelta(string $sql): array
{
    global $wpdb;

    return $wpdb->dbDelta($sql);
}

if (! function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

celestial_sitemap_test_bootstrap();
