<?php
namespace App\Console\Commands;
use App\Services\ControlPlane\TrafficRulesService; use App\Support\CommandIO;
class CdnIpRuleUpdateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||empty($o['id'])){fwrite(STDERR,"Missing --domain_id/--id\n"); return 1;} $patch=[]; foreach(['rule_type','cidr','description'] as $k){ if(array_key_exists($k,$o)){$patch[$k]=(string)$o[$k];}} if(isset($o['enabled'])){$patch['enabled']=$o['enabled']!=='0';} if($patch===[]){fwrite(STDERR,"Missing update options\n"); return 1;} $row=(new TrafficRulesService())->updateIpRule((string)$o['domain_id'],(string)$o['id'],$patch); if(!$row){fwrite(STDERR,"IP rule not found\n"); return 1;} CommandIO::printJson(['data'=>$row]); return 0; } }
