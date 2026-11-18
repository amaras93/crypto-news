<?php

namespace App\Console\Commands;

use App\Support\ProductionReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:check-production-readiness {--json : Emit configuration checks as JSON without secret values}')]
#[Description('Read-only production configuration checks; does not certify deployment readiness or contact providers')]
class CheckProductionReadiness extends Command
{
    public function handle(ProductionReadiness $readiness): int
    {
        $checks = $readiness->checks();
        $scope = 'Configuration only. External verification remains required: MySQL integration, HTTPS/public web root, shared locks/workers, browser QA, SMTP and backup restore.';
        if ($this->option('json')) {
            $this->line(json_encode(['scope' => $scope, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->line($scope);
            $this->table(['Check', 'Status', 'Severity', 'Requirement'], array_map(fn (array $check): array => array_values($check), $checks));
        }

        return collect($checks)->contains(fn (array $check): bool => $check['status'] === 'FAIL' && $check['severity'] === 'P1') ? self::FAILURE : self::SUCCESS;
    }
}
