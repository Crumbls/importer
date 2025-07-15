<?php

namespace Crumbls\Importer\Services;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\States\AbstractState;
use Filament\Resources\Pages\Page;

class PageResolver
{
    /**
     * Resolve the appropriate page class for a given state and import
     */
    public function resolvePage(AbstractState $state, ImportContract $record): Page
    {
        $pageClass = $state->getRecommendedPageClass();
        $context = $state->getPageContext($record);
        
        // Ensure the page class exists, fallback to generic if not
        if (!class_exists($pageClass)) {
            logger()->warning('Recommended page class does not exist', [
                'recommended_class' => $pageClass,
                'state' => get_class($state),
            ]);
            $pageClass = $this->getGenericPageClass($state);
        }
        
        // Double-check the final page class exists
        if (!class_exists($pageClass)) {
            throw new \Exception("Page class does not exist: {$pageClass}");
        }
        
        // Create the page instance
        try {
            $page = app($pageClass);
        } catch (\Exception $e) {
            throw new \Exception("Failed to create page instance for {$pageClass}: " . $e->getMessage());
        }
        
        // Mount the page with the context
        try {
            $this->mountPage($page, $context);
        } catch (\Exception $e) {
            throw new \Exception("Failed to mount page {$pageClass}: " . $e->getMessage());
        }
        
        return $page;
    }
    
    /**
     * Mount a page with the given context
     */
    protected function mountPage(Page $page, array $context): void
    {
        // Extract parameters for mounting
        $record = $context['record'] ?? null;
        $state = $context['state'] ?? null;
        
        if (method_exists($page, 'mount')) {
            $page->mount($record, $state);
        }
    }
    
    /**
     * Get appropriate generic page class based on state capabilities
     */
    protected function getGenericPageClass(AbstractState $state): string
    {
        $capabilities = $state->getPageCapabilities();
        
        // For now, we only have GenericFormPage implemented
        // TODO: Create other page types as needed
        
        // Check if we have specialized pages for these capabilities
        $pageClasses = [
            'table' => \Crumbls\Importer\Filament\Pages\GenericTablePage::class,
            'widgets' => \Crumbls\Importer\Filament\Pages\GenericDashboardPage::class,
            'infolist' => \Crumbls\Importer\Filament\Pages\GenericInfolistPage::class,
        ];
        
        foreach ($capabilities as $capability) {
            if (isset($pageClasses[$capability]) && class_exists($pageClasses[$capability])) {
                return $pageClasses[$capability];
            }
        }
        
        // Default to form page (the only one we have implemented)
        return \Crumbls\Importer\Filament\Pages\GenericFormPage::class;
    }
    
    /**
     * Check if a page class can handle a given state
     */
    public function canPageHandleState(string $pageClass, AbstractState $state): bool
    {
        if (!class_exists($pageClass)) {
            return false;
        }
        
        $capabilities = $state->getPageCapabilities();
        
        // If page has a method to check capabilities, use it
        if (method_exists($pageClass, 'getSupportedCapabilities')) {
            $pageCapabilities = $pageClass::getSupportedCapabilities();
            return !empty(array_intersect($capabilities, $pageCapabilities));
        }
        
        // Otherwise check by class name patterns
        $className = class_basename($pageClass);
        
        if (str_contains($className, 'Form') && in_array('form', $capabilities)) {
            return true;
        }
        
        if (str_contains($className, 'Table') && in_array('table', $capabilities)) {
            return true;
        }
        
        if (str_contains($className, 'Dashboard') && in_array('widgets', $capabilities)) {
            return true;
        }
        
        // Generic pages can handle anything
        if (str_contains($className, 'Generic')) {
            return true;
        }
        
        return false;
    }
}