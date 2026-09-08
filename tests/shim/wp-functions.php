<?php
/**
 * WordPress function shims for headless testing.
 *
 * Provides just enough of the WordPress API surface (options,
 * transients, hooks, users, posts, escaping) to boot the plugin
 * kernel and exercise the security, tools and MCP pipelines without
 * a WordPress installation.
 *
 * Test cases can override any behavior by redefining state in
 * $GLOBALS['__wp_shim'].
 *
 * @package AIOS\TestShim
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ . '/../../../../../' ); // Plugin dir parent.
}

if ( ! defined( 'AI_TEST_CONTEXT' ) ) {
        define( 'AI_TEST_CONTEXT', true );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
        define( 'MINUTE_IN_SECONDS', 60 );
        define( 'HOUR_IN_SECONDS', 3600 );
        define( 'DAY_IN_SECONDS', 86400 );
        define( 'WEEK_IN_SECONDS', 7 * 86400 );
        define( 'MONTH_IN_SECONDS', 30 * 86400 );
        define( 'YEAR_IN_SECONDS', 365 * 86400 );
}

if ( ! defined( 'ARRAY_A' ) ) {
        define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
        define( 'ARRAY_N', 'ARRAY_N' );
}

if ( ! defined( 'WPINC' ) ) {
        define( 'WPINC', 'wp-includes' );
}

$GLOBALS['__wp_shim'] = array(
        'options'    => array(),
        'transients' => array(),
        'filters'    => array(),
        'actions'    => array(),
        'posts'      => array(),
        'sitecache'  => array(),
        'current_user' => null,
        // Minimal multisite support (BUG-005): a network is just a list
        // of blog ids plus a "which one is current" pointer. Real
        // per-site table isolation is verified via 'created_tables'
        // (dbDelta() below logs every table name it is asked to create)
        // rather than by making the wpdb SQL-regex engine understand
        // arbitrary "wp_2_..." prefixes — that engine is a deliberately
        // small single-site test double, not a SQL parser, and teaching
        // it full multisite prefix handling is out of this sprint's
        // scope (see docs/roadmap/IMPLEMENTATION-TRACKER.md T-018).
        'multisite'         => false,
        'network_active'    => true,
        'sites'             => array( 1 ), // blog ids; 1 = main site.
        'current_blog_id'   => 1,
        'blog_switch_stack' => array(),
        'created_tables'    => array(),
        'roles'             => array(),
);

function __shim_state(): array {
        return $GLOBALS['__wp_shim'];
}

/**
 * Fire a recorded action (tests drive plugin boot this way).
 */
function __run_hook( string $tag, ...$args ): void {
        do_action( $tag, ...$args );
}

function __reset_shim(): void {
        $GLOBALS['__wp_shim'] = array(
                'options'    => array(),
                'transients' => array(),
                'filters'    => array(),
                'actions'    => array(),
                'posts'      => array(),
                'sitecache'  => array(),
                'current_user' => null,
                'routes'     => array(),
                'multisite'         => false,
                'network_active'    => true,
                'sites'             => array( 1 ),
                'current_blog_id'   => 1,
                'blog_switch_stack' => array(),
                'created_tables'    => array(),
                'roles'             => array(),
                // Per-user, per-site capability overlay written by
                // WP_User::add_cap()/remove_cap() and read back by
                // get_userdata(): [blog_id][user_id][capability] = true.
                // Mirrors real WordPress's per-site usermeta storage
                // closely enough for CapabilityManager's grant/revoke
                // tests, including multisite isolation.
                'user_caps'         => array(),
        );
        $GLOBALS['wpdb'] = new wpdb();
}

function plugin_dir_path( string $file ): string {
        return rtrim( dirname( $file ), '/\\' ) . '/';
}

