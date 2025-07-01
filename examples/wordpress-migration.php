<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Crumbls\Importer\Adapters\WordPressAdapter;

/**
 * WordPress Migration Example
 * 
 * This demonstrates the complete Extract-Transform-Load flow for migrating
 * WordPress XML exports to a target WordPress database.
 */

echo "WordPress Migration Example\n";
echo "===========================\n\n";

// 1. Configure the migration adapter
echo "1. Configuring WordPress Migration Adapter...\n";

$adapter = new WordPressAdapter([
    'connection' => 'target_wordpress_db',
    'strategy' => 'migration',
    'conflict_strategy' => 'skip', // skip|overwrite|merge
    'create_missing' => true,
    
    'mappings' => [
        'posts' => [
            'table' => 'wp_posts',
            'conflict_strategy' => 'skip',
            'exclude_post_types' => ['revision', 'nav_menu_item']
        ],
        'postmeta' => [
            'table' => 'wp_postmeta',
            'exclude_keys' => ['_wp_trash_*', '_edit_lock', '_edit_last']
        ],
        'users' => [
            'table' => 'wp_users',
            'key_field' => 'user_email',
            'conflict_strategy' => 'merge',
            'create_missing' => true
        ],
        'attachments' => [
            'table' => 'wp_posts',
            'download_files' => true, // Would download actual files
            'media_path' => '/wp-content/uploads/'
        ]
    ],
    
    'relationships' => [
        'posts.author' => 'users.user_login',
        'postmeta.post_id' => 'posts.post_id',
        'comments.post_id' => 'posts.post_id'
    ]
]);

echo "✓ Adapter configured\n\n";

// 2. Configure the WPXML driver
echo "2. Configuring WPXML Driver...\n";

// In a real Laravel app, you would use:
// $driver = importer()->driver('wpxml')->migrateTo($adapter);

// Extract everything by default - all post types, users, comments, meta, etc.
echo "   - Driver extracts ALL entities by default:\n";
echo "     * posts (all post types)\n";
echo "     * postmeta (ALL custom fields, ACF, etc.)\n";
echo "     * attachments (media files)\n";
echo "     * users (with full profiles)\n";
echo "     * comments (and comment meta)\n";
echo "     * terms, categories, tags\n";

echo "✓ Driver configured to extract everything\n\n";

// 3. The complete migration flow
$wpxmlFile = '/path/to/wordpress-export.xml';

echo "3. Migration Flow (simulated)...\n";

try {
    // EXTRACT PHASE
    echo "   a) Extract: Parsing WordPress XML and extracting all entities...\n";
    // This would extract: posts, postmeta, attachments, users, comments, 
    // commentmeta, terms, categories, tags - everything!
    
    // TRANSFORM PHASE (via Migration Planning)
    echo "   b) Transform: Creating migration plan...\n";
    // $plan = $driver->plan($wpxmlFile);
    echo "      - Analyzing extracted data\n";
    echo "      - Mapping to target WordPress schema\n";
    echo "      - Resolving relationships\n";
    echo "      - Detecting conflicts\n";
    
    echo "   c) Validate: Checking migration plan...\n";
    // $validation = $driver->validateMigration($wpxmlFile);
    echo "      - Verifying target database connection\n";
    echo "      - Checking required tables exist\n";
    echo "      - Validating data integrity\n";
    
    echo "   d) Dry Run: Simulating migration...\n";
    // $dryRun = $driver->dryRun($wpxmlFile);
    echo "      - Would create: 150 posts\n";
    echo "      - Would create: 500 postmeta entries\n";
    echo "      - Would create: 5 users\n";
    echo "      - Would create: 25 comments\n";
    echo "      - Would skip: 10 existing posts\n";
    echo "      - Estimated time: 5 minutes\n";
    
    // LOAD PHASE
    echo "   e) Load: Executing migration...\n";
    // $result = $driver->migrate($wpxmlFile, [
    //     'batch_size' => 100,
    //     'progress_callback' => fn($progress) => echo "Progress: {$progress['percentage']}%\n"
    // ]);
    echo "      - Creating posts...\n";
    echo "      - Creating users...\n";
    echo "      - Resolving relationships...\n";
    echo "      - Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}

echo "\n4. Migration Benefits:\n";
echo "   ✓ Extract EVERYTHING by default (no data loss)\n";
echo "   ✓ Handle all WordPress entities (posts, meta, users, comments, etc.)\n";
echo "   ✓ Configurable conflict resolution\n";
echo "   ✓ Relationship mapping and FK resolution\n";
echo "   ✓ Dry run capability for safety\n";
echo "   ✓ Migration planning and validation\n";
echo "   ✓ Progress tracking and error handling\n";
echo "   ✓ Rollback capability (future)\n";

echo "\n5. Usage Examples:\n";
echo "\n   // Simple migration (extract everything, migrate everything)\n";
echo "   \$driver = new WpxmlDriver();\n";
echo "   \$driver->migrateTo(new WordPressAdapter(['connection' => 'target_db']))\n";
echo "           ->migrate('/path/to/export.xml');\n";

echo "\n   // Selective migration\n";
echo "   \$driver = new WpxmlDriver();\n";
echo "   \$driver->onlyContent() // Just posts and related data\n";
echo "           ->migrateTo(\$adapter)\n";
echo "           ->dryRun('/path/to/export.xml'); // Check before migrating\n";

echo "\n   // Custom post type focus\n";
echo "   \$driver = new WpxmlDriver(['enabled_entities' => ['posts' => true]]);\n";
echo "   \$driver->migrateTo(\$adapter)\n";
echo "           ->migrate('/path/to/export.xml');\n";

echo "\n✅ Example completed!\n";