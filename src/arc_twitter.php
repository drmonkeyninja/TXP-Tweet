<?php

$plugin['name'] = 'arc_twitter';
$plugin['version'] = '4.4.0';
$plugin['author'] = 'Andy Carter';
$plugin['author_uri'] = 'http://andy-carter.com/';
$plugin['description'] = '<a href="http://www.twitter.com">Twitter</a> for Textpattern';
$plugin['order'] = '5';
$plugin['type'] = '5';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '3';

@include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
global $prefs,$txpcfg,$arc_twitter_consumerKey,$arc_twitter_consumerSecret;

$arc_twitter = array();

// Register tags.
Txp::get('\Textpattern\Tag\Registry')
    ->register('arc_twitter')
    ->register('arc_twitter_search')
    ->register('arc_twitter_tweet_button')
    ->register('arc_twitter_follow_button')
    ->register('arc_twitter_tweet_url')
    ->register('arc_twitter_tinyurl')
    ->register('arc_twitter_widget_js');

$arc_twitter_consumerKey = 'nKcXslwzZhBd0kfKMetnPA';
$arc_twitter_consumerSecret = 'C6nSPCL3eeHGTBhKCgwd9oclcuD0srB8WVkfXQYC54';

add_privs('plugin_prefs.arc_twitter','1,2');
register_callback('arc_twitter_install','plugin_lifecycle.arc_twitter', 'installed');
register_callback('arc_twitter_uninstall','plugin_lifecycle.arc_twitter', 'deleted');
register_callback('arc_twitter_prefs','plugin_prefs.arc_twitter');

/*
 * Setup initial preferences if not in the txp_prefs table.
 */
if (!isset($prefs['arc_twitter_user'])) {
	set_pref('arc_twitter_user', '', 'arc_twitter', 1, 'text_input');
}
if (!isset($prefs['arc_twitter_prefix'])) {
	set_pref('arc_twitter_prefix','Just posted:', 'arc_twitter', 2, 'text_input');
}
if (!isset($prefs['arc_twitter_suffix'])) {
	set_pref('arc_twitter_suffix','', 'arc_twitter', 2, 'text_input');
}
if (!isset($prefs['arc_twitter_cache_dir'])) {
	set_pref('arc_twitter_cache_dir',$txpcfg['txpath'].$prefs['tempdir'], 'arc_twitter', 1, 'text_input');
}
if (!isset($prefs['arc_twitter_tweet_default'])) {
	set_pref('arc_twitter_tweet_default', 1, 'arc_twitter', 2, 'yesnoRadio');
}
if (!isset($prefs['arc_twitter_url_method'])) {
	set_pref('arc_twitter_url_method', 'full', 'arc_twitter', 2, 'arc_twitter_url_method_select');
}
if (!isset($prefs['arc_short_url'])) {
	set_pref('arc_short_url', 0, 'arc_twitter', 2, 'yesnoRadio');
}
if (!isset($prefs['arc_short_site_url'])) {
	set_pref('arc_short_site_url', $prefs['siteurl'], 'arc_twitter', 2, 'text_input');
}
// Make sure that the Twitter tab has been defined
if (!isset($prefs['arc_twitter_tab'])) {
  set_pref('arc_twitter_tab', 'extensions', 'arc_twitter', 2,
	'arc_twitter_tab_select');
	$prefs['arc_twitter_tab'] = 'extensions';
}
if (!isset($prefs['arc_short_utm'])) {
	set_pref('arc_short_utm', 0, 'arc_twitter', 2, 'yesnoRadio');
}

// Check if arc_short_url is enabled
if ((isset($prefs['arc_short_url'])&&$prefs['arc_short_url'])
|| (isset($prefs['arc_short_url_method'])&&$prefs['arc_twitter_url_method']=='arc_twitter')) {
  register_callback('arc_short_url_redirect', 'txp_die', 404);
}

if (@txpinterface == 'admin') {
	register_callback('_arc_twitter_auto_enable', 'plugin_lifecycle.arc_twitter', 'installed');
	if (!empty($prefs['arc_twitter_user'])
		&& !empty($prefs['arc_twitter_accessToken'])
		&& !empty($prefs['arc_twitter_accessTokenSecret']) ) {

		if ($prefs['arc_twitter_tab']) {
			add_privs('arc_admin_twitter', '1,2,3,4');
			register_tab($prefs['arc_twitter_tab'], 'arc_admin_twitter', 'Twitter');
			register_callback('arc_admin_twitter', 'arc_admin_twitter');
		}

		register_callback('arc_article_tweet', 'ping');
		register_callback('arc_article_tweet', 'article_saved');
		register_callback('arc_article_tweet', 'article_posted');
		register_callback('arc_append_twitter', 'article_ui', 'status');
	}
}

/*
	Public-side functions
	================================================================
*/

function arc_twitter($atts)
{
  global $prefs,$arc_twitter_consumerKey, $arc_twitter_consumerSecret;

  extract(lAtts(array(
	'user'      => $prefs['arc_twitter_user'],
	'password'  => '',
	'timeline'  => 'user',
	'limit'     => '10',
	'fetch'     => 0,
	'full_urls' => false,
	'retweets'  => false,
	'replies'   => true,
	'dateformat'=> $prefs['archive_dateformat'],
	'caching'   => '1',
	'cache_dir' => $prefs['arc_twitter_cache_dir'],
	'cache_time'=> '5',
	'label'     => '',
	'labeltag'  => '',
	'break'     => 'li',
	'wraptag'   => '',
	'class'     => __FUNCTION__,
	'class_posted' => __FUNCTION__.'-posted'
	),$atts));

  $twit = new arc_twitter($arc_twitter_consumerKey
			, $arc_twitter_consumerSecret, $prefs['arc_twitter_accessToken']
			, $prefs['arc_twitter_accessTokenSecret']);

  if ($caching) {  // turn on caching, recommended (default)
	$twit->setCaching(true);
	$twit->cacheDir($cache_dir);
	$twit->cacheTime($cache_time);
  } else {  // turn off caching, not recommended other than for testing
	$twit->setCaching(false);
  }

  switch ($timeline) {
	case 'home': case 'friends':
	  $timeline = 'home_timeline'; break;
	case 'mentions':
	  $timeline = 'statuses/mentions_timeline'; break;
	case 'user': default: $timeline = 'statuses/user_timeline';
  }

  // Check that the fetch (Twitter's count attribute) is set correctly
  $fetch = (!$fetch || $fetch<$limit) ? $limit : $fetch;

  $out = array();
  $tweets = $twit->get($timeline, array(
	  'screen_name'=>$user,
	  'count'=>$fetch,
	  'include_rts'=>$retweets,
	  'exclude_replies'=>!$replies
	));

  if ($tweets) {
	// Apply the display limit to the returned tweets
	$tweets = array_slice($tweets, 0, $limit);
	foreach ($tweets as $tweet) {
	  $time = strtotime(htmlentities($tweet->created_at));
	  $date = safe_strftime($dateformat,$time);
	  $links = false;
	  if (isset($tweet->entities->urls) || isset($tweet->entities->media)) {
		$links = array();
	  }
	  if (isset($tweet->entities->urls)) {
		foreach ($tweet->entities->urls as $url) {
			$links[$url->url] = array(
				'expanded_url' => $url->expanded_url,
				'display_url' => $url->display_url
			);
		}
	  }
	  if (isset($tweet->entities->media)) {
		foreach ($tweet->entities->media as $url) {
			$links[$url->url] = array(
				'expanded_url' => $url->expanded_url,
				'display_url' => $url->display_url
			);
		}
	  }
	  $out[] = arc_Twitter::makeLinks(htmlentities($tweet->text, ENT_QUOTES,'UTF-8'), $links, $full_urls)
		.' '.tag(htmlentities($date),'span',' class="'.$class_posted.'"');
	}
  }

	return doLabel($label, $labeltag).doWrap($out, $wraptag, $break, $class);

}

function arc_twitter_search($atts)
{
	global $prefs,$arc_twitter_consumerKey, $arc_twitter_consumerSecret;

	extract(lAtts(array(
		'user'      => $prefs['arc_twitter_user'],
		'search'    => '',
		'hashtags'  => '',
		'user'      => '',
		'reply'     => '',
		'mention'   => '',
		'limit'     => '10',
		'full_urls' => false,
		'lang'      => '',
		'dateformat'=> $prefs['archive_dateformat'],
		'caching'   => '1',
		'cache_dir' => $prefs['arc_twitter_cache_dir'],
		'cache_time'=> '5',
		'label'     => '',
		'labeltag'  => '',
		'break'     => 'li',
		'wraptag'   => '',
		'class'     => __FUNCTION__,
		'class_posted' => __FUNCTION__.'-posted',
		'class_user'   => __FUNCTION__.'-user'
	),$atts));

		$twit = new arc_twitter($arc_twitter_consumerKey
			, $arc_twitter_consumerSecret, $prefs['arc_twitter_accessToken']
			, $prefs['arc_twitter_accessTokenSecret']);

		if ($caching) {  // turn on caching, recommended (default)
			$twit->setCaching(true);
			$twit->cacheDir($cache_dir);
			$twit->cacheTime($cache_time);
		} else {  // turn off caching, not recommended other than for testing
			$twit->setCaching(false);
		}

		// construct search query
		if (!empty($search)) {
			$terms = explode(',',$search); $terms = array_map('trim',$terms);
			$search = implode(' ',$terms);
		}
		if ($hashtags) {
			$hashes = explode(',',$hashtags); $hashes = array_map('trim',$hashes);
			$search.= (($search)?' ':'').'#'.implode(' #',$hashes);
		}
		if ($reply) {
			$search.= (($search)?' ':'').'to:'.trim($reply);
		}
		if ($user) {
			$search.= (($search)?' ':'').'from:'.trim($user);
		}
		if ($mention) {
			$search.= (($search)?' ':'').'@'.trim($mention);
		}

		if (empty($search)) {
			return '';
		} else {
			$search = urlencode($search);
		}

		$out = array();
		$results = $twit->get('search/tweets'
			, array('q'=>$search,'count'=>$limit,'lang'=>$lang));

		$tweets = $results->statuses;
		if ($tweets) {

			foreach ($tweets as $tweet) {

				$uname = $tweet->user->screen_name;
				$name = $tweet->user->name;
				$time = strtotime(htmlentities($tweet->created_at));
				$date = safe_strftime($dateformat,$time);
				$text = $tweet->text;

				$links = false;
				if (isset($tweet->entities->urls)) {
					$links = array();
					foreach ($tweet->entities->urls as $url) {
						$links[$url->url] = array(
							'expanded_url' => $url->expanded_url,
							'display_url' => $url->display_url
						);
					}
				}

				$out[] = tag(href(htmlentities($uname),'http://twitter.com/' . $tweet->user->screen_name,
					' title="'.htmlentities($name).'"').': ','span'
						,' class="'.$class_user.'"')
					.arc_Twitter::makeLinks(htmlentities($text, ENT_QUOTES,'UTF-8'), $links, $full_urls)
					.' '.tag(htmlentities($date),'span'
						,' class="'.$class_posted.'"');

			}

			return doLabel($label, $labeltag).doWrap($out, $wraptag, $break, $class);
		}

}

function arc_twitter_tweet($atts) {
	global $thisarticle;

	extract(lAtts(array(
	  'id'        => $thisarticle['thisid'],
	  'include_url'   => true
	),$atts));

	if ($id) {
	  // Fetch arc_twitter stuff to build tweet from
	  $tweet = ($include_url) ? safe_row("tweet", 'arc_twitter', "article_id={$id}")
		: safe_row("REPLACE(tweet,CONCAT(' ',tinyurl),'') AS tweet"
		  , 'arc_twitter', "article_id={$id}");
	}

	if ($tweet['tweet']) {
	  return arc_Twitter::makeLinks(
		htmlentities($tweet['tweet'], ENT_QUOTES,'UTF-8'));
	}
}

