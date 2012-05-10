<?php

register_shutdown_function ( 'HandleXtScriptShutdown' );

function HandleXtScriptShutdown () 
{
    $error = error_get_last ();

    //prevent from nested functions
    if ( $error && $error [ 'type' ] == 1 && strpos ( $error [ 'message' ], 'Maximum function nesting level' ) === 0 )
    {
        ob_end_clean ();
        common::error_page ( 'Maximum function nesting level reached', X::get ( 'user_site' ) );
        exit;
    }
}

class script 
{ 
    const XT_SYNTAX_NONE = 0;
    const XT_SYNTAX_FUNCTION = 1;
    const XT_SYNTAX_FUNCTION_IGNORE = 2;
    const XT_SYNTAX_IF_TRUE = 3;
    const XT_SYNTAX_IF_FALSE = 4;
    const XT_SYNTAX_IF_FALSE_ELSE = 5;
    const XT_SYNTAX_IF_SKIP = 6;

    //seconds
    const XT_SYNTAX_TIMEOUT = 2;

    private $url, $vars, $info, $version;
    private $xt_syntax_state = array ( self::XT_SYNTAX_NONE );
    private $xt_syntax_functions = array ();

    private static $started = null;

    public function __construct ( $url = false, $info = false, &$vars = false, &$syntax_functions = false )
    {
        if ( $url !== false && $info !== false && $vars !== false )
        {
            $this -> setup ( $url, $info, $vars, $syntax_functions );
        }
    }

    public function setup ( $url, $info, &$vars, &$syntax_functions = false )
    { 
        $this -> vars = &$vars;
        $this -> url = $url;
        $this -> info = $info;

        //reset vars
        $this -> cmd_list = array ();

        if ( $syntax_functions !== false )
        { 
            $this -> xt_syntax_functions = &$syntax_functions;
        }
        else
        { 
            $this -> xt_syntax_functions = array ();
        }

        $this -> xt_syntax_state = array ( array ( self::XT_SYNTAX_NONE => array () ) );
    }

    public function get_syntax_functions ()
    {
        return $this -> xt_syntax_functions;
    }

    public function eval_syntax ( $str, $version )
    {
        if ( is_null ( self::$started ) )
        { 
            self::$started = microtime ( true );
        }

        $this -> version = $version;

        $str = str_replace ( array ( "\r\n", "\r" ), array ( "\n", "\n" ), $str );

        if ( $version == 1 )
        {
            //replace comments
            $str = preg_replace ( '#\/\*.+\*\/#s', '', $str );

            //merge multiline strings
            if ( preg_match_all ( '#\{\{(.+?)\}\}#s', $str, $matches ) && is_array ( $matches [ 0 ] ) && sizeof ( $matches [ 0 ] ) > 0 )
            {
                foreach ( $matches [ 0 ] as $key => $val )
                {
                    $str = str_replace ( $val, str_replace ( "\n", '\\n', $matches [ 1 ][ $key ] ), $str );
                }
            }

            $result = array ();

            $this -> cmd_list = explode ( "\n", $str );

            if ( !isset ( $this -> cmd_list [ 1 ] ) )
            {
                $this -> cmd_list = explode ( "[br]", $str );
            }

            while ( list ( $key, $line ) = each ( $this -> cmd_list ) )
            {
                if ( self::$started + self::XT_SYNTAX_TIMEOUT < microtime ( true ) )
                {
                    return 'XtScript Error: Timeout.';
                }

                $line = trim ( trim ( $line ), ';' );

                if ( !empty ( $line ) && strpos ( $line, '#' ) !== 0 )
                { 
                    //replace back newlines
                    $line = str_replace ( '\\n', "\n", $line );

                    $splited = explode ( ' ', $line, 2 );
                    $cmd = strtolower ( $splited [ 0 ] );
                    $args = array ();

                    if ( $cmd == 'assign' || $cmd == 'var' )
                    {
                        $args = explode ( '=', $splited [ 1 ], 2 );
                    }
                    elseif ( $cmd == 'include' )
                    {
                        $args = explode ( ',', $splited [ 1 ] );
                        $args = array_map ( 'trim', $args );
                    }
                    elseif ( $cmd == 'call' || $cmd == 'function' )
                    { 
                        $splited = explode ( ' ', $splited [ 1 ], 2 );
                        $function = $splited [ 0 ];

                        if ( !isset ( $splited [ 1 ] ) )
                        {
                            $aargs = array ();
                        }
                        else
                        { 
                            $aargs = explode ( ';', $splited [ 1 ] );
                        }

                        $args = array ( $function );

                        if ( sizeof ( $aargs ) > 0 )
                        { 
                            foreach ( $aargs as $aarg )
                            {
                                $aarg = trim ( $aarg );
                                $tmp = explode ( '=', $aarg, 2 );

                                $args [ $tmp [ 0 ] ] = common::get_param ( $tmp [ 1 ], '' );
                            }
                        }
                    }
                    elseif ( isset ( $splited [ 1 ] ) )
                    { 
                        $args = $splited [ 1 ];
                    }

                    $result [] = $this -> eval_cmd ( $cmd, $args );
                }
            }

            return implode ( '', $result );
        }

        return '';
    }

