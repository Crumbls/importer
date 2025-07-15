{{-- Import Step Page - Delegates to state-specific pages --}}
<x-filament-panels::page>
    {{-- This view is rendered by ImportStep which delegates to state-specific pages --}}
    {{-- The actual form/content is handled by the delegated page --}}
    
    
    {{-- Show form if the delegated page or state has one --}}
    @if($delegatedPage && method_exists($delegatedPage, 'form'))
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
            
            @if (count($formActions = $this->getCachedFormActions()))
                <div class="flex flex-wrap items-center gap-4 justify-start">
                    @foreach ($formActions as $formAction)
                        {{ $formAction }}
                    @endforeach
                </div>
            @endif
        </form>
    @elseif($delegatedPage && method_exists($delegatedPage, 'infolist'))
        <div class="space-y-6">
            {{ $this->infolist }}
            
            @if (count($headerActions = $this->getHeaderActions()))
                <div class="flex flex-wrap items-center gap-4 justify-start">
                    @foreach ($headerActions as $headerAction)
                        {{ $headerAction }}
                    @endforeach
                </div>
            @endif
        </div>
    @elseif($currentState && $currentState->hasFilamentForm())
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
            
            @if (count($formActions = $this->getCachedFormActions()))
                <div class="flex flex-wrap items-center gap-4 justify-start">
                    @foreach ($formActions as $formAction)
                        {{ $formAction }}
                    @endforeach
                </div>
            @endif
        </form>
    @elseif($currentState && $currentState->hasFilamentInfolist())
        <div class="space-y-6">
            {{ $this->infolist }}
            
            @if (count($headerActions = $this->getHeaderActions()))
                <div class="flex flex-wrap items-center gap-4 justify-start">
                    @foreach ($headerActions as $headerAction)
                        {{ $headerAction }}
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Show content without form (for states like processing, completed, etc.) --}}
        <div class="space-y-6">
            {{-- State content --}}
            <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @if($currentState)
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $currentState->getSubheading($record) }}
                    </div>
                @else
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        Current state information is not available.
                    </div>
                @endif
            </div>
            
            {{-- Actions --}}
            @if(count($this->getCachedFormActions()) > 0)
                <div class="flex gap-3">
                    @foreach($this->getCachedFormActions() as $action)
                        {{ $action }}
                    @endforeach
                </div>
            @endif
        </div>
    @endif
    
    {{-- Polling functionality --}}
    @if($this->getPollingInterval())
        <div wire:poll.{{ $this->getPollingInterval() }}="refresh"></div>
    @endif
    
    {{-- Debug information (remove in production) --}}
    @if(app()->environment('local') && config('app.debug'))
        <div class="mt-8 rounded-lg bg-gray-50 p-4 text-xs text-gray-500">
            <strong>Debug Info:</strong><br>
            Current State: {{ method_exists($this, 'getCurrentState') && $this->getCurrentState() ? class_basename($this->getCurrentState()) : 'Unknown' }}<br>
            Delegated Page: {{ isset($this->delegatedPage) ? class_basename($this->delegatedPage) : 'None' }}<br>
            View: {{ $this->getView() }}<br />
            Driver: {{ $record->driver }}
        </div>
    @endif
</x-filament-panels::page>