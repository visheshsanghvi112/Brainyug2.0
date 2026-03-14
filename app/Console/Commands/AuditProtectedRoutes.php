<?php

namespace App\Console\Commands;

use App\Support\ErpModuleAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

class AuditProtectedRoutes extends Command
{
    protected $signature = 'erp:audit-protected-routes
        {--write : Write JSON report to storage/app/reports}
        {--fail-on-unmapped : Return non-zero exit code when unmapped protected routes are found}';

    protected $description = 'Audit protected named routes against ErpModuleAccess mappings for strict rollout readiness.';

    public function handle(): int
    {
        $protected = [];
        $mapped = [];
        $unmapped = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) ($route->getName() ?? '');
            if ($name === '' || !ErpModuleAccess::isProtectedRouteName($name)) {
                continue;
            }

            $methods = $this->normalizedMethods($route->methods());
            $requirementsByMethod = [];
            foreach ($methods as $method) {
                $requirementsByMethod[$method] = ErpModuleAccess::requiredForRoute($name, $method);
            }

            $protected[] = [
                'name' => $name,
                'methods' => $methods,
                'uri' => $route->uri(),
            ];

            if (in_array(null, $requirementsByMethod, true)) {
                $unmapped[] = [
                    'name' => $name,
                    'methods' => implode(',', $methods),
                    'uri' => $route->uri(),
                ];
                continue;
            }

            $firstRequirement = reset($requirementsByMethod);
            $actions = array_values(array_unique(array_map(
                static fn (array $requirement) => (string) $requirement['action'],
                $requirementsByMethod
            )));

            $mapped[] = [
                'name' => $name,
                'methods' => implode(',', $methods),
                'uri' => $route->uri(),
                'module' => $firstRequirement['module'],
                'action' => implode(',', $actions),
            ];
        }

        usort($mapped, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        usort($unmapped, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        $this->info(sprintf('Protected named routes: %d', count($protected)));
        $this->info(sprintf('Mapped protected routes: %d', count($mapped)));
        $this->line(sprintf('Unmapped protected routes: %d', count($unmapped)));

        if ($unmapped !== []) {
            $this->warn('Unmapped protected routes (strict mode would deny these):');
            $this->table(['Route', 'Methods', 'URI'], $unmapped);
        }

        if ($mapped !== []) {
            $this->line('Sample mapped routes:');
            $this->table(
                ['Route', 'Methods', 'Module', 'Action'],
                array_map(
                    static fn (array $row) => [
                        $row['name'],
                        $row['methods'],
                        $row['module'],
                        $row['action'],
                    ],
                    array_slice($mapped, 0, 25)
                )
            );
        }

        if ($this->option('write')) {
            $this->writeReport($mapped, $unmapped);
        }

        if ($this->option('fail-on-unmapped') && $unmapped !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function normalizedMethods(array $methods): array
    {
        return array_values(array_filter($methods, static fn (string $method) => $method !== 'HEAD'));
    }

    private function writeReport(array $mapped, array $unmapped): void
    {
        $directory = storage_path('app/reports');
        File::ensureDirectoryExists($directory);

        $filePath = $directory.'/module_route_audit_'.now()->format('Ymd_His').'.json';
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'strict_unmapped' => (bool) config('erp.module_access.strict_unmapped', false),
            'mapped_count' => count($mapped),
            'unmapped_count' => count($unmapped),
            'mapped' => $mapped,
            'unmapped' => $unmapped,
        ];

        File::put($filePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Wrote route audit report: '.$filePath);
    }
}
