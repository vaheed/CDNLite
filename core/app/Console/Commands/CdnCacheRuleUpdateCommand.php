<?php
namespace App\Console\Commands;
use App\Services\ControlPlane\TrafficRulesService; use App\Support\CommandIO;
class CdnCacheRuleUpdateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||empty($o['id'])){fwrite(STDERR,"Missing --domain_id/--id\n"); return 1;} $in=[]; foreach(['enabled','path_prefix','ttl_seconds'] as $k){ if(isset($o[$k])){$in[$k]=$k==='enabled'?($o[$k]!=='0'):($k==='ttl_seconds'?(int)$o[$k]:(string)$o[$k]);}} $row=(new TrafficRulesService())->updateCacheRule((string)$o['domain_id'],(string)$o['id'],$in); if($row===null){fwrite(STDERR,"cache_rule_not_found\n"); return 1;} CommandIO::printJson(['data'=>$row]); return 0; } }
