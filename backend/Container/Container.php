<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Container;

use DI\Container as PHPDIContainer;

class Container extends PHPDIContainer implements ContainerInterface
{
    protected static $instance;

    public function __construct(\DI\ContainerBuilder $builder = null)
    {
        parent::__construct();
        self::$instance = $this;
    }

    public static function getInstance()
    {
        return self::$instance;
    }
}
