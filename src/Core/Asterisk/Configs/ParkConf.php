<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2020
 */

namespace MikoPBX\Core\Asterisk\Configs;

use MikoPBX\Modules\Config\ConfigClass;
use MikoPBX\Core\System\Util;

class ParkConf extends ConfigClass
{

    protected $description = 'res_parking.conf';

    protected $ParkingExt;
    protected $ParkingStartSlot;
    protected $ParkingEndSlot;

    /**
     * Функция позволяет получить активные каналы.
     * Возвращает ассоциативный массив. Ключ - Linkedid, значение - массив каналов.
     *
     * @param null $EXTEN
     *
     * @return null
     */
    public static function getParkslotData($EXTEN = null)
    {
        $ParkeeChannel = null;
        $am            = Util::getAstManager('off');
        $res           = $am->ParkedCalls('default');
        if (count($res['data']) == 0) {
            return $ParkeeChannel;
        }

        foreach ($res['data']['ParkedCall'] as $park_row) {
            if ($park_row['ParkingSpace'] == $EXTEN || $EXTEN == null) {
                $var_data                = $am->GetVar($park_row['ParkeeChannel'], 'pt1c_is_dst');
                $park_row['pt1c_is_dst'] = ($var_data["Value"] == '1');
                $ParkeeChannel           = $park_row;
            }
        }

        return $ParkeeChannel;
    }

    /**
     * Получение настроек.
     */
    public function getSettings(): void
    {
        $this->ParkingExt       = $this->mikoPBXConfig->getGeneralSettings('PBXCallParkingExt');
        $this->ParkingStartSlot = (int)$this->mikoPBXConfig->getGeneralSettings('PBXCallParkingStartSlot');
        $this->ParkingEndSlot   = (int)$this->mikoPBXConfig->getGeneralSettings('PBXCallParkingEndSlot');
    }

    /**
     * Возвращает включения в контекст internal
     *
     * @return string
     */
    public function getIncludeInternal(): string
    {
        // Включаем контексты.
        $conf = '';

        // $conf.= "include => parked-calls \n";
        return $conf;
    }

    /**
     * Возвращает включения в контекст internal-transfer
     *
     * @return string
     */
    public function getIncludeInternalTransfer(): string
    {
        // Генерация внутреннего номерного плана.
        return 'exten => ' . $this->ParkingExt . ',1,Goto(parkedcalls,${EXTEN},1)' . "\n";
    }

    /**
     * Генерация дополнительных контекстов.
     *
     * @return string
     */
    public function extensionGenContexts(): string
    {
        // Генерация внутреннего номерного плана.
        $conf = '';
        $conf .= "[parked-calls]\n";
        $conf .= "exten => _X!,1,NoOp(--- parkedcalls)\n\t";
        $conf .= "same => n,AGI(cdr_connector.php,unpark_call)\n\t";
        $conf .= 'same => n,ExecIf($["${pt1c_PARK_CHAN}x" == "x"]?Hangup())' . "\n\t";
        $conf .= 'same => n,Bridge(${pt1c_PARK_CHAN},kKTt)' . "\n\t";
        $conf .= 'same => n,Hangup()' . "\n\n";

        $conf .= "[parkedcallstimeout]\n";
        $conf .= "exten => s,1,NoOp(This is all that happens to parked calls if they time out.)\n\t";
        $conf .= 'same => n,Set(FROM_PEER=${EMPTYVAR})' . "\n\t";
        $conf .= 'same => n,AGI(cdr_connector.php,unpark_call_timeout)' . "\n\t";
        $conf .= 'same => n,Goto(internal,${PARKER:4},1)' . "\n\t";
        $conf .= 'same => n,Hangup()' . "\n\n";

        return $conf;
    }

    /**
     * Возвращает номерной план для internal контекста.
     *
     * @return string
     */
    public function extensionGenInternal(): string
    {
        $conf = '';
        for ($ext = $this->ParkingStartSlot; $ext <= $this->ParkingEndSlot; $ext++) {
            $conf .= 'exten => ' . $ext . ',1,Goto(parked-calls,${EXTEN},1)' . "\n";
        }
        $conf .= "\n";

        return $conf;
    }

    /**
     * Дополнительные параметры для секции global.
     *
     * @return string
     */
    public function extensionGlobals(): string
    {
        // Генерация хинтов.
        $result = "PARKING_DURATION=50\n";

        return $result;
    }

    /**
     * Дополнительные коды feature.conf
     *
     * @return string
     */
    public function getFeatureMap(): string
    {
        return "parkcall => *2 \n";
    }

    /**
     * Генерация файла конфигурации.
     *
     * @param $settings
     *
     * @return bool
     */
    protected function generateConfigProtected($settings): bool
    {
        // Генерация конфигурационных файлов.
        $result = true;
        $conf   = "[general] \n" .
            "parkeddynamic = yes \n\n" .
            "[default] \n" .
            "context => parkedcalls \n" .
            "parkedcallreparking = caller\n" .
            "parkedcalltransfers = caller\n" .
            "parkext => {$this->ParkingExt} \n" .
            "findslot => next\n" .
            "comebacktoorigin=no\n" .
            "comebackcontext = parkedcallstimeout\n" .
            "parkpos => {$this->ParkingStartSlot}-{$this->ParkingEndSlot} \n\n";
        file_put_contents($this->astConfDir . '/res_parking.conf', $conf);

        return $result;
    }

}
