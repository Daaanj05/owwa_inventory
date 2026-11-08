<script>
    (() => {
        const dedupeTopbarEnd = () => {
            const end = document.querySelector('.fi-topbar-end');

            if (! end) {
                return;
            }

            const keepFirst = (selector) => {
                end.querySelectorAll(`:scope > ${selector}`).forEach((node, index) => {
                    if (index > 0) {
                        node.remove();
                    }
                });
            };

            keepFirst('.owwa-notif-root');
            keepFirst('.fi-user-menu');
        };

        document.addEventListener('DOMContentLoaded', dedupeTopbarEnd);
        document.addEventListener('livewire:navigated', dedupeTopbarEnd);
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morph.updated', ({ el }) => {
                if (el?.classList?.contains('fi-topbar-end') || el?.querySelector?.('.fi-topbar-end')) {
                    queueMicrotask(dedupeTopbarEnd);
                }
            });
        });
    })();
</script>
