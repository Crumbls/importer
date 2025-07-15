{{-- Generic Infolist Page - Shows read-only information --}}
<x-filament-panels::page>
    {{-- Show infolist for states that provide information --}}
    @if($this->state && $this->state->hasFilamentInfolist())
        <div class="space-y-6">
            {{ $this->infolist }}
            
            {{-- Header Actions --}}
            @if(count($this->getHeaderActions()) > 0)
                <div class="flex gap-3">
                    @foreach($this->getHeaderActions() as $action)
                        {{ $action }}
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Fallback content --}}
        <div class="space-y-6">
            <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    No information available for this state.
                </div>
            </div>
        </div>
    @endif
    
    {{-- Polling functionality --}}
    @if($this->getPollingInterval())
        <div wire:poll.{{ $this->getPollingInterval() }}="refresh"></div>
    @endif
</x-filament-panels::page>