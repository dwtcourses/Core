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

namespace MikoPBX\Core\System\Configs;

use MikoPBX\Common\Models\Fail2BanRules;
use MikoPBX\Common\Models\NetworkFilters;
use MikoPBX\Core\System\MikoPBXConfig;
use MikoPBX\Core\System\Processes;
use MikoPBX\Core\System\System;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\System\Verify;
use Phalcon\Di\Injectable;
use Phalcon\Text;
use SQLite3;

class Fail2BanConf extends Injectable
{
    public const FILTER_PATH = '/etc/fail2ban/filter.d';

    public const FAIL2BAN_DB_PATH = '/var/lib/fail2ban/fail2ban.sqlite3';

    public bool $fail2ban_enable;

    /**
     * Fail2Ban constructor.
     */
    public function __construct()
    {
        $mikoPBXConfig         = new MikoPBXConfig();
        $fail2ban_enable       = $mikoPBXConfig->getGeneralSettings('PBXFail2BanEnabled');
        $this->fail2ban_enable = ($fail2ban_enable === '1');
    }

    /**
     * Check fail2ban service and restart it died
     */
    public static function checkFail2ban(): void
    {
        $fail2ban = new self();
        if ($fail2ban->fail2ban_enable
            && ! $fail2ban->fail2banIsRunning()) {
            $fail2ban->fail2banStart();
        }
    }

    /**
     * Check fail2ban service status
     *
     * @return bool
     */
    private function fail2banIsRunning(): bool
    {
        $fail2banPath = Util::which('fail2ban-client');
        $res_ping     = Processes::mwExec("{$fail2banPath} ping");
        $res_stat     = Processes::mwExec("{$fail2banPath} status");

        $result = false;
        if ($res_ping === 0 && $res_stat === 0) {
            $result = true;
        }

        return $result;
    }

    /**
     * Start fail2ban service
     */
    public function fail2banStart(): void
    {
        if (Util::isSystemctl()) {
            $systemctlPath = Util::which('systemctl');
            Processes::mwExec("{$systemctlPath} restart fail2ban");

            return;
        }
        Processes::killByName('fail2ban-server');
        $fail2banPath = Util::which('fail2ban-client');
        $cmd_start    = "{$fail2banPath} -x start";
        $command      = "($cmd_start;) > /dev/null 2>&1 &";
        Processes::mwExec($command);
    }

    /**
     * Checks whether BANS table exists in DB or not
     *
     * @param SQLite3 $db
     *
     * @return bool
     */
    public function tableBanExists(SQLite3 $db): bool
    {
        $q_check      = 'SELECT name FROM sqlite_master WHERE type = "table" AND name="bans"';
        $result_check = $db->query($q_check);

        return (false !== $result_check && $result_check->fetchArray(SQLITE3_ASSOC) !== false);
    }

    /**
     * Shutdown fail2ban service
     */
    public function fail2banStop(): void
    {
        if (Util::isSystemctl()) {
            $systemctlPath = Util::which('systemctl');
            Processes::mwExec("{$systemctlPath} stop fail2ban");
        } else {
            $fail2banPath = Util::which('fail2ban-client');
            Processes::mwExec("{$fail2banPath} -x stop");
        }
    }

    /**
     * Create fail2ban dirs and DB if it does not exists
     *
     * @return string
     */
    public function fail2banMakeDirs(): string
    {
        $res_file = self::FAIL2BAN_DB_PATH;
        $filename = basename($res_file);

        $old_dir_db = '/cf/fail2ban';
        $dir_db     = $this->di->getShared('config')->path('core.fail2banDbDir');
        if (empty($dir_db)) {
            $dir_db = '/var/spool/fail2ban';
        }
        Util::mwMkdir($dir_db);
        // Создаем рабочие каталоги.
        $db_bd_dir = dirname($res_file);
        Util::mwMkdir($db_bd_dir);

        $create_link = false;

        // Символическая ссылка на базу данных.
        if (file_exists($res_file)){
            if (filetype($res_file) !== 'link') {
                unlink($res_file);
                $create_link = true;
            } elseif (readlink($res_file) === "$old_dir_db/$filename") {
                unlink($res_file);
                $create_link = true;
                if (file_exists("$old_dir_db/$filename")) {
                    // Перемещаем файл в новое местоположение.
                    $mvPath = Util::which('mv');
                    Processes::mwExec("{$mvPath} '$old_dir_db/$filename' '$dir_db/$filename'");
                }
            }
        }
        if ($create_link === true) {
            Util::createUpdateSymlink("$dir_db/$filename", $res_file);
        }

        return $res_file;
    }