function arc_twitter_tweet_url($atts, $thing=null) {
	global $thisarticle,$prefs;

	extract(lAtts(array(
	  'id'      => $thisarticle['thisid'],
	  'title'   => '',
	  'class'   => ''
	),$atts));

	if ($id) {
	  // Fetch arc_twitter stuff to build tweet from
	  $tweet = safe_row("tweet_id"
		, 'arc_twitter', "article_id={$id}");
	}

	if ($tweet['tweet_id']) {
	  $url = "http://twitter.com/".$prefs['arc_twitter_user']."/status/".$tweet['tweet_id'];
	  if ($thing===null) {
		return $url;
	  }
	  return href(parse($thing), $url,
		($title ? ' title="'.$title.'"' : '')
		.($class ? ' class="'.$class.'"' : ''));
	}
}

function arc_twitter_tinyurl($atts, $thing=null) {
	global $thisarticle;

	extract(lAtts(array(
	  'id'      => $thisarticle['thisid'],
	  'title'   => '',
	  'class'   => ''
	),$atts));

	if ($id) {
	  // Fetch arc_twitter stuff to build tweet from
	  $tweet = safe_row("tinyurl"
		, 'arc_twitter', "article_id={$id}");
	}

	if ($tweet['tinyurl']) {
	  if ($thing===null) {
		return $tweet['tinyurl'];
	  }

	  return href(parse($thing), $tweet['tinyurl'],
		($title ? ' title="'.$title.'"' : '')
		.($class ? ' class="'.$class.'"' : ''));
	}
}

/*
 * Public tag for outputting widget JS
 */
function arc_twitter_widget_js($atts)
{
  extract(lAtts(array(
		'optimise' => false
	),$atts));

  return _arc_twitter_widget_js($optimise);
}

function _arc_twitter_widget_js($optimise=true)
{
  global $arc_twitter;

  // Check if widget JS has already been output
  if ($arc_twitter['widget_js']) return;

  if ($optimise==false) {
	return '<script src="http://platform.twitter.com/widgets.js" type="text/javascript"></script>';
  }

  $js = <<<JS
<script type="text/javascript">
(function() {
  if (window.__twitterIntentHandler) return;
  var intentRegex = /twitter\.com(\:\d{2,4})?\/intent\/(\w+)/,
	  windowOptions = 'scrollbars=yes,resizable=yes,toolbar=no,location=yes',
	  width = 550,
	  height = 420,
	  winHeight = screen.height,
	  winWidth = screen.width;

  function handleIntent(e) {
	e = e || window.event;
	var target = e.target || e.srcElement,
		m, left, top;

	while (target && target.nodeName.toLowerCase() !== 'a') {
	  target = target.parentNode;
	}

	if (target && target.nodeName.toLowerCase() === 'a' && target.href) {
	  m = target.href.match(intentRegex);
	  if (m) {
		left = Math.round((winWidth / 2) - (width / 2));
		top = 0;

		if (winHeight > height) {
		  top = Math.round((winHeight / 2) - (height / 2));
		}

		window.open(target.href, 'intent', windowOptions + ',width=' + width +
										   ',height=' + height + ',left=' + left + ',top=' + top);
		e.returnValue = false;
		e.preventDefault && e.preventDefault();
	  }
	}
  }

  if (document.addEventListener) {
	document.addEventListener('click', handleIntent, false);
  } else if (document.attachEvent) {
	document.attachEvent('onclick', handleIntent);
  }
  window.__twitterIntentHandler = true;
}());
</script>
JS;
  $arc_twitter['widget_js'] = true;
  return $js;
}

// Deprecated arc_twitter_retweet tag, use arc_twitter_tweet_button instead
function arc_twitter_retweet($atts, $thing=null)
{
  return arc_twitter_tweet_button($atts, $thing=null);
}
function arc_twitter_tweet_button($atts, $thing=null)
{
	global $prefs,$arc_twitter_consumerKey, $arc_twitter_consumerSecret;
	global $thisarticle;

	extract(lAtts(array(
		'user'        => $prefs['arc_twitter_user'], // via user account
		'url'         => '',
		'text'        => '',
		'follow1'     => '',
		'follow2'     => '',
		'lang'        => '',
		'count'       => 'horizontal',
		'include_js'  => true,
		'optimise_js' => false,
		'wraptag'     => '',
		'class'       => 'twitter-share-button'
	),$atts));

	$q = ''; // query string

	if ($id=$thisarticle['thisid']) {
	  // Fetch arc_twitter stuff to build tweet from
	  $row = safe_row("REPLACE(tweet,CONCAT(' ',tinyurl),'') AS tweet,tinyurl"
		, 'arc_twitter', "article_id={$id}");

	  if ($url=='') {
		$url = ($url) ? $url : permlinkurl($thisarticle);
		$q = 'url='.urlencode($url);
	  }
	  if ($text=='') {
		$text = ($row['tweet']) ? $row['tweet'] : $thisarticle['title'];
	  }
	  $q .= ($q ? '&amp;' : '').'text='.urlencode($text);
	}
	if ($user) {
	  $q .= ($q ? '&amp;' : '').'via='.urlencode($user);
	}
	if ($follow1&&$follow2) {
	  $q .= ($q ? '&amp;' : '').'related='.urlencode($follow1.':'.$follow2);
	} elseif ($follow1||$follow2) {
	  $q .= ($q ? '&amp;' : '').'related='.urlencode($follow1.$follow2);
	}

	switch ($lang) {
	  case 'fr': break; case 'de': break; case 'es': break; case 'jp': break;
	  default:
		$lang = 'en';
	}
	$q .= ($q ? '&amp;' : '').'lang='.urlencode($lang);

	switch ($count) {
	  case 'none': break; case 'vertical': break;
	  default:
		$count = 'horizontal';
	}
	$q .= ($q ? '&amp;' : '').'count='.urlencode($count);

	$thing = ($thing===null) ? 'Tweet' : parse($thing);

	$html = href($thing,'http://twitter.com/share?'.$q
	  , ' class="'.$class.'"');

	$js = ($include_js) ? _arc_twitter_widget_js($optimise_js?true:false) : '';

	return $js.$html;
}

/*
 * Twitter Follow button
 */
function arc_twitter_follow_button($atts, $thing=null)
{
	global $prefs,$arc_twitter_consumerKey, $arc_twitter_consumerSecret;
	global $thisarticle;

	extract(lAtts(array(
		'user'        => $prefs['arc_twitter_user'], // via user account
		'lang'        => '',
		'count'       => true,
		'include_js'  => true,
		'optimise_js' => false,
		'class'       => 'twitter-follow-button'
	),$atts));

	$atts = ''; // data attributes

	switch ($lang) {
	  case 'fr': break; case 'de': break; case 'es': break; case 'jp': break;
	  default:
		$lang = 'en';
	}
	$atts .= ' data-lang="'.urlencode($lang).'"';

	$atts .= ' data-show-count="'.($count?'true':'false').'"';

	$thing = ($thing===null) ? 'Follow @'.$user : parse($thing);

	$html = href($thing,'http://twitter.com/'.urlencode($user)
	  , ' class="'.$class.'"'.$atts);

	$js = ($include_js) ? _arc_twitter_widget_js($optimise_js?true:false) : '';

	return $js.$html;
}

/*
	Admin-side functions
	================================================================
*/

// Installation function - builds MySQL table
function arc_twitter_install()
{
	global $DB;
	// For first install, create table for tweets
	$sql = "CREATE TABLE IF NOT EXISTS ".PFX."arc_twitter ";
	$sql.= "(arc_twitterid INTEGER AUTO_INCREMENT PRIMARY KEY,
		article_id INTEGER(11),
		tweet_id VARCHAR(100),
		tweet VARCHAR(280),
		tinyurl VARCHAR(30));";

	if (!safe_query($sql)) {
		return 'Error - unable to create arc_twitter table';
	}

	// Check if we need to update an existing table to use the larger string length.
	$sql = 'SELECT column_type FROM information_schema.columns WHERE column_name = \'tweet\' AND table_name = \'' . safe_pfx('arc_twitter') . '\'';
	$sql .= ' AND table_schema = \'' . $DB->db . '\'';
	$result = nextRow(safe_query($sql));
	if (! empty($result['column_type']) && strpos($result['column_type'], 'varchar(140)') !== false) {
		safe_alter('arc_twitter', 'CHANGE tweet tweet VARCHAR(280)');
	}

}

