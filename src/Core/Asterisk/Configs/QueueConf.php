<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 9 2020
 */

namespace MikoPBX\Core\Asterisk\Configs;

use MikoPBX\Common\Models\CallQueues;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Core\System\{Util};

class QueueConf extends ConfigClass
{
    protected string $description = 'queues.conf';


    /**
     * Создание конфига для очередей.
     *
     *
     * @return void
     */
    protected function generateConfigProtected() :void
    {
        $this->extensionGenInternal();
        // Генерация конфигурационных файлов.
        $q_conf = '';
        $db_data = $this->getQueueData();
        foreach ($db_data as $queue_data) {
            $joinempty        = (isset($queue_data['joinempty']) && $queue_data['joinempty'] == 1) ? 'yes' : 'no';
            $leavewhenempty   = (isset($queue_data['leavewhenempty']) && $queue_data['leavewhenempty'] == 1) ? 'yes' : 'no';
            $ringinuse        = ($queue_data['recive_calls_while_on_a_call'] == 1) ? 'yes' : 'no';
            $announceposition = ($queue_data['announce_position'] == 1) ? 'yes' : 'no';
            $announceholdtime = ($queue_data['announce_hold_time'] == 1) ? 'yes' : 'no';

            $timeout           = ($queue_data['seconds_to_ring_each_member'] == '') ? '60' : $queue_data['seconds_to_ring_each_member'];
            $wrapuptime        = ($queue_data['seconds_for_wrapup'] == '') ? '3' : $queue_data['seconds_for_wrapup'];
            $periodic_announce = '';
            if (trim($queue_data['periodic_announce']) != '') {
                $announce_file     = Util::trimExtensionForFile($queue_data['periodic_announce']);
                $periodic_announce = "periodic-announce={$announce_file} \n";
            }
            $periodic_announce_frequency = '';
            if (trim($queue_data['periodic_announce_frequency']) != '') {
                $periodic_announce_frequency = "periodic-announce-frequency={$queue_data['periodic_announce_frequency']} \n";
            }
            $announce_frequency = '';
            if ($announceposition !== 'no' || $announceholdtime !== 'no') {
                $announce_frequency .= "announce-frequency=30 \n";
            }

            // liner - под этой стратегией понимаем последовательный вызов агентов очереди.
            // Каждый новый звонок должен инициировать последовательный вызов начиная с первого агента.
            // $strategy = ('linear' === $queue_data['strategy']) ? 'ringall' : $queue_data['strategy'];
            $strategy = $queue_data['strategy'];

            $q_conf .= "[{$queue_data['uniqid']}]; {$queue_data['name']}\n";
            $q_conf .= "musicclass=default \n";
            $q_conf .= "strategy={$strategy} \n";
            $q_conf .= "timeout={$timeout} \n";
            $q_conf .= "retry=1 \n";
            $q_conf .= "wrapuptime={$wrapuptime} \n";
            $q_conf .= "ringinuse={$ringinuse} \n";
            $q_conf .= "$periodic_announce";
            $q_conf .= "$periodic_announce_frequency";
            $q_conf .= "joinempty={$joinempty} \n";
            $q_conf .= "leavewhenempty={$leavewhenempty} \n";
            $q_conf .= "announce-position={$announceposition} \n";
            $q_conf .= "announce-holdtime={$announceholdtime} \n";
            $q_conf .= "$announce_frequency";

            $penalty = 0;
            foreach ($queue_data['agents'] as $agent) {
                // if ('linear' === $queue_data['strategy']) {
                //     $penalty++;
                // }
                $hint = '';
                if ($agent['isExternal'] != true) {
                    $hint = ",hint:{$agent['agent']}@internal-hints";
                }
                $q_conf .= "member => Local/{$agent['agent']}@internal/n,{$penalty},\"{$agent['agent']}\"{$hint} \n";
            }
            $q_conf .= "\n";
        }

        Util::fileWriteContent($this->config->path('asterisk.astetcdir') . '/queues.conf', $q_conf);

    }

    /**
     * Получение настроек очередей.
     *
     * @return array
     */
    public function getQueueData(): array
    {
        $arrResult = [];
        $queues    = CallQueues::find();
        foreach ($queues as $queue) {
            $queueUniqid = $queue->uniqid; // идентификатор очереди

            $arrAgents = [];
            $agents    = $queue->CallQueueMembers;
            foreach ($agents as $agent) {
                $arrAgents[] =
                    [
                        'agent'      => $agent->extension,
                        'priority'   => $agent->priority,
                        'isExternal' => ($agent->Extensions->type === Extensions::TYPE_EXTERNAL),
                    ];
            }
            $arrResult[$queueUniqid]['agents'] = $arrAgents;
            $periodic_announce                 = '';
            if ($queue->SoundFiles != false) {
                $periodic_announce = $queue->SoundFiles->path;
            }
            $arrResult[$queueUniqid]['periodic_announce'] = $periodic_announce;

            foreach ($queue as $key => $value) {
                if ($key == 'callqueuemembers' || $key == "soundfiles") {
                    continue;
                } // эти параметры мы собрали по-своему
                $arrResult[$queueUniqid][$key] = $value;
            }
        }

        return $arrResult; // JSON_PRETTY_PRINT
    }

