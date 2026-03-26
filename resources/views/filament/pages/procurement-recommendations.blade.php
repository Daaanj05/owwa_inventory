<x-filament-panels::page>

    {{-- Page intro card --}}
    <div class="owwa-pr-intro">
        <div class="owwa-pr-intro-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" />
            </svg>
        </div>
        <div class="owwa-pr-intro-text">
            <h2 class="owwa-pr-intro-title">AI-Powered Procurement Analysis</h2>
            <p class="owwa-pr-intro-desc">Analyzes real stock levels and consumption trends to generate evidence-based reorder suggestions using DeepSeek via Ollama.</p>
        </div>
        <div class="owwa-pr-intro-badges">
            <span class="owwa-pr-badge owwa-pr-badge-blue">
                <span class="owwa-pr-badge-dot"></span>
                RAG-enhanced
            </span>
            <span class="owwa-pr-badge owwa-pr-badge-slate">Up to 5 years of data</span>
        </div>
    </div>

    {{-- Category filter: options come from registered item categories --}}
    <div class="owwa-pr-filter">
        <label for="pr-category" class="owwa-pr-filter-label">Focus on category</label>
        <select id="pr-category" wire:model.live="categoryId" class="owwa-pr-filter-select">
            <option value="">All categories</option>
            @foreach($this->getItemCategories() as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>
        @if($categoryId)
            <span class="owwa-pr-filter-hint">Recommendations will include only items in the selected category.</span>
        @endif
    </div>

    @if($loading)
        {{-- Loading state --}}
        <div class="owwa-pr-loading">
            <div class="owwa-pr-loading-inner">
                <div class="owwa-pr-spinner">
                    <svg viewBox="0 0 50 50" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="25" cy="25" r="20" fill="none" stroke-width="4" stroke="currentColor" stroke-dasharray="80 40" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <p class="owwa-pr-loading-title">Analyzing inventory data…</p>
                    <p class="owwa-pr-loading-sub">Reviewing stock levels, consumption trends, and reorder points. This may take 30–120 seconds.</p>
                </div>
            </div>
            <div class="owwa-pr-loading-steps">
                <span class="owwa-pr-step owwa-pr-step-active">Fetching inventory context</span>
                <span class="owwa-pr-step-sep">→</span>
                <span class="owwa-pr-step owwa-pr-step-active">Running AI analysis</span>
                <span class="owwa-pr-step-sep">→</span>
                <span class="owwa-pr-step">Generating report</span>
            </div>
        </div>

    @elseif($recommendation)
        {{-- Result state --}}
        <div class="owwa-pr-result">
            <div class="owwa-pr-result-header">
                <div class="owwa-pr-result-header-left">
                    <div class="owwa-pr-result-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="owwa-pr-result-title">Procurement Recommendation</h3>
                        <p class="owwa-pr-result-meta">Generated {{ now()->format('M j, Y \a\t g:i A') }} · Based on current inventory data</p>
                    </div>
                </div>
                <span class="owwa-pr-status-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg>
                    AI Generated
                </span>
            </div>

            <div class="owwa-pr-result-body-wrap" x-data="{ expanded: false }">
            <div class="owwa-pr-result-body" :class="{ 'owwa-pr-result-body--collapsed': !expanded }">
                @php
                    // Strip DeepSeek's internal <think>…</think> reasoning blocks
                    $cleanedText = preg_replace('/<think>.*?<\/think>/s', '', $recommendation);
                    $cleanedText = trim($cleanedText);

                    // Inline markdown → HTML (bold, italic)
                    $inline = function (string $text): string {
                        $t = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
                        $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
                        $t = preg_replace('/\*(.+?)\*/',     '<em>$1</em>',         $t);
                        return $t;
                    };

                    $lines      = explode("\n", $cleanedText);
                    $html       = '';
                    $inList     = false;
                    $listType   = 'ul';
                    $inTable    = false;
                    $tableHtml  = '';
                    $inSection  = false; // are we inside a <div class="owwa-pr-section-body">?

                    $closeOpenBlocks = function () use (&$html, &$inList, &$listType, &$inTable, &$tableHtml, &$inSection) {
                        if ($inTable) {
                            $tableHtml .= '</tbody></table></div>';
                            $html      .= $tableHtml;
                            $tableHtml  = '';
                            $inTable    = false;
                        }
                        if ($inList) { $html .= "</{$listType}>"; $inList = false; }
                        if ($inSection) { $html .= '</div>'; $inSection = false; }
                    };

                    $ensureSection = function () use (&$html, &$inSection) {
                        if (! $inSection) {
                            $html      .= '<div class="owwa-pr-section-body">';
                            $inSection  = true;
                        }
                    };

                    foreach ($lines as $line) {
                        $t = trim($line);

                        if ($t === '') {
                            if ($inTable) {
                                $tableHtml .= '</tbody></table></div>';
                                $html      .= $tableHtml;
                                $tableHtml  = '';
                                $inTable    = false;
                            }
                            if ($inList) { $html .= "</{$listType}>"; $inList = false; }
                            continue;
                        }

                        // Skip horizontal rules: ---, ***, ===
                        if (preg_match('/^[-*=]{3,}$/', $t)) {
                            continue;
                        }

                        // Markdown table row: | col1 | col2 | ...
                        if (str_starts_with($t, '|') && str_contains($t, '|')) {
                            if ($inList) { $html .= "</{$listType}>"; $inList = false; }
                            if (! $inTable) {
                                $inTable   = true;
                                $tableHtml = '<div class="owwa-pr-table-ctn"><table class="owwa-pr-table"><tbody>';
                            }
                            $cells = array_map('trim', explode('|', trim($t)));
                            if ($cells && $cells[0] === '') array_shift($cells);
                            if ($cells && end($cells) === '') array_pop($cells);

                            // Skip separator rows like | --- | --- |
                            $allDashes = collect($cells)->every(fn ($c) => preg_match('/^[-:]+$/', $c));
                            if ($allDashes) continue;

                            // First row with word "priority" → header row
                            $isHeader = str_contains(strtolower(implode('', $cells)), 'priority');
                            $tag = $isHeader ? 'th' : 'td';
                            $rowHtml = '<tr>';
                            foreach ($cells as $cell) {
                                $rowHtml .= "<{$tag}>" . $inline($cell) . "</{$tag}>";
                            }
                            $tableHtml .= $rowHtml . '</tr>';
                            continue;
                        }

                        if ($inTable) {
                            $tableHtml .= '</tbody></table></div>';
                            $html      .= $tableHtml;
                            $tableHtml  = '';
                            $inTable    = false;
                        }

                        // Markdown heading (#, ##, ###) → new section label
                        if (preg_match('/^#{1,3}\s+(.+)/', $t, $m)) {
                            $closeOpenBlocks();
                            $html .= '<p class="owwa-pr-section-label">' . $inline($m[1]) . '</p>';
                            continue;
                        }

                        // Unordered list item
                        if (preg_match('/^[-•*]\s+(.+)/', $t, $m)) {
                            $ensureSection();
                            if (!$inList || $listType !== 'ul') {
                                if ($inList) $html .= "</{$listType}>";
                                $html .= '<ul>'; $inList = true; $listType = 'ul';
                            }
                            $html .= '<li>' . $inline($m[1]) . '</li>';
                            continue;
                        }

                        // Ordered list item
                        if (preg_match('/^\d+\.\s+(.+)/', $t, $m)) {
                            $ensureSection();
                            if (!$inList || $listType !== 'ol') {
                                if ($inList) $html .= "</{$listType}>";
                                $html .= '<ol>'; $inList = true; $listType = 'ol';
                            }
                            $html .= '<li>' . $inline($m[1]) . '</li>';
                            continue;
                        }

                        // Regular paragraph
                        if ($inList) { $html .= "</{$listType}>"; $inList = false; }
                        $ensureSection();
                        $html .= '<p>' . $inline($t) . '</p>';
                    }

                    // Close any still-open blocks
                    if ($inTable) { $tableHtml .= '</tbody></table></div>'; $html .= $tableHtml; }
                    if ($inList)  $html .= "</{$listType}>";
                    if ($inSection) $html .= '</div>';
                @endphp
                {!! $html !!}
            </div>

            {{-- Expand / collapse toggle --}}
            <div class="owwa-pr-expand-bar" x-show="!expanded" @click="expanded = true">
                <span class="owwa-pr-expand-fade"></span>
                <button type="button" class="owwa-pr-expand-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                    Show full report
                </button>
            </div>
            <div class="owwa-pr-collapse-bar" x-show="expanded" x-cloak @click="expanded = false">
                <button type="button" class="owwa-pr-expand-btn owwa-pr-expand-btn--collapse">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                    Collapse
                </button>
            </div>
            </div>{{-- end wrap --}}

            <div class="owwa-pr-result-footer">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                AI-generated analysis. Review and validate all recommendations before making procurement decisions.
            </div>
        </div>

    @else
        {{-- Idle state --}}
        <div class="owwa-pr-idle">
            <div class="owwa-pr-idle-graphic">
                <div class="owwa-pr-idle-circle">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z" />
                    </svg>
                </div>
            </div>
            <h3 class="owwa-pr-idle-title">Ready to analyze</h3>
            <p class="owwa-pr-idle-sub">Click <strong>Generate recommendation</strong> above to analyze current stock levels, consumption trends, and reorder points.</p>

        </div>
    @endif

</x-filament-panels::page>
