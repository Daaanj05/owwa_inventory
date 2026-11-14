@if ($when === 'guest')
    <script>
        try {
            localStorage.removeItem('collapsedGroups');
            sessionStorage.setItem('owwaSidebarReset', '1');
        } catch (error) {}
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

            var reset = false;

            try {
                reset = sessionStorage.getItem('owwaSidebarReset') === '1';
            } catch (error) {
                reset = false;
            }

            if (! reset || ! document.querySelector('.fi-sidebar-nav')) {
                return;
            }

            try {
                sessionStorage.removeItem('owwaSidebarReset');
            } catch (error) {}

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

            try {
                localStorage.setItem('collapsedGroups', JSON.stringify(collapsed));
            } catch (error) {}

            if (window.Alpine && Alpine.store('sidebar')) {
                Alpine.store('sidebar').collapsedGroups = collapsed.slice();
            }
        })();
    </script>
@endif
