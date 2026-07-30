/**
 * E-Care Health Services - Frontend & Admin JavaScript
 * Pure jQuery - Shukhee/Meditaj UI
 */
(function($) {
    'use strict';

    // ================================================================
    // CAREGIVER BOOKING MODULE – Tab-style filters
    // ================================================================

    function loadCaregivers() {
        var type = $('#ecare-filter-type .ecare-type-tab.active').data('type') || '';
        var pkg  = $('#ecare-filter-package .ecare-package-tab.active').data('package') || '';

        $('#ecare-caregiver-grid').html('<p>Loading caregivers...</p>');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_caregivers',
            nonce: ecare_ajax.nonce,
            provider_type: type,
            package_type: pkg
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-grid').html(response.data.html);
            }
        });
    }

    // Type tabs
    $(document).on('click', '#ecare-filter-type .ecare-type-tab', function() {
        $('#ecare-filter-type .ecare-type-tab').removeClass('active');
        $(this).addClass('active');
        loadCaregivers();
    });

    // Package tabs
    $(document).on('click', '#ecare-filter-package .ecare-package-tab', function() {
        $('#ecare-filter-package .ecare-package-tab').removeClass('active');
        $(this).addClass('active');
        loadCaregivers();
    });

    // Load on page load
    if ($('#ecare-caregiver-grid').length) {
        loadCaregivers();
    }

    // View caregiver details
    $(document).on('click', '.ecare-view-details', function(e) {
        e.preventDefault();
        var id = $(this).data('id');

        $('#ecare-caregiver-detail-content').html('<p>Loading details...</p>');
        $('#ecare-caregiver-detail-modal').fadeIn(200);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_caregiver_details',
            nonce: ecare_ajax.nonce,
            caregiver_id: id
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-detail-content').html(response.data.html);
            } else {
                $('#ecare-caregiver-detail-content').html('<p class="error">Failed to load details.</p>');
            }
        });
    });

    // Close modal
    $(document).on('click', '.ecare-modal-close', function() {
        $('#ecare-caregiver-detail-modal').fadeOut(200);
    });
    $(document).on('click', '#ecare-caregiver-detail-modal', function(e) {
        if ($(e.target).is('#ecare-caregiver-detail-modal')) {
            $(this).fadeOut(200);
        }
    });

    // Package radio selection in detail view
    $(document).on('click', '.ecare-package-tab', function() {
        var $radio = $(this).find('input[type="radio"]');
        if ($radio.length) {
            $radio.prop('checked', true);
            var price = $radio.data('price');
            $('#ecare-price-display').text(price ? parseFloat(price).toLocaleString() : '0');
            $(this).closest('.ecare-package-tabs').find('.ecare-package-tab').removeClass('active');
            $(this).addClass('active');
        }
    });

    // Family member selection
    $(document).on('click', '.ecare-family-option', function() {
        $(this).closest('.ecare-family-card').find('.ecare-family-option').removeClass('selected');
        $(this).addClass('selected');
    });

    // Submit caregiver booking form
    $(document).on('submit', '#ecare-booking-form', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_booking');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $('#ecare-booking-form').html('<div class="ecare-form-response success">' + response.data.message + '</div>');
                } else {
                    alert(response.data.message);
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Confirm Booking');
            }
        });
    });

    // ================================================================
    // CAREGIVER REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-caregiver-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                var $resp = $form.find('.ecare-form-response');
                if (response.success) {
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success');
                    $form[0].reset();
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Submit Registration');
            }
        });
    });

    // ================================================================
    // LAB TEST MODULE
    // ================================================================

    if ($('#ecare-lab-division').length) {
        loadLocations('division', 0, '#ecare-lab-division');
    }

    function loadLocations(type, parentId, targetSelector) {
        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_locations',
            nonce: ecare_ajax.nonce,
            location_type: type,
            parent_id: parentId
        }, function(response) {
            if (response.success) {
                var $select = $(targetSelector);
                $select.find('option:not(:first)').remove();
                $.each(response.data.locations, function(i, loc) {
                    $select.append('<option value="' + loc.id + '" data-name="' + loc.name.replace(/"/g, '&quot;') + '">' + loc.name + '</option>');
                });
                $select.prop('disabled', false);
            }
        });
    }

    $(document).on('change', '#ecare-lab-division', function() {
        var val = $(this).val();
        $('#ecare-lab-district').html('<option value="">All Districts</option>').prop('disabled', true);
        $('#ecare-lab-area').html('<option value="">All Areas</option>').prop('disabled', true);
        $('#ecare-lab-provider').html('<option value="">All Providers</option>').prop('disabled', true);
        if (val) loadLocations('district', val, '#ecare-lab-district');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-district', function() {
        var val = $(this).val();
        $('#ecare-lab-area').html('<option value="">All Areas</option>').prop('disabled', true);
        $('#ecare-lab-provider').html('<option value="">All Providers</option>').prop('disabled', true);
        if (val) loadLocations('area', val, '#ecare-lab-area');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-area', function() {
        var val = $(this).val();
        $('#ecare-lab-provider').html('<option value="">All Providers</option>').prop('disabled', true);
        if (val) loadLocations('lab_provider', val, '#ecare-lab-provider');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-provider', function() { loadLabTests(); });
    $(document).on('keyup', '#ecare-lab-search', function() { loadLabTests(); });

    function getSelectedText(selectId) {
        var $sel = $(selectId);
        if ($sel.val()) return $sel.find('option:selected').data('name') || '';
        return '';
    }

    function loadLabTests() {
        $('#ecare-lab-grid').html('<p>Loading lab tests...</p>');
        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_lab_tests',
            nonce: ecare_ajax.nonce,
            division: getSelectedText('#ecare-lab-division'),
            district: getSelectedText('#ecare-lab-district'),
            area: getSelectedText('#ecare-lab-area'),
            lab_provider: getSelectedText('#ecare-lab-provider'),
            search: $('#ecare-lab-search').val()
        }, function(response) {
            if (response.success) $('#ecare-lab-grid').html(response.data.html);
        });
    }

    // Add lab test to cart
    $(document).on('click', '.ecare-add-lab-cart', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var $btn = $(this);
        $btn.text('Adding...').prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_add_lab_test_to_cart',
            nonce: ecare_ajax.nonce,
            test_id: id
        }, function(response) {
            if (response.success) {
                $btn.text('Added!');
                if (response.data.cart_url) {
                    var notice = $('<div class="ecare-form-response success" style="margin-top:10px;">Added to cart! <a href="' + response.data.cart_url + '">View Cart</a></div>');
                    $btn.closest('.ecare-lab-card').append(notice);
                }
            } else {
                alert(response.data.message);
                $btn.text('Add to Cart').prop('disabled', false);
            }
        });
    });

    // ================================================================
    // AMBULANCE MODULE – Card-style type select
    // ================================================================

    // Ambulance type card click
    $(document).on('click', '#ecare-ambulance-type .ecare-amb-type-card', function() {
        $('#ecare-ambulance-type .ecare-amb-type-card').removeClass('active');
        $(this).addClass('active');
        var type = $(this).data('type');
        var price = $(this).data('price');
        $('input[name="ambulance_type"]').val(type);
        $('#ecare-summary-type').text(type);
        $('#ecare-summary-price').text(Number(price).toLocaleString());
    });

    // Priority select update
    $(document).on('change', 'select[name="priority_level"]', function() {
        $('#ecare-summary-priority').text($(this).val());
    });

    // Submit ambulance request
    $(document).on('submit', '#ecare-ambulance-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.ecare-confirm-btn');
        $btn.prop('disabled', true).text('Submitting...');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_submit_ambulance_request',
            nonce: ecare_ajax.nonce,
            ambulance_type: $form.find('[name="ambulance_type"]').val(),
            pickup_address: $form.find('[name="pickup_address"]').val(),
            destination: $form.find('[name="destination"]').val(),
            schedule_time: $form.find('[name="schedule_time"]').val(),
            contact_phone: $form.find('[name="contact_phone"]').val(),
            priority_level: $form.find('[name="priority_level"]').val(),
            notes: $form.find('[name="notes"]').val()
        }, function(response) {
            if (response.success) {
                $form.html('<div class="ecare-form-response success">' + response.data.message + '</div>');
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).text('Confirm Request');
            }
        });
    });

    // ================================================================
    // AMBULANCE REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-ambulance-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_ambulance_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Submitting...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                var $resp = $form.find('.ecare-form-response');
                if (response.success) {
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success');
                    $form[0].reset();
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text('Submit Registration');
            }
        });
    });

    // ================================================================
    // ADMIN ACTIONS
    // ================================================================

    // Update booking status
    $(document).on('change', '.ecare-status-select', function() {
        var $select = $(this);
        var bookingId = $select.data('booking-id');
        var status = $select.val();

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_booking_status',
            nonce: ecare_ajax.nonce,
            booking_id: bookingId,
            status: status
        }, function(response) {
            if (response.success) {
                var $row = $select.closest('tr');
                var $pill = $row.find('.ecare-pill');
                var colorMap = { pending: 'yellow', approved: 'green', completed: 'green', cancelled: 'red', dispatched: 'blue', assigned: 'blue' };
                var color = colorMap[status] || 'gray';
                $pill.removeClass().addClass('ecare-pill ecare-pill-' + color).text(status.charAt(0).toUpperCase() + status.slice(1));
            }
        });
    });

    // Approve/Reject provider
    $(document).on('click', '.ecare-approve-provider, .ecare-reject-provider', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var status = $btn.hasClass('ecare-approve-provider') ? 'approved' : 'rejected';

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_provider_status',
            nonce: ecare_ajax.nonce,
            provider_id: id,
            status: status
        }, function(response) {
            if (response.success) {
                var $row = $btn.closest('tr');
                var $pill = $row.find('.ecare-pill');
                var colorMap = { approved: 'green', rejected: 'red', pending: 'yellow' };
                $pill.removeClass().addClass('ecare-pill ecare-pill-' + (colorMap[status] || 'gray')).text(status.charAt(0).toUpperCase() + status.slice(1));
                $btn.closest('td').find('.ecare-approve-provider, .ecare-reject-provider').hide();
            }
        });
    });

})(jQuery);
