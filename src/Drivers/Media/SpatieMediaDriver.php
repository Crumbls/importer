<?php

namespace Crumbls\Importer\Drivers\Media;

use Crumbls\Importer\Contracts\MediaImportDriver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Spatie Media Library driver for importing media
 */
class SpatieMediaDriver implements MediaImportDriver
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'collection' => 'default',
            'download_remote' => true,
            'disk' => 'public',
            'conversions' => ['thumb', 'medium', 'large'],
            'timeout' => 30,
        ], $config);
    }

    public function importAttachment(array $attachmentData, string $downloadUrl = null): mixed
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Spatie Media Library is not available');
        }

        $filename = $attachmentData['filename'] ?? $this->generateFilename($attachmentData, $downloadUrl);
        $title = $attachmentData['title'] ?? pathinfo($filename, PATHINFO_FILENAME);
        $description = $attachmentData['description'] ?? '';
        $altText = $attachmentData['alt_text'] ?? '';

        // Create media record without file first
        $media = new Media();
        $media->name = $title;
        $media->file_name = $filename;
        $media->mime_type = $attachmentData['mime_type'] ?? 'application/octet-stream';
        $media->size = $attachmentData['size'] ?? 0;
        $media->collection_name = $this->config['collection'];
        $media->disk = $this->config['disk'];
        
        // Add custom properties
        $media->setCustomProperty('alt_text', $altText);
        $media->setCustomProperty('description', $description);
        $media->setCustomProperty('wp_attachment_id', $attachmentData['wp_id'] ?? null);

        if ($downloadUrl && $this->config['download_remote']) {
            try {
                // Download the file
                $response = Http::timeout($this->config['timeout'])->get($downloadUrl);
                
                if ($response->successful()) {
                    $tempPath = storage_path('app/temp/' . $filename);
                    
                    // Ensure temp directory exists
                    if (!file_exists(dirname($tempPath))) {
                        mkdir(dirname($tempPath), 0755, true);
                    }
                    
                    file_put_contents($tempPath, $response->body());
                    
                    // Add file to media library
                    $media->addMediaFromExisting($tempPath)
                        ->usingName($title)
                        ->usingFileName($filename)
                        ->toMediaCollection($this->config['collection'], $this->config['disk']);
                    
                    // Clean up temp file
                    unlink($tempPath);
                } else {
                    // Save just the metadata if download fails
                    $media->save();
                }
            } catch (\Exception $e) {
                // Save just the metadata if download fails
                $media->save();
            }
        } else {
            // Save just the metadata
            $media->save();
        }

        return $media;
    }

    public function setFeaturedImage($model, $mediaId): void
    {
        if (!$model instanceof HasMedia) {
            throw new \InvalidArgumentException('Model must implement HasMedia interface');
        }

        // Clear existing featured images
        $model->clearMediaCollection('featured');
        
        // Find the media and add to featured collection
        $media = Media::find($mediaId);
        if ($media) {
            $model->addMediaFromExisting($media->getPath())
                ->usingName($media->name)
                ->toMediaCollection('featured');
        }
    }

    public function isAvailable(): bool
    {
        return class_exists(Media::class) && interface_exists(HasMedia::class);
    }

    public function getName(): string
    {
        return 'spatie';
    }

    protected function generateFilename(array $attachmentData, string $downloadUrl = null): string
    {
        if ($downloadUrl) {
            $parsedUrl = parse_url($downloadUrl);
            $pathInfo = pathinfo($parsedUrl['path'] ?? '');
            if (!empty($pathInfo['basename'])) {
                return $pathInfo['basename'];
            }
        }

        $title = $attachmentData['title'] ?? 'attachment';
        $extension = $attachmentData['extension'] ?? 'jpg';
        
        return Str::slug($title) . '.' . $extension;
    }
}