/**
 * Online Class Booking – Frontend JavaScript
 *
 * Depends on: jQuery, FullCalendar 5, Bootstrap 5
 * Server data injected via wp_localize_script as `ocData`.
 */
(function ($) {
    'use strict';

    if (typeof ocData === 'undefined') { return; }

    /* ================================================================
       Shared helpers
       ================================================================ */

    /**
     * Perform a jQuery AJAX call to admin-ajax.php.
     *
     * @param {string}   action
     * @param {Object}   data
     * @param {string}   method  'GET' | 'POST'
     * @returns {Promise}
     */
    function ocAjax(action, data, method) {
        method = method || 'POST';
        var payload = $.extend({ action: action, nonce: ocData.nonce }, data);
        return $.ajax({
            url: ocData.ajaxUrl,
            type: method,
            data: payload,
            dataType: 'json'
        });
    }

    /**
     * Show an alert inside a Bootstrap alert container.
     *
     * @param {jQuery} $el
     * @param {string} msg
     * @param {string} type  'success' | 'danger' | 'warning' | 'info'
     */
    function showAlert($el, msg, type) {
        $el.removeClass('d-none alert-success alert-danger alert-warning alert-info')
           .addClass('alert-' + (type || 'danger'))
           .html(msg);
    }

    /** Reset an alert element back to hidden. */
    function hideAlert($el) {
        $el.addClass('d-none').html('');
    }

    /**
     * Populate a <select> element.
     *
     * @param {jQuery}   $select
     * @param {Array}    items     Array of { value, label }
     * @param {string}   placeholder
     */
    function populateSelect($select, items, placeholder) {
        $select.empty().append(
            $('<option>').val('').text(placeholder || '— select —')
        );
        $.each(items, function (i, item) {
            $select.append($('<option>').val(item.value).text(item.label));
        });
    }

    /* ================================================================
       Bootstrap modal helpers
       ================================================================ */

    function showModal(id) {
        var modalEl = document.getElementById(id);
        if (!modalEl) { return; }
        var instance = bootstrap.Modal.getOrCreateInstance(modalEl);
        instance.show();
    }

    function hideModal(id) {
        var modalEl = document.getElementById(id);
        if (!modalEl) { return; }
        var instance = bootstrap.Modal.getInstance(modalEl);
        if (instance) { instance.hide(); }
    }

    /* ================================================================
       Load schools into a <select>
       ================================================================ */

    function loadSchools($select, onDone) {
        ocAjax('oc_get_groups', { type: 'school' }, 'GET')
            .done(function (res) {
                if (res.success) {
                    populateSelect($select,
                        $.map(res.data, function (g) { return { value: g.id, label: g.name }; }),
                        ocData.i18n.selectSchool || '— select school —'
                    );
                    if (typeof onDone === 'function') { onDone(); }
                }
            });
    }

    /**
     * Load classes for a given school into a <select>.
     */
    function loadClasses($select, schoolId, onDone) {
        $select.empty().append($('<option>').val('').text('— select class —'));
        if (!schoolId) { return; }
        ocAjax('oc_get_groups', { type: 'class', school_id: schoolId }, 'GET')
            .done(function (res) {
                if (res.success) {
                    populateSelect($select,
                        $.map(res.data, function (g) { return { value: g.id, label: g.name }; }),
                        '— select class —'
                    );
                    if (typeof onDone === 'function') { onDone(); }
                }
            });
    }

    /**
     * Load students (role=student) for a given group.
     */
    function loadStudents(groupId, $container, selectedIds) {
        selectedIds = selectedIds || [];
        $container.html('<div class="oc-loading"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div> Loading…</div>');
        if (!groupId) {
            $container.html('<p class="text-muted small mb-0">Select a class to load students.</p>');
            return;
        }
        ocAjax('oc_get_group_users', { group_id: groupId, role: 'student' }, 'GET')
            .done(function (res) {
                if (res.success && res.data.length) {
                    var html = '';
                    $.each(res.data, function (i, u) {
                        var checked = selectedIds.indexOf(parseInt(u.ID, 10)) !== -1 ? 'checked' : '';
                        html += '<div class="form-check">' +
                            '<input class="form-check-input oc-attendee-chk" type="checkbox" ' +
                            'name="attendees[]" value="' + parseInt(u.ID, 10) + '" id="att_' + u.ID + '" ' + checked + '>' +
                            '<label class="form-check-label" for="att_' + u.ID + '">' +
                            $('<span>').text(u.display_name).html() + '</label></div>';
                    });
                    $container.html(html);
                } else {
                    $container.html('<p class="text-muted small mb-0">No students found in this class.</p>');
                }
            });
    }

    /* ================================================================
       STUDENT CALENDAR
       ================================================================ */

    var studentWrap = document.getElementById('oc-student-calendar');

    if (studentWrap) {

        var studentCal = new FullCalendar.Calendar(studentWrap, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,timeGridWeek,timeGridDay'
            },
            height:         'auto',
            selectable:     true,
            validRange:     { start: new Date().toISOString().slice(0, 10) },
            eventSources: [
                {
                    url:     ocData.ajaxUrl,
                    method:  'GET',
                    extraParams: function () {
                        return { action: 'oc_get_student_events', nonce: ocData.nonce };
                    },
                    failure: function () { console.warn('OC: failed to load events.'); }
                }
            ],
            /* Click on empty day → open booking modal pre-filled with that date */
            dateClick: function (info) {
                var today = new Date();
                today.setHours(0, 0, 0, 0);
                if (new Date(info.dateStr) < today) { return; }
                openStudentBookingModal(info.dateStr);
            },
            /* Click on event */
            eventClick: function (info) {
                var props = info.event.extendedProps;
                if (props.type === 'availability') {
                    openStudentBookingModalWithSlot(info.event.startStr.slice(0, 10), props.avail_id, props.teacher_id);
                } else if (props.type === 'meeting') {
                    openStudentDetailModal(props.meeting_id, props.status);
                }
            }
        });

        studentCal.render();

        /* ----------------------------------------------------------------
           Student booking modal helpers
           ---------------------------------------------------------------- */

        var $bookModal      = $('#oc-book-modal');
        var $bookDate       = $('#oc-book-date');
        var $bookTeacher    = $('#oc-book-teacher');
        var $bookSlot       = $('#oc-book-slot');
        var $bookTopic      = $('#oc-book-topic');
        var $bookAlert      = $('#oc-book-alert');
        var $bookSubmit     = $('#oc-book-submit');

        function openStudentBookingModal(dateStr) {
            $bookDate.text(dateStr);
            $bookTeacher.val('');
            $bookSlot.empty().append($('<option>').val('').text('— choose a time —'));
            $bookTopic.val('');
            hideAlert($bookAlert);

            // Load available teachers for the date.
            ocAjax('oc_get_available_teachers', { date: dateStr }, 'GET')
                .done(function (res) {
                    $bookTeacher.empty().append($('<option>').val('').text('— choose a tutor —'));
                    if (res.success && res.data.length) {
                        // Deduplicate teachers.
                        var seen = {};
                        $.each(res.data, function (i, t) {
                            if (!seen[t.teacher_id]) {
                                seen[t.teacher_id] = true;
                                $bookTeacher.append(
                                    $('<option>').val(t.teacher_id).text(t.teacher_name)
                                );
                            }
                        });
                        // Store slots grouped by teacher.
                        $bookTeacher.data('slots', res.data);
                    } else {
                        $bookTeacher.append($('<option>').val('').text('No tutors available on this date').prop('disabled', true));
                    }
                });

            showModal('oc-book-modal');
        }

        function openStudentBookingModalWithSlot(dateStr, availId, teacherId) {
            openStudentBookingModal(dateStr);
            // Defer until teachers are loaded.
            var tries = 0;
            var interval = setInterval(function () {
                tries++;
                if ($bookTeacher.find('option[value="' + teacherId + '"]').length) {
                    $bookTeacher.val(teacherId).trigger('change');
                    // Then select the slot once slots load.
                    var tries2 = 0;
                    var inner = setInterval(function () {
                        tries2++;
                        if ($bookSlot.find('option[value="' + availId + '"]').length) {
                            $bookSlot.val(availId);
                            clearInterval(inner);
                        }
                        if (tries2 > 20) { clearInterval(inner); }
                    }, 100);
                    clearInterval(interval);
                }
                if (tries > 20) { clearInterval(interval); }
            }, 150);
        }

        // When teacher changes, load their time slots.
        $bookTeacher.on('change', function () {
            var tid  = parseInt($(this).val(), 10);
            var date = $bookDate.text();
            $bookSlot.empty().append($('<option>').val('').text('— choose a time —'));
            if (!tid) { return; }

            var slots = $bookTeacher.data('slots') || [];
            var filtered = $.grep(slots, function (s) { return parseInt(s.teacher_id, 10) === tid; });
            if (filtered.length) {
                $.each(filtered, function (i, s) {
                    $bookSlot.append(
                        $('<option>').val(s.avail_id).text(s.start_time + ' – ' + s.end_time)
                    );
                });
            } else {
                // Fallback – fetch fresh.
                ocAjax('oc_get_available_slots', { date: date, teacher_id: tid }, 'GET')
                    .done(function (res) {
                        if (res.success && res.data.length) {
                            $.each(res.data, function (i, s) {
                                $bookSlot.append(
                                    $('<option>').val(s.avail_id).text(s.start_time + ' – ' + s.end_time)
                                );
                            });
                        }
                    });
            }
        });

        // Submit booking.
        $bookSubmit.on('click', function () {
            hideAlert($bookAlert);
            var availId = $bookSlot.val();
            var topic   = $bookTopic.val();

            if (!availId) {
                showAlert($bookAlert, 'Please select a time slot.', 'warning');
                return;
            }

            $bookSubmit.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Booking…');

            ocAjax('oc_book_class', { avail_id: availId, topic: topic })
                .done(function (res) {
                    if (res.success) {
                        showAlert($bookAlert, res.data.message, 'success');
                        if (res.data.meeting_url) {
                            $bookAlert.append(' <a href="' + res.data.meeting_url + '" target="_blank" rel="noopener">Join Meeting</a>');
                        }
                        studentCal.refetchEvents();
                        setTimeout(function () { hideModal('oc-book-modal'); }, 2500);
                    } else {
                        showAlert($bookAlert, res.data.message || ocData.i18n.errorOccurred, 'danger');
                    }
                })
                .fail(function () { showAlert($bookAlert, ocData.i18n.errorOccurred, 'danger'); })
                .always(function () { $bookSubmit.prop('disabled', false).html('<i class="fas fa-check me-1"></i>Book Class'); });
        });

        /* ----------------------------------------------------------------
           Student detail / cancel modal
           ---------------------------------------------------------------- */
        var $detailModal = $('#oc-detail-modal');
        var $detailBody  = $('#oc-detail-body');
        var $joinLink    = $('#oc-join-link');
        var $cancelBtn   = $('#oc-cancel-btn');
        var currentMeetingId = null;

        function openStudentDetailModal(meetingId, status) {
            currentMeetingId = meetingId;
            $detailBody.html('<div class="oc-loading"><div class="spinner-border text-primary" role="status"></div></div>');
            $joinLink.addClass('d-none');
            $cancelBtn.addClass('d-none');
            showModal('oc-detail-modal');

            ocAjax('oc_get_meeting_detail', { meeting_id: meetingId }, 'GET')
                .done(function (res) {
                    if (res.success) {
                        var m = res.data;
                        var html = '<div class="oc-detail-card">' +
                            detailRow('fas fa-heading',       'Title',    m.title) +
                            detailRow('fas fa-user-tie',      'Teacher',  m.teacher_name || '') +
                            detailRow('fas fa-calendar-day',  'Start',    m.start_datetime) +
                            detailRow('fas fa-hourglass-half','Duration', m.duration + ' min') +
                            detailRow('fas fa-video',         'Platform', (m.meeting_type || '').toUpperCase()) +
                            detailRow('fas fa-book-open',     'Topic',    m.topic || '—') +
                            detailRow('fas fa-info-circle',   'Status',   '<span class="oc-badge oc-badge-' + m.status + '">' + m.status + '</span>') +
                            '</div>';
                        $detailBody.html(html);

                        if (m.meeting_url) {
                            $joinLink.attr('href', m.meeting_url).removeClass('d-none');
                        }
                        if (m.status === 'scheduled') {
                            $cancelBtn.removeClass('d-none').data('id', meetingId);
                        }
                    } else {
                        $detailBody.html('<p class="text-danger">' + (res.data.message || 'Error loading details.') + '</p>');
                    }
                })
                .fail(function () { $detailBody.html('<p class="text-danger">Failed to load meeting details.</p>'); });
        }

        function detailRow(icon, label, value) {
            return '<div class="oc-detail-row">' +
                '<span class="oc-detail-label"><i class="' + icon + ' me-1"></i>' + label + '</span>' +
                '<span class="oc-detail-value">' + value + '</span>' +
                '</div>';
        }

        $cancelBtn.on('click', function () {
            if (!confirm(ocData.i18n.confirmCancel)) { return; }
            var mid = $(this).data('id');
            $cancelBtn.prop('disabled', true);
            ocAjax('oc_cancel_booking', { meeting_id: mid })
                .done(function (res) {
                    if (res.success) {
                        hideModal('oc-detail-modal');
                        studentCal.refetchEvents();
                    } else {
                        alert(res.data.message || ocData.i18n.errorOccurred);
                    }
                })
                .fail(function () { alert(ocData.i18n.errorOccurred); })
                .always(function () { $cancelBtn.prop('disabled', false); });
        });
    }

    /* ================================================================
       TEACHER / ADMIN CALENDAR
       ================================================================ */

    var teacherWrap = document.getElementById('oc-teacher-calendar');

    if (teacherWrap) {

        var teacherFilterId = 0; // Current teacher_id filter (admin only).

        var teacherCal = new FullCalendar.Calendar(teacherWrap, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,timeGridWeek,timeGridDay'
            },
            height:    'auto',
            selectable: true,
            eventSources: [
                {
                    url:     ocData.ajaxUrl,
                    method:  'GET',
                    extraParams: function () {
                        var p = { action: 'oc_get_teacher_events', nonce: ocData.nonce };
                        if (teacherFilterId) { p.teacher_id = teacherFilterId; }
                        return p;
                    },
                    failure: function () { console.warn('OC: failed to load teacher events.'); }
                }
            ],
            dateClick: function (info) {
                openAvailModal(null, info.dateStr);
            },
            eventClick: function (info) {
                var props = info.event.extendedProps;
                if (props.type === 'availability') {
                    openAvailModal(props.avail_id, null, {
                        school_id: props.school_id,
                        class_id:  props.class_id,
                        is_booked: props.is_booked
                    });
                } else if (props.type === 'meeting') {
                    openMeetingModal(props.meeting_id);
                }
            }
        });

        teacherCal.render();

        /* ----------------------------------------------------------------
           Admin: teacher filter dropdown
           ---------------------------------------------------------------- */
        var $teacherFilter = $('#oc-admin-teacher-filter');
        if ($teacherFilter.length && parseInt(ocData.isAdmin, 10)) {
            ocAjax('oc_get_teachers', {}, 'GET').done(function (res) {
                if (res.success) {
                    $.each(res.data, function (i, t) {
                        $teacherFilter.append($('<option>').val(t.ID).text(t.display_name));
                    });
                }
            });
            $teacherFilter.on('change', function () {
                teacherFilterId = parseInt($(this).val(), 10) || 0;
                teacherCal.refetchEvents();
            });
        }

        /* ----------------------------------------------------------------
           Button: Add availability / New meeting
           ---------------------------------------------------------------- */
        $('#oc-add-avail-btn').on('click', function () {
            openAvailModal(null, null);
        });

        $('#oc-create-meeting-btn').on('click', function () {
            openMeetingModal(null);
        });

        /* ================================================================
           AVAILABILITY MODAL
           ================================================================ */

        var $availModal      = $('#oc-avail-modal');
        var $availId         = $('#oc-avail-id');
        var $availTeacher    = $('#oc-avail-teacher');
        var $availSchool     = $('#oc-avail-school');
        var $availClass      = $('#oc-avail-class');
        var $availDate       = $('#oc-avail-date');
        var $availStart      = $('#oc-avail-start');
        var $availEnd        = $('#oc-avail-end');
        var $availAlert      = $('#oc-avail-alert');
        var $availSaveBtn    = $('#oc-avail-save-btn');
        var $availDeleteBtn  = $('#oc-avail-delete-btn');

        function openAvailModal(availId, prefillDate, extra) {
            // Reset form.
            $availId.val('');
            if ($availTeacher.length) { $availTeacher.val(''); }
            $availSchool.val('');
            $availClass.empty().append($('<option>').val('').text('— select class —'));
            $availDate.val(prefillDate || '');
            $availStart.val('');
            $availEnd.val('');
            hideAlert($availAlert);
            $availDeleteBtn.addClass('d-none');

            // Load teachers (admin only).
            if ($availTeacher.length && parseInt(ocData.isAdmin, 10)) {
                ocAjax('oc_get_teachers', {}, 'GET').done(function (res) {
                    if (res.success) {
                        populateSelect($availTeacher,
                            $.map(res.data, function (t) { return { value: t.ID, label: t.display_name }; }),
                            '— select teacher —'
                        );
                    }
                });
            }

            // Load schools.
            loadSchools($availSchool);

            // If editing.
            if (availId) {
                $availId.val(availId);
                $availDeleteBtn.removeClass('d-none').data('id', availId);
                if (extra) {
                    if (extra.school_id) {
                        // Schools will be set after they load – use a short delay.
                        setTimeout(function () {
                            $availSchool.val(extra.school_id).trigger('change');
                            setTimeout(function () {
                                $availClass.val(extra.class_id || '');
                            }, 600);
                        }, 400);
                    }
                }
            }

            showModal('oc-avail-modal');
        }

        // School changes → reload classes.
        $availSchool.on('change', function () {
            loadClasses($availClass, $(this).val());
        });

        // Save availability.
        $availSaveBtn.on('click', function () {
            hideAlert($availAlert);
            var id         = $availId.val();
            var teacherId  = $availTeacher.length ? $availTeacher.val() : ocData.userId;
            var schoolId   = $availSchool.val();
            var classId    = $availClass.val();
            var date       = $availDate.val();
            var startTime  = $availStart.val();
            var endTime    = $availEnd.val();

            if (!date || !startTime || !endTime) {
                showAlert($availAlert, 'Date, start and end times are required.', 'warning');
                return;
            }

            $availSaveBtn.prop('disabled', true);
            var action = id ? 'oc_update_availability' : 'oc_add_availability';
            var payload = {
                avail_id:   id,
                teacher_id: teacherId,
                school_id:  schoolId,
                class_id:   classId,
                avail_date: date,
                start_time: startTime,
                end_time:   endTime
            };

            ocAjax(action, payload)
                .done(function (res) {
                    if (res.success) {
                        showAlert($availAlert, res.data.message, 'success');
                        teacherCal.refetchEvents();
                        setTimeout(function () { hideModal('oc-avail-modal'); }, 1500);
                    } else {
                        showAlert($availAlert, res.data.message || ocData.i18n.errorOccurred, 'danger');
                    }
                })
                .fail(function () { showAlert($availAlert, ocData.i18n.errorOccurred, 'danger'); })
                .always(function () { $availSaveBtn.prop('disabled', false); });
        });

        // Delete availability.
        $availDeleteBtn.on('click', function () {
            if (!confirm(ocData.i18n.confirmDelete)) { return; }
            var id = $(this).data('id');
            ocAjax('oc_delete_availability', { avail_id: id })
                .done(function (res) {
                    if (res.success) {
                        teacherCal.refetchEvents();
                        hideModal('oc-avail-modal');
                    } else {
                        alert(res.data.message || ocData.i18n.errorOccurred);
                    }
                })
                .fail(function () { alert(ocData.i18n.errorOccurred); });
        });

        /* ================================================================
           MEETING MODAL
           ================================================================ */

        var $meetingModal      = $('#oc-meeting-modal');
        var $meetingId         = $('#oc-meeting-id');
        var $meetingTitle      = $('#oc-meeting-title');
        var $meetingType       = $('#oc-meeting-type');
        var $meetingUrlGroup   = $('#oc-custom-url-group');
        var $meetingUrl        = $('#oc-meeting-url');
        var $meetingTeacher    = $('#oc-meeting-teacher');
        var $meetingSchool     = $('#oc-meeting-school');
        var $meetingClass      = $('#oc-meeting-class');
        var $meetingStart      = $('#oc-meeting-start');
        var $meetingDuration   = $('#oc-meeting-duration');
        var $meetingTopic      = $('#oc-meeting-topic');
        var $attendeesList     = $('#oc-meeting-attendees-list');
        var $meetingAlert      = $('#oc-meeting-alert');
        var $meetingSaveBtn    = $('#oc-meeting-save-btn');
        var $meetingDeleteBtn  = $('#oc-meeting-delete-btn');

        // Show/hide custom URL field based on meeting type.
        $meetingType.on('change', function () {
            if ($(this).val() === 'other') {
                $meetingUrlGroup.removeClass('d-none');
                $meetingUrl.prop('required', true);
            } else {
                $meetingUrlGroup.addClass('d-none');
                $meetingUrl.prop('required', false);
            }
        });

        // School changes → reload classes and attendees.
        $meetingSchool.on('change', function () {
            var schoolId = $(this).val();
            loadClasses($meetingClass, schoolId, function () {
                $attendeesList.html('<p class="text-muted small mb-0">Select a class to load students.</p>');
            });
        });

        // Class changes → reload attendees.
        $meetingClass.on('change', function () {
            loadStudents($(this).val(), $attendeesList);
        });

        function openMeetingModal(meetingDbId) {
            // Reset form.
            $meetingId.val('');
            $meetingTitle.val('');
            $meetingType.val('zoom').trigger('change');
            $meetingUrl.val('');
            if ($meetingTeacher.length) { $meetingTeacher.val(''); }
            $meetingSchool.val('');
            $meetingClass.empty().append($('<option>').val('').text('— select class —'));
            $meetingStart.val('');
            $meetingDuration.val('60');
            $meetingTopic.val('');
            $attendeesList.html('<p class="text-muted small mb-0">Select a class to load students.</p>');
            hideAlert($meetingAlert);
            $meetingDeleteBtn.addClass('d-none');

            // Load schools.
            loadSchools($meetingSchool);

            // Load teachers if admin.
            if ($meetingTeacher.length && parseInt(ocData.isAdmin, 10)) {
                ocAjax('oc_get_teachers', {}, 'GET').done(function (res) {
                    if (res.success) {
                        populateSelect($meetingTeacher,
                            $.map(res.data, function (t) { return { value: t.ID, label: t.display_name }; }),
                            '— select teacher —'
                        );
                        if (meetingDbId) { /* populated in fill step below */ }
                    }
                });
            }

            // If editing, populate form.
            if (meetingDbId) {
                $meetingId.val(meetingDbId);
                $meetingDeleteBtn.removeClass('d-none').data('id', meetingDbId);

                ocAjax('oc_get_meeting_detail', { meeting_id: meetingDbId }, 'GET')
                    .done(function (res) {
                        if (!res.success) { return; }
                        var m = res.data;
                        $meetingTitle.val(m.title);
                        $meetingType.val(m.meeting_type).trigger('change');
                        $meetingUrl.val(m.meeting_url || '');
                        $meetingDuration.val(m.duration);
                        $meetingTopic.val(m.topic || '');

                        // Format datetime for input[type=datetime-local].
                        if (m.start_datetime) {
                            $meetingStart.val(m.start_datetime.replace(' ', 'T').slice(0, 16));
                        }

                        var existingAttendees = $.map(m.attendees || [], function (a) { return parseInt(a.user_id, 10); });

                        // Set teacher (admin).
                        if ($meetingTeacher.length && m.teacher_id) {
                            var tries = 0;
                            var iv = setInterval(function () {
                                if ($meetingTeacher.find('option[value="' + m.teacher_id + '"]').length) {
                                    $meetingTeacher.val(m.teacher_id);
                                    clearInterval(iv);
                                }
                                if (++tries > 20) { clearInterval(iv); }
                            }, 150);
                        }

                        // Set school → class → attendees.
                        if (m.school_id) {
                            setTimeout(function () {
                                $meetingSchool.val(m.school_id).trigger('change');
                                setTimeout(function () {
                                    $meetingClass.val(m.class_id || '').trigger('change');
                                    setTimeout(function () {
                                        loadStudents(m.class_id, $attendeesList, existingAttendees);
                                    }, 600);
                                }, 600);
                            }, 400);
                        }
                    });
            }

            showModal('oc-meeting-modal');
        }

        // Save meeting.
        $meetingSaveBtn.on('click', function () {
            hideAlert($meetingAlert);
            var id = $meetingId.val();

            var attendees = [];
            $attendeesList.find('.oc-attendee-chk:checked').each(function () {
                attendees.push($(this).val());
            });

            var payload = {
                meeting_id:     id,
                title:          $meetingTitle.val(),
                meeting_type:   $meetingType.val(),
                meeting_url:    $meetingUrl.val(),
                school_id:      $meetingSchool.val(),
                class_id:       $meetingClass.val(),
                start_datetime: $meetingStart.val().replace('T', ' '),
                duration:       $meetingDuration.val(),
                topic:          $meetingTopic.val(),
                attendees:      attendees
            };

            if ($meetingTeacher.length) {
                payload.teacher_id = $meetingTeacher.val();
            }

            if (!payload.title || !payload.start_datetime) {
                showAlert($meetingAlert, 'Title and start date/time are required.', 'warning');
                return;
            }

            $meetingSaveBtn.prop('disabled', true);
            var action = id ? 'oc_update_meeting' : 'oc_create_meeting';

            ocAjax(action, payload)
                .done(function (res) {
                    if (res.success) {
                        showAlert($meetingAlert, res.data.message, 'success');
                        if (res.data.meeting_url) {
                            $meetingAlert.append(' <a href="' + res.data.meeting_url + '" target="_blank" rel="noopener">Join Link</a>');
                        }
                        teacherCal.refetchEvents();
                        setTimeout(function () { hideModal('oc-meeting-modal'); }, 2000);
                    } else {
                        showAlert($meetingAlert, res.data.message || ocData.i18n.errorOccurred, 'danger');
                    }
                })
                .fail(function () { showAlert($meetingAlert, ocData.i18n.errorOccurred, 'danger'); })
                .always(function () { $meetingSaveBtn.prop('disabled', false); });
        });

        // Delete meeting.
        $meetingDeleteBtn.on('click', function () {
            if (!confirm(ocData.i18n.confirmDelete)) { return; }
            var id = $(this).data('id');
            ocAjax('oc_delete_meeting', { meeting_id: id })
                .done(function (res) {
                    if (res.success) {
                        teacherCal.refetchEvents();
                        hideModal('oc-meeting-modal');
                    } else {
                        alert(res.data.message || ocData.i18n.errorOccurred);
                    }
                })
                .fail(function () { alert(ocData.i18n.errorOccurred); });
        });

    } // end if (teacherWrap)

}(jQuery));
