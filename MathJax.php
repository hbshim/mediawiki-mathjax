<?php
if(!defined('MEDIAWIKI'))
die('This is a mediawiki extensions and can\'t be run from the command line.');

$wgHooks['ParserFirstCallInit'][] = 'MathJax_Parser::RunMathJax'; // register <source>(!) tags and register other hooks

class MathJax_Parser {
     
#static $MathJaxJS; ///< Location of MathJax Engine
#static $MathJaxConfig;   ///< Localtion of local MathJax config file
    static $Markers; ///< Variables for stripping formula's in stage 2
    static $mark_n = 0; ///< Variables for numbering and resolving references at the end of stage 2 

    static function RunMathJax(Parser $parser)
    {
        global $wgHooks;
        $wgHooks['ParserBeforeStrip'][] = 'MathJax_Parser::ReplaceByMarkers';
        $wgHooks['ParserAfterTidy'][] = 'MathJax_Parser::RemoveMarkers';
        $wgHooks['BeforePageDisplay'][] = 'MathJax_Parser::Inject_JS'; // Inject MathJax and MathJax configuration
        return true;
    }

    static function Inject_JS($out)
    {
        global $MathJaxJS;
        global $MathJaxConfig;
        if(self::$mark_n == 0) return true; // there was no math detected so don't include the MathJax Javascript
        $config = rtrim(file_get_contents($MathJaxConfig)) . "\n//<![CDATA[\n" . "MathJax.Hub.config.tex2jax.inlineMath.push(['$','$']);\n" . "MathJax.Hub.config.tex2jax.displayMath.push(['$$','$$']);\n" . "//]]>\n";
        $out->addScript("<script type='text/x-mathjax-config'>\n" . rtrim($config) . "\n</script>\n");
        $out->addScript("<script type='application/javascript' src='" . $MathJaxJS . "'></script>\n");
        return true;
    }

    static function ReplaceByMarkers(Parser &$parser, &$text ) 
    {
        self::$Markers = new ReplacementArray;

        // Change <math>...</math> and :<math>...</math> into \(...\) and \[...\]
        $text = preg_replace('|:\s*<math>(.*?)</math>|s', '\\[$1\\]', $text);
        $text = preg_replace('|<math>(.*?)</math>|s', '\\($1\\)', $text);

        $text = preg_replace_callback('/(\$\$)(.*?)(\\1)/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\$)(.*?)(\\1)/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\[)(.*?)(\\])/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\()(.*?)(\\))/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\\\begin{(?:.*?)})(.*?)(\\\\end{(?:.*?)})/s', 'MathJax_Parser::Marker', $text);

    }

    static function RemoveMarkers( Parser &$parser, &$text )
    {
        $text = self::$Markers->replace($text);

        return true;
    }

    static function Marker($matches)
    {
        // we could check if this is latex, could try to see if delimiters are valid... or if eq looks like an equation, currently not done
        $eq = $matches[2];
        $eq = str_replace('&', '&#38;', $eq);
        $eq = str_replace('<', '&#60;', $eq);
        $eq = str_replace('>', '&#62;', $eq);
        $opening_delim = $matches[1];
        $closing_delim = $matches[3];

        $eq = $opening_delim . $eq . $closing_delim;
        $marker = Parser::MARKER_PREFIX . "MathJax" . ++self::$mark_n . Parser::MARKER_SUFFIX;

        self::$Markers->setPair($marker, $eq);
        return $marker;
    }

}

