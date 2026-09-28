<?php

namespace App\Services\Web\Providers;

use DOMDocument;
use DOMNode;
use DOMXPath;

class HtmlCleaner
{
    /**
     * Strip scripts, styles, navigation and other boilerplate,
     * returning collapsed readable text.
     */
    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
            $xpath = new DOMXPath($document);

            $removable = '//script | //style | //noscript | //nav | //header | //footer | //aside';
            $removable .= ' | //form | //iframe | //svg | //canvas | //button | //select | //textarea | //input';

            foreach ($xpath->query($removable) as $node) {
                if ($node instanceof DOMNode && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }

            $hidden = '//*[@hidden] | //*[@role="navigation"] | //*[@role="banner"] | //*[@role="contentinfo"]';

            foreach ($xpath->query($hidden) as $node) {
                if ($node instanceof DOMNode && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }

            $root = $document;
            $candidates = $xpath->query('//article | //main | //*[@role="main"]');

            if ($candidates !== false && $candidates->length > 0 && $candidates->item(0) instanceof DOMNode) {
                $root = $candidates->item(0);
            } else {
                $bodies = $document->getElementsByTagName('body');
                if ($bodies->length > 0) {
                    $root = $bodies->item(0);
                }
            }

            $text = $root !== null ? $root->textContent : '';

            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
            $text = (string) preg_replace('/\s*\n\s*/u', "\n", $text);
            $text = (string) preg_replace("/\n{3,}/u", "\n\n", $text);

            return trim($text);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
