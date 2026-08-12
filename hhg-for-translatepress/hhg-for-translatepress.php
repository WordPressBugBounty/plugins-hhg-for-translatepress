<?php

/**
 * Plugin Name: HHG for TranslatePress
 * Plugin URI: https://huhonggang.com/hhg-for-translatepress/
 * Description: Google Gemini AI, OpenAI-compatible (OpenAI/Groq/Azure/Ollama/OpenRouter), DeepSeek, ZhiPu AI. All engines integrated into TranslatePress as translation sources.
 * Version: 1.0.5
 * Author: huhonggang
 * Author URI: https://huhonggang.com/
 * Text Domain: hhg-for-translatepress
 * Requires Plugins: translatepress-multilingual
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages/
 */
 
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HHGFOTR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HHGFOTR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'HHG_TRANSLATEPRESS_PLUGIN_DIR' ) ) {
    define( 'HHG_TRANSLATEPRESS_PLUGIN_DIR', HHGFOTR_PLUGIN_DIR );
}
if ( ! defined( 'HHG_TRANSLATEPRESS_PLUGIN_URL' ) ) {
    define( 'HHG_TRANSLATEPRESS_PLUGIN_URL', HHGFOTR_PLUGIN_URL );
}

class HHGFOTR_TranslatePress {
    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! $this->is_translatepress_active() ) {
            add_action( 'admin_notices', array( $this, 'missing_translatepress_notice' ) );
            return;
        }
        add_action( 'plugins_loaded', array( $this, 'init' ), 1 );
    }

    private function is_translatepress_active() {
        if ( class_exists( 'TRP_Translate_Press' ) ) {
            return true;
        }
        
        $active_plugins = get_option( 'active_plugins', array() );
        if ( in_array( 'translatepress-multilingual/index.php', $active_plugins ) ) {
            return true;
        }

        if ( is_multisite() ) {
            $network_active_plugins = get_site_option( 'active_sitewide_plugins', array() );
            if ( isset( $network_active_plugins['translatepress-multilingual/index.php'] ) ) {
                return true;
            }
        }
        
        return false;
    }

    public function init() {
        $this->load_engines();
        $this->register_hooks();
        $this->setup_debug();
    }

    private function register_hooks() {
        add_filter( 'trp_machine_translation_engines', array( $this, 'add_hhg_engines_to_list' ), 20 );
        add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'register_engine_classes' ), 20 );
        add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'override_mtapi_to_zhipu' ), 100 );
        add_filter( 'trp_machine_translator_is_available', array( $this, 'force_mt_available' ), 999 );
        add_filter( 'trp_machine_translation_sanitize_settings', array( $this, 'sanitize_settings' ), 20, 2 );
        add_action( 'trp_machine_translation_extra_settings_middle', array( $this, 'add_settings_fields' ), 20, 1 );
        add_filter( 'trp_get_default_trp_machine_translation_settings', array( $this, 'add_default_settings' ), 20, 1 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_filter( 'trp_machine_translation_sanitize_settings', array( $this, 'extend_machine_translation_keys' ), 10, 2 );
        add_action( 'wp_ajax_hhgfotr_zhipu_test_api', array( $this, 'handle_zhipu_test_api' ) );
        add_action( 'wp_ajax_hhg_zhipu_test_api', array( $this, 'handle_zhipu_test_api' ) );
    }

    private function load_engines() {
        $logger_path = HHGFOTR_PLUGIN_DIR . 'includes/core/class-logger.php';
        $cache_path  = HHGFOTR_PLUGIN_DIR . 'includes/core/class-cache.php';
        $api_path    = HHGFOTR_PLUGIN_DIR . 'includes/core/class-api-client.php';
        $ctx_path    = HHGFOTR_PLUGIN_DIR . 'includes/core/class-context-builder.php';

        if ( file_exists( $logger_path ) ) {
            require_once $logger_path;
        } else {
            error_log('[HHG-TP] Logger file missing at ' . $logger_path);
        }

        if ( file_exists( $cache_path ) ) {
            require_once $cache_path;
        } else {
            error_log('[HHG-TP] Cache file missing at ' . $cache_path);
        }

        if ( file_exists( $api_path ) ) {
            require_once $api_path;
        } else {
            error_log('[HHG-TP] API client file missing at ' . $api_path);
        }

        if ( file_exists( $ctx_path ) ) {
            require_once $ctx_path;
        } else {
            error_log('[HHG-TP] Context builder file missing at ' . $ctx_path);
        }
        
        // load engine helper functions (safe)
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/gemini/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/gemini/functions.php';
        }
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/deepseek/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/deepseek/functions.php';
        }
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/openai/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/openai/functions.php';
        }
        if ( file_exists( HHGFOTR_PLUGIN_DIR . 'includes/zhipu/functions.php' ) ) {
            require_once HHGFOTR_PLUGIN_DIR . 'includes/zhipu/functions.php';
        }
    }

    public function register_engine_classes( $classes ) {
        // ensure translator classes are loaded when TP requests class names
        if ( class_exists( 'TRP_Machine_Translator' ) ) {
            $files = array(
                'includes/gemini/class-gemini-translator.php',
                'includes/deepseek/class-deepseek-translator.php',
                'includes/openai/class-openai-translator.php',
                'includes/zhipu/class-zhipu-translator.php',
            );
            foreach ( $files as $rel ) {
                $path = HHGFOTR_PLUGIN_DIR . $rel;
                if ( file_exists( $path ) ) {
                    require_once $path;
                }
            }
        }
        $classes['hhgfotr_gemini'] = 'TRP_HHGFOTR_Gemini_Machine_Translator';
        $classes['hhgfotr_deepseek'] = 'TRP_HHGFOTR_DeepSeek_Machine_Translator';
        $classes['hhgfotr_openai'] = 'TRP_HHGFOTR_OpenAI_Machine_Translator';
        $classes['hhgfotr_zhipu'] = 'TRP_HHGFOTR_Zhipu_Machine_Translator';
        $classes['hhg_gemini'] = 'TRP_HHGFOTR_Gemini_Machine_Translator';
        $classes['hhg_deepseek'] = 'TRP_HHGFOTR_DeepSeek_Machine_Translator';
        $classes['hhg_openai'] = 'TRP_HHGFOTR_OpenAI_Machine_Translator';
        $classes['hhg_zhipu'] = 'TRP_HHGFOTR_Zhipu_Machine_Translator';
        return $classes;
    }

    public function override_mtapi_to_zhipu( $classes ) {
        $classes['mtapi'] = 'TRP_HHGFOTR_Zhipu_Machine_Translator';
        return $classes;
    }

    public function force_mt_available( $is_available ) {
        $mt = get_option( 'trp_machine_translation_settings', array() );
        $engine = isset( $mt['translation-engine'] ) ? $mt['translation-engine'] : '';
        $enabled = isset( $mt['machine-translation'] ) ? $mt['machine-translation'] : 'no';
        if ( $enabled === 'yes' ) {
            if ( in_array( $engine, array( 'hhgfotr_zhipu', 'hhg_zhipu', 'hhgfotr_gemini', 'hhg_gemini', 'hhgfotr_openai', 'hhg_openai', 'hhgfotr_deepseek', 'hhg_deepseek', 'mtapi' ), true ) ) {
                return true;
            }
        }
        return $is_available;
    }

    private function setup_debug() {
        if ( defined( 'HHGFOTR_DEBUG' ) && HHGFOTR_DEBUG ) {
            add_action( 'http_api_debug', function( $response, $context, $class, $args, $url ) {
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'HTTP API call', array( 'url' => $url, 'method' => isset($args['method']) ? $args['method'] : 'GET' ) );
                }
            }, 10, 5 );

            add_action( 'wp_loaded', function() {
                $mt = get_option( 'trp_machine_translation_settings', array() );
                $trp = class_exists( 'TRP_Translate_Press' ) ? TRP_Translate_Press::get_trp_instance() : null;
                $machine_translator = $trp ? $trp->get_component( 'machine_translator' ) : null;
                $class = $machine_translator ? get_class( $machine_translator ) : 'none';
                if ( class_exists( 'HHG_Logger' ) ) {
                    HHG_Logger::log( 'TP settings snapshot', array( 'mt_settings' => $mt, 'active_engine_class' => $class ) );
                }
            });
        }
    }

    public function add_settings_fields( $mt_settings ) {
        if ( function_exists('trp_gt_add_settings') ) {
            trp_gt_add_settings($mt_settings);
        }
        if ( function_exists('trp_deepl_add_settings') ) {
            trp_deepl_add_settings($mt_settings);
        }

        // Industry/domain prompt — only for HHG engines
        $trp = class_exists( 'TRP_Translate_Press' ) ? TRP_Translate_Press::get_trp_instance() : null;
        $machine_translator = $trp ? $trp->get_component( 'machine_translator' ) : null;
        $translation_engine = isset( $mt_settings['translation-engine'] ) ? $mt_settings['translation-engine'] : '';
        $is_hhg_engine = in_array( $translation_engine, array( 'hhgfotr_gemini', 'hhg_gemini', 'hhgfotr_deepseek', 'hhg_deepseek', 'hhgfotr_openai', 'hhg_openai', 'hhgfotr_zhipu', 'hhg_zhipu', 'mtapi' ), true );

        $industry_prompt = isset( $mt_settings['hhgfotr-industry-prompt'] ) ? $mt_settings['hhgfotr-industry-prompt'] : '';
        ?>
        <div id="hhgfotr-industry-prompt-container"
             class="hhgfotr-shared-setting"
             style="<?php echo $is_hhg_engine ? '' : 'display: none;'; ?> margin-bottom: 24px; max-width: 580px; background: #f6f7f7; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; border-radius: 3px; padding: 16px 20px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <span class="dashicons dashicons-admin-site" style="color: #2271b1; font-size: 20px; width: 20px; height: 20px;"></span>
                <span class="trp-primary-text-bold" style="font-size: 14px;">
                    <?php esc_html_e( 'Industry / Domain Description', 'hhg-for-translatepress' ); ?>
                </span>
            </div>

            <p class="trp-description-text" style="margin: 0 0 10px 0; font-size: 13px; color: #646970;">
                <?php esc_html_e( 'Help the AI understand your site\'s context. Describe your industry, target audience, preferred tone, and any terminology rules.', 'hhg-for-translatepress' ); ?>
            </p>

            <textarea id="hhgfotr-industry-prompt"
                      name="trp_machine_translation_settings[hhgfotr-industry-prompt]"
                      class="trp-text-input"
                      rows="3"
                      placeholder="<?php esc_attr_e( 'e.g. Medical device manufacturer targeting professional surgeons. Use precise terminology, formal tone, keep brand names untranslated.', 'hhg-for-translatepress' ); ?>"
                      style="width: 100%; font-size: 13px; line-height: 1.5; resize: vertical; border-radius: 3px;"
            ><?php echo esc_textarea( $industry_prompt ); ?></textarea>

            <span class="trp-description-text" style="display: block; margin-top: 6px; font-size: 12px; color: #8c8f94;">
                <span class="dashicons dashicons-info-outline" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
                <?php esc_html_e( 'This description is sent with every AI translation request to improve terminology accuracy and tone consistency site-wide.', 'hhg-for-translatepress' ); ?>
            </span>
        </div>
        <?php

        $this->add_gemini_settings( $mt_settings, $machine_translator, $translation_engine );
        $this->add_deepseek_settings( $mt_settings, $machine_translator, $translation_engine );
        $this->add_openai_settings( $mt_settings, $machine_translator, $translation_engine );
        $this->add_zhipu_settings( $mt_settings, $machine_translator, $translation_engine );
    }

    private function add_gemini_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key = isset( $mt_settings['hhgfotr-gemini-key'] ) ? $mt_settings['hhgfotr-gemini-key'] : ( isset( $mt_settings['hhg-gemini-key'] ) ? $mt_settings['hhg-gemini-key'] : '' );
        $model = isset( $mt_settings['hhgfotr-gemini-model'] ) ? $mt_settings['hhgfotr-gemini-model'] : ( isset( $mt_settings['hhg-gemini-model'] ) ? $mt_settings['hhg-gemini-model'] : 'gemini-2.5-flash' );

        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_gemini', 'hhg_gemini' ), true ) && $machine_translator && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        
        $is_active = in_array( $translation_engine, array( 'hhgfotr_gemini', 'hhg_gemini' ), true );
        ?>

        <div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_gemini" style="<?php echo $is_active ? '' : 'display: none;'; ?>">
            <span class="trp-primary-text-bold"><?php esc_html_e( 'Google Gemini API Key', 'hhg-for-translatepress' ); ?></span>

            <div class="trp-automatic-translation-api-key-container">
                <input type="text" id="hhgfotr-gemini-key" placeholder="<?php esc_html_e( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>" 
                       class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>" 
                        name="trp_machine_translation_settings[hhgfotr-gemini-key]" 
                       value="<?php echo esc_attr( $api_key ); ?>" style="width: 100%;max-width:480px;" />
                <?php
                if ( $is_active && $machine_translator && function_exists( 'trp_output_svg' ) ) {
                    $machine_translator->automatic_translation_svg_output( $show_errors );
                }
                ?>
            </div>

            <?php if ( $show_errors ) : ?>
                <span class="trp-error-inline trp-settings-error-text">
                    <?php echo wp_kses_post( $error_message ); ?>
                </span>
            <?php endif; ?>

            <div class="trp-gemini-model-container" style="margin-top: 15px;">
               <p><span class="trp-primary-text-bold"><?php esc_html_e( 'Gemini Model', 'hhg-for-translatepress' ); ?></span></p>
                <select id="hhgfotr-gemini-model" name="trp_machine_translation_settings[hhgfotr-gemini-model]" class="trp-select" style="width: 100%;max-width:480px;">
                    <option value="gemini-2.5-flash-lite" <?php selected( $model, 'gemini-2.5-flash-lite' ); ?>><?php esc_html_e( 'Gemini 2.5 Flash-Lite (Fastest & Cheapest)', 'hhg-for-translatepress' ); ?></option>
                    <option value="gemini-2.5-flash" <?php selected( $model, 'gemini-2.5-flash' ); ?>><?php esc_html_e( 'Gemini 2.5 Flash (Balanced)', 'hhg-for-translatepress' ); ?></option>
                    <option value="gemini-2.5-pro" <?php selected( $model, 'gemini-2.5-pro' ); ?>><?php esc_html_e( 'Gemini 2.5 Pro (Highest Quality)', 'hhg-for-translatepress' ); ?></option>
                    <option value="gemini-3-flash" <?php selected( $model, 'gemini-3-flash' ); ?>><?php esc_html_e( 'Gemini 3 Flash (Latest Generation)', 'hhg-for-translatepress' ); ?></option>
                    <option value="gemini-3.5-flash" <?php selected( $model, 'gemini-3.5-flash' ); ?>><?php esc_html_e( 'Gemini 3.5 Flash (Newest — May 2026)', 'hhg-for-translatepress' ); ?></option>
                </select>
            </div>

            <span class="trp-description-text">
                <?php echo wp_kses( __( 'Get your API key from <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>. <strong>Flash-Lite</strong> is the fastest & cheapest option for translation. <strong>2.5 Flash</strong> offers the best balance. <strong>2.5 Pro</strong> for highest quality. <strong>3 Flash / 3.5 Flash</strong> are the latest generation models.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ], 'strong' => [] ] ); ?>
                
            </span>
        </div>

        <?php
    }

    private function add_deepseek_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key = isset( $mt_settings['hhgfotr-deepseek-key'] ) ? $mt_settings['hhgfotr-deepseek-key'] : ( isset( $mt_settings['hhg-deepseek-key'] ) ? $mt_settings['hhg-deepseek-key'] : '' );
        $model = isset( $mt_settings['hhgfotr-deepseek-model'] ) ? $mt_settings['hhgfotr-deepseek-model'] : ( isset( $mt_settings['hhg-deepseek-model'] ) ? $mt_settings['hhg-deepseek-model'] : 'deepseek-v4-flash' );
        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_deepseek', 'hhg_deepseek' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }
        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        $is_active = in_array( $translation_engine, array( 'hhgfotr_deepseek', 'hhg_deepseek' ), true );
        ?>
        <div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_deepseek" style="<?php echo $is_active ? '' : 'display: none;'; ?>">
            <span class="trp-primary-text-bold"><?php esc_html_e( 'DeepSeek API Key', 'hhg-for-translatepress' ); ?></span>
            <div class="trp-automatic-translation-api-key-container">
                <input type="text" id="hhgfotr-deepseek-key" name="trp_machine_translation_settings[hhgfotr-deepseek-key]" value="<?php echo esc_attr( $api_key ); ?>" placeholder="<?php esc_attr_e( 'Enter your DeepSeek API key...', 'hhg-for-translatepress' ); ?>" class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>" style="width: 100%;max-width:480px;" />
                <?php
                if ( $is_active && $machine_translator && function_exists( 'trp_output_svg' ) ) {
                    $machine_translator->automatic_translation_svg_output( $show_errors );
                }
                ?>
            </div>
            <?php if ( $show_errors ) : ?>
                <span class="trp-error-inline trp-settings-error-text"><?php echo esc_html( $error_message ); ?></span>
            <?php endif; ?>
            <div class="trp-deepseek-model-container" style="margin-top: 15px;">
                <p><span class="trp-primary-text-bold"><?php esc_html_e( 'Model', 'hhg-for-translatepress' ); ?></span></p>
                <select id="hhgfotr-deepseek-model" name="trp_machine_translation_settings[hhgfotr-deepseek-model]" class="trp-select" style="max-width:480px;">
                    <option value="deepseek-v4-flash" <?php selected( $model, 'deepseek-v4-flash' ); ?>><?php esc_html_e( 'DeepSeek V4 Flash (Fast & Affordable)', 'hhg-for-translatepress' ); ?></option>
                    <option value="deepseek-v4-pro" <?php selected( $model, 'deepseek-v4-pro' ); ?>><?php esc_html_e( 'DeepSeek V4 Pro (Highest Quality)', 'hhg-for-translatepress' ); ?></option>
                </select>
            </div>
            <span class="trp-description-text" style="display:block;margin-top:10px;">
                <?php echo wp_kses( __( 'Get your API key from the <a href="https://platform.deepseek.com/api_keys" target="_blank">DeepSeek Platform</a>. <strong>V4 Flash</strong> is recommended for most translations — fast, affordable, and accurate. <strong>V4 Pro</strong> offers higher quality at ~12× the cost for professional-grade translations.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ], 'strong' => [] ] ); ?>
            </span>
        </div>
        <?php
    }

    private function add_openai_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key  = isset( $mt_settings['hhgfotr-openai-key'] ) ? $mt_settings['hhgfotr-openai-key'] : ( isset( $mt_settings['hhg-openai-key'] ) ? $mt_settings['hhg-openai-key'] : '' );
        $model    = isset( $mt_settings['hhgfotr-openai-model'] ) ? $mt_settings['hhgfotr-openai-model'] : ( isset( $mt_settings['hhg-openai-model'] ) ? $mt_settings['hhg-openai-model'] : 'gpt-4o-mini' );
        $platform = isset( $mt_settings['hhgfotr-openai-platform'] ) ? $mt_settings['hhgfotr-openai-platform'] : ( isset( $mt_settings['hhg-openai-platform'] ) ? $mt_settings['hhg-openai-platform'] : 'openai' );
        $endpoint = isset( $mt_settings['hhgfotr-openai-endpoint'] ) ? $mt_settings['hhgfotr-openai-endpoint'] : ( isset( $mt_settings['hhg-openai-endpoint'] ) ? $mt_settings['hhg-openai-endpoint'] : '' );

        // Platform presets (mirrors class-openai-translator.php)
        $presets = array(
            'openai'     => array(
                'label'  => 'OpenAI',
                'models' => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1-nano', 'o4-mini', 'gpt-4-turbo' ),
                'desc'   => __( 'Get your API key from the <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>. GPT-4o Mini is the fastest and most cost-effective choice for translation.', 'hhg-for-translatepress' ),
                'show_ep'=> false,
            ),
            'azure'      => array(
                'label'  => 'Azure OpenAI',
                'models' => array( 'gpt-4o-mini', 'gpt-4o' ),
                'desc'   => __( 'Enter your Azure OpenAI endpoint URL (including the deployment path). Get your key from the Azure Portal.', 'hhg-for-translatepress' ),
                'show_ep'=> true,
            ),
            'groq'       => array(
                'label'  => 'Groq',
                'models' => array( 'llama-4-maverick-17b-128e-instruct', 'meta-llama/llama-4-scout-17b-16e-instruct', 'mixtral-8x7b-32768', 'gemma2-9b-it', 'llama-3.3-70b-versatile' ),
                'desc'   => __( 'Get your API key from <a href="https://console.groq.com/keys" target="_blank">Groq Console</a>. Ultra-fast inference with generous free tier.', 'hhg-for-translatepress' ),
                'show_ep'=> false,
            ),
            'together'   => array(
                'label'  => 'Together AI',
                'models' => array( 'deepseek-ai/DeepSeek-V4-Flash', 'Qwen/Qwen3.5-397B-A17B', 'google/gemma-4-31B-it' ),
                'desc'   => __( 'Get your API key from <a href="https://api.together.xyz/settings/api-keys" target="_blank">Together AI</a>. Open-source model hosting with competitive pricing.', 'hhg-for-translatepress' ),
                'show_ep'=> false,
            ),
            'openrouter' => array(
                'label'  => 'OpenRouter',
                'models' => array( 'openai/gpt-4.1-mini', 'anthropic/claude-sonnet-4', 'google/gemini-2.5-flash', 'meta-llama/llama-4-maverick' ),
                'desc'   => __( 'Get your API key from <a href="https://openrouter.ai/keys" target="_blank">OpenRouter</a>. Multi-provider routing — access models from OpenAI, Anthropic, Google, and more.', 'hhg-for-translatepress' ),
                'show_ep'=> false,
            ),
            'ollama'     => array(
                'label'  => 'Ollama (本地)',
                'models' => array( 'llama3.2', 'qwen3', 'mistral', 'gemma3' ),
                'desc'   => __( 'Ollama runs locally — no API key needed. Make sure Ollama is running and the model is pulled. Default endpoint: http://localhost:11434', 'hhg-for-translatepress' ),
                'show_ep'=> true,
            ),
            'custom'     => array(
                'label'  => '自定义',
                'models' => array(),
                'desc'   => __( 'Enter any OpenAI-compatible API endpoint and model name. Works with any service that supports the <code>/chat/completions</code> format.', 'hhg-for-translatepress' ),
                'show_ep'=> true,
            ),
        );

        $error_message = '';
        $show_errors   = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_openai', 'hhg_openai' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors   = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        $is_active = in_array( $translation_engine, array( 'hhgfotr_openai', 'hhg_openai' ), true );

        // Safety: fallback to openai if platform value is invalid
        if ( ! isset( $presets[ $platform ] ) ) {
            $platform = 'openai';
        }

        // Build JS presets data + also embed as JSON in a data attribute for inline fallback
        $js_presets = array();
        foreach ( $presets as $k => $p ) {
            $js_presets[ $k ] = array(
                'models'  => $p['models'],
                'show_ep' => $p['show_ep'],
                'desc'    => wp_kses( $p['desc'], array( 'a' => array( 'href' => array(), 'target' => array(), 'title' => array() ), 'code' => array() ) ),
            );
        }
        $presets_json = wp_json_encode( $js_presets );
        ?>
        <script type="text/javascript">
        window.hhgOpenAIPresets = <?php echo $presets_json; ?>;

        /**
         * Switch OpenAI platform — rebuilds endpoint visibility, chips, datalist, placeholders.
         * Called by the platform <select> onchange AND by engine-switch.js when the tab opens.
         */
        function hhgSwitchOpenAIPlatform(platformValue) {
            var presets = window.hhgOpenAIPresets || {};
            var p = presets[platformValue];
            if (!p) return;

            // 1. Endpoint row: show / hide
            var er = document.getElementById('hhgfotr-openai-endpoint-row');
            if (er) {
                er.style.display = p.show_ep ? '' : 'none';
                var ei = er.querySelector('input');
                if (ei) {
                    if (platformValue === 'azure') {
                        ei.placeholder = 'https://YOUR_RESOURCE.openai.azure.com/openai/deployments/YOUR_DEPLOYMENT';
                    } else if (platformValue === 'ollama') {
                        ei.placeholder = 'http://localhost:11434/v1';
                    } else {
                        ei.placeholder = 'https://your-api.example.com/v1';
                    }
                }
            }

            // 2. Datalist
            var dl = document.getElementById('hhgfotr-openai-model-list');
            if (dl) {
                dl.innerHTML = '';
                (p.models || []).forEach(function(m) {
                    var o = document.createElement('option');
                    o.value = m;
                    dl.appendChild(o);
                });
            }

            // 3. Chips
            var cr = document.getElementById('hhgfotr-openai-model-chips');
            if (cr) {
                cr.innerHTML = '';
                if ((p.models || []).length === 0) {
                    cr.innerHTML = '<span class="trp-description-text" style="font-size:13px;color:#666;">Custom Platform — Please enter the model name.</span>';
                } else {
                    p.models.forEach(function(m) {
                        var b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'hhgfotr-model-chip';
                        b.setAttribute('data-model', m);
                        b.textContent = m;
                        b.title = 'Click to Select: ' + m;
                        b.style.cssText = 'font-size:13px;padding:1px 8px 2px;border:1px solid #dcdcde;border-radius:3px;background:#f6f7f7;color:#50575e;cursor:pointer;white-space:nowrap;line-height:1.8;';
                        b.onmouseover = function() { this.style.borderColor='#2271b1';this.style.color='#2271b1';this.style.background='#f0f6fc'; };
                        b.onmouseout  = function() { this.style.borderColor='#dcdcde';this.style.color='#50575e';this.style.background='#f6f7f7'; };
                        b.onclick = function() {
                            var inp = document.getElementById('hhgfotr-openai-model');
                            if (inp) inp.value = this.getAttribute('data-model');
                            var all = cr.querySelectorAll('.hhgfotr-model-chip');
                            all.forEach(function(c) {
                                c.style.borderColor = '#dcdcde';
                                c.style.color      = '#50575e';
                                c.style.background  = '#f6f7f7';
                            });
                            this.style.borderColor = '#2271b1';
                            this.style.color      = '#2271b1';
                            this.style.background  = '#f0f6fc';
                        };
                        cr.appendChild(b);
                    });
                }
            }

            // 4. Description
            var ds = document.getElementById('hhgfotr-openai-platform-desc');
            if (ds) { ds.innerHTML = p.desc || ''; }

            // 5. API key placeholder (Ollama → no key needed)
            var ki = document.querySelector('#hhgfotr-openai-key-row input');
            if (ki) {
                if (platformValue === 'ollama') {
                    ki.placeholder = 'Ollama Run locally; no API Key required (can be left blank).';
                } else {
                    ki.placeholder = ki.getAttribute('data-def-ph') || 'Add your API Key here...';
                }
            }

            // 6. Model input placeholder
            var mi = document.getElementById('hhgfotr-openai-model');
            if (mi) {
                mi.placeholder = (p.models && p.models.length > 0)
                    ? 'Enter a model name, or click a recommended model below for a quick selection.'
                    : 'Please enter the model name, for example:: gpt-4o-mini';
            }
        }

        // Init placeholder saver
        (function() {
            var ki = document.querySelector('#hhgfotr-openai-key-row input');
            if (ki && !ki.getAttribute('data-def-ph')) {
                ki.setAttribute('data-def-ph', ki.placeholder);
            }
        })();
        </script>

        <div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_openai" style="<?php echo $is_active ? '' : 'display: none;'; ?>">

            <!-- Platform Preset Dropdown -->
            <div class="hhgfotr-openai-platform-container" style="margin-bottom: 15px;">
                <p><span class="trp-primary-text-bold"><?php esc_html_e( 'Platform', 'hhg-for-translatepress' ); ?></span></p>
                <select id="hhgfotr-openai-platform"
                        name="trp_machine_translation_settings[hhgfotr-openai-platform]"
                        class="trp-select"
                        onchange="hhgSwitchOpenAIPlatform(this.value)"
                        style="max-width: 480px;">
                    <?php foreach ( $presets as $pk => $pv ) : ?>
                        <option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $platform, $pk ); ?>>
                            <?php echo esc_html( $pv['label'] ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span id="hhgfotr-openai-platform-desc" class="trp-description-text" style="display: block; margin-top: 6px;">
                    <?php echo wp_kses( $presets[ $platform ]['desc'], array( 'a' => array( 'href' => array(), 'target' => array(), 'title' => array() ), 'code' => array() ) ); ?>
                </span>
            </div>

            <!-- API Key -->
            <div id="hhgfotr-openai-key-row">
                <span class="trp-primary-text-bold"><?php esc_html_e( 'API Key', 'hhg-for-translatepress' ); ?></span>
                <div class="trp-automatic-translation-api-key-container">
                    <input type="text"
                           id="hhgfotr-openai-key"
                           name="trp_machine_translation_settings[hhgfotr-openai-key]"
                           value="<?php echo esc_attr( $api_key ); ?>"
                           data-def-ph="<?php esc_attr_e( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>"
                           placeholder="<?php echo $platform === 'ollama' ? esc_attr__( 'Ollama 本地运行，无需 API Key（可留空）', 'hhg-for-translatepress' ) : esc_attr__( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>"
                           class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>"
                           style="width: 100%; max-width: 480px;" />
                    <?php
                    if ( $is_active && $machine_translator && function_exists( 'trp_output_svg' ) ) {
                        $machine_translator->automatic_translation_svg_output( $show_errors );
                    }
                    ?>
                </div>
                <?php if ( $show_errors ) : ?>
                    <span class="trp-error-inline trp-settings-error-text">
                        <?php echo wp_kses_post( $error_message ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Endpoint (conditional) -->
            <div id="hhgfotr-openai-endpoint-row" class="hhgfotr-transition-field" style="margin-top: 15px; <?php echo $presets[ $platform ]['show_ep'] ? '' : 'display: none;'; ?>">
                <p><span class="trp-primary-text-bold"><?php esc_html_e( 'API Endpoint URL', 'hhg-for-translatepress' ); ?></span></p>
                <input type="text"
                       id="hhgfotr-openai-endpoint"
                       name="trp_machine_translation_settings[hhgfotr-openai-endpoint]"
                       value="<?php echo esc_attr( $endpoint ); ?>"
                       placeholder="<?php echo esc_attr( $presets[ $platform ]['show_ep'] ? ($platform === 'azure' ? 'https://YOUR_RESOURCE.openai.azure.com/openai/deployments/YOUR_DEPLOYMENT' : ($platform === 'ollama' ? 'http://localhost:11434/v1' : 'https://your-api.example.com/v1')) : '' ); ?>"
                       class="trp-text-input"
                       style="width: 100%; max-width: 480px;" />
                <span class="trp-description-text" style="display: block; margin-top: 4px;">
                    <?php esc_html_e( 'The base URL of the API. "/chat/completions" will be appended automatically if not present.', 'hhg-for-translatepress' ); ?>
                </span>
            </div>

            <!-- Model Input (editable text + datalist + clickable chips) -->
            <div class="hhgfotr-openai-model-container" style="margin-top: 15px;">
                <p><span class="trp-primary-text-bold"><?php esc_html_e( 'Model', 'hhg-for-translatepress' ); ?></span></p>
                <input type="text"
                       id="hhgfotr-openai-model"
                       name="trp_machine_translation_settings[hhgfotr-openai-model]"
                       value="<?php echo esc_attr( $model ); ?>"
                       placeholder="<?php echo ! empty( $presets[ $platform ]['models'] ) ? esc_attr__( 'Enter a model name, or click a recommended model below for a quick selection.', 'hhg-for-translatepress' ) : esc_attr__( 'Please enter the model name, for example:: gpt-4o-mini', 'hhg-for-translatepress' ); ?>"
                       class="trp-text-input"
                       list="hhgfotr-openai-model-list"
                       autocomplete="off"
                       style="width: 100%; max-width: 480px;" />
                <datalist id="hhgfotr-openai-model-list">
                    <?php foreach ( $presets[ $platform ]['models'] as $m ) : ?>
                        <option value="<?php echo esc_attr( $m ); ?>">
                    <?php endforeach; ?>
                </datalist>
                <!-- Clickable model suggestion chips -->
                <div id="hhgfotr-openai-model-chips" style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; max-width: 520px;">
                    <?php if ( empty( $presets[ $platform ]['models'] ) ) : ?>
                        <span class="trp-description-text"><?php esc_html_e( 'Custom platform — enter your model name above', 'hhg-for-translatepress' ); ?></span>
                    <?php else : ?>
                        <?php foreach ( $presets[ $platform ]['models'] as $m ) : ?>
                            <button type="button"
                                    class="hhgfotr-model-chip"
                                    data-model="<?php echo esc_attr( $m ); ?>"
                                    title="<?php echo esc_attr( sprintf( __( 'Click to select: %s', 'hhg-for-translatepress' ), $m ) ); ?>"
                                    style="font-size:13px;padding:1px 8px 2px;border:1px solid #dcdcde;border-radius:3px;background:#f6f7f7;color:#50575e;cursor:pointer;white-space:nowrap;line-height:1.8;"
                                    onmouseover="this.style.borderColor='#2271b1';this.style.color='#2271b1';this.style.background='#f0f6fc';"
                                    onmouseout="this.style.borderColor='#dcdcde';this.style.color='#50575e';this.style.background='#f6f7f7';"
                                    onclick="var inp=document.getElementById('hhgfotr-openai-model');if(inp)inp.value=this.getAttribute('data-model');var chips=this.parentNode.querySelectorAll('.hhgfotr-model-chip');chips.forEach(function(c){c.style.borderColor='#dcdcde';c.style.color='#50575e';c.style.background='#f6f7f7';});this.style.borderColor='#2271b1';this.style.color='#2271b1';this.style.background='#f0f6fc';">
                                <?php echo esc_html( $m ); ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <span class="trp-description-text" style="display: block; margin-top: 6px;">
                    <?php esc_html_e( 'You can type any model name or click a suggestion chip above. The suggestion list updates automatically when you switch platforms.', 'hhg-for-translatepress' ); ?>
                </span>
            </div>
        </div>
        <?php
    }

    private function add_zhipu_settings( $mt_settings, $machine_translator, $translation_engine ) {
        $api_key = isset( $mt_settings['hhgfotr-zhipu-key'] ) ? $mt_settings['hhgfotr-zhipu-key'] : ( isset( $mt_settings['hhg-zhipu-key'] ) ? $mt_settings['hhg-zhipu-key'] : '' );
        $model = isset( $mt_settings['hhgfotr-zhipu-model'] ) ? $mt_settings['hhgfotr-zhipu-model'] : ( isset( $mt_settings['hhg-zhipu-model'] ) ? $mt_settings['hhg-zhipu-model'] : 'general' );

        $error_message = '';
        $show_errors = false;
        if ( in_array( $translation_engine, array( 'hhgfotr_zhipu', 'hhg_zhipu' ), true ) && method_exists( $machine_translator, 'check_api_key_validity' ) ) {
            $api_check = $machine_translator->check_api_key_validity();
            if ( isset( $api_check ) && true === $api_check['error'] ) {
                $error_message = $api_check['message'];
                $show_errors = true;
            }
        }

        $text_input_classes = array( 'trp-text-input' );
        if ( $show_errors ) {
            $text_input_classes[] = 'trp-text-input-error';
        }
        
        $is_active = in_array( $translation_engine, array( 'hhgfotr_zhipu', 'hhg_zhipu' ), true );
        ?>

        <div class="trp-engine trp-automatic-translation-engine__container" id="hhgfotr_zhipu" style="<?php echo $is_active ? '' : 'display: none;'; ?>">
            <span class="trp-primary-text-bold"><?php esc_html_e( 'ZhiPu AI API Key', 'hhg-for-translatepress' ); ?></span>

            <div class="trp-automatic-translation-api-key-container">
                <input type="text" id="hhgfotr-zhipu-key" placeholder="<?php esc_html_e( 'Add your API Key here...', 'hhg-for-translatepress' ); ?>" 
                       class="<?php echo esc_attr( implode( ' ', $text_input_classes ) ); ?>" 
                        name="trp_machine_translation_settings[hhgfotr-zhipu-key]" 
                       value="<?php echo esc_attr( $api_key ); ?>" style="width: 100%;max-width:480px;" />
                <?php
                if ( $is_active && $machine_translator && function_exists( 'trp_output_svg' ) ) {
                    $machine_translator->automatic_translation_svg_output( $show_errors );
                }
                ?>
            </div>

            <?php if ( $show_errors ) : ?>
                <span class="trp-error-inline trp-settings-error-text">
                    <?php echo wp_kses_post( $error_message ); ?>
                </span>
            <?php endif; ?>

        <div class="trp-zhipu-model-container" style="margin-top: 15px;">
           <p><span class="trp-primary-text-bold"><?php esc_html_e( 'Translation Strategy', 'hhg-for-translatepress' ); ?></span></p>
            <select id="hhgfotr-zhipu-model" name="trp_machine_translation_settings[hhgfotr-zhipu-model]" class="trp-select" style="width: 100%;max-width:480px;">
                <option value="general" <?php selected( $model, 'general' ); ?>><?php esc_html_e( 'General (Default) — Balanced', 'hhg-for-translatepress' ); ?></option>
                <option value="paraphrase" <?php selected( $model, 'paraphrase' ); ?>><?php esc_html_e( 'Paraphrase — More natural/colloquial', 'hhg-for-translatepress' ); ?></option>
                <option value="two_step" <?php selected( $model, 'two_step' ); ?>><?php esc_html_e( 'Two Step — Review & refine, better for technical text', 'hhg-for-translatepress' ); ?></option>
                <option value="three_step" <?php selected( $model, 'three_step' ); ?>><?php esc_html_e( 'Three Step — Deep analysis, highest quality (slower)', 'hhg-for-translatepress' ); ?></option>
                <option value="reflection" <?php selected( $model, 'reflection' ); ?>><?php esc_html_e( 'Reflection — Self-correction with reasoning', 'hhg-for-translatepress' ); ?></option>
                <option value="cot" <?php selected( $model, 'cot' ); ?>><?php esc_html_e( 'Chain of Thought — Best for nuanced content', 'hhg-for-translatepress' ); ?></option>
            </select>
        </div>

        

            <span class="trp-description-text">
               <?php echo wp_kses( __( 'Visit the <a href="https://www.bigmodel.cn/invite?icode=BOAFyzK705RHkwZsGiYl40jPr3uHog9F4g5tjuOUqno%3D" target="_blank">ZhiPu AI</a> to get your API key. Select a translation strategy — multi-step modes are more accurate but slower.', 'hhg-for-translatepress' ), [ 'a' => [ 'href' => [], 'title' => [], 'target' => [] ] ] ); ?>
            </span>
        </div>

        <?php
    }

    private function get_setting_value( $key, $settings ) {
        return isset( $settings['trp_machine_translation_settings'][$key] ) ? $settings['trp_machine_translation_settings'][$key] : '';
    }

    public function sanitize_settings( $settings, $mt_settings ) {
        if ( isset( $mt_settings['hhgfotr-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-key'] );
        } elseif ( isset( $mt_settings['hhg-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhg-gemini-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-model'] );
        } elseif ( isset( $mt_settings['hhg-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhg-gemini-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-deepseek-key'] ) ) {
            $settings['hhgfotr-deepseek-key'] = sanitize_text_field( $mt_settings['hhgfotr-deepseek-key'] );
        } elseif ( isset( $mt_settings['hhg-deepseek-key'] ) ) {
            $settings['hhgfotr-deepseek-key'] = sanitize_text_field( $mt_settings['hhg-deepseek-key'] );
        }

        if ( isset( $mt_settings['hhgfotr-deepseek-model'] ) ) {
            $settings['hhgfotr-deepseek-model'] = sanitize_text_field( $mt_settings['hhgfotr-deepseek-model'] );
        } elseif ( isset( $mt_settings['hhg-deepseek-model'] ) ) {
            $settings['hhgfotr-deepseek-model'] = sanitize_text_field( $mt_settings['hhg-deepseek-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhgfotr-openai-key'] );
        } elseif ( isset( $mt_settings['hhg-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhg-openai-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhgfotr-openai-model'] );
        } elseif ( isset( $mt_settings['hhg-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhg-openai-model'] );
        }

        if ( isset( $mt_settings['hhgfotr-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhgfotr-openai-endpoint'] );
        } elseif ( isset( $mt_settings['hhg-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhg-openai-endpoint'] );
        }

        if ( isset( $mt_settings['hhgfotr-openai-platform'] ) ) {
            $settings['hhgfotr-openai-platform'] = sanitize_text_field( $mt_settings['hhgfotr-openai-platform'] );
        } elseif ( isset( $mt_settings['hhg-openai-platform'] ) ) {
            $settings['hhgfotr-openai-platform'] = sanitize_text_field( $mt_settings['hhg-openai-platform'] );
        }

        if ( isset( $mt_settings['hhgfotr-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-key'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhg-zhipu-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-model'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhg-zhipu-model'] );
        }

        if ( isset( $mt_settings['hhgfotr-industry-prompt'] ) ) {
            $settings['hhgfotr-industry-prompt'] = sanitize_textarea_field( $mt_settings['hhgfotr-industry-prompt'] );
        }


        return $settings;
    }

    public function extend_machine_translation_keys( $settings, $mt_settings ) {
        if ( isset( $mt_settings['hhgfotr-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-key'] );
        } elseif ( isset( $mt_settings['hhg-gemini-key'] ) ) {
            $settings['hhgfotr-gemini-key'] = sanitize_text_field( $mt_settings['hhg-gemini-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhgfotr-gemini-model'] );
        } elseif ( isset( $mt_settings['hhg-gemini-model'] ) ) {
            $settings['hhgfotr-gemini-model'] = sanitize_text_field( $mt_settings['hhg-gemini-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-deepseek-key'] ) ) {
            $settings['hhgfotr-deepseek-key'] = sanitize_text_field( $mt_settings['hhgfotr-deepseek-key'] );
        } elseif ( isset( $mt_settings['hhg-deepseek-key'] ) ) {
            $settings['hhgfotr-deepseek-key'] = sanitize_text_field( $mt_settings['hhg-deepseek-key'] );
        }

        if ( isset( $mt_settings['hhgfotr-deepseek-model'] ) ) {
            $settings['hhgfotr-deepseek-model'] = sanitize_text_field( $mt_settings['hhgfotr-deepseek-model'] );
        } elseif ( isset( $mt_settings['hhg-deepseek-model'] ) ) {
            $settings['hhgfotr-deepseek-model'] = sanitize_text_field( $mt_settings['hhg-deepseek-model'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhgfotr-openai-key'] );
        } elseif ( isset( $mt_settings['hhg-openai-key'] ) ) {
            $settings['hhgfotr-openai-key'] = sanitize_text_field( $mt_settings['hhg-openai-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhgfotr-openai-model'] );
        } elseif ( isset( $mt_settings['hhg-openai-model'] ) ) {
            $settings['hhgfotr-openai-model'] = sanitize_text_field( $mt_settings['hhg-openai-model'] );
        }

        if ( isset( $mt_settings['hhgfotr-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhgfotr-openai-endpoint'] );
        } elseif ( isset( $mt_settings['hhg-openai-endpoint'] ) ) {
            $settings['hhgfotr-openai-endpoint'] = sanitize_text_field( $mt_settings['hhg-openai-endpoint'] );
        }

        if ( isset( $mt_settings['hhgfotr-openai-platform'] ) ) {
            $settings['hhgfotr-openai-platform'] = sanitize_text_field( $mt_settings['hhgfotr-openai-platform'] );
        } elseif ( isset( $mt_settings['hhg-openai-platform'] ) ) {
            $settings['hhgfotr-openai-platform'] = sanitize_text_field( $mt_settings['hhg-openai-platform'] );
        }

        if ( isset( $mt_settings['hhgfotr-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-key'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-key'] ) ) {
            $settings['hhgfotr-zhipu-key'] = sanitize_text_field( $mt_settings['hhg-zhipu-key'] );
        }
        
        if ( isset( $mt_settings['hhgfotr-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhgfotr-zhipu-model'] );
        } elseif ( isset( $mt_settings['hhg-zhipu-model'] ) ) {
            $settings['hhgfotr-zhipu-model'] = sanitize_text_field( $mt_settings['hhg-zhipu-model'] );
        }

        if ( isset( $mt_settings['hhgfotr-industry-prompt'] ) ) {
            $settings['hhgfotr-industry-prompt'] = sanitize_textarea_field( $mt_settings['hhgfotr-industry-prompt'] );
        }


        return $settings;
    }

    public function add_hhg_engines_to_list( $engines ) {
        $engines[] = array(
            'value' => 'hhgfotr_gemini',
            'label' => esc_html__( 'Google Gemini AI', 'hhg-for-translatepress' ),
        );
        
        $engines[] = array(
            'value' => 'hhgfotr_deepseek',
            'label' => esc_html__( 'DeepSeek AI', 'hhg-for-translatepress' ),
        );
        
        $engines[] = array(
            'value' => 'hhgfotr_openai',
            'label' => esc_html__( 'OpenAI Compatible (GPT / Groq / Ollama ...)', 'hhg-for-translatepress' ),
        );
        
        $engines[] = array(
            'value' => 'hhgfotr_zhipu',
            'label' => esc_html__( 'ZhiPu AI GLM', 'hhg-for-translatepress' ),
        );

        return $engines;
    }

    public function add_default_settings( $default_settings ) {
        $default_settings['hhgfotr-gemini-key'] = '';
        $default_settings['hhgfotr-gemini-model'] = 'gemini-2.5-flash';
        $default_settings['hhgfotr-deepseek-key'] = '';
        $default_settings['hhgfotr-deepseek-model'] = 'deepseek-v4-flash';
        $default_settings['hhgfotr-openai-key'] = '';
        $default_settings['hhgfotr-openai-model'] = 'gpt-4o-mini';
        $default_settings['hhgfotr-openai-endpoint'] = '';
        $default_settings['hhgfotr-openai-platform'] = 'openai';
        $default_settings['hhgfotr-zhipu-key'] = '';
        $default_settings['hhgfotr-zhipu-model'] = 'general';
        $default_settings['hhgfotr-industry-prompt'] = '';

        return $default_settings;
    }

    public function missing_translatepress_notice() {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'HHG for TranslatePress requires TranslatePress to be installed and activated.', 'hhg-for-translatepress' ) . '</p></div>';
    }

    public function handle_zhipu_test_api() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'hhgfotr_zhipu_test_nonce' ) && ! wp_verify_nonce( $nonce, 'hhg_zhipu_test_nonce' ) ) {
            wp_send_json_error( 'Security Authentication Failure' );
        }

        $model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

        $settings = array(
            'hhgfotr-zhipu-model' => $model
        );
        
        $translator = new TRP_HHGFOTR_Zhipu_Machine_Translator( $settings );

        $result = $translator->test_request();
        
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        
        $response_code = wp_remote_retrieve_response_code( $result );
        $response_body = wp_remote_retrieve_body( $result );
        
        if ( $response_code !== 200 ) {
            $error_data = json_decode( $response_body, true );
            $error_message = 'API request failed';
            
            if ( isset( $error_data['error']['message'] ) ) {
                $error_message = $error_data['error']['message'];
            } elseif ( isset( $error_data['error'] ) ) {
                $error_message = $error_data['error'];
            } elseif ( !empty( $response_body ) ) {
                $error_message = 'API Error: ' . $response_body;
            }
            
            wp_send_json_error( $error_message );
        }
        
        $response_data = json_decode( $response_body, true );
        
        if ( ! $response_data || ! isset( $response_data['choices'][0]['message']['content'] ) ) {
            wp_send_json_error( 'API response format error' );
        }
        
        wp_send_json_success( 'The API connection was successful! Model:' . $model );
    }

    public function enqueue_admin_scripts( $hook ) {

        if ( strpos( $hook, 'translatepress' ) === false && strpos( $hook, 'options-general.php' ) === false ) {
            return;
        }
        
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 
            'hhgfotr-admin-engine-switch', 
            HHGFOTR_PLUGIN_URL . 'assets/js/admin-engine-switch.js', 
            array( 'jquery' ), 
            '1.0.5',
            true
        );

        wp_enqueue_script(
            'hhgfotr-openai-model-switch',
            HHGFOTR_PLUGIN_URL . 'assets/js/openai-platform-switch.js',
            array( 'jquery' ),
            '1.0.5',
            true
        );

        wp_enqueue_style(
            'hhgfotr-admin-styles',
            HHGFOTR_PLUGIN_URL . 'assets/css/admin-styles.css',
            array(),
            '1.0.5'
        );
    }
}

HHGFOTR_TranslatePress::get_instance();
