<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnRateLimitDisableCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])){fwrite(STDERR,"Missing --domain_id\n"); return 1;} $ok=(new TrafficRulesService())->disableRateLimit((string)$o['domain_id']); if(!$ok){fwrite(STDERR,"rate_limit_not_found\n"); return 1;} CommandIO::printJson(['ok'=>true]); return 0; } }
