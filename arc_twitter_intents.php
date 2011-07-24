<?php

$plugin['name'] = 'arc_twitter_intents';
$plugin['version'] = '1.0-dev';
$plugin['author'] = 'Andy Carter';
$plugin['author_uri'] = 'http://redhotchilliproject.com/';
$plugin['description'] = 'Twitter Web Intents';
$plugin['order'] = '5';
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '3';

@include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
global $prefs,$txpcfg;

// arc_twitter_intents requires arc_twitter v3 or higher
require_plugin('arc_twitter');

function arc_twitter_intent_follow($atts, $thing=null)
{
    global $prefs;

    extract(lAtts(array(
        'user'      => $prefs['arc_twitter_user'],
        'user_id'   => '',
        'lang'      => '',
        'include_js'=> true,
        'optimise_js' => false,
        'class'     => ''
    ),$atts));
    
    $q = ($user_id) ? 'user_id='.$user_id : 'screen_name='.$user;
    
    $lang = _arc_twitter_intents_lang($lang);    
    $q .= '&amp;lang='.urlencode($lang);
    
    $thing = ($thing===null) ? 'Follow' : parse($thing);
    
    $html = href($thing,'http://twitter.com/intent/user?'.$q
      , ' class="'.$class.'"');
      
    $js = ($include_js) ? _arc_twitter_widget_js($optimise_js?true:false) : '';
      
    return $js.$html;
}

function arc_twitter_intent_favorite($atts, $thing=null)
{
    global $prefs, $thisarticle; 

    extract(lAtts(array(
        'user'      => $prefs['arc_twitter_user'],
        'related'   => '',
        'include_js'=> true,
        'optimise_js' => false,
        'lang'      => '',
        'id'        => '',
        'class'     => ''
    ),$atts));
    
    if ($id || $thisarticle['thisid']) {
    
      $q = 'related='.$user;
      if ($related) $q .= urlencode(($q?',':'related=').$related);
      
      if (!$id) {
        $row = safe_row("tweet_id"
        , 'arc_twitter', "article_id={$thisarticle['thisid']}");
        if (!$id = $row['tweet_id']) return false;
      }
      
      $q .= '&amp;tweet_id='.$id;
      
      $lang = _arc_twitter_intents_lang($lang);    
      $q .= '&amp;lang='.urlencode($lang);
      
      $thing = ($thing===null) ? 'Favorite' : parse($thing);
      
      $html = href($thing,'http://twitter.com/intent/favorite?'.$q
        , ' class="'.$class.'"');
        
      $js = ($include_js) ? _arc_twitter_widget_js($optimise_js?true:false) : '';
        
      return $js.$html;
    
    }
    
    return false;
}

function arc_twitter_intent_retweet($atts, $thing=null)
{
    global $prefs, $thisarticle; 

    extract(lAtts(array(
        'user'      => $prefs['arc_twitter_user'],
        'related'   => '',
        'include_js'=> true,
        'optimise_js' => false,
        'lang'      => '',
        'id'        => '',
        'class'     => ''
    ),$atts));
    
    if ($id || $thisarticle['thisid']) {
    
      $q = 'related='.$user;
      if ($related) $q .= urlencode(($q?',':'related=').$related);
      
      if (!$id) {
        $row = safe_row("tweet_id"
        , 'arc_twitter', "article_id={$thisarticle['thisid']}");
        if (!$id = $row['tweet_id']) return false;
      }
      
      $q .= '&amp;tweet_id='.$id;
      
      $lang = _arc_twitter_intents_lang($lang);    
      $q .= '&amp;lang='.urlencode($lang);
      
      $thing = ($thing===null) ? 'Retweet' : parse($thing);
      
      $html = href($thing,'http://twitter.com/intent/retweet?'.$q
        , ' class="'.$class.'"');
        
      $js = ($include_js) ? _arc_twitter_widget_js($optimise_js?true:false) : '';
        
      return $js.$html;
    
    }
    
    return false;
}

