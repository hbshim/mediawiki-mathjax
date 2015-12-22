<?php
# vim: ts=2 sw=2 expandtab

// Complete rewrite...
/**
 * http://www.mediawiki.org/wiki/Extension:MathJax
 * http://www.gnu.org/licenses/gpl-3.0.txt 
 *
 * @synopsis
 * Enables MathJax (http://www.mathjax.org/) for typesetting TeX and LaTeX 
 * formulae in MediaWiki inside $, \( and <math> (for inline) and $$, \[ and 
 * :<math> (for display) math environments. This gives nice and scalable 
 * mathematics. The extension also enables the usage of \label{} and \eqref{} 
 * tags with automatic formula numbering. If needed you can still hand label by 
 * using \tag{}.
 *
 * @note It doesn't matter if you have math support on or off in your MediaWiki 
 * installation, this extension strips the math tags before the standard math 
 * processor gets a chance to act.
 *
 * @author  Dirk Nuyens (dirk.nuyens at cs.kuleuven.be)
 * @date    Sun Apr 22 23:28:48 CEST 2012
 * @version 0.7
 *
 * Changelog:
 *   0.5        Initial public release.
 *   0.5.1      Modifications to allow integration with Semantic MW  (SMW).
 *              Compatability code for Parser::MARKER_SUFFIX added.
 *   0.5.2      Kind of revert move away from the markers used by MW as it
 *              does no really matter which ones we use (so no need for the SMW 
 *              fix from 0.5.1 anymore).
 *              Allowed \label and \tag at the same time as one would expect.
 *              Added clickable links for the formula references, this currently 
 *              assumes the used label or tag is a valid XHTML id.
 *   0.5.2b     Removed redundant comma's in the MathJax configuration hub file 
 *              to please IE...
 *   0.6        Updates for MediaWiki 1.18 (tested with 1.18.2) and MathJax 
 *              2.0, amonst others incorporating a patch from EvanChou 
 *              (thanks!) and the CDN modification of Evan. This is mainly a 
 *              maintenance update to get the extension back on track for 
 *              1.18.2.
 *   0.7        Complete rewrite, $ and $$ can now be turned on and off, much
 *              better protection and detection, numbering is consistent among
 *              different environments, no global variables anymore (everything
 *              is now in one class), new tag <nomathjax>, magic words to turn
 *              on and off features: __MATHJAX__ and __NOMATHJAX__, 
 *              __MATHJAX_NUMBER__ and __MATHJAX_NONUMBER__, 
 *              __MATHJAX_DOLLAR__ and __MATHJAX_NODOLLAR__, and
 *              __MATHJAX_DOLLARDOLLAR__ and __MATHJAX_NODOLLARDOLLAR__.
 */

# We can't run without MW:
if(!defined('MEDIAWIKI'))
  die('This is a mediawiki extensions and can\'t be run from the command line.');

# List the extension on the Special:Version page
$wgExtensionCredits['other'][] = array(
  'path'         => __FILE__,
  'name'         => 'MathJax',
  'version'      => '0.7',
  'author'       => array('Dirk Nuyens'),
  'url'          => 'http://www.mediawiki.org/wiki/Extension:MathJax',
  'description'  => 'Enables MathJax (http://www.mathjax.org/) for typesetting TeX '
                   .'and LaTeX formulae in MediaWiki inside <tt><nowiki>$</nowiki>'
                   .'</tt>, <tt>\(</tt> and <tt>&lt;math&gt;</tt> (for inline) and '
                   .'<tt><nowiki>$$</nowiki></tt>, <tt>\[</tt> and <tt>:&lt;math&gt;'
                   .'</tt> (for display) math environments.'
                   .' This gives nice and scalable mathematics. The extension also '
                   .'enables the usage of <tt>\label{}</tt> and <tt><nowiki>\eqref{}'
                   .'</nowiki></tt> tags with automatic formula numbering. If needed'
                   .' you can still hand label by using <tt>\tag{}</tt>.',
);

$wgHooks['ParserFirstCallInit'][] = 'MathJax_Parser::ParserInit'; // register <nomathjax> and <source>(!) tags and register other hooks

class MathJax_Parser {

  static $MathJaxJS; ///< Location of MathJax Engine

  ///< To use MathJax content delivery network (CDN) (the easy default) 
  ///< set 'http://cdn.mathjax.org/mathjax/latest/MathJax.js?config=TeX-AMS-MML_HTMLorMML' for the default

  static $MathJaxConfig;   ///< Localtion of local MathJax config file

  ///< to set this to the defaults, set
  ///< dirname(__FILE__) . "/mwMathJaxConfig.js"

