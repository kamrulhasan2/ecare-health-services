/**
 * E-Care Health Services - Frontend & Admin JavaScript
 * Pure jQuery - Shukhee/Meditaj UI
 */
(function($) {
    'use strict';

    function validateForm($form) {
        var isValid = true;
        var missingFields = [];

        $form.find('[required]').each(function() {
            var $input = $(this);
            var val = $input.val();
            
            if (!val || ($input.is(':checkbox') && !$input.is(':checked'))) {
                isValid = false;
                var $field = $input.closest('.ecare-form-field');
                var labelText = '';
                
                if ($field.length) {
                    labelText = $field.find('label').text();
                }
                
                if ($input.attr('name') === 'care_photo') {
                    labelText = 'Profile Photo';
                }
                
                if (!labelText) {
                    labelText = $input.attr('placeholder') || $input.attr('name');
                }
                
                labelText = labelText.replace('*', '').trim();
                missingFields.push(labelText);
            }
        });

        if (!isValid) {
            var $resp = $form.find('.ecare-form-response');
            $resp.html('<div class="error">The following fields are required: ' + missingFields.join(', ') + '.</div>').addClass('error').removeClass('success');
            $('html, body').animate({ scrollTop: $form.offset().top - 40 }, 300);
        }

        return isValid;
    }

    // ================================================================
    // 1. CAREGIVER BOOKING MODULE – Tab-style filters
    // ================================================================

    function loadCaregivers() {
        var type = $('#ecare-filter-type .ecare-type-tab.active').data('type') || '';
        var pkg  = $('#ecare-filter-package .ecare-package-tab.active').data('package') || '';

        $('#ecare-caregiver-grid').html('<div style="grid-column:1/-1;text-align:center;padding:40px;"><p>Loading caregivers...</p></div>');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_caregivers',
            nonce: ecare_ajax.nonce,
            provider_type: type,
            package_type: pkg
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-grid').html(response.data.html);
                
                // Update badge and count text if element exists
                $('.ecare-results-count').text(response.data.count + ' caregivers available');
                
                var badgesHtml = '';
                if (type) {
                    badgesHtml += '<span class="ecare-filter-badge">' + type + '</span>';
                }
                if (pkg) {
                    var pkgLabel = $('#ecare-filter-package .ecare-package-tab.active .ecare-pkg-label').text();
                    badgesHtml += '<span class="ecare-filter-badge">' + pkgLabel + '</span>';
                }
                $('.ecare-filter-badge-row').html(badgesHtml);
            }
        });
    }

    function renderPackageTabs(type) {
        var $container = $('#ecare-filter-package');
        if (!$container.length) return;

        var html = '';
        var packagesMap = ecare_ajax.type_packages || {};
        var packages = [];
        
        if (type && packagesMap[type]) {
            packages = packagesMap[type];
        } else {
            for (var key in packagesMap) {
                if (packagesMap[key] && packagesMap[key].length > 0) {
                    packages = packagesMap[key];
                    break;
                }
            }
        }

        $.each(packages, function(i, pkg) {
            var pkgKey = pkg.label;
            var activeClass = (i === 0) ? ' active' : '';
            html += '<div class="ecare-package-tab' + activeClass + '" data-package="' + pkgKey + '">';
            html += '  <span class="ecare-pkg-label">' + pkg.label + '</span>';
            html += '  <span class="ecare-pkg-price">Total ৳ ' + parseFloat(pkg.price).toLocaleString() + '</span>';
            html += '</div>';
        });

        $container.html(html);
    }

    // Type tabs click
    $(document).on('click', '#ecare-filter-type .ecare-type-tab', function() {
        $('#ecare-filter-type .ecare-type-tab').removeClass('active');
        $(this).addClass('active');
        
        var type = $(this).data('type') || '';
        renderPackageTabs(type);
        loadCaregivers();
    });

    // Package tabs click
    $(document).on('click', '#ecare-filter-package .ecare-package-tab', function() {
        $('#ecare-filter-package .ecare-package-tab').removeClass('active');
        $(this).addClass('active');
        loadCaregivers();
    });

    // Load initial caregiver list on page load
    if ($('#ecare-caregiver-grid').length) {
        var initialType = $('#ecare-filter-type .ecare-type-tab.active').data('type') || '';
        renderPackageTabs(initialType);
        loadCaregivers();
    }

    function loadCaregiverDetails(id, activeIndex) {
        if (!$('#ecare-caregiver-detail-modal').length) {
            $('body').append(
                '<div id="ecare-caregiver-detail-modal" class="ecare-modal-backdrop" style="display:none;">' +
                '  <div class="ecare-modal-container">' +
                '    <div class="ecare-modal-header">' +
                '      <h3>Caregiver Profile & Booking</h3>' +
                '      <button class="ecare-modal-close-btn">&times;</button>' +
                '    </div>' +
                '    <div id="ecare-caregiver-detail-content" class="ecare-modal-body"></div>' +
                '  </div>' +
                '</div>'
            );
        }

        $('#ecare-caregiver-detail-content').html('<p style="text-align:center;padding:40px;">Loading details...</p>');
        $('#ecare-caregiver-detail-modal').fadeIn(200);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_caregiver_details',
            nonce: ecare_ajax.nonce,
            caregiver_id: id
        }, function(response) {
            if (response.success) {
                $('#ecare-caregiver-detail-content').html(response.data.html);
                
                // Retrieve preselected package from the outside filter
                var preselectedPkg = $('#ecare-filter-package .ecare-package-tab.active').data('package');
                
                // If "All Packages" is selected, fallback to the first available package for this Caregiver Type
                if (!preselectedPkg) {
                    preselectedPkg = 'daily_12'; 
                }
                
                $('#ecare-booking-package-val').val(preselectedPkg);

                if (typeof activeIndex !== 'undefined' && activeIndex !== null) {
                    var $row = $('.ecare-family-option-row[data-index="' + activeIndex + '"]');
                    if ($row.length) {
                        $row.trigger('click');
                    }
                }
            } else {
                $('#ecare-caregiver-detail-content').html('<p class="error" style="color:#b91c1c;text-align:center;padding:40px;">Failed to load details.</p>');
            }
        });
    }

    // Open caregiver details modal
    $(document).on('click', '.ecare-view-details, .ecare-cg-card-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        loadCaregiverDetails(id);
    });

    // Close modal
    $(document).on('click', '.ecare-modal-close-btn', function() {
        $('#ecare-caregiver-detail-modal').fadeOut(200);
    });
    $(document).on('click', '#ecare-caregiver-detail-modal', function(e) {
        if ($(e.target).is('#ecare-caregiver-detail-modal')) {
            $(this).fadeOut(200);
        }
    });

    // Interactive family member selection toggler
    $(document).on('click', '.ecare-change-family-link', function(e) {
        e.preventDefault();
        var $list = $('.ecare-family-select-list');
        if ($list.is(':visible')) {
            $list.slideUp(200);
            $(this).text('Change Family Member');
        } else {
            $list.slideDown(200);
            $(this).text('Hide Family Members List');
        }
    });

    $(document).on('click', '.ecare-family-option-row', function(e) {
        if ($(e.target).hasClass('ecare-family-edit-btn')) return;

        var $row = $(this);
        $row.siblings().removeClass('selected');
        $row.addClass('selected');
        
        var name = $row.data('name');
        var relation = $row.data('relation');
        var phone = $row.data('phone');
        var email = $row.data('email');
        var gender = $row.data('gender');
        var age = $row.data('age');
        var weight = $row.data('weight');
        var height = $row.data('height');
        var index = $row.data('index');

        // Update selected box details
        $('.ecare-family-name').text(name);
        $('.ecare-family-badge').text(relation);
        
        // Update active index
        $('.ecare-family-details').attr('data-active-index', index);
        
        // Update meta items
        $('.ecare-family-phone-val').text(phone || '--');
        $('.ecare-family-email-val').text(email || '--');
        $('.ecare-family-gender-age-val').text(gender + (age ? ' | ' + age : ''));
        $('.ecare-family-height-val').text(height);
        $('.ecare-family-weight-val').text(weight);

        // Update booking form fields
        $('#ecare-booking-patient-name-val').val(name);
        $('#ecare-booking-patient-relation-val').val(relation);
        $('#ecare-booking-patient-phone-val').val(phone);

        // Hide select list
        $('.ecare-family-select-list').slideUp(200);
        $('.ecare-change-family-link').text('Change Family Member');
    });

    function openEditFamilyMemberModal($row) {
        var name = $row.attr('data-name') || '';
        var relation = $row.attr('data-relation') || '';
        var phone = $row.attr('data-phone') || '';
        var email = $row.attr('data-email') || '';
        var gender = $row.attr('data-gender') || '';
        var dob = $row.attr('data-dob') || '';
        var weight = $row.attr('data-weight') || '';
        var heightFt = $row.attr('data-height-ft') || '';
        var heightIn = $row.attr('data-height-in') || '';
        var index = $row.attr('data-index') || '';

        var $form = $('#ecare-create-family-form');
        $form.find('input[name="member_name"]').val(name);
        $form.find('input[name="member_phone"]').val(phone);
        $form.find('input[name="member_email"]').val(email);
        $form.find('select[name="member_gender"]').val(gender);
        $form.find('select[name="member_relation"]').val(relation);
        
        var numericWeight = weight.replace(/[^\d]/g, '');
        $form.find('input[name="member_weight"]').val(numericWeight);
        
        $form.find('input[name="member_height_ft"]').val(heightFt);
        $form.find('input[name="member_height_in"]').val(heightIn);
        
        $form.find('#ecare-member-index-val').val(index);

        if (dob) {
            var parts = dob.split('-');
            if (parts.length === 3) {
                $form.find('select[name="member_dob_year"]').val(parts[0]);
                $form.find('select[name="member_dob_month"]').val(parts[1]);
                $form.find('select[name="member_dob_day"]').val(parts[2]);
            }
        } else {
            $form.find('select[name="member_dob_year"]').val('');
            $form.find('select[name="member_dob_month"]').val('');
            $form.find('select[name="member_dob_day"]').val('');
        }

        $('#ecare-create-family-modal h3').text('Edit Family Member');
        $('#ecare-create-family-modal button[type="submit"]').text('Save Changes');

        $('#ecare-create-family-modal').css('display', 'flex');
    }

    // Open/Close Create Family Member Modal
    $(document).on('click', '#ecare-open-create-family-btn', function(e) {
        e.preventDefault();
        var $form = $('#ecare-create-family-form');
        $form[0].reset();
        $form.find('#ecare-member-index-val').val('');
        
        $('#ecare-create-family-modal h3').text('Create Family Member');
        $('#ecare-create-family-modal button[type="submit"]').text('Add Member');
        
        $('#ecare-create-family-modal').css('display', 'flex');
    });

    $(document).on('click', '#ecare-close-create-family-modal', function(e) {
        e.preventDefault();
        $('#ecare-create-family-modal').hide();
    });

    $(document).on('click', '.ecare-family-edit-btn', function(e) {
        e.stopPropagation();
        var $row = $(this).closest('.ecare-family-option-row');
        openEditFamilyMemberModal($row);
    });

    $(document).on('click', '.ecare-edit-active-family-link', function(e) {
        e.preventDefault();
        var activeIndex = $('.ecare-family-details').attr('data-active-index') || '0';
        var $row = $('.ecare-family-option-row[data-index="' + activeIndex + '"]');
        if ($row.length) {
            openEditFamilyMemberModal($row);
        }
    });

    // Submit Create Family Member Form
    $(document).on('submit', '#ecare-create-family-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var formData = $form.serializeArray();
        
        var postData = {
            action: 'ecare_create_family_member',
            nonce: ecare_ajax.nonce
        };
        $.each(formData, function(i, field) {
            postData[field.name] = field.value;
        });

        $.post(ecare_ajax.ajax_url, postData, function(response) {
            if (response.success) {
                var index = response.data.index;
                var caregiverId = $('#ecare-booking-form input[name="caregiver_id"]').val();
                
                $('#ecare-create-family-modal').hide();
                $form[0].reset();

                if (caregiverId) {
                    loadCaregiverDetails(caregiverId, index);
                }
            } else {
                alert(response.data.message || 'Failed to save family member details.');
            }
        });
    });

    // Submit caregiver booking form
    $(document).on('submit', '#ecare-booking-form', function(e) {
        e.preventDefault();

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_booking');
        formData.append('nonce', ecare_ajax.nonce);
        
        // Append selected family member name & relation
        formData.append('family_member_name', $('.ecare-family-name').text());
        formData.append('family_member_relation', $('.ecare-family-badge').text());

        var $btn = $(this).find('button[type="submit"]');
        var origText = $btn.text();
        $btn.prop('disabled', true).text('Booking in progress...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    if (response.data.checkout_url) {
                        $('#ecare-booking-form').html(
                            '<div class="ecare-form-response success" style="margin-top:20px;">' + 
                            '  <strong>Success!</strong> ' + response.data.message + '<br>Redirecting to checkout...' + 
                            '</div>'
                        );
                        setTimeout(function() {
                            window.location.href = response.data.checkout_url;
                        }, 2000);
                    } else {
                        $('#ecare-booking-form').html(
                            '<div class="ecare-form-response success" style="margin-top:20px;">' + 
                            '  <strong>Success!</strong> ' + response.data.message + 
                            '</div>'
                        );
                        // Redirect back to list after 3 seconds as required by specification
                        setTimeout(function() {
                            $('#ecare-caregiver-detail-modal').fadeOut(200, function() {
                                loadCaregivers();
                            });
                        }, 3000);
                    }
                } else {
                    alert(response.data.message);
                    $btn.prop('disabled', false).text(origText);
                }
            },
            error: function() {
                alert('Something went wrong. Please try again.');
                $btn.prop('disabled', false).text(origText);
            }
        });
    });

    // ================================================================
    // 2. CAREGIVER REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-caregiver-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        if (!validateForm($form)) {
            return;
        }

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_caregiver_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.text();
        $btn.prop('disabled', true).text('Registering...');

        $.ajax({
            url: ecare_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                var $resp = $form.find('.ecare-form-response');
                if (response.success) {
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success').removeClass('error');
                    $form[0].reset();
                    // Scroll to top of form
                    $('html, body').animate({ scrollTop: $form.offset().top - 40 }, 300);
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error').removeClass('success');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text(origText);
            }
        });
    });

    // Active Packages Inputs in caregiver registration based on selected caregiver type
    $(document).on('change', 'select[name="provider_type"]', function() {
        var val = $(this).val();
        var $pkgBox = $('.ecare-package-prices-section');
        if (val) {
            $pkgBox.slideDown(200);
        } else {
            $pkgBox.slideUp(200);
        }
    });

    // Toggle Bank Info fields in registration
    $(document).on('click', '.ecare-bank-type-btn', function() {
        $(this).siblings().removeClass('active');
        $(this).addClass('active');
        var target = $(this).data('target');
        if (target === 'mobile') {
            $('.bank-field-lbl').text('Mobile Wallet Operator (e.g. bKash, Rocket)');
            $('.bank-acc-lbl').text('Wallet Account Number');
        } else {
            $('.bank-field-lbl').text('Bank Name & Branch');
            $('.bank-acc-lbl').text('Account Number');
        }
    });

    // ================================================================
    // 3. LAB TEST MODULE
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
        $('#ecare-lab-district').html('<option value="">Select District</option>').prop('disabled', true);
        $('#ecare-lab-area').html('<option value="">Select Area</option>').prop('disabled', true);
        $('#ecare-lab-provider').html('<option value="">Select Lab Provider</option>').prop('disabled', true);
        if (val) loadLocations('district', val, '#ecare-lab-district');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-district', function() {
        var val = $(this).val();
        $('#ecare-lab-area').html('<option value="">Select Area</option>').prop('disabled', true);
        $('#ecare-lab-provider').html('<option value="">Select Lab Provider</option>').prop('disabled', true);
        if (val) loadLocations('area', val, '#ecare-lab-area');
        loadLabTests();
    });

    $(document).on('change', '#ecare-lab-area', function() {
        var val = $(this).val();
        $('#ecare-lab-provider').html('<option value="">Select Lab Provider</option>').prop('disabled', true);
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
        var division = getSelectedText('#ecare-lab-division');
        var district = getSelectedText('#ecare-lab-district');
        var area = getSelectedText('#ecare-lab-area');
        var provider = getSelectedText('#ecare-lab-provider');

        // Only search/load if location is selected or at least division is selected
        if (!division) {
            $('#ecare-lab-grid').html(
                '<div class="ecare-empty-lab-view" style="grid-column:1/-1;">' +
                '  <span style="font-size:48px;display:block;margin-bottom:12px;">🏥</span>' +
                '  <p>Select a location and provider to view available tests.</p>' +
                '</div>'
            );
            $('.ecare-test-count').text('Showing 0 tests');
            return;
        }

        $('#ecare-lab-grid').html('<div style="grid-column:1/-1;text-align:center;padding:40px;"><p>Loading lab tests...</p></div>');
        
        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_filter_lab_tests',
            nonce: ecare_ajax.nonce,
            division: division,
            district: district,
            area: area,
            lab_provider: provider,
            search: $('#ecare-lab-search').val()
        }, function(response) {
            if (response.success) {
                $('#ecare-lab-grid').html(response.data.html);
                $('.ecare-test-count').text('Showing ' + response.data.count + ' tests');
            }
        });
    }

    // Add lab test to cart via plus (+) button
    $(document).on('click', '.ecare-add-to-cart-plus-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var $btn = $(this);
        
        $btn.html('⏳').prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_add_lab_test_to_cart',
            nonce: ecare_ajax.nonce,
            test_id: id
        }, function(response) {
            if (response.success) {
                $btn.html('✓').css('background-color', '#0E9F6E');
                
                // Show floating success notice
                var $card = $btn.closest('.ecare-lab-test-card');
                var noticeHtml = '<div class="ecare-form-response success" style="margin-top:12px;font-size:12px;padding:6px 10px;">Added! <a href="' + response.data.cart_url + '" style="font-weight:700;color:#166534;text-decoration:underline;">Checkout</a></div>';
                
                // Remove previous notices in this card
                $card.find('.ecare-form-response').remove();
                $card.append(noticeHtml);

                // Update cart badge if exists
                if ($('.ecare-cart-badge').length && response.data.cart_count) {
                    $('.ecare-cart-badge').html('🛒 Cart (' + response.data.cart_count + ')');
                }
            } else {
                alert(response.data.message);
                $btn.html('+').prop('disabled', false);
            }
        });
    });

    // ================================================================
    // 4. AMBULANCE MODULE – Card-style type select
    // ================================================================

    // Ambulance type card click
    $(document).on('click', '#ecare-ambulance-type .ecare-amb-type-card', function() {
        $('#ecare-ambulance-type .ecare-amb-type-card').removeClass('active');
        $(this).addClass('active');
        
        var type = $(this).data('type');
        var label = $(this).find('.ecare-amb-type-title').text();
        var price = $(this).data('price');
        
        $('input[name="ambulance_type"]').val(type);
        $('#ecare-summary-type').text(label);
        $('#ecare-summary-price').text(Number(price).toLocaleString());
    });

    // Priority select update
    $(document).on('change', 'select[name="priority_level"]', function() {
        $('#ecare-summary-priority').text($(this).find('option:selected').text());
    });

    // Submit ambulance request form
    $(document).on('submit', '#ecare-ambulance-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $('.ecare-confirm-btn, .ecare-confirm-amb-btn');
        
        if (!$('#agree-terms').is(':checked')) {
            alert('Please agree to the terms and privacy policy.');
            return;
        }

        var origText = $btn.text();
        $btn.prop('disabled', true).text('Confirming request...');

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
                // Check if WooCommerce payment redirect URL is returned
                if (response.data.checkout_url) {
                    $form.html('<div class="ecare-form-response success">' + response.data.message + '<br>Redirecting to checkout...</div>');
                    setTimeout(function() {
                        window.location.href = response.data.checkout_url;
                    }, 2000);
                } else {
                    $form.html('<div class="ecare-form-response success">' + response.data.message + '</div>');
                }
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).text(origText);
            }
        }).fail(function() {
            alert('Error submitting request. Please try again.');
            $btn.prop('disabled', false).text(origText);
        });
    });

    // ================================================================
    // 5. AMBULANCE REGISTRATION
    // ================================================================

    $(document).on('submit', '#ecare-ambulance-registration-form', function(e) {
        e.preventDefault();
        var $form = $(this);

        if (!validateForm($form)) {
            return;
        }

        var formData = new FormData(this);
        formData.append('action', 'ecare_submit_ambulance_registration');
        formData.append('nonce', ecare_ajax.nonce);

        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.text();
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
                    $resp.html('<div class="success">' + response.data.message + '</div>').addClass('success').removeClass('error');
                    $form[0].reset();
                    $('html, body').animate({ scrollTop: $form.offset().top - 40 }, 300);
                } else {
                    $resp.html('<div class="error">' + response.data.message + '</div>').addClass('error').removeClass('success');
                }
            },
            complete: function() {
                $btn.prop('disabled', false).text(origText);
            }
        });
    });

    // ================================================================
    // 6. ADMIN DASHBOARD ACTIONS (Meditaj / Shukhee Style)
    // ================================================================

    // Update booking status instantly via AJAX select
    $(document).on('change', '.ecare-status-select', function() {
        var $select = $(this);
        var bookingId = $select.data('booking-id');
        var status = $select.val();

        $select.prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_booking_status',
            nonce: ecare_ajax.nonce,
            booking_id: bookingId,
            status: status
        }, function(response) {
            $select.prop('disabled', false);
            if (response.success) {
                var $row = $select.closest('tr');
                var $pill = $row.find('.ecare-status-pill');
                
                // Remove existing status classes
                $pill.removeClass('pending approved completed cancelled dispatched assigned emergency');
                $pill.addClass(status);
                
                var label = status.charAt(0).toUpperCase() + status.slice(1);
                $pill.text(label);
            } else {
                alert(response.data.message);
            }
        });
    });

    // Approve/Reject provider actions from admin table
    $(document).on('click', '.ecare-approve-provider, .ecare-reject-provider', function() {
        var $btn = $(this);
        var id = $btn.data('id');
        var status = $btn.hasClass('ecare-approve-provider') ? 'approved' : 'rejected';

        $btn.prop('disabled', true);

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_update_provider_status',
            nonce: ecare_ajax.nonce,
            provider_id: id,
            status: status
        }, function(response) {
            $btn.prop('disabled', false);
            if (response.success) {
                var $row = $btn.closest('tr');
                var $pill = $row.find('.ecare-status-pill');
                
                $pill.removeClass('pending approved rejected');
                $pill.addClass(status);
                $pill.text(status.charAt(0).toUpperCase() + status.slice(1));
                
                // Hide actions column buttons as status resolved
                $btn.closest('td').find('.ecare-approve-provider, .ecare-reject-provider').hide();
            } else {
                alert(response.data.message);
            }
        });
    });

    // ================================================================
    // 7. TAXONOMY MEDIA UPLOADER (Caregiver Type Image)
    // ================================================================
    $(document).on('click', '.ecare_upload_media_btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $input = $('#caregiver_type_image');
        var $preview = $('#caregiver_type_image_preview');
        var $removeBtn = $('.ecare_remove_media_btn');

        var uploader = wp.media({
            title: 'Choose Caregiver Type Image',
            button: {
                text: 'Select Image'
            },
            multiple: false
        }).on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $input.val(attachment.id);
            $preview.html('<img src="' + attachment.url + '" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:1px solid #ddd;display:block;" />');
            $removeBtn.show();
            $btn.val('Change Image');
        }).open();
    });

    $(document).on('click', '.ecare_remove_media_btn', function(e) {
        e.preventDefault();
        $('#caregiver_type_image').val('');
        $('#caregiver_type_image_preview').html('');
        $(this).hide();
        $('.ecare_upload_media_btn').val('Upload Image');
    });

    // ================================================================
    // 8. ADMIN CASCADING DROPDOWNS (Lab Test Edit Locations)
    // ================================================================
    if ($('#ecare-admin-division').length) {
        var selectedDiv = $('#ecare-admin-division').data('selected');
        var selectedDist = $('#ecare-admin-district').data('selected');
        var selectedArea = $('#ecare-admin-area').data('selected');
        var selectedProv = $('#ecare-admin-provider').data('selected');

        // Load divisions
        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_locations',
            nonce: ecare_ajax.nonce,
            location_type: 'division',
            parent_id: 0
        }, function(response) {
            if (response.success) {
                var $select = $('#ecare-admin-division');
                $.each(response.data.locations, function(i, loc) {
                    var selectedAttr = (loc.name === selectedDiv) ? ' selected' : '';
                    $select.append('<option value="' + loc.name.replace(/"/g, '&quot;') + '" data-id="' + loc.id + '"' + selectedAttr + '>' + loc.name + '</option>');
                });
                if (selectedDiv) {
                    $select.trigger('change');
                }
            }
        });
    }

    $(document).on('change', '#ecare-admin-division', function() {
        var $opt = $(this).find('option:selected');
        var parentId = $opt.data('id');
        var selectedDist = $('#ecare-admin-district').data('selected');
        
        $('#ecare-admin-district').html('<option value="">Select District</option>').prop('disabled', true);
        $('#ecare-admin-area').html('<option value="">Select Area</option>').prop('disabled', true);
        $('#ecare-admin-provider').html('<option value="">Select Provider</option>').prop('disabled', true);

        if (parentId) {
            $.post(ecare_ajax.ajax_url, {
                action: 'ecare_get_locations',
                nonce: ecare_ajax.nonce,
                location_type: 'district',
                parent_id: parentId
            }, function(response) {
                if (response.success) {
                    var $select = $('#ecare-admin-district');
                    $.each(response.data.locations, function(i, loc) {
                        var selectedAttr = (loc.name === selectedDist) ? ' selected' : '';
                        $select.append('<option value="' + loc.name.replace(/"/g, '&quot;') + '" data-id="' + loc.id + '"' + selectedAttr + '>' + loc.name + '</option>');
                    });
                    $select.prop('disabled', false);
                    if (selectedDist) {
                        $select.trigger('change');
                    }
                }
            });
        }
    });

    $(document).on('change', '#ecare-admin-district', function() {
        var $opt = $(this).find('option:selected');
        var parentId = $opt.data('id');
        var selectedArea = $('#ecare-admin-area').data('selected');
        
        $('#ecare-admin-area').html('<option value="">Select Area</option>').prop('disabled', true);
        $('#ecare-admin-provider').html('<option value="">Select Provider</option>').prop('disabled', true);

        if (parentId) {
            $.post(ecare_ajax.ajax_url, {
                action: 'ecare_get_locations',
                nonce: ecare_ajax.nonce,
                location_type: 'area',
                parent_id: parentId
            }, function(response) {
                if (response.success) {
                    var $select = $('#ecare-admin-area');
                    $.each(response.data.locations, function(i, loc) {
                        var selectedAttr = (loc.name === selectedArea) ? ' selected' : '';
                        $select.append('<option value="' + loc.name.replace(/"/g, '&quot;') + '" data-id="' + loc.id + '"' + selectedAttr + '>' + loc.name + '</option>');
                    });
                    $select.prop('disabled', false);
                    if (selectedArea) {
                        $select.trigger('change');
                    }
                }
            });
        }
    });

    $(document).on('change', '#ecare-admin-area', function() {
        var $opt = $(this).find('option:selected');
        var parentId = $opt.data('id');
        var selectedProv = $('#ecare-admin-provider').data('selected');
        
        $('#ecare-admin-provider').html('<option value="">Select Provider</option>').prop('disabled', true);

        if (parentId) {
            $.post(ecare_ajax.ajax_url, {
                action: 'ecare_get_locations',
                nonce: ecare_ajax.nonce,
                location_type: 'lab_provider',
                parent_id: parentId
            }, function(response) {
                if (response.success) {
                    var $select = $('#ecare-admin-provider');
                    $.each(response.data.locations, function(i, loc) {
                        var selectedAttr = (loc.name === selectedProv) ? ' selected' : '';
                        $select.append('<option value="' + loc.name.replace(/"/g, '&quot;') + '" data-id="' + loc.id + '"' + selectedAttr + '>' + loc.name + '</option>');
                    });
                    $select.prop('disabled', false);
                }
            });
        }
    });

    // ================================================================
    // 9. ADMIN ADD CAREGIVER TYPE MODAL HANDLERS
    // ================================================================
    function resetTypeForm() {
        $('#ecare-add-type-form')[0].reset();
        $('#ecare-edit-term-id').val('');
        $('#ecare-type-remove-image').val('0');
        $('#ecare-new-type-image').val('');
        $('#ecare-new-type-image-preview').hide().html('');
        $('.id-remove-type-image-btn').hide();
        $('.id-upload-type-image-btn').val('Upload Image');
        $('#ecare-type-modal-title').text('Add New Caregiver Type Info');
        $('#ecare-submit-type-btn').text('Add Type');
        $('#ecare-cancel-edit-type-btn').hide();

        var defaultRowHtml = '<div class="ecare-modal-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">' +
                             '  <input type="text" name="term_package_labels[]" placeholder="e.g. Daily (12 Hours)" style="flex:2; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />' +
                             '  <input type="number" name="term_package_prices[]" placeholder="Price (৳)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />' +
                             '  <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding:6px 10px; height:auto; line-height:1;">&times;</button>' +
                             '</div>';
        $('#ecare-modal-packages-list').html(defaultRowHtml);
    }

    $(document).on('click', '#ecare-add-caregiver-type-btn', function() {
        $('#ecare-add-type-modal').css('display', 'flex');
    });

    $(document).on('click', '#ecare-close-type-modal', function() {
        $('#ecare-add-type-modal').hide();
        $('#ecare-manage-types-container').hide();
        resetTypeForm();
    });

    $(document).on('click', '#ecare-cancel-edit-type-btn', function(e) {
        e.preventDefault();
        resetTypeForm();
    });

    $(document).on('click', '.id-upload-type-image-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $input = $('#ecare-new-type-image');
        var $preview = $('#ecare-new-type-image-preview');
        var $removeBtn = $('.id-remove-type-image-btn');

        var uploader = wp.media({
            title: 'Choose Caregiver Type Image',
            button: {
                text: 'Select Image'
            },
            multiple: false
        }).on('select', function() {
            var attachment = uploader.state().get('selection').first().toJSON();
            $input.val(attachment.id);
            $('#ecare-type-remove-image').val('0');
            $preview.html('<img src="' + attachment.url + '" style="width:100%;height:100%;object-fit:cover;display:block;" />').css('display', 'flex');
            $removeBtn.show();
            $btn.val('Change Image');
        }).open();
    });

    $(document).on('click', '.id-remove-type-image-btn', function(e) {
        e.preventDefault();
        $('#ecare-new-type-image').val('');
        $('#ecare-type-remove-image').val('1');
        $('#ecare-new-type-image-preview').hide().html('');
        $(this).hide();
        $('.id-upload-type-image-btn').val('Upload Image');
    });

    // Add row in modal
    $(document).on('click', '#ecare-modal-add-package-row-btn', function() {
        var rowHtml = '';
        rowHtml += '<div class="ecare-modal-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">';
        rowHtml += '  <input type="text" name="term_package_labels[]" placeholder="e.g. Daily (12 Hours)" style="flex:2; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />';
        rowHtml += '  <input type="number" name="term_package_prices[]" placeholder="Price (৳)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />';
        rowHtml += '  <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding:6px 10px; height:auto; line-height:1;">&times;</button>';
        rowHtml += '</div>';
        $('#ecare-modal-packages-list').append(rowHtml);
    });

    // Add row in admin screen (taxonomy edit/create pages)
    $(document).on('click', '#ecare-add-package-row-btn', function(e) {
        e.preventDefault();
        var rowHtml = '';
        rowHtml += '<div class="ecare-term-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">';
        rowHtml += '  <input type="text" name="term_package_labels[]" placeholder="Duration (e.g. Daily (12 Hours))" style="flex:2;" required />';
        rowHtml += '  <input type="number" name="term_package_prices[]" placeholder="Price (৳)" style="flex:1;" required />';
        rowHtml += '  <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding:4px 8px; line-height:1.2;">&times;</button>';
        rowHtml += '</div>';
        $('#ecare-term-packages-list').append(rowHtml);
    });

    // Remove row in both modal and admin screen
    $(document).on('click', '.ecare-remove-package-row-btn', function(e) {
        e.preventDefault();
        var $list = $(this).closest('#ecare-term-packages-list, #ecare-modal-packages-list');
        var count = $list.find('.ecare-term-package-row, .ecare-modal-package-row').length;
        if (count > 1) {
            $(this).parent().remove();
        } else {
            alert('At least one package is required.');
        }
    });

    $(document).on('click', '#ecare-toggle-manage-types-btn', function(e) {
        e.preventDefault();
        var $container = $('#ecare-manage-types-container');
        $container.slideToggle(300, function() {
            if ($container.is(':visible')) {
                loadCaregiverTypesList();
            }
        });
    });

    function loadCaregiverTypesList() {
        var $list = $('#ecare-manage-types-list');
        $list.html('<p style="text-align:center; color:#94A3B8; font-size:13px; margin: 10px 0;">Loading types...</p>');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_get_caregiver_types',
            nonce: ecare_ajax.nonce
        }, function(response) {
            if (response && response.success && response.data.types) {
                var types = response.data.types;
                if (types.length === 0) {
                    $list.html('<p style="text-align:center; color:#94A3B8; font-size:13px; margin: 10px 0;">No caregiver types found.</p>');
                    return;
                }

                var html = '';
                $.each(types, function(i, type) {
                    var imgHtml = '';
                    if (type.image_url) {
                        imgHtml = '<img src="' + type.image_url + '" style="width:32px; height:32px; border-radius:50%; object-fit:cover; border:1px solid #CBD5E1; display:block;" />';
                    } else {
                        imgHtml = '<div style="width:32px; height:32px; border-radius:50%; background:#CBD5E1; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px;">CT</div>';
                    }

                    var pkgsString = JSON.stringify(type.packages);

                    html += '<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; background:#fff; padding:8px 12px; border-radius:8px; border:1px solid #E2E8F0; margin-bottom: 6px;">';
                    html += '  <div style="display:flex; align-items:center; gap:10px; flex:1;">';
                    html += '    ' + imgHtml;
                    html += '    <span style="font-weight:600; color:#1E293B; font-size:13px;">' + type.name + '</span>';
                    html += '  </div>';
                    html += '  <div style="display:flex; gap:6px; align-items:center;">';
                    html += '    <button type="button" class="button ecare-edit-type-item-btn" style="padding:4px 10px; font-size:11px; height:auto; line-height:1; background:#3B82F6; border-color:#3B82F6; color:#fff;" data-id="' + type.term_id + '" data-name="' + type.name + '" data-image-id="' + type.image_id + '" data-image-url="' + type.image_url + '" data-packages=\'' + pkgsString.replace(/'/g, "&apos;") + '\'>Edit</button>';
                    html += '    <button type="button" class="button ecare-delete-type-item-btn" style="padding:4px 10px; font-size:11px; height:auto; line-height:1; background:#EF4444; border-color:#EF4444; color:#fff;" data-id="' + type.term_id + '" data-name="' + type.name + '">Delete</button>';
                    html += '  </div>';
                    html += '</div>';
                });
                $list.html(html);
            } else {
                $list.html('<p style="text-align:center; color:#EF4444; font-size:13px; margin: 10px 0;">Failed to load caregiver types.</p>');
            }
        }).fail(function() {
            $list.html('<p style="text-align:center; color:#EF4444; font-size:13px; margin: 10px 0;">Server error.</p>');
        });
    }

    $(document).on('click', '.ecare-edit-type-item-btn', function(e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        var name = $(this).attr('data-name');
        var imageId = $(this).attr('data-image-id');
        var imageUrl = $(this).attr('data-image-url');
        var packagesData = $(this).attr('data-packages');
        
        var packages = [];
        try {
            packages = JSON.parse(packagesData);
        } catch(err) {
            packages = [];
        }

        $('#ecare-edit-term-id').val(id);
        $('#ecare-new-type-name').val(name);
        $('#ecare-new-type-image').val(imageId || '');
        $('#ecare-type-remove-image').val('0');
        
        var $preview = $('#ecare-new-type-image-preview');
        var $removeBtn = $('.id-remove-type-image-btn');
        var $uploadBtn = $('.id-upload-type-image-btn');

        if (imageUrl) {
            $preview.html('<img src="' + imageUrl + '" style="width:100%;height:100%;object-fit:cover;display:block;" />').css('display', 'flex');
            $removeBtn.show();
            $uploadBtn.val('Change Image');
        } else {
            $preview.hide().html('');
            $removeBtn.hide();
            $uploadBtn.val('Upload Image');
        }

        var $list = $('#ecare-modal-packages-list');
        $list.empty();
        
        if (packages && packages.length > 0) {
            $.each(packages, function(i, pkg) {
                var rowHtml = '';
                rowHtml += '<div class="ecare-modal-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">';
                rowHtml += '  <input type="text" name="term_package_labels[]" value="' + pkg.label + '" placeholder="e.g. Daily (12 Hours)" style="flex:2; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />';
                rowHtml += '  <input type="number" name="term_package_prices[]" value="' + pkg.price + '" placeholder="Price (৳)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />';
                rowHtml += '  <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding:6px 10px; height:auto; line-height:1;">&times;</button>';
                rowHtml += '</div>';
                $list.append(rowHtml);
            });
        } else {
            var rowHtml = '';
            rowHtml += '<div class="ecare-modal-package-row" style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">';
            rowHtml += '  <input type="text" name="term_package_labels[]" placeholder="e.g. Daily (12 Hours)" style="flex:2; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />';
            rowHtml += '  <input type="number" name="term_package_prices[]" placeholder="Price (৳)" style="flex:1; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px;" required />';
            rowHtml += '  <button type="button" class="button ecare-remove-package-row-btn" style="background:#EF4444; color:#fff; border-color:#EF4444; padding:6px 10px; height:auto; line-height:1;">&times;</button>';
            rowHtml += '</div>';
            $list.append(rowHtml);
        }

        $('#ecare-type-modal-title').text('Edit Caregiver Type Info');
        $('#ecare-submit-type-btn').text('Update Type');
        $('#ecare-cancel-edit-type-btn').show();

        $('.ecare-admin-modal-content').scrollTop(0);
    });

    $(document).on('click', '.ecare-delete-type-item-btn', function(e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        var name = $(this).attr('data-name');
        
        if (confirm('Are you sure you want to delete Caregiver Type Info "' + name + '"? Caregivers assigned to this type will become uncategorized.')) {
            var $btn = $(this);
            $btn.prop('disabled', true).text('...');

            $.post(ecare_ajax.ajax_url, {
                action: 'ecare_delete_caregiver_type',
                nonce: ecare_ajax.nonce,
                term_id: id
            }, function(response) {
                if (response && response.success) {
                    alert(response.data.message);
                    loadCaregiverTypesList();
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Failed to delete caregiver type info.';
                    alert(msg);
                    $btn.prop('disabled', false).text('Delete');
                }
            }).fail(function() {
                alert('Server error.');
                $btn.prop('disabled', false).text('Delete');
            });
        }
    });

    $(document).on('submit', '#ecare-add-type-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var termId = $('#ecare-edit-term-id').val();
        var removeImage = $('#ecare-type-remove-image').val();
        var name = $('#ecare-new-type-name').val();
        var imageId = $('#ecare-new-type-image').val();
        var $btn = $form.find('button[type="submit"]');

        var labels = [];
        var prices = [];
        $form.find('input[name="term_package_labels[]"]').each(function() {
            labels.push($(this).val());
        });
        $form.find('input[name="term_package_prices[]"]').each(function() {
            prices.push($(this).val());
        });

        $btn.prop('disabled', true).text(termId ? 'Updating...' : 'Adding...');

        $.post(ecare_ajax.ajax_url, {
            action: 'ecare_add_caregiver_type',
            nonce: ecare_ajax.nonce,
            term_id: termId,
            remove_image: removeImage,
            type_name: name,
            image_id: imageId,
            term_package_labels: labels,
            term_package_prices: prices
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
                window.location.reload();
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false).text(termId ? 'Update Type' : 'Add Type');
            }
        });
    });

    // ================================================================
    // 10. ADMIN DELETE CAREGIVER ACTION
    // ================================================================
    $(document).on('click', '.ecare-delete-provider', function() {
        var id = $(this).data('id');
        if (confirm('Are you sure you want to delete this provider? This action is permanent.')) {
            var $btn = $(this);
            $btn.prop('disabled', true);
            
            $.post(ecare_ajax.ajax_url, {
                action: 'ecare_delete_caregiver',
                nonce: ecare_ajax.nonce,
                provider_id: id
            }, function(response) {
                if (response && response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : 'Failed to delete provider.';
                    alert(msg);
                    $btn.prop('disabled', false);
                }
            }).fail(function() {
                alert('Server error occurred.');
                $btn.prop('disabled', false);
            });
        }
    });

})(jQuery);
