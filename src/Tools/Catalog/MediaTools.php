<?php
/**
 * Media tools (spec §4).
 *
 * Phase 1 scope: media.upload accepts base64 content only — NOT URLs.
 * Fetching remote URLs would create an SSRF surface; the URL-sideload
 * path is deliberately deferred to Phase 4 with an outbound-fetch
 * allowlist + IP-range validation.
 *
 * @package AIOS\Tools\Catalog
 */

declare( strict_types=1 );

namespace AIOS\Tools\Catalog;

use AIOS\Abilities\Ability;
use AIOS\Abilities\AbilityResult;
use AIOS\Abilities\AbilityRegistry;
use AIOS\Tools\Tool;
use AIOS\Tools\ToolRegistry;
use WP_Error;

final class MediaTools implements CatalogProviderInterface {

        public static function id(): string {
                return 'media';
        }

        public static function isActive(): bool {
                return function_exists( 'wp_upload_bits' );
        }

        public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
                self::registerList( $abilities, $tools );
                self::registerGet( $abilities, $tools );
                self::registerUpload( $abilities, $tools );
                self::registerUpdate( $abilities, $tools );
                self::registerDelete( $abilities, $tools );
        }

        // --------------------------------------------------------------- media.list

        private static function registerList( AbilityRegistry $abilities, ToolRegistry $tools ): void {
                $abilities->register( Ability::make(
                        array(
                                'name'        => 'media.list',
                                'description' => 'List media library items with mime-type filter, search and pagination.',
                                'inputSchema' => array(
                                        'type'       => 'object',
                                        'properties' => array(
                                                'mime_type' => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Filter by mime type, e.g. image/jpeg. Use image for all images.' ),
                                                'search'    => array( 'type' => 'string', 'maxLength' => 200 ),
                                                'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
                                                'page'      => array( 'type' => 'integer', 'minimum' => 1 ),
                                        ),
                                        'additionalProperties' => false,
                                ),
                                'level'              => 0,
                                'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'upload_files' ),
                                'executeCallback'    => static function ( array $args, $user ): AbilityResult {
                                        $query_args = array(
                                                'post_type'      => 'attachment',
                                                'post_status'    => 'inherit',
                                                'posts_per_page' => (int) ( $args['per_page'] ?? 20 ),
                                                'paged'          => (int) ( $args['page'] ?? 1 ),
                                                'orderby'        => 'date',
                                                'order'          => 'DESC',
                                        );
                                        if ( ! empty( $args['mime_type'] ) ) {
                                                $mime = sanitize_mime_type( (string) $args['mime_type'] );
                                                if ( 'image' === $mime ) {
                                                        $query_args['post_mime_type'] = 'image';
                                                } elseif ( false !== $mime ) {
                                                        $query_args['post_mime_type'] = $mime;
                                                }
                                        }
                                        if ( ! empty( $args['search'] ) ) {
                                                $query_args['s'] = sanitize_text_field( (string) $args['search'] );
                                        }

                                        $query = new \WP_Query( $query_args );
                                        $items = array();
                                        foreach ( $query->posts as $attachment ) {
                                                $items[] = self::summary( $attachment );
                                        }

                                        return AbilityResult::success(
                                                array(
                                                        'items'    => $items,
                                                        'total'    => (int) $query->found_posts,
                                                        'pages'    => (int) $query->max_num_pages,
                                                        'per_page' => $query_args['posts_per_page'],
                                                )
                                        );
                                },
                        )
                ) );

                $tools->register( Tool::make(
                        array(
                                'name'            => 'media.list',
                                'description'     => 'List media library items with mime filter, search and pagination. Read-only.',
                                'category'        => 'media',
                                'inputSchema'     => array(
                                        'type'       => 'object',
                                        'properties' => array(
                                                'mime_type' => array( 'type' => 'string', 'maxLength' => 100 ),
                                                'search'    => array( 'type' => 'string', 'maxLength' => 200 ),
                                                'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
                                                'page'      => array( 'type' => 'integer', 'minimum' => 1 ),
                                        ),
                                        'additionalProperties' => false,
                                ),
                                'riskLevel'       => 0,
                                'permissionLevel' => 0,
                                'confirmation'    => 'never',
                        )
                ) );
        }

        // ---------------------------------------------------------------- media.get

        private static function registerGet( AbilityRegistry $abilities, ToolRegistry $tools ): void {
                $abilities->register( Ability::make(
                        array(
                                'name'        => 'media.get',
                                'description' => 'Get one media item: URL, mime type, dimensions, alt text, title, caption.',
                                'inputSchema' => array(
                                        'type'       => 'object',
                                        'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
                                        'required'   => array( 'id' ),
                                        'additionalProperties' => false,
                                ),
                                'level'              => 0,
                                'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'upload_files' ),
                                'executeCallback'    => static function ( array $args, $user ): AbilityResult {
                                        $attachment = get_post( (int) ( $args['id'] ?? 0 ) );
                                        if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
                                                return AbilityResult::error( 'media.not_found', 'No media item found with that id.', 'not_found' );
                                        }
                                        $result = AbilityResult::success( self::detail( $attachment ) );
                                        $result->affected( 'attachment', $attachment->ID );
                                        return $result;
                                },
                        )
                ) );

                $tools->register( Tool::make(
                        array(
                                'name'            => 'media.get',
                                'description'     => 'Get one media item: URL, dimensions, alt text, metadata. Read-only.',
                                'category'        => 'media',
                                'inputSchema'     => array(
                                        'type'       => 'object',
                                        'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
                                        'required'   => array( 'id' ),
                                        'additionalProperties' => false,
                                ),
                                'riskLevel'       => 0,
                                'permissionLevel' => 0,
                                'confirmation'    => 'never',
                        )
                ) );
        }

        // ------------------------------------------------------------- media.upload

        private static function registerUpload( AbilityRegistry $abilities, ToolRegistry $tools ): void {
                $abilities->register( Ability::make(
                        array(
                                'name'        => 'media.upload',
                                'description' => 'Upload a file to the media library from base64-encoded content. Returns the attachment id and URL.',
                                'inputSchema' => array(
                                        'type'       => 'object',
                                        'properties' => array(
                                                'filename' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'File name including extension, e.g. hero.jpg' ),
                                                'content'  => array( 'type' => 'string', 'description' => 'Base64-encoded file content (no data: prefix).' ),
                                                'title'    => array( 'type' => 'string', 'maxLength' => 300 ),
                                                'alt'      => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'Alt text for images (recommended).' ),
                                        ),
                                        'required'   => array( 'filename', 'content' ),
                                        'additionalProperties' => false,
                                ),
                                'level'              => 1,
                                'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'upload_files' ),
                                'executeCallback'    => static function ( array $args, $user ): AbilityResult {
                                        $filename = sanitize_file_name( (string) ( $args['filename'] ?? '' ) );
                                        if ( '' === $filename || '.' === $filename ) {
                                                return AbilityResult::error( 'media.invalid_filename', 'A valid filename is required.', 'validation' );
                                        }

                                        $raw = base64_decode( (string) ( $args['content'] ?? '' ), true );
                                        if ( false === $raw || '' === $raw ) {
                                                return AbilityResult::error( 'media.invalid_content', 'content must be valid base64-encoded file bytes.', 'validation' );
                                        }
                                        if ( strlen( $raw ) > 20 * 1024 * 1024 ) {
                                                return AbilityResult::error( 'media.too_large', 'File exceeds the 20MB AI OS upload limit.', 'validation' );
                                        }

                                        // Hard extension allowlist (WP checks content too).
                                        $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
                                        $allowed   = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'pdf', 'mp3', 'mp4', 'txt', 'csv', 'zip' );
                                        if ( ! in_array( $extension, $allowed, true ) ) {
                                                return AbilityResult::error( 'media.extension_not_allowed', "Extension .{$extension} is not in the allowed upload list.", 'validation' );
                                        }

                                        $upload = wp_upload_bits( $filename, null, $raw );
                                        if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
                                                return AbilityResult::error( 'media.upload_failed', 'WordPress rejected the upload.', 'execution' );
                                        }

                                        $filetype   = wp_check_filetype( $upload['file'] );
                                        $post_title = sanitize_text_field( (string) ( $args['title'] ?? pathinfo( $filename, PATHINFO_FILENAME ) ) );

                                        $attachment_id = wp_insert_attachment(
                                                array(
                                                        'post_mime_type' => $filetype['type'] ?: 'application/octet-stream',
                                                        'post_title'     => $post_title,
                                                        'post_content'   => '',
                                                        'post_status'    => 'inherit',
                                                ),
                                                $upload['file'],
                                                0,
                                                true
                                        );
                                        if ( is_wp_error( $attachment_id ) ) {
                                                wp_delete_file( $upload['file'] );
                                                return AbilityResult::error( 'media.attach_failed', $attachment_id->get_error_message(), 'execution' );
                                        }

                                        // Metadata + alt text (wp-admin includes guarded for
                                        // headless/test contexts).
                                        $image_admin = ABSPATH . 'wp-admin/includes/image.php';
                                        if ( is_file( $image_admin ) && ! function_exists( 'wp_create_image_subsizes' ) ) {
                                                require_once $image_admin;
                                        }
                                        if ( function_exists( 'wp_create_image_subsizes' ) ) {
                                                wp_update_attachment_metadata( $attachment_id, wp_create_image_subsizes( $upload['file'], $attachment_id ) );
                                        }

                                        if ( ! empty( $args['alt'] ) ) {
                                                update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
                                        }

                                        $attachment = get_post( $attachment_id );
                                        $result = AbilityResult::success(
                                                array(
                                                        'id'  => (int) $attachment_id,
                                                        'url' => (string) ( $upload['url'] ?? '' ),
                                                        'mime' => $filetype['type'],
                                                )
                                        );
                                        $result->affected( 'attachment', $attachment_id );
                                        $result->note( sprintf( 'Upload media file "%s" (%d bytes).', $filename, strlen( $raw ) ) );
                                        return $result;
                                },
                        )
                ) );

                $tools->register( Tool::make(
                        array(
                                'name'            => 'media.upload',
                                'description'     => 'Upload a file to the media library from base64 content (URL fetching is intentionally not supported yet). Level 1.',
                                'category'        => 'media',
                                'inputSchema'     => array(
                                        'type'       => 'object',
                                        'properties' => array(
                                                'filename' => array( 'type' => 'string', 'maxLength' => 200 ),
                                                'content'  => array( 'type' => 'string' ),
                                                'title'    => array( 'type' => 'string', 'maxLength' => 300 ),
                                                'alt'      => array( 'type' => 'string', 'maxLength' => 500 ),
                                        ),
                                        'required'   => array( 'filename', 'content' ),
                                        'additionalProperties' => false,
                                ),
                                'riskLevel'       => 1,
                                'permissionLevel' => 1,
                                'confirmation'    => 'never',
                        )
                ) );
        }

        // ------------------------------------------------------------- media.update

        private static function registerUpdate( AbilityRegistry $abilities, ToolRegistry $tools ): void {
                $abilities->register( Ability::make(
                        array(
                                'name'        => 'media.update',
                                'description' => 'Update media item metadata: title, caption, description, alt text.',
                                'inputSchema' => array(
                                        'type'       => 'object',
                                        'properties' => array(
                                                'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
                                                'title'       => array( 'type' => 'string', 'maxLength' => 300 ),
                                                'caption'     => array( 'type' => 'string', 'maxLength' => 2000 ),
                                                'description' => array( 'type' => 'string', 'maxLength' => 5000 ),
                                                'alt'         => array( 'type' => 'string', 'maxLength' => 500 ),
                                        ),
                                        'required'   => array( 'id' ),
                                        'additionalProperties' => false,
                                ),
                                'level'              => 1,
                                'permissionCallback' => static function ( $user, array $args ): bool {
                                        $post = get_post( (int) ( $args['id'] ?? 0 ) );
                                        return $post instanceof \WP_Post && $user->has_cap( 'edit_post', $post->ID );
                                },
                                'executeCallback'    => static function ( array $args, $user ): AbilityResult {
                                        $attachment = get_post( (int) ( $args['id'] ?? 0 ) );
                                        if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
                                                return AbilityResult::error( 'media.not_found', 'No media item found with that id.', 'not_found' );
                                        }

                                        $postarr = array( 'ID' => $attachment->ID );
                                        if ( array_key_exists( 'title', $args ) ) {
                                                $postarr['post_title'] = sanitize_text_field( (string) $args['title'] );
                                        }
                                        if ( array_key_exists( 'caption', $args ) ) {
                                                $postarr['post_excerpt'] = wp_kses_post( (string) $args['caption'] );
                                        }
                                        if ( array_key_exists( 'description', $args ) ) {
                                                $postarr['post_content'] = wp_kses_post( (string) $args['description'] );
                                        }

                                        $updated = wp_update_post( $postarr, true );
                                        if ( $updated instanceof WP_Error ) {
                                                return AbilityResult::error( 'media.update_failed', $updated->get_error_message(), 'execution' );
                                        }

                                        if ( array_key_exists( 'alt', $args ) ) {
                                                update_post_meta( $attachment->ID, '_wp_attachment_image_alt', sanitize_text_field( (string) $args['alt'] ) );
                                        }

                                        $result = AbilityResult::success( array( 'id' => $attachment->ID, 'updated' => true ) );
                                        $result->affected( 'attachment', $attachment->ID );
                                        $result->note( sprintf( 'Update media metadata for attachment #%d.', $attachment->ID ) );
                                        return $result;
                                },
                        )
                ) );

                $tools->register( Tool::make(
                        array(
                                'name'            => 'media.update',
                                'description'     => 'Update media metadata (title, caption, description, alt text). Level 1.',
                                'category'        => 'media',
                                'inputSchema'     => array(
                                        'type'       => 'object',
                                        'properties' => array(
                                                'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
                                                'title'       => array( 'type' => 'string', 'maxLength' => 300 ),
                                                'caption'     => array( 'type' => 'string', 'maxLength' => 2000 ),
                                                'description' => array( 'type' => 'string', 'maxLength' => 5000 ),
                                                'alt'         => array( 'type' => 'string', 'maxLength' => 500 ),
                                        ),
                                        'required'   => array( 'id' ),
                                        'additionalProperties' => false,
                                ),
                                'riskLevel'       => 1,
                                'permissionLevel' => 1,
                                'confirmation'    => 'never',
                        )
                ) );
        }

        // ------------------------------------------------------------- media.delete

        private static function registerDelete( AbilityRegistry $abilities, ToolRegistry $tools ): void {
                $abilities->register( Ability::make(
                        array(
                                'name'        => 'media.delete',
                                'description' => 'Permanently delete a media item and its files. Level 3 (destructive).',
                                'inputSchema' => array(
                                        'type'       => 'object',
                                        'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
                                        'required'   => array( 'id' ),
                                        'additionalProperties' => false,
                                ),
                                'level'              => 3,
                                'permissionCallback' => static function ( $user, array $args ): bool {
                                        $post = get_post( (int) ( $args['id'] ?? 0 ) );
                                        return $post instanceof \WP_Post && $user->has_cap( 'delete_post', $post->ID );
                                },
                                'executeCallback'    => static function ( array $args, $user ): AbilityResult {
                                        $attachment = get_post( (int) ( $args['id'] ?? 0 ) );
                                        if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
                                                return AbilityResult::error( 'media.not_found', 'No media item found with that id.', 'not_found' );
                                        }

                                        $deleted = wp_delete_attachment( $attachment->ID, true );
                                        if ( false === $deleted ) {
                                                return AbilityResult::error( 'media.delete_failed', 'Could not delete the media item.', 'execution' );
                                        }

                                        $result = AbilityResult::success( array( 'id' => $attachment->ID, 'deleted' => true ) );
                                        $result->affected( 'attachment', $attachment->ID );
                                        $result->note( sprintf( 'Permanently delete attachment #%d.', $attachment->ID ) );
                                        return $result;
                                },
                        )
                ) );

                $tools->register( Tool::make(
                        array(
                                'name'            => 'media.delete',
                                'description'     => 'Permanently delete a media item and its files (destructive, approval required).',
                                'category'        => 'media',
                                'inputSchema'     => array(
                                        'type'       => 'object',
                                        'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
                                        'required'   => array( 'id' ),
                                        'additionalProperties' => false,
                                ),
                                'riskLevel'       => 3,
                                'permissionLevel' => 3,
                                'confirmation'    => 'approval',
                        )
                ) );
        }

        // ---------------------------------------------------------------- helpers

        /**
         * @return array<string, mixed>
         */
        private static function summary( \WP_Post $attachment ): array {
                $meta = wp_get_attachment_metadata( $attachment->ID );

                return array(
                        'id'    => (int) $attachment->ID,
                        'title' => get_the_title( $attachment ),
                        'url'   => wp_get_attachment_url( $attachment->ID ) ?: null,
                        'mime'  => $attachment->post_mime_type,
                        'date'  => $attachment->post_date_gmt,
                        'alt'   => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
                        'size'  => is_array( $meta ) && isset( $meta['filesize'] ) ? (int) $meta['filesize'] : null,
                        'dimensions' => is_array( $meta ) && isset( $meta['width'], $meta['height'] )
                                ? array( (int) $meta['width'], (int) $meta['height'] ) : null,
                );
        }

        /**
         * @return array<string, mixed>
         */
        private static function detail( \WP_Post $attachment ): array {
                $payload = self::summary( $attachment );
                $payload['caption']     = (string) $attachment->post_excerpt;
                $payload['description'] = (string) $attachment->post_content;
                $payload['link']        = get_permalink( $attachment ) ?: null;
                return $payload;
        }
}