// Uninstall function - deletes MySQL table and related preferences
function arc_twitter_uninstall()
{

	$sql = "DROP TABLE IF EXISTS ".PFX."arc_twitter;";
	if (!safe_query($sql)) {
		return 'Error - unable to delete arc_twitter table';
	}

	$sql = "DELETE FROM  ".PFX."txp_prefs WHERE event='arc_twitter';";
	if (!safe_query($sql)) {
		return 'Error - unable to delete arc_twitter preferences';
	}

}
function arc_twitter_url_method_select($name, $val)
{
	$methods = array(
		'full' => 'Do not shorten',
		'tinyurl' => 'Tinyurl',
		'isgd' => 'Is.gd',
		'arc_twitter' => 'TXP Tweet',
		'smd' => 'smd_short_url'
	);
	return selectInput($name, $methods, $val, '', '', $name);
}
function arc_twitter_tab_select($name, $val)
{
	$tabs = array('content' => 'Content',
		'extensions' => 'Extensions',
		'' => 'Hidden');
	return selectInput($name, $tabs, $val, '', '', $name);
}
// Provide interface for setting preferences
function arc_twitter_prefs($event,$step)
{

	global $prefs, $arc_twitter_consumerKey, $arc_twitter_consumerSecret;

	$user = $prefs['arc_twitter_user'];
	$prefix = $prefs['arc_twitter_prefix'];
	$suffix = $prefs['arc_twitter_suffix'];
	$tweet_default = $prefs['arc_twitter_tweet_default'];
	$url_method = $prefs['arc_twitter_url_method'];
	$short_url = $prefs['arc_short_url'];
	$short_site_url = $prefs['arc_short_site_url'];
	$cache_dir = $prefs['arc_twitter_cache_dir'];
	$tab = $prefs['arc_twitter_tab'];
	$utm = !empty($prefs['arc_twitter_utm']) ? $prefs['arc_twitter_utm'] : 0;

	switch ($step) {
		case 'prefs_save': pagetop('TXP Tweet', 'Preferences saved'); break;
		case 'register': pagetop('TXP Tweet','Connect to Twitter'); break;
		case 'validate':
		default: pagetop('TXP Tweet');
	}

	$html = '';

	if ($step=='register') { // OAuth registration process

		$twit = new arc_twitter($arc_twitter_consumerKey, $arc_twitter_consumerSecret);

		// Build a callback URL for Twitter to return to the next stage
		$callbackURL = $twit->callbackURL($event,'validate');

		$request = $twit->getRequestToken($callbackURL);
		$request_token = $request["oauth_token"];
		$request_token_secret = $request["oauth_token_secret"];

		set_pref('arc_twitter_requestToken',$request_token, 'arc_twitter',2);
		set_pref('arc_twitter_requestTokenSecret',$request_token_secret, 'arc_twitter',2);

	$html = "<div class='text-column'>"
	  ."<p>".href('Sign-in to Twitter', $twit->getAuthorizeURL($request))." and follow the instructions to allow TXP Tweet to use your account. If you are already signed in to Twitter then that account will be associated with TXP Tweet so you may need to sign out first if you want to use a different account.</p>"
	  ."</div>";

	} elseif ($step=='validate') {
		$twit = new arc_twitter($arc_twitter_consumerKey
			, $arc_twitter_consumerSecret, $prefs['arc_twitter_requestToken']
			, $prefs['arc_twitter_requestTokenSecret']);
		// Ask Twitter for an access token (and an access token secret)
		$request = $twit->getAccessToken( gps('oauth_verifier') );
		$access_token = $request['oauth_token'];
		$access_token_secret = $request['oauth_token_secret'];
		$user = $request['screen_name'];
		// Store the access token and secret
		set_pref('arc_twitter_accessToken',$access_token, 'arc_twitter',2);
		set_pref('arc_twitter_accessTokenSecret',$access_token_secret
			, 'arc_twitter',2);
		set_pref('arc_twitter_user',$user);
		$prefs['arc_twitter_accessToken'] = $access_token;
		$prefs['arc_twitter_accessTokenSecret'] = $access_token_secret;
		unset($twit);
	}

	if ($step=="prefs_save") {

		$prefix = trim(gps('arc_twitter_prefix'));
		$suffix = trim(gps('arc_twitter_suffix'));
		$tweet_default = gps('arc_twitter_tweet_default');
		$url_method = gps('arc_twitter_url_method');
		$short_url = gps('arc_short_url');
		$short_site_url = gps('arc_short_site_url');
		$cache_dir = gps('arc_twitter_cache_dir');
		$tab = gps('arc_twitter_tab');
		$utm = gps('arc_twitter_utm');

		if (strlen($prefix)<=20) {
			set_pref('arc_twitter_prefix',$prefix);
		} else {
			$prefix = $prefs['arc_twitter_prefix'];
		}
		if (strlen($suffix)<=20) {
			set_pref('arc_twitter_suffix',$suffix);
		} else {
			$suffix = $prefs['arc_twitter_suffix'];
		}
		$tweet_default = ($tweet_default) ? 1 : 0;
		$short_url = ($short_url) ? 1 : 0;
		if (!$short_site_url) {
			$short_site_url = $prefs['siteurl'];
		}
		set_pref('arc_twitter_tweet_default', $tweet_default);
		set_pref('arc_short_url', $short_url);
		set_pref('arc_twitter_url_method', $url_method);
		set_pref('arc_short_site_url', $short_site_url);
		set_pref('arc_twitter_cache_dir', $cache_dir);
		set_pref('arc_twitter_tab', $tab);
		set_pref('arc_twitter_utm', $utm);

	}

	if ( $step!='register' ) {
		if ( isset($prefs['arc_twitter_accessToken'])
		&& isset($prefs['arc_twitter_accessTokenSecret']) ) {
			$twit = new arc_twitter($arc_twitter_consumerKey
				, $arc_twitter_consumerSecret, $prefs['arc_twitter_accessToken']
				, $prefs['arc_twitter_accessTokenSecret']);
			$registerURL = $twit->callbackURL($event,'register');

			// Define the fields ready to build the form
			$fields = array(
				'Tweet Settings' => array(
				  'arc_twitter_prefix' => array(
					'label' => 'Tweet prefix',
					'value' => $prefix
				  ),
				  'arc_twitter_suffix' => array(
					'label' => 'Tweet suffix',
					'value' => $suffix
				  ),
				  'arc_twitter_tweet_default' => array(
					'label' => 'Tweet articles by default',
					'type' => 'yesnoRadio',
					'value' => $tweet_default
				  ),
				  'arc_twitter_url_method' => array(
					'label' => 'URL shortner',
					'type' => 'arc_twitter_url_method_select',
					'value' => $url_method
				  )
				),
				'TXP Tweet short URL' => array(
				  'arc_short_url' => array(
					'label' => 'Enable TXP Tweet short URL redirect',
					'type' => 'yesnoRadio',
					'value' => $short_url
				  ),
				  'arc_short_site_url' => array(
					'label' => 'TXP Tweet short site URL',
					'value' => $short_site_url
				  )
				),
				'Twitter Tab' => array(
				  'arc_twitter_tab' => array(
					'label' => 'Location of tab',
					'type' => 'arc_twitter_tab_select',
					'value' => $tab
				  )
				),
				'Google Analytics' => array(
					'arc_twitter_utm' => array(
						'label' => 'Enable UTM',
						'type' => 'yesnoRadio',
						'value' => $utm
					)
				),
				'Cache' => array(
				  'arc_twitter_cache_dir' => array(
					'label' => 'Cache directory',
					'value' => $cache_dir
				  )
				)
			);

			$form = "<h2>Twitter account details</h2>"
		."<p><span class='edit-label'>Twitter username</span>"
		."<span class='edit-value'>"
		.($prefs['arc_twitter_user'] ? $user.' ('.href('Re-connect',$registerURL).')' : '<em>unknown</em>'.href('Connect to Twitter',$registerURL))
				."</span></p>";

			$form .= _arc_twitter_form_builder($fields);

			$form .= sInput('prefs_save').n.eInput('plugin_prefs.arc_twitter');

	  $form .= '<p>'.fInput('submit', 'Submit', gTxt('save_button'), 'publish').'</p>';

	  $html = "<h1 class='txp-heading'>TXP Tweet</h1>"
		."<p class='nav-tertiary'>"
		."<a href='./?event=arc_admin_twitter' class='navlink'>Twitter</a><a href='./?event=plugin_prefs.arc_twitter' class='navlink-active'>Options</a>"
		."</p>";

	  $html .= form("<div class='plugin-column'>".$form."</div>", " class='edit-form'");

		} elseif ( $step!='register' ) {

			$registerURL = arc_twitter::callbackURL($event,'register');

			$form = "<h2>Twitter account details</h2>"
		."<span class='edit-label'>Twitter username</span>"
		."<span class='edit-value'><em>unknown</em> &mdash; "
		.href('Connect to Twitter',$registerURL)
				."</span>";

	  $html = form("<div class='plugin-column'>".$form."</div>", " class='edit-form'");

		}
	}

	// Set jQuery for switching on/off relevant arc_short_url fields
	$js = <<<JS
<script language="javascript" type="text/javascript">
$(document).ready(function(){
  var onoff = $('.arc_short_url');
  var arc_short_url_off = $('#arc_short_url-arc_short_url-0');
  var url = $('.arc_short_site_url');
  var url_method = $('select[name="arc_twitter_url_method"]');

  if (arc_short_url_off.attr('checked')=='checked' && $('option:selected', url_method).val()!='arc_twitter') {
	url.hide();
  }
  $('input', onoff).change(function(){
	if ($('option:selected', url_method).val()!='arc_twitter') {
	  arc_short_url_off.attr('checked')=='checked' ? url.hide() : url.show();
	}
  });

  if ($('option:selected', url_method).val()=='arc_twitter') {
	onoff.hide(); url.show();
  }
  url_method.change(function(){
	if ($('option:selected', url_method).val()=='arc_twitter') {
	  onoff.toggle(); url.show();
	} else {
	  onoff.toggle();
	  arc_short_url_off.attr('checked')=='checked' ? url.hide() : url.show();
	}
  })
});
</script>
JS;

	echo $js.$html;
}

function _arc_twitter_form_builder($fields) {

  $form = '';

  foreach ($fields as $fk => $fv) {

	$form .= ($fk) ? "<h2>$fk</h2>" : '';

	foreach ($fv as $k => $v) {

	  $type = isset($v['type']) ? $v['type'] : 'text';

	  $form .= "<p class='$k'>"
		."<span class='edit-label'><label for='$k'>".$v['label']."</label></span>";

	  switch ($type)  {

		case 'textarea':

		  $form .= text_area($k, '50', '550', $v['value'], $k);
		  break;

		case 'yesnoRadio':

		  $form .= "<span class='edit-value'>".yesnoRadio($k, $v['value'], '', $k)."</span>";
		  break;

		case 'arc_twitter_tab_select':

		  $form .= "<span class='edit-value'>".arc_twitter_tab_select($k, $v['value'])."</span>";
		  break;

		case 'arc_twitter_url_method_select':

		  $form .= "<span class='edit-value'>".arc_twitter_url_method_select($k, $v['value'])."</span>";
		  break;

		default:

		  $form .= "<span class='edit-value'>".fInput('text',$k,$v['value'],'','','','','',$k)."</span>";
		  break;

	  }

	  $form .= "</p>";
	}

  }

  return $form;
}