function arc_twitter_intent_reply($atts, $thing=null)
{
    global $prefs, $thisarticle; 

    extract(lAtts(array(
        'user'      => $prefs['arc_twitter_user'],
        'related'   => '',
        'text'      => '',
        'include_js'=> true,
        'optimise_js' => false,
        'lang'      => '',
        'id'        => '',
        'class'     => ''
    ),$atts));
    
    if ($id || $thisarticle['thisid']) {
    
      if (!$id) {
        $row = safe_row("tweet_id"
        , 'arc_twitter', "article_id={$thisarticle['thisid']}");
        if (!$id = $row['tweet_id']) return false;
      }
    
      $q = 'in_reply_to='.$id;
      
      if ($user) {
        $q .= '&amp;related='.urlencode($user);
      }
      if ($related) {
        $q .= urlencode(($user?',':'&related=').$related);
      }
      if ($text) {
        $q .= '&amp;text='.urlencode($text);
      }
      
      $lang = _arc_twitter_intents_lang($lang);    
      $q .= '&amp;lang='.urlencode($lang);
      
      $thing = ($thing===null) ? 'Reply' : parse($thing);
      
      $html = href($thing,'http://twitter.com/intent/tweet?'.$q
        , ' class="'.$class.'"');
        
      $js = ($include_js) ? _arc_twitter_widget_js($optimise_js?true:false) : '';
        
      return $js.$html;
    
    }
    
    return false;
}

/*
 * Set the intent language
 */
function _arc_twitter_intents_lang($lang='en')
{
  $lang = strtolower($lang);
  $langs = array('en', 'it', 'es', 'fr', 'ko', 'jp');
  return (in_array($lang,$langs)) ? $lang : 'en';
}


# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---

h1(title). TXP Tweet Intents

arc_twitter_intents requires arc_twitter v3 or higher to work.

h2(section). The arc_twitter_intent_follow tag

h3. Syntax

&lt;txp:arc_twitter_intent_follow&gt;Follow&lt;/txp:arc_twitter_intent_follow&gt;

h3. Usage

|_. Attribute|_. Description|_. Default|_. Example|
|user|Twitter user name to follow| _arc_twitter username_|user=&quot;drmonkeyninja&quot;|
|user_id|Twitter user id to follow (overrides the user attribute)| _arc_twitter username_|user=&quot;drmonkeyninja&quot;|


h2(section). The arc_twitter_intent_favorite tag

h3. Syntax

&lt;txp:arc_twitter_intent_favorite&gt;Favorite&lt;/txp:arc_twitter_intent_favorite&gt;

h3. Usage

|_. Attribute|_. Description|_. Default|_. Example|
|id|Tweet id to favourite| _article's associated tweet from arc_twitter_| |
|user|Twitter user name related to tweet| _arc_twitter username_|user=&quot;drmonkeyninja&quot;|
|related|Comma separated list of related Twitter users to recommend following| _unset_|related=&quot;textpattern, twitter&quot;|


h2(section). The arc_twitter_intent_retweet tag

h3. Syntax

&lt;txp:arc_twitter_intent_retweet&gt;Retweet&lt;/txp:arc_twitter_intent_retweet&gt;

h3. Usage

|_. Attribute|_. Description|_. Default|_. Example|
|id|Tweet id to retweet| _article's associated tweet from arc_twitter_| |
|user|Twitter user name related to tweet| _arc_twitter username_|user=&quot;drmonkeyninja&quot;|
|related|Comma separated list of related Twitter users to recommend following| _unset_|related=&quot;textpattern, twitter&quot;|


h2(section). The arc_twitter_intent_reply tag

h3. Syntax

&lt;txp:arc_twitter_intent_reply&gt;Reply&lt;/txp:arc_twitter_intent_reply&gt;

h3. Usage

|_. Attribute|_. Description|_. Default|_. Example|
|id|Tweet id to reply to| _article's associated tweet from arc_twitter_| |
|user|Twitter user name related to tweet (used for recommended accounts)| _arc_twitter username_|user=&quot;drmonkeyninja&quot;|
|related|Comma separated list of related Twitter users to recommend following| _unset_|related=&quot;textpattern, twitter&quot;|
|text|Set some text for the reply tweet| _unset_| |


h2(section). Language

All the arc_twitter_intents tags have an optional "lang" attribute for setting the language of the Twitter interface. The following values/languages are available:-

|_. Attribute value|_. Language|
|en|English (default)|
|fr|French|
|it|Italian|
|es|Spanish|
|ko|Korean|
|jp|Japanese|


h2(section). JavaScript

All the arc_twitter_intents tags have two optional attributes that relate to the inclusion of the Twitter widget JavaScript.

|_. Attribute|_. Description|_. Default|_. Example|
|include_js|Include the Twitter widget JavaScript|true|include_js=&quot;false&quot;|
|optimise_js|Optimise the JavaScript by using a local script rather than that hosted on the Twitter site|false|optimise_js=&quot;true&quot;|

TXP Tweet will only include the widget JavaScript once on a page regardless of the number of tags used that include it.


# --- END PLUGIN HELP ---
-->
<?php
}
?>
