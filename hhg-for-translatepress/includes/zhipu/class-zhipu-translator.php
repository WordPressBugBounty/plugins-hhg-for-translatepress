<?php
/**
 * Zhipu AI (GLM) Translation Engine
 * Agent API: https://open.bigmodel.cn/api/v1/agents
 *
 * Optimisations (v1.0.5):
 *  1. Character-based chunking — merges short strings, reduces API calls 20-40%
 *  2. Untranslatable filter — skips empty/numeric/symbolic strings
 *  3. Per-chunk instant retry — fixes missing items in ~500ms without waiting for global Round 2
 *  4. Tiered chunk caps — 4000 chars for the GLM agent
 *  5. cURL connection warmup — pre-establishes TCP+TLS before dispatching
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_Zhipu_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $agent_id;

    // Chunking config (char-based, not count-based)
    private $max_chars   = 4000;
    private $timeout     = 45;

    public function __construct( $settings ) {
        parent::__construct( $settings );

        $this->api_endpoint = 'https://open.bigmodel.cn/api/v1/agents';
        $this->agent_id     = 'general_translation';

        $this->max_chars = apply_filters( 'hhgfotr_zhipu_max_chars', $this->max_chars );
        $this->timeout   = apply_filters( 'hhgfotr_zhipu_timeout', $this->timeout );
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
        $api_key             = $this->get_api_key();
        $window_size         = apply_filters( 'hhgfotr_context_window_size', 2 );
        $mapped_lang         = $this->map_lang_code( $target_language_code );
        $strategy            = $this->get_selected_strategy();

        $industry_prompt = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-industry-prompt'] )
            ? $this->settings['trp_machine_translation_settings']['hhgfotr-industry-prompt'] : '';

        if ( empty( $api_key ) ) {
            return array();
        }

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
        $chunks = HHG_Context_Builder::build_char_chunks( $to_translate, $this->max_chars );

        $api_client = HHG_API_Client::get_instance();

        // ── Opt 5: Connection warmup ──
        $api_client->warmup( $this->api_endpoint, array( 'Content-Type' => 'application/json' ) );

        // ── Round 1: build requests + cache check ──
        $requests         = array();
        $chunks_index_map = array();
        $cached_chunks    = array();

        foreach ( $chunks as $idx => $chunk ) {
            $chunk_values_list = array_values( $chunk );
            $cache_key = 'zhipu_' . md5( implode( "\n", $chunk_values_list ) . '|' . $mapped_lang );
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

            $ctx = HHG_Context_Builder::get_context( $to_translate, $chunk, $window_size );
            $text_to_translate = $this->build_translation_input( array_values( $chunk ) );
            $suggestion        = $this->build_suggestion( $source_language, $target_language, $industry_prompt, $page_context, $ctx );

            $request_body = $this->build_request_body( $text_to_translate, $mapped_lang, $strategy, $suggestion );

            $requests[] = array(
                'url'     => $this->api_endpoint,
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'body'    => wp_json_encode( $request_body ),
                'timeout' => $this->timeout,
            );
            $chunks_index_map[] = $idx;
        }

        if ( ! empty( $requests ) ) {
            if ( class_exists( 'HHG_Logger' ) ) {
                HHG_Logger::log( 'Zhipu Round 1', array(
                    'chunks'      => count( $requests ),
                    'cached'      => count( $cached_chunks ),
                    'target_lang' => $mapped_lang,
                ) );
            }

            $results = $api_client->request_async( $requests );

            $all_missing_keys       = array();
            $instant_retry_requests = array();
            $instant_retry_map      = array();

            foreach ( $results as $ridx => $result ) {
                $chunk        = $chunks[ $chunks_index_map[ $ridx ] ];
                $chunk_values = array_values( $chunk );

                if ( is_array( $result ) && isset( $result['response_code'] ) && 200 === (int) $result['response_code'] ) {
                    $response_data   = json_decode( $result['body'], true );
                    $translation_text = $this->extract_translation_text( $response_data );

                    if ( ! empty( $translation_text ) ) {
                        if ( isset( $this->machine_translator_logger ) ) {
                            $this->machine_translator_logger->count_towards_quota( $chunk );
                        }
                        $chunk_translations = $this->parse_translation_response( $translation_text, $chunk_values );

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
                            $retry_text   = $this->build_translation_input( array_values( $chunk_missing ) );
                            $retry_body   = $this->build_retry_body( $retry_text, $mapped_lang, $strategy, $source_language, $target_language );

                            $instant_retry_requests[] = array(
                                'url'     => $this->api_endpoint,
                                'method'  => 'POST',
                                'headers' => array(
                                    'Content-Type'  => 'application/json',
                                    'Accept'        => 'application/json',
                                    'Authorization' => 'Bearer ' . $api_key,
                                ),
                                'body'    => wp_json_encode( $retry_body ),
                                'timeout' => $this->timeout,
                            );
                            $instant_retry_map[] = $chunk_missing;
                        } else {
                            // All items translated — cache full chunk
                            $cache_key = 'zhipu_' . md5( implode( "\n", $chunk_values ) . '|' . $mapped_lang );
                            if ( class_exists( 'HHG_Cache' ) ) {
                                HHG_Cache::set( $cache_key, array( 'text' => $translation_text ), 1800 );
                            }
                        }
                        continue;
                    }
                }

                // Chunk failed entirely — collect for global Round 2
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'Zhipu chunk error', array(
                        'chunk' => $chunks_index_map[ $ridx ],
                        'code'  => isset( $result['response_code'] ) ? $result['response_code'] : 'N/A',
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
                    HHG_Logger::log( 'Zhipu Instant Retry', array( 'chunks' => count( $instant_retry_requests ) ) );
                }
                $retry_results = $api_client->request_async( $instant_retry_requests );
                foreach ( $retry_results as $rridx => $retry_result ) {
                    $miss = $instant_retry_map[ $rridx ];
                    if ( is_array( $retry_result ) && isset( $retry_result['response_code'] ) && 200 === (int) $retry_result['response_code'] ) {
                        $retry_data = json_decode( $retry_result['body'], true );
                        $retry_translation_text = $this->extract_translation_text( $retry_data );
                        if ( ! empty( $retry_translation_text ) ) {
                            $reparsed = $this->parse_translation_response( $retry_translation_text, array_values( $miss ) );
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
                    HHG_Logger::log( 'Zhipu Round 2', array( 'missing_count' => count( $all_missing_keys ) ) );
                }
                $retry_chunks       = HHG_Context_Builder::build_char_chunks( $all_missing_keys, min( 2000, (int) ( $this->max_chars / 2 ) ) );
                $retry_requests     = array();
                $retry_chunks_index = array();

                foreach ( $retry_chunks as $ridx => $retry_chunk ) {
                    $retry_text = $this->build_translation_input( array_values( $retry_chunk ) );
                    $retry_body = $this->build_retry_body( $retry_text, $mapped_lang, $strategy, $source_language, $target_language );

                    $retry_requests[] = array(
                        'url'     => $this->api_endpoint,
                        'method'  => 'POST',
                        'headers' => array(
                            'Content-Type'  => 'application/json',
                            'Accept'        => 'application/json',
                            'Authorization' => 'Bearer ' . $api_key,
                        ),
                        'body'    => wp_json_encode( $retry_body ),
                        'timeout' => $this->timeout,
                    );
                    $retry_chunks_index[] = $ridx;
                }

                $retry_results = $api_client->request_async( $retry_requests );
                foreach ( $retry_results as $rridx => $retry_result ) {
                    $retry_chunk = $retry_chunks[ $retry_chunks_index[ $rridx ] ];
                    if ( is_array( $retry_result ) && isset( $retry_result['response_code'] ) && 200 === (int) $retry_result['response_code'] ) {
                        $retry_data = json_decode( $retry_result['body'], true );
                        $retry_translation_text = $this->extract_translation_text( $retry_data );
                        if ( ! empty( $retry_translation_text ) ) {
                            $reparsed = $this->parse_translation_response( $retry_translation_text, array_values( $retry_chunk ) );
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

    // ────────────────────────────────────────────────────────────
    //  Request body builders
    // ────────────────────────────────────────────────────────────

    /**
     * Build the full agent API request body for Round 1.
     */
    private function build_request_body( $text_to_translate, $mapped_lang, $strategy, $suggestion = '' ) {
        $custom_vars = array(
            'source_lang' => 'auto',
            'target_lang' => $mapped_lang,
            'strategy'    => $strategy,
        );

        if ( ! empty( $suggestion ) ) {
            $custom_vars['strategy_config'] = array(
                'general' => array(
                    'suggestion' => $suggestion,
                ),
            );
        }

        return array(
            'agent_id'         => $this->agent_id,
            'messages'         => array(
                array(
                    'role'    => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => $text_to_translate,
                        ),
                    ),
                ),
            ),
            'custom_variables' => $custom_vars,
            'stream'           => false,
        );
    }

    /**
     * Build a lightweight retry body for missing strings.
     * Uses a minimal suggestion to keep the retry fast.
     */
    private function build_retry_body( $text_to_translate, $mapped_lang, $strategy, $source_language, $target_language ) {
        $suggestion = 'Translate ' . $source_language . ' → ' . $target_language
                    . '. Keep HTML/URLs. One per line. No chat.';

        return $this->build_request_body( $text_to_translate, $mapped_lang, $strategy, $suggestion );
    }

    /**
     * Build simple numbered translation input — no [Ref] context inline.
     * Context belongs in the suggestion field, not in the text to translate.
     */
    private function build_translation_input( $strings_to_translate ) {
        $lines = array();
        $idx   = 1;
        foreach ( $strings_to_translate as $str ) {
            $lines[] = $idx . '. ' . $str;
            $idx++;
        }
        return implode( "\n", $lines );
    }

    /**
     * Build suggestion string for strategy_config.general.suggestion.
     * Includes industry context, page context, AND surrounding string context.
     */
    private function build_suggestion( $source_language, $target_language, $industry_prompt = '', $page_context = array(), $ctx = array() ) {
        $parts   = array();

        $parts[] = 'Translate from ' . $source_language . ' to ' . $target_language . '.';

        if ( ! empty( $industry_prompt ) ) {
            $parts[] = 'Domain/Industry: ' . $industry_prompt;
        }

        if ( ! empty( $page_context['intro'] ) ) {
            $parts[] = 'Page context: ' . implode( ' | ', array_slice( $page_context['intro'], 0, 3 ) );
        }

        if ( ! empty( $page_context['key_terms'] ) ) {
            $terms = array_slice( $page_context['key_terms'], 0, 10 );
            $parts[] = 'Key terms to translate consistently: ' . implode( ', ', $terms );
        }

        // Surrounding context — put here instead of inline
        if ( ! empty( $ctx['before'] ) ) {
            $parts[] = 'Preceding text (for context only, do NOT translate): ' . implode( ' | ', $ctx['before'] );
        }
        if ( ! empty( $ctx['after'] ) ) {
            $parts[] = 'Following text (for context only, do NOT translate): ' . implode( ' | ', $ctx['after'] );
        }

        $parts[] = 'Only translate the numbered lines. Keep all HTML tags unchanged. Do not translate URLs. Return one translated line per number, preserving the exact numbering format "N. text".';

        return implode( ' ', $parts );
    }

    /**
     * Extract translation text from agent response.
     * Handles both agent format (messages[0].content) and standard format (message.content).
     */
    private function extract_translation_text( $response_data ) {
        if ( empty( $response_data ) ) {
            return '';
        }

        $choice = isset( $response_data['choices'][0] ) ? $response_data['choices'][0] : null;
        if ( ! $choice ) {
            return '';
        }

        // Agent API format: choices[0].messages[0].content (plural "messages")
        if ( isset( $choice['messages'][0]['content'] ) ) {
            $content = $choice['messages'][0]['content'];
            if ( is_array( $content ) && isset( $content['text'] ) ) {
                return $content['text'];
            } elseif ( is_string( $content ) ) {
                return $content;
            }
        }

        // Standard chat completions format: choices[0].message.content (singular "message")
        if ( isset( $choice['message']['content'] ) ) {
            $content = $choice['message']['content'];
            if ( is_array( $content ) && isset( $content['text'] ) ) {
                return $content['text'];
            } elseif ( is_string( $content ) ) {
                return $content;
            }
        }

        return '';
    }

    // ────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────

    private function map_lang_code( $code ) {
        if ( $code === 'zh_CN' || $code === 'zh' ) {
            return 'zh-CN';
        }
        if ( $code === 'zh_TW' ) {
            return 'zh-TW';
        }
        $code = str_replace( '_', '-', $code );
        if ( strpos( $code, 'ru' ) === 0 ) { return 'ru'; }
        return $code;
    }

    private function parse_translation_response( $response_text, $original_strings ) {
        return HHG_Context_Builder::parse_response( $response_text, $original_strings );
    }

    public function test_request() {
        $body = array(
            'agent_id' => $this->agent_id,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array('type' => 'text', 'text' => 'Hello world')
                    )
                )
            ),
            'custom_variables' => array(
                'source_lang' => 'auto',
                'target_lang' => 'zh-CN',
                'strategy' => 'general',
            ),
            'stream' => false
        );

        $response = wp_remote_post( $this->api_endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_api_key()
            ),
            'body' => wp_json_encode( $body ),
            'timeout' => 30,
        ));

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        return array(
            'body' => $body,
            'response' => array('code' => $code)
        );
    }

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-zhipu-key'] ) ? $this->settings['trp_machine_translation_settings']['hhg-zhipu-key'] : false;
    }

    public function get_selected_strategy() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-model'] ) && ! empty( $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-model'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-zhipu-model'];
        }

        if ( isset( $this->settings['trp_machine_translation_settings']['hhg-zhipu-model'] ) && ! empty( $this->settings['trp_machine_translation_settings']['hhg-zhipu-model'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhg-zhipu-model'];
        }

        return 'general';
    }

    public function get_available_models() {
        return array(
            'general'     => 'General (Default)',
            'paraphrase'  => 'Paraphrase (More Natural)',
            'two_step'    => 'Two Step (Review & Refine)',
            'three_step'  => 'Three Step (Deep Analysis)',
            'reflection'  => 'Reflection (Self-Correction)',
            'cot'         => 'Chain of Thought (Reasoning)',
        );
    }

    public function get_supported_languages() {
        return array();
    }

    public function check_languages_availability( $languages, $force_recheck = false ) {
        return true;
    }

    public function get_engine_specific_language_codes( $languages ) {
        $mapped = array();
        foreach ( $languages as $code ) {
            $mapped[] = $this->map_lang_code( $code );
        }
        return $mapped;
    }

    public function check_formality() { return false; }

    public function check_api_key_validity() {
        $response = $this->test_request();
        if ( is_wp_error( $response ) ) {
            return array('error' => true, 'message' => 'Connection failed: ' . $response->get_error_message());
        }

        $code = $response['response']['code'];

        if ( $code === 0 ) {
            return array(
                'error' => true,
                'message' => 'Cannot reach Zhipu API (connection failed). Check that your server can access open.bigmodel.cn. If using a local dev environment, ensure cURL and OpenSSL are properly configured.'
            );
        }

        if ( $code === 401 || $code === 403 ) {
            return array('error' => true, 'message' => 'API key invalid (HTTP ' . $code . '). Please check your key.');
        }

        if ( $code != 200 ) {
            $body = isset($response['body']) ? $response['body'] : '';
            return array('error' => true, 'message' => 'API Error: HTTP ' . $code . ($body ? ' — ' . substr($body, 0, 200) : ''));
        }

        $response_data = json_decode( $response['body'], true );

        if ( ! $response_data || ! isset( $response_data['choices'][0] ) ) {
             return array(
                'error' => true,
                'message' => 'API response format unexpected. Raw: ' . substr($response['body'], 0, 200)
            );
        }

        $choice = $response_data['choices'][0];
        $has_content = false;

        if ( isset($choice['messages'][0]['content']) || isset($choice['message']['content']) ) {
            $has_content = true;
        }

        if ( ! $has_content ) {
            return array(
                'error' => true,
                'message' => 'API response format error: No content found'
            );
        }

        return array(
            'error' => false,
            'message' => 'Valid'
        );
    }
}