// Add Twitter tab to Textpattern
function arc_admin_twitter($event,$step)
{
	global $prefs, $arc_twitter_consumerKey, $arc_twitter_consumerSecret;

	$twit = new arc_twitter($arc_twitter_consumerKey
			, $arc_twitter_consumerSecret, $prefs['arc_twitter_accessToken']
			, $prefs['arc_twitter_accessTokenSecret']);

	$twit->cacheDir($prefs['arc_twitter_cache_dir']);

	$data = $twit->get('users/show'
		, array('screen_name'=>$prefs['arc_twitter_user']));
	$twitterUser = $data;
	$twitterUserURL = 'http://www.twitter.com/'.$twitterUser->screen_name;

	if ($step=="tweet") { // post an update to Twitter

		// fetch and clean message
		$message = strip_tags(gps('message'));
		// We need to account for t.co URL shortening in our count (22 characters for a URL, 23 if https).
		$urlPattern = "/(http|https):\/\/[\w-]+(\.[\w-]+)+([\w.,@?^=%&amp;:\/~+#-]*[\w@?^=%&amp;\/~+#-])?/";
		$tmpMessage = preg_replace($urlPattern, "$1xxxxxxxxxxxxxxxxxx", $message);
		$count = strlen($tmpMessage);
		unset($tmpMessage);

		if ($count<=280 && $count>0) { // post update
			$result = $twit->post('statuses/update', array('status' => $message));
		} else { // message too long, JavaScript interface should prevent this
			$result = false;
		}

		pagetop('Twitter',
			(($result!=false)?'Twitter updated':'Error updating Twitter'));

	} elseif ($step=="delete") { // delete an update from Twitter

		$id = strip_tags(gps('id'));
		if ($id) {
			$twit->delete('statuses/destroy/'.$id);
			safe_delete('arc_twitter',"tweet_id = $id");
		}

		pagetop('Twitter','Twitter updated');

	} else {

		pagetop('Twitter');

	}

	// Prepare JavaScript to create Twitter update interface

	$js = '<script language="javascript" type="text/javascript">';
	$js.= <<<JS
$(document).ready(function(){
	var counter = $('<span>', {
		'text' : '280',
		'id' : 'tweetcount'
	});
	$('.message').append(counter);
	var counterStyle = 'font-weight:bold;padding-left:.5em;font-size:2em;line-height:1.2em;';
	$('#tweetcount').attr('style', counterStyle+'color:#ccc;');
	$('#message').keyup(function() {
		// We need to account for t.co URL shortening in our count (22 characters for a URL, 23 if https).
		var message = $('#message').val();
		var urlPattern = /(http|https):\/\/[\w-]+(\.[\w-]+)+([\w.,@?^=%&amp;:\/~+#-]*[\w@?^=%&amp;\/~+#-])?/;
		message = message.replace(urlPattern, '$1xxxxxxxxxxxxxxxxxx');
		var count = 280-message.length;
		$('#tweetcount').html(count+''); // hack to force output of 0
		if (count<0) {
			$('input.publish').prop('disabled', 'disabled');
		} else {
			$('input.publish').prop('disabled', '');
		}
		if (count<0) {
			$('#tweetcount').attr('style', counterStyle+'color:#f00;');
		} else if (count<10) {
			$('#tweetcount').attr('style', counterStyle+'color:#000;');
		} else {
			$('#tweetcount').attr('style', counterStyle+'color:#ccc;');
		}
	})
});
JS;
	$js.= "</script>";

	$out = '';
	$tweets = $twit->get('statuses/user_timeline'
		, array('screen_name'=>$prefs['arc_twitter_user'],'count'=>25));
	if ($tweets) foreach ($tweets as $tweet) {
		$time = strtotime(htmlentities($tweet->created_at));
		$date = safe_strftime($prefs['archive_dateformat'],$time);
		$links = false;
		if (isset($tweet->entities->urls)) {
			$links = array();
			foreach ($tweet->entities->urls as $url) {
				$links[$url->url] = array(
					'expanded_url' => $url->expanded_url,
					'display_url' => $url->display_url
				);
			}
		}
		$out.= tr(td($date,'span')
			.td(arc_Twitter::makeLinks(htmlentities($tweet->text
				, ENT_QUOTES,'UTF-8'), $links, true))
			.td(dLink('arc_admin_twitter','delete','id',$tweet->id_str,''))
		);
	}

	$fields = array(
	'' => array(
	  'message' => array(
		'label' => 'Update Twitter',
		'type' => 'textarea',
		'value' => ''
	  )
	)
	);

	$profile = '<img src="'.$twitterUser->profile_image_url.'" alt="Twitter avatar" style="float:left; margin-right: 1em" />'
	.graf(href($twitterUser->name,$twitterUserURL),' style="font-size:1.2em;font-weight:bold;"')
	.graf(href($twitterUser->friends_count.' following',$twitterUserURL.'/following')
	.', '.href($twitterUser->followers_count.' followers',$twitterUserURL.'/followers')
	.', '.href($twitterUser->statuses_count.' updates',$twitterUserURL));

	$form = _arc_twitter_form_builder($fields)
	.eInput('arc_admin_twitter')
	.sInput('tweet');
  $form .= '<p>'.fInput('submit', 'Submit', gTxt('Update'), 'publish').'</p>';

  $html = "<h1 class='txp-heading'>TXP Tweet</h1>"
	."<p class='nav-tertiary'>"
	."<a href='./?event=arc_admin_twitter' class='navlink-active'>Twitter</a><a href='./?event=plugin_prefs.arc_twitter' class='navlink'>Options</a>"
	."</p>";

	$html .= "<div class='text-column'>".$profile."</div>"
	."<br style='clear:both' />"
	.form("<div class='plugin-column'>".$form."</div>".br);

	// Attach recent Twitter updates

	$html.= "<div class='txp-listtables'>"
	.startTable('arc_twitter_timeline','','txp-list').$out.endTable()
	."</div>";

	// Output JavaScript and HTML

	echo $js.$html;
}

// Add Twitter options to article article screen
function arc_append_twitter($event, $step, $data, $rs1)
{
	global $prefs, $arc_twitter, $app_mode;

	$prefix = trim(gps('arc_twitter_prefix'));
	$prefix = ($prefix) ? $prefix : $prefs['arc_twitter_prefix'];
	$suffix = trim(gps('arc_twitter_suffix'));
	$suffix = ($suffix) ? $suffix : $prefs['arc_twitter_suffix'];

	if ($rs1['ID']) {
		$sql = "SELECT tweet_id,tweet FROM ".PFX."arc_twitter WHERE article_id=".$rs1['ID'].";";
		$rs2 = safe_query($sql); $rs2 = nextRow($rs2);
	} else { // new article
		$rs2 = '';
	}

	if ($app_mode == 'async')
	{
	 send_script_response('$("#arc_twitter").remove();');
	}

	if ($rs1['ID'] && $rs2['tweet_id'])
	{
		$content = arc_Twitter::makeLinks($rs2['tweet']);
		return $data.fieldset(tag($content, 'p'), 'Twitter update', 'arc_twitter');
	}
	else
	{
		$var = gps('arc_tweet_this');
		$var = ($rs1['ID']&&!$var) ? 0 : $prefs['arc_twitter_tweet_default'];
		$content  = tag(yesnoRadio('arc_tweet_this', $var, '', 'arc_tweet_this'),'p');
		$content .= tag(href('Options','#arc_twitter_options', ' onclick="$(\'#arc_twitter_options\').toggle(); return false;"'),'p',' style="margin-top:5px;"');
		$content .= tag(tag(tag('Tweet prefix','label', ' for="arc_twitter_prefix"') . '<br />'
			.fInput('text','arc_twitter_prefix',$prefix,'edit','','','22','','arc_twitter_prefix'),'p')
			.tag(tag('Tweet suffix (eg #hashtags)','label', ' for="arc_twitter_suffix"') . '<br />'
			.fInput('text','arc_twitter_suffix',$suffix,'edit','','','22','','arc_twitter_suffix'),'p')
			,'div',' id="arc_twitter_options" class="toggle" style="display:none"');
		if (isset($arc_twitter['error'])) {
			$content .= '<p>'.$arc_twitter['error'].'</p>';
		}
		return $data.fieldset($content, 'Update Twitter', 'arc_twitter');
	}

}

// Update Twitter with posted article
function arc_article_tweet($event,$step)
{
	global $prefs, $arc_twitter, $arc_twitter_consumerKey
		, $arc_twitter_consumerSecret;

	$article_id = empty($GLOBALS['ID']) ? gps('ID') : $GLOBALS['ID'];
	if (!empty($article_id)) {

		include_once txpath.'/publish/taghandlers.php';

		$article = safe_row("ID, Title, Section, Posted", 'textpattern',
			"ID={$article_id} AND Status=4 AND now()>=Posted");

		if ($article && gps('arc_tweet_this')) { // tweet article

			// Need to manually update the 'URL only title' before building the
			// URL
			$article['url_title'] = gps('url_title');

			// Make short URL
			$url = permlinkurl($article);
			$short_url = arc_shorten_url(
				$url,
				$prefs['arc_twitter_url_method'],
				array('id' => $article_id)
			);

			if (!$short_url) { // Failed to obtain a shortened URL, do not tweet!
				$arc_twitter['error'] = 'Unable to obtain a short URL for this article.';

				return false;
			}

			// Construct Twitter update
			$prefix  = trim(gps('arc_twitter_prefix'));
			$pre_len = strlen($prefix);
			$prefix  = ($prefix && $pre_len<=20) ? $prefix.' ' : '';
			$suffix  = trim(gps('arc_twitter_suffix'));
			$suf_len = strlen($suffix);
			$suffix  = ($suffix && $suf_len<=40) ? ' '.$suffix : '';
			$url_len = preg_match('|^https|i', $short_url) ? 23 : 22; // count URL length + 1 for prefixed space
			if ($prefix) {
				$pre_len += 1;
			}
			if ($suffix) {
				$suf_len += 1;
			}
			if ((strlen($article['Title'])+$url_len+$pre_len+$suf_len)>280) {
				$article['Title'] = substr($article['Title'],0,135-$url_len-$pre_len-$suf_len).'...';
			}
			$tweet = $prefix.$article['Title']." ".$short_url.$suffix;

			// Update Twitter
			$twit = new arc_twitter($arc_twitter_consumerKey
				, $arc_twitter_consumerSecret, $prefs['arc_twitter_accessToken']
				, $prefs['arc_twitter_accessTokenSecret']);
			$result = $twit->post('statuses/update', array('status' => $tweet));

			$tweet_id = (is_object($result)) ? $result->id_str : 0;

			if ($tweet_id) {

				$tweet = addslashes($tweet);

				// If we're not using a URL shortener don't store the short URL.
				if ($prefs['arc_twitter_url_method']=='full') {
					$short_url = null;
				}

				// update arc_twitter table with tweet
				$sql = "INSERT INTO ".PFX."arc_twitter (article_id,tweet_id,tweet,tinyurl) ";
				$sql.= "VALUES($article_id,$tweet_id,\"$tweet\",'$short_url');";
				safe_query($sql);

				return true;

			} else {

				$arc_twitter['error'] = 'Twitter response: '
					.$twit->http_code;
				return false;

			}

		}

	}

	return false;

}

/*
 * Shorten URLs using various methods
 */

function arc_shorten_url($url, $method='', $atts=array())
{
  global $prefs;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);

  $utm = null;

  if (!empty($prefs['arc_twitter_utm'])) {

	$utm = 'utm_source=twitter.com&utm_medium=social&utm_campaign=arc_twitter&utm_content=txp:' . $atts['id'];
	$separator = (parse_url($url, PHP_URL_QUERY) == NULL) ? '?' : '&';
	$url .= $separator . $utm;

  }

  switch ($method) {
	case 'smd': // create a shortened URL using SMD Short URL

		$url = !empty($atts['id']) ? hu . $atts['id'] : false;
		$url .= ($url!==false && !empty($utm)) ? '?' . $utm : false;
		return $url; break;

	case 'arc_twitter': // native URL shortening

		$url = !empty($atts['id']) ? PROTOCOL . $prefs['arc_short_site_url'] . '/' . $atts['id'] : false;
		$url .= ($url!==false && !empty($utm)) ? '?' . $utm : false;
		return $url;
		break;

	case 'isgd':

		$u = 'http://is.gd/api.php?longurl='.urlencode($url);
		curl_setopt($ch, CURLOPT_URL, $u);

		$tinyurl = curl_exec($ch);
		if ($tinyurl!='Error' && $tinyurl!='') {
			return $tinyurl;
		} else {
			trigger_error('arc_twitter failed to get a is.gd URL for '
				.$url,E_USER_WARNING);
		}
		break;

	case 'tinyurl': // create a shortened URL using TinyURL

		$u = 'http://tinyurl.com/api-create.php?url='.urlencode($url);
		curl_setopt($ch, CURLOPT_URL, $u);
		$tinyurl = curl_exec($ch);
		if ($tinyurl!='Error' && $tinyurl!='') {
			return $tinyurl;
		} else {
			trigger_error('arc_twitter failed to get a TinyURL for '.$url,E_USER_WARNING);
		}

	default:

		return $url;

  }

  return false; // fail

}

/*
 * Shortened URL redirect based on smd_short_url
 */
function arc_short_url_redirect($event, $step) {
  global $prefs;

  $have_id = 0;

  // Check if there is an available short site url and if it is being used in
  // this instance
  $short_site_url = $prefs['arc_short_site_url'];
  if ($short_site_url) {
	$short_site_url = PROTOCOL.$short_site_url.'/';
	$url_parts = parse_url($short_site_url);
	$re = '#^'.$url_parts['path'].'([0-9].*)#';
	$have_id = preg_match($re, $_SERVER['REQUEST_URI'], $m);
  }

  // Fall back to standard site url (smd_short_url behaviour)
  if ($have_id) {
	$url_parts = parse_url(hu);
	$re = '#^'.$url_parts['path'].'([0-9].*)#';
	$have_id = preg_match($re, $_SERVER['REQUEST_URI'], $m);
  }

  // Do the redirect if we've got an article id
  if ($have_id) {
	$id = $m[1];
	$permlink = permlinkurl_id($id);

	if ($permlink) {
	  ob_end_clean();

	  // Stupid, over the top header setting for IE
	  header("Status: 301");
	  header("HTTP/1.0 301 Moved Permanently");
	  header("Location: ".$permlink, TRUE, 301);

	  // In case the header() method fails, fall back on a classic redirect
	  echo '<html><head><META HTTP-EQUIV="Refresh" CONTENT="0;URL='
		.$permlink.'"></head><body></body></html>';
	  die();
	}
  }

}

// Auto enable plugin on install (original idea by Michael Manfre)
function _arc_twitter_auto_enable($event, $step, $prefix='arc_twitter')
{
  $plugin = substr($event, strlen('plugin_lifecycle.'));
  if (strncmp($plugin, $prefix, strlen($prefix)) == 0)
  {
	safe_update('txp_plugin', "status = 1", "name = '" . doSlash($plugin) . "'");
  }
}

/*
 *******************************************************************************
*/

class arc_twitter extends TwitterOAuth {
	// Caching variables
	private $_cache = true;
	private $_cache_dir = './tmp';
	private $_cache_time = 1800; // 30 minute cache

	function __construct($consumer_key, $consumer_secret, $oauth_token = NULL
		, $oauth_token_secret = NULL)
	{
		parent::__construct($consumer_key, $consumer_secret, $oauth_token
			, $oauth_token_secret);
		$this->format = 'json';
		$this->timeout = 15;
		$this->connecttimeout = 15;
	}

	public function callbackURL($event,$step)
	{
		return preg_replace('/\?.*/', '',PROTOCOL.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'])
		  .'?event='.$event.'&amp;step='.$step;
	}

	// create Twitter and external links in text
	public static function makeLinks($text, $urls = false, $expandedUrls = false)
	{
		if ($urls!==false) {

			foreach ($urls as $tcoUrl => $url)
			{
				if (!$expandedUrls)
				{
					$url['expanded_url'] = $tcoUrl;
					$url['display_url'] = preg_replace('|^https?:\/\/|i', '', $tcoUrl);
				}
				$text = str_replace(
					$tcoUrl,
					"<a href='{$url['expanded_url']}'>{$url['display_url']}</a>",
					$text
				);
			}

		}
		elseif ($urls!==false)
		{

		}
		else
		{
			$links = array(
				'/\b(http|https|ftp):\/\/([A-Z0-9][A-Z0-9_-]*(?:\.[A-Z0-9][A-Z0-9_-]*)+):?(\d+)?\/?([\/\w+-\.]+)(\?[\.a-z=&0-4:_-]+){0,1}\b/i' => "<a href='$0' rel='external'>$2$3$4</a>",
				'/\b(^|\s)www.([a-z_A-Z0-9]+)((\.[a-z]+)+)\b/i' => "<a href='http://www.$2$3' rel='external'>www.$2$3</a>"
			);
			$text = preg_replace(
				array_keys($links),
				array_values($links),
				$text
			);
		}

		$links = array(
			'/(^|\s).?@([a-z_A-Z0-9]+)/' => "$1<a href='http://twitter.com/$2' rel='external'>@$2</a>",
			'/(^|\s)(\#([a-z_A-Z0-9:_-]+))/' => "$1<a href='http://twitter.com/search?q=%23$3' rel='external'>$2</a>"
		);
		return preg_replace(
			array_keys($links),
			array_values($links),
			$text
		);
  }

	public function get($url, $params = array())
	{
		$api_url = md5($url.urlencode(serialize($params)));
		$data = '';

		if ($this->_cache) { // check for cached json

			$data = $this->_retrieveCache($api_url);

		}
		if (empty($data)) {
			$data = parent::get($url, $params); // data already json_decode'd
			if ($this->http_code===200 && $encoded_data=json_encode($data)) { // save cache
				$file = $this->_cache_dir.'/'.$api_url;
				file_put_contents($file,$encoded_data,LOCK_EX);
				return $data;
			} else { // failed to retrieve data from Twitter

				if ($this->_cache) { // attempt to force cached json return

					$data = $this->_retrieveCache($api_url,true);
					if ($data) return json_decode($data);

				}

				return false;

			}
		} else { // return cached json
			return json_decode($data);
		}
	} //end get()

	function post($url, $params = array())
	{
		$data = parent::post($url,$params);
		return $data;
	}

	function delete($url, $params = array())
	{
		$data = parent::delete($url,$params);
		return $data;
	}

	// Cache methods

	public function setCaching($cache=true)
	{
		$this->_cache = ($cache) ? true : false;
		return true;
	}

	public function cacheDir($loc)
	{
		$this->_cache_dir = $loc;
		return true;
	}

	public function cacheTime($mins)
	{
		$this->_cache_time = $mins*60; // convert minutes into seconds
		return true;
	}

	private function _retrieveCache($url,$overide_timeout=false)
	{
		$file = $this->_cache_dir.'/'.$url;
		if (file_exists($file)) {

			$diff = time() - filemtime($file);
			if ($overide_timeout || $diff < $this->_cache_time) {
				return file_get_contents($file);
			} else {
				return false;
			}

		} else {
			return false;
		}
	}
}

/*
 * Abraham Williams (abraham@abrah.am) http://abrah.am
 *
 * The first PHP Library to support OAuth for Twitter's REST API.
 */

/**
 * Twitter OAuth class
 */
class TwitterOAuth {
  /* Contains the last HTTP status code returned. */
  public $http_code;
  /* Contains the last API call. */
  public $url;
  /* Set up the API root URL. */
  public $host = "https://api.twitter.com/1.1/";
  /* Set timeout default. */
  public $timeout = 30;
  /* Set connect timeout. */
  public $connecttimeout = 30;
  /* Verify SSL Cert. */
  public $ssl_verifypeer = FALSE;
  /* Respons format. */
  public $format = 'json';
  /* Decode returned json data. */
  public $decode_json = TRUE;
  /* Contains the last HTTP headers returned. */
  public $http_info;
  /* Set the useragnet. */
  public $useragent = 'TwitterOAuth v0.2.0-beta2';
  /* Immediately retry the API call if the response was not successful. */
  //public $retry = TRUE;

  /**
   * Set API URLS
   */
  function accessTokenURL()  { return 'https://api.twitter.com/oauth/access_token'; }
  function authenticateURL() { return 'https://api.twitter.com/oauth/authenticate'; }
  function authorizeURL()    { return 'https://api.twitter.com/oauth/authorize'; }
  function requestTokenURL() { return 'https://api.twitter.com/oauth/request_token'; }

  /**
   * Debug helpers
   */
  function lastStatusCode() { return $this->http_status; }
  function lastAPICall() { return $this->last_api_call; }

  /**
   * construct TwitterOAuth object
   */
  function __construct($consumer_key, $consumer_secret, $oauth_token = NULL, $oauth_token_secret = NULL) {
	$this->sha1_method = new OAuthSignatureMethod_HMAC_SHA1();
	$this->consumer = new OAuthConsumer($consumer_key, $consumer_secret);
	if (!empty($oauth_token) && !empty($oauth_token_secret)) {
	  $this->token = new OAuthConsumer($oauth_token, $oauth_token_secret);
	} else {
	  $this->token = NULL;
	}
  }


  /**
   * Get a request_token from Twitter
   *
   * @returns a key/value array containing oauth_token and oauth_token_secret
   */
  function getRequestToken($oauth_callback) {
	$parameters = array();
	$parameters['oauth_callback'] = $oauth_callback;
	$request = $this->oAuthRequest($this->requestTokenURL(), 'GET', $parameters);
	$token = OAuthUtil::parse_parameters($request);
	$this->token = new OAuthConsumer($token['oauth_token'], $token['oauth_token_secret']);
	return $token;
  }

  /**
   * Get the authorize URL
   *
   * @returns a string
   */
  function getAuthorizeURL($token, $sign_in_with_twitter = TRUE) {
	if (is_array($token)) {
	  $token = $token['oauth_token'];
	}
	if (empty($sign_in_with_twitter)) {
	  return $this->authorizeURL() . "?oauth_token={$token}";
	} else {
	   return $this->authenticateURL() . "?oauth_token={$token}";
	}
  }

  /**
   * Exchange request token and secret for an access token and
   * secret, to sign API calls.
   *
   * @returns array("oauth_token" => "the-access-token",
   *                "oauth_token_secret" => "the-access-secret",
   *                "user_id" => "9436992",
   *                "screen_name" => "abraham")
   */
  function getAccessToken($oauth_verifier) {
	$parameters = array();
	$parameters['oauth_verifier'] = $oauth_verifier;
	$request = $this->oAuthRequest($this->accessTokenURL(), 'GET', $parameters);
	$token = OAuthUtil::parse_parameters($request);
	$this->token = new OAuthConsumer($token['oauth_token'], $token['oauth_token_secret']);
	return $token;
  }

  /**
   * One time exchange of username and password for access token and secret.
   *
   * @returns array("oauth_token" => "the-access-token",
   *                "oauth_token_secret" => "the-access-secret",
   *                "user_id" => "9436992",
   *                "screen_name" => "abraham",
   *                "x_auth_expires" => "0")
   */
  function getXAuthToken($username, $password) {
	$parameters = array();
	$parameters['x_auth_username'] = $username;
	$parameters['x_auth_password'] = $password;
	$parameters['x_auth_mode'] = 'client_auth';
	$request = $this->oAuthRequest($this->accessTokenURL(), 'POST', $parameters);
	$token = OAuthUtil::parse_parameters($request);
	$this->token = new OAuthConsumer($token['oauth_token'], $token['oauth_token_secret']);
	return $token;
  }

  /**
   * GET wrapper for oAuthRequest.
   */
  function get($url, $parameters = array()) {
	$response = $this->oAuthRequest($url, 'GET', $parameters);
	if ($this->format === 'json' && $this->decode_json) {
	  return json_decode($response);
	}
	return $response;
  }

  /**
   * POST wrapper for oAuthRequest.
   */
  function post($url, $parameters = array()) {
	$response = $this->oAuthRequest($url, 'POST', $parameters);
	if ($this->format === 'json' && $this->decode_json) {
	  return json_decode($response);
	}
	return $response;
  }

  /**
   * DELETE wrapper for oAuthReqeust.
   */
  function delete($url, $parameters = array()) {
	$response = $this->oAuthRequest($url, 'DELETE', $parameters);
	if ($this->format === 'json' && $this->decode_json) {
	  return json_decode($response);
	}
	return $response;
  }

  /**
   * Format and sign an OAuth / API request
   */
  function oAuthRequest($url, $method, $parameters) {
	if (strrpos($url, 'https://') !== 0 && strrpos($url, 'http://') !== 0) {
	  $url = "{$this->host}{$url}.{$this->format}";
	}
	$request = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, $method, $url, $parameters);
	$request->sign_request($this->sha1_method, $this->consumer, $this->token);
	switch ($method) {
	case 'GET':
	  return $this->http($request->to_url(), 'GET');
	default:
	  return $this->http($request->get_normalized_http_url(), $method, $request->to_postdata());
	}
  }

  /**
   * Make an HTTP request
   *
   * @return API results
   */
  function http($url, $method, $postfields = NULL) {
	$this->http_info = array();
	$ci = curl_init();
	/* Curl settings */
	curl_setopt($ci, CURLOPT_USERAGENT, $this->useragent);
	curl_setopt($ci, CURLOPT_CONNECTTIMEOUT, $this->connecttimeout);
	curl_setopt($ci, CURLOPT_TIMEOUT, $this->timeout);
	curl_setopt($ci, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ci, CURLOPT_HTTPHEADER, array('Expect:'));
	curl_setopt($ci, CURLOPT_SSL_VERIFYPEER, $this->ssl_verifypeer);
	curl_setopt($ci, CURLOPT_HEADERFUNCTION, array($this, 'getHeader'));
	curl_setopt($ci, CURLOPT_HEADER, FALSE);

	switch ($method) {
	  case 'POST':
		curl_setopt($ci, CURLOPT_POST, TRUE);
		if (!empty($postfields)) {
		  curl_setopt($ci, CURLOPT_POSTFIELDS, $postfields);
		}
		break;
	  case 'DELETE':
		curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'DELETE');
		if (!empty($postfields)) {
		  $url = "{$url}?{$postfields}";
		}
	}

	curl_setopt($ci, CURLOPT_URL, $url);
	$response = curl_exec($ci);
	$this->http_code = curl_getinfo($ci, CURLINFO_HTTP_CODE);
	$this->http_info = array_merge($this->http_info, curl_getinfo($ci));
	$this->url = $url;
	curl_close ($ci);
	return $response;
  }

  /**
   * Get the header info to store.
   */
  function getHeader($ch, $header) {
	$i = strpos($header, ':');
	if (!empty($i)) {
	  $key = str_replace('-', '_', strtolower(substr($header, 0, $i)));
	  $value = trim(substr($header, $i + 2));
	  $this->http_header[$key] = $value;
	}
	return strlen($header);
  }
}
/* Generic exception class
 */
