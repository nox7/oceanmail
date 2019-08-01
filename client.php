<?php
	mail("test@localhost.com", "Test Email", "Content", [
		"From"=>"test@example.com",
		"Return-Path"=>"garet@footbridgemedia.com",
		"Content-Type"=>"text/html; charset=UTF-8;",
	]);

	function sendmail( $param )
{
    $from    = &$param[ 'from' ];
    $to      = &$param[ 'to' ];
    $message = &$param[ 'data' ];

    $isError = function( $string )
    {
        if( preg_match( '/^((\d)(\d{2}))/', $string, $matches ) )
        {
            if( $matches[ 2 ] == 4 || $matches[ 2 ] == 5 ) return( $matches[ 1 ] );
        }
        else
        {
            return( false );
        }
    };

    try
    {
        $socket = null;
        if( ( $socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP ) ) == false )
        {
            throw new Exception( sprintf( "Unable to create a socket: %s", socket_strerror( socket_last_error() ) ) );
        }
        if( !socket_connect( $socket, MAIL_SERVER, MAIL_PORT ) )
        {
            throw new Exception( sprintf( "Unable to connect to server %s: %s", MAIL_SERVER, socket_strerror( socket_last_error() ) ) );
        }
        $read = socket_read( $socket, 1024 );
        if( $read == false )
        {
            throw new Exception( sprintf( "Unable to read from socket: %s", socket_strerror( socket_last_error() ) ) );
        }

        if( socket_write( $socket, sprintf( "HELO %s\r\n", gethostname() ) ) === false )
        {
            throw new Exception( sprintf( "Unable to write to socket: %s", socket_strerror( socket_last_error() ) ) );
        }
        $read = socket_read( $socket, 1024 );
        if( $read == false )
        {
            throw new Exception( sprintf( "Unable to read from socket: %s", socket_strerror( socket_last_error() ) ) );
        }
        else
        {
            if( ( $errCode = $isError( $read ) ) ) throw new Exception( "Server responded with an error code $errCode" );
        }

        if( socket_write( $socket, sprintf( "MAIL FROM: %s\r\n", $from ) ) === false )
        {
            throw new Exception( sprintf( "Unable to write to socket: %s", socket_strerror( socket_last_error() ) ) );
        }
        $read = socket_read( $socket, 1024 );
        if( $read == false )
        {
            throw new Exception( sprintf( "Unable to read from socket: %s", socket_strerror( socket_last_error() ) ) );
        }
        else
        {
            if( ( $errCode = $isError( $read ) ) ) throw new Exception( "Server responded with an error code $errCode" );
        }
        /* And some more code, but not enough place in comment */
        return( $totalWriten );
    }
    catch( Exception $e )
    {
        $ERROR = sprintf( "Error sending mail message at line %d. ", $e->getLine() ) . $e->getMessage();
        return( false );
    }
}
