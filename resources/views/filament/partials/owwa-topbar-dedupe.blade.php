<script data-navigate-once>
    (() => {
        if (window.owwaTopbarDedupeBound) {
            return;
        }

        window.owwaTopbarDedupeBound = true;

        const normalizePath = (path) => {
            if (path.length > 1 && path.endsWith('/')) {
                return path.slice(0, -1);
            }

            return path;
        };

        const sidebarPath = () => {
            const active = document.querySelector('.fi-sidebar-item.fi-active a[href]');

            if (! active) {
                return null;
            }

            try {
                return normalizePath(new URL(active.href, window.location.origin).pathname);
            } catch (error) {
                return null;
            }
        };

        const dedupeTopbarEnd = () => {
            document.querySelectorAll('.fi-topbar-end[x-persist]').forEach((el) => {
                el.removeAttribute('x-persist');
            });

            const topbar = document.querySelector('.fi-topbar');

            if (! topbar) {
                return;
            }

            document.querySelectorAll('.fi-topbar-end').forEach((el) => {
                if (! topbar.contains(el)) {
                    el.remove();
                }
            });

            const ends = topbar.querySelectorAll(':scope > .fi-topbar-end');

            ends.forEach((el, index) => {
                if (index > 0) {
                    el.remove();
                }
            });

            const end = topbar.querySelector(':scope > .fi-topbar-end');

            if (! end) {
                return;
            }

            const keepFirst = (selector) => {
                end.querySelectorAll(selector).forEach((node, index) => {
                    if (index > 0) {
                        node.remove();
                    }
                });
            };

            keepFirst('.owwa-notif-root');
            keepFirst('.fi-user-menu');

            document.querySelectorAll('.fi-user-menu').forEach((node) => {
                if (! end.contains(node)) {
                    node.remove();
                }
            });
        };

        let shownPath = normalizePath(window.location.pathname);

        document.addEventListener('alpine:navigating', dedupeTopbarEnd);
        document.addEventListener('livewire:navigated', () => {
            shownPath = normalizePath(window.location.pathname);
            dedupeTopbarEnd();
        });
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morph.updated', ({ el }) => {
                if (
                    el?.classList?.contains('fi-topbar-end')
                    || el?.classList?.contains('fi-user-menu')
                    || el?.querySelector?.('.fi-topbar-end, .fi-user-menu')
                ) {
                    queueMicrotask(dedupeTopbarEnd);
                }
            });
        });
        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                dedupeTopbarEnd();
            }
        });
        window.addEventListener('popstate', () => {
            const targetPath = normalizePath(window.location.pathname);

            window.setTimeout(() => {
                if (normalizePath(window.location.pathname) !== targetPath) {
                    return;
                }

                const activePath = sidebarPath();
                const renderedPath = activePath ?? shownPath;

                if (renderedPath !== targetPath) {
                    window.location.reload();
                }
            }, 400);
        });

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', dedupeTopbarEnd, { once: true });
        } else {
            dedupeTopbarEnd();
        }
    })();
</script>