  static $disabled        = false; ///< Disable the extension unless marked on the page to enable it "__MATHJAX__" or disable by "__NOMATHJAX__".
  static $do_number       = true;  ///< Turn on or off numbering and "\eqref" linking by this extension, also by "__MATHJAX_NUMBER__" and "__MATHJAX_NONUMBER__".
  static $do_dollar       = true;  ///< Turn on or off processing of inline math delimited by dollar sign: $...$. Also by "__MATHJAX_DOLLAR__" and "__MATHJAX_NODOLLAR__".
  static $do_dollardollar = true;  ///< Turn on or off processing of display math delimetd by double dollar sign: $$...$$. Also by "__MATHJAX_DOLLARDOLLAR__" and "__MATHJAX_NODOLLARDOLLAR__".

  static $ProtectTags = array('nowiki', 'pre', 'source', 'syntaxhighlight'); ///< these are stripped out by default
  static $ProtectTags_special = array('code', 'nomathjax'); ///< these are not stripped out by default

  static $tempstrip; ///< Variables for stripping formula's in stage 2
  static $uniq_prefix; ///< Variables for stripping formula's in stage 2
  static $postfix; ///< Variables for stripping formula's in stage 2 
  static $strip_state = null; ///< Variables for stripping formula's in stage 2 

  static $mark_n = 1; ///< Variables for numbering and resolving references at the end of stage 2 
  static $eqnumbers = array(); ///< Variables for numbering and resolving references at the end of stage 2 
  static $eqnumbers_rev = array(); ///< Variables for numbering and resolving references at the end of stage 2 
  static $eqnumber = 1; ///< Variables for numbering and resolving references at the end of stage 2 
  static $remembered_label; ///< Variables for numbering and resolving references at the end of stage 2 
  static $label; ///< Variables for numbering and resolving references at the end of stage 2 

  /** \brief set up hooks "<nomathjax>", "<code>" and other parser hooks for math processing and injection of the MathJax JavaScript.
   *  @param[out] $parser
   *  @param[in] $parser
   */
  static function ParserInit(Parser $parser)
  {
    $parser->setHook('nomathjax', 'MathJax_Parser::Render_NoMathJax');
    $parser->setHook('code',      'MathJax_Parser::Render_Code');
    global $wgHooks;
    $wgHooks['ParserBeforeInternalParse'][] = 'MathJax_Parser::Stage1';
    $wgHooks['InternalParseBeforeLinks'][]  = 'MathJax_Parser::Stage2';
    $wgHooks['BeforePageDisplay'][]         = 'MathJax_Parser::Inject_JS'; // Inject MathJax and MathJax configuration
    return true;
  }

  /** \brief handling of <nomathjax> tag
   *
   * The callback should have the following form: <code>function myParserHook( $text, $params, $parser, $frame ) { ... }</code>
   */
  static function Render_NoMathJax($text, array $args, Parser $parser, PPFrame $frame)
  {
    // this is a no-op, we do nothing
    return $parser->recursiveTagParse($text, $frame);
  }

  // helper for next function
  static function ArgsToString($args) {
    $argsarray = array();
    foreach($args as $optname => $optvalue) {
      $argsarray[] = $optname . "='" . htmlspecialchars($optvalue, ENT_QUOTES) . "'";
    }
    $argstring = implode(' ', $argsarray);
    if(!empty($argstring)) $argstring = ' ' . $argstring;
    return $argstring;
  }

  // handling of <code> tag
  static function Render_Code($text, array $args, Parser $parser, PPFrame $frame)
  {
    // this is a no-op, we just want this tag stripped out in stage2 and mw doesn't do this one
    $output = $parser->recursiveTagParse($text, $frame);
    return "<code" . self::ArgsToString($args) . ">$output</code>";
  }

