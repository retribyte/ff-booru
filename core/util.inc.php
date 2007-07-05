<?php

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Input / Output Sanitising                                                 *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function html_escape($input) {
	return htmlentities($input);
}

function int_escape($input) {
	return (int)$input;
}

function url_escape($input) {
	$input = rawurlencode($input);
	return $input;
}

function sql_escape($input) {
	global $database;
	return $database->db->Quote($input);
}

function parse_shorthand_int($limit) {
	if(is_numeric($limit)) {
		return (int)$limit;
	}

	if(preg_match('/^([\d\.]+)([gmk])?b?$/i', "$limit", $m)) {
		$value = $m[1];
		if (isset($m[2])) {
			switch(strtolower($m[2])) {
				case 'g': $value *= 1024;  # fallthrough
				case 'm': $value *= 1024;  # fallthrough
				case 'k': $value *= 1024; break;
				default: $value = -1;
			}
		}
		return (int)$value;
	} else {
		return -1;
	}
}

function to_shorthand_int($int) {
	if($int >= pow(1024, 3)) {
		return sprintf("%.1fGB", $int / pow(1024, 3));
	}
	else if($int >= pow(1024, 2)) {
		return sprintf("%.1fMB", $int / pow(1024, 2));
	}
	else if($int >= 1024) {
		return sprintf("%.1fKB", $int / 1024);
	}
	else {
		return "$int";
	}
}

function tag_explode($tags) {
	if(is_string($tags)) {
		$tags = explode(' ', $tags);
	}
	else if(is_array($tags)) {
		// do nothing
	}
	else {
		die("tag_explode only takes strings or arrays");
	}

	$tags = array_map("trim", $tags);

	$tag_array = array();
	foreach($tags as $tag) {
		if(is_string($tag) && strlen($tag) > 0) {
			$tag_array[] = $tag;
		}
	}

	if(count($tag_array) == 0) {
		$tag_array = array("tagme");
	}

	return $tag_array;
}


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* HTML Generation                                                           *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function make_link($page, $query=null) {
	global $config;
	$base = $config->get_string('base_href');

	if(is_null($query)) {
		return "$base/$page";
	}
	else {
		if(strpos($base, "?")) {
			return "$base/$page&$query";
		}
		else {
			return "$base/$page?$query";
		}
	}
}

function bbcode_to_html($text) {
	$text = trim($text);
	$text = html_escape($text);
	$text = preg_replace("/\[b\](.*?)\[\/b\]/s", "<b>\\1</b>", $text);
	$text = preg_replace("/\[i\](.*?)\[\/i\]/s", "<i>\\1</i>", $text);
	$text = preg_replace("/\[u\](.*?)\[\/u\]/s", "<u>\\1</u>", $text);
	$text = preg_replace("/\[code\](.*?)\[\/code\]/s", "<pre>\\1</pre>", $text);
	$text = preg_replace("/&gt;&gt;(\d+)/s",
		"<a href='".make_link("post/view/\\1")."'>&gt;&gt;\\1</a>", $text);
	$text = preg_replace("/\[url=((?:https?|ftp|irc):\/\/.*?)\](.*?)\[\/url\]/s", "<a href='\\1'>\\2</a>", $text);
	$text = preg_replace("/\[url\]((?:https?|ftp|irc):\/\/.*?)\[\/url\]/s", "<a href='\\1'>\\1</a>", $text);
	$text = preg_replace("/\[\[(.*?)\]\]/s", 
		"<a href='".make_link("wiki/\\1")."'>\\1</a>", $text);
	$text = str_replace("\n", "\n<br>", $text);
	return $text;
}

function bbcode_to_text($text) {
	$text = trim($text);
	$text = html_escape($text);
	$text = preg_replace("/\[b\](.*?)\[\/b\]/s", "\\1", $text);
	$text = preg_replace("/\[i\](.*?)\[\/i\]/s", "\\1", $text);
	$text = preg_replace("/\[u\](.*?)\[\/u\]/s", "\\1", $text);
	$text = preg_replace("/\[code\](.*?)\[\/code\]/s", "\\1", $text);
	$text = preg_replace("/\[url=(.*?)\](.*?)\[\/url\]/s", "\\2", $text);
	$text = preg_replace("/\[url\](.*?)\[\/url\]/s", "\\1", $text);
	$text = preg_replace("/\[\[(.*?)\]\]/s", "\\1", $text);
	return $text;
}

