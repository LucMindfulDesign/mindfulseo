/**
 * Content Hub JavaScript
 *
 * Handles Content Hub: tab switching, refresh clusters, analyze internal links.
 * Uses adminNonce for AJAX handlers that check_ajax_referer('mindfulseo_admin', 'nonce').
 *
 * @package MindfulSEO
 * @since 2.0.0
 */

(function($) {
    'use strict';

    var ContentHub = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            var self = this;
            if (typeof mfseoContentHub === 'undefined') {
                return;
            }

            if (mfseoContentHub.tabUrls) {
                $('.mfseo-tabs__tab').on('click', function(e) {
                    e.preventDefault();
                    var tab = $(this).data('tab');
                    if (mfseoContentHub.tabUrls[tab]) {
                        window.location.href = mfseoContentHub.tabUrls[tab];
                    }
                });
            }

            $('#analyze-internal-links').on('click', function() {
                self.analyzeInternalLinks();
            });

            $('#mfseo-refresh-clusters').on('click', function(e) {
                e.preventDefault();
                self.refreshClusters();
            });

            // Shared card toggle (works for all hub tabs: clusters, health, links)
            $(document).on('click', '.mfseo-hub-card__header[data-toggle]', function() {
                var targetId = $(this).data('toggle');
                var $body = $('#' + targetId);
                var $card = $(this).closest('.mfseo-hub-card');
                $body.slideToggle(250);
                $card.toggleClass('open');
            });
        },

        getAdminNonce: function() {
            if (typeof mfseoContentHub !== 'undefined' && mfseoContentHub.adminNonce) {
                return mfseoContentHub.adminNonce;
            }
            if (typeof mindfulseoAdmin !== 'undefined' && mindfulseoAdmin.nonce) {
                return mindfulseoAdmin.nonce;
            }
            return '';
        },

        /**
         * Show a spinner overlay on a button while an operation is running.
         */
        setButtonLoading: function($btn, loadingText) {
            $btn.prop('disabled', true);
            $btn.data('original-html', $btn.html());
            $btn.html('<span class="dashicons dashicons-update mfseo-spin"></span> ' + loadingText);
        },

        /**
         * Restore a button to its original state.
         */
        resetButton: function($btn) {
            $btn.prop('disabled', false);
            var original = $btn.data('original-html');
            if (original) {
                $btn.html(original);
            }
        },

        /**
         * Show a temporary notice at the top of the Content Hub content area.
         */
        showNotice: function(message, type) {
            type = type || 'info';
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible mfseo-ajax-notice" style="margin: 10px 0;">' +
                '<p>' + message + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
                '</div>');
            $('.mindfulseo-content').prepend($notice);
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(200, function() { $(this).remove(); });
            });
            setTimeout(function() {
                $notice.fadeOut(400, function() { $(this).remove(); });
            }, 8000);
        },

        analyzeInternalLinks: function() {
            var self = this;
            var $btn = $('#analyze-internal-links');
            self.setButtonLoading($btn, 'Analyzing... this may take up to a minute');

            $.ajax({
                url: mfseoContentHub.ajaxUrl,
                method: 'POST',
                timeout: 120000,
                data: {
                    action: 'mindfulseo_analyze_internal_links',
                    nonce: self.getAdminNonce()
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice(
                            (response.data && response.data.message) ? response.data.message : 'Analysis complete!',
                            'success'
                        );
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Unknown error';
                        self.showNotice('Analysis failed: ' + msg, 'error');
                    }
                },
                error: function(xhr, status) {
                    var msg = status === 'timeout'
                        ? 'Request timed out. The site may have a very large number of posts. Please try again.'
                        : 'Network error. Please try again.';
                    self.showNotice(msg, 'error');
                },
                complete: function() {
                    self.resetButton($btn);
                }
            });
        },

        refreshClusters: function() {
            var self = this;
            var $btn = $('#mfseo-refresh-clusters');
            self.setButtonLoading($btn, 'Refreshing...');

            $.ajax({
                url: mfseoContentHub.ajaxUrl,
                method: 'POST',
                timeout: 60000,
                data: {
                    action: 'mindfulseo_refresh_clusters',
                    nonce: self.getAdminNonce()
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'Unknown error';
                        self.showNotice('Refresh failed: ' + msg, 'error');
                    }
                },
                error: function(xhr, status) {
                    var msg = status === 'timeout' ? 'Request timed out. Try again in a moment.' : 'Network error. Please try again.';
                    self.showNotice(msg, 'error');
                },
                complete: function() {
                    self.resetButton($btn);
                }
            });
        }
    };

    $(document).ready(function() {
        ContentHub.init();
    });

})(jQuery);
