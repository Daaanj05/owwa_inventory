@if ((int) config('inventory.idle_logout_minutes', 30) >= 1)
    @php
        $idleMinutes = (int) config('inventory.idle_logout_minutes', 30);
        $warningMinutes = (int) config('inventory.idle_warning_minutes', 5);
        $warningMinutes = min($warningMinutes, max(0, $idleMinutes - 1));
        $idleMs = $idleMinutes * 60 * 1000;
        $warningMs = $warningMinutes * 60 * 1000;
        $logoutUrl = route('audit.idle-logout');
        $loginUrl = $loginUrl ?? url('/');
    @endphp

    <div
        x-data="{
            idleMs: {{ $idleMs }},
            warningMs: {{ $warningMs }},
            warningOpen: false,
            secondsLeft: 0,
            timer: null,
            warningTimer: null,
            resetIdle() {
                clearTimeout(this.timer);
                clearTimeout(this.warningTimer);
                this.warningOpen = false;
                if (this.warningMs > 0 && this.idleMs > this.warningMs) {
                    this.warningTimer = setTimeout(() => {
                        this.warningOpen = true;
                        this.secondsLeft = Math.ceil((this.idleMs - this.warningMs) / 1000);
                        const countdown = setInterval(() => {
                            this.secondsLeft--;
                            if (this.secondsLeft <= 0) {
                                clearInterval(countdown);
                            }
                        }, 1000);
                    }, this.idleMs - this.warningMs);
                }
                this.timer = setTimeout(() => this.logout(), this.idleMs);
            },
            async logout() {
                const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content');
                const body = new URLSearchParams();
                body.set('_token', token ?? '');
                body.set('redirect', @js($loginUrl));
                await fetch(@js($logoutUrl), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'Accept': 'text/html',
                    },
                    body: body.toString(),
                    credentials: 'same-origin',
                });
                window.location.href = @js($loginUrl);
            },
            init() {
                const events = ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click'];
                events.forEach((event) => window.addEventListener(event, () => this.resetIdle(), { passive: true }));
                this.resetIdle();
            },
        }"
        x-cloak
        class="owwa-idle-logout-root"
    >
        <div
            x-show="warningOpen"
            x-transition
            class="owwa-idle-logout-backdrop"
            role="dialog"
            aria-modal="true"
            aria-labelledby="owwa-idle-logout-title"
        >
            <div class="owwa-idle-logout-modal">
                <h2 id="owwa-idle-logout-title" class="owwa-idle-logout-title">Still there?</h2>
                <p class="owwa-idle-logout-text">
                    You will be signed out due to inactivity
                    <span x-show="secondsLeft > 0">in <strong x-text="secondsLeft"></strong> seconds</span>.
                </p>
                <div class="owwa-idle-logout-actions">
                    <button type="button" class="owwa-idle-logout-btn owwa-idle-logout-btn--primary" @click="resetIdle()">
                        Stay signed in
                    </button>
                    <button type="button" class="owwa-idle-logout-btn" @click="logout()">
                        Sign out now
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