  // inject MathJax JavaScript and configuration
  static function Inject_JS($out)
  {
    if(self::$disabled) return true;
    if(self::$mark_n == 1) return true; // there was no math detected so don't include the MathJax Javascript
    global $wgTitle, $wgJsMimeType;
    if($wgTitle->getNamespace() == NS_SPECIAL) return true;
    if(!isset(self::$MathJaxConfig)) self::$MathJaxConfig = dirname(__FILE__) . "/mwMathJaxConfig.js"; # seems to be not allowed in static declaration
    $config = rtrim(file_get_contents(self::$MathJaxConfig));
    $config .= "\n//<![CDATA[\n";
    if(self::$do_dollar) {
      $config .= "MathJax.Hub.config.tex2jax.inlineMath.push(['$','$']);\n";
    } else {
      $config .= "for(i = 0; i < MathJax.Hub.config.tex2jax.inlineMath.length; i++) { if(MathJax.Hub.config.tex2jax.inlineMath[i][0] == '$') { MathJax.Hub.config.tex2jax.inlineMath.splice(i, 1); break; } }\n";
    }
    if(self::$do_dollardollar) {
      $config .= "MathJax.Hub.config.tex2jax.displayMath.push(['$$','$$']);\n";
    } else {
      $config .= "for(i = 0; i < MathJax.Hub.config.tex2jax.displayMath.length; i++) { if(MathJax.Hub.config.tex2jax.displayMath[i][0] == '$$') { MathJax.Hub.config.tex2jax.displayMath.splice(i, 1); break; } }\n";
    }
    $config .= "//]]>\n";
    $out->addScript("<script type='text/x-mathjax-config'>\n" . rtrim($config) . "\n</script>\n");
    $out->addScript("<script type='$wgJsMimeType' src='" . self::$MathJaxJS . "'></script>\n");
    return true;
  }

  /** \brief Remove certain tags
   *
   * @param[in] $text
   * @param[in] $ConfigTag string value to be removed from $text
   * @param[out] boolean true if removal ocurred and false otherwise
   */
  static function LookForConfigTag(&$text, $ConfigTag)
  {
    $i = strpos($text, $ConfigTag);
    if($i === false) return false;
    $text = str_replace($ConfigTag, '', $text);
    return true;
  }

  // protect certain parts from MathJax
  // specifically: <nomathjax>...</nomathjax>
  // detect magic words to configure on a per page level
  static function Stage1(Parser &$parser, &$text, &$strip_state)
  {
    if(self::LookForConfigTag($text, '__MATHJAX__')) {
      self::$disabled = false;
    }
    if(self::$disabled) return true;
    if(self::LookForConfigTag($text, '__NOMATHJAX__') !== false) {
      self::$disabled = true;
      return true;
    }
    if(self::LookForConfigTag($text, '__MATHJAX_NUMBER__')) self::$do_number = true;
    if(self::LookForConfigTag($text, '__MATHJAX_NONUMBER__')) self::$do_number = false;
    if(self::LookForConfigTag($text, '__MATHJAX_DOLLAR__')) self::$do_dollar = true;
    if(self::LookForConfigTag($text, '__MATHJAX_NODOLLAR__')) self::$do_dollar = false;
    if(self::LookForConfigTag($text, '__MATHJAX_DOLLARDOLLAR__')) self::$do_dollardollar = true;
    if(self::LookForConfigTag($text, '__MATHJAX_NODOLLARDOLLAR__')) self::$do_dollardollar = false;

    self::$uniq_prefix = $parser->mUniqPrefix;
    self::$postfix = "-QINU\x7f"; // Parser::MARKER_SUFFIX;
    // in here we just want to protect some tags as being considered for math processing...
    // we strip them out and put protection arround them by a span tag with class=tex2jax_ignore
    $tempstrip = new ReplacementArray;
    $matches = array();
    $text = Parser::extractTagsAndParams(array_merge(self::$ProtectTags, self::$ProtectTags_special), $text, $matches, self::$uniq_prefix);
    foreach($matches as $marker => $data) {
      list($element, $content, $params, $tag) = $data;
      $tagName = strtolower($element);
      switch($tagName) {
      case '!--': // Comments <!-- ... --> get always extracted
        $output = $tag;
        $tempstrip->setPair($marker, $output);
        break;
      default:
        $output = '<span class="tex2jax_ignore">' . $tag . '</span>'; // add protection
        $tempstrip->setPair($marker, $output);
        break;
      }
    }
    self::SubstMathTag($text); // <math>...</math> -> \(...\) and :<math>...</math> -> \[...\]
    $text = $tempstrip->replace($text);
    return true;
  }

  /**
   * SubstMathTag(&$text)
   *
   * Change "<math>...</math>"   into "\(...\)" (inline math style)
   *    and ":</math>...</math>" into "\[...\]" (display math style) in place.
   */
  static function SubstMathTag(&$text)
  {
    // Change <math>...</math> and :<math>...</math> into \(...\) and \[...\]
    $text = preg_replace('|:\s*<math>(.*?)</math>|s', '\\\\[$1\\\\]', $text);
    $text = preg_replace('|<math>(.*?)</math>|s', '\\\\($1\\\\)', $text);
  }

