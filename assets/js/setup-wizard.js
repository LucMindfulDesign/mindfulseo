/**
 * Setup Wizard JavaScript
 * 
 * 4-step onboarding: Connect AI -> Site Profile -> Analyze Content -> Quick Optimize
 */

(function($) {
    'use strict';
    
    const MindfulSEOWizard = {
        currentStep: 1,
        totalSteps: 4,
        
        init: function() {
            if ($('.mfseo-wizard-page').length === 0) {
                return;
            }
            this.bindEvents();
            if ($('#wizard-step-3').length) {
                this.syncStep3SavedPanels();
                this.syncWizardUseSavedUI();
            }
            this.bindFormatExampleModal();
            if ($('#mfseo_wizard_ai').length) {
                this.syncWizardStep1AiPanels();
            }
        },

        bindFormatExampleModal: function() {
            var self = this;
            $(document).on('click', '.mfseo-wizard-format-example-btn', function(e) {
                e.preventDefault();
                self.openWizardFormatModal($(this));
            });
            $(document).on('click', '#wizard-format-modal', function(e) {
                if (e.target === e.currentTarget) {
                    self.closeWizardFormatModal();
                }
            });
            $(document).on('click', '#wizard-format-modal-close', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.closeWizardFormatModal();
            });
            $(document).on('keydown.mfseoWizardFormat', function(e) {
                if (e.keyCode === 27 && !$('#wizard-format-modal').prop('hidden')) {
                    self.closeWizardFormatModal();
                }
            });
            self.closeWizardFormatModal();
        },

        openWizardFormatModal: function($btn) {
            /* Use attr('data-format') — jQuery .data() can cache stale values; clone nodes instead of .html() for reliability when templates sit in hidden containers. */
            var fmt = ( $btn.attr('data-format') || '' ).toString().toLowerCase().trim();
            var title = $btn.attr('data-modal-title') || '';
            var $body = $('#wizard-format-modal-body');
            var $src = fmt === 'keywords' ? $('#wizard-format-content-keywords') : ( fmt === 'guidelines' ? $('#wizard-format-content-guidelines') : $() );
            if ( !$src.length ) {
                return;
            }
            $body.empty();
            $src.contents().clone(true, true).appendTo($body);
            if ( !$body.text().trim() ) {
                var fallback = ( typeof mfseoWizard !== 'undefined' && mfseoWizard.strings && mfseoWizard.strings.formatExampleMissing )
                    ? mfseoWizard.strings.formatExampleMissing
                    : 'Example content could not be loaded.';
                $body.html('<p class="mfseo-wizard-format-modal-fallback" style="margin:0;color:#64748b;">' + $('<span>').text(fallback).html() + '</p>');
            }
            $('#wizard-format-modal-title').text(title);
            $('#wizard-format-modal').prop('hidden', false);
            $('body').addClass('mfseo-wizard-modal-open');
        },

        closeWizardFormatModal: function() {
            $('#wizard-format-modal').prop('hidden', true);
            $('body').removeClass('mfseo-wizard-modal-open');
        },
        
        bindEvents: function() {
            const self = this;
            
            // Step 1 -> 2
            $('#wizard-step-1-next').on('click', function() { self.goToStep(2); });
            // Step 2
            $('#wizard-step-2-back').on('click', function() { self.goToStep(1); });
            $('#wizard-step-2-next').on('click', function() { self.goToStep(3); });
            // Step 3 (Analyze Content) — delegated (completion mode swaps visible controls)
            $(document).on('click', '#wizard-step-3-back', function() { self.goToStep(2); });
            $(document).on('click', '#wizard-step-3-analyze', function() { self.runAnalysis(); });
            $(document).on('click', '#wizard-step-3-skip', function() { self.goToStep(4); });
            $(document).on('click', '#wizard-step-3-continue-final', function() { self.goToStep(4); });
            $(document).on('change', '#wizard-use-saved', function() {
                if (!$(this).prop('disabled')) {
                    $(this).data('mfseoUserToggled', true);
                }
                self.syncWizardUseSavedUI();
            });
            $('#wizard-csv-file').on('change', function() {
                $('#wizard-import-csv').prop('disabled', !this.files.length);
            });
            $('#wizard-import-csv').on('click', function() { self.importCSV(); });
            $('#wizard-guidelines-csv-file').on('change', function() {
                $('#wizard-import-guidelines-csv').prop('disabled', !this.files.length);
            });
            $('#wizard-import-guidelines-csv').on('click', function() { self.importGuidelinesCSV(); });
            // Step 4 (Quick Optimize)
            $('#wizard-step-4-back').on('click', function() { self.goToStep(3); });
            $('#wizard-step-4-skip').on('click', function() { self.completeWizard(); });
            
            // Step 1: AI connection dropdown (delegated + namespaced)
            $(document).off('change.mfseoStep1Ai', '#mfseo_wizard_ai').on('change.mfseoStep1Ai', '#mfseo_wizard_ai', function() {
                self.syncWizardStep1AiPanels();
            });
            
            // Site type toggle
            $('input[name="site_type"]').on('change', function() {
                $('.mfseo-wizard-type-card').removeClass('active');
                $(this).closest('.mfseo-wizard-type-card').addClass('active');
            });
            
            // API test
            $('.test-api-btn').on('click', function() {
                self.testAPI($(this));
            });
            
            // Optimize (now step 4)
            $('#wizard-step-4-optimize').on('click', function() {
                self.runFirstOptimization();
            });
            
            // Finish button on success screen
            $('#wizard-finish-btn').on('click', function() {
                self.completeWizard();
            });
            
            // View Optimized Posts completes wizard then navigates
            $('#wizard-view-posts-btn').on('click', function(e) {
                e.preventDefault();
                var href = $(this).attr('href');
                self.completeWizardThen(href);
            });
            
            // Dismiss link
            $('#wizard-dismiss-link').on('click', function(e) {
                e.preventDefault();
                self.dismissWizard();
            });
            
            // Dismiss from admin notice
            $('.mfseo-dismiss-wizard').on('click', function() {
                self.dismissWizard();
            });
        },

        syncWizardStep1AiPanels: function() {
            var sel = document.getElementById('mfseo_wizard_ai');
            var step1 = document.getElementById('wizard-step-1');
            if (!sel || !step1) {
                return;
            }
            var mode = sel.value || 'openai';
            if (mode !== 'openai' && mode !== 'claude' && mode !== 'openrouter') {
                mode = 'openai';
            }
            step1.setAttribute('data-ai-panel', mode);
        },
        
        goToStep: function(step) {
            if (step < 1 || step > this.totalSteps) {
                return;
            }
            
            var self = this;
            var fromStep = this.currentStep;
            
            this.saveStepData(fromStep, function() {
                $('.mfseo-wizard-step').hide();
                $('#wizard-step-' + step).fadeIn(250);
                
                self.currentStep = step;
                
                $('.mfseo-wizard-step-dot').each(function() {
                    var dotStep = parseInt($(this).data('step'));
                    $(this).toggleClass('active', dotStep <= step);
                    $(this).toggleClass('completed', dotStep < step);
                });
                $('.mfseo-wizard-step-line').each(function(i) {
                    $(this).toggleClass('active', (i + 1) < step);
                });
                
                if (step === 3 && fromStep === 2) {
                    self.step3ExitCompletionMode();
                    $('#wizard-analyze-progress').hide();
                    $('#wizard-analyze-info').show();
                    $('#wizard-analyze-results').hide();
                    $('.mfseo-wizard-saved-summary').show();
                    self.syncStep3SavedPanels();
                    var $an = $('#wizard-step-3-analyze');
                    $an.prop('disabled', $an.attr('data-analyze-enabled') !== '1');
                }
            });
        },
        
        saveStepData: function(step, callback) {
            let data = {};
            
            if (step === 1) {
                var mode = $('#mfseo_wizard_ai').val() || 'openai';
                data.ai_backend = (mode === 'openrouter') ? 'openrouter' : 'direct';
                data.ai_provider = (mode === 'openrouter') ? 'openai' : mode;
                data.openai_api_key = $('input[name="openai_api_key"]').val() || '';
                data.claude_api_key = $('input[name="claude_api_key"]').val() || '';
                data.openrouter_api_key = $('input[name="openrouter_api_key"]').val() || '';
                data.openrouter_model = $('#wizard-openrouter-model').val() || '';
            } else if (step === 2) {
                data.site_type = $('input[name="site_type"]:checked').val() || '';
            }
            
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'mfseo_wizard_save_step',
                    nonce: mfseoWizard.nonce,
                    step: step,
                    data: data
                },
                success: function() {
                    if (callback) callback();
                },
                error: function() {
                    if (callback) callback();
                }
            });
        },
        
        testAPI: function($btn) {
            const provider = $btn.data('provider');
            const $result = $btn.siblings('.api-test-result');
            var apiKey;
            if (provider === 'openrouter') {
                apiKey = $('input[name="openrouter_api_key"]').val();
            } else {
                apiKey = $btn.siblings('label').first().find('input').val();
            }
            
            if (!apiKey) {
                $result.html('<p class="error">Please enter your API key first</p>');
                return;
            }
            
            $btn.prop('disabled', true).text('Testing...');
            $result.html('<p class="info">Testing connection...</p>');

            var ajaxData = {
                    action: 'mfseo_wizard_test_api',
                    nonce: mfseoWizard.nonce,
                    provider: provider,
                    api_key: apiKey
            };
            if (provider === 'openrouter') {
                ajaxData.model = $('#wizard-openrouter-model').val() || '';
            }
            
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                method: 'POST',
                timeout: 30000,
                data: ajaxData,
                success: function(response) {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Test Connection');
                    if (response.success) {
                        $result.html('<p class="success">' + response.data.message + '</p>');
                    } else {
                        var err = response.data;
                        if (err && typeof err === 'object' && err.message) {
                            err = err.message;
                        }
                        $result.html('<p class="error">' + err + '</p>');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Test Connection');
                    $result.html('<p class="error">Connection failed. Please check your API key.</p>');
                }
            });
        },
        
        // ── Step 3: Analyze Content ──
        
        importCSV: function() {
            var self = this;
            var fileInput = document.getElementById('wizard-csv-file');
            if (!fileInput.files.length) return;
            
            var $btn = $('#wizard-import-csv');
            var $status = $('#wizard-import-status');
            $btn.prop('disabled', true).text('Importing...');
            $status.text('').css('color', '#666');
            
            var formData = new FormData();
            formData.append('action', 'mfseo_wizard_import_csv');
            formData.append('nonce', mfseoWizard.nonce);
            formData.append('csv_file', fileInput.files[0]);
            
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 180000,
                success: function(response) {
                    if (response.success) {
                        var d = response.data;
                        var msg = d.message || ('Imported ' + d.imported + ' keywords' + (d.skipped ? ' (' + d.skipped + ' skipped)' : ''));
                        $status.css('color', d.imported > 0 ? '#46b450' : '#dc3232').text(msg);
                        if (typeof d.keywords_total === 'number' && typeof d.guidelines_total === 'number') {
                            self.updateWizardSavedCounts(d.keywords_total, d.guidelines_total);
                        }
                        fileInput.value = '';
                    } else {
                        $status.css('color', '#dc3232').text('Error: ' + (response.data || 'Import failed'));
                    }
                },
                error: function() {
                    $status.css('color', '#dc3232').text('Connection error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Import');
                }
            });
        },
        
        importGuidelinesCSV: function() {
            var self = this;
            var fileInput = document.getElementById('wizard-guidelines-csv-file');
            if (!fileInput.files.length) return;
            
            var $btn = $('#wizard-import-guidelines-csv');
            var $status = $('#wizard-import-guidelines-status');
            $btn.prop('disabled', true).text('Importing...');
            $status.text('').css('color', '#666');
            
            var formData = new FormData();
            formData.append('action', 'mfseo_wizard_import_guidelines_csv');
            formData.append('nonce', mfseoWizard.nonce);
            formData.append('csv_file', fileInput.files[0]);
            
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 180000,
                success: function(response) {
                    if (response.success) {
                        var d = response.data;
                        var fmt = d.format ? ' (' + d.format + ')' : '';
                        $status.css('color', d.imported > 0 ? '#46b450' : '#dc3232')
                            .text('Imported ' + d.imported + ' guidelines' + (d.skipped ? ', ' + d.skipped + ' skipped' : '') + fmt);
                        if (typeof d.keywords_total === 'number' && typeof d.guidelines_total === 'number') {
                            self.updateWizardSavedCounts(d.keywords_total, d.guidelines_total);
                        }
                        fileInput.value = '';
                    } else {
                        $status.css('color', '#dc3232').text('Error: ' + (response.data || 'Import failed'));
                    }
                },
                error: function() {
                    $status.css('color', '#dc3232').text('Connection error');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Import');
                }
            });
        },

        /**
         * If user chose files but did not click Import, save them before Analyze (same DB writes as Import buttons).
         */
        preflightStep3Imports: function() {
            var self = this;
            var deferred = $.Deferred();
            var kwInput = document.getElementById('wizard-csv-file');
            var glInput = document.getElementById('wizard-guidelines-csv-file');

            function postFile(action, file) {
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', mfseoWizard.nonce);
                fd.append('csv_file', file);
                return $.ajax({
                    url: mfseoWizard.ajaxUrl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    timeout: 180000
                });
            }

            function failMsg(res) {
                if (!res) {
                    return 'Import failed.';
                }
                if (typeof res.data === 'string') {
                    return res.data;
                }
                return 'Import failed.';
            }

            function chainGl() {
                if (!glInput.files.length) {
                    deferred.resolve();
                    return;
                }
                var $st = $('#wizard-import-guidelines-status');
                postFile('mfseo_wizard_import_guidelines_csv', glInput.files[0])
                    .done(function(res) {
                        if (res.success) {
                            var d = res.data;
                            if (typeof d.keywords_total === 'number' && typeof d.guidelines_total === 'number') {
                                self.updateWizardSavedCounts(d.keywords_total, d.guidelines_total);
                            }
                            var fmt = d.format ? ' (' + d.format + ')' : '';
                            $st.css('color', d.imported > 0 ? '#46b450' : '#dc3232')
                                .text('Saved ' + d.imported + ' guidelines' + (d.skipped ? ', ' + d.skipped + ' skipped' : '') + fmt);
                            glInput.value = '';
                            deferred.resolve();
                        } else {
                            deferred.reject(failMsg(res));
                        }
                    })
                    .fail(function() {
                        deferred.reject('Guidelines import: connection error.');
                    });
            }

            function chainKw() {
                if (!kwInput.files.length) {
                    chainGl();
                    return;
                }
                var $st = $('#wizard-import-status');
                postFile('mfseo_wizard_import_csv', kwInput.files[0])
                    .done(function(res) {
                        if (res.success) {
                            var d = res.data;
                            if (typeof d.keywords_total === 'number' && typeof d.guidelines_total === 'number') {
                                self.updateWizardSavedCounts(d.keywords_total, d.guidelines_total);
                            }
                            var msg = d.message || ('Saved ' + d.imported + ' keywords');
                            $st.css('color', d.imported > 0 ? '#46b450' : '#dc3232').text(msg);
                            kwInput.value = '';
                            chainGl();
                        } else {
                            deferred.reject(failMsg(res));
                        }
                    })
                    .fail(function() {
                        deferred.reject('Keyword import: connection error.');
                    });
            }

            chainKw();
            return deferred.promise();
        },
        
        clearStep3Warnings: function() {
            $('#wizard-step-3 .mfseo-wizard-step3-warning').remove();
        },
        
        setStep3ResultLabels: function(mode) {
            var s = mfseoWizard.strings || {};
            if (mode === 'saved') {
                $('#wizard-result-heading').text(s.resultHeadingSaved || 'Using your saved keyword strategy & guidelines');
                $('#wizard-kw-count-label').text(' ' + (s.kwLabelSaved || 'keywords in your strategy'));
                $('#wizard-gl-count-label').text(' ' + (s.glLabelSaved || 'guidelines in use'));
            } else {
                $('#wizard-result-heading').text(s.resultHeadingAI || 'Content analysis complete!');
                $('#wizard-kw-count-label').text(' ' + (s.kwLabelGenerated || 'keywords generated'));
                $('#wizard-gl-count-label').text(' ' + (s.glLabelCreated || 'guidelines created'));
            }
        },
        
        hasPrestartStrategy: function() {
            return $('#wizard-step-3').attr('data-prestart-strategy') === '1';
        },

        updateWizardSavedCounts: function(kw, gl) {
            var $m = $('#wizard-saved-meta');
            if (!$m.length) {
                return;
            }
            kw = parseInt(kw, 10) || 0;
            gl = parseInt(gl, 10) || 0;
            $m.attr('data-kw', kw).attr('data-gl', gl);
            var prestart = this.hasPrestartStrategy();
            var s = (typeof mfseoWizard !== 'undefined' && mfseoWizard.strings) ? mfseoWizard.strings : {};
            var $line = $('#wizard-saved-summary-line');
            if (prestart && $line.length) {
                if (kw + gl > 0) {
                    var tmpl = s.savedSummaryLine || 'You have %1$s keywords and %2$s language guidelines saved in MindfulSEO.';
                    $line.removeClass('mfseo-wizard-saved-summary-intro').text(
                        tmpl.replace('%1$s', kw.toLocaleString()).replace('%2$s', gl.toLocaleString())
                    );
                } else {
                    $line.addClass('mfseo-wizard-saved-summary-intro').text(
                        s.savedSummaryEmpty || 'No keywords or guidelines in MindfulSEO yet — import files below, then run analysis (or analyze first).'
                    );
                }
            }
            if (prestart) {
                if (kw + gl > 0) {
                    var $t = $('#wizard-use-saved');
                    $t.prop('disabled', false);
                    if (!$t.prop('checked') && $t.data('mfseoUserToggled') !== true) {
                        $t.prop('checked', true);
                    }
                } else {
                    $('#wizard-use-saved').prop('disabled', true).prop('checked', false).removeData('mfseoUserToggled');
                }
            }
            this.syncStep3SavedPanels();
            this.syncWizardUseSavedUI();
        },
        
        getWizardSavedCounts: function() {
            var $m = $('#wizard-saved-meta');
            if (!$m.length) {
                return { kw: 0, gl: 0 };
            }
            var kw = parseInt($m.attr('data-kw'), 10);
            var gl = parseInt($m.attr('data-gl'), 10);
            if (isNaN(kw)) kw = 0;
            if (isNaN(gl)) gl = 0;
            return { kw: kw, gl: gl };
        },

        syncStep3SavedPanels: function() {
            if (!this.hasPrestartStrategy()) {
                return;
            }
            var c = this.getWizardSavedCounts();
            var has = (c.kw + c.gl) > 0;
            var $full = $('#wizard-saved-full-controls');
            if ($full.length) {
                $full.toggle(has);
            }
        },
        
        syncWizardUseSavedUI: function() {
            var $step = $('#wizard-step-3');
            if (!$step.length || !this.hasPrestartStrategy()) {
                return;
            }
            var $toggle = $('#wizard-use-saved');
            if (!$toggle.length) {
                return;
            }
            var $regen = $('#wizard-regenerate-options');
            var counts = this.getWizardSavedCounts();
            var hasSaved = (counts.kw + counts.gl) > 0;
            var useSaved = $toggle.is(':checked');

            if (!hasSaved) {
                $toggle.prop('disabled', true).prop('checked', false);
                if ($regen.length) {
                    $regen.removeClass('is-disabled');
                    $('#wizard-regenerate-keywords, #wizard-regenerate-guidelines').prop('disabled', true).prop('checked', true);
                }
            } else {
                $toggle.prop('disabled', false);
                if ($regen.length) {
                    if (useSaved) {
                        $('#wizard-regenerate-keywords, #wizard-regenerate-guidelines').prop('checked', true).prop('disabled', true);
                        $regen.addClass('is-disabled');
                    } else {
                        $regen.removeClass('is-disabled');
                        $('#wizard-regenerate-keywords, #wizard-regenerate-guidelines').prop('disabled', false);
                    }
                }
            }

            $('#wizard-step-3-analyze').show();

            $toggle.attr('aria-checked', useSaved ? 'true' : 'false');
        },
        
        step3EnterCompletionMode: function() {
            $('#wizard-step-3-main-actions').hide();
            $('#wizard-step-3-skip').hide();
            var label = (mfseoWizard.strings && mfseoWizard.strings.continueToNext) ? mfseoWizard.strings.continueToNext : 'Continue →';
            $('#wizard-step-3-continue-final').text(label).show();
        },
        
        step3ExitCompletionMode: function() {
            $('#wizard-step-3-continue-final').hide();
            $('#wizard-step-3-main-actions').show();
            $('#wizard-step-3-skip').show();
        },
        
        runAnalysis: function() {
            var self = this;
            var $btn = $('#wizard-step-3-analyze');
            var $progress = $('#wizard-analyze-progress');
            var $info = $('#wizard-analyze-info');
            var $actions = $('#wizard-step-3-actions');
            var $results = $('#wizard-analyze-results');
            var $barFill = $('#wizard-analyze-bar-fill');
            var $status = $('#wizard-analyze-status');
            var $toggle = $('#wizard-use-saved');
            var useSaved = $toggle.length && $toggle.is(':checked');
            var counts = self.getWizardSavedCounts();
            var hasSaved = (counts.kw + counts.gl) > 0;
            var forceFullImprove = useSaved && hasSaved;

            var $rk = $('#wizard-regenerate-keywords');
            var $rg = $('#wizard-regenerate-guidelines');
            var regenKw = forceFullImprove || ($rk.length ? $rk.is(':checked') : true);
            var regenGl = forceFullImprove || ($rg.length ? $rg.is(':checked') : true);
            if (!regenKw && !regenGl) {
                var msg = (mfseoWizard.strings && mfseoWizard.strings.selectRegenerateArea)
                    ? mfseoWizard.strings.selectRegenerateArea
                    : 'Choose at least one area to regenerate.';
                alert(msg);
                return;
            }

            var kwInputEl = document.getElementById('wizard-csv-file');
            var glInputEl = document.getElementById('wizard-guidelines-csv-file');
            var pendingFiles = (kwInputEl && kwInputEl.files.length > 0) || (glInputEl && glInputEl.files.length > 0);

            function runAnalyzeAjax() {
            self.step3ExitCompletionMode();
            self.clearStep3Warnings();
            self.setStep3ResultLabels('ai');
            $results.hide();
            
            $btn.prop('disabled', true);
            $info.hide();
            $('.mfseo-wizard-saved-summary').hide();
            $('#wizard-saved-full-controls').hide();
            $actions.hide();
            $progress.show();
            
            var deepAnalysisChk = $('#wizard-deep-analysis').is(':checked');
            var deepAnalysis = deepAnalysisChk ? 1 : 0;
            // Expected server time (ms): one AI-heavy request; asymptotic bar never “stalls” mid-way on purpose.
            var estMs = deepAnalysisChk ? 200000 : 80000;
            if (regenKw && !regenGl) {
                estMs *= 0.55;
            } else if (!regenKw && regenGl) {
                estMs *= 0.5;
            }
            estMs = Math.max(45000, estMs);
            var progressStartMs = Date.now();
            var progressInterval = setInterval(function() {
                var elapsed = Date.now() - progressStartMs;
                var tau = estMs * 0.42;
                var asympt = 96 * (1 - Math.exp(-elapsed / tau));
                var pct = Math.min(94, Math.max(3, asympt));
                $barFill.css('width', pct + '%');
                var phase = elapsed / estMs;
                if (phase < 0.1) {
                    $status.text('Scanning all published content...');
                } else if (phase < 0.22) {
                    $status.text('Extracting names, entities & terminology...');
                } else if (phase < 0.48) {
                    if (regenKw) {
                        $status.text('AI is generating keyword strategy...');
                    } else {
                        $status.text('Preparing guideline context...');
                    }
                } else if (phase < 0.72) {
                    if (regenKw && regenGl) {
                        $status.text('Finishing keywords; starting language guidelines...');
                    } else if (regenGl) {
                        $status.text('Detecting terminology & editorial patterns...');
                    } else {
                        $status.text('Almost done with keywords...');
                    }
                } else {
                    if (regenGl) {
                        $status.text('Building language guidelines (this step is often the longest)...');
                    } else {
                        $status.text('Finalizing...');
                    }
                }
            }, 450);
            
            var regenKwPost = regenKw ? 1 : 0;
            var regenGlPost = regenGl ? 1 : 0;
            var useSavedContextPost = forceFullImprove ? 1 : 0;
            
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                method: 'POST',
                timeout: deepAnalysis ? 600000 : 300000,
                data: {
                    action: 'mfseo_wizard_analyze_content',
                    nonce: mfseoWizard.nonce,
                    deep_analysis: deepAnalysis,
                    regenerate_keywords: regenKwPost,
                    regenerate_guidelines: regenGlPost,
                    use_saved_context: useSavedContextPost
                },
                success: function(response) {
                    clearInterval(progressInterval);
                    $barFill.css('width', '100%');
                    
                    if (response.success) {
                        self.setStep3ResultLabels('ai');
                        $('#wizard-kw-count').text(response.data.keywords_count);
                        $('#wizard-gl-count').text(response.data.guidelines_count);
                        if (typeof response.data.keywords_total === 'number' && typeof response.data.guidelines_total === 'number') {
                            self.updateWizardSavedCounts(response.data.keywords_total, response.data.guidelines_total);
                        }
                        
                        var warningHtml = '';
                        if (response.data.errors && response.data.errors.length > 0) {
                            warningHtml = '<div class="mfseo-wizard-step3-warning" style="background:#fff3cd;border:1px solid #ffc107;padding:10px;border-radius:6px;margin-bottom:12px;font-size:13px;">' +
                                '<strong>Warnings:</strong><ul style="margin:5px 0 0 15px;">';
                            response.data.errors.forEach(function(err) {
                                warningHtml += '<li>' + err + '</li>';
                            });
                            warningHtml += '</ul></div>';
                        }
                        
                        setTimeout(function() {
                            $progress.hide();
                            if (warningHtml) {
                                $results.before(warningHtml);
                            }
                            var hasErr = response.data.errors && response.data.errors.length > 0;
                            var $icon = $('#wizard-analyze-result-icon');
                            var issuesHeading = (mfseoWizard.strings && mfseoWizard.strings.resultHeadingIssues)
                                ? mfseoWizard.strings.resultHeadingIssues
                                : 'Analysis finished with issues';
                            var issuesNote = (mfseoWizard.strings && mfseoWizard.strings.analyzeNoteIssues)
                                ? mfseoWizard.strings.analyzeNoteIssues
                                : '';
                            var okHeading = (mfseoWizard.strings && mfseoWizard.strings.resultHeadingAI)
                                ? mfseoWizard.strings.resultHeadingAI
                                : 'Content analysis complete!';
                            if (hasErr) {
                                $('#wizard-result-heading').text(issuesHeading);
                                if ($('#wizard-analyze-result-note').length && issuesNote) {
                                    $('#wizard-analyze-result-note').text(issuesNote);
                                }
                                $icon.removeClass('mfseo-wizard-analyze-result-icon--success').addClass('mfseo-wizard-analyze-result-icon--warning');
                                $icon.find('.dashicons').removeClass('dashicons-yes-alt').addClass('dashicons-warning');
                            } else {
                                $('#wizard-result-heading').text(okHeading);
                                var defaultNote = (mfseoWizard.strings && mfseoWizard.strings.analyzeNoteDefault)
                                    ? mfseoWizard.strings.analyzeNoteDefault
                                    : 'You can review and edit these anytime from the Keyword Strategy and Language Guidelines pages.';
                                var wp = response.data.wizard_preservation;
                                if (wp && (wp.keywords_protected > 0 || wp.guidelines_protected > 0)) {
                                    var s = mfseoWizard.strings || {};
                                    var sum = s.wizardPreservationSummary
                                        ? s.wizardPreservationSummary
                                            .replace('%1$s', String(wp.keywords_protected))
                                            .replace('%2$s', String(wp.guidelines_protected))
                                        : ('Protected your imports: ' + wp.keywords_protected + ' keyword row(s) and ' + wp.guidelines_protected + ' guideline row(s) were kept separate from AI additions.');
                                    defaultNote = sum + ' ' + defaultNote;
                                    var rk = wp.keywords_restored || 0;
                                    var rg = wp.guidelines_restored || 0;
                                    if (rk > 0 || rg > 0) {
                                        var rs = s.wizardPreservationRestored
                                            ? s.wizardPreservationRestored.replace('%1$s', String(rk)).replace('%2$s', String(rg))
                                            : (' Re-synced ' + rk + ' keyword and ' + rg + ' guideline row(s).');
                                        defaultNote += rs;
                                    }
                                }
                                if ($('#wizard-analyze-result-note').length) {
                                    $('#wizard-analyze-result-note').text(defaultNote);
                                }
                                $icon.removeClass('mfseo-wizard-analyze-result-icon--warning').addClass('mfseo-wizard-analyze-result-icon--success');
                                $icon.find('.dashicons').removeClass('dashicons-warning').addClass('dashicons-yes-alt');
                            }
                            $('.mfseo-wizard-saved-summary').show();
                            self.syncStep3SavedPanels();
                            $results.show();
                            self.step3EnterCompletionMode();
                            $actions.show();
                        }, 500);
                    } else {
                        $progress.hide();
                        $info.show();
                        $('.mfseo-wizard-saved-summary').show();
                        self.syncStep3SavedPanels();
                        $actions.show();
                        $btn.prop('disabled', false);
                        alert('Analysis failed: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    clearInterval(progressInterval);
                    $progress.hide();
                    $info.show();
                    $('.mfseo-wizard-saved-summary').show();
                    self.syncStep3SavedPanels();
                    $actions.show();
                    $btn.prop('disabled', false);
                    alert('Connection error. Please try again.');
                }
            });
            }

            if (pendingFiles) {
                $btn.prop('disabled', true);
                $info.hide();
                $actions.hide();
                $progress.show();
                $status.text('Saving imported keyword/guideline files to the database...');
                $barFill.css('width', '5%');
                self.preflightStep3Imports().done(function() {
                    $progress.hide();
                    $status.text('');
                    $barFill.css('width', '0%');
                    runAnalyzeAjax();
                }).fail(function(err) {
                    $progress.hide();
                    $info.show();
                    $actions.show();
                    $btn.prop('disabled', false);
                    $('.mfseo-wizard-saved-summary').show();
                    self.syncStep3SavedPanels();
                    alert(err || 'Import failed.');
                });
                return;
            }

            runAnalyzeAjax();
        },
        
        // ── Step 4: Quick Optimize ──
        
        runFirstOptimization: function() {
            const self = this;
            self._optQueue = [];
            self._optResults = [];
            self._optCompleted = 0;
            self._optActive = 0;
            self._optNextIndex = 0;
            
            $('input[name="wizard_posts[]"]:checked').each(function() {
                self._optQueue.push(parseInt($(this).val()));
            });
            
            if (self._optQueue.length === 0) {
                alert(mfseoWizard.strings.selectAtLeastOne);
                return;
            }
            
            $('#wizard-posts-selection').hide();
            $('#wizard-step-4-actions').hide();
            $('#wizard-opt-progress').show();
            
            self._fillOptSlots();
        },
        
        _fillOptSlots: function() {
            var self = this;
            var CONCURRENCY = 2;
            
            while (self._optActive < CONCURRENCY && self._optNextIndex < self._optQueue.length) {
                var postId = self._optQueue[self._optNextIndex];
                self._optNextIndex++;
                self._optActive++;
                self._optimizeSingle(postId);
            }
            
            if (self._optActive === 0 && self._optNextIndex >= self._optQueue.length) {
                self.showOptimizationResults({ results: self._optResults, total: self._optQueue.length });
            }
        },
        
        _optimizeSingle: function(postId) {
            var self = this;
            var total = self._optQueue.length;
            
            $('#wizard-opt-current').text(
                mfseoWizard.strings.optimizingPost
                    .replace('%1$d', Math.min(self._optCompleted + self._optActive, total))
                    .replace('%2$d', total)
            );
            
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                method: 'POST',
                timeout: 120000,
                data: {
                    action: 'mindfulseo_batch_optimize_single',
                    nonce: mfseoWizard.batchNonce || mfseoWizard.nonce,
                    post_id: postId
                },
                success: function(response) {
                    if (response.success) {
                        self._optResults.push({
                            post_id: postId,
                            success: true,
                            title: response.data.post_title || 'Untitled',
                            edit_link: mfseoWizard.adminUrl + 'post.php?post=' + postId + '&action=edit'
                        });
                    } else {
                        self._optResults.push({
                            post_id: postId,
                            success: false,
                            error: response.data || 'Optimization failed'
                        });
                    }
                    self._advanceOpt();
                },
                error: function(xhr, status) {
                    self._optResults.push({
                        post_id: postId,
                        success: false,
                        error: status === 'timeout' ? 'Request timed out' : 'Connection error'
                    });
                    self._advanceOpt();
                }
            });
        },
        
        _advanceOpt: function() {
            var self = this;
            self._optCompleted++;
            self._optActive--;
            var progress = Math.round((self._optCompleted / self._optQueue.length) * 100);
            $('#wizard-opt-bar-fill').css('width', progress + '%');
            setTimeout(function() { self._fillOptSlots(); }, 200);
        },
        
        showOptimizationResults: function(data) {
            let html = '';
            const self = this;
            
            data.results.forEach(function(result) {
                if (result.success) {
                    html += '<div class="mfseo-wizard-result-item mfseo-wizard-result-item--success">' +
                        '<span class="dashicons dashicons-yes-alt"></span>' +
                        '<strong>' + self.escapeHtml(result.title) + '</strong>' +
                        '<a href="' + result.edit_link + '" target="_blank" class="mfseo-wizard-result-link">Edit</a>' +
                        '</div>';
                } else {
                    html += '<div class="mfseo-wizard-result-item mfseo-wizard-result-item--error">' +
                        '<span class="dashicons dashicons-warning"></span>' +
                        '<span>' + self.escapeHtml(result.error) + '</span>' +
                        '</div>';
                }
            });
            
            $('#wizard-results').html(html);
            $('#wizard-opt-progress').hide();
            $('#wizard-opt-success').show();
        },
        
        completeWizard: function() {
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'mfseo_wizard_complete',
                    nonce: mfseoWizard.nonce
                },
                success: function(response) {
                    if (response.success && response.data.redirect) {
                        window.location.href = response.data.redirect;
                    }
                }
            });
        },
        
        completeWizardThen: function(redirectUrl) {
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'mfseo_wizard_complete',
                    nonce: mfseoWizard.nonce
                },
                complete: function() {
                    window.location.href = redirectUrl;
                }
            });
        },
        
        dismissWizard: function() {
            $.ajax({
                url: mfseoWizard.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'mfseo_wizard_dismiss',
                    nonce: mfseoWizard.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.mfseo-wizard-notice').fadeOut();
                        window.location.href = mfseoWizard.dashboardUrl;
                    }
                }
            });
        },
        
        escapeHtml: function(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
    };
    
    $(document).ready(function() {
        MindfulSEOWizard.init();
    });
    
})(jQuery);
