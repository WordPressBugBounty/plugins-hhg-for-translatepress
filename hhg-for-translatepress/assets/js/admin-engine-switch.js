jQuery(document).ready(function($) {
    var HHG_ENGINES = [
        'hhgfotr_gemini', 'hhg_gemini',
        'hhgfotr_deepseek', 'hhg_deepseek',
        'hhgfotr_openai', 'hhg_openai',
        'hhgfotr_zhipu', 'hhg_zhipu',
        'mtapi'
    ];

    function isHHGEngine(engine) {
        return HHG_ENGINES.indexOf(engine) !== -1;
    }

    function toggleIndustryPrompt(engine) {
        var $container = $('#hhgfotr-industry-prompt-container');
        if ($container.length) {
            isHHGEngine(engine) ? $container.show() : $container.hide();
        }
    }

    $('#trp-translation-engines').on('change', function() {
        var selectedEngine = $(this).val();
        $('.trp-engine').hide();

        if (selectedEngine === 'hhgfotr_gemini' || selectedEngine === 'hhg_gemini') {
            $('#hhgfotr_gemini').show();
        } else if (selectedEngine === 'hhgfotr_deepseek' || selectedEngine === 'hhg_deepseek') {
            $('#hhgfotr_deepseek').show();
        } else if (selectedEngine === 'hhgfotr_openai' || selectedEngine === 'hhg_openai') {
            $('#hhgfotr_openai').show();
            // Trigger platform refresh
            if (typeof hhgOpenAI !== 'undefined' && hhgOpenAI.refresh) {
                hhgOpenAI.refresh();
            }
        } else if (selectedEngine === 'hhgfotr_zhipu' || selectedEngine === 'hhg_zhipu') {
            $('#hhgfotr_zhipu').show();
        }

        // Show/hide Industry prompt for HHG vs non-HHG engines
        toggleIndustryPrompt(selectedEngine);
    });

    // Initial state
    var currentEngine = $('#trp-translation-engines').val();
    if (currentEngine === 'hhgfotr_gemini' || currentEngine === 'hhg_gemini') {
        $('#hhgfotr_gemini').show();
    } else if (currentEngine === 'hhgfotr_deepseek' || currentEngine === 'hhg_deepseek') {
        $('#hhgfotr_deepseek').show();
    } else if (currentEngine === 'hhgfotr_openai' || currentEngine === 'hhg_openai') {
        $('#hhgfotr_openai').show();
    } else if (currentEngine === 'hhgfotr_zhipu' || currentEngine === 'hhg_zhipu') {
        $('#hhgfotr_zhipu').show();
    }

    // Apply industry prompt visibility for initial engine
    toggleIndustryPrompt(currentEngine);
});