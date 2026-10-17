<script>
    (function () {
        var flag = 'owwaNavFoldersCollapsedV1';
        var labels = @json($labels);
        var stored = localStorage.getItem('collapsedGroups');
        var collapsed;

        try {
            collapsed = stored === null || stored === 'null' ? [] : JSON.parse(stored);
        } catch (error) {
            collapsed = [];
        }

        if (! Array.isArray(collapsed)) {
            collapsed = [];
        }

        if (! localStorage.getItem(flag)) {
            labels.forEach(function (label) {
                if (! collapsed.includes(label)) {
                    collapsed.push(label);
                }
            });
            localStorage.setItem(flag, '1');
        }

        var sectionFlag = 'owwaNavSectionsCollapsedV1';
        var sections = ['Requisitions', 'Analytics'];

        if (! localStorage.getItem(sectionFlag)) {
            sections.forEach(function (label) {
                if (! collapsed.includes(label)) {
                    collapsed.push(label);
                }
            });
            localStorage.setItem(sectionFlag, '1');
        }

        var active = document.querySelector('.fi-sidebar-group.fi-active');

        if (active) {
            var activeLabel = active.dataset.groupLabel;
            collapsed = collapsed.filter(function (label) {
                return label !== activeLabel;
            });
            var items = active.querySelector('.fi-sidebar-group-items');

            if (items) {
                items.style.display = '';
            }

            active.classList.remove('fi-collapsed');
        }

        localStorage.setItem('collapsedGroups', JSON.stringify(collapsed));

        if (window.Alpine && Alpine.store('sidebar')) {
            Alpine.store('sidebar').collapsedGroups = collapsed.slice();
        }

        collapsed.forEach(function (label) {
            var group = document.querySelector('.fi-sidebar-group[data-group-label="' + label.replace(/"/g, '\\"') + '"]');

            if (! group || group.classList.contains('fi-active')) {
                return;
            }

            var groupItems = group.querySelector('.fi-sidebar-group-items');

            if (groupItems) {
                groupItems.style.display = 'none';
            }

            group.classList.add('fi-collapsed');
        });

        var requisitions = document.querySelector('.fi-sidebar-group[data-group-label="Requisitions"]');
        var requisitionBadge = requisitions
            ? requisitions.querySelector('.fi-sidebar-item-badge-ctn')
            : null;
        var requisitionButton = requisitions
            ? requisitions.querySelector(':scope > .fi-sidebar-group-btn')
            : null;

        if (! window.owwaSidebarAccordion) {
            window.owwaSidebarAccordion = true;

            document.addEventListener('click', function (event) {
                var button = event.target.closest('.fi-sidebar-group-btn');

                if (! button || ! window.Alpine || ! Alpine.store('sidebar')) {
                    return;
                }

                var group = button.closest('.fi-sidebar-group');
                var label = group ? group.dataset.groupLabel : null;

                if (! label) {
                    return;
                }

                setTimeout(function () {
                    var store = Alpine.store('sidebar');
                    var collapsedNow = Array.isArray(store.collapsedGroups) ? store.collapsedGroups : [];

                    if (collapsedNow.includes(label)) {
                        return;
                    }

                    var others = [];

                    document.querySelectorAll('.fi-sidebar-group[data-group-label]').forEach(function (other) {
                        var otherLabel = other.dataset.groupLabel;

                        if (otherLabel && otherLabel !== label) {
                            others.push(otherLabel);
                        }
                    });

                    store.collapsedGroups = others;
                }, 0);
            }, true);
        }

        if (requisitionBadge && requisitionButton && ! requisitionButton.querySelector('.owwa-nav-group-badge')) {
            var badge = requisitionBadge.cloneNode(true);
            badge.classList.add('owwa-nav-group-badge');
            var chevron = requisitionButton.querySelector('.fi-sidebar-group-collapse-btn');

            if (chevron) {
                requisitionButton.insertBefore(badge, chevron);
            } else {
                requisitionButton.appendChild(badge);
            }
        }
    })();

    if (window.Livewire && ! window.owwaSidebarBadgePoll) {
        window.owwaSidebarBadgePoll = setInterval(function () {
            Livewire.dispatch('refresh-sidebar');
        }, {{ (int) ($pollMs ?? 5000) }});
    }
</script>