function build_thumb_html($image, $query=null) {
	global $config;
	$h_view_link = make_link("post/view/{$image->id}", $query);
	$h_tip = html_escape($image->get_tooltip());
	$h_thumb_link = $image->get_thumb_link();
	$tsize = get_thumbnail_size($image->width, $image->height);
	return "<a href='$h_view_link'><img title='$h_tip' alt='$h_tip'
			width='{$tsize[0]}' height='{$tsize[1]}' src='$h_thumb_link' /></a>\n";
}


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Input sanitising                                                          *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function get_memory_limit() {
	global $config;

	// thumbnail generation requires lots of memory
	$default_limit = 8*1024*1024;
	$shimmie_limit = parse_shorthand_int($config->get_int("thumb_mem_limit"));
	if($shimmie_limit < 3*1024*1024) {
		// we aren't going to fit, override
		$shimmie_limit = $default_limit;
	}
	
	ini_set("memory_limit", $shimmie_limit);
	$memory = parse_shorthand_int(ini_get("memory_limit"));

	// changing of memory limit is disabled / failed
	if($memory == -1) {
		$memory = $default_limit; 
	}

	assert($memory > 0);

	return $memory;
}


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Misc                                                                      *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function get_thumbnail_size($orig_width, $orig_height) {
	global $config;

	if($orig_width == 0) $orig_width = 192;
	if($orig_height == 0) $orig_height = 192;

	$max_width  = $config->get_int('thumb_width');
	$max_height = $config->get_int('thumb_height');

	$xscale = ($max_height / $orig_height);
	$yscale = ($max_width / $orig_width);
	$scale = ($xscale < $yscale) ? $xscale : $yscale;

	if($scale > 1 && $config->get_bool('thumb_upscale')) {
		return array($orig_width, $orig_height);
	}
	else {
		return array($orig_width*$scale, $orig_height*$scale);
	}
}

# $db is the connection object
function CountExecs($db, $sql, $inputarray) {
	global $_execs;
#	$fp = fopen("sql.log", "a");
#	fwrite($fp, preg_replace('/\s+/msi', ' ', $sql)."\n");
#	fclose($fp);
	if (!is_array($inputarray)) $_execs++;
	# handle 2-dimensional input arrays
	else if (is_array(reset($inputarray))) $_execs += sizeof($inputarray);
	else $_execs++;
	# in PHP4.4 and PHP5, we need to return a value by reference
	$null = null; return $null;
}

function get_theme_object($file, $class) {
	global $config;
	$theme = $config->get_string("theme", "default");
	if(file_exists("themes/$theme/$file.theme.php")) {
		require_once "themes/$theme/$file.theme.php";
		return new $class();
	}
	else {
		require_once "ext/$file/theme.php";
		return new $class();
	}
}

function get_debug_info() {
	global $config;
	
	if($config->get_bool('debug_enabled')) {
		if(function_exists('memory_get_usage')) {
			$i_mem = sprintf("%5.2f", ((memory_get_usage()+512)/1024)/1024);
		}
		else {
			$i_mem = "???";
		}
		if(function_exists('getrusage')) {
			$ru = getrusage();
			$i_utime = sprintf("%5.2f", ($ru["ru_utime.tv_sec"]*1e6+$ru["ru_utime.tv_usec"])/1000000);
			$i_stime = sprintf("%5.2f", ($ru["ru_stime.tv_sec"]*1e6+$ru["ru_stime.tv_usec"])/1000000);
		}
		else {
			$i_utime = "???";
			$i_stime = "???";
		}
		$i_files = count(get_included_files());
		global $_execs;
		$debug = "<br>Took $i_utime + $i_stime seconds and {$i_mem}MB of RAM";
		$debug .= "; Used $i_files files and $_execs queries";
	}
	else {
		$debug = "";
	}
	return $debug;
}

function blockcmp($a, $b) {
	if($a->position == $b->position) {
		return 0;
	}
	else {
		return ($a->position > $b->position);
	}
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Things which should be in the core API                                    *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function array_remove($array, $to_remove) {
	$array = array_unique($array);
	$a2 = array();
	foreach($array as $existing) {
		if($existing != $to_remove) {
			$a2[] = $existing;
		}
	}
	return $a2;
}


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Event API                                                                 *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

$_event_listeners = array();

function add_event_listener($block, $pos=50) {
	global $_event_listeners;
	while(isset($_event_listeners[$pos])) {
		$pos++;
	}
	$_event_listeners[$pos] = $block;
}

function send_event($event) {
	global $_event_listeners;
	ksort($_event_listeners);
	foreach($_event_listeners as $listener) {
		$listener->receive_event($event);
	}
}


/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *\
* Request initialisation stuff                                              *
\* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

function _get_query_parts() {
	if(isset($_GET["q"])) {
		$path = $_GET["q"];
	}
	else if(isset($_SERVER["PATH_INFO"])) {
		$path = $_SERVER["PATH_INFO"];
	}
	else {
		$path = "";
	}
	
	while(strlen($path) > 0 && $path[0] == '/') {
		$path = substr($path, 1);
	}

	return split('/', $path);
}

function get_page_request($page_object) {
	global $config;
	$args = _get_query_parts();

	if(count($args) == 0 || strlen($args[0]) == 0) {
		$page = $config->get_string('front_page', 'index');
		$args = array();
	}
	else if(count($args) == 1) {
		$page = $args[0];
		$args = array();
	}
	else {
		$page = $args[0];
		$args = array_slice($args, 1);
	}
	
	return new PageRequestEvent($page, $args, $page_object);
}

function get_user() {
	global $database;
	global $config;
	
	$user = null;
	if(isset($_COOKIE["shm_user"]) && isset($_COOKIE["shm_session"])) {
	    $tmp_user = $database->get_user_session($_COOKIE["shm_user"], $_COOKIE["shm_session"]);
		if(!is_null($tmp_user) && $tmp_user->is_enabled()) {
			$user = $tmp_user;
		}
		
	}
	if(is_null($user)) {
		$user = $database->get_user($config->get_int("anon_id", 0));
	}
	assert(!is_null($user));
	return $user;
}

?>
