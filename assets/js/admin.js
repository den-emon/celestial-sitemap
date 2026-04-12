/**
 * Celestial Sitemap – Admin JS
 *
 * FIX: Previous version had mismatched selectors (#cel-message vs template IDs),
 * read data-id from button instead of parent <tr>, and targeted nonexistent row IDs.
 */
(function ($) {
    'use strict';

    /**
     * Show a notice on the current admin page.
     * Looks for the first visible cel notice container.
     */
    function showMessage(text, type) {
        var $notice = $('#cel-settings-notice, #cel-redirect-notice, #cel-404-notice, #cel-robots-notice').first();
        if (!$notice.length) return;
        $notice
            .removeClass('notice-success notice-error hidden')
            .addClass('notice-' + type)
            .empty().append($('<p>').text(text))
            .show();
        setTimeout(function () { $notice.fadeOut(); }, 4000);
    }

    /**
     * Generic AJAX helper.
     */
    function ajax(action, data, cb) {
        if (typeof data === 'string') {
            // Serialized form string — append action & nonce
            data += '&action=' + encodeURIComponent(action);
            data += '&_ajax_nonce=' + encodeURIComponent(celAdmin.nonce);
        } else {
            data.action = action;
            data._ajax_nonce = celAdmin.nonce;
        }
        $.post(celAdmin.ajaxUrl, data, function (res) {
            if (res.success) {
                showMessage(res.data.message || 'Done.', 'success');
                if (cb) cb(res.data);
            } else {
                showMessage(res.data.message || 'Error.', 'error');
            }
        }).fail(function () {
            showMessage('Request failed.', 'error');
        });
    }

    // ── Save settings ────────────────────────────────────────────────
    $('#cel-settings-form').on('submit', function (e) {
        e.preventDefault();
        // jQuery.serialize() omits unchecked checkboxes.
        // The server-side (AdminController::ajaxSaveSettings) already handles
        // absent checkbox keys as 0, so serialize() is correct here.
        ajax('cel_save_settings', $(this).serialize());
    });

    // ── Save robots.txt ─────────────────────────────────────────────
    $('#cel-robots-txt-form').on('submit', function (e) {
        e.preventDefault();
        var $notice = $('#cel-robots-notice');
        var data = $(this).serialize();
        data += '&action=cel_save_robots_txt';
        data += '&_ajax_nonce=' + encodeURIComponent(celAdmin.nonce);

        $.post(celAdmin.ajaxUrl, data, function (res) {
            if (res.success) {
                // Show warnings if any
                var msg = res.data.message || 'Done.';
                if (res.data.warnings && res.data.warnings.length) {
                    msg += '\n\n' + res.data.warnings.join('\n');
                }
                $notice
                    .removeClass('notice-success notice-error notice-warning hidden')
                    .addClass(res.data.warnings && res.data.warnings.length ? 'notice-warning' : 'notice-success')
                    .empty();
                // Use <p> for each line
                $.each(msg.split('\n\n'), function (_, line) {
                    if ($.trim(line)) {
                        $notice.append($('<p>').text(line));
                    }
                });
                $notice.show();
                setTimeout(function () { $notice.fadeOut(); }, 6000);
            } else {
                $notice
                    .removeClass('notice-success notice-error notice-warning hidden')
                    .addClass('notice-error')
                    .empty().append($('<p>').text(res.data.message || 'Error.'))
                    .show();
            }
        }).fail(function () {
            $notice
                .removeClass('notice-success notice-error notice-warning hidden')
                .addClass('notice-error')
                .empty().append($('<p>').text('Request failed.'))
                .show();
        });
    });

    // ── Flush sitemap ────────────────────────────────────────────────
    $('#cel-flush-sitemap').on('click', function () {
        ajax('cel_flush_sitemap', {});
    });

    // ── Add redirect ─────────────────────────────────────────────────
    $('#cel-add-redirect').on('click', function () {
        ajax('cel_add_redirect', {
            source: $('#cel-redir-source').val(),
            target: $('#cel-redir-target').val(),
            match_type: $('#cel-redir-match-type').val(),
            status_code: $('#cel-redir-code').val()
        }, function () {
            location.reload();
        });
    });

    // ── Delete redirect ──────────────────────────────────────────────
    // FIX: data-id is on the parent <tr>, not the button itself.
    $(document).on('click', '.cel-delete-redirect', function () {
        var $tr = $(this).closest('tr');
        var id  = $tr.data('id');
        if (!id || !confirm('Delete this redirect?')) return;
        ajax('cel_delete_redirect', { id: id }, function () {
            $tr.fadeOut(300, function () { $(this).remove(); });
        });
    });

    // ── Delete 404 entry ─────────────────────────────────────────────
    // FIX: data-id is on the parent <tr>, not the button itself.
    $(document).on('click', '.cel-delete-404', function () {
        var $tr = $(this).closest('tr');
        var id  = $tr.data('id');
        if (!id) return;
        ajax('cel_delete_404', { id: id }, function () {
            $tr.fadeOut(300, function () { $(this).remove(); });
        });
    });

    // ── Clear 404 log ────────────────────────────────────────────────
    $('#cel-clear-404').on('click', function () {
        if (!confirm('Clear all 404 entries?')) return;
        ajax('cel_clear_404', {}, function () {
            location.reload();
        });
    });

})(jQuery);
