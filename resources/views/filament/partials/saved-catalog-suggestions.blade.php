<script>
    (() => {
        if (window.__owwaSavedCatalogSuggestions) {
            return;
        }

        window.__owwaSavedCatalogSuggestions = true;

        const bindings = new Set();

        const savedOptions = (input) => {
            try {
                const parsed = JSON.parse(input.dataset.owwaSavedSuggestions || '[]');

                return Array.isArray(parsed)
                    ? parsed.filter((option) => typeof option === 'string' && option.trim() !== '')
                    : [];
            } catch {
                return [];
            }
        };

        const hide = (input) => {
            if (input._owwaSuggestPanel instanceof HTMLElement) {
                input._owwaSuggestPanel.hidden = true;
            }
        };

        const place = (input, panel) => {
            const rect = input.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            panel.style.left = `${rect.left}px`;
            panel.style.width = `${rect.width}px`;

            if (spaceBelow < 180 && rect.top > spaceBelow) {
                panel.style.top = 'auto';
                panel.style.bottom = `${window.innerHeight - rect.top + 4}px`;
            } else {
                panel.style.bottom = 'auto';
                panel.style.top = `${rect.bottom + 4}px`;
            }
        };

        const show = (input) => {
            const panel = input._owwaSuggestPanel;
            if (! (panel instanceof HTMLElement)) {
                return;
            }

            const query = input.value.trim().toLowerCase();
            const matches = savedOptions(input).filter((option) => query === '' || option.toLowerCase().includes(query));
            panel.replaceChildren();

            if (matches.length === 0) {
                panel.hidden = true;

                return;
            }

            matches.forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'owwa-styled-datalist-option';
                button.textContent = option;
                button.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    input.value = option;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    panel.hidden = true;
                    input.dispatchEvent(new FocusEvent('blur', { bubbles: true }));
                });
                panel.appendChild(button);
            });

            panel.hidden = false;
            place(input, panel);
        };

        const bind = (input) => {
            if (input._owwaSuggestPanel instanceof HTMLElement) {
                return;
            }

            input.setAttribute('autocomplete', `off-${input.id || 'catalog'}-${Math.random().toString(36).slice(2, 8)}`);

            const panel = document.createElement('div');
            panel.className = 'owwa-styled-datalist-panel';
            panel.style.position = 'fixed';
            panel.style.zIndex = '10000';
            panel.hidden = true;
            panel.setAttribute('role', 'listbox');
            document.body.appendChild(panel);
            input._owwaSuggestPanel = panel;
            bindings.add(input);

            input.addEventListener('focus', () => show(input));
            input.addEventListener('input', () => show(input));
            input.addEventListener('blur', () => {
                window.setTimeout(() => hide(input), 120);
            });
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    hide(input);
                }
            });
        };

        const scan = () => {
            document.querySelectorAll('input[data-owwa-saved-suggestions]').forEach((node) => {
                if (node instanceof HTMLInputElement) {
                    bind(node);
                }
            });

            bindings.forEach((input) => {
                if (input.isConnected) {
                    return;
                }

                input._owwaSuggestPanel?.remove();
                bindings.delete(input);
            });
        };

        let scheduled = false;
        const scheduleScan = () => {
            if (scheduled) {
                return;
            }

            scheduled = true;
            window.requestAnimationFrame(() => {
                scheduled = false;
                scan();
            });
        };

        document.addEventListener('scroll', () => {
            bindings.forEach((input) => hide(input));
        }, true);

        new MutationObserver(scheduleScan).observe(document.documentElement, {
            childList: true,
            subtree: true,
        });

        scheduleScan();
    })();
</script>
