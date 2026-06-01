<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnCacheRuleCreateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['site_id'])||!isset($o['path_prefix'])||!isset($o['ttl_seconds'])){fwrite(STDERR,"Missing --site_id/--path_prefix/--ttl_seconds\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->createCacheRule((string)$o['site_id'],['enabled'=>($o['enabled']??'1')!=='0','path_prefix'=>(string)$o['path_prefix'],'ttl_seconds'=>(int)$o['ttl_seconds']])]); return 0; } }
