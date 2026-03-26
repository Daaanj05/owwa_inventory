{{-- Hidden by default; shows only while `activeItemCategoryId` is updating. --}}
<div
    wire:loading.delay.100ms
    wire:target="activeItemCategoryId"
    class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/25"
>
    <div class="bg-white rounded-xl shadow-xl px-6 py-4 flex items-center gap-3">
        <svg
            fill="none"
            viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg"
            class="fi-icon fi-loading-indicator fi-size-md text-primary-600"
        >
            <path
                clip-rule="evenodd"
                d="M12 19C15.866 19 19 15.866 19 12C19 8.13401 15.866 5 12 5C8.13401 5 5 8.13401 5 12C5 15.866 8.13401 19 12 19ZM12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z"
                fill-rule="evenodd"
                fill="currentColor"
                opacity="0.2"
            ></path>
            <path
                d="M2 12C2 6.47715 6.47715 2 12 2V5C8.13401 5 5 8.13401 5 12H2Z"
                fill="currentColor"
            ></path>
        </svg>

        <div class="flex flex-col">
            <span class="text-sm font-semibold text-gray-900">Loading acquisitions...</span>
            <span class="text-xs text-gray-500">Please wait.</span>
        </div>
    </div>
</div>