if (!class_exists('OAuthException')) {
  class OAuthException extends Exception {
	// pass
  }
}

class OAuthConsumer {
  public $key;
  public $secret;

  function __construct($key, $secret, $callback_url=NULL) {
	$this->key = $key;
	$this->secret = $secret;
	$this->callback_url = $callback_url;
  }

  function __toString() {
	return "OAuthConsumer[key=$this->key,secret=$this->secret]";
  }
}

class OAuthToken {
  // access tokens and request tokens
  public $key;
  public $secret;

  /**
   * key = the token
   * secret = the token secret
   */
  function __construct($key, $secret) {
	$this->key = $key;
	$this->secret = $secret;
  }

  /**
   * generates the basic string serialization of a token that a server
   * would respond to request_token and access_token calls with
   */
  function to_string() {
	return "oauth_token=" .
		   OAuthUtil::urlencode_rfc3986($this->key) .
		   "&oauth_token_secret=" .
		   OAuthUtil::urlencode_rfc3986($this->secret);
  }

  function __toString() {
	return $this->to_string();
  }
}

/**
 * A class for implementing a Signature Method
 * See section 9 ("Signing Requests") in the spec
 */
abstract class OAuthSignatureMethod {
  /**
   * Needs to return the name of the Signature Method (ie HMAC-SHA1)
   * @return string
   */
  abstract public function get_name();

