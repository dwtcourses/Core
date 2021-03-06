<?php
/*
 * MikoPBX - free phone system for small business
 * Copyright (C) 2017-2020 Alexey Portnov and Nikolay Beketov
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program.
 * If not, see <https://www.gnu.org/licenses/>.
 */

namespace MikoPBX\Core\Workers;
require_once 'Globals.php';

use MikoPBX\Core\System\{Processes, Storage, Util};
use Throwable;


class WorkerRemoveOldRecords extends WorkerBase
{

    public function start($argv): void
    {
        $varEtcDir = $this->di->getShared('config')->path('core.varEtcDir');
        $filename   = "{$varEtcDir}/storage_device";
        if (file_exists($filename)) {
            $mount_point = file_get_contents($filename);
        } else {
            return;
        }
        $out = [];
        $busyboxPath = Util::which('busybox');
        $mountPath = Util::which('mount');
        $grepPath = Util::which('grep');
        $awkPath = Util::which('awk');
        $headPath = Util::which('head');
        Processes::mwExec("{$mountPath} | {$busyboxPath} {$grepPath} {$mount_point} | {$busyboxPath} {$awkPath} '{print $1}' | {$headPath} -n 1", $out);
        $dev = implode('', $out);

        $s          = new Storage();
        $MIN_SPACE  = 100; // MB
        $free_space = $s->getFreeSpace($dev);
        if ($free_space > $MIN_SPACE) {
            // Очистка диска не требуется.
            return;
        }
        $monitor_dir = Storage::getMonitorDir();
        $out         = [];
        //$count_dir   = 1;
        $busyboxPath = Util::which('busybox');
        $sortPath = Util::which('sort');
        $findPath = Util::which('find');
        $awkPath = Util::which('awk');
        $headPath = Util::which('head');
        Processes::mwExec(
            "{$findPath} {$monitor_dir}/*/*/*  -maxdepth 0 -type d  -printf '%T+ %p\n' 2> /dev/null | {$sortPath} | {$headPath} -n 10 | {$busyboxPath} {$awkPath} '{print $2}'",
            $out
        );
        foreach ($out as $dir_info) {
            if ( ! is_dir($dir_info)) {
                echo 'error';
                continue;
            }
            $free_space = $s->getFreeSpace($dev);
            if ($free_space > $MIN_SPACE) {
                // Очистка диска не требуется.
                break;
            }
            $busyboxPath = Util::which('busybox');
            $rmPath = Util::which('rm');
            Processes::mwExec("{$busyboxPath} {$rmPath} -rf {$dir_info}");
        }
    }
}

// Start worker process
$workerClassname = WorkerRemoveOldRecords::class;
if (isset($argv) && count($argv) > 1) {
    cli_set_process_title($workerClassname);
    try {
        $worker = new $workerClassname();
        $worker->start($argv);
    } catch (Throwable $e) {
        global $errorLogger;
        $errorLogger->captureException($e);
        Util::sysLogMsg("{$workerClassname}_EXCEPTION", $e->getMessage(), LOG_ERR);
    }
}