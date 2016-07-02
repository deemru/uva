<?php

$UVA_CAMERAS = array(
    array( 'id' => '2cdb6366-f1f2-32f3-b37f-a0db92f52228', 'name' => 'Garadj_Dome', 'jam' => true ),
    array( 'id' => '07c5dd79-681c-3d88-90dd-e6cc9d217825', 'name' => 'Letniy_Domik', 'jam' => true ),
    array( 'id' => '68c50d5a-53d0-396e-94dc-3e1213238652', 'name' => 'Garadj_Prud', 'jam' => true ),
    array( 'id' => 'f3987a0c-008b-3e00-bc37-af1761d8a65d', 'name' => 'Garadj_Parad', 'jam' => true ),
    array( 'id' => 'f36fb0d8-b650-3937-93ef-2093f8d9ffc3', 'name' => 'Dacha_Vhod', 'jam' => false ),
);

define( 'UVA_OUTPUT_DIRECTORY', 'P:/Camera_Timelapser/' );
define( 'UVA_CAMERA_DIRECTORY', 'R:/Cameras/' );
define( 'UVA_TEMP_DIRECTORY', 'C:/Temp/' );
define( 'UVA_FFMPEG', 'C:/_lll_/work/ffmpeg.exe' ); // or avconv

define( 'UVA_MAX_DURATION_NO_MOTION', 20 );
define( 'UVA_MAX_DURATION', 60 );

define( 'UVA_FPS', 30 );
define( 'UVA_CRF', 21 );

date_default_timezone_set( 'Europe/Moscow' );
define( 'UVA_START_WORK', 5 ); // 5 o'clock (avoid 0..3)

function uva_cleanup()
{
    $files = glob( UVA_TEMP_DIRECTORY . '*.jpg' );
    foreach( $files as $file )
        unlink( $file );
}

function uva_cmp( $a, $b )
{
    $a = substr( $a, strrpos( $a, '/' ) + 1 );
    $a = substr( $a, 0, strpos( $a, '_' ) );
    $b = substr( $b, strrpos( $b, '/' ) + 1 );
    $b = substr( $b, 0, strpos( $b, '_' ) );

    return $a < $b ? -1 : 1;
}

function uva_get_segments( $dir )
{
    $segments = glob( "$dir*.mp4");

    $files = array();
    $starts = array();
    $ends = array();
    $n = 0;

    foreach( $segments as $segment )
    {
        $files[ $n ] = $segment;

        $segment = substr( $segment, strrpos( $segment, '/' ) + 1 );
        list( $start, $end ) = explode( '_', $segment );

        $starts[ $start ] = $n;
        $ends[ $end ] = $n;

        $n++;
    }

    return array( 'files' => $files, 'starts' => $starts, 'ends' => $ends );
}

function uva_get_jsons( $dir )
{
    $jsons = glob( "{$dir}meta/*.json");

    $starts = array();
    $ends = array();
    $times = array();
    $types = array();

    foreach( $jsons as $json )
    {
        $json = json_decode( file_get_contents( $json ) );

        if( empty( $json->eventType ) || empty( $json->startTime ) || empty( $json->startTime ) )
            continue;

        $starts[] = (string)$json->startTime;
        $ends[] = (string)$json->endTime;
        $times[] = ( $json->endTime - $json->startTime ) / 1000;;
        $motions[] = $json->eventType == 'motionRecording';
    }

    return array( 'starts' => $starts, 'ends' => $ends, 'times' => $times, 'motions' => $motions );
}

function uva_log( $type, $msg = '' )
{
    if( $type == 'e' )
        $type = 'ERROR: ';
    else
    if( $type == 'w' )
        $type = 'WARNING: ';
    else
    if( $type == 'i' )
        $type = 'INFO: ';
    else
    if( $type == 'ir' )
    {
        $type = 'INFO: ';
        echo "\r" . date('Y.m.d H:i:s ') . "$type$msg\t";
        return;
    }
    else
    if( $type == 'hr' )
    {
        $type = '-----------------------------------------------------------';
    }

    echo date('Y.m.d H:i:s ') . "$type$msg" . PHP_EOL;
}