  /**
   * Build up the signature
   * NOTE: The output of this function MUST NOT be urlencoded.
   * the encoding is handled in OAuthRequest when the final
   * request is serialized
   * @param OAuthRequest $request
   * @param OAuthConsumer $consumer
   * @param OAuthToken $token
   * @return string
   */
  abstract public function build_signature($request, $consumer, $token);

  /**
   * Verifies that a given signature is correct
   * @param OAuthRequest $request
   * @param OAuthConsumer $consumer
   * @param OAuthToken $token
   * @param string $signature
   * @return bool
   */
  public function check_signature($request, $consumer, $token, $signature) {
	$built = $this->build_signature($request, $consumer, $token);
	return $built == $signature;
  }
}

/**
 * The HMAC-SHA1 signature method uses the HMAC-SHA1 signature algorithm as defined in [RFC2104]
 * where the Signature Base String is the text and the key is the concatenated values (each first
 * encoded per Parameter Encoding) of the Consumer Secret and Token Secret, separated by an '&'
 * character (ASCII code 38) even if empty.
 *   - Chapter 9.2 ("HMAC-SHA1")
 */
class OAuthSignatureMethod_HMAC_SHA1 extends OAuthSignatureMethod {
  function get_name() {
	return "HMAC-SHA1";
  }

  public function build_signature($request, $consumer, $token) {
	$base_string = $request->get_signature_base_string();
	$request->base_string = $base_string;

	$key_parts = array(
	  $consumer->secret,
	  ($token) ? $token->secret : ""
	);

	$key_parts = OAuthUtil::urlencode_rfc3986($key_parts);
	$key = implode('&', $key_parts);

	return base64_encode(hash_hmac('sha1', $base_string, $key, true));
  }
}

/**
 * The PLAINTEXT method does not provide any security protection and SHOULD only be used
 * over a secure channel such as HTTPS. It does not use the Signature Base String.
 *   - Chapter 9.4 ("PLAINTEXT")
 */
class OAuthSignatureMethod_PLAINTEXT extends OAuthSignatureMethod {
  public function get_name() {
	return "PLAINTEXT";
  }

  /**
   * oauth_signature is set to the concatenated encoded values of the Consumer Secret and
   * Token Secret, separated by a '&' character (ASCII code 38), even if either secret is
   * empty. The result MUST be encoded again.
   *   - Chapter 9.4.1 ("Generating Signatures")
   *
   * Please note that the second encoding MUST NOT happen in the SignatureMethod, as
   * OAuthRequest handles this!
   */
  public function build_signature($request, $consumer, $token) {
	$key_parts = array(
	  $consumer->secret,
	  ($token) ? $token->secret : ""
	);

	$key_parts = OAuthUtil::urlencode_rfc3986($key_parts);
	$key = implode('&', $key_parts);
	$request->base_string = $key;

	return $key;
  }
}

/**
 * The RSA-SHA1 signature method uses the RSASSA-PKCS1-v1_5 signature algorithm as defined in
 * [RFC3447] section 8.2 (more simply known as PKCS#1), using SHA-1 as the hash function for
 * EMSA-PKCS1-v1_5. It is assumed that the Consumer has provided its RSA public key in a
 * verified way to the Service Provider, in a manner which is beyond the scope of this
 * specification.
 *   - Chapter 9.3 ("RSA-SHA1")
 */
abstract class OAuthSignatureMethod_RSA_SHA1 extends OAuthSignatureMethod {
  public function get_name() {
	return "RSA-SHA1";
  }

  // Up to the SP to implement this lookup of keys. Possible ideas are:
  // (1) do a lookup in a table of trusted certs keyed off of consumer
  // (2) fetch via http using a url provided by the requester
  // (3) some sort of specific discovery code based on request
  //
  // Either way should return a string representation of the certificate
  protected abstract function fetch_public_cert(&$request);

  // Up to the SP to implement this lookup of keys. Possible ideas are:
  // (1) do a lookup in a table of trusted certs keyed off of consumer
  //
  // Either way should return a string representation of the certificate
  protected abstract function fetch_private_cert(&$request);

  public function build_signature($request, $consumer, $token) {
	$base_string = $request->get_signature_base_string();
	$request->base_string = $base_string;

	// Fetch the private key cert based on the request
	$cert = $this->fetch_private_cert($request);

	// Pull the private key ID from the certificate
	$privatekeyid = openssl_get_privatekey($cert);

	// Sign using the key
	$ok = openssl_sign($base_string, $signature, $privatekeyid);

	// Release the key resource
	openssl_free_key($privatekeyid);

	return base64_encode($signature);
  }

  public function check_signature($request, $consumer, $token, $signature) {
	$decoded_sig = base64_decode($signature);

	$base_string = $request->get_signature_base_string();

	// Fetch the public key cert based on the request
	$cert = $this->fetch_public_cert($request);

	// Pull the public key ID from the certificate
	$publickeyid = openssl_get_publickey($cert);

	// Check the computed signature against the one passed in the query
	$ok = openssl_verify($base_string, $decoded_sig, $publickeyid);

	// Release the key resource
	openssl_free_key($publickeyid);

	return $ok == 1;
  }
}

class OAuthRequest {
  private $parameters;
  private $http_method;
  private $http_url;
  // for debug purposes
  public $base_string;
  public static $version = '1.0';
  public static $POST_INPUT = 'php://input';

  function __construct($http_method, $http_url, $parameters=NULL) {
	@$parameters or $parameters = array();
	$parameters = array_merge( OAuthUtil::parse_parameters(parse_url($http_url, PHP_URL_QUERY)), $parameters);
	$this->parameters = $parameters;
	$this->http_method = $http_method;
	$this->http_url = $http_url;
  }


  /**
   * attempt to build up a request from what was passed to the server
   */
  public static function from_request($http_method=NULL, $http_url=NULL, $parameters=NULL) {
	$scheme = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on")
			  ? 'http'
			  : 'https';
	@$http_url or $http_url = $scheme .
							  '://' . $_SERVER['HTTP_HOST'] .
							  ':' .
							  $_SERVER['SERVER_PORT'] .
							  $_SERVER['REQUEST_URI'];
	@$http_method or $http_method = $_SERVER['REQUEST_METHOD'];

	// We weren't handed any parameters, so let's find the ones relevant to
	// this request.
	// If you run XML-RPC or similar you should use this to provide your own
	// parsed parameter-list
	if (!$parameters) {
	  // Find request headers
	  $request_headers = OAuthUtil::get_headers();

	  // Parse the query-string to find GET parameters
	  $parameters = OAuthUtil::parse_parameters($_SERVER['QUERY_STRING']);

	  // It's a POST request of the proper content-type, so parse POST
	  // parameters and add those overriding any duplicates from GET
	  if ($http_method == "POST"
		  && @strstr($request_headers["Content-Type"],
					 "application/x-www-form-urlencoded")
		  ) {
		$post_data = OAuthUtil::parse_parameters(
		  file_get_contents(self::$POST_INPUT)
		);
		$parameters = array_merge($parameters, $post_data);
	  }

	  // We have a Authorization-header with OAuth data. Parse the header
	  // and add those overriding any duplicates from GET or POST
	  if (@substr($request_headers['Authorization'], 0, 6) == "OAuth ") {
		$header_parameters = OAuthUtil::split_header(
		  $request_headers['Authorization']
		);
		$parameters = array_merge($parameters, $header_parameters);
	  }

	}

	return new OAuthRequest($http_method, $http_url, $parameters);
  }

  /**
   * pretty much a helper function to set up the request
   */
  public static function from_consumer_and_token($consumer, $token, $http_method, $http_url, $parameters=NULL) {
	@$parameters or $parameters = array();
	$defaults = array("oauth_version" => OAuthRequest::$version,
					  "oauth_nonce" => OAuthRequest::generate_nonce(),
					  "oauth_timestamp" => OAuthRequest::generate_timestamp(),
					  "oauth_consumer_key" => $consumer->key);
	if ($token)
	  $defaults['oauth_token'] = $token->key;

	$parameters = array_merge($defaults, $parameters);

	return new OAuthRequest($http_method, $http_url, $parameters);
  }

  public function set_parameter($name, $value, $allow_duplicates = true) {
	if ($allow_duplicates && isset($this->parameters[$name])) {
	  // We have already added parameter(s) with this name, so add to the list
	  if (is_scalar($this->parameters[$name])) {
		// This is the first duplicate, so transform scalar (string)
		// into an array so we can add the duplicates
		$this->parameters[$name] = array($this->parameters[$name]);
	  }

	  $this->parameters[$name][] = $value;
	} else {
	  $this->parameters[$name] = $value;
	}
  }

  public function get_parameter($name) {
	return isset($this->parameters[$name]) ? $this->parameters[$name] : null;
  }

  public function get_parameters() {
	return $this->parameters;
  }

  public function unset_parameter($name) {
	unset($this->parameters[$name]);
  }

  /**
   * The request parameters, sorted and concatenated into a normalized string.
   * @return string
   */
  public function get_signable_parameters() {
	// Grab all parameters
	$params = $this->parameters;

	// Remove oauth_signature if present
	// Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
	if (isset($params['oauth_signature'])) {
	  unset($params['oauth_signature']);
	}

	return OAuthUtil::build_http_query($params);
  }

  /**
   * Returns the base string of this request
   *
   * The base string defined as the method, the url
   * and the parameters (normalized), each urlencoded
   * and the concated with &.
   */
  public function get_signature_base_string() {
	$parts = array(
	  $this->get_normalized_http_method(),
	  $this->get_normalized_http_url(),
	  $this->get_signable_parameters()
	);

	$parts = OAuthUtil::urlencode_rfc3986($parts);

	return implode('&', $parts);
  }

  /**
   * just uppercases the http method
   */
  public function get_normalized_http_method() {
	return strtoupper($this->http_method);
  }

  /**
   * parses the url and rebuilds it to be
   * scheme://host/path
   */
  public function get_normalized_http_url() {
	$parts = parse_url($this->http_url);

	$port = @$parts['port'];
	$scheme = $parts['scheme'];
	$host = $parts['host'];
	$path = @$parts['path'];

	$port or $port = ($scheme == 'https') ? '443' : '80';

	if (($scheme == 'https' && $port != '443')
		|| ($scheme == 'http' && $port != '80')) {
	  $host = "$host:$port";
	}
	return "$scheme://$host$path";
  }

  /**
   * builds a url usable for a GET request
   */
  public function to_url() {
	$post_data = $this->to_postdata();
	$out = $this->get_normalized_http_url();
	if ($post_data) {
	  $out .= '?'.$post_data;
	}
	return $out;
  }

  /**
   * builds the data one would send in a POST request
   */
  public function to_postdata() {
	return OAuthUtil::build_http_query($this->parameters);
  }

