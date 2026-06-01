<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnWafCreateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['site_id'])||empty($o['type'])||!isset($o['pattern'])){fwrite(STDERR,"Missing --site_id/--type/--pattern\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->createWaf((string)$o['site_id'],['enabled'=>($o['enabled']??'1')!=='0','type'=>(string)$o['type'],'pattern'=>(string)$o['pattern']])]); return 0; } }
