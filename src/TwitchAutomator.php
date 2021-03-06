<?php

namespace App;

use DateTime;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class TwitchAutomator {

	public $data_cache = [];

	public $json = [];

	public $errors = [];
	public $info = [];

	public $force_record;

	public $vod;

	const NOTIFY_GENERIC = 1;
	const NOTIFY_DOWNLOAD = 2;
	const NOTIFY_ERROR = 4;
	const NOTIFY_GAMECHANGE = 8;

	public $notify_level = self::NOTIFY_GENERIC && self::NOTIFY_DOWNLOAD && self::NOTIFY_ERROR && self::NOTIFY_GAMECHANGE;

	/**
	 * Generate a basename from the VOD payload
	 *
	 * @param array $data
	 * @return string basename
	 */
	public function basename( $data ){

		$data_id = $data['data'][0]['id'];
		$data_title = $data['data'][0]['title'];
		$data_started = $data['data'][0]['started_at'];
		$data_game_id = $data['data'][0]['game_id'];
		$data_username = $data['data'][0]['user_name'];

		// return $data_username . '_' . $data_id . '_' . str_replace(':', '_', $data_started);

		return $data_username . '_' . str_replace(':', '_', $data_started) . '_' . $data_id;

	}

	public function getDateTime(){
		date_default_timezone_set('UTC');
		return date("Y-m-d\TH:i:s\Z");
	}
	
	/**
	 * Either send email or store in logs directory
	 * TODO: remove this, obsolete
	 *
	 * @param string $body
	 * @param string $title
	 * @param int $notification_type
	 * @return void
	 */
	public function notify( $body, $title, $notification_type = self::NOTIFY_GENERIC ){

		$headers = "From: " . TwitchConfig::cfg('notify_from') . "\r\n";
		$headers .= "Content-Type: text/html; charset=UTF-8\r\n";

		$body = '<h1>' . $title . '</h1>' . $body;

		if($this->data_cache){
			$body .= '<pre>' . print_r( $this->data_cache, true ) . '</pre>';
		}

		if( sizeof($this->errors) > 0 ){

			$body .= '<h3>Errors</h3>';

			$body .= '<ul class="errors">';
			foreach ($this->errors as $k => $v) {
				$body .= '<li>' . $v . '</li>';
			}
			$body .= '</ul>';

		}

		if( sizeof($this->info) > 0 ){

			$body .= '<h3>Info</h3>';

			$body .= '<ul class="info">';
			foreach ($this->info as $k => $v) {
				$body .= '<li>' . $v . '</li>';
			}
			$body .= '</ul>';

		}

		if( TwitchConfig::cfg('notify_to') ){
			mail(TwitchConfig::cfg('notify_to'), TwitchConfig::cfg('app_name') . ' - ' . $title, $body, $headers);
		}else{
			file_put_contents( __DIR__ . "/../logs/" . date("Y-m-d.H_i_s") . ".html", $body);
		}

	}

	/**
	 * Remove old VODs by streamer name, this has to be properly rewritten
	 */
	public function cleanup( $streamer_name, $source_basename = null ){

		$vods = glob( TwitchHelper::vod_folder( $streamer_name ) . DIRECTORY_SEPARATOR . $streamer_name . "_*.json");

		$total_size = 0;

		$vod_list = [];

		foreach ($vods as $v) {
			
			$vodclass = new TwitchVOD();
			$vodclass->load($v);

			$vod_list[] = $vodclass;

			foreach($vodclass->segments_raw as $s){
				$total_size += filesize( TwitchHelper::vod_folder( $streamer_name ) . DIRECTORY_SEPARATOR . basename($s) );
			}
			
		}

		$gb = $total_size / 1024 / 1024 / 1024;

		$this->info[] = 'Total filesize for ' . $streamer_name . ': ' . $gb;
		TwitchHelper::log( TwitchHelper::LOG_INFO, 'Total filesize for ' . $streamer_name . ': ' . round($gb, 2));

		TwitchHelper::log( TwitchHelper::LOG_INFO, 'Amount for ' . $streamer_name . ': ' . sizeof($vod_list) . '/' . ( TwitchConfig::cfg('vods_to_keep') + 1 ) );

		// don't include the current vod
		if( sizeof($vod_list) > ( TwitchConfig::cfg('vods_to_keep') + 1 ) || $gb > TwitchConfig::cfg('storage_per_streamer') ){

			TwitchHelper::log( TwitchHelper::LOG_INFO, 'Total filesize for ' . $streamer_name . ' exceeds either vod amount or storage per streamer');

			// don't delete the newest vod, hopefully
			if( $source_basename != null && $vod_list[0]->basename == $source_basename ){
				TwitchHelper::log( TwitchHelper::LOG_ERROR, "Trying to cleanup latest VOD " . $vod_list[0]->basename);
				return false;
			}

			TwitchHelper::log( TwitchHelper::LOG_INFO, "Cleanup " . $vod_list[0]->basename);
			$vod_list[0]->delete();

		}

	}

	/**
	 * Entrypoint for stream capture
	 *
	 * @param array $data
	 * @return void
	 */
	public function handle( $data ){

		TwitchHelper::log( TwitchHelper::LOG_DEBUG, "Handle called");

		if( !$data['data'] ){
			TwitchHelper::log( TwitchHelper::LOG_ERROR, "No data supplied for handle");
			return false;
		}

		$data_id = $data['data'][0]['id'];
		// $data_title = $data['data'][0]['title'];
		// $data_started = $data['data'][0]['started_at'];
		// $data_game_id = $data['data'][0]['game_id'];
		$data_username = $data['data'][0]['user_name'];

		$this->data_cache = $data;

		if( !$data_id ){

			$this->end( $data );

		}else{

			$basename = $this->basename( $data );

			$folder_base = TwitchHelper::vod_folder( $data_username );
			
			if( file_exists( $folder_base . DIRECTORY_SEPARATOR . $basename . '.json') ){

				if( !file_exists( $folder_base. DIRECTORY_SEPARATOR . $basename . '.ts') ){

					$this->notify($basename, 'VOD JSON EXISTS BUT NOT VIDEO', self::NOTIFY_ERROR);

					$this->download( $data );

				}else{

					$this->updateGame( $data );

				}

			}else{

				$this->download( $data );

			}

		}

	}

	/**
	 * Add game/chapter to stream
	 *
	 * @param array $data
	 * @return void
	 */
	public function updateGame( $data ){

		$data_id 			= $data['data'][0]['id'];
		$data_started 		= $data['data'][0]['started_at'];
		$data_game_id 		= $data['data'][0]['game_id'];
		$data_username 		= $data['data'][0]['user_name'];
		$data_viewer_count 	= $data['data'][0]['viewer_count'];
		$data_title 		= $data['data'][0]['title'];

		$basename = $this->basename( $data );

		$folder_base = TwitchHelper::vod_folder( $data_username );

		if( $this->vod ){
			$this->vod->refreshJSON();
		}else{
			$this->vod = new TwitchVOD();
			if( $this->vod->load( $folder_base . DIRECTORY_SEPARATOR . $basename . '.json' ) ){
				// ok
			}else{
				$this->vod->create( $folder_base . DIRECTORY_SEPARATOR . $basename . '.json' );
			}
		}
		
		// $this->jsonLoad();

		// json format
	
		// full json data
		$this->vod->meta = $data;
		$this->vod->json['meta'] = $data;

		if( $this->force_record ) $this->vod->force_record = true;
		
		// full datetime-stamp of stream start
		// $this->json['started_at'] = $data_started;
		$this->vod->started_at = \DateTime::createFromFormat("Y-m-d\TH:i:s\Z", $data_started );

		// fetch game name from either cache or twitch
		$game_name = TwitchHelper::getGameName( $data_game_id );

		$chapter = [
			'time' 			=> $this->getDateTime(),
			'datetime'		=> new \DateTime(),
			'game_id' 		=> $data_game_id,
			'game_name'		=> $game_name,
			'viewer_count' 	=> $data_viewer_count,
			'title'			=> $data_title
		];
		
		$this->vod->addChapter($chapter);
		$this->vod->saveJSON('game update');

		$this->notify('', '[' . $data_username . '] [game update: ' . $game_name . ']', self::NOTIFY_GAMECHANGE);

		TwitchHelper::log( TwitchHelper::LOG_SUCCESS, "Game updated on " . $data_username . " to " . $game_name);

	}

	public function end(){

		$this->notify('', '[stream end]', self::NOTIFY_DOWNLOAD);

	}

	/**
	 * Start the download/capture process of a live stream
	 *
	 * @param array $data
	 * @param integer $tries
	 * @return void
	 */
	public function download( $data, $tries = 0 ){

		$data_id = $data['data'][0]['id'];
		$data_title = $data['data'][0]['title'];
		$data_started = $data['data'][0]['started_at'];
		$data_game_id = $data['data'][0]['game_id'];
		$data_username = $data['data'][0]['user_name'];

		if( !$data_id ){
			$this->errors[] = 'No data id for download';
			$this->notify($data, 'NO DATA SUPPLIED FOR DOWNLOAD, TRY #' . $tries, self::NOTIFY_ERROR);
			throw new \Exception('No data supplied');
			return;
		}

		// $this->notify('', '[' . $data_username . '] [prepare download]');

		$stream_url = 'twitch.tv/' . $data_username;

		$basename = $this->basename( $data );

		$folder_base = TwitchHelper::vod_folder( $data_username );
		
		$this->vod = new TwitchVOD();
		$this->vod->create( $folder_base . DIRECTORY_SEPARATOR . $basename . '.json' );

		$this->vod->meta = $data;
		$this->vod->json['meta'] = $data;
		$this->vod->streamer_name = $data_username;
		$this->vod->streamer_id = TwitchHelper::getChannelId( $data_username );
		$this->vod->started_at = \DateTime::createFromFormat("Y-m-d\TH:i:s\Z", $data_started );

		if( $this->force_record ) $this->vod->force_record = true;

		$this->vod->saveJSON('stream download');
		$this->vod->refreshJSON();
		
        
        $streamer = TwitchConfig::getStreamer( $data_username );

		// check matched title, broken?
		if( $streamer && isset( $streamer['match'] ) ){

			$match = false;

			$this->notify($basename, 'Check keyword matches for user ' . json_encode( $streamer ), self::NOTIFY_GENERIC);
			TwitchHelper::log( TwitchHelper::LOG_INFO, "Check keyword matches for " . $basename, ['download' => $data_username] );

			foreach( $streamer['match'] as $m ){
				if( strpos( strtolower($data_title), $m ) !== false ){
					$match = true;
					break;
				}
			}

			if(!$match){
				$this->notify($basename, 'Cancel download because stream title does not contain keywords', self::NOTIFY_GENERIC);
				TwitchHelper::log( TwitchHelper::LOG_WARNING, "Cancel download of " . $basename . " due to missing keywords", ['download' => $data_username] );
				return;
			}

		}

		$this->vod->is_capturing = true;
		$this->vod->saveJSON('is_capturing set');

		// in progress
		TwitchHelper::log( TwitchHelper::LOG_INFO, "Update game for " . $basename, ['download' => $data_username]);
		$this->updateGame( $data );

		// download notification
		$this->notify($basename, '[' . $data_username . '] [download]', self::NOTIFY_DOWNLOAD);
	
		// capture with streamlink
		$capture_filename = $this->capture( $data );

		// error handling if nothing got downloaded
		if( !file_exists( $capture_filename ) ){

			TwitchHelper::log( TwitchHelper::LOG_WARNING, "Panic handler for " . $basename . ", no captured file!");

			if( $tries >= TwitchConfig::cfg('download_retries') ){
				$this->errors[] = 'Giving up on downloading, too many tries';
				$this->notify($basename, 'GIVING UP, TOO MANY TRIES', self::NOTIFY_ERROR);
				TwitchHelper::log( TwitchHelper::LOG_ERROR, "Giving up on downloading, too many tries for " . $basename, ['download' => $data_username] );
				rename( $folder_base . DIRECTORY_SEPARATOR . $basename . '.json', $folder_base . DIRECTORY_SEPARATOR . $basename . '.json.broken' );
				throw new \Exception('Too many tries');
				return;
			}

			$this->errors[] = 'Error when downloading, retrying';

			$this->info[] = 'Capture name: ' . $capture_filename;

			$this->notify($basename, 'MISSING DOWNLOAD, TRYING AGAIN (#' . $tries . ')', self::NOTIFY_ERROR);
			
			TwitchHelper::log( TwitchHelper::LOG_ERROR, "Error when downloading, retrying " . $basename, ['download' => $data_username] );

			sleep(15);

			$this->download( $data, $tries + 1 );

			return;

		}

		// timestamp
		TwitchHelper::log( TwitchHelper::LOG_INFO, "Add end timestamp for " . $basename, ['download' => $data_username] );

		$this->vod->refreshJSON();
		$this->vod->ended_at = $this->getDateTime();
		$this->vod->dt_ended_at = new \DateTime();
		$this->vod->is_capturing = false;
		if( $this->stream_resolution ) $this->vod->stream_resolution = $this->stream_resolution;
		$this->vod->saveJSON('stream capture end');

		sleep(60);


		// convert notify
		$this->notify($basename, '[' . $data_username . '] [convert]', self::NOTIFY_DOWNLOAD);

		$this->vod->refreshJSON();
		$this->vod->is_converting = true;
		$this->vod->saveJSON('is_converting set');
		
		// convert with ffmpeg
		$converted_filename = $this->convert( $basename );

		sleep(10);
		
		// remove ts if both files exist
		if( file_exists( $capture_filename ) && file_exists( $converted_filename ) ){

			unlink( $capture_filename );

		}else{

			$this->errors[] = 'Video files are missing';

			$this->notify($basename, 'MISSING FILES', self::NOTIFY_ERROR);

		}

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Add segments to " . $basename, ['download' => $data_username] );
		$this->vod->refreshJSON();
		$this->vod->is_converting = false;
		// if(!$this->json['segments_raw']) $this->json['segments_raw'] = [];
		$this->vod->addSegment( $converted_filename );
		$this->vod->saveJSON('add segment');

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Cleanup old VODs for " . $data_username, ['download' => $data_username] );
		$this->cleanup( $data_username, $basename );

		$this->notify($basename, '[' . $data_username . '] [end]', self::NOTIFY_DOWNLOAD);

		// finalize

		// metadata stuff
		TwitchHelper::log( TwitchHelper::LOG_INFO, "Sleep 5 minutes for " . $basename, ['download' => $data_username] );
		sleep(60 * 5);

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Do metadata on " . $basename, ['download' => $data_username] );

		$vodclass = new TwitchVOD();
		$vodclass->load( $folder_base . DIRECTORY_SEPARATOR . $basename . '.json');

		// $vodclass->getDuration();
		// $vodclass->saveVideoMetadata();
		/*
		$vodclass->getMediainfo();
		$vodclass->saveLosslessCut();
		$vodclass->matchTwitchVod();
		$vodclass->is_finalized = true;
		*/
		$vodclass->finalize();
		$vodclass->saveJSON('finalized');
		
		if( ( TwitchConfig::cfg('download_chat') || ( TwitchConfig::getStreamer($data_username)['download_chat'] ) && $vodclass->twitch_vod_id ) ){
			TwitchHelper::log( TwitchHelper::LOG_INFO, "Auto download chat on " . $basename, ['download' => $data_username] );
			$vodclass->downloadChat();

			if( TwitchConfig::getStreamer($data_username)['burn_chat'] ){
				if( $vodclass->renderChat() ){
					$vodclass->burnChat();
				}
			}

		}

		// add to history, testing
		$history = file_exists( TwitchConfig::$historyPath ) ? json_decode( file_get_contents( TwitchConfig::$historyPath ), true ) : [];
		$history[] = [ 'streamer_name' => $this->vod->streamer_name, 'started_at' => $this->vod->started_at, 'ended_at' => $this->vod->ended_at, 'title' => $data_title ];
		file_put_contents( TwitchConfig::$historyPath, json_encode($history) );

		TwitchHelper::log( TwitchHelper::LOG_SUCCESS, "All done for " . $basename, ['download' => $data_username] );

	}

	/**
	 * Actually capture the stream with streamlink or youtube-dl
	 * Blocking function
	 *
	 * @param array $data
	 * @return string Captured filename
	 */
	public function capture( $data ){

		$data_id = $data['data'][0]['id'];
		$data_title = $data['data'][0]['title'];
		$data_started = $data['data'][0]['started_at'];
		$data_game_id = $data['data'][0]['game_id'];
		$data_username = $data['data'][0]['user_name'];

		if(!$data_id){
			$this->errors[] = 'ID not supplied for capture';
			return false;
		}
		
		$stream_url = 'twitch.tv/' . $data_username;

		$basename = $this->basename( $data );

		$folder_base = TwitchHelper::vod_folder( $data_username );

		$capture_filename = $folder_base . DIRECTORY_SEPARATOR . $basename . '.ts';

		// failure
		$int = 1;
		while( file_exists( $capture_filename ) ){
			$this->errors[] = 'File exists while capturing, making a new name';
			TwitchHelper::log( TwitchHelper::LOG_ERROR, "File exists while capturing, making a new name for " . $basename . ", attempt #" . $int, ['download-capture' => $data_username] );
			$capture_filename = $folder_base . DIRECTORY_SEPARATOR . $basename . '-' . $int . '.ts';
			$int++;
		}
		
		$cmd = [];

		// use python pipenv or regular executable
		if( TwitchConfig::cfg('pipenv') ){
			$cmd[] = 'pipenv run streamlink';
		}else{
			$cmd[] = TwitchHelper::path_streamlink();
		}

		$cmd[] = '--hls-live-restart'; // start recording from start of stream, though twitch doesn't support this
		
		$cmd[] = '--hls-live-edge';
		$cmd[] = '99999'; // How many segments from the end to start live HLS streams on.
		
		$cmd[] = '--hls-timeout';
		$cmd[] = TwitchConfig::cfg('hls_timeout', 120); // timeout due to ads

		$cmd[] = '--hls-segment-threads';
		$cmd[] = '5'; // The size of the thread pool used to download HLS segments.

		$cmd[] = '--twitch-disable-hosting'; // disable channel hosting
		
		if( TwitchConfig::cfg('low_latency', false) ){
			$cmd[] = '--twitch-low-latency'; // enable low latency mode, probably not a good idea without testing
		}
		if( TwitchConfig::cfg('disable_ads', false) ){
			$cmd[] = '--twitch-disable-ads'; // Skip embedded advertisement segments at the beginning or during a stream
		}
		
		$cmd[] = '--retry-streams';
		$cmd[] = '10'; // Retry fetching the list of available streams until streams are found 
		
		$cmd[] = '--retry-max';
		$cmd[] = '5'; //  stop retrying the fetch after COUNT retry attempt(s).
		
		if( TwitchConfig::cfg('debug', false) || TwitchConfig::cfg('app_verbose', false) ){
			$cmd[] = '--loglevel';
			$cmd[] = 'debug';
		}

		$cmd[] = '-o';
		$cmd[] = $capture_filename; // output file
		
		$cmd[] = '--url';
		$cmd[] = $stream_url; // twitch url
		
		$cmd[] = '--default-stream';
		$cmd[] = implode(",", TwitchConfig::getStreamer( $data_username )['quality'] ); // quality

		$this->info[] = 'Streamlink cmd: ' . implode(" ", $cmd);

		$this->vod->refreshJSON();
		$this->vod->dt_capture_started = new \DateTime();
		$this->vod->saveJSON('dt_capture_started set');

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Starting capture with filename " . basename($capture_filename), ['download-capture' => $data_username] );
		
		// $capture_output = shell_exec( $cmd );
		$process = new Process( $cmd, dirname($capture_filename), null, null, null );

		TwitchHelper::append_log("streamlink_" . $basename . "_stdout." . $int, "$ " . implode(" ", $cmd) );
		TwitchHelper::append_log("streamlink_" . $basename . "_stderr." . $int, "$ " . implode(" ", $cmd) );

		$process->run(function($type, $buffer) use($basename, $int, $data_username) {
			if (Process::ERR === $type) {
				// echo 'ERR > '.$buffer;
			} else {
				// echo 'OUT > '.$buffer;
			}
			
			preg_match("/stream:\s([0-9_a-z]+)\s/", $buffer, $matches);
			if($matches){
				$this->stream_resolution = $matches[1];
				TwitchHelper::log( TwitchHelper::LOG_INFO, "Stream resolution for " . $basename . ": " . $this->stream_resolution, ['download-capture' => $data_username] );
			}

			if( strpos($buffer, "404 Client Error") !== false ){
				TwitchHelper::log( TwitchHelper::LOG_WARNING, "Chunk removed for " . $basename . "!", ['download-capture' => $data_username] );
			}

			if( Process::ERR === $type ){
				TwitchHelper::append_log("streamlink_" . $basename . "_stderr." . $int, $buffer );
			}else{
				TwitchHelper::append_log("streamlink_" . $basename . "_stdout." . $int, $buffer );
			}
			
		});


		TwitchHelper::log( TwitchHelper::LOG_INFO, "Finishing capture with filename " . basename($capture_filename), ['download-capture' => $data_username] );

		$this->info[] = 'Streamlink output: ' . $process->getOutput();
		$this->info[] = 'Streamlink error: ' . $process->getErrorOutput();

		// file_put_contents( __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "streamlink_" . $basename . "_" . time() . ".log", "$ " . $cmd . "\n" . $capture_output);
		// file_put_contents( __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "streamlink_" . $basename . "_" . time() . "_stdout.log", "$ " . implode(" ", $cmd) . "\n" . $process->getOutput() );
		// file_put_contents( __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "streamlink_" . $basename . "_" . time() . "_stderr.log", "$ " . implode(" ", $cmd) . "\n" . $process->getErrorOutput() );


		// download with youtube-dl if streamlink fails
		if( strpos($process->getOutput(), '410 Client Error') !== false ){
			
			$this->notify($basename, '410 Error', self::NOTIFY_ERROR);
			// return false;
			
			if( TwitchConfig::cfg('pipenv') ){
				$cmd = 'pipenv run youtube-dl';
			}else{
				$cmd = TwitchHelper::path_youtubedl();
			}
			
			$cmd .= ' --hls-use-mpegts'; // use ts instead of mp4
			$cmd .= ' --no-part';
			$cmd .= ' -o ' . escapeshellarg($capture_filename); // output file
			$cmd .= ' -f ' . escapeshellarg( implode('/', TwitchConfig::getStreamer( $data_username )['quality'] ) ); // format, does this work?
			if( TwitchConfig::cfg('debug', false) || TwitchConfig::cfg('app_verbose', false) ) $cmd .= ' -v';
			$cmd .= ' ' . escapeshellarg($stream_url);

			$this->info[] = 'Youtube-dl cmd: ' . $cmd;

			$capture_output = shell_exec( $cmd );

			$this->info[] = 'Youtube-dl output: ' . $capture_output;

			// file_put_contents( __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "youtubedl_" . $basename . "_" . time() . ".log", "$ " . $cmd . "\n" . $capture_output);
			TwitchHelper::append_log( "youtubedl_" . $basename . "_" . time(), "$ " . $cmd . "\n" . $capture_output );

			// exit(500);
		}

		if( strpos($capture_output, 'already exists, use') !== false ){
			TwitchHelper::log( TwitchHelper::LOG_FATAL, "Unexplainable, " . basename($capture_filename) . " could not be captured due to existing file already.", ['download-capture' => $data_username] );
		}

		preg_match("/stream:\s([0-9_a-z]+)\s/", $capture_output, $matches);
		if($matches){
			$this->stream_resolution = $matches[1];
		}

		return $capture_filename;

	}

	/**
	 * Mux .ts to .mp4, for better compatibility
	 *
	 * @param string $basename Basename of input file
	 * @return string Converted filename
	 */
	public function convert( $basename ){

		$container_ext = TwitchConfig::cfg('vod_container', 'mp4');

		$folder_base = TwitchHelper::vod_folder( $this->vod->streamer_name );

		$capture_filename 	= $folder_base . DIRECTORY_SEPARATOR . $basename . '.ts';

		$converted_filename = $folder_base . DIRECTORY_SEPARATOR . $basename . '.' . $container_ext;

		$data_username = $this->vod->streamer_name;

		$int = 1;

		while( file_exists( $converted_filename ) ){
			$this->errors[] = 'File exists while converting, making a new name';
			TwitchHelper::log( TwitchHelper::LOG_ERROR, "File exists while converting, making a new name for " . $basename . ", attempt #" . $int, ['download-convert' => $data_username]);
			$converted_filename = $folder_base . DIRECTORY_SEPARATOR . $basename . '-' . $int . '.' . $container_ext;
			$int++;
		}

		$cmd = TwitchHelper::path_ffmpeg();
		$cmd .= ' -i ' . escapeshellarg($capture_filename); // input filename
		$cmd .= ' -codec copy'; // use same codec
		$cmd .= ' -bsf:a aac_adtstoasc'; // fix audio sync in ts
		if( TwitchConfig::cfg('debug', false) || TwitchConfig::cfg('app_verbose', false) ) $cmd .= ' -loglevel repeat+level+verbose';
		$cmd .= ' ' . escapeshellarg($converted_filename); // output filename
		$cmd .= ' 2>&1'; // console output
		
		$this->info[] = 'ffmpeg cmd: ' . $cmd;

		$this->vod->refreshJSON();
		$this->vod->dt_conversion_started = new \DateTime();
		$this->vod->saveJSON('dt_conversion_started set');

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Starting conversion of " . basename($capture_filename) . " to " . basename($converted_filename), ['download-convert' => $data_username] );

		$output_convert = shell_exec( $cmd ); // do it

		if( file_exists( $converted_filename ) ){
			TwitchHelper::log( TwitchHelper::LOG_SUCCESS, "Finished conversion of " . basename($capture_filename) . " to " . basename($converted_filename), ['download-convert' => $data_username] );
		}else{
			TwitchHelper::log( TwitchHelper::LOG_ERROR, "Failed conversion of " . basename($capture_filename) . " to " . basename($converted_filename), ['download-convert' => $data_username] );
		}

		$this->info[] = 'ffmpeg output: ' . $output_convert;

		// file_put_contents( __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . "convert_" . $basename . "_" . time() . ".log", "$ " . $cmd . "\n" . $output_convert);
		TwitchHelper::append_log( "convert_" . $basename . "_" . time(), "$ " . $cmd . "\n" . $output_convert );

		return $converted_filename;

	}

}
