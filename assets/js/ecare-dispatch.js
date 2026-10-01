/*
 * Ambulance Dispatch screen: assign an ambulance, change a status.
 * The server decides (ECare_Ambulance_Dispatch::assign, ECare_Ajax::update_booking_status);
 * this only sends the choice and shows the answer, putting the old choice
 * back when the server says no.
 */
(function ($) {
    'use strict';
    var A = window.ecare_ajax || {};
    var C = window.ecareDispatch || { labels: {}, needUnit: '', failed: 'Could not save.', saving: 'Saving…' };
    var STATUSES = ['pending', 'approved', 'assigned', 'dispatched', 'completed', 'cancelled'];
    var OPEN = ['pending', 'approved', 'assigned', 'dispatched'];

    function say($row, text, kind) {
        $row.find('.ecare-row-msg').text(text || '').removeClass('is-error is-ok').addClass(kind ? 'is-' + kind : '');
    }

    function setStatus($row, status) {
        var $pill = $row.find('.ecare-dispatch-pill');
        $pill.removeClass(STATUSES.join(' ')).addClass(status).text(C.labels[status] || status);
        $row.find('.ecare-dispatch-status').val(status).attr('data-prev', status);
        var open = OPEN.indexOf(status) !== -1;
        $row.find('.ecare-assign-select').prop('disabled', !open);
        markRow($row);
    }

    function markRow($row) {
        var unit = parseInt($row.find('.ecare-assign-select').val(), 10) || 0;
        var open = OPEN.indexOf($row.find('.ecare-dispatch-status').val()) !== -1;
        $row.toggleClass('ecare-unassigned', !unit && open);
    }

    function setCount(n) {
        $('#ecare-unassigned-count').text(n);
        $('#ecare-dispatch-alert').prop('hidden', !n);
    }

    $(document).on('change', '.ecare-dispatch-table .ecare-assign-select', function () {
        var $sel = $(this), $row = $sel.closest('tr');
        var prev = $sel.attr('data-prev');
        $sel.prop('disabled', true);
        say($row, C.saving);
        $.post(A.ajax_url, {
            action: 'ecare_assign_ambulance',
            nonce: A.nonce,
            booking_id: $sel.data('booking-id'),
            unit_id: $sel.val()
        }).done(function (r) {
            var d = (r && r.data) || {};
            if (r && r.success) {
                $sel.attr('data-prev', $sel.val());
                setStatus($row, d.status);
                setCount(d.unassigned);
                say($row, d.message, (d.mail === 'failed' || d.mail === 'no_address') ? 'error' : 'ok');
            } else {
                $sel.val(prev);
                say($row, d.message || C.failed, 'error');
            }
        }).fail(function (x) {
            var d = (x.responseJSON && x.responseJSON.data) || {};
            $sel.val(prev);
            say($row, d.message || C.failed, 'error');
        }).always(function () {
            $sel.prop('disabled', OPEN.indexOf($row.find('.ecare-dispatch-status').val()) === -1);
            markRow($row);
        });
    });

    $(document).on('change', '.ecare-dispatch-table .ecare-dispatch-status', function () {
        var $sel = $(this), $row = $sel.closest('tr');
        var prev = $sel.attr('data-prev'), status = $sel.val();
        var unit = parseInt($row.find('.ecare-assign-select').val(), 10) || 0;
        if (!unit && (status === 'assigned' || status === 'dispatched')) {
            $sel.val(prev);
            say($row, C.needUnit, 'error');
            $row.find('.ecare-assign-select').trigger('focus');
            return;
        }
        $sel.prop('disabled', true);
        say($row, C.saving);
        $.post(A.ajax_url, {
            action: 'ecare_update_booking_status',
            nonce: A.nonce,
            booking_id: $sel.data('booking-id'),
            status: status
        }).done(function (r) {
            if (r && r.success) {
                setStatus($row, status);
                say($row, '');
                // An unassigned row counts while it is open: closing or reopening it moves the count.
                var was = !unit && OPEN.indexOf(prev) !== -1, now = !unit && OPEN.indexOf(status) !== -1;
                setCount(Math.max(0, (parseInt($('#ecare-unassigned-count').text(), 10) || 0) + (now ? 1 : 0) - (was ? 1 : 0)));
            } else {
                $sel.val(prev);
                say($row, (r && r.data && r.data.message) || C.failed, 'error');
            }
        }).fail(function () {
            $sel.val(prev);
            say($row, C.failed, 'error');
        }).always(function () {
            $sel.prop('disabled', false);
        });
    });
})(jQuery);
