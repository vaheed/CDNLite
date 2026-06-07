<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnIpRuleCreateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||empty($o['rule_type'])||empty($o['cidr'])){fwrite(STDERR,"Missing --domain_id/--rule_type/--cidr\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->createIpRule((string)$o['domain_id'],['enabled'=>($o['enabled']??'1')!=='0','rule_type'=>(string)$o['rule_type'],'cidr'=>(string)$o['cidr'],'description'=>$o['description']??null])]); return 0; } }
