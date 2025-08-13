<?php

namespace Crumbls\Importer\Services\WordPress;

use Illuminate\Support\Str;

class WordPressColumnMapper
{
    /**
     * Map WordPress post fields to Laravel column names
     */
    public function mapPostFieldToColumn(string $wpField): string
    {
        $mappings = [
            'post_id' => 'id',
            'post_title' => 'title',
            'post_content' => 'content',
            'post_excerpt' => 'excerpt',
            'post_status' => 'status',
            'post_name' => 'slug',
            'post_date' => 'published_at',
            'post_date_gmt' => 'published_at_gmt',
            'post_modified' => 'modified_at',
            'post_modified_gmt' => 'modified_at_gmt',
            'post_author' => 'author_id',
            'post_parent' => 'parent_id',
            'menu_order' => 'menu_order',
            'guid' => 'guid',
            'comment_status' => 'comment_status',
            'ping_status' => 'ping_status',
            'comment_count' => 'comment_count',
            'post_mime_type' => 'mime_type',
            'post_password' => 'password',
            'post_content_filtered' => 'content_filtered',
            'to_ping' => 'to_ping',
            'pinged' => 'pinged',
        ];

        return $mappings[$wpField] ?? Str::snake($wpField);
    }

    /**
     * Map WordPress meta keys to Laravel column names
     */
    public function mapMetaKeyToColumn(string $metaKey): string
    {
        $mappings = [
            '_thumbnail_id' => 'featured_image_id',
            '_wp_attached_file' => 'file_path',
            '_wp_attachment_metadata' => 'metadata',
            '_wp_attachment_image_alt' => 'alt_text',
            '_edit_last' => 'last_editor_id',
            '_wp_page_template' => 'page_template',
            
            // WooCommerce
            '_sku' => 'sku',
            '_price' => 'price',
            '_regular_price' => 'regular_price',
            '_sale_price' => 'sale_price',
            '_sale_price_dates_from' => 'sale_start_date',
            '_sale_price_dates_to' => 'sale_end_date',
            '_tax_status' => 'tax_status',
            '_tax_class' => 'tax_class',
            '_manage_stock' => 'manage_stock',
            '_stock' => 'stock_quantity',
            '_stock_status' => 'stock_status',
            '_backorders' => 'backorders',
            '_low_stock_amount' => 'low_stock_threshold',
            '_sold_individually' => 'sold_individually',
            '_weight' => 'weight',
            '_length' => 'length',
            '_width' => 'width',
            '_height' => 'height',
            '_upsell_ids' => 'upsell_ids',
            '_crosssell_ids' => 'crosssell_ids',
            '_purchase_note' => 'purchase_note',
            '_default_attributes' => 'default_attributes',
            '_virtual' => 'is_virtual',
            '_downloadable' => 'is_downloadable',
            '_download_limit' => 'download_limit',
            '_download_expiry' => 'download_expiry',
            '_featured' => 'is_featured',
            '_visibility' => 'visibility',
            '_product_attributes' => 'attributes',
            
            // Events
            '_event_start_date' => 'event_start',
            '_event_end_date' => 'event_end',
            '_event_start_time' => 'event_start_time',
            '_event_end_time' => 'event_end_time',
            '_event_all_day' => 'all_day',
            '_event_timezone' => 'timezone',
            '_event_location' => 'location',
            '_event_address' => 'address',
            '_event_city' => 'city',
            '_event_state' => 'state',
            '_event_zip' => 'zip_code',
            '_event_country' => 'country',
            '_event_latitude' => 'latitude',
            '_event_longitude' => 'longitude',
            '_event_cost' => 'cost',
            '_event_organizer' => 'organizer',
            '_event_venue' => 'venue',
            '_event_max_attendees' => 'max_attendees',
            '_event_rsvp' => 'rsvp_enabled',
            
            // SEO (Yoast)
            '_yoast_wpseo_title' => 'seo_title',
            '_yoast_wpseo_metadesc' => 'seo_description',
            '_yoast_wpseo_canonical' => 'seo_canonical',
            '_yoast_wpseo_focuskw' => 'seo_focus_keyword',
            '_yoast_wpseo_meta-robots-noindex' => 'seo_noindex',
            '_yoast_wpseo_meta-robots-nofollow' => 'seo_nofollow',
            '_yoast_wpseo_opengraph-title' => 'og_title',
            '_yoast_wpseo_opengraph-description' => 'og_description',
            '_yoast_wpseo_opengraph-image' => 'og_image',
            '_yoast_wpseo_twitter-title' => 'twitter_title',
            '_yoast_wpseo_twitter-description' => 'twitter_description',
            '_yoast_wpseo_twitter-image' => 'twitter_image',
            
            // Custom Fields
            '_custom_field_' => 'custom_',
            'field_' => '',
            
            // Menu Items
            '_menu_item_type' => 'object_type',
            '_menu_item_menu_item_parent' => 'menu_item_parent',
            '_menu_item_object_id' => 'object_id',
            '_menu_item_object' => 'object',
            '_menu_item_target' => 'target',
            '_menu_item_classes' => 'css_classes',
            '_menu_item_xfn' => 'xfn',
            '_menu_item_url' => 'url',
            '_menu_item_description' => 'description',
            
        ];

        // Check for exact matches first
        if (isset($mappings[$metaKey])) {
            return $mappings[$metaKey];
        }

        // Check for pattern matches
        foreach ($mappings as $pattern => $replacement) {
            if (str_starts_with($metaKey, $pattern)) {
                $suffix = str_replace($pattern, '', $metaKey);
                return $replacement . Str::snake($suffix);
            }
        }

        // Default: convert to snake_case and remove leading underscore
        return Str::snake(ltrim($metaKey, '_'));
    }