    /**
     * Записываем конфиг для fail2ban. Описываем правила блокировок.
     */
    public function writeConfig(): void
    {
        $user_whitelist = '';
        /** @var \MikoPBX\Common\Models\Fail2BanRules $res */
        $res = Fail2BanRules::findFirst("id = '1'");
        if ($res !== null) {
            $max_retry     = $res->maxretry;
            $find_time     = $res->findtime;
            $ban_time      = $res->bantime;
            $whitelist     = $res->whitelist;
            $arr_whitelist = explode(' ', $whitelist);
            foreach ($arr_whitelist as $ip_string) {
                if (Verify::isIpAddress($ip_string)) {
                    $user_whitelist .= "$ip_string ";
                }
            }
            $net_filters = NetworkFilters::find("newer_block_ip = '1'");
            foreach ($net_filters as $filter) {
                $user_whitelist .= "{$filter->permit} ";
            }

            $user_whitelist = trim($user_whitelist);
        } else {
            $max_retry = '10';
            $find_time = '1800';
            $ban_time  = '43200';
        }
        $this->generateJails();

        $jails        = [
            'dropbear'    => 'iptables-allports[name=SSH, protocol=all]',
            'mikopbx-www' => 'iptables-allports[name=HTTP, protocol=all]',
        ];
        $modulesJails = $this->generateModulesJailsLocal();
        $jails        = array_merge($jails, $modulesJails);
        $config       = "[DEFAULT]\n" .
            "ignoreip = 127.0.0.1 {$user_whitelist}\n\n";

        $syslog_file = SyslogConf::getSyslogFile();

        foreach ($jails as $jail => $action) {
            $config .= "[{$jail}]\n" .
                "enabled = true\n" .
                "backend = process\n" .
                "logpath = {$syslog_file}\n" .
                // "logprocess = logread -f\n".
                "maxretry = {$max_retry}\n" .
                "findtime = {$find_time}\n" .
                "bantime = {$ban_time}\n" .
                "logencoding = utf-8\n" .
                "action = {$action}\n\n";
        }

        $log_dir = System::getLogDir() . '/asterisk/';
        $config  .= "[asterisk_security_log]\n" .
            "enabled = true\n" .
            "filter = asterisk\n" .
            "action = iptables-allports[name=ASTERISK, protocol=all]\n" .
            "logencoding = utf-8\n" .
            "maxretry = {$max_retry}\n" .
            "findtime = {$find_time}\n" .
            "bantime = {$ban_time}\n" .
            "logpath = {$log_dir}security_log\n\n";

        $config .= "[asterisk_error]\n" .
            "enabled = true\n" .
            "filter = asterisk\n" .
            "action = iptables-allports[name=ASTERISK_ERROR, protocol=all]\n" .
            "maxretry = {$max_retry}\n" .
            "findtime = {$find_time}\n" .
            "bantime = {$ban_time}\n" .
            "logencoding = utf-8\n" .
            "logpath = {$log_dir}error\n\n";

        $config .= "[asterisk_public]\n" .
            "enabled = true\n" .
            "filter = asterisk\n" .
            "action = iptables-allports[name=ASTERISK_PUBLIC, protocol=all]\n" .
            "maxretry = {$max_retry}\n" .
            "findtime = {$find_time}\n" .
            "bantime = {$ban_time}\n" .
            "logencoding = utf-8\n" .
            "logpath = {$log_dir}messages\n\n";

        Util::fileWriteContent('/etc/fail2ban/jail.local', $config);
    }

    /**
     * Creates additional rules
     */
    private function generateJails(): void
    {
        $filterPath = self::FILTER_PATH;

        $conf = "[INCLUDES]\n" .
            "before = common.conf\n" .
            "[Definition]\n" .
            "_daemon = [\S\W\s]+web_auth\n" .
            'failregex = ^%(__prefix_line)sFrom:\s<HOST>\sUserAgent:(\S|\s)*Wrong password$' . "\n" .
            '            ^(\S|\s)*nginx:\s+\d+/\d+/\d+\s+(\S|\s)*status\s+403(\S|\s)*client:\s+<HOST>(\S|\s)*' . "\n" .
            "ignoreregex =\n";
        file_put_contents("{$filterPath}/mikopbx-www.conf", $conf);

        $conf = "[INCLUDES]\n" .
            "before = common.conf\n" .
            "[Definition]\n" .
            "_daemon = (authpriv.warn )?dropbear\n" .
            'prefregex = ^%(__prefix_line)s<F-CONTENT>(?:[Ll]ogin|[Bb]ad|[Ee]xit).+</F-CONTENT>$' . "\n" .
            'failregex = ^[Ll]ogin attempt for nonexistent user (\'.*\' )?from <HOST>:\d+$' . "\n" .
            '            ^[Bb]ad (PAM )?password attempt for .+ from <HOST>(:\d+)?$' . "\n" .
            '            ^[Ee]xit before auth \(user \'.+\', \d+ fails\): Max auth tries reached - user \'.+\' from <HOST>:\d+\s*$' . "\n" .
            "ignoreregex =\n";
        file_put_contents("{$filterPath}/dropbear.conf", $conf);

        $this->generateModulesFilters();
    }

    /**
     * Generate additional modules filter files
     */
    protected function generateModulesFilters(): void
    {
        $filterPath        = self::FILTER_PATH;
        $additionalModules = $this->di->getShared('pbxConfModules');
        $rmPath            = Util::which('rm');
        Processes::mwExec("{$rmPath} -rf {$filterPath}/module_*.conf");
        foreach ($additionalModules as $appClass) {
            if (method_exists($appClass, 'generateFail2BanJails')) {
                $content = $appClass->generateFail2BanJails();
                if ( ! empty($content)) {
                    $moduleUniqueId = $appClass->moduleUniqueId;
                    $fileName = Text::uncamelize($moduleUniqueId,'_').'.conf';
                    file_put_contents("{$filterPath}/{$fileName}", $content);
                }
            }
        }
    }

    /**
     * Generate additional modules include to /etc/fail2ban/jail.local
     *
     * @return array
     */
    protected function generateModulesJailsLocal(): array
    {
        $jails             = [];
        $additionalModules = $this->di->getShared('pbxConfModules');
        foreach ($additionalModules as $appClass) {
            if (method_exists($appClass, 'generateFail2BanJails')) {
                $content = $appClass->generateFail2BanJails();
                if ( ! empty($content)) {
                    $moduleUniqueId                    = $appClass->moduleUniqueId;
                    $fileName = Text::uncamelize($moduleUniqueId,'_');
                    $jails[$fileName] = "iptables-allports[name={$moduleUniqueId}, protocol=all]";
                }
            }
        }

        return $jails;
    }

}