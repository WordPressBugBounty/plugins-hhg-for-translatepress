/**
 * OpenAI Platform Switcher — thin enhancement layer.
 *
 * Core switching logic lives in the inline <script> as hhgSwitchOpenAIPlatform().
 * This file handles cross-engine refresh + CSS transition polish.
 */
(function() {
    'use strict';

    var platformSelect, endpointRow;

    function init() {
        platformSelect = document.getElementById('hhgfotr-openai-platform');
        endpointRow    = document.getElementById('hhgfotr-openai-endpoint-row');

        if (!platformSelect) {
            setTimeout(init, 200);
            return;
        }

        // CSS transition on endpoint row
        if (endpointRow) {
            endpointRow.classList.add('hhgfotr-transition-field');
        }

        // Expose refresh for engine-switch.js
        window.hhgOpenAI = window.hhgOpenAI || {};
        window.hhgOpenAI.refresh = function() {
            if (typeof hhgSwitchOpenAIPlatform === 'function' && platformSelect) {
                hhgSwitchOpenAIPlatform(platformSelect.value);
            }
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 50);
    }
})();