    /**
     * Map WordPress data types to Laravel column types
     */
    public function mapMetaTypeToColumn(string $wpType): array
    {
        return match($wpType) {
            'integer' => [
                'type' => 'integer',
                'cast' => 'integer',
            ],
            'float' => [
                'type' => 'decimal',
                'precision' => 10,
                'scale' => 2,
                'cast' => 'decimal:2',
            ],
            'boolean' => [
                'type' => 'boolean',
                'cast' => 'boolean',
            ],
            'datetime' => [
                'type' => 'datetime',
                'cast' => 'datetime',
            ],
            'json' => [
                'type' => 'json',
                'cast' => 'array',
            ],
            'url' => [
                'type' => 'string',
                'length' => 500,
                'cast' => 'string',
            ],
            'email' => [
                'type' => 'string',
                'length' => 255,
                'cast' => 'string',
            ],
            'string' => [
                'type' => 'string',
                'length' => 255,
                'cast' => 'string',
            ],
            default => [
                'type' => 'text',
                'cast' => 'string',
            ],
        };
    }

    /**
     * Map WordPress post field types to Laravel column types
     */
    public function mapPostFieldTypeToColumn(string $wpField): array
    {
        return match($wpField) {
            'post_id' => [
                'type' => 'id',
                'auto_increment' => true,
                'primary' => true,
            ],
            'post_author' => [
                'type' => 'unsignedBigInteger',
                'foreign_key' => 'users.id',
                'cast' => 'integer',
            ],
            'post_parent' => [
                'type' => 'unsignedBigInteger',
                'nullable' => true,
                'self_reference' => true,
                'cast' => 'integer',
            ],
            'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' => [
                'type' => 'datetime',
                'cast' => 'datetime',
            ],
            'post_title' => [
                'type' => 'string',
                'length' => 255,
                'nullable' => false,
            ],
            'post_content' => [
                'type' => 'longText',
                'nullable' => true,
            ],
            'post_excerpt' => [
                'type' => 'text',
                'nullable' => true,
            ],
            'post_status', 'comment_status', 'ping_status' => [
                'type' => 'string',
                'length' => 20,
                'nullable' => false,
                'index' => true,
            ],
            'post_name' => [
                'type' => 'string',
                'length' => 200,
                'nullable' => false,
                'unique' => true,
            ],
            'post_mime_type' => [
                'type' => 'string',
                'length' => 100,
                'nullable' => true,
                'index' => true,
            ],
            'menu_order', 'comment_count' => [
                'type' => 'integer',
                'nullable' => false,
                'default' => 0,
                'cast' => 'integer',
            ],
            'guid' => [
                'type' => 'string',
                'length' => 255,
                'nullable' => true,
            ],
            'post_password' => [
                'type' => 'string',
                'length' => 255,
                'nullable' => true,
            ],
            'post_content_filtered' => [
                'type' => 'longText',
                'nullable' => true,
            ],
            'to_ping', 'pinged' => [
                'type' => 'text',
                'nullable' => true,
            ],
            default => [
                'type' => 'text',
                'nullable' => true,
            ],
        };
    }

    /**
     * Get smart default values for WordPress fields
     */
    public function getDefaultValue(string $wpField): mixed
    {
        return match($wpField) {
            'post_status' => 'draft',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'menu_order' => 0,
            'comment_count' => 0,
            'post_password' => '',
            'post_content_filtered' => '',
            'to_ping' => '',
            'pinged' => '',
            default => null,
        };
    }

    /**
     * Determine if a WordPress field should be indexed
     */
    public function shouldBeIndexed(string $wpField): bool
    {
        $indexedFields = [
            'post_author',
            'post_parent',
            'post_status',
            'post_date',
            'post_name',
            'post_mime_type',
            'comment_status',
            'ping_status',
        ];

        return in_array($wpField, $indexedFields);
    }

    /**
     * Determine if a WordPress field should be unique
     */
    public function shouldBeUnique(string $wpField): bool
    {
        $uniqueFields = [
            'post_name', // slug should be unique
        ];

        return in_array($wpField, $uniqueFields);
    }

    /**
     * Get suggested Laravel validation rules for WordPress fields
     */
    public function getValidationRules(string $wpField): array
    {
        return match($wpField) {
            'post_title' => ['required', 'string', 'max:255'],
            'post_content' => ['nullable', 'string'],
            'post_excerpt' => ['nullable', 'string', 'max:1000'],
            'post_status' => ['required', 'string', 'in:publish,draft,private,pending,trash'],
            'post_name' => ['required', 'string', 'max:200', 'unique:posts,slug'],
            'post_date' => ['nullable', 'date'],
            'post_author' => ['required', 'integer', 'exists:users,id'],
            'post_parent' => ['nullable', 'integer'],
            'menu_order' => ['integer', 'min:0'],
            'comment_status' => ['string', 'in:open,closed'],
            'ping_status' => ['string', 'in:open,closed'],
            default => ['nullable', 'string'],
        };
    }
}