  // Stage 2 processing: strip out math tags and do numbering and referencing if enabled
  static function Stage2(Parser &$parser, &$text, &$strip_state)
  {
    if(self::$disabled) return true;

    // we need to filter out the newlines in our math here such that the further wiki markup will not screw our maths
    // for this we need to detect all maths and replace newlines by spaces
    // the two problematic math delimeters are $...$ and $$...$$ as they might be used for non math
    self::$tempstrip = new ReplacementArray;

    // at this stage there could have been transclusion expanded or template expansion so expand wiki math tags again:
    self::SubstMathTag($text); // <math>...</math> -> \(...\) and :<math>...</math> -> \[...\]

    // now handle all MathJax math environments by registering equations 
    // (numbering) and removing newlines
    // watch out for \$ in TeX using negative look behind:
    // Note: ordering of numbering is wrong in this way, so we fix this up just below
    // php regex madness, count the number of slashes
    if(self::$do_number) {
      // we need two passes for the numbering, so use tempstrip here:
      if(self::$do_dollardollar)
        $text = preg_replace_callback('/((\$\$))(.*?)((?<!\\\\)\\2)/s',         'MathJax_Parser::RegisterAndTempStripMath', $text);
      if(self::$do_dollar)
        $text = preg_replace_callback('/((\$))(.*?)((?<!\\\\)\\2)/s',           'MathJax_Parser::RegisterAndTempStripMath', $text);
      $text = preg_replace_callback('/((\\\\\\[))(.*?)(\\\\\\])/s',             'MathJax_Parser::RegisterAndTempStripMath', $text);
      $text = preg_replace_callback('/((\\\\\\())(.*?)(\\\\\\))/s',             'MathJax_Parser::RegisterAndTempStripMath', $text);
      $text = preg_replace_callback('/(\\\\begin{(.*?)})(.*?)(\\\\end{\\2})/s', 'MathJax_Parser::RegisterAndTempStripMath', $text);

      $text = self::$tempstrip->replace($text);

      // now renumber!
      self::$eqnumber = 1;
      $text = preg_replace_callback('/\\\\tag{(' . self::$uniq_prefix . "-MathJax-EqNumber-" . '\\d+' . self::$postfix . ')}/', 'MathJax_Parser::Renumber',  $text);
      $text = preg_replace_callback('/' . self::$uniq_prefix . "-MathJax-EqNumber-" . '\\d+' . self::$postfix . '/',            'MathJax_Parser::Renumber2', $text);

      // replace all occurrences of \eqref{} (and also \ref{}) by the correct formula 
      // reference (as plain text, e.g., \eqref{sum} becomes (2))
      $text = preg_replace_callback('(\\\\(eq)?ref\\{(.*?)\\})', 'MathJax_Parser::ReplaceEqrefs', $text);
    }

    // now really strip the math to protect it from being processed as wiki text:
    self::$strip_state = &$strip_state;
    if(self::$do_dollardollar)
      $text = preg_replace_callback('/((\$\$))(.*?)((?<!\\\\)\\2)/s',         'MathJax_Parser::StripMath', $text);
    if(self::$do_dollar) // FIXME: check negative look behind for $$
      $text = preg_replace_callback('/((\$))(.*?)((?<!\\\\)\\2)/s',           'MathJax_Parser::StripMath', $text);
    $text = preg_replace_callback('/((\\\\\\[))(.*?)(\\\\\\])/s',             'MathJax_Parser::StripMath', $text);
    $text = preg_replace_callback('/((\\\\\\())(.*?)(\\\\\\))/s',             'MathJax_Parser::StripMath', $text);
    $text = preg_replace_callback('/(\\\\begin{(.*?)})(.*?)(\\\\end{\\2})/s', 'MathJax_Parser::StripMath', $text);

    return true;
  }

  /**
   * MathJax_register_and_strip_math($matches)
   *
   * This is a regex callback used from MathJax_parser_stage2 to replace MathJax 
   * math environments and register an equation number (if \label{} or \tag{} is 
   * present).
   *
   * @param matches   Match array where 
   *                    $matches[1] is the opening math delimiter
   *                    $matches[3] is the TeX content
   *                    $matches[4] is the closing math delimiter
   *                  Note: $matches[2] has no function; it was used as a backref
   *
   * @return The marker which was used to replace the math environment.
   *
   * @globals   $MathJax_strip_state, $MathJax_unique_prefix, $MathJax_mark_n, $MathJax_marker_suffix
   *            $MathJax_eqnumber, $MathJax_eqnumbers, $MathJax_strip_ws_no_strip, $MathJax_remembered_label,
   *            $MathJax_label
   */
  static function RegisterAndTempStripMath($matches)
  {
    // we could check if this is latex, could try to see if delimiters are valid... or if eq looks like an equation, currently not done
    $eq = $matches[3];
    $opening_delim = $matches[1];
    $closing_delim = $matches[4];

    if(self::$do_number) {
      // formula numbering
      // TODO: numbering might now be done now by MathJax... but I couldn't get this to work reliably yet
      self::$label = false; // to remember if we had to label
      // first check if there is a \label and a \tag
      self::$remembered_label = "";
      if((strpos($eq, '\\tag') !== false) && preg_match('/\\\\label\{(.*?)\}/', $eq, $matches2)) {
        // if so then remove the label and remember it
        self::$remembered_label = $matches2[1];
        $eq = str_replace('\\label{'.$matches2[1].'}', '', $eq);
      }
      // formula numbering
      $eq = preg_replace_callback('(\\\\(label|tag)\{(.*?)\})', 'MathJax_Parser::RegisterEq', $eq);
    }

    $stripped = $opening_delim . $eq . $closing_delim;
    if(self::$label) $stripped = '<span id="Eq-' . self::$label . '">' . $stripped . "</span>";
    $marker = self::$uniq_prefix . "-MathJax-" . self::$mark_n++ . self::$postfix;

    self::$tempstrip->setPair($marker, $stripped);
    return $marker;
  }