function plugin_dir_url( string $file ): string {
        return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

/* --------------------------------------------------------------- options */

/**
 * Options and transients are keyed by the CURRENT blog id (BUG-005),
 * exactly like real WordPress (they live in each site's own
 * wp_options table). Every existing single-site test leaves
 * current_blog_id at its default of 1 and never calls
 * switch_to_blog(), so this is purely additive: those tests see
 * identical behavior to the previous flat store, just nested one
 * level under blog id 1.
 */
function get_option( string $name, mixed $default = false ): mixed {
        $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;
        return $GLOBALS['__wp_shim']['options'][ $blog_id ][ $name ] ?? $default;
}

function get_site_option( string $name, mixed $default = false ): mixed {
        return get_option( 'site:' . $name, $default );
}

function update_site_option( string $name, mixed $value ): bool {
        return update_option( 'site:' . $name, $value );
}

function delete_site_transient( string $key ): bool {
        return delete_transient( 'site:' . $key );
}

function update_option( string $name, mixed $value, bool $autoload = true ): bool {
        $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;
        $GLOBALS['__wp_shim']['options'][ $blog_id ][ $name ] = $value;
        return true;
}

function delete_option( string $name ): bool {
        $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;
        unset( $GLOBALS['__wp_shim']['options'][ $blog_id ][ $name ] );
        return true;
}

function add_option( string $name, mixed $value ): bool {
        $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;
        $GLOBALS['__wp_shim']['options'][ $blog_id ][ $name ] = $value;
        return true;
}

/* ------------------------------------------------------------ transients */

function get_transient( string $key ): mixed {
        $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;
        return $GLOBALS['__wp_shim']['transients'][ $blog_id ][ $key ] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
        $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;
        $GLOBALS['__wp_shim']['transients'][ $blog_id ][ $key ] = $value;
        return true;
}

function delete_transient( string $key ): bool {
        $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;
        unset( $GLOBALS['__wp_shim']['transients'][ $blog_id ][ $key ] );
        return true;
}

/* ----------------------------------------------------------------- hooks */

function add_filter( string $tag, callable $callback, int $priority = 10, int $args = 1 ): true {
        $GLOBALS['__wp_shim']['filters'][ $tag ][] = array( $callback, $priority );
        return true;
}

function apply_filters( string $tag, mixed $value, ...$rest ): mixed {
        foreach ( $GLOBALS['__wp_shim']['filters'][ $tag ] ?? array() as $entry ) {
                $value = ( $entry[0] )( $value, ...$rest );
        }
        return $value;
}

function do_action( string $tag, ...$args ): void {
        foreach ( $GLOBALS['__wp_shim']['filters'][ $tag ] ?? array() as $entry ) {
                ( $entry[0] )( ...$args );
        }
}

function add_action( string $tag, callable $callback, int $priority = 10, int $args = 1 ): true {
        return add_filter( $tag, $callback, $priority, $args );
}

/* ------------------------------------------------------------------ time */

function current_time( string $type, bool $gmt = false ): string|int {
        return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
}

/* ------------------------------------------------------------------ misc */

function wp_json_encode( mixed $data, int $flags = 0 ): string|false {
        return json_encode( $data, $flags );
}

function __( string $text, string $domain = 'default' ): string {
        return $text;
}

function esc_html__( string $text, string $domain = 'default' ): string {
        return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_html( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_attr( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES );
}

function sanitize_text_field( string $text ): string {
        return trim( preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? $text );
}

function sanitize_key( string $key ): string {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? $key );
}

function sanitize_file_name( string $name ): string {
        $name = preg_replace( '/[^A-Za-z0-9._\-]/', '-', $name ) ?? $name;
        return trim( $name, '-' );
}

function sanitize_title( string $title ): string {
        $title = strtolower( $title );
        $title = preg_replace( '/[^a-z0-9\- ]/', '', $title ) ?? $title;
        return str_replace( ' ', '-', trim( $title ) );
}

function sanitize_mime_type( string $mime ): string|false {
        return preg_match( '/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/i', $mime ) ? $mime : false;
}

function absint( mixed $value ): int {
        return abs( (int) $value );
}

function is_wp_error( mixed $thing ): bool {
        return $thing instanceof WP_Error;
}

function wp_verify_nonce( string $nonce, string $action ): bool {
        return hash_equals( wp_create_nonce( $action ), $nonce );
}

function wp_create_nonce( string $action ): string {
        return substr( hash( 'sha256', 'nonce|' . $action ), 0, 20 );
}

function wp_salt( string $scheme = 'auth' ): string {
        return 'test-salt-' . $scheme;
}

function load_plugin_textdomain( string $domain, bool $deprecated = false, string|false $path = false ): bool {
        return true;
}

function wp_timezone_string(): string {
        return 'UTC';
}

function get_bloginfo( string $name ): string {
        return match ( $name ) {
                'version' => '6.9',
                'language' => 'en-US',
                'name' => 'AI OS Test Site',
                'description' => 'A test site',
                default => '',
        };
}

function is_multisite(): bool {
        return $GLOBALS['__wp_shim']['multisite'];
}

/**
 * @param array{fields?: string} $args
 * @return array<int, int>|array<int, object{blog_id: int}>
 */
function get_sites( array $args = array() ): array {
        $ids = $GLOBALS['__wp_shim']['sites'];
        if ( 'ids' === ( $args['fields'] ?? '' ) ) {
                return array_map( 'intval', $ids );
        }
        return array_map( static fn( int $id ): object => (object) array( 'blog_id' => $id ), $ids );
}

function get_current_blog_id(): int {
        return (int) $GLOBALS['__wp_shim']['current_blog_id'];
}

/**
 * Mirrors real WP prefixing: the main site (blog 1) uses the bare
 * prefix; every other site gets "{prefix}{blog_id}_".
 */
function switch_to_blog( int $blog_id ): bool {
        global $wpdb;
        $GLOBALS['__wp_shim']['blog_switch_stack'][] = array( $GLOBALS['__wp_shim']['current_blog_id'], $wpdb->prefix );
        $GLOBALS['__wp_shim']['current_blog_id'] = $blog_id;
        $wpdb->prefix = 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
        return true;
}

function restore_current_blog(): bool {
        global $wpdb;
        $previous = array_pop( $GLOBALS['__wp_shim']['blog_switch_stack'] );
        if ( null === $previous ) {
                return false;
        }
        [ $blog_id, $prefix ]                      = $previous;
        $GLOBALS['__wp_shim']['current_blog_id']   = $blog_id;
        $wpdb->prefix                              = $prefix;
        return true;
}

function is_plugin_active_for_network( string $plugin ): bool {
        return $GLOBALS['__wp_shim']['network_active'];
}

function is_ssl(): bool {
        return $GLOBALS['__wp_shim']['is_ssl'] ?? true;
}

/**
 * Mirrors real WordPress: resolves purely from server-side
 * configuration (the WP_ENVIRONMENT_TYPE constant, or the
 * wp_get_environment_type filter) — never from any request header, so
 * a test can control it without ever touching $_SERVER.
 */
function wp_get_environment_type(): string {
        return $GLOBALS['__wp_shim']['environment_type'] ?? 'production';
}

function is_admin(): bool {
        return false;
}

function wp_using_ext_object_cache(): bool {
        return false;
}

function wp_cache_flush(): bool {
        return true;
}

function get_current_user_id(): int {
        $user = wp_get_current_user();
        return $user ? (int) $user->ID : 0;
}

function wp_get_current_user(): WP_User {
        return $GLOBALS['__wp_shim']['current_user'] ?? new WP_User( 0 );
}

function plugin_basename( string $file ): string {
        return basename( dirname( $file ) ) . '/' . basename( $file );
}

function register_activation_hook( string $file, callable $callback ): void {}
function register_deactivation_hook( string $file, callable $callback ): void {}
function wp_die( string $message ): void {
        throw new RuntimeException( 'wp_die: ' . $message );
}
function deactivate_plugins( string $plugin ): void {}
function add_action_stub(): void {}

/* ------------------------------------------------------------------ WP_Error stub */

class WP_Error {
        private array $errors = array();

        /** @var array<string, mixed> */
        private array $error_data = array();

        public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
                if ( '' !== $code ) {
                        $this->errors[ $code ] = $message;
                        if ( '' !== $data ) {
                                $this->error_data[ $code ] = $data;
                        }
                }
        }

        public function get_error_code(): string {
                return (string) array_key_first( $this->errors );
        }

        public function get_error_message( string $code = '' ): string {
                if ( '' === $code ) {
                        $first = array_key_first( $this->errors );
                        return $first === null ? '' : (string) $this->errors[ $first ];
                }
                return (string) ( $this->errors[ $code ] ?? '' );
        }

        /**
         * @return mixed
         */
        public function get_error_data( string $code = '' ) {
                if ( '' === $code ) {
                        $code = (string) array_key_first( $this->errors );
                }
                return $this->error_data[ $code ] ?? null;
        }
}

/* ------------------------------------------------------------------ WP_User stub */

class WP_User {
        public int $ID;
        public string $user_login = 'testuser';
        public string $display_name = 'Test User';
        public string $user_email = 'test@example.com';
        public string $user_registered = '2025-01-01 00:00:00';
        public string $user_url = '';
        /** @var array<string, bool> */
        public array $allcaps = array();
        /** @var string[] */
        public array $roles = array( 'administrator' );

        public function __construct( int $id = 0, ?array $caps = null ) {
                $this->ID = $id;
                // Null → admin default; an EXPLICIT empty array means no caps.
                $this->allcaps = $caps ?? array(
                        'manage_options' => true,
                        'edit_posts' => true,
                        'edit_pages' => true,
                        'upload_files' => true,
                        'publish_posts' => true,
                        'publish_pages' => true,
                        'delete_posts' => true,
                        'edit_others_posts' => true,
                        'list_users' => true,
                        'activate_plugins' => true,
                        'switch_themes' => true,
                        'edit_theme_options' => true,
                        'ai_os_use' => true,
                        'ai_os_approve' => true,
                );
        }

