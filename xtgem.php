<?php

//Just some dummy classes for standalone functionality

class common
{ 
    public static function error_page ()
    {
        return true;
    }

    public static function get_param ( &$param, $default = false, $valid_set = null )
    {
        if ( !isset ( $param ) )
        {
            return $default;
        }
        else
        {
            if ( is_array ( $valid_set ) && sizeof ( $valid_set ) )
            { 
                if ( !in_array ( $param, $valid_set ) )
                { 
                    return $default;
                }
            }

            return $param;
        }
    }

    public static function domain ( $url )
    {
        // Country-code TLDs
        $cctlds = '(?:a[c-gil-oq-uwxz]|b[abd-jmnorstvwyz]|'.
            'c[acdf-ik-oruvxyz]|d[ejkmoz]|e[ceghrstu]|f[ijkmor]|'.
            'g[abd-ilmnp-tuwy]|h[kmnrtu]|i[delmnoq-t]|j[emop]|'.
            'k[eghimnprwyz]|l[abcikr-uvy]|m[acdeghk-z]|'.
            'n[acefgilopruzc]|om|p[ae-hk-nrstwy]|qa|r[eosuw]|'.
            's[a-eg-ortuvyz]|t[cdfghj-prtvwz]|u[agksyz]|v[aceginu]|'.
            'w[fs]|xt|y[etu]|z[amw])';
        // Valid sub/domain characters
        $name = '[a-z\d][a-z\d-_]{0,61}[a-z\d]';
        // Break down the URL
        preg_match ( '#(?:(.+?)\.)?(('. $name. ')\.('. $name. '\.'.
            '(?:biz|com?|edu|gov|info|int|mil|name|net|org|aero|'.
            'asia|cat|coop|jobs|mobi|museum|pro|tel|travel|arpa|root)?'.
            '(?:\.'. $cctlds. '?|'. $cctlds. ')?))((?:/.*?)?)'.
            '((?:\?.+)?)$#i', $url, $match );

        return count ( $match )? array_slice ( $match, 1 ): false;
    }
}

class content_model
{
    public static function parse_xt ( $contents, $url, $info, &$widget_total = null, &$widget_placeholders = null )
    {
        return $contents;    
    }
}

class X
{
    public static function model ( $model )
    {
        return new $model ();
    }

    public static function get ( $var )
    {
        return $var;
    }
}

class filesystem
{
    private $user_dir = '/var/users';

    public function __construct ()
    {
        //workaround
        $this -> user_dir = dirname ( __FILE__ );
    }

    public function path ( $url )
    {
        $match = common::domain ( $url );
        if ( !count ( $match ) || !isset ( $match [ 4 ] ) )
        { 
            return false;
        }

        $path [ 'subdomain' ] = strtolower ( $match [ 1 ] );

        $match [ 2 ] = strtolower ( $match [ 2 ] );
        $path [ 'absolute' ] = $this -> user_dir;
        $path [ 'relative' ] = trim ( strtolower ( implode ( '/',
            array_reverse ( explode ( '.', $match [ 0 ] ) ) ) ).
                $match [ 4 ], '/?' );

        $path [ 'absolute' ] .= ( $path [ 'relative' ]? '/': '' ) . $path [ 'relative' ];

        $path [ 'absolute' ] = str_replace ( '..', null, $path [ 'absolute' ] );

        return $path;
    }
}