  // really strip the math and add it to the Parser's nowiki queueu
  static function StripMath($matches)
  {
    // we could check if this is latex, could try to see if delimiters are valid... or if eq looks like an equation, currently not done
    $eq = $matches[3];
    $opening_delim = $matches[1];
    $closing_delim = $matches[4];

    //$eq = preg_replace('/\n/', "\r", $eq); // if we wouldn't strip we would have to protect newlines

    $stripped = $opening_delim . $eq . $closing_delim;
    $marker = self::$uniq_prefix . "-MathJax-" . self::$mark_n++ . self::$postfix;

    // self::$strip_state->nowiki->setPair($marker, $stripped); // for older MW use this line instead of next line
    self::$strip_state->addNoWiki($marker, $stripped);
    return $marker;
  }

  static function MakeEqNumberMarker($n)
  {
    return self::$uniq_prefix . "-MathJax-EqNumber-" . $n . self::$postfix;
  }

  /**
   * RegisterEq($matches)
   *
   * This is a regex callback used from MathJax_register_and_strip_math to 
   * register a formula number (or given tag) for later referencing by \eqref.
   *
   * @param matches   Match array where
   *                    $matches[1]   is either 'label' or 'tag'
   *                    $matches[2]   is the argument from \label{} or \tag{}
   *
   * @return An autonumbered tag.
   *
   * @globals   $MathJax_eqnumber, $MathJax_eqnumbers, $MathJax_remembered_label, $MathJax_label
   */
  static function RegisterEq($matches)
  {
    $label = $matches[2];
    if(array_key_exists($label, self::$eqnumbers) or 
      (self::$remembered_label and array_key_exists(self::$remembered_label, self::$eqnumbers))) 
      return '\\tag{' . $label . ':label exists!}';
    self::$eqnumbers[$label] = $matches[1] == 'label' ? self::MakeEqNumberMarker(self::$eqnumber++) : $label;
    self::$eqnumbers_rev[self::$eqnumbers[$label]] = $label; // for renumbering later
    self::$label = self::$eqnumbers[$label];
    if(self::$remembered_label) self::$eqnumbers[self::$remembered_label] = self::$eqnumbers[$label];
    return '\\tag{' . self::$eqnumbers[$label] . '}';
  }

  // renumber \tag's
  static function Renumber($matches)
  {
    $label = self::$eqnumbers_rev[$matches[1]];
    self::$eqnumbers[$label] = self::$eqnumber;
    return '\\tag{' . self::$eqnumber++ . '}';
  }

  // also renumber id='s
  static function Renumber2($matches)
  {
    $label = self::$eqnumbers_rev[$matches[0]];
    return self::$eqnumbers[$label];
  }

  /**
   * ReplaceEqrefs($matches)
   *
   * This is a regex callback used from MathJax_parser_stage2 to replace all 
   * \eqref{} references with the correct formula reference.
   *
   * @param matches   Match array where
   *                    $matches[2]   is the argument of \eqref{}
   *
   * @return A clickable reference.
   *
   * @globals   $MathJax_eqnumbers
   */
  static function ReplaceEqrefs($matches)
  {
    $label = $matches[2]; # the possible "eq" for "eqref" is in $matches[1]...
    if(array_key_exists($label, self::$eqnumbers)) 
      return '(<a href="#Eq-' . self::$eqnumbers[$label] . '">' . self::$eqnumbers[$label] . '</a>)';
    return "<span class='tex2jax_ignore' style='color: red;'>" . $matches[0] . "</span>";
  }

}

