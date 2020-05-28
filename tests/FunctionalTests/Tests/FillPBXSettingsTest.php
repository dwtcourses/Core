<?php

/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 5 2020
 *
 */
namespace MikoPBX\FunctionalTests\Tests;

use Facebook\WebDriver\WebDriverBy;
use MikoPBX\FunctionalTests\Lib\MikoPBXTestsBase;

class FillPBXSettingsTest extends MikoPBXTestsBase
{
    /**
     * @depends testLogin
     * @dataProvider additionProvider
     *
     * @param array $dataSet
     */
    public function testFillPBXSettings($dataSet): void
    {
        $this->clickSidebarMenuItemByHref("/admin-cabinet/general-settings/modify/");

        foreach ($dataSet as $key => $value) {
            $this->findElementOnPageAndFillValue($key, $value);
        }

        $this->submitForm('general-settings-form');

        $this->clickSidebarMenuItemByHref("/admin-cabinet/general-settings/modify/");

        foreach ($dataSet as $key => $value) {
            $this->findElementOnPageAndCheckValue($key, $value);
        }
    }


    private function findElementOnPageAndFillValue(string $key, $value): void
    {
        $xpath = '//input[@name="' . $key . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"tab")]';

        $inputItemPages = self::$driver->findElements(WebDriverBy::xpath($xpath));

        foreach ($inputItemPages as $inputItemPage) {
            $elementPage = $inputItemPage->getAttribute('data-tab');
            self::$driver->get("{$GLOBALS['SERVER_PBX']}/admin-cabinet/general-settings/modify/#/{$elementPage}");
            $this->changeInputField($key, $value);
            $this->changeCheckBoxState($key, $value);
        }

        $xpath             = '//textarea[@name="' . $key . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"tab")]';
        $textAreaItemPages = self::$driver->findElements(WebDriverBy::xpath($xpath));

        foreach ($textAreaItemPages as $textAreaItemPage) {
            $elementPage = $textAreaItemPage->getAttribute('data-tab');
            self::$driver->get("{$GLOBALS['SERVER_PBX']}/admin-cabinet/general-settings/modify/#/{$elementPage}");
            $this->changeTextAreaValue($key, $value);
        }

        $xpath           = '//select[@name="' . $key . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"tab")]';
        $selectItemPages = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($selectItemPages as $selectItemPage) {
            $elementPage = $selectItemPage->getAttribute('data-tab');
            self::$driver->get("{$GLOBALS['SERVER_PBX']}/admin-cabinet/general-settings/modify/#/{$elementPage}");
            $this->selectDropdownItem($key, $value);
        }
    }

    private function findElementOnPageAndCheckValue(string $key, $value): void
    {
        $xpath          = '//input[@name="' . $key . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"tab")]';
        $inputItemPages = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($inputItemPages as $inputItemPage) {
            $elementPage = $inputItemPage->getAttribute('data-tab');
            self::$driver->get("{$GLOBALS['SERVER_PBX']}/admin-cabinet/general-settings/modify/#/{$elementPage}");
            $this->assertInputFieldValueEqual($key, $value);

            $this->assertCheckBoxStageIsEqual($key, $value);
        }

        $xpath             = '//textarea[@name="' . $key . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"tab")]';
        $textAreaItemPages = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($textAreaItemPages as $textAreaItemPage) {
            $elementPage = $textAreaItemPage->getAttribute('data-tab');
            self::$driver->get("{$GLOBALS['SERVER_PBX']}/admin-cabinet/general-settings/modify/#/{$elementPage}");
            $this->assertTextAreaValueIsEqual($key, $value);
        }

        $xpath           = '//select[@name="' . $key . '"]/ancestor::div[contains(@class, "ui") and contains(@class ,"tab")]';
        $selectItemPages = self::$driver->findElements(WebDriverBy::xpath($xpath));
        foreach ($selectItemPages as $selectItemPage) {
            $elementPage = $selectItemPage->getAttribute('data-tab');
            self::$driver->get("{$GLOBALS['SERVER_PBX']}/admin-cabinet/general-settings/modify/#/{$elementPage}");
            $this->assertMenuItemSelected($key, $value);
        }
    }

    /**
     * Dataset provider
     * @return array
     */
    private function additionProvider(): array
    {
        $params = [];
        $SSHAuthorizedKeys = 'ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAQEAuzhViulNR4CXHvTfz8XVdrHq/Hmb3tZP9tFvwzEPtUmSK9ZihL2w45GhEkgXROKM4fY4Ii/KmZq+K2raWFUM54r7A83WseaAZpQM649WbJFVXPOwK6gDJtU/DaL4aSCsZwqhd6eE07ELVLnvjtQMvHqGd3lHI1zn/JnXZ55VDSTPqxDIApgCa5z8yNNXf3JGx5O+teHkG2pgh1Cnki7CE/aYzNWJW6ybq9rXQa6hGna53TuNfS1DwQ2LgF3bGG+Pl7PKCbU2CesqFw6uyGlWvdtF//GmZXEuy1FZNP1f5dHqyIxxanJOcd6rI1tkIZjtckrpIyfytC2coKZKJgX2aQ== nbek@miko.ru
ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQDZ3hd6/gqPxMMCqFytFdVznYD3Debp2LKTRiJEaS2SSIRHtE9jMNJjCfMR3CnScjKFh19Hfg/SJf2/rmXIJOHNjZvZZ7GgPTMBYllj3okniCA4/vQQRd6FMVPa9Rhu+N2kyMoQcuDEhzL5kEw0ge5BJJcmNjzW+an3fKqB7QwfMQ== jorikfon@MacBook-Pro-Nikolay.local';

        $params[] = [[
            'Name'                   => 'Тестовая 72',
            'Description'            => 'log: admin  pass: 8635255226',
            'PBXLanguage'            => 'en-en',
            'PBXRecordCalls'         => true,
            'SendMetrics'            => false,
            'SSHPassword'            => '8635255226',
            'SSHPasswordRepeat'      => '8635255226',
            'SSHAuthorizedKeys'      => $SSHAuthorizedKeys,
            'WebAdminPassword'       => '8635255226',
            'WebAdminPasswordRepeat' => '8635255226',

        ]];
        return $params;
    }
}



