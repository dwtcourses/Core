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

use MikoPBX\Core\Asterisk\AsteriskManager;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\System\Processes;
use Phalcon\Di;
use Phalcon\Text;

abstract class WorkerBase extends Di\Injectable implements WorkerInterface
{
    protected AsteriskManager $am;
    public int $maxProc = 1;
    protected bool $needRestart = false;

    /**
     * Workers shared constructor
     * Do not remove FINAL there, use START function to add something
     */
    final public function __construct()
    {
        pcntl_async_signals(true);
        pcntl_signal(
            SIGUSR1,
            [$this, 'signalHandler'],
            false
        );
        register_shutdown_function( [$this, 'shutdownHandler']);
        $this->savePidFile();
    }

    /**
     * Saves pid to pidfile
     */
    private function savePidFile(): void
    {
        $activeProcesses = Processes::getPidOfProcess(static::class);
        $processes       = explode(' ', $activeProcesses);
        if (count($processes) === 1) {
            file_put_contents($this->getPidFile(), $activeProcesses);
        } else {
            $pidFilesDir = dirname($this->getPidFile());
            $pidFile     = $pidFilesDir . '/' . pathinfo($this->getPidFile(), PATHINFO_BASENAME);
            // Delete old PID files
            $rm = Util::which('rm');
            Processes::mwExec("{$rm} -rf {$pidFile}*");
            $i = 1;
            foreach ($processes as $process) {
                file_put_contents("{$pidFile}-{$i}.pid", $process);
                $i++;
            }
        }
    }

    /**
     * Create PID file for worker
     */
    public function getPidFile(): string
    {
        $name = str_replace("\\", '-', static::class);

        return "/var/run/{$name}.pid";
    }

    /**
     * Process async system signal
     *
     * @param int $signal
     */
    public function signalHandler(int $signal): void
    {
        Util::sysLogMsg(static::class, "Receive signal to restart  ".$signal, LOG_DEBUG);
        $this->needRestart = true;
    }

    /**
     * Process shutdown event
     *
     */
    public function shutdownHandler(): void
    {
        $e = error_get_last();
        Util::sysLogMsg(static::class, "shutdownHandler ". print_r($e,true), LOG_DEBUG);
    }


    /**
     * Ping callback for keep alive check
     *
     * @param BeanstalkClient $message
     */
    public function pingCallBack(BeanstalkClient $message): void
    {
        $message->reply(json_encode($message->getBody() . ':pong'));
    }

    /**
     * If it was Ping request to check worker, we answer Pong and return True
     *
     * @param $parameters
     *
     * @return bool
     */
    public function replyOnPingRequest($parameters): bool
    {
        $pingTube = $this->makePingTubeName(static::class);
        if ($pingTube === $parameters['UserEvent']) {
            $this->am->UserEvent("{$pingTube}Pong", []);

            return true;
        }

        return false;
    }

    /**
     * Makes ping tube from classname and ping word
     *
     * @param string $workerClassName
     *
     * @return string
     */
    public function makePingTubeName(string $workerClassName): string
    {
        return Text::camelize("ping_{$workerClassName}", '\\');
    }

    /**
     * Deletes old PID files
     */
    public function __destruct()
    {
        $this->savePidFile();
    }
}