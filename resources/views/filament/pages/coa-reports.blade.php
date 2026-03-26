<x-filament-panels::page>
    <div class="owwa-page-intro">
        Commission on Audit (COA) compliant reports for OWWA inventory. Download PDF copies for audit submission and official records.
    </div>

    <div class="owwa-report-grid">
        {{-- Stock Level Report --}}
        <div class="owwa-report-card">
            <div class="owwa-report-card-icon owwa-report-icon-blue">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                </svg>
            </div>
            <div class="owwa-report-card-body">
                <h3 class="owwa-report-card-title">Stock level report</h3>
                <p class="owwa-report-card-desc">Current inventory stock by item and office, including reorder points and low-stock indicators.</p>
                <div class="owwa-report-card-meta">
                    <span class="owwa-badge owwa-badge-blue">PDF</span>
                    <span class="owwa-report-meta-text">Real-time snapshot</span>
                </div>
            </div>
            <div class="owwa-report-card-action">
                <a href="{{ route('reports.coa.stock-level') }}" class="owwa-report-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Download
                </a>
            </div>
        </div>

        {{-- Issuance Report --}}
        <div class="owwa-report-card">
            <div class="owwa-report-card-icon owwa-report-icon-indigo">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" />
                </svg>
            </div>
            <div class="owwa-report-card-body">
                <h3 class="owwa-report-card-title">Issuance report</h3>
                <p class="owwa-report-card-desc">Full issuance transaction history with reference codes, dates, items, and recipient departments.</p>
                <div class="owwa-report-card-meta">
                    <span class="owwa-badge owwa-badge-blue">PDF</span>
                    <span class="owwa-report-meta-text">All transactions</span>
                </div>
            </div>
            <div class="owwa-report-card-action">
                <a href="{{ route('reports.coa.issuance') }}" class="owwa-report-btn">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Download
                </a>
            </div>
        </div>
    </div>

    <p class="owwa-page-note">
        Reports are generated from live data. For audit submission, download immediately before the reporting date to capture the current state.
    </p>
</x-filament-panels::page>
