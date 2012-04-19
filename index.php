<?php

$DIR = dirname ( __FILE__ );

//dummy xtgem functions
require ( $DIR .'/xtgem.php' );

//main xtscript file
require ( $DIR .'/script.php' );

//example content
$contents = file_get_contents ( $DIR .'/examples.txt' );

//example site
$url = 'test.xtgem.com/index';
$info = array ();
$url_vars = array ();

if ( preg_match_all ( '#<!--parser:xtscript-->(.+?)<!--/parser:xtscript-->#mis', $contents, $sm ) )
{
    $script = new script ( $url, $info, $url_vars );
    $version = 1;

    try
    { 
        foreach ( $sm [ 1 ] as $key => $part )
        {   
            $contents = preg_replace ( '#'. preg_quote ( $sm [ 0 ] [ $key ], '#' ) .'#', $script -> eval_syntax ( $part, $version ), $contents, 1 );
        }
    }
    catch ( SyntaxException $e )
    {
        die ( $e -> errorMessage ( $syntax, $e ) );
    }
}

die ( $contents );
