<?php

# This extension requires a table in the database, create it with the following mysql command:
# replace varbinary with varchar depending on wiki setup
# create table /*$wgDBprefix*/wigotext (vote_id varbinary(255) NOT NULL, text mediumblob, is_cp boolean default false, PRIMARY KEY (vote_id)) /*$wgDBTableOptions*/;

//version 3.5 database addition:
//alter table wigotext add column is_cp boolean default false;

// Extension credits that show up on Special:Version
$wgExtensionCredits['parserhook'][] = array(
        'name' => 'Best of display system',
        'author' => '[http://rationalwiki.com/wiki/User:Tmtoulouse Trent Toulouse], [http://rationalwiki.com/wiki/User:Nx Nx]',
        'url' => 'http://rationalwiki.com/',
        'description' => 'Displays WIGO items sorted by votes, with custom settings.',
        'version' => '3.5'
);

$wgBestofIP = dirname( __FILE__ );
$wgExtensionMessagesFiles['bestof'] = "$wgBestofIP/bestof.i18n.php";

//Avoid unstubbing $wgParser on setHook() too early on modern (1.12+) MW versions, as per r35980
if ( defined( 'MW_SUPPORTS_PARSERFIRSTCALLINIT' ) ) {
	$wgHooks['ParserFirstCallInit'][] = 'bestofinit';
} else { // Otherwise do things the old fashioned way
	$wgExtensionFunctions[] = 'bestofinit';
}

$wgHooks['ArticleSaveComplete'][] = 'bestofharvest';

function bestofinit()
{
  global $wgParser;
  wfLoadExtensionMessages('bestof');  
  $wgParser->setHook('bestof','bestofrender');
  return true;  
}

function image(&$input,$pollid) {
  $input = preg_replace('/\[[^\]]*conservapedia\.com[^\]]*\]/i',
                        "$0<sup>[[:Image:{$pollid}_x.png|img]]</sup>",$input);
  $x = 0;
  do {
    $input = preg_replace('/(<sup>\[\[:Image:' . $pollid . '_)x(\.png\|img\]\]<\/sup>)/',
                        "$1 {$x}$2", $input,1,$count);
    ++$x;
  } while ($count);
}

function imagecp(&$output, $parser) {
  $matchi = preg_match_all('/(<a[^>]*href="([^"]*conservapedia\.com[^"]*)"[^>]*>(?:[^<]|<[^\/]|<\/[^a]|<\/a[^>])*<\/a>)(?!<span class="wigocapture">)/i', $output,$matches,PREG_OFFSET_CAPTURE);
  if ($matchi > 0) $newoutput = substr($output,0,$matches[1][0][1]);
  for ($i=0; $i<$matchi;++$i) {
    $imgname = 'capture_' . sha1($matches[2][$i][0]) . '.png';
    $text = $matches[1][$i][0];    
    $img =  $parser->recursiveTagParse("<span class=\"wigocapture\"><sup>[[:Image:$imgname|img]]</sup></span>");
    $nextlength = (($i == $matchi-1) ? (strlen($output) - ($matches[1][$i][1] + strlen($text))) : ($matches[1][$i+1][1] - ($matches[1][$i][1] + strlen($text))));
    $newoutput .= substr($output,$matches[1][$i][1],strlen($text)) . $img . 
                  substr($output,$matches[1][$i][1]+strlen($text),$nextlength);
  }
  if ($matchi > 0) $output = $newoutput;
}

function bestofharvest( &$article, &$user, $text, $summary, $minoredit, $sectionanchor, $dummynull, &$flags, $revision, &$status, $baseRevId ) {
  Sanitizer::removeHTMLcomments($text);
  $matchnum = preg_match_all('/<vote(cp|)[^>]*poll=([^ >]*|"[^"]*")[^>]*>(.*?)<\/vote(?:cp|)>/',$text,$matches,PREG_SET_ORDER);
  $dbw = wfGetDB(DB_MASTER);
  foreach ($matches as $match) {
    $hasimg = ( (strpos($match[0],'img="on"') !== false) || (strpos($match[0],'img="expanded"') !== false) );
    $hasimgcp = ( $match[1] == "cp" );
    $voteid = $match[2];
    $wtext = $match[3];
    if ($hasimg) {
      image($wtext,$voteid);
    }
    $dbw->replace('wigotext','vote_id',array('vote_id' => $voteid, 'text' => $wtext, 'is_cp' => $hasimgcp),__METHOD__);
  }
  $dbw->commit();
  return true;
}