    private function eval_cmd ( $cmd, $args )
    {
        if ( !is_array ( $args ) )
        {
            $args = array ( $args );
        }

        $result = '';
        $__state = $this -> syntax_get_state ();

        if ( $cmd == 'endfunction' && ( $__state == self::XT_SYNTAX_FUNCTION || $__state == self::XT_SYNTAX_FUNCTION_IGNORE ) )
        { 
            $this -> syntax_pop_state ();
            return '';
        }

        if ( $__state == self::XT_SYNTAX_FUNCTION )
        {
            end ( $this -> xt_syntax_functions );
            $function = key ( $this -> xt_syntax_functions );

            if ( isset ( $this -> xt_syntax_functions [ $function ][ 'code' ] ) )
            { 
                prev ( $this -> cmd_list );
                $this -> xt_syntax_functions [ $function ][ 'code' ] .= trim ( current ( $this -> cmd_list ) ) ."\n";
                next ( $this -> cmd_list );
            }
            
            return '';
        }

        if ( $cmd == 'endif' && in_array ( $__state, array ( self::XT_SYNTAX_IF_FALSE, self::XT_SYNTAX_IF_TRUE, self::XT_SYNTAX_IF_FALSE_ELSE, self::XT_SYNTAX_IF_SKIP ) ) )
        {
            $this -> syntax_pop_state ();
            return '';
        }

        if ( $__state == self::XT_SYNTAX_IF_SKIP || $__state == self::XT_SYNTAX_FUNCTION_IGNORE )
        {
            return '';
        }

        if ( $__state == self::XT_SYNTAX_IF_TRUE && ( $cmd == 'else' || $cmd == 'elseif' ) )
        {
            $this -> syntax_set_state ( self::XT_SYNTAX_IF_SKIP );
            return '';
        }

        if ( $__state == self::XT_SYNTAX_IF_FALSE )
        {
            if ( $cmd != 'else' && $cmd != 'elseif' )
            {
                return '';
            }
        }

        $args = array_map ( 'trim', $args );
        if ( $cmd == 'assign' || $cmd == 'var' )
        {
            if ( sizeof ( $args ) === 2 )
            { 
                if ( substr ( $args [ 1 ], 0, 4 ) == '<xt:' )
                { 
                    $args [ 1 ] = $this -> eval_vars ( $args [ 1 ] );
                    $args [ 1 ] = content_model::parse_xt ( $args [ 1 ], $this -> url, $this -> info );

                    $this -> vars [ $args [ 0 ] ] = $this -> eval_vars ( $args [ 1 ] );
                }
                elseif ( strpos ( $args [ 1 ], 'call ' ) === 0 )
                { 
                    $splited = explode ( ' ', substr ( $args [ 1 ], 5 ), 2 );
                    $function = $splited [ 0 ];

                    if ( !isset ( $splited [ 1 ] ) )
                    {
                        $aargs = array ();
                    }
                    else
                    { 
                        $aargs = explode ( ';', $splited [ 1 ] );
                    }

                    $vars = array ();

                    if ( sizeof ( $aargs ) > 0 )
                    { 
                        foreach ( $aargs as $aarg )
                        {
                            $aarg = trim ( $aarg );
                            $tmp = explode ( '=', $aarg, 2 );

                            $vars [ $tmp [ 0 ] ] = common::get_param ( $tmp [ 1 ], '' );
                        }
                    }
                    
                    $this -> vars [ $args [ 0 ] ] = $this -> eval_function ( $function, $vars );
                }
                else
                { 
                    $this -> vars [ $args [ 0 ] ] = $this -> eval_vars ( $args [ 1 ] );
                }

            }
        }
        elseif ( $cmd == 'del' || $cmd == 'delete' )
        {
            unset ( $this -> vars [ $args [ 0 ] ] );
        }
        elseif ( $cmd == 'get' )
        {
            if ( isset ( $args [ 0 ] ) )
            {
                $this -> vars [ '$'. $args [ 0 ] ] = common::get_param ( $this -> vars [ $args [ 0 ] ], '' );
            }
        }
        elseif ( $cmd == 'print' || $cmd == 'return' )
        {
            $result = isset ( $this -> vars [ $args [ 0 ] ] ) ? $this -> vars [ $args [ 0 ] ] : false;

            if ( !$result && !empty ( $args [ 0 ] ) )
            { 
                $result = $this -> eval_vars ( $args [ 0 ] );
            }

            if ( $cmd == 'return' )
            {
                $this -> syntax_set_state ( self::XT_SYNTAX_FUNCTION_IGNORE );
            }
        }
        elseif ( $cmd == 'print_raw' )
        {
            $result = $args [ 0 ];
        }
        elseif ( $cmd == 'call' )
        {
            $function = array_shift ( $args );

            $result = $this -> eval_function ( $function, $args );
        }
        elseif ( $cmd == 'if' || ( $cmd == 'elseif' && $__state == self::XT_SYNTAX_IF_FALSE ) )
        {
            $new_state = self::XT_SYNTAX_IF_FALSE;

            $operators = array ( '>=', '<=', '>', '<', '==' );

            //TODO: "AND" implementation
            $ors = explode ( 'or', $args [ 0 ] );

            foreach ( $ors as $or )
            { 
                $or = trim ( $or );

                $operator = false;

                foreach ( $operators as $op )
                {
                    if ( strpos ( $or, $op ) !== false )
                    {
                        $operator = $op;
                        break;
                    }
                }

                if ( $operator )
                { 
                    $condition = array_map ( 'trim', explode ( $operator, $or, 2 ) );
                }
                else
                {
                    $operator = 'bool';
                    $condition = array ( $or );
                }

                $inverse_condition = false;

                if ( strpos ( $condition [ 0 ], 'not ' ) === 0 )
                {
                    $condition [ 0 ] = substr ( $condition [ 0 ], 4 );
                    $inverse_condition = true;
                }

                $cond_result = $this -> eval_condition ( $operator, $condition );

                if ( $inverse_condition )
                {
                    $cond_result = $cond_result ? false : true;
                }

                if ( $cond_result )
                {
                    $new_state = self::XT_SYNTAX_IF_TRUE;
                    break;
                }
            }

            if ( $new_state == self::XT_SYNTAX_IF_TRUE && $cmd == 'elseif' && $__state == self::XT_SYNTAX_IF_FALSE )
            {
                $new_state = self::XT_SYNTAX_IF_FALSE_ELSE;
            }

            if ( $cmd == 'if' )
            { 
                $this -> syntax_push_state ( $new_state );
            }
            else
            { 
                $this -> syntax_set_state ( $new_state );
            }
        }
        elseif ( $cmd == 'else' )
        {
            if ( $__state == self::XT_SYNTAX_IF_FALSE )
            { 
                $this -> syntax_set_state ( self::XT_SYNTAX_IF_FALSE_ELSE );
                return '';
            } 
            elseif ( $__state == self::XT_SYNTAX_IF_FALSE_ELSE )
            { 
                $this -> syntax_set_state ( self::XT_SYNTAX_IF_SKIP );
                return '';
            }
        }
        elseif ( $cmd == 'endif' && ( $__state == self::XT_SYNTAX_IF_TRUE || $__state == self::XT_SYNTAX_IF_FALSE ) )
        {
            $this -> syntax_pop_state ();
        }
        elseif ( $cmd == 'goto' )
        {
            if ( isset ( $args [ 0 ] ) && !empty ( $args [ 0 ] ) && strpos ( $args [ 0 ], '@' ) === 0 )
            {
                $limit = 10000;
                while ( --$limit )
                {
                    prev ( $this -> cmd_list );
                    $line = current ( $this -> cmd_list );

                    if ( trim ( $line ) == $args [ 0 ] )
                    {
                        break;
                    }
                }
            }
        }
        elseif ( $cmd == 'function' && $__state != self::XT_SYNTAX_FUNCTION && $__state != self::XT_SYNTAX_FUNCTION_IGNORE )
        {
            $function = array_shift ( $args );

            if ( method_exists ( $this, '__'. $function ) )
            {
                $result = 'XtScript Error: Trying to overload native `'. $function .'` function.';
            
                $this -> syntax_push_state ( self::XT_SYNTAX_FUNCTION_IGNORE );
            }
            else
            {
                $this -> syntax_push_state ( self::XT_SYNTAX_FUNCTION );
                $this -> xt_syntax_functions [ $function ] = array ( 'args' => $args, 'code' => '' );
            }   
        }
        elseif ( $cmd == 'include' )
        {
            $fs = X::model ( 'filesystem' );
            $domain = substr ( $this -> url, 0, strpos ( $this -> url, '/' ) );
            $domain_path = $fs -> path ( $domain );

            $path = $fs -> path ( dirname ( $this -> url ) );

            foreach ( $args as $arg )
            { 
                if ( substr ( $arg, -3 ) !== '.xt' )
                {
                    continue;
                }

                $p = $fs -> path ( $arg );

                $functions_prefix = '';
                $check_owner = false;

                //Trying to include local file
                if ( !$p || !isset ( $p [ 'absolute' ] ) )
                { 
                    $file = realpath ( $path [ 'absolute' ] .'/'. ltrim ( $arg, '/' ) );

                    if ( $file && strpos ( $file, realpath ( $domain_path [ 'absolute' ] ) ) !== 0 )
                    {
                        $file = false;
                    }
                }
                //Trying to include other user file
                else
                { 
                    $functions_prefix = substr ( $arg, 0, strpos ( $arg, '/' ) );
                    $check_owner = true;
                    $file = realpath ( $p [ 'absolute' ] );
                }

                if ( $file )
                { 
                    $contents = file_get_contents ( $file );

                    $cfg = preg_split ( '#\r?\n#', $contents, 2 );

                    if ( $check_owner )
                    {
                        if ( stripos ( $cfg [ 0 ], 'exportable' ) === false )
                        {
                            continue;
                        }
                    }

                    $version = 1;
                    if ( isset ( $cfg [ 0 ] ) )
                    {
                        if ( preg_match ( '#version(\d)#', $cfg [ 0 ], $m ) )
                        {
                            $version = $m [ 1 ];
                        }
                    }

                    $functions = array ();

                    if ( sizeof ( $_POST ) > 0 )
                    { 
                        $vars = array_merge ( $_GET, $_POST );
                    }
                    else
                    { 
                        $vars = $_GET;
                    }

                    $orign_vars = $vars;

                    $obj = new script ( $this -> url, $this -> info, $vars, $functions );
                    $result .= $obj -> eval_syntax ( $contents, $version );

                    if ( sizeof ( $functions ) > 0 )
                    {
                        foreach ( $functions as $function => $code )
                        { 
                            $this -> xt_syntax_functions [ $functions_prefix .'@'. $function ] = $code;
                        }
                    }

                    foreach ( $vars as $key => $var )
                    {
                        if ( !isset ( $origin_vars [ $key ] ) || $origin_vars [ $key ] != $var )
                        { 
                            $this -> vars [ $functions_prefix .'@'. $key ] = $var;
                        }
                    }
                }
            }
        }

        return $result;
    }

