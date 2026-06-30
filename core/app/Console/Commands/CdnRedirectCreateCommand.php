<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\TrafficRulesService;
use App\Support\CommandIO;

class CdnRedirectCreateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||empty($o['source_path'])||empty($o['target_url'])){fwrite(STDERR,"Missing --domain_id/--source_path/--target_url\n"); return 1;} CommandIO::printJson(['data'=>(new TrafficRulesService())->createRedirect((string)$o['domain_id'],['enabled'=>($o['enabled']??'1')!=='0','source_path'=>(string)$o['source_path'],'target_url'=>(string)$o['target_url'],'status_code'=>(int)($o['status_code']??302)])]); return 0; } }