function uva_run( $cameras, $date_work )
{
    foreach( $cameras as $camera )
    {
        $day_back = 0;
        $max_jumps = 5;

        uva_log( 'i', $camera['name'] );

        while( true )
        {
            $date_back = $date_work - 86400 * ++$day_back;

            $video_file = UVA_OUTPUT_DIRECTORY . date( 'Ymd__', $date_back ) . $camera['name'] . '.mp4';

            if( file_exists( $video_file ) )
                continue;

            $camera_dir = UVA_CAMERA_DIRECTORY . $camera['id'] . date( '/Y/m/d/', $date_back );

            if( !file_exists( $camera_dir ) )
            {
                if( --$max_jumps )
                    continue;

                uva_log( 'i', 'OK' );
                break;
            }

            uva_log( 'i', 'Making ' . substr( $video_file, strrpos( $video_file, '/' ) + 1 ) );

            uva_log( 'i', "Getting jsons..." );
            $jsons = uva_get_jsons( $camera_dir );
            if( $jsons == FALSE || sizeof( $jsons ) == 0 )
                continue;
            uva_log( 'i', "OK" );

            uva_log( 'i', "Getting segments..." );
            $segments = uva_get_segments( $camera_dir );
            if( $segments == FALSE || sizeof( $segments ) == 0 )
                continue;
            uva_log( 'i', "OK" );

            $starts = &$jsons['starts'];
            $ends = &$jsons['ends'];
            $times = &$jsons['times'];
            $motions = &$jsons['motions'];

            $n = sizeof( $times );
            $motions_count = 0;

            for( $i = 0; $i < $n; $i++ )
                if( $motions[ $i ] )
                {
                    $motions_count++;
                    if( $motions_count == 1 )
                    {
                        $time_min = $times[ $i ];
                        $time_max = $times[ $i ];
                        $time_aprox = $times[ $i ];
                    }
                    else
                    {
                        $time_min = min( $time_min, $times[ $i ] );
                        $time_max = max( $time_max, $times[ $i ] );
                        $time_aprox = $time_aprox * $i + $times[ $i ] ) / ( $i + 1 );
                    }
                }
            uva_log( 'i', "Motion count - $motions_count" );
            if( $motions_count )
            {
              uva_log( 'i', 'Minimum motion time - ' . round( $time_min, 2 ) );
              uva_log( 'i', 'Maximum motion time - ' . round( $time_max, 2 ) );
              uva_log( 'i', 'Average motion time - ' . round( $time_aprox, 2 ) );
            }

            $skippers = array();

            for( $i = 0; $i < $n; $i++ )
            {
                $skippers[ $i ] = 1;

                if( $motions[ $i ] == false || $camera['jam'] == false )
                    continue;

                $time = $times[ $i ];

                for( $q = 64; $q > 0.125; $q /= 2 )
                    if( $time > $time_aprox * $q )
                        $skippers[ $i ] = $time / 128 / $q;

                $skippers[ $i ] = max( $skippers[ $i ], 1 );
            }

            $renders = array();

            for( $i = 0; $i < $n; $i++ )
            {
                if( $motions[ $i ] == false )
                    continue;

                if( !isset( $segments['starts'][ $starts[ $i ] ] ) ||
                    !isset( $segments['ends'][ $ends[ $i ] ] ) )
                    continue;

                $start = $segments['starts'][ $starts[ $i ] ];
                $end = $segments['ends'][ $ends[ $i ] ];
                $skipper = $skippers[ $i ];
                $count = ceil( ( $end - $start ) / $skipper );

                for( $j = 0; $j < $count; $j++ )
                    $renders[ $segments['files'][ ceil( $start + $j * $skipper ) ] ] = true;
            }

            uva_log( 'i', 'Motion segments - ' . sizeof( $renders ) );

            $n = sizeof( $segments['files'] );

            if( $n > UVA_FPS * UVA_MAX_DURATION_NO_MOTION )
            {
                $q = $n / UVA_FPS / UVA_MAX_DURATION_NO_MOTION;

                for( $i = 0; $i < UVA_FPS * UVA_MAX_DURATION_NO_MOTION; $i++ )
                    $renders[ $segments['files'][ ceil( $i * $q ) ] ] = true;
            }

            uva_log( 'i', 'Total segments - ' . sizeof( $renders ) );

            $renders = array_keys( $renders );
            usort( $renders, 'uva_cmp' );

            $n = sizeof( $renders );

            if( $n > UVA_FPS * UVA_MAX_DURATION )
            {
                $q = $n / UVA_FPS / UVA_MAX_DURATION;

                $renders_final = array();

                for( $i = 0; $i < UVA_FPS * UVA_MAX_DURATION; $i++ )
                    $renders_final[] = $renders[ ceil( $i * $q ) ];
            }
            else
                $renders_final = $renders;

            $total_segments = sizeof( $renders_final );

            uva_log( 'i', 'Final segments - ' . $total_segments );

            if( $total_segments == 0 )
            {
                uva_log( 'w', 'Skip' );
                continue;
            }

            uva_log( 'i', 'Processing segments...' );

            $c = 0;

            $temp_file = UVA_TEMP_DIRECTORY . 'temp.jpg';

            foreach( $renders_final as $file )
            {
                exec( '"' . UVA_FFMPEG . "\" -y -i \"$file\" -f mjpeg -vframes 1 \"$temp_file\" 2>&1" );

                clearstatcache();
                if( !file_exists( $temp_file ) || filesize( $temp_file ) == 0 )
                {
                    uva_log( 'e', $file );
                    continue;
                }

                rename( $temp_file, sprintf( UVA_TEMP_DIRECTORY . 'image_%010d.jpg', ++$c ) );
                uva_log( 'ir', "$c / $total_segments" );
            }

            uva_log( 'i', 'OK' );

            exec( '"' .UVA_FFMPEG . '" -r ' . UVA_FPS . ' -y -i "' . UVA_TEMP_DIRECTORY . 'image_%010d.jpg" -vcodec libx264 -crf '. UVA_CRF . " \"$video_file\"" );
            uva_cleanup();
        }
    }
}

uva_log( 'i', 'UniFi Video Archive' );

while( true )
{
    $date_work = time();
    uva_log( 'hr' );
    uva_cleanup();

    if( !file_exists( UVA_OUTPUT_DIRECTORY ) )
    {
        uva_log( 'e', UVA_OUTPUT_DIRECTORY );
        uva_log( 'i', 'Sleeping for 1 hour...' );
        sleep( 3600 );
        continue;
    }

    uva_run( $UVA_CAMERAS, $date_work );

    $date_work = time() - $date_work;
    uva_log( 'i', sprintf( 'Work done in %02d:%02d:%02d', floor( $date_work / 3600 ), floor( $date_work / 60 ) % 60, $date_work % 60 ) );
    $date_work = mktime( UVA_START_WORK, 0, 0, date( 'm' ), date( 'd' ) + 1, date( 'Y' ) );
    uva_log( 'i', 'Sleep until '. date( 'Y.m.d H:i:s', $date_work ) );
    $date_work = $date_work - time();
    sleep( $date_work );
}

?>
