@if ((int) config('inventory.idle_logout_minutes', 30) >= 1)
    @php
        $idleMinutes = (int) config('inventory.idle_logout_minutes', 30);
        $warningMinutes = (int) config('inventory.idle_warning_minutes', 5);
        $warningMinutes = min($warningMinutes, max(0, $idleMinutes - 1));
        $idleMs = $idleMinutes * 60 * 1000;
        $warningMs = $warningMinutes * 60 * 1000;
        $recoverUrl = route('session.recover', ['reason' => 'idle_timeout']);
    @endphp

    <div
        x-data="{
            idleMs: {{ $idleMs }},
            warningMs: {{ $warningMs }},
            warningOpen: false,
            secondsLeft: 0,
            timer: null,
            warningTimer: null,
            countdown: null,
            resetIdle() {
                clearTimeout(this.timer);
                clearTimeout(this.warningTimer);
                clearInterval(this.countdown);
                this.countdown = null;
                this.warningOpen = false;
                this.secondsLeft = 0;
                if (this.warningMs > 0 && this.idleMs > this.warningMs) {
                    this.warningTimer = setTimeout(() => {
                        this.warningOpen = true;
                        this.secondsLeft = Math.ceil((this.idleMs - this.warningMs) / 1000);
                        this.countdown = setInterval(() => {
                            this.secondsLeft--;
                            if (this.secondsLeft <= 0) {
                                clearInterval(this.countdown);
                                this.countdown = null;
                            }
                        }, 1000);
                    }, this.idleMs - this.warningMs);
                }
                this.timer = setTimeout(() => this.logout(), this.idleMs);
            },
            logout() {
                clearTimeout(this.timer);
                clearTimeout(this.warningTimer);
                clearInterval(this.countdown);
                // Navigate to recover (GET logout). Do not POST with the page CSRF —
                // a stale token returns 419 and leaves the user signed in.
                window.location.replace(@js($recoverUrl));
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
