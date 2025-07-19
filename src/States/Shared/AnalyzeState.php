<?php

namespace Crumbls\Importer\States\Shared;

use Crumbls\Importer\Facades\Storage;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Crumbls\Importer\States\FailedState;

/**
 * @deprecated
 */
class AnalyzingState extends AbstractState
{

    public function onEnter(): void
    {
        $import = $this->getRecord();

        if (!$import instanceof ImportContract) {
            throw new \RuntimeException('Import contract not found in context');
        }

        try {
            $metadata = $import->metadata ?? [];
            
            if (!isset($metadata['storage_driver'])) {
                throw new \RuntimeException('No storage driver found in metadata');
            }

            // Get the storage driver
            $storage = Storage::driver($metadata['storage_driver'])
                ->configureFromMetadata($metadata);

            // Analyze the WordPress data
            $analysis = $this->analyzeWordPressData($storage);

            // Update import metadata with analysis results
            $import->update([
                'metadata' => array_merge($metadata, [
                    'analysis_completed' => true,
                    'analysis_results' => $analysis,
                    'analyzed_at' => now()->toISOString(),
                ])
            ]);

        } catch (\Exception $e) {
            $import->update([
                'state' => FailedState::class,
                'error_message' => $e->getMessage(),
                'failed_at' => now(),
            ]);
            throw $e;
        }
    }

    protected function analyzeWordPressData($storage): array
    {
        $analysis = [
            'post_types' => [],
            'taxonomies' => [],
            'meta_fields' => [],
            'users' => [],
            'summary' => []
        ];

        // Analyze post types and their structure
        if ($storage->tableExists('posts')) {
            $analysis['post_types'] = $this->analyzePostTypes($storage);
        }

        // Analyze taxonomies and terms
        if ($storage->tableExists('terms')) {
            $analysis['taxonomies'] = $this->analyzeTaxonomies($storage);
        }

        // Analyze meta fields by post type
        if ($storage->tableExists('postmeta')) {
            $analysis['meta_fields'] = $this->analyzeMetaFields($storage);
        }

        // Analyze users
        if ($storage->tableExists('users')) {
            $analysis['users'] = $this->analyzeUsers($storage);
        }

        // Generate summary
        $analysis['summary'] = $this->generateSummary($analysis, $storage);

        return $analysis;
    }



    protected function analyzeTaxonomies($storage): array
    {
        $taxonomies = [];

        // Get all terms grouped by taxonomy
        $terms = $storage->select('terms', []);
        
        foreach ($terms as $term) {
            // Convert to array if it's an object
            $termData = is_object($term) ? (array) $term : $term;
            
            $taxonomy = $termData['taxonomy'] ?? 'category';

            if (!isset($taxonomies[$taxonomy])) {
                $taxonomies[$taxonomy] = [
                    'term_count' => 0,
                    'sample_terms' => []
                ];
            }

            $taxonomies[$taxonomy]['term_count']++;

            // Keep sample terms
            if (count($taxonomies[$taxonomy]['sample_terms']) < 5) {
                $taxonomies[$taxonomy]['sample_terms'][] = [
                    'term_id' => $termData['term_id'],
                    'name' => $termData['name'],
                    'slug' => $termData['slug']
                ];
            }
        }

        return $taxonomies;
    }

    protected function analyzeMetaFields($storage): array
    {
        $metaFields = [];

        // Get all postmeta grouped by post type
        $postTypeMeta = $storage->select('postmeta', []);
        $postTypes = $storage->select('posts', []);

        // Create a lookup for post_id to post_type
        $postTypeLookup = [];
        foreach ($postTypes as $post) {
            $postData = is_object($post) ? (array) $post : $post;
            $postTypeLookup[$postData['post_id']] = $postData['post_type'] ?? 'post';
        }

        // Group meta fields by post type
        foreach ($postTypeMeta as $meta) {
            $metaData = is_object($meta) ? (array) $meta : $meta;
            
            $postType = $postTypeLookup[$metaData['post_id']] ?? 'unknown';
            $metaKey = $metaData['meta_key'] ?? '';

            if (!isset($metaFields[$postType])) {
                $metaFields[$postType] = [];
            }

            if (!isset($metaFields[$postType][$metaKey])) {
                $metaFields[$postType][$metaKey] = [
                    'count' => 0,
                    'sample_values' => []
                ];
            }

            $metaFields[$postType][$metaKey]['count']++;

            // Keep sample values (truncated for readability)
            if (count($metaFields[$postType][$metaKey]['sample_values']) < 3) {
                $value = $metaData['meta_value'] ?? '';
                if (strlen($value) > 100) {
                    $value = substr($value, 0, 100) . '...';
                }
                $metaFields[$postType][$metaKey]['sample_values'][] = $value;
            }
        }

        // Sort meta fields by frequency for each post type
        foreach ($metaFields as $postType => &$fields) {
            uasort($fields, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });
        }

        return $metaFields;
    }

    protected function analyzeUsers($storage): array
    {
        $users = $storage->select('users', []);

        return [
            'total_count' => count($users),
            'sample_users' => array_slice(array_map(function ($user) {
                $userData = is_object($user) ? (array) $user : $user;
                
                return [
                    'user_id' => $userData['user_id'],
                    'login' => $userData['login'],
                    'display_name' => $userData['display_name'],
                    'email' => $userData['email'] ? '***@' . substr($userData['email'], strpos($userData['email'], '@')) : null
                ];
            }, $users), 0, 5)
        ];
    }

    protected function generateSummary($analysis, $storage): array
    {
        $summary = [
            'total_posts' => 0,
            'total_meta_fields' => 0,
            'total_terms' => 0,
            'total_users' => 0,
            'post_type_distribution' => [],
            'taxonomy_distribution' => [],
            'most_common_meta_fields' => []
        ];

        // Summarize post types
        foreach ($analysis['post_types'] as $postType => $data) {
            $summary['total_posts'] += $data['total_count'];
            $summary['post_type_distribution'][$postType] = $data['total_count'];
        }

        // Summarize taxonomies
        foreach ($analysis['taxonomies'] as $taxonomy => $data) {
            $summary['total_terms'] += $data['term_count'];
            $summary['taxonomy_distribution'][$taxonomy] = $data['term_count'];
        }

        // Find most common meta fields across all post types
        $allMetaFields = [];
        foreach ($analysis['meta_fields'] as $postType => $fields) {
            foreach ($fields as $fieldName => $fieldData) {
                if (!isset($allMetaFields[$fieldName])) {
                    $allMetaFields[$fieldName] = 0;
                }
                $allMetaFields[$fieldName] += $fieldData['count'];
                $summary['total_meta_fields'] += $fieldData['count'];
            }
        }

        // Sort and get top 10 meta fields
        arsort($allMetaFields);
        $summary['most_common_meta_fields'] = array_slice($allMetaFields, 0, 10, true);

        $summary['total_users'] = $analysis['users']['total_count'] ?? 0;

        return $summary;
    }
}