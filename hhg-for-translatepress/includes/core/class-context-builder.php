<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HHG_Context_Builder {

    // -------------------------------------------------------------------------
    //  Sliding-window context (layer 3)
    // -------------------------------------------------------------------------

    /**
     * Extract surrounding context strings for a chunk.
     *
     * @param array $all_strings   Full batch being translated (preserved keys).
     * @param array $chunk_strings Current chunk (preserved keys).
     * @param int   $window        How many strings to include on each side.
     * @return array ['before' => string[], 'after' => string[]]
     */
    public static function get_context( array $all_strings, array $chunk_strings, int $window = 3 ): array {
        $all_keys   = array_keys( $all_strings );
        $chunk_keys = array_keys( $chunk_strings );

        if ( empty( $chunk_keys ) || empty( $all_keys ) ) {
            return [ 'before' => [], 'after' => [] ];
        }

        $first_key   = $chunk_keys[0];
        $chunk_start = array_search( $first_key, $all_keys, true );

        if ( $chunk_start === false ) {
            return [ 'before' => [], 'after' => [] ];
        }

        $chunk_end = $chunk_start + count( $chunk_strings ) - 1;
        $total     = count( $all_strings );

        $before = [];
        for ( $i = max( 0, $chunk_start - $window ); $i < $chunk_start; $i++ ) {
            $key      = $all_keys[ $i ];
            $before[] = $all_strings[ $key ];
        }

        $after = [];
        for ( $i = $chunk_end + 1; $i <= min( $total - 1, $chunk_end + $window ); $i++ ) {
            $key     = $all_keys[ $i ];
            $after[] = $all_strings[ $key ];
        }

        return [ 'before' => $before, 'after' => $after ];
    }

    // -------------------------------------------------------------------------
    //  Page-level context (layer 2)
    // -------------------------------------------------------------------------

    /**
     * Extract page-level context from the full string batch.
     *
     * Returns an array with 'intro' (first few strings) and 'key_terms'
     * (high-frequency domain-significant terms) to help the AI understand
     * what the page is about and maintain terminology consistency.
     *
     * @param array $all_strings Full batch of strings on the page.
     * @param int   $intro_count How many leading strings to use as intro.
     * @return array ['intro' => string[], 'key_terms' => string[]]
     */
    public static function extract_page_context( array $all_strings, int $intro_count = 3 ): array {
        $values = array_values( $all_strings );
        $intro  = array_slice( $values, 0, $intro_count );

        $key_terms = self::extract_key_terms( $all_strings );

        return [
            'intro'     => $intro,
            'key_terms' => $key_terms,
        ];
    }

    /**
     * Extract frequently-repeated significant terms from the page content.
     *
     * Uses character-level n-gram frequency analysis that works across
     * languages without requiring a word-segmentation library.
     *
     * @param array $strings All strings on the page.
     * @return string[] Top key terms (max 10).
     */
    private static function extract_key_terms( array $strings ): array {
        $all_text = implode( ' ', $strings );

        // Normalize whitespace
        $all_text = preg_replace( '/\s+/', ' ', $all_text );

        // Extract candidate terms: 2-8 character sequences that are likely words
        // Works for both CJK (2-4 chars = word) and Latin scripts (3-8 chars = word)
        $candidates = [];
        $len        = mb_strlen( $all_text );

        for ( $i = 0; $i < $len; $i++ ) {
            for ( $l = 2; $l <= 8; $l++ ) {
                if ( $i + $l > $len ) {
                    break;
                }
                $term = mb_substr( $all_text, $i, $l );
                $term = trim( $term );
                if ( $term === '' ) {
                    continue;
                }
                // Skip terms that are purely punctuation / whitespace / numbers
                if ( preg_match( '/^[\d\s\p{P}]+$/u', $term ) ) {
                    continue;
                }
                // Skip HTML fragments
                if ( preg_match( '/[<>]/', $term ) ) {
                    continue;
                }
                if ( ! isset( $candidates[ $term ] ) ) {
                    $candidates[ $term ] = 0;
                }
                $candidates[ $term ]++;
            }
        }

        // Filter: appear 3+ times, meaningful length
        $filtered = [];
        foreach ( $candidates as $term => $count ) {
            if ( $count < 3 ) {
                continue;
            }
            $term_len = mb_strlen( $term );
            if ( $term_len < 2 ) {
                continue;
            }
            $filtered[ $term ] = $count;
        }

        // Deduplicate: if a longer term contains a shorter term, prefer the longer
        arsort( $filtered );
        $final = [];
        foreach ( $filtered as $term => $count ) {
            $is_sub = false;
            foreach ( $final as $existing => $_ ) {
                if ( mb_strpos( $existing, $term ) !== false && $existing !== $term ) {
                    $is_sub = true;
                    break;
                }
            }
            if ( ! $is_sub ) {
                $final[ $term ] = $count;
            }
            if ( count( $final ) >= 10 ) {
                break;
            }
        }

        return array_keys( $final );
    }

    // -------------------------------------------------------------------------
    //  Master prompt builder (layers 1 + 2 + 3)
    // -------------------------------------------------------------------------

    /**
     * Build a context-aware translation prompt with all three context layers.
     *
     * Layer 1 — Industry / domain instruction from user settings.
     * Layer 2 — Page-level overview (intro text + key terms).
     * Layer 3 — Sliding-window surrounding strings.
     *
     * @param string   $source_lang    e.g. "Chinese"
     * @param string   $target_lang    e.g. "English"
     * @param string[] $target_strings Strings to translate (indexed array).
     * @param string[] $context_before Surrounding strings before target.
     * @param string[] $context_after  Surrounding strings after target.
     * @param string   $industry_prompt User-defined industry/domain description.
     * @param array    $page_context   ['intro' => string[], 'key_terms' => string[]]
     * @return string
     */
    public static function build_prompt(
        string $source_lang,
        string $target_lang,
        array  $target_strings,
        array  $context_before     = [],
        array  $context_after      = [],
        string $industry_prompt    = '',
        array  $page_context       = []
    ): string {
        $prompt = '';

        // ---- Layer 1: Industry / domain instruction ----
        if ( ! empty( $industry_prompt ) ) {
            $prompt .= "[INDUSTRY CONTEXT]\n";
            $prompt .= "You are translating content for: " . $industry_prompt . "\n";
            $prompt .= "Apply this domain knowledge: use accurate industry terminology, ";
            $prompt .= "maintain the appropriate tone for this audience, and keep ";
            $prompt .= "brand/product names untranslated unless specified otherwise.\n\n";
        }

        // ---- Layer 2: Page-level context ----
        $intro     = $page_context['intro'] ?? [];
        $key_terms = $page_context['key_terms'] ?? [];

        if ( ! empty( $intro ) || ! empty( $key_terms ) ) {
            $prompt .= "[PAGE CONTEXT]\n";

            if ( ! empty( $intro ) ) {
                $prompt .= "This page is about: ";
                $first = true;
                foreach ( $intro as $s ) {
                    // Keep intro snippets short
                    $snippet = mb_strlen( $s ) > 80 ? mb_substr( $s, 0, 80 ) . '…' : $s;
                    if ( ! $first ) {
                        $prompt .= ' | ';
                    }
                    $prompt .= '"' . $snippet . '"';
                    $first = false;
                }
                $prompt .= "\n";
            }

            if ( ! empty( $key_terms ) ) {
                $prompt .= "Key recurring terms on this page: ";
                $prompt .= implode( ', ', $key_terms ) . "\n";
                $prompt .= "Translate these terms consistently throughout.\n";
            }

            $prompt .= "\n";
        }

        // ---- Core translation rules ----
        $prompt .= "Act as a translation assistant, translating {$source_lang} to {$target_lang}. Must comply:\n";
        $prompt .= "100% retain all original HTML format.\n";
        $prompt .= "Do not translate URL links, only translate text.\n";
        $prompt .= "Return translated text, one per line.\n";
        $prompt .= "100% Keep the same structure as the original.\n";
        $prompt .= "You only need to translate the text, don't prompt.\n";

        // ---- Layer 3: Sliding-window context ----
        if ( ! empty( $context_before ) ) {
            $prompt .= "\n[Reference context — already translated, for consistency only:]\n";
            $n = 1;
            foreach ( $context_before as $s ) {
                $prompt .= $n . ". " . $s . "\n";
                $n++;
            }
        }

        $prompt .= "\nThe text to be translated is as follows:\n";
        $start_num = empty( $context_before ) ? 1 : count( $context_before ) + 1;
        $counter   = $start_num;
        foreach ( $target_strings as $string ) {
            $prompt .= $counter . ". " . $string . "\n";
            $counter++;
        }

        if ( ! empty( $context_after ) ) {
            $prompt .= "\n[Reference context — for awareness, do NOT translate:]\n";
            foreach ( $context_after as $s ) {
                $prompt .= $counter . ". " . $s . "\n";
                $counter++;
            }
        }

        return $prompt;
    }

    // -------------------------------------------------------------------------
    //  Response parser
    // -------------------------------------------------------------------------

    /**
     * Robust multi-strategy response parser.
     *
     * Tries several strategies in order to extract translations from the
     * AI response, falling back gracefully when the format is unexpected.
     *
     * @param string   $response_text    Raw text returned by the AI.
     * @param string[] $original_strings Original strings that were sent for translation.
     * @return string[] Translated strings (same count as originals).
     */
    public static function parse_response( string $response_text, array $original_strings ): array {
        $text   = trim( $response_text );
        $count  = count( $original_strings );
        $result = [];

        // Strategy 1 — numbered lines: "1. text" / "1) text" / "1、text"
        $extracted = self::extract_numbered_lines( $text );

        if ( count( $extracted ) >= $count ) {
            for ( $i = 0; $i < $count; $i++ ) {
                $result[] = self::clean_line( $extracted[ $i ], $original_strings[ $i ] );
            }
            return $result;
        }

        // Strategy 2 — split by blank lines, take non-empty blocks
        $blocks = preg_split( '/\n\s*\n/', $text );
        $blocks = array_values( array_filter( array_map( 'trim', $blocks ) ) );
        $lines  = [];
        foreach ( $blocks as $block ) {
            $sub = explode( "\n", $block );
            foreach ( $sub as $s ) {
                $s = trim( $s );
                if ( $s !== '' ) {
                    $lines[] = $s;
                }
            }
        }

        if ( count( $lines ) >= $count ) {
            for ( $i = 0; $i < $count; $i++ ) {
                $result[] = self::clean_line( $lines[ $i ], $original_strings[ $i ] );
            }
            return $result;
        }

        // Strategy 3 — per-line extraction with noise filtering
        $raw_lines = explode( "\n", $text );
        $filtered  = [];
        foreach ( $raw_lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( preg_match( '/^(Here|Sure|Certainly|Translation|Output|The|Below|Above|Note|Please|I|This|These|Let|Based|Following|OK|Okay)/i', $line ) ) {
                continue;
            }
            if ( preg_match( '/^\d+[\.\)、]?\s*$/', $line ) ) {
                continue;
            }
            $stripped   = preg_replace( '/^\d+[\.\)、]\s*/', '', $line );
            $filtered[] = $stripped;
        }

        if ( count( $filtered ) >= $count ) {
            for ( $i = 0; $i < $count; $i++ ) {
                $result[] = self::clean_line( $filtered[ $i ], $original_strings[ $i ] );
            }
            return $result;
        }

        // Strategy 4 — partial match: use what we have, fill rest with originals
        $filtered_count = count( $filtered );
        for ( $i = 0; $i < $count; $i++ ) {
            if ( $i < $filtered_count ) {
                $result[] = self::clean_line( $filtered[ $i ], $original_strings[ $i ] );
            } else {
                $result[] = $original_strings[ $i ];
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    //  Speed optimization utilities
    // -------------------------------------------------------------------------

    /**
     * Filter out strings that don't need AI translation.
     *
     * Strings that are empty, purely numeric, or purely symbolic (prices,
     * phone numbers, percentages) are returned in $skip — these should be
     * mapped directly to themselves without hitting the API.
     *
     * @param string[] $strings Strings to translate (preserved keys).
     * @return array ['skip' => string[], 'translate' => string[]]
     */
    public static function filter_untranslatable( array $strings ): array {
        $skip      = [];
        $translate = [];

        foreach ( $strings as $k => $v ) {
            $trimmed = trim( $v );

            // Empty
            if ( $trimmed === '' ) {
                $skip[ $k ] = $v;
                continue;
            }

            // Pure numeric (integers, decimals)
            if ( is_numeric( $trimmed ) ) {
                $skip[ $k ] = $v;
                continue;
            }

            // Pure symbolic: digits, spaces, dots, commas, +, -, %, currency symbols
            if ( preg_match( '/^[\d\s\.\,\+\-\%\$€£¥\xA2-\xA5]+$/u', $trimmed ) ) {
                $skip[ $k ] = $v;
                continue;
            }

            $translate[ $k ] = $v;
        }

        return [ 'skip' => $skip, 'translate' => $translate ];
    }

    /**
     * Build chunks based on total character count rather than string count.
     *
     * Short strings (buttons, labels, menu items) are automatically merged
     * into larger chunks, while long paragraphs self-limit to fewer items.
     * This reduces API calls for short-string-heavy pages by 20-40% without
     * increasing per-request payload beyond the configured threshold.
     *
     * @param string[] $strings  Strings to chunk (preserved keys).
     * @param int      $max_chars Maximum characters per chunk (default 4000).
     * @return array[] Indexed array of chunks, each with preserved keys.
     */
    public static function build_char_chunks( array $strings, int $max_chars = 4000 ): array {
        $chunks     = [];
        $current    = [];
        $char_count = 0;

        foreach ( $strings as $k => $v ) {
            $len = mb_strlen( $v );

            if ( $char_count + $len > $max_chars && ! empty( $current ) ) {
                $chunks[]   = $current;
                $current    = [];
                $char_count = 0;
            }

            $current[ $k ] = $v;
            $char_count   += $len;
        }

        if ( ! empty( $current ) ) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Build a minimal retry prompt for missing strings after a partial response.
     *
     * Unlike the full build_prompt(), this sends only the missed lines with
     * a terse instruction — no industry context, page context, or sliding
     * window. This keeps the retry lightweight and fast (< 500 ms).
     *
     * @param string   $source_lang Source language name.
     * @param string   $target_lang Target language name.
     * @param string[] $missing     Strings that were not translated.
     * @return string
     */
    public static function build_retry_prompt(
        string $source_lang,
        string $target_lang,
        array  $missing
    ): string {
        $lines   = [];
        $counter = 1;

        foreach ( $missing as $s ) {
            $lines[] = $counter . '. ' . $s;
            $counter++;
        }

        return "Translate {$source_lang} → {$target_lang}. "
             . "Keep HTML, one per line, no extra text.\n\n"
             . implode( "\n", $lines );
    }

    // -------------------------------------------------------------------------
    //  Internal helpers
    // -------------------------------------------------------------------------

    private static function extract_numbered_lines( string $text ): array {
        $lines = explode( "\n", $text );
        $out   = [];

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) {
                continue;
            }
            if ( preg_match( '/^\d+[\.\)、\]](?:\s*\.?\s*)?(.+)$/', $line, $m ) ) {
                $content = trim( $m[1] );
                if ( $content !== '' ) {
                    $out[] = $content;
                }
            }
        }

        return $out;
    }

    private static function clean_line( string $translated, string $original ): string {
        $cleaned = preg_replace( '/^\d+[\.\)、\]](?:\s*\.?\s*)?/', '', trim( $translated ) );
        $cleaned = trim( $cleaned );

        if ( $cleaned === '' || strlen( $cleaned ) < 1 ) {
            return $original;
        }

        if ( preg_match( '/^\d+\.?$/', $cleaned ) ) {
            return $original;
        }

        return $cleaned;
    }
}
