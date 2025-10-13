<?php
/**
 * MarkdownParser - Simple Markdown to HTML converter
 *
 * Converts basic Markdown syntax to HTML for documentation rendering.
 * Can be used across the application for any markdown content.
 *
 * @package TikNix
 */

namespace app;

class MarkdownParser {

    /**
     * Convert header text to URL-friendly slug for ID attribute
     *
     * @param string $text Header text
     * @return string URL-friendly slug
     */
    private static function slugify($text) {
        // Remove HTML tags
        $text = strip_tags($text);
        // Convert to lowercase
        $text = strtolower($text);
        // Replace spaces and special characters with hyphens
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        // Remove leading/trailing hyphens
        $text = trim($text, '-');
        return $text;
    }

    /**
     * Parse markdown text and convert to HTML
     *
     * @param string $text Markdown text to parse
     * @return string HTML output
     */
    public static function parse($text) {
        // IMPORTANT: Process code blocks FIRST to protect code from other transformations

        // Store code blocks temporarily to prevent them from being processed
        $codeBlocks = [];
        $blockCounter = 0;

        // Convert code blocks first (triple backticks)
        $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($matches) use (&$codeBlocks, &$blockCounter) {
            $language = $matches[1] ?: 'plaintext';
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            $placeholder = "###CODEBLOCK{$blockCounter}###";
            $codeBlocks[$placeholder] = '<pre><code class="language-' . $language . '">' . $code . '</code></pre>';
            $blockCounter++;
            return $placeholder;
        }, $text);

        // Store inline code temporarily
        $inlineCode = [];
        $inlineCounter = 0;

        // Convert inline code (single backticks) - must be before other inline formatting
        $text = preg_replace_callback('/`([^`\n]+)`/', function($matches) use (&$inlineCode, &$inlineCounter) {
            $code = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $placeholder = "###INLINECODE{$inlineCounter}###";
            $inlineCode[$placeholder] = '<code>' . $code . '</code>';
            $inlineCounter++;
            return $placeholder;
        }, $text);

        // Convert headers with ID attributes for anchor links
        $text = preg_replace_callback('/^#### (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h4 id="' . $id . '">' . $matches[1] . '</h4>';
        }, $text);
        $text = preg_replace_callback('/^### (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h3 id="' . $id . '">' . $matches[1] . '</h3>';
        }, $text);
        $text = preg_replace_callback('/^## (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h2 id="' . $id . '">' . $matches[1] . '</h2>';
        }, $text);
        $text = preg_replace_callback('/^# (.*?)$/m', function($matches) {
            $id = self::slugify($matches[1]);
            return '<h1 id="' . $id . '">' . $matches[1] . '</h1>';
        }, $text);

        // Convert bold and italic (order matters!)
        $text = preg_replace('/\*\*\*(.*?)\*\*\*/s', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)([^*\n]+)\*(?!\*)/s', '<em>$1</em>', $text);

        // Convert links
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);

        // Convert blockquotes
        $text = preg_replace('/^> (.*)$/m', '<blockquote>$1</blockquote>', $text);

        // Convert horizontal rules
        $text = preg_replace('/^---+$/m', '<hr>', $text);

        // Convert lists (handle nested lists better)
        $lines = explode("\n", $text);
        $inList = false;
        $listHtml = '';
        $newLines = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\s*)[-*+] (.*)$/', $line, $matches)) {
                if (!$inList) {
                    $inList = true;
                    $listHtml = '<ul>';
                }
                $indent = strlen($matches[1]);
                $content = $matches[2];
                $listHtml .= '<li>' . $content . '</li>';
            } else {
                if ($inList) {
                    $newLines[] = $listHtml . '</ul>';
                    $inList = false;
                    $listHtml = '';
                }
                $newLines[] = $line;
            }
        }
        if ($inList) {
            $newLines[] = $listHtml . '</ul>';
        }
        $text = implode("\n", $newLines);

        // Convert tables (simple table support)
        $text = preg_replace_callback('/^\|(.+)\|$/m', function($matches) {
            $cells = explode('|', trim($matches[1], '|'));
            $cellHtml = '';
            foreach ($cells as $cell) {
                $cellHtml .= '<td>' . trim($cell) . '</td>';
            }
            return '<tr>' . $cellHtml . '</tr>';
        }, $text);

        // Wrap table rows in table tags
        $text = preg_replace('/(<tr>.*?<\/tr>\s*)+/s', '<table class="table table-bordered">$0</table>', $text);

        // Restore inline code
        foreach ($inlineCode as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }

        // Restore code blocks
        foreach ($codeBlocks as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }

        // Convert line breaks to paragraphs (but be smarter about it)
        $paragraphs = explode("\n\n", $text);
        $html = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para) {
                // Don't wrap if it's already an HTML element
                if (!preg_match('/^<(h[1-6]|ul|ol|pre|div|table|blockquote|hr)/i', $para)) {
                    // Don't use nl2br on content that already has block elements
                    if (preg_match('/<(li|tr|td|th)/', $para)) {
                        $html .= $para;
                    } else {
                        $html .= '<p>' . nl2br($para) . '</p>';
                    }
                } else {
                    $html .= $para;
                }
            }
        }

        return $html;
    }
}
