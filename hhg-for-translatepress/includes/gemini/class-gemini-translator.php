<?php
/**
 * Google Gemini Translation Engine
 * API: https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *
 * Optimisations (v1.0.5):
 *  1. Character-based chunking — merges short strings, reduces API calls 20-40%
 *  2. Untranslatable filter — skips empty/numeric/symbolic strings
 *  3. Per-chunk instant retry — fixes missing items in ~500ms without waiting for global Round 2
 *  4. Tiered chunk caps — Flash-Lite 6000→Pro 3500 chars based on model capability
 *  5. cURL connection warmup — pre-establishes TCP+TLS before dispatching
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_Gemini_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $config;

    public function __construct( $settings ) {
        parent::__construct( $settings );

        $selected_model = $this->get_selected_model();
        $this->api_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $selected_model . ':generateContent';
        $this->config = $this->get_optimized_config( $selected_model );
    }

    private function get_optimized_config( $model ) {
        $base_config = array(
            'max_chars'    => 3000,
            'timeout'      => 45,
            'temperature'  => 0.01,
            'top_p'        => 0.95,
        );

        if ( strpos( $model, 'flash-lite' ) !== false ) {
            // 2.5 Flash-Lite — ultra fast, cheapest
            $base_config['max_chars']   = 6000;
            $base_config['timeout']     = 25;
            $base_config['temperature'] = 0.005;
        } elseif ( strpos( $model, '3-flash' ) !== false || strpos( $model, '3.5-flash' ) !== false ) {
            // 3 Flash / 3.5 Flash — latest generation, fast + accurate
            $base_config['max_chars']   = 5000;
            $base_config['timeout']     = 35;
            $base_config['temperature'] = 0.005;
        } elseif ( strpos( $model, '2.5-flash' ) !== false ) {
            // 2.5 Flash — balanced
            $base_config['max_chars']   = 4500;
            $base_config['timeout']     = 30;
            $base_config['temperature'] = 0.005;
        } elseif ( strpos( $model, '2.5-pro' ) !== false ) {
            // 2.5 Pro — highest quality (slower, keep chunks moderate)
            $base_config['max_chars']   = 3500;
            $base_config['timeout']     = 55;
            $base_config['temperature'] = 0.005;
        } elseif ( strpos( $model, '2.0' ) !== false ) {
            // 2.0 Flash (legacy)
            $base_config['max_chars']   = 2500;
            $base_config['timeout']     = 45;
            $base_config['temperature'] = 0.005;
        }

        return $base_config;
    }

    public function send_request( $source_language, $target_language, $strings_array, $context_before = array(), $context_after = array(), $industry_prompt = '', $page_context = array() ) {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', __( 'Gemini API key cannot be empty.', 'hhg-for-translatepress' ) );
        }

        $prompt = $this->build_translation_prompt( $source_language, $target_language, $strings_array, $context_before, $context_after, $industry_prompt, $page_context );

        $request_body = array(
            'systemInstruction' => array(
                'parts' => array(
                    array( 'text' => 'Translate ' . $source_language . ' → ' . $target_language . '. Keep HTML/URLs. One per line. No chat.' )
                )
            ),
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $prompt )
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => $this->config['temperature'],
                'maxOutputTokens' => $this->get_dynamic_max_tokens( $strings_array ),
                'topP' => $this->config['top_p'],
                'candidateCount' => 1,
            ),
            'safetySettings' => array(
                array( 'category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
                array( 'category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH' ),
                array( 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
                array( 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            )
        );

        $url = $this->api_endpoint . '?key=' . $api_key;

        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Referer' => $this->get_referer(),
                'User-Agent' => 'TranslatePress/1.0'
            ),
            'body' => wp_json_encode( $request_body ),
            'timeout' => $this->config['timeout'],
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => true
        ));

        return $response;
    }

    private function build_translation_prompt( $source_language, $target_language, $strings_array, $context_before = array(), $context_after = array(), $industry_prompt = '', $page_context = array() ) {
        return HHG_Context_Builder::build_prompt( $source_language, $target_language, array_values( $strings_array ), $context_before, $context_after, $industry_prompt, $page_context );
    }

    private function parse_translation_response( $response_text, $original_strings ) {
        return HHG_Context_Builder::parse_response( $response_text, $original_strings );
    }

    // ────────────────────────────────────────────────────────────
    //  OPTIMISED translate_array (v1.0.5)
    // ────────────────────────────────────────────────────────────

    public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
        if ( $source_language_code == null ) {
            $source_language_code = $this->settings['default-language'];
        }

        if ( empty( $new_strings ) || ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
            return array();
        }

        $source_language     = $this->machine_translation_codes[ $source_language_code ];
        $target_language     = $this->machine_translation_codes[ $target_language_code ];
        $translated_strings  = array();
        $selected_model      = $this->get_selected_model();
        $api_key             = $this->get_api_key();
        $api_url             = 'https://generativelanguage.googleapis.com/v1beta/models/' . $selected_model . ':generateContent?key=' . $api_key;
        $window_size         = apply_filters( 'hhgfotr_context_window_size', 3 );

        $industry_prompt = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-industry-prompt'] )
            ? $this->settings['trp_machine_translation_settings']['hhgfotr-industry-prompt'] : '';

        // ── Opt 2: Filter untranslatable strings ──
        $filtered     = HHG_Context_Builder::filter_untranslatable( $new_strings );
        $skip         = $filtered['skip'];
        $to_translate = $filtered['translate'];

        foreach ( $skip as $k => $v ) {
            $translated_strings[ $k ] = $v;
        }

        if ( empty( $to_translate ) ) {
            return $translated_strings;
        }

        $page_context = HHG_Context_Builder::extract_page_context( $to_translate );

        // ── Opt 1: Character-based chunking ──
        $chunks = HHG_Context_Builder::build_char_chunks( $to_translate, $this->config['max_chars'] );

        $api_client = class_exists( 'HHG_API_Client' ) ? HHG_API_Client::get_instance() : null;

        // ── Opt 5: Connection warmup ──
        if ( $api_client ) {
            $api_client->warmup( $api_url, array( 'Content-Type' => 'application/json' ) );
        }

        // ── Round 1: build requests + cache check ──
        $requests          = array();
        $chunks_index_map  = array();
        $cached_chunks     = array(); // track which chunks are cached for logging

        foreach ( $chunks as $idx => $chunk ) {
            $chunk_values_list = array_values( $chunk );
            $cache_key = 'gemini_' . md5( implode( "\n", $chunk_values_list ) . '|' . $target_language_code . '|' . $selected_model );
            $cached = class_exists( 'HHG_Cache' ) ? HHG_Cache::get( $cache_key ) : false;

            if ( $cached && isset( $cached['text'] ) ) {
                $cached_translations = $this->parse_translation_response( $cached['text'], $chunk_values_list );
                $i = 0;
                foreach ( $chunk as $key => $old_string ) {
                    $translated_strings[ $key ] = ( isset( $cached_translations[ $i ] ) && ! empty( $cached_translations[ $i ] ) )
                        ? $cached_translations[ $i ] : $old_string;
                    $i++;
                }
                if ( isset( $this->machine_translator_logger ) ) {
                    $this->machine_translator_logger->count_towards_quota( $chunk );
                }
                $cached_chunks[ $idx ] = true;
                continue;
            }

            $context = HHG_Context_Builder::get_context( $to_translate, $chunk, $window_size );
            $prompt  = $this->build_translation_prompt( $source_language, $target_language, $chunk, $context['before'], $context['after'], $industry_prompt, $page_context );
            $request_body = $this->build_request_body( $source_language, $target_language, $prompt, $chunk );

            $requests[] = array(
                'url'     => $api_url,
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                    'Referer'      => $this->get_referer(),
                    'User-Agent'   => 'TranslatePress/1.0',
                ),
                'body'    => wp_json_encode( $request_body ),
                'timeout' => $this->config['timeout'],
            );
            $chunks_index_map[] = $idx;
        }

        if ( $api_client && ! empty( $requests ) ) {
            if ( class_exists( 'HHG_Logger' ) ) {
                HHG_Logger::log( 'Gemini Round 1', array(
                    'chunks'       => count( $requests ),
                    'cached'       => count( $cached_chunks ),
                    'target_lang'  => $target_language,
                ) );
            }

            $results = $api_client->request_async( $requests );

            $all_missing_keys        = array();
            $instant_retry_requests  = array();
            $instant_retry_map       = array();

            foreach ( $results as $ridx => $result ) {
                $chunk        = $chunks[ $chunks_index_map[ $ridx ] ];
                $chunk_values = array_values( $chunk );

                if ( is_array( $result ) && isset( $result['response_code'] ) && 200 === (int) $result['response_code'] ) {
                    $response_body = json_decode( $result['body'], true );
                    if ( isset( $response_body['candidates'][0]['content']['parts'][0]['text'] ) ) {
                        if ( isset( $this->machine_translator_logger ) ) {
                            $this->machine_translator_logger->count_towards_quota( $chunk );
                        }
                        $response_text      = $response_body['candidates'][0]['content']['parts'][0]['text'];
                        $chunk_translations = $this->parse_translation_response( $response_text, $chunk_values );

                        // Separate translated vs. missing within this chunk
                        $chunk_missing = array();
                        $i = 0;
                        foreach ( $chunk as $key => $old_string ) {
                            if ( isset( $chunk_translations[ $i ] ) && ! empty( $chunk_translations[ $i ] ) && $chunk_translations[ $i ] !== $old_string ) {
                                $translated_strings[ $key ] = $chunk_translations[ $i ];
                            } else {
                                $translated_strings[ $key ] = $old_string;
                                $chunk_missing[ $key ]      = $old_string;
                            }
                            $i++;
                        }

                        if ( ! empty( $chunk_missing ) ) {
                            // ── Opt 3: Per-chunk instant retry ──
                            $retry_prompt = HHG_Context_Builder::build_retry_prompt( $source_language, $target_language, $chunk_missing );
                            $retry_body   = $this->build_retry_body( $source_language, $target_language, $retry_prompt, $chunk_missing );

                            $instant_retry_requests[] = array(
                                'url'     => $api_url,
                                'method'  => 'POST',
                                'headers' => array(
                                    'Content-Type' => 'application/json',
                                    'Accept'       => 'application/json',
                                    'Referer'      => $this->get_referer(),
                                    'User-Agent'   => 'TranslatePress/1.0',
                                ),
                                'body'    => wp_json_encode( $retry_body ),
                                'timeout' => $this->config['timeout'],
                            );
                            $instant_retry_map[] = $chunk_missing;
                        } else {
                            // All items translated — cache full chunk
                            $cache_key = 'gemini_' . md5( implode( "\n", $chunk_values ) . '|' . $target_language_code . '|' . $selected_model );
                            if ( class_exists( 'HHG_Cache' ) ) {
                                HHG_Cache::set( $cache_key, array( 'text' => $response_text ), 1800 );
                            }
                        }
                        continue;
                    }
                }

                // Chunk failed entirely — collect for global Round 2
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'Gemini chunk error', array(
                        'code' => isset( $result['response_code'] ) ? $result['response_code'] : 'N/A',
                    ) );
                }
                foreach ( $chunk as $key => $old_string ) {
                    $translated_strings[ $key ] = $old_string;
                    $all_missing_keys[ $key ]   = $old_string;
                }
            }

            // ── Process instant retries ──
            if ( ! empty( $instant_retry_requests ) ) {
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'Gemini Instant Retry', array( 'chunks' => count( $instant_retry_requests ) ) );
                }
                $retry_results = $api_client->request_async( $instant_retry_requests );
                foreach ( $retry_results as $rridx => $retry_result ) {
                    $miss = $instant_retry_map[ $rridx ];
                    if ( is_array( $retry_result ) && isset( $retry_result['response_code'] ) && 200 === (int) $retry_result['response_code'] ) {
                        $retry_data = json_decode( $retry_result['body'], true );
                        if ( isset( $retry_data['candidates'][0]['content']['parts'][0]['text'] ) ) {
                            $reparsed = $this->parse_translation_response( $retry_data['candidates'][0]['content']['parts'][0]['text'], array_values( $miss ) );
                            $j = 0;
                            foreach ( $miss as $mkey => $morig ) {
                                if ( isset( $reparsed[ $j ] ) && ! empty( $reparsed[ $j ] ) ) {
                                    $translated_strings[ $mkey ] = $reparsed[ $j ];
                                }
                                $j++;
                            }
                        }
                    }
                    // If instant retry still failed, escalate to global Round 2
                    foreach ( $miss as $mkey => $morig ) {
                        if ( ! isset( $translated_strings[ $mkey ] ) || $translated_strings[ $mkey ] === $morig ) {
                            $all_missing_keys[ $mkey ] = $morig;
                        }
                    }
                }
            }

            // ── Round 2: shrunken global retry for any remaining missing ──
            if ( ! empty( $all_missing_keys ) ) {
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'Gemini Round 2', array( 'missing_count' => count( $all_missing_keys ) ) );
                }
                $retry_chunks       = HHG_Context_Builder::build_char_chunks( $all_missing_keys, min( 2000, (int) ( $this->config['max_chars'] / 2 ) ) );
                $retry_requests     = array();
                $retry_chunks_index = array();

                foreach ( $retry_chunks as $ridx => $retry_chunk ) {
                    $retry_prompt = HHG_Context_Builder::build_retry_prompt( $source_language, $target_language, $retry_chunk );
                    $retry_body   = $this->build_retry_body( $source_language, $target_language, $retry_prompt, $retry_chunk );

                    $retry_requests[] = array(
                        'url'     => $api_url,
                        'method'  => 'POST',
                        'headers' => array(
                            'Content-Type' => 'application/json',
                            'Accept'       => 'application/json',
                            'Referer'      => $this->get_referer(),
                            'User-Agent'   => 'TranslatePress/1.0',
                        ),
                        'body'    => wp_json_encode( $retry_body ),
                        'timeout' => $this->config['timeout'],
                    );
                    $retry_chunks_index[] = $ridx;
                }

                $retry_results = $api_client->request_async( $retry_requests );
                foreach ( $retry_results as $rridx => $retry_result ) {
                    $retry_chunk = $retry_chunks[ $retry_chunks_index[ $rridx ] ];
                    if ( is_array( $retry_result ) && isset( $retry_result['response_code'] ) && 200 === (int) $retry_result['response_code'] ) {
                        $retry_data = json_decode( $retry_result['body'], true );
                        if ( isset( $retry_data['candidates'][0]['content']['parts'][0]['text'] ) ) {
                            $reparsed = $this->parse_translation_response( $retry_data['candidates'][0]['content']['parts'][0]['text'], array_values( $retry_chunk ) );
                            $j = 0;
                            foreach ( $retry_chunk as $mkey => $morig ) {
                                if ( isset( $reparsed[ $j ] ) && ! empty( $reparsed[ $j ] ) ) {
                                    $translated_strings[ $mkey ] = $reparsed[ $j ];
                                }
                                $j++;
                            }
                        }
                    }
                }
            }
        }

        return $translated_strings;
    }

    /**
     * Build request body for Gemini generateContent API.
     */
    private function build_request_body( $source_language, $target_language, $prompt, $strings_array ) {
        return array(
            'systemInstruction' => array(
                'parts' => array(
                    array( 'text' => 'Translate ' . $source_language . ' → ' . $target_language . '. Keep HTML/URLs. One per line. No chat.' )
                )
            ),
            'contents' => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) )
            ),
            'generationConfig' => array(
                'temperature'     => $this->config['temperature'],
                'maxOutputTokens' => $this->get_dynamic_max_tokens( $strings_array ),
                'topP'            => $this->config['top_p'],
                'candidateCount'  => 1,
            ),
            'safetySettings' => array(
                array( 'category' => 'HARM_CATEGORY_HARASSMENT',       'threshold' => 'BLOCK_ONLY_HIGH' ),
                array( 'category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH' ),
                array( 'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
                array( 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ),
            ),
        );
    }

    /**
     * Build a lightweight retry body for missing strings.
     * Uses lower temperature + compact system instruction.
     */
    private function build_retry_body( $source_language, $target_language, $prompt, $strings_array ) {
        return array(
            'systemInstruction' => array(
                'parts' => array(
                    array( 'text' => $source_language . '→' . $target_language . '. One per line.' )
                )
            ),
            'contents' => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) )
            ),
            'generationConfig' => array(
                'temperature'     => min( $this->config['temperature'], 0.003 ),
                'maxOutputTokens' => $this->get_dynamic_max_tokens( $strings_array ),
                'topP'            => $this->config['top_p'],
                'candidateCount'  => 1,
            ),
        );
    }

    // ────────────────────────────────────────────────────────────
    //  Sync fallback (unchanged)
    // ────────────────────────────────────────────────────────────

    private function process_sync_response( $response, $chunk, &$translated_strings, $source_language, $target_language, $target_language_code, $selected_model ) {
        $this->machine_translator_logger->log(array(
            'strings'   => serialize( $chunk ),
            'response'  => serialize( $response ),
            'lang_source'  => $source_language,
            'lang_target'  => $target_language,
        ));

        $chunk_values = array_values( $chunk );

        if ( is_array( $response ) && ! is_wp_error( $response ) && isset( $response['response']['code'] ) && 200 === (int) $response['response']['code'] ) {
            $response_body = json_decode( $response['body'], true );
            if ( isset( $response_body['candidates'][0]['content']['parts'][0]['text'] ) ) {
                if ( isset( $this->machine_translator_logger ) ) {
                    $this->machine_translator_logger->count_towards_quota( $chunk );
                }
                $response_text = $response_body['candidates'][0]['content']['parts'][0]['text'];
                $chunk_translations = $this->parse_translation_response( $response_text, $chunk_values );

                $cache_key = 'gemini_' . md5( implode( "\n", $chunk_values ) . '|' . $target_language_code . '|' . $selected_model );
                if ( class_exists( 'HHG_Cache' ) ) {
                    HHG_Cache::set( $cache_key, array( 'text' => $response_text ), 1800 );
                }

                $i = 0;
                foreach ( $chunk as $key => $old_string ) {
                    $translated_strings[ $key ] = ( isset( $chunk_translations[ $i ] ) && ! empty( $chunk_translations[ $i ] ) )
                        ? $chunk_translations[ $i ] : $old_string;
                    $i++;
                }
                return;
            }
        }
        // Failed — keep original
        foreach ( $chunk as $key => $old_string ) {
            $translated_strings[ $key ] = $old_string;
        }
    }

    // ────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────

    public function test_request() {
        return $this->send_request( 'en', 'zh', array( 'Hello, world!' ) );
    }

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-gemini-key'] )
            ? $this->settings['trp_machine_translation_settings']['hhg-gemini-key']
            : false;
    }

    public function get_selected_model() {
        $selected_model = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-model'] )
            ? $this->settings['trp_machine_translation_settings']['hhgfotr-gemini-model']
            : ( isset( $this->settings['trp_machine_translation_settings']['hhg-gemini-model'] )
                ? $this->settings['trp_machine_translation_settings']['hhg-gemini-model']
                : 'gemini-2.5-flash' );
        $available_models = $this->get_available_models();
        if ( ! array_key_exists( $selected_model, $available_models ) ) {
            $selected_model = 'gemini-2.5-flash';
        }
        return $selected_model;
    }

    public function get_available_models() {
        return array(
            'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash-Lite (Fastest & Cheapest)',
            'gemini-2.5-flash'      => 'Gemini 2.5 Flash (Balanced)',
            'gemini-2.5-pro'        => 'Gemini 2.5 Pro (Highest Quality)',
            'gemini-3-flash'        => 'Gemini 3 Flash (Latest Generation)',
            'gemini-3.5-flash'      => 'Gemini 3.5 Flash (Newest — May 2026)',
        );
    }

    public function get_supported_languages() {
        $supported_languages = array(
            'en', 'zh', 'zh-CN', 'zh-TW', 'ja', 'ko', 'fr', 'de', 'es', 'it', 'pt', 'ru',
            'ar', 'hi', 'th', 'vi', 'id', 'ms', 'tl', 'tr', 'pl', 'nl', 'sv', 'da',
            'no', 'fi', 'hu', 'cs', 'sk', 'ro', 'bg', 'hr', 'sr', 'sl', 'et', 'lv',
            'lt', 'uk', 'be', 'ka', 'am', 'sw', 'zu', 'af', 'sq', 'eu', 'ca', 'gl',
            'is', 'ga', 'mt', 'cy', 'bn', 'gu', 'kn', 'ml', 'mr', 'ne', 'pa', 'si',
            'ta', 'te', 'ur', 'my', 'km', 'lo', 'hy', 'az', 'kk', 'ky', 'mn', 'uz',
            'tk', 'fa', 'ps', 'sd', 'yi', 'he', 'jv', 'su', 'ceb', 'haw', 'mg', 'sm'
        );
        $supported_languages = apply_filters( 'trp_add_hhgfotr_gemini_supported_languages_to_the_array', $supported_languages );
        $supported_languages = apply_filters( 'trp_add_hhg_gemini_supported_languages_to_the_array', $supported_languages );
        return $supported_languages;
    }

    public function get_engine_specific_language_codes( $languages ) {
        $gemini_language_codes = array();
        $iso_codes = $this->trp_languages->get_iso_codes( $languages );

        $gemini_language_mapping = array(
            'zh_HK' => 'zh-TW', 'zh_TW' => 'zh-TW', 'zh_CN' => 'zh-CN', 'zh_SG' => 'zh-CN',
            'en_US' => 'en', 'en_GB' => 'en', 'en_CA' => 'en', 'en_AU' => 'en',
            'pt_BR' => 'pt', 'pt_PT' => 'pt', 'es_ES' => 'es', 'es_MX' => 'es',
            'fr_FR' => 'fr', 'fr_CA' => 'fr', 'de_DE' => 'de', 'de_AT' => 'de',
            'nb_NO' => 'no', 'nn_NO' => 'no', 'de_DE_formal' => 'de'
        );

        foreach ( $languages as $language ) {
            if ( isset( $gemini_language_mapping[ $language ] ) ) {
                $gemini_language_codes[ $language ] = $gemini_language_mapping[ $language ];
            } else {
                $gemini_language_codes[ $language ] = isset( $iso_codes[ $language ] ) ? $iso_codes[ $language ] : $language;
            }
        }

        return $gemini_language_codes;
    }

    public function check_formality() {
        return array();
    }

    public function check_api_key_validity() {
        $machine_translator = $this;
        $translation_engine = $this->settings['trp_machine_translation_settings']['translation-engine'];
        $api_key = $machine_translator->get_api_key();

        $is_error = false;
        $return_message = '';

        if ( 'hhgfotr_gemini' === $translation_engine &&
             isset( $this->settings['trp_machine_translation_settings']['machine-translation'] ) &&
             'yes' === $this->settings['trp_machine_translation_settings']['machine-translation'] ) {

            if ( isset( $this->correct_api_key ) && $this->correct_api_key != null ) {
                return $this->correct_api_key;
            }

            if ( empty( $api_key ) ) {
                $is_error = true;
                $return_message = __( 'Please enter your Google Gemini API key.', 'hhg-for-translatepress' );
            } else {
                $response = $machine_translator->test_request();
                if ( is_wp_error( $response ) ) {
                    $is_error = true;
                    $return_message = $response->get_error_message();
                } else {
                    $code = wp_remote_retrieve_response_code( $response );
                    if ( 200 !== $code ) {
                        $body = wp_remote_retrieve_body( $response );
                        $decoded = json_decode( $body, true );
                        $msg = '';
                        if ( isset( $decoded['error']['message'] ) ) {
                            $msg = $decoded['error']['message'];
                        } elseif ( ! empty( $body ) ) {
                            $msg = $body;
                        }
                        $is_error = true;
                        $return_message = sprintf(
                            /* translators: 1: HTTP status code, 2: error message */
                            __( 'The API key is invalid or the API request failed. Error Code: %1$d; %2$s', 'hhg-for-translatepress' ),
                            $code,
                            $msg
                        );
                    }
                }
            }

            $this->correct_api_key = array(
                'message' => $return_message,
                'error'   => $is_error,
            );
        }

        return array(
            'message' => $return_message,
            'error'   => $is_error,
        );
    }

    private function get_dynamic_max_tokens( $strings_array ) {
        $values = is_array( $strings_array ) ? array_values( $strings_array ) : array( $strings_array );
        $total_chars = 0;
        foreach ( $values as $str ) {
            $total_chars += strlen( $str );
        }
        return max( 4096, (int) ( $total_chars * 2 ) );
    }

    public function check_languages_availability( $languages, $force_recheck = false ) {
        if ( ! method_exists( $this, 'get_supported_languages' ) || ! method_exists( $this, 'get_engine_specific_language_codes' ) ) {
            return true;
        }

        $force_recheck = ( current_user_can( 'manage_options' ) &&
            ! empty( $_GET['trp_recheck_supported_languages'] ) && '1' === $_GET['trp_recheck_supported_languages'] &&
            isset( $_GET['trp_recheck_supported_languages_nonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['trp_recheck_supported_languages_nonce'] ) ), 'trp_recheck_supported_languages' ) )
            ? true : $force_recheck;

        $data = get_option( 'trp_db_stored_data', array() );
        if ( isset( $_GET['trp_recheck_supported_languages'] ) ) {
            unset( $_GET['trp_recheck_supported_languages'] );
        }

        if ( empty( $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['last-checked'] ) || $force_recheck ) {
            if ( empty( $data['trp_mt_supported_languages'] ) ) {
                $data['trp_mt_supported_languages'] = array();
            }
            if ( empty( $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ] ) ) {
                $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ] = array( 'languages' => array() );
            }

            $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['languages'] = $this->get_supported_languages();

            if ( method_exists( $this, 'check_formality' ) ) {
                $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['formality-supported-languages'] = $this->check_formality();
            } else {
                $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['formality-supported-languages'] = array();
            }

            $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['last-checked'] = gmdate( 'Y-m-d H:i:s' );
            update_option( 'trp_db_stored_data', $data );
        }

        $languages_iso_to_check = $this->get_engine_specific_language_codes( $languages );
        $all_are_available = ! array_diff( $languages_iso_to_check, $data['trp_mt_supported_languages'][ $this->settings['trp_machine_translation_settings']['translation-engine'] ]['languages'] );
        return apply_filters( 'trp_mt_available_supported_languages', $all_are_available, $languages, $this->settings );
    }

    public function get_referer() {
        if ( isset( $_SERVER['HTTP_HOST'] ) ) {
            $protocol = is_ssl() ? 'https://' : 'http://';
            return $protocol . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
        }
        return home_url();
    }
}
