/**
 * FwChangelog: opens the page changelog in a modal from the Settings tab of
 * the page editor.
 *
 * Nothing is requested until the modal is opened for the first time.
 */
(function ($) {
    'use strict';

    // Translated texts come from the module (ProcessWire.config.FwChangelog); English is the fallback
    var text = $.extend(
        { loading: 'Loading…', error: 'Could not load the history.', close: 'Close' },
        (window.ProcessWire && ProcessWire.config && ProcessWire.config.FwChangelog) || {}
    );

    var $modal = null; // built on first open
    var $trigger = null; // link that opened the modal, to give focus back on close

    /**
     * Request one page of the changelog and append it to the container
     *
     * @param {jQuery} $box The .fw-changelog container
     * @param {number} offset Number of saves to skip
     */
    function load($box, offset) {
        if ($box.data('fwLoading')) return;
        $box.data('fwLoading', true);

        $.ajax({
            url: $box.data('url') + '&offset=' + offset,
            dataType: 'html'
        }).done(function (html) {
            $box.find('.fw-changelog-loading, .fw-changelog-more').remove();
            $box.append(html);
            $box.data('fwLoaded', true);
        }).fail(function () {
            $box.find('.fw-changelog-loading').text(text.error);
            $box.find('.fw-changelog-more button').prop('disabled', false);
        }).always(function () {
            $box.data('fwLoading', false);
        });
    }

    /**
     * Create the modal and attach its events
     *
     * @param {string} url Endpoint returning the changelog HTML
     * @param {string} title Title of the page being edited
     * @returns {jQuery}
     */
    function build(url, title) {
        var $el = $(
            '<div class="fw-changelog-modal" role="dialog" aria-modal="true" aria-labelledby="fw-changelog-title">' +
                '<div class="fw-changelog-dialog">' +
                    // Top-right corner of the card; stays put while the content scrolls
                    '<button type="button" class="fw-changelog-close">&times;</button>' +
                    '<div class="fw-changelog-dialog-body">' +
                        '<div class="fw-changelog"><p class="fw-changelog-loading"></p></div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        // Set as text, never as HTML: the title is user content
        $el.find('#fw-changelog-title span').text(title ? '· ' + title : '');
        $el.find('.fw-changelog').data('url', url);
        $el.find('.fw-changelog-close').attr('aria-label', text.close);
        $el.find('.fw-changelog-loading').text(text.loading);

        // Click on the backdrop (outside the dialog) or on the close button
        $el.on('click', function (event) {
            if (event.target === $el[0]) close();
        });
        $el.on('click', '.fw-changelog-close', close);

        $el.on('click', '.fw-changelog-more button', function () {
            var $button = $(this);
            $button.prop('disabled', true);
            load($el.find('.fw-changelog'), parseInt($button.data('offset'), 10) || 0);
        });

        return $el.appendTo(document.body);
    }

    function open() {
        var $box = $modal.find('.fw-changelog');
        $modal.addClass('fw-changelog-modal-open');
        $(document.body).addClass('fw-changelog-noscroll');
        $modal.find('.fw-changelog-close').trigger('focus');
        if (!$box.data('fwLoaded')) load($box, 0);
    }

    function close() {
        if (!$modal) return;
        $modal.removeClass('fw-changelog-modal-open');
        $(document.body).removeClass('fw-changelog-noscroll');
        if ($trigger) $trigger.trigger('focus');
    }

    $(document).on('click', '.fw-changelog-open', function (event) {
        // The trigger is a link: do not follow its href
        event.preventDefault();
        $trigger = $(this);
        if (!$modal) $modal = build($trigger.data('url'), $trigger.data('title'));
        open();
    });

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && $modal && $modal.hasClass('fw-changelog-modal-open')) close();
    });
})(jQuery);
