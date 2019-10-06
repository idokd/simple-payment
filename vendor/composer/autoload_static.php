<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitbe03bdd49f4637afb5fbdac3bee97a61
{
    public static $prefixLengthsPsr4 = array (
        'l' => 
        array (
            'leewillis77\\WpListTableExportable\\' => 34,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'leewillis77\\WpListTableExportable\\' => 
        array (
            0 => __DIR__ . '/..' . '/leewillis77/WpListTableExportable/src',
        ),
    );

    public static $classMap = array (
        'SimplePayment\\Engines\\Cardcom' => __DIR__ . '/..' . '/yalla-ya/SimplePayment/Engines/Cardcom.php',
        'SimplePayment\\Engines\\Engine' => __DIR__ . '/..' . '/yalla-ya/SimplePayment/Engines/Engine.php',
        'SimplePayment\\Engines\\PayPal' => __DIR__ . '/..' . '/yalla-ya/SimplePayment/Engines/PayPal.php',
        'SimplePayment\\SimplePayment' => __DIR__ . '/..' . '/yalla-ya/SimplePayment/SimplePayment.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitbe03bdd49f4637afb5fbdac3bee97a61::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitbe03bdd49f4637afb5fbdac3bee97a61::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitbe03bdd49f4637afb5fbdac3bee97a61::$classMap;

        }, null, ClassLoader::class);
    }
}