  /**
   * builds the Authorization: header
   */
  public function to_header($realm=null) {
	$first = true;
  if($realm) {
	  $out = 'Authorization: OAuth realm="' . OAuthUtil::urlencode_rfc3986($realm) . '"';
	  $first = false;
	} else
	  $out = 'Authorization: OAuth';

	$total = array();
	foreach ($this->parameters as $k => $v) {
	  if (substr($k, 0, 5) != "oauth") continue;
	  if (is_array($v)) {
		throw new OAuthException('Arrays not supported in headers');
	  }
	  $out .= ($first) ? ' ' : ',';
	  $out .= OAuthUtil::urlencode_rfc3986($k) .
			  '="' .
			  OAuthUtil::urlencode_rfc3986($v) .
			  '"';
	  $first = false;
	}
	return $out;
  }

  public function __toString() {
	return $this->to_url();
  }


  public function sign_request($signature_method, $consumer, $token) {
	$this->set_parameter(
	  "oauth_signature_method",
	  $signature_method->get_name(),
	  false
	);
	$signature = $this->build_signature($signature_method, $consumer, $token);
	$this->set_parameter("oauth_signature", $signature, false);
  }

  public function build_signature($signature_method, $consumer, $token) {
	$signature = $signature_method->build_signature($this, $consumer, $token);
	return $signature;
  }

  /**
   * util function: current timestamp
   */
  private static function generate_timestamp() {
	return time();
  }

  /**
   * util function: current nonce
   */
  private static function generate_nonce() {
	$mt = microtime();
	$rand = mt_rand();

	return md5($mt . $rand); // md5s look nicer than numbers
  }
}

class OAuthServer {
  protected $timestamp_threshold = 300; // in seconds, five minutes
  protected $version = '1.0';             // hi blaine
  protected $signature_methods = array();

  protected $data_store;

  function __construct($data_store) {
	$this->data_store = $data_store;
  }

  public function add_signature_method($signature_method) {
	$this->signature_methods[$signature_method->get_name()] =
	  $signature_method;
  }

  // high level functions

  /**
   * process a request_token request
   * returns the request token on success
   */
  public function fetch_request_token(&$request) {
	$this->get_version($request);

	$consumer = $this->get_consumer($request);

	// no token required for the initial token request
	$token = NULL;

	$this->check_signature($request, $consumer, $token);

	// Rev A change
	$callback = $request->get_parameter('oauth_callback');
	$new_token = $this->data_store->new_request_token($consumer, $callback);

	return $new_token;
  }

  /**
   * process an access_token request
   * returns the access token on success
   */
  public function fetch_access_token(&$request) {
	$this->get_version($request);

	$consumer = $this->get_consumer($request);

	// requires authorized request token
	$token = $this->get_token($request, $consumer, "request");

	$this->check_signature($request, $consumer, $token);

	// Rev A change
	$verifier = $request->get_parameter('oauth_verifier');
	$new_token = $this->data_store->new_access_token($token, $consumer, $verifier);

	return $new_token;
  }

  /**
   * verify an api call, checks all the parameters
   */
  public function verify_request(&$request) {
	$this->get_version($request);
	$consumer = $this->get_consumer($request);
	$token = $this->get_token($request, $consumer, "access");
	$this->check_signature($request, $consumer, $token);
	return array($consumer, $token);
  }

  // Internals from here
  /**
   * version 1
   */
  private function get_version(&$request) {
	$version = $request->get_parameter("oauth_version");
	if (!$version) {
	  // Service Providers MUST assume the protocol version to be 1.0 if this parameter is not present.
	  // Chapter 7.0 ("Accessing Protected Ressources")
	  $version = '1.0';
	}
	if ($version !== $this->version) {
	  throw new OAuthException("OAuth version '$version' not supported");
	}
	return $version;
  }

  /**
   * figure out the signature with some defaults
   */
  private function get_signature_method(&$request) {
	$signature_method =
		@$request->get_parameter("oauth_signature_method");

	if (!$signature_method) {
	  // According to chapter 7 ("Accessing Protected Ressources") the signature-method
	  // parameter is required, and we can't just fallback to PLAINTEXT
	  throw new OAuthException('No signature method parameter. This parameter is required');
	}

	if (!in_array($signature_method,
				  array_keys($this->signature_methods))) {
	  throw new OAuthException(
		"Signature method '$signature_method' not supported " .
		"try one of the following: " .
		implode(", ", array_keys($this->signature_methods))
	  );
	}
	return $this->signature_methods[$signature_method];
  }

  /**
   * try to find the consumer for the provided request's consumer key
   */
  private function get_consumer(&$request) {
	$consumer_key = @$request->get_parameter("oauth_consumer_key");
	if (!$consumer_key) {
	  throw new OAuthException("Invalid consumer key");
	}

	$consumer = $this->data_store->lookup_consumer($consumer_key);
	if (!$consumer) {
	  throw new OAuthException("Invalid consumer");
	}

	return $consumer;
  }

  /**
   * try to find the token for the provided request's token key
   */
  private function get_token(&$request, $consumer, $token_type="access") {
	$token_field = @$request->get_parameter('oauth_token');
	$token = $this->data_store->lookup_token(
	  $consumer, $token_type, $token_field
	);
	if (!$token) {
	  throw new OAuthException("Invalid $token_type token: $token_field");
	}
	return $token;
  }

  /**
   * all-in-one function to check the signature on a request
   * should guess the signature method appropriately
   */
  private function check_signature(&$request, $consumer, $token) {
	// this should probably be in a different method
	$timestamp = @$request->get_parameter('oauth_timestamp');
	$nonce = @$request->get_parameter('oauth_nonce');

	$this->check_timestamp($timestamp);
	$this->check_nonce($consumer, $token, $nonce, $timestamp);

	$signature_method = $this->get_signature_method($request);

	$signature = $request->get_parameter('oauth_signature');
	$valid_sig = $signature_method->check_signature(
	  $request,
	  $consumer,
	  $token,
	  $signature
	);

	if (!$valid_sig) {
	  throw new OAuthException("Invalid signature");
	}
  }

  /**
   * check that the timestamp is new enough
   */
  private function check_timestamp($timestamp) {
	if( ! $timestamp )
	  throw new OAuthException(
		'Missing timestamp parameter. The parameter is required'
	  );

	// verify that timestamp is recentish
	$now = time();
	if (abs($now - $timestamp) > $this->timestamp_threshold) {
	  throw new OAuthException(
		"Expired timestamp, yours $timestamp, ours $now"
	  );
	}
  }

  /**
   * check that the nonce is not repeated
   */
  private function check_nonce($consumer, $token, $nonce, $timestamp) {
	if( ! $nonce )
	  throw new OAuthException(
		'Missing nonce parameter. The parameter is required'
	  );

	// verify that the nonce is uniqueish
	$found = $this->data_store->lookup_nonce(
	  $consumer,
	  $token,
	  $nonce,
	  $timestamp
	);
	if ($found) {
	  throw new OAuthException("Nonce already used: $nonce");
	}
  }

}

class OAuthDataStore {
  function lookup_consumer($consumer_key) {
	// implement me
  }

  function lookup_token($consumer, $token_type, $token) {
	// implement me
  }

  function lookup_nonce($consumer, $token, $nonce, $timestamp) {
	// implement me
  }

  function new_request_token($consumer, $callback = null) {
	// return a new token attached to this consumer
  }

  function new_access_token($token, $consumer, $verifier = null) {
	// return a new access token attached to this consumer
	// for the user associated with this token if the request token
	// is authorized
	// should also invalidate the request token
  }

}

class OAuthUtil {
  public static function urlencode_rfc3986($input) {
  if (is_array($input)) {
	return array_map(array('OAuthUtil', 'urlencode_rfc3986'), $input);
  } else if (is_scalar($input)) {
	return str_replace(
	  '+',
	  ' ',
	  str_replace('%7E', '~', rawurlencode($input))
	);
  } else {
	return '';
  }
}


  // This decode function isn't taking into consideration the above
  // modifications to the encoding process. However, this method doesn't
  // seem to be used anywhere so leaving it as is.
  public static function urldecode_rfc3986($string) {
	return urldecode($string);
  }

  // Utility function for turning the Authorization: header into
  // parameters, has to do some unescaping
  // Can filter out any non-oauth parameters if needed (default behaviour)
  public static function split_header($header, $only_allow_oauth_parameters = true) {
	$pattern = '/(([-_a-z]*)=("([^"]*)"|([^,]*)),?)/';
	$offset = 0;
	$params = array();
	while (preg_match($pattern, $header, $matches, PREG_OFFSET_CAPTURE, $offset) > 0) {
	  $match = $matches[0];
	  $header_name = $matches[2][0];
	  $header_content = (isset($matches[5])) ? $matches[5][0] : $matches[4][0];
	  if (preg_match('/^oauth_/', $header_name) || !$only_allow_oauth_parameters) {
		$params[$header_name] = OAuthUtil::urldecode_rfc3986($header_content);
	  }
	  $offset = $match[1] + strlen($match[0]);
	}

	if (isset($params['realm'])) {
	  unset($params['realm']);
	}

	return $params;
  }

  // helper to try to sort out headers for people who aren't running apache
  public static function get_headers() {
	if (function_exists('apache_request_headers')) {
	  // we need this to get the actual Authorization: header
	  // because apache tends to tell us it doesn't exist
	  $headers = apache_request_headers();

	  // sanitize the output of apache_request_headers because
	  // we always want the keys to be Cased-Like-This and arh()
	  // returns the headers in the same case as they are in the
	  // request
	  $out = array();
	  foreach( $headers AS $key => $value ) {
		$key = str_replace(
			" ",
			"-",
			ucwords(strtolower(str_replace("-", " ", $key)))
		  );
		$out[$key] = $value;
	  }
	} else {
	  // otherwise we don't have apache and are just going to have to hope
	  // that $_SERVER actually contains what we need
	  $out = array();
	  if( isset($_SERVER['CONTENT_TYPE']) )
		$out['Content-Type'] = $_SERVER['CONTENT_TYPE'];
	  if( isset($_ENV['CONTENT_TYPE']) )
		$out['Content-Type'] = $_ENV['CONTENT_TYPE'];

	  foreach ($_SERVER as $key => $value) {
		if (substr($key, 0, 5) == "HTTP_") {
		  // this is chaos, basically it is just there to capitalize the first
		  // letter of every word that is not an initial HTTP and strip HTTP
		  // code from przemek
		  $key = str_replace(
			" ",
			"-",
			ucwords(strtolower(str_replace("_", " ", substr($key, 5))))
		  );
		  $out[$key] = $value;
		}
	  }
	}
	return $out;
  }

  // This function takes a input like a=b&a=c&d=e and returns the parsed
  // parameters like this
  // array('a' => array('b','c'), 'd' => 'e')
  public static function parse_parameters( $input ) {
	if (!isset($input) || !$input) return array();

	$pairs = explode('&', $input);

	$parsed_parameters = array();
	foreach ($pairs as $pair) {
	  $split = explode('=', $pair, 2);
	  $parameter = OAuthUtil::urldecode_rfc3986($split[0]);
	  $value = isset($split[1]) ? OAuthUtil::urldecode_rfc3986($split[1]) : '';

	  if (isset($parsed_parameters[$parameter])) {
		// We have already recieved parameter(s) with this name, so add to the list
		// of parameters with this name

		if (is_scalar($parsed_parameters[$parameter])) {
		  // This is the first duplicate, so transform scalar (string) into an array
		  // so we can add the duplicates
		  $parsed_parameters[$parameter] = array($parsed_parameters[$parameter]);
		}

		$parsed_parameters[$parameter][] = $value;
	  } else {
		$parsed_parameters[$parameter] = $value;
	  }
	}
	return $parsed_parameters;
  }

