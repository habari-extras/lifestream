<?php

//require 'simplepie.php';
require 'idna_convert.php';

class LifeStream extends Plugin
{
	const VERSION= '1.0';
	
	public function info()
	{
		return array (
			'name' => 'CJD Lifestream',
			'url' => 'http://chrisjdavis.org/',
			'author' => 'Chris J. Davis and Drunken Monkey Labs',
			'authorurl' => 'http://chrisjdavis.org/',
			'version' => self::VERSION,
			'description' => 'Lifestream for Habari.',
			'license' => 'GPL',
		);
	}
	
	public function action_init() {
		DB::register_table( 'l_data' );
		DB::register_table( 'l_source' );
		
		switch( DB::get_driver_name() ) { 
			case 'sqlite':
				$q= '';
				$q= "CREATE TABLE " . DB::table('l_source') . "( 
					id INTEGER NOT NULL AUTOINCREMENT,
					title VARCHAR(255) NOT NULL,
					profile_url VARCHAR(255) NOT NULL,
					feed_url VARCHAR(255) NOT NULL,
					favicon VARCHAR(255) NOT NULL,
					CREATE UNIQUE INDEX IF NOT EXISTS id ON ". DB::table('l_source') . "(id);
				);";
				
				$q .= "CREATE TABLE " . DB::table('l_data') . "(
					id INTIGER NOT NULL AUTOINCREMENT,
					name VARCHAR(255) NOT NULL,
					content TEXT NOT NULL,
					date VARCHAR(255) NOT NULL,
					link VARCHAR(255) NOT NULL,
					enabled TINYINT(1) NOT NULL,
					CREATE UNIQUE INDEX IF NOT EXISTS id ON ". DB::table('l_data') . "(id);
				);";
				return $sql = DB::dbdelta( $q );
			break;
			case 'mysql' :
				$q= '';
				$q= "CREATE TABLE " . DB::table('l_source') . "( 
					id INT UNSIGNED NOT NULL AUTO_INCREMENT,
					title VARCHAR(255) NOT NULL,
					profile_url VARCHAR(255) NOT NULL,
					feed_url VARCHAR(255) NOT NULL,
					favicon VARCHAR(255) NOT NULL,
					UNIQUE KEY id (id)
				);";
				$q .= "CREATE TABLE " . DB::table('l_data') . "(
					id INT UNSIGNED NOT NULL AUTO_INCREMENT,
					name VARCHAR(255) NOT NULL,
					content TEXT NOT NULL,
					date VARCHAR(255) NOT NULL,
					link VARCHAR(255) NOT NULL,
					enabled TINYINT(1) NOT NULL,
					UNIQUE KEY id (id)
				);";
				return $sql = DB::dbdelta( $q );
			break;
			case 'postgresql' :
				// need to figure out what schema changes are needed for postgreSQL
			break;	
	}
}
	
	public function filter_rewrite_rules( $rules ) {
		$rules[] = new RewriteRule(array(
			'name' => 'lifestream',
			'parse_regex' => '/^lifestream[\/]{0,1}$/i',
			'build_str' => 'lifestream',
			'handler' => 'LifeStreamHandler',
			'action' => 'display_lifestream',
			'priority' => 7,
			'is_active' => 1,
		));
		
		return $rules;
	}
	
	function action_update_check() {
		Update::add( 'Lifestream', '122b28dc-0861-11dc-8314-0800200c9a66', self::VERSION ); 
	}
	
	/**
	 * Executes when the admin plugins page wants to know if plugins have configuration links to display.
	 * 
	 * @param array $actions An array of existing actions for the specified plugin id. 
	 * @param string $plugin_id A unique id identifying a plugin.
	 * @return array An array of supported actions for the named plugin
	 */
	public function filter_plugin_config( $actions, $plugin_id ) {
		// Is this plugin the one specified?
		if( $plugin_id == $this->plugin_id ) {
			// Add a 'configure' action in the admin's list of plugins
			$actions[]= 'Configure';
		}
		return $actions;
	}
	
	/**
	 * Executes when the admin plugins page wants to display the UI for a particular plugin action.
	 * Displays the plugin's UI.
	 * 
	 * @param string $plugin_id The unique id of a plugin
	 * @param string $action The action to display
	 */

	public function action_plugin_ui( $plugin_id, $action ) {
		// Display the UI for this plugin?
		if( $plugin_id == $this->plugin_id ) {
			// Depending on the action specified, do different things
			switch($action) {
			// For the action 'configure':
			case 'Configure':
				// Create a new Form called 'lifestream'
				$ui = new FormUI( 'lifestream' );
				// Add a text control for the feed URL
				$feedurl= $ui->append('text', 'lifeurl', 'lifestream__lifeurl', _t('Lifestream URL'));
				// Mark the field as required
				$feedurl->add_validator('validate_required');
				// Mark the field as requiring a valid URL
				$feedurl->add_validator('validate_url');
				
				$submit= $ui->append( 'submit', 'submit', _t('Save') );

				// Display the form
				$ui->out();
				break;
			}
		}
	}
	
}

class LifeStreamHandler extends ActionHandler
{
	private $stream_contents;
	private $config;
	private $theme= null;
	
	public function __construct() {
		$this->theme= Themes::create();
	}
	
