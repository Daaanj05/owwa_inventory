<?php

namespace App\Console\Commands;

use App\Support\OwwaCellMapping;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

class AuditOwwaTemplates extends Command
{
    protected $signature = 'app:audit-owwa-templates {--json : Print JSON summary}';

    protected $description = 'Audit template usage, duplicates, and instruction coverage against OWWA export mappings.';

    public function handle(): int
    {
        $templatesRoot = storage_path('app/templates');
        $config = (array) config('owwa_templates', []);
        $templateFiles = $this->collectTemplateFiles($templatesRoot);
        $configuredFiles = $this->collectConfiguredFiles($config);

        $missingConfiguredTemplates = $configuredFiles
            ->filter(fn (string $path): bool => ! is_file($templatesRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path)))
            ->values();

        $excelFiles = $templateFiles
            ->filter(fn (string $path): bool => preg_match('/\.(xls|xlsx|xlsm)$/i', $path) === 1)
            ->values();

        $instructionFiles = $templateFiles
            ->filter(fn (string $path): bool => preg_match('/instructions?\s*-\s*.*\.(doc|docx|pdf)$/i', basename($path)) === 1)
            ->values();

        $excelWithoutInstructions = $excelFiles
            ->filter(function (string $excel) use ($instructionFiles): bool {
                $base = $this->extractFormToken(pathinfo($excel, PATHINFO_FILENAME));

                return $instructionFiles->first(function (string $instruction) use ($base): bool {
                    $instructionStem = $this->extractFormToken(
                        preg_replace('/^.*instructions?\s*-\s*/i', '', pathinfo($instruction, PATHINFO_FILENAME)) ?? ''
                    );

                    return $instructionStem === $base;
                }) === null;
            })
            ->values();

        $hashGroups = $this->collectHashGroups($templatesRoot, $templateFiles);
        $duplicateContentGroups = $hashGroups
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->values();

        $unusedExcelTemplates = $excelFiles
            ->filter(function (string $excel) use ($configuredFiles): bool {
                if ($configuredFiles->contains($excel)) {
                    return false;
                }

                return ! str_contains($excel, 'Instructions');
            })
            ->values();

        $summary = [
            'configured_count' => $configuredFiles->count(),
            'excel_count' => $excelFiles->count(),
            'instruction_count' => $instructionFiles->count(),
            'cell_map_form_codes' => OwwaCellMapping::configuredFormCodes(),
            'missing_configured_templates' => $missingConfiguredTemplates->all(),
            'excel_without_instruction' => $excelWithoutInstructions->all(),
            'unused_excel_templates' => $unusedExcelTemplates->all(),
            'duplicate_content_groups' => $duplicateContentGroups->map(
                fn (Collection $group): array => $group->all()
            )->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        $this->info('OWWA template audit summary');
        $this->line('Configured templates: '.$configuredFiles->count());
        $this->line('Excel templates: '.$excelFiles->count());
        $this->line('Instruction files: '.$instructionFiles->count());
        $this->line('Cell map form codes: '.implode(', ', OwwaCellMapping::configuredFormCodes()));

        $this->printList('Missing configured templates', $missingConfiguredTemplates);
        $this->printList('Excel without instructions', $excelWithoutInstructions);
        $this->printList('Unused excel templates', $unusedExcelTemplates);

        if ($duplicateContentGroups->isEmpty()) {
            $this->line(PHP_EOL.'Duplicate content groups: none');
        } else {
            $this->line(PHP_EOL.'Duplicate content groups:');
            foreach ($duplicateContentGroups as $index => $group) {
                $this->line('  Group '.($index + 1).':');
                foreach ($group as $path) {
                    $this->line('    - '.$path);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    protected function collectTemplateFiles(string $templatesRoot): Collection
    {
        $finder = new Finder;
        $finder->files()->in($templatesRoot);

        return collect($finder)
            ->map(function (\SplFileInfo $file) use ($templatesRoot): string {
                return str_replace('\\', '/', Str::after($file->getPathname(), $templatesRoot.DIRECTORY_SEPARATOR));
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return Collection<int, string>
     */
    protected function collectConfiguredFiles(array $config): Collection
    {
        $files = collect();

        $walk = function (mixed $node) use (&$walk, $files): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['file']) && is_string($node['file'])) {
                $files->push(str_replace('\\', '/', $node['file']));
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };

        $walk($config);

        return $files->unique()->values();
    }

    protected function normalizeTemplateStem(string $name): string
    {
        return Str::lower(
            trim((string) preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $name)))
        );
    }

    protected function extractFormToken(string $name): string
    {
        $candidate = trim($name);

        if (preg_match('/instructions?\s*-\s*(.+)$/i', $candidate, $match) === 1) {
            $candidate = trim($match[1]);
        } elseif (preg_match('/-\s*([^-\n\r]+)$/', $candidate, $match) === 1) {
            $candidate = trim($match[1]);
        }

        return $this->normalizeTemplateStem($candidate);
    }

    /**
     * @param  Collection<int, string>  $paths
     * @return Collection<string, Collection<int, string>>
     */
    protected function collectHashGroups(string $templatesRoot, Collection $paths): Collection
    {
        return $paths
            ->filter(fn (string $path): bool => preg_match('/\.(xls|xlsx|xlsm|doc|docx|pdf)$/i', $path) === 1)
            ->map(function (string $path) use ($templatesRoot): array {
                $absolute = $templatesRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

                return [
                    'hash' => hash_file('sha256', $absolute),
                    'path' => $path,
                ];
            })
            ->groupBy(fn (array $row): string => $row['hash'])
            ->map(fn (Collection $group): Collection => $group->pluck('path')->values());
    }

    /**
     * @param  Collection<int, string>  $items
     */
    protected function printList(string $title, Collection $items): void
    {
        if ($items->isEmpty()) {
            $this->line(PHP_EOL.$title.': none');

            return;
        }

        $this->line(PHP_EOL.$title.':');
        foreach ($items as $item) {
            $this->line('  - '.$item);
        }
    }
}