        /**
         * Real WP_User::add_cap()/remove_cap() persist to the user's
         * usermeta (per-site in multisite). This mirrors that: the
         * in-memory allcaps is updated immediately (so the same
         * already-fetched instance reflects the change), AND the
         * change is persisted to the current blog's overlay so a
         * later get_userdata() call for this id sees it too.
         */
        public function add_cap( string $cap, bool $grant = true ): void {
                $this->allcaps[ $cap ] = $grant;
                $blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
                $GLOBALS['__wp_shim']['user_caps'][ $blog_id ][ $this->ID ][ $cap ] = $grant;
        }

        public function remove_cap( string $cap ): void {
                unset( $this->allcaps[ $cap ] );
                $blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
                unset( $GLOBALS['__wp_shim']['user_caps'][ $blog_id ][ $this->ID ][ $cap ] );
        }

        public function has_cap( string $capability ): bool {
                // Approximate map_meta_cap for the meta caps the plugin uses:
                // edit_post/delete_post resolve to their primitive caps.
                $mapped = match ( $capability ) {
                        'edit_post', 'edit_page'    => array( 'edit_posts', 'edit_pages', 'edit_others_posts' ),
                        'delete_post', 'delete_page' => array( 'delete_posts', 'delete_others_posts', 'manage_options' ),
                        default                     => null,
                };
                if ( null !== $mapped ) {
                        foreach ( $mapped as $candidate ) {
                                if ( ! empty( $this->allcaps[ $candidate ] ) ) {
                                        return true;
                                }
                        }
                        return false;
                }
                return (bool) ( $this->allcaps[ $capability ] ?? false );
        }

        public function exists(): bool {
                return $this->ID > 0;
        }
}

/* ------------------------------------------------------------------ posts */

/**
 * Minimal post store: $GLOBALS['__wp_shim']['posts'][id] = array( ... ).
 */
function wp_insert_post( array $postarr, bool $wp_error = false ): int|WP_Error {
        $state =& $GLOBALS['__wp_shim'];
        $id = count( $state['posts'] ) + 1000;
        $post = array_merge(
                array(
                        'ID' => $id,
                        'post_type' => 'post',
                        'post_status' => 'draft',
                        'post_title' => '',
                        'post_name' => '',
                        'post_content' => '',
                        'post_excerpt' => '',
                        'post_author' => 1,
                        'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ),
                        'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
                ),
                $postarr
        );
        $post['ID'] = $id;
        $state['posts'][ $id ] = $post;
        return $id;
}

function wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error {
        $id = (int) ( $postarr['ID'] ?? 0 );
        if ( ! isset( $GLOBALS['__wp_shim']['posts'][ $id ] ) ) {
                return $wp_error ? new WP_Error( 'invalid_post', 'Post does not exist' ) : 0;
        }
        $GLOBALS['__wp_shim']['posts'][ $id ] = array_merge( $GLOBALS['__wp_shim']['posts'][ $id ], $postarr );
        $GLOBALS['__wp_shim']['posts'][ $id ]['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s' );
        return $id;
}

function get_post( int|WP_Post|null $post ): WP_Post|null {
        $state = __shim_state();
        if ( $post instanceof WP_Post ) {
                return $post;
        }
        $data = $state['posts'][ (int) $post ] ?? null;
        if ( null === $data ) {
                return null;
        }
        $object = new WP_Post();
        foreach ( $data as $key => $value ) {
                $object->{$key} = $value;
        }
        return $object;
}

class WP_Post {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_name = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public int $post_author = 0;
        public string $post_date_gmt = '';
        public string $post_modified_gmt = '';
        public string $post_mime_type = '';
}

function wp_trash_post( int $id ): bool {
        if ( isset( $GLOBALS['__wp_shim']['posts'][ $id ] ) ) {
                $GLOBALS['__wp_shim']['posts'][ $id ]['post_status'] = 'trash';
                return true;
        }
        return false;
}

/**
 * Matches real WordPress's actual contract: values are always stored
 * as a list per key (update_post_meta() below wraps every value in
 * array($value), mirroring how WP itself supports multiple values per
 * meta key via add_post_meta()). $single=true unwraps to the first
 * stored value (or '' if none); $single=false (default) returns the
 * full list. A prior version of this shim returned the raw stored
 * list even when $single was true — undetected until Sprint 0.3A's
 * MetadataUpdateOperation exercised the true-branch through a real
 * apply()->verify() cycle for the first time.
 */
function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
        $state = __shim_state();
        $meta = $state['sitecache'][ "meta_{$id}" ] ?? array();
        if ( '' === $key ) {
                return $meta;
        }
        if ( ! array_key_exists( $key, $meta ) ) {
                return $single ? '' : array();
        }
        $values = $meta[ $key ];
        return $single ? ( $values[0] ?? '' ) : $values;
}

function update_post_meta( int $id, string $key, mixed $value ): bool {
        $GLOBALS['__wp_shim']['sitecache'][ "meta_{$id}" ][ $key ] = array( $value );
        return true;
}

function delete_post_meta( int $id, string $key ): bool {
        unset( $GLOBALS['__wp_shim']['sitecache'][ "meta_{$id}" ][ $key ] );
        return true;
}

function get_permalink( int|WP_Post $post ): string|false {
        $id = $post instanceof WP_Post ? $post->ID : $post;
        return isset( $GLOBALS['__wp_shim']['posts'][ $id ] ) ? 'https://example.test/?p=' . $id : false;
}

function get_the_title( int|WP_Post $post ): string {
        $p = get_post( $post );
        return $p?->post_title ?? '';
}

function get_the_excerpt( $post ): string {
        return '';
}

function wp_trim_words( string $text, int $num = 55 ): string {
        return implode( ' ', array_slice( explode( ' ', $text ), 0, $num ) );
}

function wp_strip_all_tags( string $text ): string {
        return trim( strip_tags( $text ) );
}

/* ------------------------------------------------------------- theme stubs */

function get_theme_root( string $stylesheet_or_template = '' ): string {
        return sys_get_temp_dir() . '/aios-test-themes';
}

function get_stylesheet(): string {
        return 'testtheme';
}

function get_template(): string {
        return 'testtheme';
}

function is_child_theme(): bool {
        return false;
}

function wp_get_theme( string $stylesheet = '' ): object {
        $slug = '' === $stylesheet ? get_stylesheet() : $stylesheet;
        return new class( $slug ) {
                public function __construct( private string $slug ) {}
                public function get( string $header ): string {
                        return match ( $header ) {
                                'Name' => 'Test Theme',
                                'Version' => '1.2.3',
                                'Author' => 'Tester',
                                'TextDomain' => 'testtheme',
                                default => '',
                        };
                }
                public function exists(): bool {
                        return true;
                }
                public function get_files( string $type, int $depth = 0 ): array {
                        return array();
                }
                public function get_stylesheet(): string {
                        return $this->slug;
                }
                public function get_template(): string {
                        return $this->slug;
                }
        };
}

function current_theme_supports( string $feature ): bool {
        return in_array( $feature, array( 'post-thumbnails', 'title-tag', 'html5' ), true );
}