	public function act_display_lifestream() {
		$this->archive_feeds();
		$this->theme->assign( 'lifestream', $this->stream_contents );
		$this->theme->assign( 'title', 'Lifestream - ' . Options::get( 'title' ) );
		$this->theme->assign( 'streams', $this->config->stream );
		$this->theme->display( 'lifestream' );
	}

	private function fetch_remote_file( $file ) {

		$path = parse_url( $file );

		if ($fs = @fsockopen($path['host'], isset($path['port'])?$path['port']:80)) {

			$header = "GET " . $path['path'] . " HTTP/1.0\r\nHost: " . $path['host'] . "\r\n\r\n";

			fwrite($fs, $header);

			$buffer = '';

			while ($tmp = fread($fs, 1024)) { $buffer .= $tmp; }

			preg_match('/HTTP\/[0-9\.]{1,3} ([0-9]{3})/', $buffer, $http);
			preg_match('/Location: (.*)/', $buffer, $redirect);

			if (isset($redirect[1]) && $file != trim($redirect[1])) { return self::fetch_remote_file(trim($redirect[1])); }

			if (isset($http[1]) && $http[1] == 200) { return substr($buffer, strpos($buffer, "\r\n\r\n") +4); } else { return false; }

		} else { return false; }

	}
	
	private function favicache( $feed, $name ) {
		$folder= '/user/plugins/lifestream/images/';

		if ( !is_dir( ABSPATH . $folder ) ) { 
			mkdir( ABSPATH . $folder, 0777 ); 
		}

		$url= parse_url( $feed );

		$cache= self::fetch_remote_file( 'http://' . $url['host'] . '/favicon.ico' );
		
		if ( !$cache ) {
			preg_match( '/<link.*(?:rel="icon" href="(.*)"|href="(.*)" rel="icon").*>/U',
			self::fetch_remote_file( $_POST['link_url'] ), $matches );
			$cache= self::fetch_remote_file( $matches[1] );
		}

		if ( $cache ) {
			file_put_contents( HABARI_PATH . '/' . $folder . md5( $url['host'] ) . '.ico', $cache );
			$icon= get_option( 'siteurl' ) . $folder . md5( $url['host'] ) . '.ico';
			} elseif( is_file( HABARI_PATH  . '/' . 'user/plugins/lifestream/images/icon.gif' ) ) {
				$icon= Site::get_url( 'habari' ) . 'user/plugins/lifestream/images/icon.gif';
		}
		
		$wpdb->query( 'UPDATE `' . DB::table( 'l_source' ) . '` SET `favicon` = "' . $icon . '" WHERE `title` = "' . $name . '"' );
		return false;
	}
	
	/**
	* Grab all the sources we have stored in the db.
	* <code>
	* foreach( $streams->collect() as $source ) {
	* 	echo $source->title;	
	* }
	* </code>
	*/
	public function collect() {
		$sources= DB::get_results( "SELECT * FROM " . DB::table('l_data') );
		if( is_array( $sources ) ) {
			return $sources;
		} else {
			return array();
		}
	}	
	
	private function get_feeds() {
		$this->config= simplexml_load_file( dirname( __FILE__ ) . '/lifestream.config.xml' );
		foreach ( $this->config->stream as $stream ) {
			$feed = new SimplePie( (string) $stream['feedURL'], HABARI_PATH . '/' . (string) $this->config->cache['location'], (int) $this->config->cache['expire'] );
			$feed->handle_content_type();
			if( $feed->data ) {
				foreach( $feed->get_items() as $entry ) {
					$name= $stream['name'];
					$date = strtotime( substr( $entry->get_date(), 0, 25 ) );
					$this->stream_contents[$date]['name']= (string) $name;
					$this->stream_contents[$date]['title']= $entry->get_title();
					$this->stream_contents[$date]['link']= $entry->get_permalink();
					$this->stream_contents[$date]['date']= strtotime( substr( $entry->get_date(), 0, 25 ) );
					if ( $enclosure = $entry->get_enclosure( 0 ) ) {
						$this->stream_contents[$date]['enclosure'] = $enclosure->get_link();
					}
				}
			}
		}
		krsort( $this->stream_contents );
		return $this->stream_contents;
	}
	
	public function archive_feeds() {
		foreach( self::get_feeds() as $archive ) {
			if( !DB::exists( DB::table('l_data'), array( 'link' => $archive['link'], 'date' => $archive['date'] ) ) ) {
				$insert= array();
				$insert['name']= addslashes( $archive['name'] );
				$insert['content']= addslashes( $archive['title'] );
				$insert['date']= $archive['date'];
				$insert['link']= $archive['link'];
				$insert['enabled']= 1;
				return DB::insert( DB::table( 'l_data' ), $insert );
			}
		}
	}
	
	/**
	* Method to grab all of our lifestream data from the DB.
	* <code>
	* foreach( $streams->show_streams() as $stream ) {
	*	// do something clever
	* }
	* </code>
	*/
	public function show_streams() {
		$show= DB::get_results( "SELECT * FROM " . DB::table( 'l_data' ) . " WHERE enabled = 1 ORDER BY date DESC" );
		return $show;
	}
	
	public function source( $name ) {
		$which= DB::get_results( "SELECT profile_url, favicon FROM " . DB::table( 'l_source' ) ." WHERE title = '$name'" );
		return $which[0];
	}
	
	public function legend_types() {
		$types= DB::get_results( "SELECT * FROM " . DB::table( 'l_source' ) ." ORDER BY title" );
		return $types;
	}	
}

?>