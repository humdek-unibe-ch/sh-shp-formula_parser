<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInite6a0e68ed27dffc7d44d30aa19359517
{
    public static $prefixLengthsPsr4 = array (
        'N' => 
        array (
            'NXP\\' => 4,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'NXP\\' => 
        array (
            0 => __DIR__ . '/..' . '/nxp/math-executor/src/NXP',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInite6a0e68ed27dffc7d44d30aa19359517::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInite6a0e68ed27dffc7d44d30aa19359517::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInite6a0e68ed27dffc7d44d30aa19359517::$classMap;

        }, null, ClassLoader::class);
    }
}