    private function eval_condition ( $operator, $args )
    {
        //TODO: support for more operators and more args
        $size = sizeof ( $args );

        $args [ 0 ] = $this -> eval_vars ( $args [ 0 ] );

        if ( isset ( $args [ 1 ] ) )
        { 
            $args [ 1 ] = $this -> eval_vars ( $args [ 1 ] );
        }

        if ( $operator === 'bool' && isset ( $args [ 0 ] ) )
        {
            return $args [ 0 ] ? true : false;
        }
        elseif ( sizeof ( $args ) < 2 )
        {
            return false;
        }
        else
        {
            $arg1 = $args [ 0 ];
            $arg2 = $args [ 1 ];

            if ( $operator === '>' )
            {
                return ( bool ) ( $arg1 > $arg2 );
            }
            elseif ( $operator === '<' )
            { 
                return ( bool ) ( $arg1 < $arg2 );
            }
            elseif ( $operator === '==' )
            { 
                return ( bool ) ( $arg1 == $arg2 );
            }
            elseif ( $operator === '>=' )
            { 
                return ( bool ) ( $arg1 >= $arg2 );
            }
            elseif ( $operator === '<=' )
            { 
                return ( bool ) ( $arg1 <= $arg2 );
            }
        }

        return false;
    }

    private function eval_vars ( $result )
    { 
        $result = str_replace ( array ( '\$', '\(', '\)' ), array ( '&#36;', '&#40;', '&#41;' ), $result );

        if ( strpos ( $result, '$' ) !== false )
        {
            preg_match_all ( '#\$\w+#', $result, $vars );

            if ( sizeof ( $vars ) > 0 )
            { 
                foreach ( $vars [ 0 ] as $var )
                {
                    $res = isset ( $this -> vars [ $var ] ) ? $this -> vars [ $var ] : '';
                    $result = preg_replace ( '#'. preg_quote ( $var ) .'#', $res, $result, 1 );
                }
            }
        }

        if ( strpos ( $result, '(' ) !== false && strpos ( $result, ')' ) !== false )
        { 
            preg_match_all ( '#\(.+?\)#', $result, $matches );

            if ( sizeof ( $matches ) > 0 )
            {
                foreach ( $matches [ 0 ] as $match )
                {
                    $res = $this -> eval_math ( substr ( $match, 1, -1 ) );
                    $result = preg_replace ( '#'. preg_quote ( $match ) .'#', $res, $result, 1 );
                }
            }
        }

        return $result;
    }

