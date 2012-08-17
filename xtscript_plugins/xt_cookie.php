<?php

class xt_cookie
{ 
    private static $url, $info;

    public static function __setup ( $url = false, $info = false )
    {
        self::$url = $url;
        self::$info = $info;
    }

    public static function set ( $args )
    {
        if ( !isset ( $args [ '$name' ] ) || empty ( $args [ '$name' ] ) || !isset ( $args [ '$val' ] ) )
        { 
            return '';
        }

        $expire = 0;

        if ( isset ( $args [ '$expire' ] ) && !empty ( $args [ '$expire' ] ) )
        {
            $expire = time () + $args [ '$expire' ];
        }

        $path = isset ( $args [ '$path' ] ) ? $args [ 'path' ] : '/';

        $domain = null;
        if ( self::$url )
        {
            list ( $domain, ) = explode ( '/', self::$url, 2 );
        }

        setcookie ( $args [ '$name' ], $args [ '$val' ], $expire, $path, $domain );

        if ( isset ( $args [ '$force_current' ] ) && !empty ( $args [ '$force_current' ] ) )
        {
            $_COOKIE [ $args [ '$name' ] ] = $args [ '$val' ];
        }
    }

    public static function delete ( $args )
    {
        if ( !isset ( $args [ '$name' ] ) || empty ( $args [ '$name' ] ) )
        { 
            return '';
        }

        $path = isset ( $args [ '$path' ] ) ? $args [ 'path' ] : '/';

        $domain = null;
        if ( self::$url )
        {
            list ( $domain, ) = explode ( '/', self::$url, 2 );
        }

        setcookie ( $args [ '$name' ], '', (time ()-86400), $path, $domain );

        if ( isset ( $args [ '$force_current' ] ) && !empty ( $args [ '$force_current' ] ) && isset ( $_COOKIE [ $args [ '$name' ] ] ) )
        {
            unset ( $_COOKIE [ $args [ '$name' ] ] );
        }
    }

    public static function get ( $args )
    {
        if ( !isset ( $args [ '$name' ] ) || empty ( $args [ '$name' ] ) )
        { 
            return '';
        }

        if ( isset ( $_COOKIE [ $args [ '$name' ] ] ) )
        {
            return $_COOKIE [ $args [ '$name' ] ];
        }

        if ( isset ( $args [ '$default' ] ) )
        {
            return $args [ '$default' ];
        }

        return '';
    }
}
