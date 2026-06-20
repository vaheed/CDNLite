<?php

namespace App\Console\Commands;

use App\Modules\Recommendations\Services\RecommendationService;
use App\Support\CommandIO;

class CdnRecommendationsGenerateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = isset($opts['domain_id']) ? (string) $opts['domain_id'] : null;
        CommandIO::printJson((new RecommendationService())->generate($domainId));
        return 0;
    }
}