    private function eval_math ( $code )
    {
        $operators = array ( '+', '-', '/', '*', '%' );
        $init = false;
        $return = 0;

        $code = preg_replace ( '#\s+#', '', $code );

        $splits = preg_split ( '(\\'. implode ( '|\\', $operators ) .')', $code, -1, PREG_SPLIT_OFFSET_CAPTURE );

        foreach ( $splits as $split )
        { 
            if ( !$init )
            {
                $init = true;
                $return = $split [ 0 ];

                continue;
            } 
            else
            {
                $op = substr ( $code, ( $split [ 1 ]-1 ), 1 );
                
                if ( $op == '+' )
                {
                    $return += $split [ 0 ];
                }
                elseif ( $op == '-' )
                {
                    $return -= $split [ 0 ];
                }
                elseif ( $op == '/' )
                {
                    if ( $split [ 0 ] != 0 )
                    { 
                        $return /= $split [ 0 ];
                    }
                }
                elseif ( $op == '*' )
                {
                    $return *= $split [ 0 ];
                }
                elseif ( $op == '%' )
                { 
                    $return %= $split [ 0 ];
                }
            }
        }

        return $return;
    }

    public function eval_function ( $function, $args )
    { 
        $method = '__'. $function;
        $result = '';

        if ( method_exists ( $this, $method ) )
        {
            foreach ( $args as $key => $val )
            {
                $args [ $key ] = $this -> eval_vars ( $val );
            }

            $result = call_user_func ( array ( $this, $method ), $args );
        }
        else
        { 
            if ( !isset ( $this -> xt_syntax_functions [ $function ][ 'code' ] ) )
            { 
                $result = 'XtScript Error: Undefined function `'. $function .'`';
            }
            else
            { 
                $arguments = $this -> xt_syntax_functions [ $function ][ 'args' ];

                foreach ( $arguments as $key => $val )
                {
                    if ( isset ( $args [ $key ] ) )
                    {
                        $arguments [ $key ] = $this -> eval_vars ( $args [ $key ] );
                    }
                }

                $vars = array_merge ( $this -> vars, $arguments );
                $obj = new script ( $this -> url, $this -> info, $vars, $this -> xt_syntax_functions );
                
                $result = $obj -> eval_syntax ( $this -> xt_syntax_functions [ $function ][ 'code' ], $this -> version );
            }
        }

        return $result;
    }