    /**
     * Возвращает дополнительные контексты для Очереди.
     *
     * @return string
     */
    public function extensionGenContexts(): string
    {
        // Генерация внутреннего номерного плана.
        $conf = "[queue_agent_answer]\n";
        $conf .= "exten => s,1,NoOp(--- Answer Queue ---)\n\t";
        $conf .= 'same => n,Gosub(queue_answer,${EXTEN},1)' . "\n\t";
        $conf .= "same => n,Return()\n\n";

        return $conf;
    }

    /**
     * Генерация хинтов.
     *
     * @return string
     */
    public function extensionGenHints(): string
    {
        $conf = '';
        $db_data = $this->getQueueData();
        foreach ($db_data as $queue) {
            $conf .= "exten => {$queue['extension']},hint,Custom:{$queue['extension']} \n";
        }

        return $conf;
    }

    /**
     * @return string
     */
    public function extensionGenInternalTransfer(): string
    {
        $conf = '';
        $db_data = $this->getQueueData();
        foreach ($db_data as $queue) {
            $conf .= 'exten => _' . $queue['extension'] . ',1,Set(__ISTRANSFER=transfer_)' . " \n\t";
            $conf .= 'same => n,Goto(internal,${EXTEN},1)' . " \n";
        }
        $conf .= "\n";

        return $conf;
    }



    /**
     * Возвращает номерной план для internal контекста.
     *
     * @return string
     */
    public function extensionGenInternal(): string
    {
        $queue_ext_conf = '';
        $db_data = $this->getQueueData();
        foreach ($db_data as $queue) {
            $calleridPrefix = preg_replace('/[^a-zA-Zа-яА-Я0-9 ]/ui', '', $queue['callerid_prefix']??'');

            $queue_ext_conf .= "exten => {$queue['extension']},1,NoOp(--- Start Queue ---) \n\t";
            $queue_ext_conf .= "same => n,Answer() \n\t";
            $queue_ext_conf .= 'same => n,Set(__QUEUE_SRC_CHAN=${CHANNEL})' . "\n\t";
            $queue_ext_conf .= 'same => n,ExecIf($["${CHANNEL(channeltype)}" == "Local"]?Gosub(set_orign_chan,s,1))' . "\n\t";
            $queue_ext_conf .= 'same => n,Set(CHANNEL(hangup_handler_wipe)=hangup_handler,s,1)' . "\n\t";
            $queue_ext_conf .= 'same => n,Gosub(queue_start,${EXTEN},1)' . "\n\t";

            $options = '';
            if (isset($queue['caller_hear']) && $queue['caller_hear'] === 'ringing') {
                $options .= 'r'; // Установить КПВ (гудки) вместо Музыки на Удержании для ожидающих в очереди
            }
            $ringlength     = (trim($queue['timeout_to_redirect_to_extension']) == '') ? 300 : $queue['timeout_to_redirect_to_extension'];
            if(!empty($calleridPrefix)){
                $queue_ext_conf .= "same => n,Set(CALLERID(name)={$calleridPrefix}:".'${CALLERID(name)}'.") \n\t";
            }

            $queue_ext_conf .= "same => n,Queue({$queue['uniqid']},CkT{$options},,,{$ringlength},,,queue_agent_answer) \n\t";
            // Оповестим о завершении работы очереди.
            $queue_ext_conf .= 'same => n,Gosub(queue_end,${EXTEN},1)' . "\n\t";

            if (trim($queue['timeout_extension']) !== '') {
                // Если по таймауту не ответили, то выполним переадресацию.
                $queue_ext_conf .= 'same => n,ExecIf($["${QUEUESTATUS}" == "TIMEOUT"]?Goto(internal,' . $queue['timeout_extension'] . ',1))' . " \n\t";
            }
            if (trim($queue['redirect_to_extension_if_empty']) !== '') {
                // Если пустая очередь, то выполним переадресацию.
                $exp            = '$["${QUEUESTATUS}" == "JOINEMPTY" || "${QUEUESTATUS}" == "LEAVEEMPTY" ]';
                $queue_ext_conf .= 'same => n,ExecIf(' . $exp . '?Goto(internal,' . $queue['redirect_to_extension_if_empty'] . ',1))' . " \n\t";
            }
            $queue_ext_conf .= "\n";
        }

        return $queue_ext_conf;
    }

    /**
     * Generates queue.conf and restart asterisk queue module
     */
    public static function queueReload(): void
    {
        $queue            = new self();
        $queue->generateConfig();
        $out = [];
        $asteriskPath = Util::which('asterisk');
        Util::mwExec("{$asteriskPath} -rx 'queue reload all '", $out);
    }
}