function untrailingslashit( string $value ): string {
        return rtrim( $value, '/' );
}

function trailingslashit( string $value ): string {
        return rtrim( $value, '/' ) . '/';
}

/* -------------------------------------------------------- remaining stubs */

function get_nav_menu_locations(): array {
        return array();
}

function wp_get_nav_menus(): array {
        return array();
}

function wp_get_nav_menu_object( int|string $menu ): object|false {
        return false;
}

function wp_get_nav_menu_items( int|string $menu ): array|false {
        return array();
}

function get_object_taxonomies( $object, string $output = 'names' ): array {
        return array();
}

function wp_get_post_terms( int $id, string $taxonomy, array $args = array() ): array {
        return array();
}

function is_serialized( mixed $data ): bool {
        return is_string( $data ) && str_starts_with( trim( $data ), 'a:' );
}

// get_loaded_extensions() is a PHP built-in — no shim needed.

function count_users(): array {
        return array( 'total_users' => 2 );
}

function wp_count_posts( string $type = 'post' ): object {
        $counts = new stdClass();
        $counts->publish = 3;
        $counts->inherit = 5;
        return $counts;
}

function get_post_types( array $args = array(), string $output = 'names' ): array {
        $types = array(
                'post' => array( 'name' => 'post', 'label' => 'Posts', 'public' => true ),
                'page' => array( 'name' => 'page', 'label' => 'Pages', 'public' => true ),
        );
        if ( 'objects' === $output ) {
                $objects = array();
                foreach ( $types as $key => $data ) {
                        $objects[ $key ] = new stdClass();
                        foreach ( $data as $field => $value ) {
                                $objects[ $key ]->{$field} = $value;
                        }
                }
                return $objects;
        }
        return array_keys( $types );
}

function get_taxonomies( array $args = array(), string $output = 'names' ): array {
        if ( 'objects' === $output ) {
                $object = new stdClass();
                $object->name = 'category';
                $object->label = 'Categories';
                $object->hierarchical = true;
                return array( 'category' => $object );
        }
        return array( 'category' );
}

function wp_count_terms( array|string $args ): int|WP_Error {
        return 2;
}

function get_users( array $args = array() ): array {
        return array();
}

class WP_Query {
        public array $posts = array();
        public int $found_posts = 0;
        public int $max_num_pages = 1;

        public function __construct( array $args = array() ) {
                $state = __shim_state();
                $per_page = (int) ( $args['posts_per_page'] ?? 10 );
                $page = (int) ( $args['paged'] ?? 1 );
                $all = array_values( array_filter(
                        $state['posts'],
                        static function ( array $p ) use ( $args ): bool {
                                if ( isset( $args['post_type'] ) && ( $args['post_type'] !== $p['post_type'] ) ) {
                                        return false;
                                }
                                if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && $args['post_status'] !== $p['post_status'] ) {
                                        return false;
                                }
                                if ( isset( $args['s'] ) && '' !== $args['s'] ) {
                                        $haystack = $p['post_title'] . ' ' . $p['post_content'];
                                        if ( ! str_contains( strtolower( $haystack ), strtolower( (string) $args['s'] ) ) ) {
                                                return false;
                                        }
                                }
                                return true;
                        }
                ) );
                $this->found_posts = count( $all );
                $this->max_num_pages = (int) ceil( max( 1, count( $all ) ) / max( 1, $per_page ) );
                foreach ( array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) as $data ) {
                        $post = new WP_Post();
                        foreach ( $data as $key => $value ) {
                                $post->{$key} = $value;
                        }
                        $this->posts[] = $post;
                }
        }
}

class WP_User_Query {
        private array $results = array();
        private int $total = 0;

        public function __construct( array $args = array() ) {
                $users = array(
                        new WP_User( 1 ),
                        new WP_User( 2, array( 'edit_posts' => true, 'upload_files' => true, 'ai_os_use' => true ) ),
                );
                $this->results = $users;
                $this->total = count( $users );
        }

        public function get_results(): array {
                return $this->results;
        }

        public function get_total(): int {
                return $this->total;
        }
}

/**
 * User ids 1-4 mirror TestCase's adminUser()/editorUser()/
 * contributorUser()/anonymousUser() fixtures exactly, so a user
 * fetched here and one fetched via those helpers agree on baseline
 * capabilities. Any grant/revoke made via WP_User::add_cap()/
 * remove_cap() (CapabilityManager) is layered on top, scoped to the
 * current blog — the same per-site isolation real WordPress usermeta
 * has.
 */
function get_userdata( int $id ): WP_User|false {
        $blog_id   = get_current_blog_id();
        $persisted = $GLOBALS['__wp_shim']['user_caps'][ $blog_id ][ $id ] ?? array();

        if ( ! in_array( $id, array( 1, 2, 3, 4 ), true ) && array() === $persisted ) {
                return false; // Unknown user, never granted anything on this site: does not exist.
        }

        $user = match ( $id ) {
                1       => new WP_User( 1 ), // null caps arg → WP_User's own full-admin default.
                2       => new WP_User( 2, array( 'edit_posts' => true, 'upload_files' => true, 'ai_os_use' => true ) ),
                3       => new WP_User( 3, array( 'edit_posts' => true, 'ai_os_use' => true ) ),
                4       => new WP_User( 4, array() ),
                default => new WP_User( $id, array() ),
        };

        foreach ( $persisted as $cap => $grant ) {
                $user->allcaps[ $cap ] = $grant;
        }
        return $user;
}

function wp_next_scheduled( string $hook ): int|false {
        return false;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
        return true;
}

function wp_clear_scheduled_hook( string $hook ): int {
        return 0;
}

function rest_url( string $path = '' ): string {
        return 'https://example.test/wp-json/' . $path;
}

function home_url( string $path = '' ): string {
        return 'https://example.test' . ( '' === $path ? '/' : $path );
}

function site_url( string $path = '' ): string {
        return 'https://example.test' . ( '' === $path ? '/' : $path );
}

function _get_cron_array(): array {
        return array(
                time() + 600 => array( 'my_hook' => array( 'abc' => array( 'schedule' => false, 'args' => array( 1, 2 ) ) ) ),
        );
}

function get_plugins(): array {
        return array(
                'hello/hello.php' => array( 'Name' => 'Hello', 'Version' => '1.0' ),
                'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce', 'Version' => '9.0' ),
        );
}

function current_user_can( string $cap ): bool {
        $user = wp_get_current_user();
        return $user->exists() && $user->has_cap( $cap );
}

/* ------------------------------------------------------------------ roles */

/**
 * Minimal WP_Role: enough for activation-time add_cap()/remove_cap()
 * calls to be observable in a test (BUG capability-usability). Not
 * wired into WP_User::has_cap(), which stays fixture-driven (explicit
 * caps arrays via TestCase::adminUser()/editorUser()/etc.) — real
 * per-user capability resolution through roles is exactly the kind of
 * complexity those fixtures exist to sidestep.
 */