    private function syntax_push_state ( $state )
    { 
        array_push ( $this -> xt_syntax_state, $state );
    }

    private function syntax_pop_state ()
    { 
        return array_pop ( $this -> xt_syntax_state );
    }

    private function syntax_set_state ( $state )
    { 
        $this -> syntax_pop_state ();
        $this -> syntax_push_state ( $state );
    }

    private function syntax_get_state ()
    { 
        return end ( $this -> xt_syntax_state ); 
    }

    private function __dump_vars ( $args )
    { 
        $result = null;

        $tmp = $this -> vars;

        unset ( $tmp [ '___p' ] );

        if ( empty ( $tmp [ 'code' ] ) )
        {
            unset ( $tmp [ 'code' ] );
        }

        $result = '<pre>'. print_r ( $tmp, true ) .'</pre>';

        return $result;
    }

    private function __dump_functions ( $args )
    {
        $result = '<pre>'. print_r ( array_keys ( $this -> xt_syntax_functions ), true ) .'</pre>';

        return $result;
    }

    private function __args ( $args )
    {
        return '<pre>'. print_r ( $args, true ) .'</pre>';
    }

    private function __execution_time ( $args )
    {
        return number_format ( ( ( microtime ( true ) - self::$started ) / 1000 ) , 6, '.', '' ) .'s.';
    }

