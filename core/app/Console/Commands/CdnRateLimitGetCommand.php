<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnRateLimitGetCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['site_id'])){fwrite(STDERR,"Missing --site_id\n"); return 1;} $row=(new TrafficRulesService())->getRateLimit((string)$o['site_id']); if($row===null){fwrite(STDERR,"rate_limit_not_found\n"); return 1;} CommandIO::printJson(['data'=>$row]); return 0; } }
