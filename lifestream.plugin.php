<?php
require 'idna_convert.php';
require 'simplepie.php';

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
				$q = "CREATE TABLE " . DB::table('l_data') . "(
					id INTIGER NOT NULL AUTOINCREMENT,
					name VARCHAR(255) NOT NULL,
					content TEXT NOT NULL,
					data BLOB NOT NULL,
					date VARCHAR(255) NOT NULL,
					link VARCHAR(255) NOT NULL,
					enabled TINYINT(1) NOT NULL,
					CREATE UNIQUE INDEX IF NOT EXISTS id ON ". DB::table('l_data') . "(id);
				);";
				return $sql = DB::dbdelta( $q );
			break;
			case 'mysql' :
				$q = "CREATE TABLE " . DB::table('l_data') . "(
					id INT UNSIGNED NOT NULL AUTO_INCREMENT,
					name VARCHAR(255) NOT NULL,
					content TEXT NOT NULL,
					data BLOB NOT NULL,
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
		
		$this->add_template('lifestream_template', dirname(__FILE__) . '/lifestream_template.php');
		
	}
	
	public function filter_rewrite_rules( $rules ) {
		$rules[] = new RewriteRule(array(
			'name' => 'lifestream',
			'parse_regex' => '%^' . Options::get('lifestream__lifeurl') . '(/type/(?P<type>[^/]*))?(?:/page/(?P<page>\d+))?/?$%i',
			'build_str' => Options::get('lifestream__lifeurl') . '(/{$type})(/page/{$page})',
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
				$feedurl= $ui->append('text', 'feedurl', 'lifestream__feedurl', _t('Feed URL'));
				// Mark the field as required
				$feedurl->add_validator('validate_required');
				// Mark the field as requiring a valid URL
				$feedurl->add_validator('validate_url');
				
				// Add a text control for the rewrite base
				$rewritebase= $ui->append('text', 'lifeurl', 'lifestream__lifeurl', _t('Lifestream URL'));
				// Mark the field as required
				$rewritebase->add_validator('validate_required');
				
				// Add a text control for the entries per page
				$perpage= $ui->append('text', 'perpage', 'lifestream__perpage', _t('Items Per Page'));
				// Mark the field as required
				$perpage->add_validator('validate_required');
				
				$submit= $ui->append( 'submit', 'submit', _t('Save') );

				// Display the form
				$ui->out();
				break;
			}
		}
	}
	
	public function insert( $entries = array() ) {
		foreach( $entries as $entry) {
			$check= DB::get_results( "SELECT ID FROM " . DB::table( 'l_data' ) . ' WHERE link= "' . $entry['link'] . '"' );
			if( !$check ) {
				DB::insert( DB::table( 'l_data' ), $entry );
			}
		}
	}

	public function get_entries($type = 'any', $offset = 0, $number = 20, $format = 'object', $start = null, $end = null) {
		$query= '';
		$query.= 'SELECT * FROM ' . DB::table('l_data');
		
		if($type != 'any' && $start == null && $end == null) {
			$query.= " WHERE name= '$type'";
		}
		
		if($start != null && $end != null) {
			$query.= " WHERE date BETWEEN $start AND $end";
			if($type != 'any') {
				$query.= " AND name= '$type'";
			}
		}
		
		$query.= ' ORDER BY date DESC';
		if($number != null) {
			$query.= ' LIMIT ' . $offset . ', ' . $number;
		}
		
		$results = DB::get_results( $query );

		if($format == 'object') {
			return $results;
		} elseif($format == 'array') {
			$return = array();
			foreach($results as $result) {
				$return[] = $result->to_array();
			}
			return $return;
		} else {
			return $results;
		}
		
	}
	
	public function action_ajax_lifestream_entries($type = 'any', $offset = 0, $number = 20) {
		echo json_encode($this->get_entries($type, $offset, $number, 'array'));
	}
	
}

class LifeStreamHandler extends ActionHandler
{
	private $stream_contents;
	private $config;
	private $streams;
	private $theme= null;
	
	public function __construct() {
		$this->config= simplexml_load_file( dirname( __FILE__ ) . '/lifestream.config.xml' );
		$this->theme= Themes::create();
	}
	
	public function act_display_lifestream() {
		$vars = Controller::get_handler_vars();
		
		$this->fetch_feeds();
				
		if(!isset($vars['type'])) {
			$vars['type'] = 'any';
		}
		
		if(!isset($vars['page'])) {
			$vars['page'] = 0;
		}
		
		$offset = $vars['page'] * Options::get('lifestream__perpage');
			
		$this->theme->assign( 'lifestream', LifeStream::get_entries($vars['type'], $offset, Options::get('lifestream__perpage')) );
		$this->theme->assign( 'title', 'Lifestream - ' . Options::get( 'title' ) );
		$this->theme->assign( 'streams', $this->config->stream );
		$this->theme->display( 'lifestream' );
		$this->$theme->fetch( 'lifestream_template' );
	}
	
	public function fetch_feeds() {
		foreach ( $this->config->stream as $stream ) {
			$feed = new SimplePie( (string) $stream['feedURL'], HABARI_PATH . '/' . (string) $this->config->cache['location'], (int) $this->config->cache['expire'] );
			$feed->handle_content_type();
			if( $feed->data ) {
				foreach( $feed->get_items() as $entry ) {
					$name= $stream['name'];
					$date = strtotime( substr( $entry->get_date(), 0, 25 ) );
					$data['name']= (string) $name;
					$data['content']= $entry->get_title();
					$data['link']= $entry->get_permalink();
					$data['date']= strtotime( substr( $entry->get_date(), 0, 25 ) );
					if ( $enclosure = $entry->get_enclosure( 0 ) ) {
						$data['data'] = $enclosure->get_link();
					}
					$this->stream_contents[$date]= $data;
				}
			}
		}
		LifeStream::insert( $this->stream_contents );
		return $this->stream_contents;
	}

}

?>