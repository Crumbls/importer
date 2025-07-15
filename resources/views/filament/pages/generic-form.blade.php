{{-- Generic Form Page Template --}}
<x-filament-panels::page>
    {{-- Show form if state has one --}}
    @if(isset($this->state) && $this->state->hasFilamentForm())
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
    @else
        {{-- Show content without form --}}
        <div class="space-y-6">
            {{-- State content --}}
            <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                @if(isset($this->state))
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $this->state->getSubheading($this->record) }}
                    </div>
                @else
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        State information is not available.
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
</x-filament-panels::page>