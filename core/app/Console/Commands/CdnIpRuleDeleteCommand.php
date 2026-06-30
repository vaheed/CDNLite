<?php
namespace App\Console\Commands;
use App\Services\ControlPlane\TrafficRulesService; use App\Support\CommandIO;
class CdnIpRuleDeleteCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['domain_id'])||empty($o['id'])){fwrite(STDERR,"Missing --domain_id/--id\n"); return 1;} $ok=(new TrafficRulesService())->deleteIpRule((string)$o['domain_id'],(string)$o['id']); if(!$ok){fwrite(STDERR,"IP rule not found\n"); return 1;} CommandIO::printJson(['ok'=>true]); return 0; } }