class WP_Role {
        /** @var array<string, bool> */
        public array $capabilities;

        public function __construct( public string $name, array $capabilities = array() ) {
                $this->capabilities = $capabilities;
        }

        public function add_cap( string $cap, bool $grant = true ): void {
                $this->capabilities[ $cap ] = $grant;
        }

        public function remove_cap( string $cap ): void {
                unset( $this->capabilities[ $cap ] );
        }

        public function has_cap( string $cap ): bool {
                return ! empty( $this->capabilities[ $cap ] );
        }
}

function get_role( string $role ): ?WP_Role {
        if ( ! isset( $GLOBALS['__wp_shim']['roles'][ $role ] ) ) {
                // Seeded once per role on first lookup — close enough to
                // WordPress's own built-in role definitions for this
                // plugin's activation-time capability checks.
                $defaults = match ( $role ) {
                        'administrator' => array(
                                'manage_options' => true, 'edit_posts' => true, 'edit_pages' => true,
                                'edit_others_posts' => true, 'publish_posts' => true, 'upload_files' => true,
                                'delete_posts' => true, 'list_users' => true,
                        ),
                        'editor'      => array( 'edit_posts' => true, 'edit_pages' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'upload_files' => true ),
                        'author'      => array( 'edit_posts' => true, 'publish_posts' => true, 'upload_files' => true ),
                        'contributor' => array( 'edit_posts' => true ),
                        'subscriber'  => array(),
                        default       => array(),
                };
                $GLOBALS['__wp_shim']['roles'][ $role ] = new WP_Role( $role, $defaults );
        }
        return $GLOBALS['__wp_shim']['roles'][ $role ];
}

function wp_kses_post( string $content ): string {
        return strip_tags( $content, '<p><a><strong><em><br><ul><ol><li><h1><h2><h3><blockquote><code>' );
}

function check_admin_referer( string $action = '-1' ): bool {
        return true;
}

/* --------------------------------------------- dbDelta + WPDB in-memory */

/**
 * In-memory wpdb replacement backed by SQLite-less storage: records
 * schema, offers prepare() emulation, and stores rows for the tables
 * the repositories use. This is enough for repository-level tests.
 */
class wpdb {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public string $sitemeta = 'wp_sitemeta';
        public string $last_error = '';
        public int $insert_id = 0;
        public int $rows_affected = 0;

        /** @var array<string, array<int, array<string, mixed>>> */
        public array $tables = array();

