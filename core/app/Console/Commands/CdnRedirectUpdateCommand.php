<?php
namespace App\Console\Commands;
use App\Modules\Proxy\Services\TrafficRulesService; use App\Support\CommandIO;
class CdnRedirectUpdateCommand { public function __invoke(array $argv): int { $o=CommandIO::parseOptions($argv); if(empty($o['site_id'])||empty($o['id'])){fwrite(STDERR,"Missing --site_id/--id\n"); return 1;} $in=[]; foreach(['enabled','source_path','target_url','status_code'] as $k){ if(isset($o[$k])){$in[$k]=$k==='enabled'?($o[$k]!=='0'):($k==='status_code'?(int)$o[$k]:(string)$o[$k]);}} $row=(new TrafficRulesService())->updateRedirect((string)$o['site_id'],(string)$o['id'],$in); if($row===null){fwrite(STDERR,"redirect_not_found\n"); return 1;} CommandIO::printJson(['data'=>$row]); return 0; } }
