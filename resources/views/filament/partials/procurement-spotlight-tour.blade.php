<script data-navigate-once>
    if (! window.procurementSpotlightTour) {
        window.procurementSpotlightTour = function (slides) {
            const steps = [
                {
                    key: 'period',
                    selector: '[data-tour="period"]',
                    title: 'Last 12 months',
                    body: 'Suggested reorders and replacement reviews use the last 12 months through today. Property, plant, and equipment stay out of this page.',
                },
                {
                    key: 'kpis',
                    selector: '[data-tour="kpis"]',
                    title: 'Risk cards',
                    body: 'These cards count the pairs that need attention. High means under about 1 month of cover or at or below reorder. Medium means about 1–3 months.',
                },
                {
                    key: 'reorders',
                    selector: '[data-tour="reorders"]',
                    slide: 'reorders',
                    title: 'Suggested reorders',
                    body: 'This list compares consumable stock with issuance pace and the reorder point.',
                },
                {
                    key: 'filters',
                    selector: '[data-tour="filters"]',
                    slide: 'reorders',
                    title: 'List filters',
                    body: 'All at-risk shows every pair that needs attention. Stocking out in 2 months shows items projected to run out within 2 months, plus high-priority items at or below reorder with no recent usage.',
                },
                {
                    key: 'useful-life',
                    selector: '[data-tour="useful-life"]',
                    slide: 'replacement',
                    title: 'Useful life',
                    body: 'These are issued semi-expendable units nearing or past useful life. A useful-life date alone does not create a purchase. The arrows stay blocked; this tour is what moves the list.',
                },
                {
                    key: 'export',
                    selector: '[data-tour="export"]',
                    title: 'Export PDF',
                    body: 'This downloads the list currently on screen. The button does not run during the tour.',
                },
                {
                    key: 'generate',
                    selector: '[data-tour="generate"]',
                    title: 'Generate recommendation',
                    body: 'This builds a recommendation from the consumable reorder list and the semi-expendable replacement list. Clicking it during the tour does not start a run.',
                },
            ];

            return {
                slides: Array.isArray(slides) ? slides : [],
                steps,
                activeSteps: [],
                open: false,
                index: 0,
                token: 0,
                settling: false,
                returnFocus: null,
                hole: { top: 0, left: 0, width: 0, height: 0, ready: false },
                cardTop: 16,
                cardLeft: 16,
                onKeydown: null,
                onLayout: null,
                offMorphed: null,
                get holeStyle() {
                    return 'top:' + this.hole.top + 'px;left:' + this.hole.left + 'px;width:' + this.hole.width + 'px;height:' + this.hole.height + 'px;';
                },
                get cardStyle() {
                    return 'top:' + this.cardTop + 'px;left:' + this.cardLeft + 'px;';
                },
                get progressLabel() {
                    if (this.activeSteps.length === 0) {
                        return '';
                    }

                    return (this.index + 1) + ' of ' + this.activeSteps.length;
                },
                get isLast() {
                    return this.activeSteps.length > 0 && this.index === this.activeSteps.length - 1;
                },
                init() {
                    this.onKeydown = (event) => {
                        if (event.key === 'Escape' && this.open) {
                            event.preventDefault();
                            this.close();
                        }
                    };
                    this.onLayout = () => {
                        if (this.open && ! this.settling) {
                            this.measure();
                        }
                    };
                    window.addEventListener('keydown', this.onKeydown);
                    window.addEventListener('resize', this.onLayout);
                    window.addEventListener('scroll', this.onLayout, true);
                    if (window.Livewire && typeof window.Livewire.hook === 'function') {
                        this.offMorphed = window.Livewire.hook('morphed', ({ el }) => {
                            if (! this.open || this.settling) {
                                return;
                            }
                            const step = this.currentStep();
                            const target = step ? document.querySelector(step.selector) : null;
                            if (! target || ! el || ! el.contains(target)) {
                                return;
                            }
                            this.measure();
                        });
                    }
                },
                destroy() {
                    this.unlockPage();
                    window.removeEventListener('keydown', this.onKeydown);
                    window.removeEventListener('resize', this.onLayout);
                    window.removeEventListener('scroll', this.onLayout, true);
                    if (typeof this.offMorphed === 'function') {
                        this.offMorphed();
                    }
                },
                currentStep() {
                    return this.activeSteps[this.index] ?? null;
                },
                start() {
                    if (this.open) {
                        return;
                    }
                    this.returnFocus = this.$refs.startButton;
                    this.activeSteps = this.steps.filter((step) => this.stepAvailable(step));
                    if (this.activeSteps.length === 0) {
                        return;
                    }
                    this.index = 0;
                    this.open = true;
                    this.hole.ready = false;
                    this.lockPage();
                    this.applyStep();
                },
                stepAvailable(step) {
                    if (step.slide && ! this.slides.includes(step.slide)) {
                        return false;
                    }

                    return document.querySelector(step.selector) !== null;
                },
                lockPage() {
                    document.querySelectorAll('.fi-layout, .fi-topbar-ctn').forEach((element) => {
                        element.setAttribute('inert', '');
                    });
                },
                unlockPage() {
                    document.querySelectorAll('.fi-layout, .fi-topbar-ctn').forEach((element) => {
                        element.removeAttribute('inert');
                    });
                },
                close() {
                    if (! this.open) {
                        return;
                    }
                    this.token += 1;
                    this.open = false;
                    this.settling = false;
                    this.hole.ready = false;
                    this.unlockPage();
                    this.$nextTick(() => {
                        this.returnFocus?.focus();
                    });
                },
                next() {
                    if (this.isLast) {
                        this.close();
                        return;
                    }
                    this.goTo(this.index + 1);
                },
                back() {
                    if (this.index === 0) {
                        return;
                    }
                    this.goTo(this.index - 1);
                },
                goTo(nextIndex) {
                    if (nextIndex < 0 || nextIndex >= this.activeSteps.length) {
                        this.close();
                        return;
                    }
                    this.index = nextIndex;
                    this.applyStep();
                },
                async applyStep() {
                    const token = ++this.token;
                    const step = this.currentStep();
                    if (! step) {
                        this.close();
                        return;
                    }
                    if (! document.querySelector(step.selector) && ! step.slide) {
                        this.goTo(this.index + 1);
                        return;
                    }
                    this.settling = true;
                    this.hole.ready = false;
                    const already = this.slideReady(step);
                    if (step.slide) {
                        window.dispatchEvent(new CustomEvent('pa-tour-slide', { detail: step.slide }));
                    }
                    await this.waitUntil(() => this.slideReady(step) && document.querySelector(step.selector) !== null, 2000);
                    if (token !== this.token) {
                        return;
                    }
                    if (! already) {
                        await this.sleep(420);
                    }
                    if (token !== this.token) {
                        return;
                    }
                    const target = document.querySelector(step.selector);
                    if (! target) {
                        this.settling = false;
                        this.goTo(this.index + 1);
                        return;
                    }
                    this.scrollTargetIntoView(target);
                    await this.sleep(50);
                    if (token !== this.token) {
                        return;
                    }
                    this.settling = false;
                    this.measure();
                    this.$nextTick(() => {
                        this.$refs.card?.focus({ preventScroll: true });
                    });
                },
                slideReady(step) {
                    if (! step.slide) {
                        return true;
                    }
                    const track = document.querySelector('.owwa-pa-slide-track');
                    if (! track) {
                        return false;
                    }
                    const onReplacement = track.classList.contains('is-replacement');

                    return step.slide === 'replacement' ? onReplacement : ! onReplacement;
                },
                contentLeft() {
                    const sidebar = document.querySelector('.fi-sidebar');
                    if (! sidebar) {
                        return 0;
                    }
                    const box = sidebar.getBoundingClientRect();
                    if (box.width < 8 || box.right <= 0) {
                        return 0;
                    }

                    return box.right;
                },
                scrollTargetIntoView(target) {
                    const scrollingElement = document.scrollingElement || document.documentElement;
                    const topbar = document.querySelector('.fi-topbar-ctn');
                    const topGap = (topbar ? topbar.getBoundingClientRect().height : 0) + 16;
                    const rect = target.getBoundingClientRect();
                    const above = rect.top < topGap;
                    const below = rect.top > window.innerHeight - 80;
                    if (! above && ! below) {
                        return;
                    }
                    const before = scrollingElement.scrollTop;
                    const delta = rect.top - topGap;
                    scrollingElement.scrollTop = before + delta;
                    if (Math.abs(scrollingElement.scrollTop - before) < 1) {
                        window.scrollBy(0, delta);
                    }
                },
                measure() {
                    const step = this.currentStep();
                    const target = step ? document.querySelector(step.selector) : null;
                    if (! this.open || ! target) {
                        return;
                    }
                    const rect = target.getBoundingClientRect();
                    const pad = 8;
                    const minLeft = this.contentLeft();
                    const minTop = 8;
                    const left = Math.max(rect.left, minLeft);
                    const top = Math.max(rect.top, minTop);
                    const right = Math.min(rect.right, window.innerWidth - 8);
                    const bottom = Math.min(rect.bottom, window.innerHeight - 8);
                    if (right - left < 16 || bottom - top < 16) {
                        return;
                    }
                    if (rect.left < minLeft - 24) {
                        return;
                    }
                    this.hole = {
                        top: top - pad,
                        left: left - pad,
                        width: right - left + (pad * 2),
                        height: bottom - top + (pad * 2),
                        ready: true,
                    };
                    const margin = 12;
                    const cardWidth = 352;
                    const cardHeight = 230;
                    let cardTop = this.hole.top + this.hole.height + margin;
                    if (cardTop + cardHeight > window.innerHeight - margin) {
                        const above = this.hole.top - cardHeight - margin;
                        cardTop = above >= margin ? above : margin;
                    }
                    let cardLeft = this.hole.left;
                    if (cardLeft + cardWidth > window.innerWidth - margin) {
                        cardLeft = window.innerWidth - cardWidth - margin;
                    }
                    this.cardTop = Math.max(margin, cardTop);
                    this.cardLeft = Math.max(margin, cardLeft);
                    this.$nextTick(() => this.placeCard());
                },
                placeCard() {
                    const card = this.$refs.card;
                    if (! card || ! this.open) {
                        return;
                    }
                    const margin = 12;
                    const width = card.offsetWidth;
                    const height = card.offsetHeight;
                    let top = this.hole.top + this.hole.height + margin;
                    if (top + height > window.innerHeight - margin) {
                        const above = this.hole.top - height - margin;
                        top = above >= margin ? above : margin;
                    }
                    let left = this.hole.left;
                    if (left + width > window.innerWidth - margin) {
                        left = window.innerWidth - width - margin;
                    }
                    this.cardTop = Math.max(margin, top);
                    this.cardLeft = Math.max(margin, left);
                },
                trapFocus(event) {
                    const card = this.$refs.card;
                    if (! card) {
                        return;
                    }
                    const nodes = [...card.querySelectorAll('button:not([disabled])')];
                    if (nodes.length === 0) {
                        return;
                    }
                    const first = nodes[0];
                    const last = nodes[nodes.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (! event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                },
                waitUntil(check, timeout) {
                    const started = performance.now();

                    return new Promise((resolve) => {
                        const tick = () => {
                            if (check() || performance.now() - started > timeout) {
                                resolve();
                                return;
                            }
                            requestAnimationFrame(tick);
                        };
                        tick();
                    });
                },
                sleep(ms) {
                    return new Promise((resolve) => {
                        setTimeout(resolve, ms);
                    });
                },
            };
        };
    }
</script>
