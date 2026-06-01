<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnCacheRuleListCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['site_id'])){fwrite(STDERR,"Missing --site_id\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->listCacheRules((string)$o['site_id'])]); return 0; } }
