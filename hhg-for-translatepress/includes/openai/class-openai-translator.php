<?php
/**
 * Universal OpenAI-Compatible Translation Engine
 * Supports: OpenAI, Azure, Groq, Together AI, OpenRouter, Ollama, and any custom endpoint
 *
 * Optimisations (v1.0.5):
 *  1. Character-based chunking — merges short strings, reduces API calls 20-40%
 *  2. Untranslatable filter — skips empty/numeric/symbolic strings
 *  3. Per-chunk instant retry — fixes missing items in ~500ms without waiting for global Round 2
 *  4. Tiered chunk caps — 6000→3500 chars based on model capability heuristics
 *  5. cURL connection warmup — pre-establishes TCP+TLS before dispatching
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRP_HHGFOTR_OpenAI_Machine_Translator extends TRP_Machine_Translator {

    private $api_endpoint;
    private $config;

    public function __construct( $settings ) {
        parent::__construct( $settings );

        $this->api_endpoint = $this->get_api_endpoint();
        $this->config = $this->get_optimized_config();
    }

    /**
     * Get optimized config based on model name heuristics.
     * Works for any OpenAI-compatible model.
     */
    private function get_optimized_config() {
        $model = strtolower( $this->get_selected_model() );

        $config = array(
            'max_chars'   => 3000,
            'timeout'     => 30,
            'temperature' => 0.005,
            'top_p'       => 0.95,
        );

        // Heuristic: detect model family and adjust
        if ( strpos( $model, 'gpt-4' ) !== false && strpos( $model, 'mini' ) === false && strpos( $model, 'nano' ) === false ) {
            // GPT-4 class (non-mini): slower, higher quality — smaller chunks
            $config['max_chars']   = 2000;
            $config['timeout']     = 60;
            $config['temperature'] = 0.005;
        } elseif ( strpos( $model, 'gpt-3.5' ) !== false ) {
            // GPT-3.5: fast but older
            $config['max_chars']   = 4000;
            $config['timeout']     = 25;
        } elseif ( strpos( $model, 'o1' ) !== false || strpos( $model, 'o3' ) !== false || strpos( $model, 'o4' ) !== false ) {
            // Reasoning models: slow, high quality, may not support temp=0
            $config['max_chars']   = 1500;
            $config['timeout']     = 90;
            $config['temperature'] = 1.0;
        } elseif ( strpos( $model, 'llama' ) !== false && strpos( $model, '4' ) !== false ) {
            // Llama 4 class: fast open model
            $config['max_chars']   = 5000;
            $config['timeout']     = 25;
        } elseif ( strpos( $model, 'llama' ) !== false ) {
            // Llama 3 class
            $config['max_chars']   = 4000;
            $config['timeout']     = 30;
        } elseif ( strpos( $model, 'mixtral' ) !== false ) {
            // Mixtral: MoE, fast
            $config['max_chars']   = 5000;
            $config['timeout']     = 25;
        } elseif ( strpos( $model, 'gemma' ) !== false ) {
            // Gemma: fast small model
            $config['max_chars']   = 6000;
            $config['timeout']     = 20;
        } elseif ( strpos( $model, 'deepseek' ) !== false ) {
            // DeepSeek via compatible API
            $config['max_chars']   = 5000;
            $config['timeout']     = 30;
        } elseif ( strpos( $model, 'qwen' ) !== false ) {
            // Qwen series
            $config['max_chars']   = 5000;
            $config['timeout']     = 30;
        } elseif ( strpos( $model, 'claude' ) !== false || strpos( $model, 'anthropic' ) !== false ) {
            // Anthropic via OpenRouter
            $config['max_chars']   = 3500;
            $config['timeout']     = 45;
        }

        return $config;
    }

    /**
     * Build a single API request (used for test_request and sync fallback)
     */
    public function send_request( $source_language, $target_language, $strings_array, $context_before = array(), $context_after = array(), $industry_prompt = '', $page_context = array() ) {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) && $this->get_platform() !== 'ollama' ) {
            return new WP_Error( 'no_api_key', __( 'API key cannot be empty.', 'hhg-for-translatepress' ) );
        }

        $prompt = $this->build_translation_prompt( $source_language, $target_language, $strings_array, $context_before, $context_after, $industry_prompt, $page_context );
        $request_body = $this->build_request_body( $source_language, $target_language, $prompt, $strings_array );
        $headers = $this->build_auth_headers();

        $response = wp_remote_post( $this->api_endpoint, array(
            'headers'     => $headers,
            'body'        => wp_json_encode( $request_body ),
            'timeout'     => $this->config['timeout'],
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'sslverify'   => true,
        ));

        return $response;
    }

    /**
     * Build auth headers for the current platform.
     */
    private function build_auth_headers() {
        $api_key  = $this->get_api_key();
        $platform = $this->get_platform();

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'User-Agent'   => 'TranslatePress/1.0',
        );

        if ( $platform === 'azure' ) {
            $headers['api-key'] = $api_key;
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        if ( $platform === 'openrouter' ) {
            $headers['HTTP-Referer'] = $this->get_referer();
            $headers['X-Title']      = 'TranslatePress';
        }

        return $headers;
    }

    /**
     * Build request body — shared across send_request and translate_array
     */
    private function build_request_body( $source_language, $target_language, $prompt, $strings_array ) {
        return array(
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
    }

    /**
     * Build a lightweight retry body for missing strings.
     * Uses lower temperature + compact system instruction.
     */
    private function build_retry_body( $source_language, $target_language, $prompt, $strings_array ) {
        return array(
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
            'temperature' => min( $this->config['temperature'], 0.003 ),
            'top_p'       => $this->config['top_p'],
            'stream'      => false,
        );
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
        $platform            = $this->get_platform();
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

        // Build auth headers once for reuse
        $base_headers = $this->build_auth_headers();

        // ── Opt 5: Connection warmup ──
        if ( $api_client ) {
            $warmup_headers = array( 'Content-Type' => 'application/json' );
            if ( isset( $base_headers['Authorization'] ) ) {
                $warmup_headers['Authorization'] = $base_headers['Authorization'];
            }
            $api_client->warmup( $this->api_endpoint, $warmup_headers );
        }

        // ── Round 1: build requests + cache check ──
        $requests         = array();
        $chunks_index_map = array();
        $cached_chunks    = array();

        foreach ( $chunks as $idx => $chunk ) {
            $chunk_values_list = array_values( $chunk );
            $cache_key = 'openai_' . md5( implode( "\n", $chunk_values_list ) . '|' . $target_language_code . '|' . $selected_model . '|' . $platform );
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
                'headers' => $base_headers,
                'body'    => wp_json_encode( $request_body ),
                'timeout' => $this->config['timeout'],
            );
            $chunks_index_map[] = $idx;
        }

        if ( $api_client && ! empty( $requests ) ) {
            if ( class_exists( 'HHG_Logger' ) ) {
                HHG_Logger::log( 'OpenAI Round 1', array(
                    'chunks'      => count( $requests ),
                    'cached'      => count( $cached_chunks ),
                    'target_lang' => $target_language,
                    'platform'    => $platform,
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
                                'headers' => $base_headers,
                                'body'    => wp_json_encode( $retry_body ),
                                'timeout' => $this->config['timeout'],
                            );
                            $instant_retry_map[] = $chunk_missing;
                        } else {
                            // All items translated — cache full chunk
                            $cache_key = 'openai_' . md5( implode( "\n", $chunk_values ) . '|' . $target_language_code . '|' . $selected_model . '|' . $platform );
                            if ( class_exists( 'HHG_Cache' ) ) {
                                HHG_Cache::set( $cache_key, array( 'text' => $response_text ), 1800 );
                            }
                        }
                        continue;
                    }
                }

                // Chunk failed entirely — collect for global Round 2
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'OpenAI chunk error', array(
                        'code' => isset( $result['response_code'] ) ? $result['response_code'] : 'N/A',
                    ));
                }
                foreach ( $chunk as $key => $old_string ) {
                    $translated_strings[ $key ] = $old_string;
                    $all_missing_keys[ $key ]   = $old_string;
                }
            }

            // ── Process instant retries ──
            if ( ! empty( $instant_retry_requests ) ) {
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'OpenAI Instant Retry', array( 'chunks' => count( $instant_retry_requests ) ) );
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
                    HHG_Logger::log( 'OpenAI Round 2', array( 'missing_count' => count( $all_missing_keys ) ) );
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
                        'headers' => $base_headers,
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

    // ────────────────────────────────────────────────────────────
    //  Sync fallback
    // ────────────────────────────────────────────────────────────

    private function process_sync_response( $response, $chunk, &$translated_strings, $target_language_code, $selected_model, $platform ) {
        $chunk_values = array_values( $chunk );

        if ( is_array( $response ) && ! is_wp_error( $response ) && isset( $response['response']['code'] ) && 200 === (int) $response['response']['code'] ) {
            $response_body = json_decode( $response['body'], true );
            if ( isset( $response_body['choices'][0]['message']['content'] ) ) {
                if ( isset( $this->machine_translator_logger ) ) {
                    $this->machine_translator_logger->count_towards_quota( $chunk );
                }
                $response_text      = $response_body['choices'][0]['message']['content'];
                $chunk_translations = $this->parse_translation_response( $response_text, $chunk_values );

                $cache_key = 'openai_' . md5( implode( "\n", $chunk_values ) . '|' . $target_language_code . '|' . $selected_model . '|' . $platform );
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

    private function get_dynamic_max_tokens( $strings_array ) {
        $values      = is_array( $strings_array ) ? array_values( $strings_array ) : array( $strings_array );
        $total_chars = 0;
        foreach ( $values as $str ) {
            $total_chars += strlen( $str );
        }
        return max( 4096, (int) ( $total_chars * 2 ) );
    }

    // ─── Settings Getters ───────────────────────────────────────────

    public function get_api_key() {
        if ( isset( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-key'] ) ) {
            return $this->settings['trp_machine_translation_settings']['hhgfotr-openai-key'];
        }
        return isset( $this->settings['trp_machine_translation_settings']['hhg-openai-key'] )
            ? $this->settings['trp_machine_translation_settings']['hhg-openai-key']
            : '';
    }

    public function get_selected_model() {
        $model = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-model'] )
            ? trim( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-model'] )
            : ( isset( $this->settings['trp_machine_translation_settings']['hhg-openai-model'] )
                ? trim( $this->settings['trp_machine_translation_settings']['hhg-openai-model'] )
                : 'gpt-4o-mini' );

        if ( empty( $model ) ) {
            $model = 'gpt-4o-mini';
        }

        return $model;
    }

    /**
     * Get the current platform preset
     */
    public function get_platform() {
        $platform = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-platform'] )
            ? $this->settings['trp_machine_translation_settings']['hhgfotr-openai-platform']
            : ( isset( $this->settings['trp_machine_translation_settings']['hhg-openai-platform'] )
                ? $this->settings['trp_machine_translation_settings']['hhg-openai-platform']
                : 'openai' );

        $valid = array_keys( $this->get_platform_presets() );
        if ( ! in_array( $platform, $valid, true ) ) {
            return 'openai';
        }
        return $platform;
    }

    /**
     * Resolve the effective API endpoint
     */
    public function get_api_endpoint() {
        $platform = $this->get_platform();
        $presets  = $this->get_platform_presets();
        $custom   = isset( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-endpoint'] )
            ? trim( $this->settings['trp_machine_translation_settings']['hhgfotr-openai-endpoint'] )
            : ( isset( $this->settings['trp_machine_translation_settings']['hhg-openai-endpoint'] )
                ? trim( $this->settings['trp_machine_translation_settings']['hhg-openai-endpoint'] )
                : '' );

        // Use custom endpoint if provided (overrides preset)
        if ( ! empty( $custom ) ) {
            $custom = rtrim( $custom, '/' );
            // Don't append chat/completions if it's already in the URL
            if ( strpos( $custom, 'chat/completions' ) === false ) {
                return $custom . '/chat/completions';
            }
            return $custom;
        }

        // Fall back to preset (skip if empty)
        if ( isset( $presets[ $platform ]['endpoint'] ) && ! empty( $presets[ $platform ]['endpoint'] ) ) {
            return $presets[ $platform ]['endpoint'];
        }

        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Platform presets with default endpoints and suggested models
     */
    public function get_platform_presets() {
        return array(
            'openai'     => array(
                'label'    => 'OpenAI',
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'models'   => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1-nano', 'o4-mini', 'gpt-4-turbo' ),
                'desc'     => 'api.openai.com — 官方 OpenAI API',
            ),
            'azure'      => array(
                'label'    => 'Azure OpenAI',
                'endpoint' => '',
                'models'   => array( 'gpt-4o-mini', 'gpt-4o' ),
                'desc'     => '需要填写完整端点地址（含 deployment 路径）',
            ),
            'groq'       => array(
                'label'    => 'Groq',
                'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
                'models'   => array( 'llama-4-maverick-17b-128e-instruct', 'meta-llama/llama-4-scout-17b-16e-instruct', 'mixtral-8x7b-32768', 'gemma2-9b-it', 'llama-3.3-70b-versatile' ),
                'desc'     => 'api.groq.com — 极速推理，免费额度',
            ),
            'together'   => array(
                'label'    => 'Together AI',
                'endpoint' => 'https://api.together.xyz/v1/chat/completions',
                'models'   => array( 'deepseek-ai/DeepSeek-V4-Flash', 'Qwen/Qwen3.5-397B-A17B', 'google/gemma-4-31B-it' ),
                'desc'     => 'api.together.xyz — 开源模型托管',
            ),
            'openrouter' => array(
                'label'    => 'OpenRouter',
                'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
                'models'   => array( 'openai/gpt-4.1-mini', 'anthropic/claude-sonnet-4', 'google/gemini-2.5-flash', 'meta-llama/llama-4-maverick' ),
                'desc'     => 'openrouter.ai — 多模型聚合路由',
            ),
            'ollama'     => array(
                'label'    => 'Ollama (本地)',
                'endpoint' => 'http://localhost:11434/v1/chat/completions',
                'models'   => array( 'llama3.2', 'qwen3', 'mistral', 'gemma3' ),
                'desc'     => 'localhost:11434 — 本地运行，无需 API Key',
            ),
            'custom'     => array(
                'label'    => '自定义',
                'endpoint' => '',
                'models'   => array(),
                'desc'     => '填入任意兼容 OpenAI 的 API 地址',
            ),
        );
    }

    /**
     * Backward-compatible model list (dropdown suggestions)
     */
    public function get_available_models() {
        return $this->get_platform_presets();
    }

    public function get_supported_languages() {
        return array();
    }

    public function get_engine_specific_language_codes( $languages ) {
        $codes = array();
        foreach ( $languages as $language ) {
            $codes[ $language ] = $language;
        }
        return $codes;
    }

    public function check_formality() {
        return false;
    }

    public function check_languages_availability( $languages, $force_recheck = false ) {
        return true;
    }

    public function test_request() {
        return $this->send_request( 'English', 'Chinese', array( 'Hello world' ) );
    }

    public function check_api_key_validity() {
        $api_key = $this->get_api_key();

        // Ollama doesn't need an API key
        if ( $this->get_platform() === 'ollama' ) {
            $response = $this->test_request();
            if ( is_wp_error( $response ) ) {
                return array(
                    'error'   => true,
                    'message' => __( 'Connection to Ollama failed. Make sure Ollama is running.', 'hhg-for-translatepress' ),
                );
            }
            return array(
                'error'   => false,
                'message' => __( 'Ollama connected successfully.', 'hhg-for-translatepress' ),
            );
        }

        if ( empty( $api_key ) ) {
            return array(
                'error'   => true,
                'message' => __( 'API key cannot be empty.', 'hhg-for-translatepress' ),
            );
        }

        $response = $this->test_request();

        if ( is_wp_error( $response ) ) {
            return array(
                'error'   => true,
                'message' => $response->get_error_message(),
            );
        }

        if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
            $code = (int) $response['response']['code'];
            if ( 200 === $code ) {
                return array(
                    'error'   => false,
                    'message' => __( 'API connection successful.', 'hhg-for-translatepress' ),
                );
            }

            $body    = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $body, true );
            $msg     = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : substr( $body, 0, 200 );

            if ( 401 === $code || 403 === $code ) {
                return array(
                    'error'   => true,
                    'message' => sprintf( __( 'Authentication failed (HTTP %d). Please check your API key.', 'hhg-for-translatepress' ), $code ),
                );
            }

            return array(
                'error'   => true,
                'message' => sprintf( __( 'API error (HTTP %d): %s', 'hhg-for-translatepress' ), $code, $msg ),
            );
        }

        return array(
            'error'   => true,
            'message' => __( 'Cannot reach the API endpoint. Check the URL and your network connection.', 'hhg-for-translatepress' ),
        );
    }

    public function get_referer() {
        if ( isset( $_SERVER['HTTP_HOST'] ) ) {
            $protocol = is_ssl() ? 'https://' : 'http://';
            return $protocol . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
        }
        return home_url();
    }
}
