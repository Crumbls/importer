<?php

namespace Crumbls\Importer\Drivers\Tags;

use Crumbls\Importer\Contracts\TagsImportDriver;
use Spatie\Tags\HasTags;
use Spatie\Tags\Tag;
use Illuminate\Support\Str;

/**
 * Spatie Tags driver for importing WordPress categories and tags
 */
class SpatieTagsDriver implements TagsImportDriver
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'category_type' => 'category',
            'tag_type' => 'tag',
            'locale' => 'en',
        ], $config);
    }

    public function importCategory(array $categoryData): mixed
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Spatie Tags is not available');
        }

        $name = $categoryData['name'];
        $slug = $categoryData['slug'] ?? Str::slug($name);
        $description = $categoryData['description'] ?? '';

        // Find or create tag with category type
        $tag = Tag::findOrCreate($name, $this->config['category_type'], $this->config['locale']);
        
        // Update slug if provided
        if ($slug && $tag->slug !== $slug) {
            $tag->slug = $slug;
        }
        
        // Store additional WordPress data as extra attributes
        $extraAttributes = [];
        if (isset($categoryData['wp_id'])) {
            $extraAttributes['wp_term_id'] = $categoryData['wp_id'];
        }
        if ($description) {
            $extraAttributes['description'] = $description;
        }
        if (isset($categoryData['parent_id'])) {
            $extraAttributes['parent_id'] = $categoryData['parent_id'];
        }
        
        if (!empty($extraAttributes)) {
            foreach ($extraAttributes as $key => $value) {
                $tag->{$key} = $value;
            }
        }
        
        $tag->save();

        return $tag;
    }

    public function importTag(array $tagData): mixed
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Spatie Tags is not available');
        }

        $name = $tagData['name'];
        $slug = $tagData['slug'] ?? Str::slug($name);
        $description = $tagData['description'] ?? '';

        // Find or create tag with tag type
        $tag = Tag::findOrCreate($name, $this->config['tag_type'], $this->config['locale']);
        
        // Update slug if provided
        if ($slug && $tag->slug !== $slug) {
            $tag->slug = $slug;
        }
        
        // Store additional WordPress data as extra attributes
        $extraAttributes = [];
        if (isset($tagData['wp_id'])) {
            $extraAttributes['wp_term_id'] = $tagData['wp_id'];
        }
        if ($description) {
            $extraAttributes['description'] = $description;
        }
        
        if (!empty($extraAttributes)) {
            foreach ($extraAttributes as $key => $value) {
                $tag->{$key} = $value;
            }
        }
        
        $tag->save();

        return $tag;
    }

    public function attachToModel($model, array $tags, string $type = 'tag'): void
    {
        // Check if model uses the HasTags trait
        if (!in_array(HasTags::class, class_uses_recursive($model))) {
            throw new \InvalidArgumentException('Model must use HasTags trait');
        }

        // Filter tags by type
        $filteredTags = collect($tags)->filter(function ($tag) use ($type) {
            if (is_string($tag)) {
                return true; // Assume it matches if it's just a string
            }
            
            if ($tag instanceof Tag) {
                return $tag->type === $type;
            }
            
            if (is_array($tag)) {
                return ($tag['type'] ?? 'tag') === $type;
            }
            
            return false;
        });

        // Convert to tag names/objects for attachment
        $tagNames = $filteredTags->map(function ($tag) {
            if (is_string($tag)) {
                return $tag;
            }
            
            if ($tag instanceof Tag) {
                return $tag->name;
            }
            
            if (is_array($tag)) {
                return $tag['name'];
            }
            
            return null;
        })->filter()->toArray();

        if (!empty($tagNames)) {
            // Use Spatie Tags method to attach tags of specific type
            $model->attachTags($tagNames, $type);
        }
    }

    public function isAvailable(): bool
    {
        return class_exists(Tag::class) && trait_exists(HasTags::class);
    }

    public function getName(): string
    {
        return 'spatie';
    }
}