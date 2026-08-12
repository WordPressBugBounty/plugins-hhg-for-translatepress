=== HHG for TranslatePress ===
Contributors: gwuluo, jayden778
Donate link: https://huhonggang.com/hhg-for-translatepress/
Tags: translatepress, translation, openai, gemini, deepseek
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add powerful AI translation engines to TranslatePress — Google Gemini, OpenAI, DeepSeek, and ZhiPu GLM with multi-model support.

== Description ==

HHG for TranslatePress extends TranslatePress with best-in-class AI translation engines. Pick from four engine families instead of a single provider.

**Supported Translation Engines:**

= Google Gemini AI =
Powered by Google's latest Gemini models. Choose from Flash-Lite (fastest & cheapest), Flash (balanced), Pro (highest quality), or 3 Flash Preview (cutting edge). API key from Google AI Studio.

= OpenAI Compatible Platform =
A universal engine supporting **seven** OpenAI-compatible API providers through a single, unified interface:

- **OpenAI** — GPT-4o Mini, GPT-4o, GPT-4.1, o4-mini, GPT-4 Turbo
- **Azure OpenAI** — Bring your own deployment endpoint
- **Groq** — Ultra-fast inference with generous free tier (Llama 4, Mixtral, Gemma)
- **Together AI** — Open-source model hosting (Llama 4, DeepSeek V3, Qwen3)
- **OpenRouter** — Multi-provider routing (access OpenAI, Anthropic, Google models)
- **Ollama** — Run locally, zero API cost (Llama 3.2, Qwen3, Mistral, Gemma 3)
- **Custom** — Any OpenAI-compatible endpoint you provide

Each platform preset includes curated model suggestions, platform-specific descriptions, and automatic API configuration. The model input supports both free-form text entry and one-click suggestion chips — type any model name or click to select.

= DeepSeek AI =
DeepSeek V4 Flash for fast, affordable translations and V4 Pro for professional-grade quality. Optimized with thinking disabled for faster throughput and lower cost.

= ZhiPu AI GLM =
Six translation strategies: General, Paraphrase, Two Step, Three Step, Reflection, and Chain of Thought. Choose speed or depth depending on your content.

**Key Features:**

- **Industry / Domain Description** — Describe your site's context once (industry, tone, terminology rules) and it's injected into every translation request for consistent, accurate output across all engines
- **Three-layer context system** — Sliding-window surrounding strings, page-level key term extraction, and industry prompt for terminology consistency
- **Parallel async translation** — All chunks dispatched simultaneously via cURL multi-handle for maximum speed
- **Two-round retry logic** — Failed strings automatically retried in a second pass with lower temperature for maximum completeness
- **Intelligent caching** — 30-minute transient cache per chunk for instant repeat translations
- **Adaptive chunk sizing** — Chunk size auto-adjusted based on average string length per page
- **Platform-aware configuration** — Timeout, temperature, and chunk size tuned per model family via heuristic detection
- **Native TranslatePress integration** — Settings panel styled to blend seamlessly with TranslatePress's own UI, with smooth CSS transitions
- **Code security** — All inputs sanitized, outputs escaped, nonces verified, SSL enforced, following WordPress best practices

== External Services ==

This plugin connects to external AI translation services to provide automatic translation functionality. The following third-party services are used:

