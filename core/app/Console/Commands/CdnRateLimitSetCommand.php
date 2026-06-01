<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnRateLimitSetCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['site_id'])||empty($o['requests_per_minute'])){fwrite(STDERR,"Missing --site_id/--requests_per_minute\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->setRateLimit((string)$o['site_id'],['enabled'=>($o['enabled']??'1')!=='0','requests_per_minute'=>(int)$o['requests_per_minute']])]); return 0; } }
