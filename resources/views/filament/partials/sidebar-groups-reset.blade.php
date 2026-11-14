@if ($when === 'guest')
    <script>
        try {
            localStorage.removeItem('collapsedGroups');
            sessionStorage.setItem('owwaSidebarReset', '1');
        } catch (error) {}
    </script>
@elseif ($when === 'marker')
    @php
        $serverReset = (bool) session()->pull('owwa_sidebar_reset', false);
    @endphp
    <script>
        (function () {
            var fromBrowser = false;

            try {
                fromBrowser = sessionStorage.getItem('owwaSidebarReset') === '1';
            } catch (error) {
                fromBrowser = false;
            }

            window.owwaSidebarReset = @json($serverReset) || fromBrowser;

            if (window.owwaSidebarReset) {
                try {
                    sessionStorage.removeItem('owwaSidebarReset');
                } catch (error) {}
            }
        })();
    </script>
@else
    <script>
        (function () {
            function markSidebarReset() {
                try {
                    localStorage.removeItem('collapsedGroups');
                    sessionStorage.setItem('owwaSidebarReset', '1');
                } catch (error) {}
            }

            function collapseAllSidebarGroups() {
                var collapsed = [];

                document.querySelectorAll('.fi-sidebar-group.fi-collapsible[data-group-label]').forEach(function (group) {
                    var label = group.dataset.groupLabel;

                    if (! label || collapsed.indexOf(label) !== -1) {
                        return;
                    }

                    collapsed.push(label);

                    var groupItems = group.querySelector('.fi-sidebar-group-items');

                    if (groupItems) {
                        groupItems.style.display = 'none';
                    }

                    group.classList.add('fi-collapsed');
                });

                if (! collapsed.length) {
                    return;
                }

                try {
                    localStorage.setItem('collapsedGroups', JSON.stringify(collapsed));
                } catch (error) {}

                if (window.Alpine && Alpine.store('sidebar')) {
                    Alpine.store('sidebar').collapsedGroups = collapsed.slice();
                }
            }

            if (! window.owwaSidebarResetBound) {
                window.owwaSidebarResetBound = true;

                document.addEventListener('submit', function (event) {
                    var form = event.target;

                    if (! form || form.nodeName !== 'FORM') {
                        return;
                    }

                    var action = form.getAttribute('action') || '';

                    if (action.indexOf('/logout') === -1) {
                        return;
                    }

                    markSidebarReset();
                }, true);
            }

            if (window.owwaSidebarReset !== true || window.owwaSidebarResetApplied) {
                return;
            }

            if (! document.querySelector('.fi-sidebar-nav')) {
                return;
            }

            window.owwaSidebarResetApplied = true;
            collapseAllSidebarGroups();
            document.addEventListener('alpine:initialized', collapseAllSidebarGroups, { once: true });
        })();
    </script>
@endif
