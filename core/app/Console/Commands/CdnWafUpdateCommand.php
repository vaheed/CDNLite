<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnWafUpdateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||empty($o['id'])){fwrite(STDERR,"Missing --domain_id/--id\n"); return 1;} $in=[]; foreach(['enabled','type','pattern'] as $k){ if(isset($o[$k])){$in[$k]=$k==='enabled'?($o[$k]!=='0'):(string)$o[$k];}} $row=(new TrafficRulesService())->updateWaf((string)$o['domain_id'],(string)$o['id'],$in); if($row===null){fwrite(STDERR,"waf_not_found\n"); return 1;} CommandIO::printJson(['data'=>$row]); return 0; } }
