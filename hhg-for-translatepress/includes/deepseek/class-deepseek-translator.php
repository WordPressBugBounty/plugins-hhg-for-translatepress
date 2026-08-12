<?php
/**
 * DeepSeek Translation Engine
 * OpenAI-compatible API: https://api.deepseek.com/chat/completions
 *
 * Optimisations (v1.0.5):
 *  1. Character-based chunking — merges short strings, reduces API calls 20-40%
 *  2. Untranslatable filter — skips empty/numeric/symbolic strings
 *  3. Per-chunk instant retry — fixes missing items in ~500ms without waiting for global Round 2
 *  4. Tiered chunk caps — V4 Flash 6000→V4 Pro 3500 chars based on model capability
 *  5. cURL connection warmup — pre-establishes TCP+TLS before dispatching
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_DeepSeek_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $config;

    public function __construct( $settings ) {
        parent::__construct( $settings );

        $selected_model = $this->get_selected_model();
        $this->api_endpoint = 'https://api.deepseek.com/chat/completions';
        $this->config = $this->get_optimized_config( $selected_model );
    }

    private function get_optimized_config( $model ) {
        $base_config = array(
            'max_chars'   => 3000,
            'timeout'     => 45,
            'temperature' => 0.01,
            'top_p'       => 0.95,
            'thinking'    => null,
        );

        if ( strpos( $model, 'v4-flash' ) !== false ) {
            // V4 Flash — fast, cheap, large chunks
            $base_config['max_chars']   = 6000;
            $base_config['timeout']     = 25;
            $base_config['temperature'] = 0.005;
            $base_config['thinking']    = 'disabled';
        } elseif ( strpos( $model, 'v4-pro' ) !== false ) {
            // V4 Pro — highest quality (slower, keep chunks moderate)
            $base_config['max_chars']   = 3500;
            $base_config['timeout']     = 50;
            $base_config['temperature'] = 0.005;
            $base_config['thinking']    = 'disabled';
        }

        return $base_config;
    }

    public function send_request( $source_language, $target_language, $strings_array, $context_before = array(), $context_after = array(), $industry_prompt = '', $page_context = array() ) {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_api_key', 'DeepSeek API key cannot be empty' );
        }

        $prompt = $this->build_translation_prompt( $source_language, $target_language, $strings_array, $context_before, $context_after, $industry_prompt, $page_context );
        $request_body = $this->build_request_body( $source_language, $target_language, $prompt, $strings_array );

        $response = wp_remote_post( $this->api_endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
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
            $api_client->warmup( $this->api_endpoint, array( 'Content-Type' => 'application/json' ) );
        }

        // ── Round 1: build requests + cache check ──
        $requests         = array();
        $chunks_index_map = array();
        $cached_chunks    = array();

        foreach ( $chunks as $idx => $chunk ) {
            $chunk_values_list = array_values( $chunk );
            $cache_key = 'deepseek_' . md5( implode( "\n", $chunk_values_list ) . '|' . $target_language_code . '|' . $selected_model );
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
                'url'     => $this->api_endpoint,
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $this->get_api_key(),
                    'User-Agent'    => 'TranslatePress/1.0',
                ),
                'body'    => wp_json_encode( $request_body ),
                'timeout' => $this->config['timeout'],
            );
            $chunks_index_map[] = $idx;
        }

        if ( $api_client && ! empty( $requests ) ) {
            if ( class_exists( 'HHG_Logger' ) ) {
                HHG_Logger::log( 'DeepSeek Round 1', array(
                    'chunks'      => count( $requests ),
                    'cached'      => count( $cached_chunks ),
                    'target_lang' => $target_language,
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
                    $response_body = json_decode( $result['body'], true );
                    if ( isset( $response_body['choices'][0]['message']['content'] ) ) {
                        if ( isset( $this->machine_translator_logger ) ) {
                            $this->machine_translator_logger->count_towards_quota( $chunk );
                        }
                        $response_text      = $response_body['choices'][0]['message']['content'];
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
                                'url'     => $this->api_endpoint,
                                'method'  => 'POST',
                                'headers' => array(
                                    'Content-Type'  => 'application/json',
                                    'Accept'        => 'application/json',
                                    'Authorization' => 'Bearer ' . $this->get_api_key(),
                                    'User-Agent'    => 'TranslatePress/1.0',
                                ),
                                'body'    => wp_json_encode( $retry_body ),
                                'timeout' => $this->config['timeout'],
                            );
                            $instant_retry_map[] = $chunk_missing;
                        } else {
                            // All items translated — cache full chunk
                            $cache_key = 'deepseek_' . md5( implode( "\n", $chunk_values ) . '|' . $target_language_code . '|' . $selected_model );
                            if ( class_exists( 'HHG_Cache' ) ) {
                                HHG_Cache::set( $cache_key, array( 'text' => $response_text ), 1800 );
                            }
                        }
                        continue;
                    }
                }

                // Chunk failed entirely — collect for global Round 2
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'DeepSeek chunk error', array(
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
                    HHG_Logger::log( 'DeepSeek Instant Retry', array( 'chunks' => count( $instant_retry_requests ) ) );
                }
                $retry_results = $api_client->request_async( $instant_retry_requests );
                foreach ( $retry_results as $rridx => $retry_result ) {
                    $miss = $instant_retry_map[ $rridx ];
                    if ( is_array( $retry_result ) && isset( $retry_result['response_code'] ) && 200 === (int) $retry_result['response_code'] ) {
                        $retry_data = json_decode( $retry_result['body'], true );
                        if ( isset( $retry_data['choices'][0]['message']['content'] ) ) {
                            $reparsed = $this->parse_translation_response( $retry_data['choices'][0]['message']['content'], array_values( $miss ) );
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
                    HHG_Logger::log( 'DeepSeek Round 2', array( 'missing_count' => count( $all_missing_keys ) ) );
                }
                $retry_chunks       = HHG_Context_Builder::build_char_chunks( $all_missing_keys, min( 2000, (int) ( $this->config['max_chars'] / 2 ) ) );
                $retry_requests     = array();
                $retry_chunks_index = array();

                foreach ( $retry_chunks as $ridx => $retry_chunk ) {
                    $retry_prompt = HHG_Context_Builder::build_retry_prompt( $source_language, $target_language, $retry_chunk );
                    $retry_body   = $this->build_retry_body( $source_language, $target_language, $retry_prompt, $retry_chunk );

                    $retry_requests[] = array(
                        'url'     => $this->api_endpoint,
                        'method'  => 'POST',
                        'headers' => array(
                            'Content-Type'  => 'application/json',
                            'Accept'        => 'application/json',
                            'Authorization' => 'Bearer ' . $this->get_api_key(),
                            'User-Agent'    => 'TranslatePress/1.0',
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
                        if ( isset( $retry_data['choices'][0]['message']['content'] ) ) {
                            $reparsed = $this->parse_translation_response( $retry_data['choices'][0]['message']['content'], array_values( $retry_chunk ) );
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
     * Build request body for DeepSeek chat/completions API.
     */
    private function build_request_body( $source_language, $target_language, $prompt, $strings_array ) {
        $body = array(
            'model'       => $this->get_selected_model(),
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => 'Translate ' . $source_language . ' → ' . $target_language . '. Keep HTML/URLs. One per line. No chat.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'max_tokens'  => $this->get_dynamic_max_tokens( $strings_array ),
            'temperature' => $this->config['temperature'],
            'top_p'       => $this->config['top_p'],
            'stream'      => false,
        );

        // V4 models: disable thinking for translation (faster, cheaper, same quality)
        if ( $this->config['thinking'] === 'disabled' ) {
            $body['thinking'] = array( 'type' => 'disabled' );
        }

        return $body;
    }

    /**
     * Build a lightweight retry body for missing strings.
     * Uses lower temperature + compact system instruction.
     */
    private function build_retry_body( $source_language, $target_language, $prompt, $strings_array ) {
        $body = array(
            'model'       => $this->get_selected_model(),
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => $source_language . '→' . $target_language . '. One per line.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'max_tokens'  => $this->get_dynamic_max_tokens( $strings_array ),
            'temperature' => min( $this->config['temperature'], 0.005 ),
            'top_p'       => $this->config['top_p'],
            'stream'      => false,
        );

        if ( $this->config['thinking'] === 'disabled' ) {
            $body['thinking'] = array( 'type' => 'disabled' );
        }

        return $body;
    }

    // ────────────────────────────────────────────────────────────
    //  Sync fallback
    // ────────────────────────────────────────────────────────────

    private function process_sync_response( $response, $chunk, &$translated_strings, $source_language, $target_language, $target_language_code, $selected_model ) {
        $this->machine_translator_logger->log(array(
            'strings'    => serialize( $chunk ),
            'response'   => serialize( $response ),
            'lang_source'  => $source_language,
            'lang_target'  => $target_language,
        ));

        $chunk_values = array_values( $chunk );

        if ( is_array( $response ) && ! is_wp_error( $response ) && isset( $response['response']['code'] ) && 200 === (int) $response['response']['code'] ) {
            $response_body = json_decode( $response['body'], true );
            if ( isset( $response_body['choices'][0]['message']['content'] ) ) {
                if ( isset( $this->machine_translator_logger ) ) {
                    $this->machine_translator_logger->count_towards_quota( $chunk );
                }
                $response_text = $response_body['choices'][0]['message']['content'];
                $chunk_translations = $this->parse_translation_response( $response_text, $chunk_values );

                $cache_key = 'deepseek_' . md5( implode( "\n", $chunk_values ) . '|' . $target_language_code . '|' . $selected_model );
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
        return $this->send_request( 'English', 'Chinese', array( 'Hello world' ) );
    }

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-deepseek-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-deepseek-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-deepseek-key'] ) ? $this->settings['trp_machine_translation_settings']['hhg-deepseek-key'] : '';
    }

    public function get_selected_model() {
        $selected = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-deepseek-model'] )
            ? $this->settings['trp_machine_translation_settings']['hhgfotr-deepseek-model']
            : ( isset( $this->settings['trp_machine_translation_settings']['hhg-deepseek-model'] )
                ? $this->settings['trp_machine_translation_settings']['hhg-deepseek-model']
                : 'deepseek-v4-flash' );

        $available = array_keys( $this->get_available_models() );
        if ( ! in_array( $selected, $available, true ) ) {
            return 'deepseek-v4-flash';
        }
        return $selected;
    }

    public function get_available_models() {
        return array(
            'deepseek-v4-flash'  => 'DeepSeek V4 Flash (Fast & Affordable)',
            'deepseek-v4-pro'    => 'DeepSeek V4 Pro (Highest Quality)',
        );
    }

    public function get_supported_languages() {
        return array();
    }

    public function get_engine_specific_language_codes( $languages ) {
        $codes = array();
        foreach ( $languages as $language ) {
            $codes[$language] = $language;
        }
        return $codes;
    }

    public function check_formality() { return false; }

    public function check_api_key_validity() {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return array(
                'error' => true,
                'message' => 'DeepSeek API key cannot be empty'
            );
        }

        $response = $this->test_request();

        if ( is_wp_error( $response ) ) {
            return array(
                'error' => true,
                'message' => $response->get_error_message()
            );
        }

        if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
            $code = $response['response']['code'];
            if ( $code == 200 ) {
                return array(
                    'error' => false,
                    'message' => 'DeepSeek API key is valid'
                );
            } elseif ( $code === 401 || $code === 403 ) {
                return array('error' => true, 'message' => 'API key invalid (HTTP ' . $code . '). Please check your key.');
            } else {
                $body = wp_remote_retrieve_body( $response );
                $decoded = json_decode( $body, true );
                $msg = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : $body;
                return array(
                    'error' => true,
                    'message' => 'API error (HTTP ' . $code . '): ' . substr($msg, 0, 200)
                );
            }
        }

        return array(
            'error' => true,
            'message' => 'Cannot reach DeepSeek API'
        );
    }

    public function check_languages_availability( $languages, $force_recheck = false ) {
        return true;
    }

    private function get_dynamic_max_tokens( $strings_array ) {
        $values = is_array( $strings_array ) ? array_values( $strings_array ) : array( $strings_array );
        $total_chars = 0;
        foreach ( $values as $str ) {
            $total_chars += strlen( $str );
        }
        // Translation output ≈ input size; 2× headroom for CJK expansion, min 4096
        return max( 4096, (int)( $total_chars * 2 ) );
    }
}