**Google Gemini AI**
- Service: Google AI Studio API (https://aistudio.google.com/)
- Purpose: AI-powered text translation using Google Gemini models
- Data sent: Text content to be translated, source and target language codes
- When: Only when Gemini is selected as the translation engine and translation is requested
- Privacy Policy: https://policies.google.com/privacy
- Terms of Service: https://policies.google.com/terms

**OpenAI Compatible Platform**
- Services: OpenAI (https://api.openai.com/), Azure OpenAI, Groq (https://api.groq.com/), Together AI (https://api.together.xyz/), OpenRouter (https://openrouter.ai/), Ollama (local)
- Purpose: AI-powered text translation using various OpenAI-compatible models
- Data sent: Text content to be translated, source and target language codes
- When: Only when OpenAI Compatible is selected as the translation engine and translation is requested
- OpenAI Privacy Policy: https://openai.com/privacy/
- OpenAI Terms of Service: https://openai.com/terms/
- Groq Privacy Policy: https://groq.com/privacy/
- Together AI Privacy Policy: https://www.together.ai/privacy
- OpenRouter Privacy Policy: https://openrouter.ai/privacy

**DeepSeek AI**
- Service: DeepSeek API (https://api.deepseek.com/)
- Purpose: AI-powered text translation using DeepSeek models
- Data sent: Text content to be translated, source and target language codes
- When: Only when DeepSeek is selected as the translation engine and translation is requested
- Privacy Policy: https://platform.deepseek.com/privacy
- Terms of Service: https://platform.deepseek.com/terms

**ZhiPu AI GLM**
- Service: ZhiPu AI API (https://bigmodel.cn/)
- Purpose: AI-powered text translation using ZhiPu GLM models
- Data sent: Text content to be translated, source and target language codes
- When: Only when ZhiPu is selected as the translation engine and translation is requested
- Privacy Policy: https://www.zhipuai.cn/privacy
- Terms of Service: https://www.zhipuai.cn/terms

**Important Notes:**
- No data is sent to external services unless you explicitly configure and use one of these translation engines
- All API communications are made over secure HTTPS connections
- No personal user data is collected or transmitted — only the text content you choose to translate
- You are responsible for complying with the terms of service and privacy policies of the services you choose to use

== Installation ==

1. Make sure the [TranslatePress](https://wordpress.org/plugins/translatepress-multilingual/) plugin is installed and activated.
2. Upload the plugin to the `/wp-content/plugins/hhg-for-translatepress/` directory, or install via the WordPress plugin uploader.
3. Activate "HHG for TranslatePress" from the Plugins page.
4. Go to **Settings → TranslatePress → Automatic Translation**, select your preferred AI translation engine, configure your API key and model, and save.

== Frequently Asked Questions ==

= Which translation engine should I choose? =

- **Gemini 2.5 Flash** — Best all-around choice: fast, accurate, affordable
- **OpenAI GPT-4o Mini** — Excellent quality, widely available
- **DeepSeek V4 Flash** — Great value, very fast
- **Ollama** — Free, runs locally, no API key needed
- **Groq** — Ultra-fast with generous free tier

= Do I need the TranslatePress plugin? =

Yes. HHG for TranslatePress is an add-on that requires TranslatePress — Multilingual to be installed and activated first.

= Do I need the TranslatePress Business version? =

No. This plugin works with the free version of TranslatePress. No paid add-ons required.

= How do I get an API key? =

Visit the respective platform's website: Google AI Studio for Gemini, OpenAI Platform for GPT, DeepSeek Platform for DeepSeek, or ZhiPu AI Open Platform for GLM. Links are provided on each engine's settings panel.

= Can I use my own model or endpoint? =

Yes. The OpenAI Compatible engine includes a "Custom" platform preset where you can enter any OpenAI-compatible API endpoint and model name. The model input accepts free-form text — you are not limited to the suggestion list.

= Can I run translations locally without an API key? =

Yes. Select "OpenAI Compatible" as the engine, choose "Ollama" as the platform, and point it to your local Ollama instance. No API key or internet connection required for the translation itself.

= Is the plugin secure? =

All inputs are sanitized, all outputs are properly escaped, API keys are stored in WordPress options, nonce verification is enforced on AJAX endpoints, and all API communication uses HTTPS with SSL verification enabled. The plugin follows WordPress coding standards and security best practices.

= Will my existing translations be overwritten? =

No. The plugin respects TranslatePress's manual translation priority. Only untranslated strings are sent for automatic translation.

== Screenshots ==

1. Engine selection — choose from Google Gemini, OpenAI Compatible, DeepSeek, or ZhiPu AI
2. OpenAI Compatible platform picker — switch between OpenAI, Azure, Groq, Together AI, OpenRouter, Ollama, or Custom
3. Gemini settings with model selection
4. DeepSeek configuration
5. Industry / Domain Description card for consistent translation quality

== Changelog ==

= 1.0.5 =
* OpenAI: Redesigned as universal OpenAI-compatible platform supporting 7 presets (OpenAI, Azure, Groq, Together AI, OpenRouter, Ollama, Custom)
* OpenAI: Editable model input with datalist suggestions and clickable model chips
* OpenAI: Dynamic endpoint field that shows/hides based on platform selection
* OpenAI: Platform-aware config (timeout, chunk size, temperature) via model heuristics
* DeepSeek: Upgraded to V4 models (Flash & Pro) with thinking disabled for speed
* Gemini: Added 2.5 Flash-Lite and 3 Flash Preview models
* Industry Prompt: Redesigned as styled card, now auto-hides for non-HHG engines via JS
* Industry Prompt: Injected into every translation request for terminology consistency
* Context Builder: Three-layer context system (industry, page-level key terms, sliding window)
* All Engines: Parallel async translation via cURL multi-handle for maximum speed
* All Engines: Two-round retry logic for failed strings with lower temperature
* All Engines: 30-minute transient cache per chunk for instant repeat translations
* All Engines: Adaptive chunk sizing based on average string length
* Security: Added null-safety checks for machine_translator across all settings methods
* Security: Fixed missing logger guard in Gemini/DeepSeek async Round 1 paths
* Bug Fix: Zhipu mixed cached/uncached chunks now correctly dispatch async requests
* Bug Fix: Removed dead code (ensure_engine_switch, force_active_engine_instance)
* Internationalization: Gemini error messages now properly translatable
* Code Quality: Removed redundant Hunyuan references throughout

= 1.0.4 =
* Zhipu GLM: Fix fallback to mtapi by overriding engine class mapping and forcing availability
* Zhipu GLM: Use source_lang=auto, standardize target language codes (zh_CN→zh-CN, zh_TW→zh-TW, en_US→en, ru_RU→ru)
* Zhipu GLM: Response parser compatible with both agent and standard chat completions formats
* Zhipu GLM: Add second-pass retry for missing items in a batch
* Zhipu GLM: Cache only full-success batches to avoid partial-cache issues
* Zhipu GLM: Add HTTP/2, gzip, TCP_NODELAY optimizations
* OpenAI: Parallelize batch requests, add second-pass retry for missing items
* DeepSeek: Initial engine implementation replacing Hunyuan
* DeepSeek: Switch to ChatTranslations API with TC3-HMAC-SHA256 signature
* Stability: Fix direct write to protected TP property causing fatal error
* Stability: Fix missing includes paths for logger/cache with existence checks

= 1.0.3 =
* Fix various functions and improve compatibility
* Remove business version force loading

= 1.0.2 =
* Add ZhiPu AI GLM support with multiple translation strategies
* Fix ZhiPu API key saving
* Fix "Undefined array key" warning in front-end translation
* Optimize API call parameters with concurrency support
* Unify settings interface across all engines
* Improve error handling and API test function
* Code security hardening per WordPress standards

= 1.0.1 =
* Add OpenAI support with custom models and API Endpoint
* Optimize Gemini and Hunyuan interface and compatibility
* Fix settings saving, API testing, and batch translation issues
* Code security enhancement, remove unused files

= 1.0.0 =
* Initial release with Google Gemini and Tencent Hunyuan support
* Multi-model selection and API key configuration
* Batch translation with error handling

== Upgrade Notice ==

= 1.0.5 =
* OpenAI engine redesigned as universal platform — existing OpenAI settings are preserved
* New Industry / Domain Description field available for all HHG engines
* DeepSeek V4 models replace previous versions

== License ==

This plugin is licensed under the GPLv2 or later. You are free to use, modify, and redistribute it under the terms of the GNU General Public License.