    private function __source ( $args )
    {
        return $this -> __file_get_contents ( $args );
    }

    private function __file_get_contents ( $args )
    { 
        if ( !isset ( $args [ '$file' ] ) )
        {
            return '';
        }

        $fs = X::model ( 'filesystem' );

        $domain = substr ( $this -> url, 0, strpos ( $this -> url, '/' ) );
        $domain_path = $fs -> path ( $domain );

        $path = $fs -> path ( dirname ( $this -> url ) );

        $file = realpath ( $path [ 'absolute' ] .'/'. ltrim ( $args [ '$file' ], '/' ) );

        $return = '';

        //TODO: remove realpath checks
        if ( $file && strpos ( $file, realpath ( $domain_path [ 'absolute' ] ) ) === 0 )
        { 
            $return = file_get_contents ( $file );

            if ( isset ( $args [ '$html_safe' ] ) && $args [ '$html_safe' ] )
            {
                $return = htmlspecialchars ( $return );
            }

            if ( isset ( $args [ '$nl2br' ] ) && $args [ '$nl2br' ] )
            {
                $return = nl2br ( $return );
            }

            if ( isset ( $args [ '$space2nbsp' ] ) )
            {
                $return = str_replace ( '  ', '&nbsp;&nbsp;', $return );
            }
        }

        return $return;
    }

