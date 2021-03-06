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

declare(strict_types=1);

namespace MikoPBX\Common\Providers;


use Phalcon\Cache;
use Phalcon\Cache\Adapter\Stream;
use Phalcon\Di\DiInterface;
use Phalcon\Di\ServiceProviderInterface;
use Phalcon\Storage\SerializerFactory;


/**
 * Main database connection is created based in the parameters defined in the configuration file
 */
class ManagedCacheProvider implements ServiceProviderInterface
{
    public const SERVICE_NAME = 'managedCache';

    /**
     * Register managedCache service provider
     *
     * @param \Phalcon\Di\DiInterface $di
     */
    public function register(DiInterface $di): void
    {
        if (posix_getuid() === 0) {
            $tempDir = $di->getShared('config')->path('core.managedCacheDir');
        } else {
            $tempDir = $di->getShared('config')->path('www.managedCacheDir');
        }
        $di->setShared(
            self::SERVICE_NAME,
            function () use ($tempDir){
                $serializerFactory = new SerializerFactory();

                $options = [
                    'defaultSerializer' => 'Php',
                    'lifetime'          => 7200,
                    'storageDir'        => $tempDir,
                ];

                $adapter = new Stream ($serializerFactory, $options);

                return new Cache($adapter);
            }
        );
    }
}