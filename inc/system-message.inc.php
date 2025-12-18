<?php

# DISCLAIMER MESSAGE

/**
 * Constructs the system message for chat completions.
 *
 * @param array $active_config The active deployment configuration.
 * @return array The system message array.
 */
function build_system_message($active_config) {
    // Initialize DateTime with the specified timezone
    $date = new DateTime();
    $timezone = new DateTimeZone('America/New_York');
    $date->setTimezone($timezone);

    $about = '/truncated/';#clean_disclaimer_text();
    $sentinel = defined('STREAM_STOP_SENTINEL') ? STREAM_STOP_SENTINEL : '<<END_OF_REPLY>>';

    // Construct the system message with correct role and defined $date
    $system_message = [
        [
            'role' => 'user',
            'content' => 'You are NHLBI Chat, a helpful assistant for staff at the National Heart Lung and Blood Institute. In this timezone, ' . $date->getTimezone()->getName() . ', the current date and time of this prompt is ' . $date->format('Y-m-d H:i:s') . '. The user\'s browser has the preferred language (HTTP_ACCEPT_LANGUAGE) set to ' . $_SERVER['HTTP_ACCEPT_LANGUAGE'] . ', so please reply in that language if possible, unless directed otherwise. If you return code, be sure to use the tic-mark (```) notation so that it renders properly in the Chat interface. If the prompt includes document or RAG context with citation tags, include those tags verbatim next to the relevant statements and do not invent citations. Avoid ending with elipsis notation, but end with a clear conclusion (a period, exclamation point or question mark, as appropriate.) After your final sentence, output the token ' . $sentinel . ' on its own line to indicate the response is complete. The following is the disclaimer / instruction text we present to users: <<<' . $about . '>>>. '
        ]
    ];

    return $system_message; 
}


function clean_disclaimer_text() {
    global $config;
    require_once 'staticpages/disclaimer_text.php';

    // Step 1: Replace structural tags with line breaks
    $html = preg_replace('/<\/?(p|ul|ol)>/i', "\n\n", $maintext);   // Paragraphs/lists → double break
    $html = preg_replace('/<li>/i', "- ", $html);               // Bullet for list items
    //$html = preg_replace('/<\/li>/i', "\n", $html);             // Line break after list item
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);         // Single break

    // Step 2: Strip remaining tags and decode entities
    $html = strip_tags($html);
    $html = html_entity_decode($html);

    // Step 3: Normalize whitespace (preserve double line breaks)
    $html = preg_replace('/[ \t]+/', ' ', $html);               // Collapse spaces/tabs
    $html = preg_replace('/[ \t]*\n[ \t]*/', "\n", $html);       // Clean line edges
    $html = preg_replace('/\n{3,}/', "\n\n", $html);             // Collapse 3+ breaks → 2

    $text = preg_replace('/\byou\b/i', 'NHLBI staff', $html);
    $text = preg_replace('/\byour\b/i', 'the', $text);

    // Step 4: Final trim
    return trim($text);
}