    //String functions
    private function __chr ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return chr ( $args [ '$val' ] );
    }

    private function __ord ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return ord ( $args [ '$val' ] );
    }

    private function __crc32 ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return crc32 ( $args [ '$val' ] );
    }

    private function __md5 ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return md5 ( $args [ '$val' ] );
    }

    private function __sha1 ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return sha1 ( $args [ '$val' ] );
    }

    private function __base64_encode ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return base64_encode ( $args [ '$val' ] );
    }

    private function __base64_decode ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return base64_decode ( $args [ '$val' ] );
    }

    private function __bin2hex ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return bin2hex ( $args [ '$val' ] );
    }

    private function __hex2bin ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        if ( !function_exists ( 'hex2bin' ) )
        { 
            $len = strlen ( $args [ '$val' ] );
            $bin = '';
            $i = 0;

            do 
            {
                $bin .= chr ( hexdec ( $args [ '$val' ]{$i} . $args [ '$val' ]{( $i + 1 )} ) );
                $i += 2;
            } 
            while ( $i < $len );

            return $bin;
        }
        else
        { 
            return hex2bin ( $args [ '$val' ] );
        }
    }

    private function __hexdec ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return hexdec ( $args [ '$val' ] );
    }

    private function __dechex ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return dechex ( $args [ '$val' ] );
    }


    private function __htmlspecialchars ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        $flags = defined ( 'ENT_HTML401' ) ? ENT_COMPAT | ENT_HTML401 : ENT_COMPAT;

        if ( isset ( $args [ '$flags' ] ) && defined ( $args [ '$flags' ] ) )
        {
            $flags = constant ( $args [ '$flags' ] );
        }

        return htmlspecialchars ( $args [ '$val' ], $flags, common::get_param ( $args [ '$encoding' ], 'UTF-8' ), common::get_param ( $args [ '$double_encode' ], true ) );
    }

    private function __lcfirst ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return lcfirst ( $args [ '$val' ] );
    }

    private function __ucfirst ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return ucfirst ( $args [ '$val' ] );
    }

    private function __ucwords ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return ucwords ( $args [ '$val' ] );
    }

    private function __strtoupper ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return strtoupper ( $args [ '$val' ] );
    }

    private function __strtolower ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return strtolower ( $args [ '$val' ] );
    }

    private function __trim ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return trim ( $args [ '$val' ], common::get_param ( $args [ '$charlist' ], null ) );
    }

    private function __ltrim ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return ltrim ( $args [ '$val' ], common::get_param ( $args [ '$charlist' ], null ) );
    }

    private function __rtrim ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return rtrim ( $args [ '$val' ], common::get_param ( $args [ '$charlist' ], null ) );
    }

    private function __nl2br ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return nl2br ( $args [ '$val' ] );
    }

    private function __br2nl ( $args )
    {
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return str_replace ( array ( '<br>', '<br/>', '<br />' ), array ( "\n", "\n", "\n" ), $args [ '$val' ] );
    }

    private function __str_replace ( $args )
    {
        if ( !isset ( $args [ '$subject' ] ) || !isset ( $args [ '$replace' ] ) || !isset ( $args [ '$search' ] ) )
        {
            return '';
        }

        return str_replace ( $args [ '$search' ], $args [ '$replace' ], $args [ '$subject' ] );
    }

    private function __str_ireplace ( $args )
    {
        if ( !isset ( $args [ '$subject' ] ) || !isset ( $args [ '$replace' ] ) || !isset ( $args [ '$search' ] ) )
        {
            return '';
        }

        return str_ireplace ( $args [ '$search' ], $args [ '$replace' ], $args [ '$subject' ] );
    }

    private function __str_pad ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) || !isset ( $args [ '$pad_length' ] ) )
        {
            return '';
        }

        $pad_type = STR_PAD_RIGHT;

        if ( isset ( $args [ '$pad_type' ] ) && defined ( $args [ '$pad_type' ] ) )
        {
            $pad_type = constant ( $args [ '$pad_type' ] );
        }

        return str_pad ( $args [ '$val' ], $args [ '$pad_length' ], common::get_param ( $args [ '$pad_string' ], ' ' ), $pad_type );
    }

    private function __str_repeat ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) || !isset ( $args [ '$multiplier' ] ) )
        {
            return '';
        }

        return str_repeat ( $args [ '$val' ], $args [ '$multiplier' ] );
    }

    private function __str_shuffle ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return str_shuffle ( $args [ '$val' ] );
    }

    private function __strip_tags ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return strip_tags ( $args [ '$val' ], common::get_param ( $args [ '$allowable_tags' ], '' ) );
    }

    private function __addslashes ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return addslashes ( $args [ '$val' ] );
    }

    private function __stripslashes ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return stripslashes ( $args [ '$val' ] );
    }

    private function __strpos ( $args )
    { 
        if ( !isset ( $args [ '$haystack' ] ) || !isset ( $args [ '$needle' ] ) )
        {
            return '';
        }

        return strpos ( $args [ '$haystack' ], $args [ '$needle' ], common::get_param ( $args [ '$offset' ], 0 ) );
    }

    private function __strrpos ( $args )
    { 
        if ( !isset ( $args [ '$haystack' ] ) || !isset ( $args [ '$needle' ] ) )
        {
            return '';
        }

        return strrpos ( $args [ '$haystack' ], $args [ '$needle' ], common::get_param ( $args [ '$offset' ], 0 ) );
    }

    private function __stripos ( $args )
    { 
        if ( !isset ( $args [ '$haystack' ] ) || !isset ( $args [ '$needle' ] ) )
        {
            return '';
        }

        return stripos ( $args [ '$haystack' ], $args [ '$needle' ], common::get_param ( $args [ '$offset' ], 0 ) );
    }

    private function __strripos ( $args )
    { 
        if ( !isset ( $args [ '$haystack' ] ) || !isset ( $args [ '$needle' ] ) )
        {
            return '';
        }

        return strripos ( $args [ '$haystack' ], $args [ '$needle' ], common::get_param ( $args [ '$offset' ], 0 ) );
    }

    private function __strstr ( $args )
    { 
        if ( !isset ( $args [ '$haystack' ] ) || !isset ( $args [ '$needle' ] ) )
        {
            return '';
        }

        return strstr ( $args [ '$haystack' ], $args [ '$needle' ], common::get_param ( $args [ '$before_needle' ], false ) );
    }

    private function __stristr ( $args )
    { 
        if ( !isset ( $args [ '$haystack' ] ) || !isset ( $args [ '$needle' ] ) )
        {
            return '';
        }

        return stristr ( $args [ '$haystack' ], $args [ '$needle' ], common::get_param ( $args [ '$before_needle' ], false ) );
    }

    private function __strrchr ( $args )
    { 
        if ( !isset ( $args [ '$haystack' ] ) || !isset ( $args [ '$needle' ] ) )
        {
            return '';
        }

        return strrchr ( $args [ '$haystack' ], $args [ '$needle' ] );
    }

    private function __strrev ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) )
        {
            return '';
        }

        return strrev ( $args [ '$val' ] );
    }

    private function __substr ( $args )
    { 
        if ( !isset ( $args [ '$val' ] ) || !isset ( $args [ '$start' ] ) )
        {
            return '';
        }

        return substr ( $args [ '$val' ], $args [ '$start' ], common::get_param ( $args [ '$length' ], null ) );
    }

    //Math
    private function __abs ( $args )
    {
        if ( !isset ( $args [ '$num' ] ) )
        {
            return '';
        }

        return abs ( $args [ '$num' ] );
    }

    private function __ceil ( $args )
    {
        if ( !isset ( $args [ '$num' ] ) )
        {
            return '';
        }

        return ceil ( $args [ '$num' ] );
    }

    private function __floor ( $args )
    {
        if ( !isset ( $args [ '$num' ] ) )
        {
            return '';
        }

        return floor ( $args [ '$num' ] );
    }

    private function __round ( $args )
    {
        if ( !isset ( $args [ '$num' ] ) )
        {
            return '';
        }

        $mode = PHP_ROUND_HALF_UP;

        if ( isset ( $args [ '$mode' ] ) && defined ( $args [ '$mode' ] ) )
        {
            $mode = constant ( $args [ '$mode' ] );
        }

        return round ( $args [ '$num' ], common::get_param ( $args [ '$precision' ], 0 ), $mode );
    }

    private function __mt_rand ( $args )
    {
        return mt_rand ( common::get_param ( $args [ '$min' ], null ), common::get_param ( $args [ '$max' ], null ) );
    }

    private function __pi ( $args )
    {
        return pi ();
    }

    private function __pow ( $args )
    {
        if ( !isset ( $args [ '$num' ] ) || !isset ( $args [ '$exp' ] ) )
        {
            return '';
        }

        return pow ( $args [ '$num' ], $args [ '$exp' ] );
    }

    private function __sqrt ( $args )
    {
        if ( !isset ( $args [ '$num' ] ) )
        {
            return '';
        }

        return sqrt ( $args [ '$num' ] );
    }
}

class SyntaxException extends Exception
{ 
    public function errorMessage ( $syntax, $e )
    { 
        $line = key ( $syntax -> cmd_list ) - 1;
        return 'XtScript Error on line '. $line .': <br />'. $syntax -> cmd_list [ $line ];
    }
}