  public static function build_http_query($params) {
	if (!$params) return '';

	// Urlencode both keys and values
	$keys = OAuthUtil::urlencode_rfc3986(array_keys($params));
	$values = OAuthUtil::urlencode_rfc3986(array_values($params));
	$params = array_combine($keys, $values);

	// Parameters are sorted by name, using lexicographical byte value ordering.
	// Ref: Spec: 9.1.1 (1)
	uksort($params, 'strcmp');

	$pairs = array();
	foreach ($params as $parameter => $value) {
	  if (is_array($value)) {
		// If two or more parameters share the same name, they are sorted by their value
		// Ref: Spec: 9.1.1 (1)
		natsort($value);
		foreach ($value as $duplicate_value) {
		  $pairs[] = $parameter . '=' . $duplicate_value;
		}
	  } else {
		$pairs[] = $parameter . '=' . $value;
	  }
	}
	// For each parameter, the name is separated from the corresponding value by an '=' character (ASCII code 61)
	// Each name-value pair is separated by an '&' character (ASCII code 38)
	return implode('&', $pairs);
  }
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---

h1. TXP Tweet (arc_twitter for Textpattern)

TXP Tweet provides access to your Twitter account through both the admin interface and the public side of your site. Update Twitter when you post a new article (with article-by-article opt out option), update and view your Twitter feed through the admin Twitter tab, and display Twitter feeds on your site.

h2. Table of contents

# "Plugin requirements":#arc_twitter_requirements
# "Installation / Uninstallation":#arc_twitter_installation
# "Tags":#arc_twitter_tags
# "Caching":#arc_twitter_caching
# "Preferences":#arc_twitter_prefs
# "Tweeting articles":#arc_twitter_article
# "The Twitter tab":#arc_twitter_admin
# "Author":#arc_twitter_author

h2(#arc_twitter_requirements). Plugin requirements

arc_twitter's minumum requirements:

* Textpattern 4.5+
* PHP 5 and cURL


h2(#arc_twitter_installation). Installation / Uninstallation

To install go to the 'plugins' tab under 'admin' and paste the plugin code into the 'Install plugin' box, 'upload' and then 'install'. The plugin will be automatically activated.

Before you start using %(tag)arc_twitter% you will need to make sure that the cache directory is writable. See the 'Caching' subsection below for further information.

You will need to associate your site with a Twitter account by connecting to Twitter from the plugin's options screen. Click on the link to connect to Twitter, you will be asked to login to Twitter, clicking this link will temporarily take you to the Twitter site where you will be asked to login and approve access for TXP Tweet to read and write to your Twitter account. If all is successful you will be returned to the options screen and your user name will appear.

At any time you can disassociate your Twitter account with TXP Tweet via your Twitter account preferences on the "Twitter website":http://www.twitter.com.

To uninstall %(tag)arc_twitter% simply delete the plugin from the 'Plugins' tab.  This will remove the plugin code, delete related preferences and drop the %(tag)arc_twitter% table from your Textpattern database.

h2(#arc_twitter_tags). Tags

h3(#arc_twitter_tag). arc_twitter

bc. <txp: arc_twitter />

h4. Attributes

* _user_ -- Twitter user name. Default: _arc_twitter username_.
* _retweets_ -- 1 to include retweets, 0 to exclude them from the feed. Default: '0'.
* _replies_ -- 1 to include replies, 0 to exclude them from the feed. Default: '1'.
* _limit_ -- maximum number of tweets to display (max. 200). Default: '25'.
* _fetch_ -- maximum number of tweets to retrieve from Twitter (max. 200)[1]. Default: '0'.
* _full_urls_ -- 1 to display full URLs, 0 for t.co URLs. Default: '0'.
* _dateformat_ -- format that update dates will appear as. Default: _Archive date format_.
* _label_ -- label for the top of the list.
* _labeltag_ -- independent wraptag for label.
* _break_ -- HTML tag (without brackets), or string, used to separate the updates. Default: 'li'.
* _wraptag_ -- HTML tag to be used as the wraptag, without brackets.
* _class_ -- CSS class attribute for wraptag. Default: 'arc_twitter'.
* _class_posted_ -- CSS class attribute applied to span tag around posted date. Default: 'arc_twitter-posted'.

fn1. Twitter's API counts the number of tweets to return before applying some of the filters, so the response can be fewer tweets than expected. To help avoid this you can set a _fetch_ limit that is higher than the display _limit_ that you want to output. If _fetch_ is less than _limit_ the latter will be used instead.

h4. Example usage

bc. <txp:arc_twitter user="drmonkeyninja" limit="5" wraptag="ul" break="li" dateformat="%b %Oe, %I:%M %p" />

Produces a bullet point list of the last 5 Twitter updates from drmonkeyninja's Twitter feed with a defined date format to override the default archive date format.

h3(#arc_twitter_search_tag). arc_twitter_search

bc. <txp:arc_twitter_search hashtags="txp" />

h4. Attributes

* _search_ -- comma separated list of search words.
* _hashtags_ -- comma separated list of hashtags to search for (not including the hash).
* _reply_ -- username of tweets in reply to.
* _mention_ -- username of user mentioned in tweets (__i.e.__ tweets containing @username).
* _limit_ -- maximum number of tweets to display (max. 200). Default: '10'.
* _full_urls_ -- 1 to display full URLs, 0 for t.co URLs. Default: '0'.
* _dateformat_ -- format that update dates will appear as. Default: _Archive date format_.
* _label_ -- label for the top of the list.
* _labeltag_ -- independent wraptag for label.
* _break_ -- HTML tag (without brackets), or string, used to separate the updates. Default: 'li'.
* _wraptag_ -- HTML tag to be used as the wraptag, without brackets.
* _class_ -- CSS class attribute for wraptag. Default: 'arc_twitter_search'.
* _class_user_ -- CSS class attribute applied to span tag around user name. Default: 'arc_twitter-user'.
* _class_posted_ -- CSS class attribute applied to span tag around posted date. Default: 'arc_twitter-posted'.

h4. Example usage

bc. <txp:arc_twitter_search search="plugin" hashtags="txp,textpattern" limit="25" />

Produces a list of tweets containing the word 'plugin' and the hashtags '#txp' and '#textpattern'. The tag will return a maximum of 25 tweets.


h3(#arc_twitter_retweet_tag). arc_twitter_tweet_button

Outputs the Twitter tweet button (formerly @<txp:arc_twitter_retweet />@).

bc. <txp:arc_twitter_tweet_button />

h4. Attributes

* _user_ -- Twitter user name to quote. Default: _arc_twitter username_.
* _url_ -- URL to tweet.
* _text_ -- tweet text.
* _follow1_ -- suggested Twitter account to follow.
* _follow2_ -- as _follow1_.
* _lang_ -- langauge. Default: 'en'.
* _count_ -- count box position, options: none, horizontal or vertical. Default: 'horizontal'.
* _include_js_ -- whether or not to include the widget JavaScript. Default: '1'.
* _optimise_js_ -- optimise the JavaScript by using a local script rather than that hosted on theTwitter site. Default: '0'.
* _class_ -- CSS class attribute applied to the tweet button. Default: 'twitter-share-button'.


h3(#arc_twitter_follow_button_tag). arc_twitter_follow_button

Outputs the Twitter follow button (an alternative version is included in the "arc_twitter_intents extension plugin":http://andy-carter.com/txp/arc_twitter_intents-txp-tweet-add-on).

bc. <txp:arc_twitter_follow_button />

h4. Attributes

* _user_ -- Twitter user name to follow. Default: _arc_twitter username_.
* _lang_ -- language. Default: 'en'.
* _count_ -- whether or not the follower count is shown. Default: '1'.
* _include_js_ -- whether or not to include the widget JavaScript. Default: '1'.
* _optimise_js_ -- optimise the JavaScript by using a local script rather than that hosted on the Twitter site. Default: '0'.
* _class_ -- CSS class attribute applied to the follow button. Default: 'twitter-follow-button'.


h3(#arc_twitter_tweet_url). arc_twitter_tweet_url

Returns the URL of the Twitter status for an article.

bc.. <txp:arc_twitter_tweet_url />

<txp:arc_twitter_tweet_url>Link text</txp:arc_twitter_tweet_url>

h4. Attributes

* _id_ -- Textpattern article ID. Default: _current article_.
* _title_ -- title attribute of the link.
* _class_ -- CSS class attribute applied to the link.


h3(#arc_twitter_tinyurl). arc_twitter_tinyurl

Returns the shortened URL of the article used for the Twitter update.

bc.. <txp:arc_twitter_tinyurl />

<txp:arc_twitter_tinyurl>Link text</txp:arc_twitter_tinyurl>

h4. Attributes

* _id_ -- Textpattern article ID. Default: _current article_.
* _title_ -- title attribute of the link.
* _class_ -- CSS class attribute applied to the link.


h2(#arc_twitter_caching). Caching

In order to prevent excessive repeatitive calls to the Twitter website it is recommended to cache results. Twitter limits the number of calls through the API, and continuous calls will result in Twitter closing to further requests. By default, arc_twitter caches for 30 minute intervals.

The following attributes are available to the _arc_twitter_ and _arc_twitter_search_ tags:-

* _caching_ -- '1' to cache feed, '0' to turn caching off (not recommended). Default: '1'.
* _cache_dir_ -- absolute path to the cache directory (must be writable). Default: _arc_twitter preferences_.
* _cache_time_ -- time in minutes that the cache files are stored before being refreshed. Default: '5'.

The admin side of this plugin enforces caching, apart from when it is posting to Twitter (__e.g.__ when posting or deleting an update).


h2(#arc_twitter_prefs). Preferences

You can access the plugins core preferences from either the Preferences or Plugins tabs in admin. Setup your Twitter account (you will be asked to connect via Twitter and this needs doing before you can use the plugin) and change the cache directory using arc_twitter's preferences. Without providing your account login details the admin area features of this plugin will be inactive.

You can select the URL shortener method you want to use to link back to your article on Twitter. Please note that if you select smd_short_url you will need to have installed and activated the "%(tag)smd_short_url% plugin":http://textpattern.org/plugins/1099/smd_short_url developed by Stef Dawson.


h2(#arc_twitter_article). Tweeting articles

By default arc_twitter will post an update to Twitter including a shortened URL to your article. Only live and active articles will be sent to Twitter, __i.e.__ articles posted in the future or as sticky articles will not be sent. If your article is successfully submitted to Twitter the update will appear in place of the Twitter option on the right-hand-side of the article edit screen.

Tweets are sent in the following format: [_Tweet prefix_] [_Article title_] [_Shortened URL_] [_Tweet suffix_]. You can change the prefix and suffix on an article-by-article basis by changing the tweet options under 'Update Twitter' on the article editor screen. The default _Tweet prefix_ can be set under the %(tag)arc_twitter% preferences screen (the default on installation is "Just posted:").

Please note that once an article has been tweeted the tweet cannot be edited.

If you want to monitor how many clicks you're getting from your TXP tweets in Google Analytics enable UTM in the plugin's preferences. It will add UTM parameters to the URLs tweeted that can then be monitored via the _Campaigns_ section of Google Analytics.


h2(#arc_twitter_admin). The Twitter tab

Under the Extensions tab (this can be changed from the plugin's preference page) a new Twitter tab should appear once you have connected your site to your Twitter account. From here you will be able to submit new Twitter updates, view basic account statistics, and check out your recent updates (including the option to delete your tweets).

h2(#arc_twitter_author). Author

"Andy Carter":http://andy-carter.com. For other Textpattern plugins by me visit my "Plugins page":http://andy-carter.com/txp.

Contributors: Andy Carter, Phil Wareham and Tommy Schmucker.

Thanks to "Michael Manfre":http://manfre.net/ for inspiration for the article tweet part of this plugin based on his %(tag)mem_twitter% plugin.  Additional thanks to the great Textpattern community for helping to test this plugin and for suggesting new features. The OAuth part of the plugin is thanks to "Abraham Williams":http://twitter.com/abraham.


# --- END PLUGIN HELP ---
-->
<?php
}
?>
