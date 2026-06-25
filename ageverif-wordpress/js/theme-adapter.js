<?php
/**
 * Theme adaptation script for AgeVerif verification gate
 * Dynamically adapts to host theme's color scheme and design system
 */

(function() {
    'use strict';

    var ageverifThemeAdapter = function() {
        // Initialize theme adaptation
        var mode = detectThemeMode();
        applyThemeStyles(mode);

        // Listen for theme changes
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    var newMode = detectThemeMode();
                    if (newMode !== mode) {
                        mode = newMode;
                        applyThemeStyles(mode);
                    }
                }
            });
        });

        // Observe the root element for class changes
        var root = document.documentElement;
        observer.observe(root, {
            attributes: true,
            attributeSelector: 'class'
        });

        // Detect theme mode using various heuristics
        function detectThemeMode() {
            // Check for standard WordPress dark mode class
            if (document.body.classList.contains('wp-dark-mode') || 
                document.body.classList.contains('is-dark')) {
                return 'dark';
            }

            // Check for dark mode media query
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                return 'dark';
            }

            // Check for theme variables possible in CSS
            var styles = getComputedStyle(document.documentElement);
            if (styles.getPropertyValue('--wp-mode') === 'dark') {
                return 'dark';
            }

            return 'light';
        }

        // Apply theme-specific styles to AgeVerif gate
        function applyThemeStyles(mode) {
            var gateElements = document.querySelectorAll('.ageverif, .ageverif-modal');
            gateElements.forEach(function(element) {
                if (element.classList.contains('ageverif-loaded')) continue;
                element.classList.add('ageverif-loaded');

                // Remove existing theme styles
                removeThemeStyles(element);

                // Apply appropriate theme class
                if (mode === 'dark') {
                    element.classList.add('ageverif-mode-dark');
                } else {
                    element.classList.add('ageverif-mode-light');
                }
            });

            // Add theme variables to document
            addThemeVariables(mode);
        }

        // Remove existing theme styles
        function removeThemeStyles(element) {
            element.classList.remove('ageverif-mode-dark', 'ageverif-mode-light');
        }

        // Add theme variables to document
        function addThemeVariables(mode) {
            // Get theme variables from computed styles
            var doc = document.documentElement;
            var computed = getComputedStyle(doc);

            // Extract common theme variables
            var bgColor = computed.getPropertyValue('--wp-color-bg');
            var textColor = computed.getPropertyValue('--wp-color-text');
            var primaryColor = computed.getPropertyValue('--wp-color-primary');
            var secondaryColor = computed.getPropertyValue('--wp-color-secondary');
            var borderRadius = computed.getPropertyValue('--wp-rborder-radius');
            var fontFamily = computed.getPropertyValue('font-family').split(',')[0];

            // Set theme variables on document
            var baseUrl = 'https://www.ageverif.com';
            var varCss = document.createElement('style');
            varCss.type = 'text/css';
            varCss.innerHTML = `
                :root {
                    --ageverif-bg-color: ${bgColor};
                    --ageverif-text-color: ${textColor};
                    --ageverif-primary-color: ${primaryColor};
                    --ageverif-secondary-color: ${secondaryColor};
                    --ageverif-border-radius: ${borderRadius};
                }
                .ageverif-mode-dark .ageverif-modal {
                    background-color: var(--ageverif-bg-color, ${bgColor});
                    color: var(--ageverif-text-color, ${textColor});
                    border-radius: var(--ageverif-border-radius, ${borderRadius});
                }
                .ageverif-mode-light .ageverif-modal {
                    background-color: var(--ageverif-bg-color, ${bgColor});
                    color: var(--ageverif-text-color, ${textColor});
                    border-radius: var(--ageverif-border-radius, ${borderRadius});
                }
            `;

            document.head.appendChild(varCss);
        }

        // Initialize the adapter
        ageverifThemeAdapter.init = function() {
            ageverifThemeAdapter();
        };

        // Make available globally
        if (window.ageverif && window.ageverif.themeAdapter) {
            window.ageverif.themeAdapter = {
                init: ageverifThemeAdapter.init
            };
        }
    };

    // Add script tag for theme detection
    var script = document.createElement('script');
    script.type = 'text/javascript';
    script.textContent = 'ageverifThemeAdapter();';
    document.head.appendChild(script);
})();