        public function get_charset_collate(): string {
                return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function prepare( string $query, ...$args ): string|null {
                if ( array() === $args ) {
                        return $query;
                }
                // Very small subset: %d, %s, %f substitution.
                $pos = 0;
                $index = 0;
                $out = '';
                while ( false !== ( $pos = strpos( $query, '%', $pos ) ) ) {
                        $spec = $query[ $pos + 1 ] ?? '';
                        if ( ! in_array( $spec, array( 'd', 's', 'f' ), true ) ) {
                                $out .= $query[ $pos ];
                                $pos++;
                                continue;
                        }
                        $replacement = $args[ $index ] ?? '';
                        $out .= match ( $spec ) {
                                'd' => (string) (int) $replacement,
                                'f' => (string) (float) $replacement,
                                's' => "'" . addslashes( (string) $replacement ) . "'",
                        };
                        $index++;
                        $pos += 2;
                }
                // Rebuild: naive parse above loses untouched segments; do a
                // simpler, correct pass instead.
                $parts = preg_split( '/(%[dsf])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array();
                $out = '';
                $index = 0;
                foreach ( $parts as $part ) {
                        if ( preg_match( '/^%[dsf]$/', $part ) ) {
                                $replacement = $args[ $index ] ?? '';
                                $out .= match ( $part[1] ) {
                                        'd' => (string) (int) $replacement,
                                        'f' => (string) (float) $replacement,
                                        's' => "'" . addslashes( (string) $replacement ) . "'",
                                };
                                $index++;
                        } else {
                                $out .= $part;
                        }
                }
                return $out;
        }

        private function tableFor( string $sql ): ?string {
                if ( preg_match( '/from\s+`?wp_((?:\d+_)?[a-z_]+)`?/i', $sql, $m ) ) {
                        return $m[1];
                }
                if ( preg_match( '/insert\s+into\s+`?wp_((?:\d+_)?[a-z_]+)`?/i', $sql, $m ) ) {
                        return $m[1];
                }
                if ( preg_match( '/update\s+`?wp_((?:\d+_)?[a-z_]+)`?/i', $sql, $m ) ) {
                        return $m[1];
                }
                if ( preg_match( '/delete\s+from\s+`?wp_((?:\d+_)?[a-z_]+)`?/i', $sql, $m ) ) {
                        return $m[1];
                }
                return null;
        }

        public function query( string $sql ): int {
                // Atomic upsert for RateLimitRepository::hit() (spec
                // Sprint 0.3A-A): "INSERT ... VALUES (key, 1, reset_at)
                // ON DUPLICATE KEY UPDATE count = IF(reset_at <= now, 1,
                // count + 1), reset_at = IF(reset_at <= now, reset_at, reset_at)".
                // A targeted parser for exactly this shape, not a general
                // ON DUPLICATE KEY UPDATE engine — this shim is a small,
                // deliberately scoped test double (see the class docblock).
                if ( preg_match(
                        '/insert\s+into\s+`?wp_((?:\d+_)?[a-z_]+)`?\s*\(\s*rate_key\s*,\s*count\s*,\s*reset_at\s*\)\s*values\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*1\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)\s*on\s+duplicate\s+key\s+update.*?reset_at\s*<=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/is',
                        $sql,
                        $m
                ) ) {
                        $table        = $m[1];
                        $rate_key     = stripslashes( $m[2] );
                        $new_reset_at = stripslashes( $m[3] );
                        $now          = stripslashes( $m[4] );

                        $existing_index = null;
                        foreach ( $this->tables[ $table ] ?? array() as $i => $row ) {
                                if ( ( $row['rate_key'] ?? null ) === $rate_key ) {
                                        $existing_index = $i;
                                        break;
                                }
                        }

                        if ( null === $existing_index ) {
                                $row = array(
                                        'id'       => count( $this->tables[ $table ] ?? array() ) + 1,
                                        'rate_key' => $rate_key,
                                        'count'    => 1,
                                        'reset_at' => $new_reset_at,
                                );
                                $this->tables[ $table ][] = $row;
                                $this->insert_id = $row['id'];
                                $this->rows_affected = 1;
                                return 1;
                        }

                        $existing = $this->tables[ $table ][ $existing_index ];
                        if ( (string) ( $existing['reset_at'] ?? '' ) <= $now ) {
                                $this->tables[ $table ][ $existing_index ]['count']    = 1;
                                $this->tables[ $table ][ $existing_index ]['reset_at'] = $new_reset_at;
                        } else {
                                $this->tables[ $table ][ $existing_index ]['count'] = (int) ( $existing['count'] ?? 0 ) + 1;
                        }
                        $this->rows_affected = 1;
                        $this->insert_id     = 0;
                        return 1;
                }

                // INSERT parsing for our repository inserts.
                if ( preg_match( '/insert\s+into\s+`?wp_((?:\d+_)?[a-z_]+)`?\s*\(([^)]+)\)\s*values\s*\((.+)\)/is', $sql, $m ) ) {
                        $table = $m[1];
                        $columns = array_map( 'trim', explode( ',', $m[2] ) );
                        // Split values by commas outside quotes.
                        $values = array();
                        $buf = '';
                        $in_string = false;
                        foreach ( str_split( $m[3] ) as $char ) {
                                if ( "'" === $char ) {
                                        $in_string = ! $in_string;
                                        $buf .= $char;
                                        continue;
                                }
                                if ( ',' === $char && ! $in_string ) {
                                        $values[] = $buf;
                                        $buf = '';
                                        continue;
                                }
                                $buf .= $char;
                        }
                        $values[] = $buf;

                        $row = array();
                        foreach ( $columns as $i => $column ) {
                                $value = trim( $values[ $i ] ?? 'NULL' );
                                if ( 'NULL' === $value || '' === $value ) {
                                        $row[ $column ] = null;
                                } elseif ( str_starts_with( $value, "'" ) ) {
                                        $row[ $column ] = stripslashes( substr( $value, 1, -1 ) );
                                } else {
                                        $row[ $column ] = is_numeric( $value ) ? (int) $value : $value;
                                }
                        }
                        $row['id'] = count( $this->tables[ $table ] ?? array() ) + 1;
                        $this->tables[ $table ][] = $row;
                        $this->insert_id = $row['id'];
                        $this->rows_affected = 1;
                        return 1;
                }

                if ( preg_match( '/update\s+`?wp_((?:\d+_)?[a-z_]+)`?\s+set\s+(.+?)\s+where\s+(.+)$/is', $sql, $m ) ) {
                        $table = $m[1];
                        $sets = array();
                        foreach ( explode( ',', $m[2] ) as $assignment ) {
                                if ( preg_match( '/^`?([a-z_0-9]+)`?\s*=\s*(.+)$/i', trim( $assignment ), $am ) ) {
                                        $value = trim( $am[2] );
                                        if ( str_starts_with( $value, "'" ) ) {
                                                $value = stripslashes( substr( $value, 1, -1 ) );
                                        } elseif ( is_numeric( $value ) ) {
                                                $value = (int) $value;
                                        }
                                        $sets[ $am[1] ] = $value;
                                }
                        }
                        $updated = 0;
                        foreach ( $this->tables[ $table ] ?? array() as $index => $existing ) {
                                if ( $this->rowMatches( $existing, $m[3] ) ) {
                                        $this->tables[ $table ][ $index ] = array_merge( $existing, $sets );
                                        $updated++;
                                }
                        }
                        $this->rows_affected = $updated;
                        $this->insert_id = 0;
                        return $updated;
                }

                if ( preg_match( '/delete\s+from\s+`?wp_((?:\d+_)?[a-z_]+)`?\s+where\s+(.+)$/is', $sql, $m ) ) {
                        $table = $m[1];
                        $kept = array();
                        $deleted = 0;
                        foreach ( $this->tables[ $table ] ?? array() as $existing ) {
                                if ( $this->rowMatches( $existing, $m[2] ) ) {
                                        $deleted++;
                                } else {
                                        $kept[] = $existing;
                                }
                        }
                        $this->tables[ $table ] = $kept;
                        $this->rows_affected = $deleted;
                        return $deleted;
                }

                $this->rows_affected = 0;
                $this->insert_id = 0;
                return 0;
        }

        /**
         * Ultra-small WHERE matcher: supports `col = 'val'`, `col = 1`,
         * AND chains, comparisons (<, <=, >, >=), `col IS NULL`, and the
         * tautology `1=1`.
         */
        private function rowMatches( array $row, string $where ): bool {
                foreach ( preg_split( '/\s+and\s+/i', $where ) as $clause ) {
                        $clause = trim( $clause );

                        // `1=1` tautology and empty clauses always match.
                        if ( '' === $clause || preg_match( '/^\d+\s*=\s*\d+$/', $clause ) ) {
                                continue;
                        }

                        // col IS NULL: missing columns count as null
                        // (the shim stores sparse rows).
                        if ( preg_match( '/^`?([a-z_0-9]+)`?\s+is\s+null$/i', $clause, $cm ) ) {
                                if ( array_key_exists( $cm[1], $row ) && null !== $row[ $cm[1] ] ) {
                                        return false;
                                }
                                continue;
                        }

                        // Function conditions like SUM(...) are out of scope: match.
                        if ( preg_match( '/^[a-z_]+\(/i', $clause ) ) {
                                continue;
                        }

                        if ( preg_match( '/^`?([a-z_0-9]+)`?\s*(>=|<=|!=|=|>|<)\s*(.+)$/i', $clause, $cm ) ) {
                                $column   = $cm[1];
                                $operator = $cm[2];
                                $value    = trim( $cm[3] );
                                if ( str_starts_with( $value, "'" ) ) {
                                        // Quoted string: stop at the closing quote
                                        // so trailing LIMIT clauses cannot bleed in.
                                        $end   = strpos( $value, "'", 1 );
                                        $value = stripslashes( false !== $end ? substr( $value, 1, $end - 1 ) : substr( $value, 1 ) );
                                } elseif ( is_numeric( $value ) ) {
                                        $value = (int) $value;
                                } else {
                                        // Unquoted token: stop at first whitespace
                                        // (e.g. "LIMIT 1").
                                        $value = explode( ' ', $value )[0];
                                }
                                $actual = $row[ $column ] ?? null;
                                if ( null === $actual ) {
                                        return false;
                                }
                                $matches = match ( $operator ) {
                                        '=' => (string) $actual === (string) $value,
                                        '!=' => (string) $actual !== (string) $value,
                                        '<', '<=', '>', '>=' => version_compare( (string) $actual, (string) $value, $operator ),
                                        default => false,
                                };
                                if ( ! $matches ) {
                                        return false;
                                }
                        }
                }
                return true;
        }

        public function get_results( string $sql, string $output = ARRAY_A ): array|null {
                // Aggregate SELECTs (COUNT/SUM/AVG/COALESCE with aliases).
                $aggregated = $this->aggregate( $sql );
                if ( null !== $aggregated ) {
                        return array( $aggregated );
                }

                // WHERE-less recent-listing (ORDER BY id DESC LIMIT n).
                if ( preg_match( '/select\s+\*\s+from\s+`?wp_((?:\d+_)?[a-z_]+)`?\s+order\s+by\s+id\s+desc\s+limit\s+(\d+)/i', $sql, $m ) ) {
                        $rows = array_slice( array_reverse( $this->tables[ $m[1] ] ?? array() ), 0, (int) $m[2] );
                        return $rows;
                }

                if ( preg_match( '/select\s+(?:\*|id)\s+from\s+`?wp_((?:\d+_)?[a-z_]+)`?\s+where\s+(.+?)(?:\s+order\s+by\s+(.+))?$/is', $sql, $m ) ) {
                        $table = $m[1];
                        $rows = array_values( array_filter( $this->tables[ $table ] ?? array(), fn( array $r ): bool => $this->rowMatches( $r, $m[2] ) ) );
                        if ( isset( $m[3] ) ) {
                                // ORDER BY id DESC default handling.
                                if ( preg_match( '/id\s+desc/i', $m[3] ) ) {
                                        usort( $rows, fn( $a, $b ): int => (int) $b['id'] <=> (int) $a['id'] );
                                }
                        }
                        if ( preg_match( '/limit\s+(\d+)\s+offset\s+(\d+)/i', $sql, $lm ) ) {
                                $rows = array_slice( $rows, (int) $lm[2], (int) $lm[1] );
                        }
                        return $rows;
                }
                return array();
        }

        /**
         * Evaluate a small aggregate SELECT into a single result row:
         *   SELECT COUNT(*) AS a, SUM(1 - col) AS b, AVG(col) AS c,
         *          SUM(col = 'v') AS d, COALESCE(AVG(col), 0) AS e
         *   FROM wp_x [WHERE ...]
         * Returns null when the SQL is not an aggregate.
         *
         * @return array<string, mixed>|null
         */
        private function aggregate( string $sql ): ?array {
                if ( ! preg_match( '/^select\s+(count|sum|avg|coalesce)\s*\(/i', trim( $sql ) ) ) {
                        return null;
                }
                if ( ! preg_match( '/^select\s+(.+?)\s+from\s+`?wp_((?:\d+_)?[a-z_]+)`?(?:\s+where\s+(.+))?$/is', trim( $sql ), $m ) ) {
                        return null;
                }

                $select = $m[1];
                $table  = $m[2];
                $where  = $m[3] ?? '1=1';
                $rows   = array_values( array_filter( $this->tables[ $table ] ?? array(), fn( array $r ): bool => $this->rowMatches( $r, $where ) ) );

                // Split the select list on top-level commas.
                $parts = array();
                $buffer = '';
                $depth = 0;
                foreach ( str_split( $select ) as $char ) {
                        if ( '(' === $char ) { $depth++; }
                        if ( ')' === $char ) { $depth--; }
                        if ( ',' === $char && 0 === $depth ) {
                                $parts[] = trim( $buffer );
                                $buffer = '';
                                continue;
                        }
                        $buffer .= $char;
                }
                $parts[] = trim( $buffer );

                $out = array();
                foreach ( $parts as $part ) {
                        if ( ! preg_match( '/^(.+?)\s+as\s+([a-z_0-9]+)$/i', $part, $am ) ) {
                                continue;
                        }
                        $out[ $am[2] ] = $this->evalAggregate( $am[1], $rows );
                }
                return $out;
        }

        /**
         * @param array<int, array<string, mixed>> $rows
         */
        private function evalAggregate( string $expr, array $rows ): int|float {
                $expr = trim( $expr );

                if ( preg_match( '/^count\(\*\)$/i', $expr ) ) {
                        return count( $rows );
                }
                if ( preg_match( '/^coalesce\(\s*avg\(\s*([a-z_0-9]+)\s*\)\s*,\s*0\s*\)$/i', $expr, $m ) ) {
                        return $this->evalAggregate( 'AVG(' . $m[1] . ')', $rows );
                }
                if ( preg_match( '/^avg\(\s*([a-z_0-9]+)\s*\)$/i', $expr, $m ) ) {
                        $column = $m[1];
                        $values = array_filter( array_column( $rows, $column ), 'is_numeric' );
                        return $values === array() ? 0 : array_sum( $values ) / count( $values );
                }
                if ( preg_match( '/^sum\(\s*1\s*-\s*([a-z_0-9]+)\s*\)$/i', $expr, $m ) ) {
                        $column = $m[1];
                        $count = 0;
                        foreach ( $rows as $row ) {
                                if ( isset( $row[ $column ] ) && 0 === (int) $row[ $column ] ) {
                                        $count++;
                                }
                        }
                        return $count;
                }
                if ( preg_match( '/^sum\(\s*([a-z_0-9]+)\s*=\s*[\'"]([^\'"]*)[\'"]\s*\)$/i', $expr, $m ) ) {
                        $column = $m[1];
                        $value  = $m[2];
                        $count  = 0;
                        foreach ( $rows as $row ) {
                                if ( ( $row[ $column ] ?? null ) === $value ) {
                                        $count++;
                                }
                        }
                        return $count;
                }
                return 0;
        }

        public function get_row( string $sql, string $output = ARRAY_A ): array|null {
                $results = $this->get_results( $sql, $output );
                return $results[0] ?? null;
        }

        /**
         * uninstall.php's rate-limiter transient scan (spec BUG-005) is
         * the only caller: "SELECT option_name FROM {$wpdb->options}
         * WHERE option_name LIKE '_transient_ai_os_rl_%'" (or the
         * sitemeta/_site_transient_ equivalent). The shim keeps
         * transients in their own per-blog store rather than modeling
         * wp_options as literal rows (see the class docblock), so this
         * translates the LIKE-pattern scan into a scan over that store
         * instead of real SQL — enough to verify uninstall.php's cleanup
         * logic finds exactly the keys it should, without building a
         * general-purpose SQL engine for a single, narrow call site.
         *
         * @return string[]
         */
        public function get_col( string $sql ): array {
                $blog_id = $GLOBALS['__wp_shim']['current_blog_id'] ?? 1;

                if ( preg_match( "/option_name\\s+like\\s+'_transient_(.*?)%?'/i", $sql, $m ) ) {
                        $matches = array();
                        foreach ( array_keys( $GLOBALS['__wp_shim']['transients'][ $blog_id ] ?? array() ) as $key ) {
                                if ( str_starts_with( $key, $m[1] ) ) {
                                        $matches[] = '_transient_' . $key;
                                }
                        }
                        return $matches;
                }

                if ( preg_match( "/meta_key\\s+like\\s+'_site_transient_(.*?)%?'/i", $sql, $m ) ) {
                        $matches = array();
                        // Site-wide ("network") transients are modeled with
                        // a "site:" key prefix regardless of current blog —
                        // see get_site_option()/delete_site_transient().
                        foreach ( array_keys( $GLOBALS['__wp_shim']['transients'][1] ?? array() ) as $key ) {
                                if ( str_starts_with( $key, 'site:' . $m[1] ) ) {
                                        $matches[] = '_site_transient_' . substr( $key, strlen( 'site:' ) );
                                }
                        }
                        return $matches;
                }

                return array();
        }

        public function get_var( string $sql ): mixed {
                if ( preg_match( '/select\s+count\(\*\)\s+from\s+`?wp_((?:\d+_)?[a-z_]+)`?\s+where\s+(.+)$/is', $sql, $m ) ) {
                        $rows = array_filter( $this->tables[ $m[1] ] ?? array(), fn( array $r ): bool => $this->rowMatches( $r, $m[2] ) );
                        return (string) count( $rows );
                }
                if ( preg_match( '/select\s+count\(\*\)\s+from\s+`?wp_((?:\d+_)?[a-z_]+)`?$/i', $sql, $m ) ) {
                        return (string) count( $this->tables[ $m[1] ] ?? array() );
                }
                // Aggregates (SUM/AVG) return "0" — sufficient for tests.
                if ( preg_match( '/select\s+(sum|avg|coalesce)\(/i', $sql ) ) {
                        return '0';
                }
                return null;
        }

        public function insert( string $table, array $data ): int|false {
                $columns = array_map( static fn( string $c ): string => trim( $c, '`' ), array_keys( $data ) );
                $values  = array_map(
                        static function ( mixed $v ): string {
                                if ( null === $v ) {
                                        return 'NULL';
                                }
                                if ( is_int( $v ) || is_float( $v ) ) {
                                        return (string) $v;
                                }
                                return "'" . addslashes( (string) $v ) . "'";
                        },
                        array_values( $data )
                );
                $sql    = 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')';
                $result = $this->query( $sql );
                return 1 === $result ? $this->insert_id : false;
        }

        public function update( string $table, array $data, array $where ): int|false {
                $sets = array();
                foreach ( $data as $column => $value ) {
                        $sets[] = trim( $column, '`' ) . ' = ' . ( null === $value ? 'NULL' : ( is_numeric( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'" ) );
                }
                $conditions = array();
                foreach ( $where as $column => $value ) {
                        $conditions[] = trim( $column, '`' ) . ' = ' . ( is_numeric( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'" );
                }
                $sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $conditions );
                return $this->query( $sql );
        }

        public function esc_like( string $text ): string {
                return addcslashes( $text, '_%\\' );
        }

        public function db_version(): string {
                return '10.11.8-MariaDB';
        }
}

$GLOBALS['wpdb'] = new wpdb();

function dbDelta( array|string $queries = array() ): array {
        // In tests, CREATE TABLE statements succeed silently; the wpdb
        // shim pre-creates table storage on demand. The table name IS
        // logged (BUG-005) so multisite provisioning tests can assert
        // exactly which site-prefixed tables a migration run touched,
        // without needing the wpdb SQL-regex engine to fully understand
        // multisite prefixes.
        foreach ( is_array( $queries ) ? $queries : array( $queries ) as $sql ) {
                if ( preg_match( '/create\s+table\s+`?([a-zA-Z0-9_]+)`?/i', (string) $sql, $m ) ) {
                        $GLOBALS['__wp_shim']['created_tables'][] = $m[1];
                }
        }
        return array();
}

// Additional helpers referenced by tool catalog.
function wp_upload_bits( string $name, ?array $type, string $content ): array {
        $dir = sys_get_temp_dir() . '/aios-uploads';
        if ( ! is_dir( $dir ) ) {
                mkdir( $dir, 0777, true );
        }
        $filename = $dir . '/' . $name;
        file_put_contents( $filename, $content );
        return array( 'file' => $filename, 'url' => 'https://example.test/wp-content/uploads/' . $name, 'error' => false );
}

function wp_check_filetype( string $file ): array {
        $extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        $map = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf', 'svg' => 'image/svg+xml' );
        return array( 'ext' => $extension, 'type' => $map[ $extension ] ?? 'application/octet-stream' );
}

function wp_insert_attachment( array $postarr, string $file = '' ): int|WP_Error {
        $postarr['post_type'] = 'attachment';
        return wp_insert_post( $postarr );
}

function wp_update_attachment_metadata( int $id, mixed $data ): bool {
        return true;
}

function wp_create_image_subsizes( string $file ): array {
        return array();
}

function wp_delete_attachment( int $id, bool $force = false ): bool|WP_Post {
        $deleted = get_post( $id );
        if ( ! $deleted ) {
                return false;
        }
        unset( $GLOBALS['__wp_shim']['posts'][ $id ] );
        return $deleted;
}

function wp_delete_file( string $file ): void {
        if ( file_exists( $file ) ) {
                unlink( $file );
        }
}

function wp_get_attachment_url( int $id ): string|false {
        return isset( $GLOBALS['__wp_shim']['posts'][ $id ] ) ? 'https://example.test/wp-content/uploads/' . $id . '.jpg' : false;
}

function wp_get_attachment_metadata( int $id ): mixed {
        return array( 'width' => 100, 'height' => 80, 'filesize' => 2048 );
}

function sanitize_header( string $value ): string {
        return $value;
}

// Gutenberg/block helpers.
function use_block_editor_for_post_type( string $type ): bool {
        return true;
}

// wp-admin includes may be required by plugin code — provide guards.
if ( ! function_exists( 'get_plugins' ) ) {
        // Already defined above.
}

/* ------------------------------------------------------- WP_Filesystem shim */

function WP_Filesystem( ?array $args = array() ): bool {
        return true;
}

class WP_Filesystem_Base {
        public function exists( string $path ): bool {
                return file_exists( $path );
        }

        public function is_readable( string $path ): bool {
                return is_readable( $path );
        }

        public function get_contents( string $path ): string|false {
                return file_get_contents( $path ); // phpcs:ignore
        }
}

$GLOBALS['wp_filesystem'] = new WP_Filesystem_Base();

/* ---------------------------------------------------- REST shim (minimal) */

class WP_REST_Request {
        /** @var array<string, mixed> */
        private array $params = array();

        public function __construct( array $params = array() ) {
                $this->params = $params;
        }

        public function get_param( string $key ): mixed {
                return $this->params[ $key ] ?? null;
        }

        public function set_param( string $key, mixed $value ): void {
                $this->params[ $key ] = $value;
        }

        public function get_body(): string {
                return (string) ( $this->params['__body'] ?? '' );
        }

        public function get_header( string $name ): ?string {
                return $this->params[ '__header_' . strtolower( $name ) ] ?? null;
        }
}

class WP_REST_Response {
        /** @var array<string, mixed> */
        private array $headers = array();

        public function __construct( public mixed $data = null, public int $status = 200 ) {}

        public function header( string $key, string $value ): void {
                $this->headers[ $key ] = $value;
        }

        public function get_headers(): array {
                return $this->headers;
        }
}

function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
        $GLOBALS['__wp_shim']['routes'][] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
        return true;
}

function add_menu_page( ...$args ): string { return 'ai-page'; }
function add_submenu_page( ...$args ): string|false { return 'ai-sub'; }
function wp_enqueue_style( ...$args ): void {}
function wp_enqueue_script( ...$args ): void {}
function wp_localize_script( ...$args ): bool { return true; }
function wp_create_nonce_fallback(): string { return 'x'; }
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . $path; }
function get_current_screen(): ?object { return null; }
function wp_safe_redirect( string $location ): void {}
