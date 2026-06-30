<?php
namespace App\Console\Commands;
use App\Services\ControlPlane\TrafficRulesService; use App\Support\CommandIO;
class CdnWafCreateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||empty($o['type'])||!isset($o['pattern'])){fwrite(STDERR,"Missing --domain_id/--type/--pattern\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->createWaf((string)$o['domain_id'],['enabled'=>($o['enabled']??'1')!=='0','type'=>(string)$o['type'],'pattern'=>(string)$o['pattern']])]); return 0; } }
