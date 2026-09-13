<?php

namespace App\Services;

class RichTextSanitizer
{
    public function sanitize(mixed $value): ?string
    {
        $html = trim((string) $value);

        if ($html === '') {
            return null;
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,s,ul,ol,li,blockquote,pre,code,a[href|title],img[src|alt|title|width|height],span,div,h1,h2,h3,h4,h5,h6,table,thead,tbody,tr,th,td');
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
            'tel' => true,
        ]);
        $html = (new \HTMLPurifier($config))->purify($html);

        $hasText = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '';
        $hasImage = preg_match('/<img\b/i', $html) === 1;

        return ($hasText || $hasImage) && $html !== '' ? $html : null;
    }
}