function bestofrender($input, $args, $parser)
{
  $parser->disableCache();
  if ($args['dynamic']) {
    global $wgRequest;
    global $wgTitle;
    $cutoff = $wgRequest->getVal('bfcutoff', array_key_exists('cutoff',$args) ? $args['cutoff'] : null);
    $year = $wgRequest->getVal('bfyear', array_key_exists('year',$args) ? $args['yar'] : null);
    $month = $wgRequest->getVal('bfmonth', array_key_exists('month',$args) ? $args['month'] : null);
    $keyword = $wgRequest->getVal('bfsearch', array_key_exists('keyword',$args) ? $args['keyword'] : null);
    
    wfLoadExtensionMessages('bestof');
    
    global $wgLang;
    $selected = $month === null ? 'all' : intval($month);
    $monthopts[] = Xml::option(wfMessage('monthsall')->text(),'all',$selected === 'all');
    for ($i = 1; $i < 13; ++$i)
    {
      $monthopts[] = Xml::option($wgLang->getMonthName($i),$i, $selected === $i);
    }
    $forminside = Xml::openElement('table',array('cellpadding' => 8)) .
                  Xml::openElement('tr') .
                    Xml::openElement('td') .
                      Xml::label(wfMessage('bestof-cutoff')->text(),'bfcutoff') .
                    Xml::closeElement('td') .
                    Xml::openElement('td') .                  
                      Xml::label(wfMessage('bestof-month')->text(),'bfmonth') .
                    Xml::closeElement('td') .
                    Xml::openElement('td') .
                      Xml::label(wfMessage('bestof-filter')->text(),'bfsearch') .
                    Xml::closeElement('td') .
                  Xml::closeElement('tr') . "\n" .
                  Xml::openElement('tr') .
                    Xml::openElement('td') .
                      Xml::input( 'bfcutoff', 3, $cutoff, array('maxlength' => 3) ) . ' '.
                    Xml::closeElement('td') .
                    Xml::openElement('td') . 
                      Xml::input( 'bfyear', 4, $year, array('maxlength' => 4) ) . ' '.
                      Xml::openElement('select',array('name' => 'bfmonth','class' => 'mw-month-selector')) .
                        implode("\n",$monthopts) .
                      Xml::closeElement('select') .
                    Xml::closeElement('td') .
                    Xml::openElement('td') .
                      Xml::input( 'bfsearch', 25, $keyword ) . ' '.
                    Xml::closeElement('td') .
                    Xml::openElement('td') .
                      Xml::submitButton(wfMessage('bestof-submit')->text()) .
                    Xml::closeElement('td') .
                  Xml::closeElement('tr') .
                  Xml::closeElement('table');
    foreach ($wgRequest->getValues() as $key => $value)
    {
      if ($key != 'bfcutoff' && $key != 'bfyear' && $key != 'bfmonth' && $key != 'bfsearch') {
        $forminside .= "\n" . html::hidden($key,$value);
      }
    }
    $form = Xml::openElement('form', array( 'id' => 'bestofoption', 
                                            'action' => '',
                                            'method' => $wgRequest->wasPosted() ? 'POST' : 'GET' ) ) .
            Xml::fieldset(wfMessage('bestof-legend')->text(),$forminside) .
            Xml::closeElement('form');
    $output = $form;
  } else {
    $cutoff = $args['cutoff'];
    $year = $args['year'];
    $month = $args['month'];
    $keyword = $args['keyword'];
  }
  $list = bestofget($args['poll'],$cutoff,$month === 'all' ? null : $month,$year,$keyword,$parser);          
  $output .= $list;
  return $output;
}

function bestofget($pollid, $cutoff, $month, $year, $keyword, $parser)
{
  $dbr = wfGetDB(DB_SLAVE);
  $conds = array("vote_id " . $dbr->buildLike( $pollid, $dbr->anyString() ) );
  $options = array('GROUP BY' => 'vote_id','ORDER BY' => 'total DESC');
  if ($cutoff != null)
  {
    $options['HAVING'] = "total >= " . intval( $cutoff );
  }
  if ($month != null && $year != null)
  {
    $month = str_pad($month,2,'0',STR_PAD_LEFT);
    $conds[] = "timestamp " . $dbr->buildLike( $year, $month, $dbr->anyString() );
  } elseif ($month == null && $year != null) {
    $conds[] = "timestamp " . $dbr->buildLike( $year, $dbr->anyString() );
  } elseif ($month != null && $year == null) {
    $month = str_pad($month,2,'0',STR_PAD_LEFT);
    $conds[] = "timestamp " . $dbr->buildLike( $dbr->anyChar(), $dbr->anyChar(), $dbr->anyChar(), $dbr->anyChar(), $month, $dbr->anyString() );
  }
  if ($keyword != null) {
    $conds[] = "text " . $dbr->buildLike( $dbr->anyString(), $keyword, $dbr->anyString() );
  }
  $res = $dbr->select(array('wigovote','wigotext'),
                      array('sum(case vote when 1 then 1 else 0 end) as plus',
                            'sum(case vote when -1 then 1 else 0 end) as minus',
                            'sum(case vote when 0 then 1 else 0 end) as zero',
                            'ifnull(sum(vote),0) as total',
                            'vote_id', 'text','is_cp'),
                      $conds,
                      __METHOD__,
                      $options,
                      array('wigotext' => array('RIGHT JOIN','id=vote_id')));
  $output="<table cellspacing=\"2\" cellpadding=\"2\" border=\"0\">";
  wfLoadExtensionMessages('wigo3');
  static $sep = null;
  if (is_null($sep)) $sep = wfMessage('bestof-tooltipseparator')->text();
  while ($row = $res->fetchRow()) {
    $plus = $row['plus'];
    $minus = $row['minus'];
    $zero = $row['zero'];
    $numvotes = $plus+$minus+$zero;
    $total = $plus - $minus;
	// wfMsgExt() resets the parser state if the message contains a parser function, breaking for example references. recursiveTagParse doesn't.
	// @todo That's probably not true anymore, Message::parseText() uses the clone held by MessageCache, not $wgParser
    $totalvotes = htmlspecialchars($row['vote_id']) . $sep . $parser->recursiveTagParse(wfMessage('wigovotestotald')->params($numvotes,$plus,$zero,$minus)->plain());
    $text = $parser->recursiveTagParse($row['text']);
    if ($row['is_cp']) {
      imagecp($text,$parser);
    }
    $output .= "<tr><td width=20px valign=top title=\"{$totalvotes}\">{$total}</td><td>{$text}</td></tr>";
  }
  $output.="</table>";
  $res->free();  
  return "$output";
}
