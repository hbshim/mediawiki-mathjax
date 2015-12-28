<?php
if(!defined('MEDIAWIKI'))
die('This is a mediawiki extensions and can\'t be run from the command line.');

$wgHooks['ParserFirstCallInit'][] = 'MathJax_Parser::RunMathJax'; // register <source>(!) tags and register other hooks

class MathJax_Parser {
     
    static $Markers; ///< Variables for stripping formula's in stage 2
    static $mark_n = 0; ///< Variables for numbering and resolving references at the end of stage 2 
    static $tempScript;
    static $NoMathJaxMarkers;

    static function RunMathJax(Parser $parser)
    {
        global $wgHooks;
        $wgHooks['ParserBeforeStrip'][] = 'MathJax_Parser::RemoveMathTags';
        $wgHooks['InternalParseBeforeLinks'][] = 'MathJax_Parser::ReplaceByMarkers';
        $wgHooks['ParserAfterTidy'][] = 'MathJax_Parser::RemoveMarkers';
        $wgHooks['BeforePageDisplay'][] = 'MathJax_Parser::Inject_JS'; 
        return true;
    }

    static function RemoveMathTags(&$parser, &$text) 
    {
        $text = preg_replace('|:\s*<math>(.*?)</math>|s', '\\[$1\\]', $text);
        $text = preg_replace('|<math>(.*?)</math>|s', '\\($1\\)', $text);
        return true;
    }

    static function Inject_JS($out)
    {
        global $wgMathJaxJS;
        global $wgMathJaxProcConf;
        global $wgMathJaxLocConf;

        if(self::$mark_n == 0) return true; // there was no math detected

        $tempScript = "<script type='text/javascript' src='" . $wgMathJaxJS . "?config=" . $wgMathJaxProcConf;
        if (!is_null($wgMathJaxLocConf)) $tempScript = $tempScript . "," . $wgMathJaxLocConf;
        $tempScript = $tempScript . "'> </script>";
        $out->addScript( $tempScript );

        return true;
    }



    static function ReplaceByMarkers(Parser &$parser, &$text ) 
    {
#        $text = preg_replace_callback('|<nomathjax>(.*?)</nomathjax>|s', 'MathJax_Parser::NoMathJax', $text);

        $text = preg_replace_callback('/(\$\$)(.*?)(\$\$)/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\$)(.*?)(\$)/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\\\\[)(.*?)(\\\\\])/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\\\\()(.*?)(\\\\\))/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\\begin{(?:.*?)})(.*?)(\\\end{(?:.*?)})/s', 'MathJax_Parser::Marker', $text);

#        $text = preg_replace_callback('/' . Parser::MARKER_PREFIX . 'NoMathJax(?:.*?)' . Parser::MARKER_SUFFIX . '/s', 'MathJax_Parser::Test', $text);

        return true;
    }

    static function NoMathJax($matches)
    {
        $marker = Parser::MARKER_PREFIX . 'NoMathJax' . ++self::$mark_n . Parser::MARKER_SUFFIX;
        self::$NoMathJaxMarkers[$marker] = '<span class="tex2jax_ignore">' . $matches[1] . '</span>';

        return $marker;
    }

    static function RemoveMarkers( Parser &$parser, &$text )
    {
        $text = preg_replace_callback('/' . Parser::MARKER_PREFIX . 'MathJax(?:.*?)' . Parser::MARKER_SUFFIX . '/s', 'MathJax_Parser::Test', $text);

        return true;
    }


    static function Test($matches)
    {
        return self::$Markers[$matches[0]];
    }


    static function Marker($matches)
    {
        $eq = $matches[2];
        $opening_delim = "$matches[1]";
        $closing_delim = "$matches[3]";

        $eq = "$opening_delim" . "$eq" . "$closing_delim";
        $marker = Parser::MARKER_PREFIX . 'MathJax' . ++self::$mark_n . Parser::MARKER_SUFFIX;

        self::$Markers[$marker] = $eq;

        return $marker;
    }
}

