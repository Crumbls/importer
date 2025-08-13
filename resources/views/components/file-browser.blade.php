<x-dynamic-component
        :component="$getFieldWrapperView()"
        :field="$field"
>
    @php
        $contents = $contents ?? ['directories' => [], 'files' => [], 'current_path' => '', 'parent_path' => null];
        $currentPath = $contents['current_path'] ?? '';
        $parentPath = $contents['parent_path'] ?? null;
        $selectedValue = $getState() ?? '';
        $hasFileSelected = is_string($selectedValue) && str_contains($selectedValue, '::');
    @endphp

    <div class="space-y-3" x-data="{ 
        isOpen: {{ $hasFileSelected ? 'false' : 'true' }},
        selectedFile: '{{ $hasFileSelected ? $selectedValue : '' }}'
    }">
        {{-- Selected File Display (looks like native select dropdown) --}}
        <div x-show="!isOpen" 
             class="fi-fo-field-wrp">
            <div class="fi-input-wrp fi-has-suffixs-actions" 
                 x-on:click="isOpen = true">
                <div class="fi-input-wrp-content-ctn">
                    <input class="fi-input"
                           readonly
                           x-bind:value="selectedFile.split('::')[1] ? selectedFile.split('::')[1] : 'No file selected'"
                           x-bind:placeholder="'Select a file...'"
                           style="cursor: pointer;">
                </div>
                <div class="fi-input-wrp-suffix-actions" style="display: flex; align-items: center; padding-right: 0.75rem;">
                    <svg style="width: 1rem; height: 1rem; color: #6b7280;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"></path>
                    </svg>
                </div>
            </div>
        </div>

        {{-- File Browser (when browsing) --}}
        <div x-show="isOpen" class="space-y-3">
            {{-- Current Path Breadcrumb --}}
            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center p-4">
                    <div class="flex items-center text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Current disk:</span>
                        <span class="ml-2 font-medium text-gray-950 dark:text-white">{{ $driveName ?? 'Storage' }}</span>
                        @if($currentPath)
                            <span class="mx-2 text-gray-400">/</span>
                            <span class="text-gray-600 dark:text-gray-300">{{ $currentPath }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- File Browser Table --}}
        <div class="fi-ta-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                <thead class="divide-y divide-gray-200 dark:divide-white/5">
                <tr class="bg-gray-50 dark:bg-white/5">
                    <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                        <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                            <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Name</span>
                        </span>
                    </th>
                    <th class="fi-ta-header-cell px-3 py-3.5 text-center w-20">
                        <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Type</span>
                    </th>
                    <th class="fi-ta-header-cell px-3 py-3.5 text-end w-24">
                        <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Size</span>
                    </th>
                    <th class="fi-ta-header-cell px-3 py-3.5 text-end w-32">
                        <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Modified</span>
                    </th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                {{-- Up Directory Link (if not at root) --}}
                @if($parentPath !== null)
                    <tr class="fi-ta-row transition duration-75 hover:bg-gray-50 focus:bg-gray-50 dark:hover:bg-white/5 dark:focus:bg-white/5" 
                        x-data 
                        x-on:click="$wire.set('data.{{ $getStatePath(false) }}', { current_path: '{{ $parentPath }}' })">
                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                            <div class="fi-ta-col-wrp">
                                <div class="flex w-full justify-start text-start">
                                    <div class="fi-ta-text grid w-full gap-y-1 px-3 py-4">
                                        <div class="flex">
                                            <div class="flex max-w-max">
                                                <div class="fi-ta-text-item inline-flex items-center gap-1.5 text-sm leading-6 text-primary-600 dark:text-primary-400 font-medium">
                                                    ← Back
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="fi-ta-cell p-0 text-center"><div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">—</div></td>
                        <td class="fi-ta-cell p-0 text-end"><div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">—</div></td>
                        <td class="fi-ta-cell p-0 text-end"><div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">—</div></td>
                    </tr>
                @endif

                {{-- Directories --}}
                @foreach($contents['directories'] as $dir)
                    <tr class="fi-ta-row transition duration-75 hover:bg-gray-50 focus:bg-gray-50 dark:hover:bg-white/5 dark:focus:bg-white/5" 
                        x-data 
                        x-on:click="$wire.set('data.{{ $getStatePath(false) }}', { current_path: '{{ $dir['path'] }}' })">
                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                            <div class="fi-ta-col-wrp">
                                <div class="flex w-full justify-start text-start">
                                    <div class="fi-ta-text grid w-full gap-y-1 px-3 py-4">
                                        <div class="flex">
                                            <div class="flex max-w-max">
                                                <div class="fi-ta-text-item inline-flex items-center gap-1.5 text-sm leading-6 text-gray-950 dark:text-white font-medium">
                                                    {{ $dir['name'] }}/
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="fi-ta-cell p-0 text-center">
                            <div class="px-3 py-4">
                                <div class="fi-badge fi-color-gray flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-4 py-1 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30" style="--c-50:var(--gray-50);--c-400:var(--gray-400);--c-600:var(--gray-600);">
                                    <span class="grid">
                                        <span class="truncate">DIR</span>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="fi-ta-cell p-0 text-end"><div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">—</div></td>
                        <td class="fi-ta-cell p-0 text-end"><div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">—</div></td>
                    </tr>
                @endforeach

                {{-- Files --}}
                @foreach($contents['files'] as $file)
                    @php
                        $filePath = $driveName . '::' . $file['path'];
                        $isSelected = $selectedValue === $filePath;
                    @endphp
                    <tr class="fi-ta-row transition duration-75 hover:bg-gray-50 focus:bg-gray-50 dark:hover:bg-white/5 dark:focus:bg-white/5" 
                        x-data 
                        x-on:click="
                            $wire.set('data.{{ $getStatePath(false) }}', '{{ $filePath }}');
                            selectedFile = '{{ $filePath }}';
                            isOpen = false;
                        ">
                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                            <div class="fi-ta-col-wrp">
                                <div class="flex w-full justify-start text-start">
                                    <div class="fi-ta-text grid w-full gap-y-1 px-3 py-4">
                                        <div class="flex">
                                            <div class="flex max-w-max">
                                                <div class="fi-ta-text-item inline-flex items-center gap-1.5 text-sm leading-6 text-gray-950 dark:text-white {{ $isSelected ? 'font-semibold' : '' }}">
                                                    {{ $file['name'] }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="fi-ta-cell p-0 text-center">
                            <div class="px-3 py-4">
                                <div class="fi-badge fi-color-gray flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-h-4 py-1 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30" style="--c-50:var(--gray-50);--c-400:var(--gray-400);--c-600:var(--gray-600);">
                                    <span class="grid">
                                        <span class="truncate uppercase">{{ $file['extension'] }}</span>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="fi-ta-cell p-0 text-end">
                            <div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ number_format($file['size'] / 1024, 1) }} KB
                            </div>
                        </td>
                        <td class="fi-ta-cell p-0 text-end">
                            <div class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ \Carbon\Carbon::createFromTimestamp($file['modified'])->format('M j, g:i A') }}
                            </div>
                        </td>
                    </tr>
                @endforeach

                {{-- Empty State --}}
                @if(empty($contents['directories']) && empty($contents['files']))
                    <tr>
                        <td colspan="4" class="fi-ta-cell px-3 py-8 text-center">
                            <div class="flex flex-col items-center text-gray-500 dark:text-gray-400">
                                <div class="text-2xl mb-2">📂</div>
                                <div>This folder is empty</div>
                            </div>
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
        </div> {{-- Close File Browser (when browsing) --}}
        
        {{-- Hidden input to store the selected file path --}}
        <input type="hidden" 
               name="{{ $getStatePath() }}" 
               x-bind:value="selectedFile"
               x-ref="hiddenInput">
    </div> {{-- Close main container --}}
</x-dynamic-component>