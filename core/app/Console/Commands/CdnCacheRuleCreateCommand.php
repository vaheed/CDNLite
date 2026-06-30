<?php
namespace App\Console\Commands;
use App\Services\ControlPlane\TrafficRulesService; use App\Support\CommandIO;
class CdnCacheRuleCreateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||!isset($o['path_prefix'])||!isset($o['ttl_seconds'])){fwrite(STDERR,"Missing --domain_id/--path_prefix/--ttl_seconds\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->createCacheRule((string)$o['domain_id'],['enabled'=>($o['enabled']??'1')!=='0','path_prefix'=>(string)$o['path_prefix'],'ttl_seconds'=>(int)$o['ttl_seconds']])]); return 0; } }
