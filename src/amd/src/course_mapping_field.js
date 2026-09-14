define(['jquery', 'core/ajax', 'core/notification'], function($, ajax, notification) {
    return {
        init: function(courseid) {
            console.log('Skilland course mapping field: Initializing for course', courseid);

            // Find the Skilland Course ID custom field input.
            // Moodle custom fields use different naming patterns - try multiple selectors.
            var fieldInput = $('input[id*="customfield_skilland_course_id"], input[name*="customfield_skilland_course_id"], input[id*="id_customfield_skilland_course_id"]');

            if (fieldInput.length === 0) {
                // Try finding by label text.
                $('label').each(function() {
                    if ($(this).text().indexOf('Skilland Course ID') !== -1) {
                        var forAttr = $(this).attr('for');
                        if (forAttr) {
                            fieldInput = $('#' + forAttr);
                            if (fieldInput.length > 0) {
                                return false; // Break the loop.
                            }
                        }
                    }
                });
            }

            if (fieldInput.length === 0) {
                // Last resort: find input in the same form item as the label.
                $('.fitem').each(function() {
                    var $fitem = $(this);
                    if ($fitem.find('label').text().indexOf('Skilland Course ID') !== -1) {
                        fieldInput = $fitem.find('input[type="text"]');
                        if (fieldInput.length > 0) {
                            return false; // Break the loop.
                        }
                    }
                });
            }

            console.log('Skilland course mapping field: Found input?', fieldInput.length > 0, fieldInput);

            if (fieldInput.length > 0) {
                console.log('Skilland course mapping field: Processing field', fieldInput.attr('id'), fieldInput.attr('name'));
                var formItem = fieldInput.closest('.fitem');
                var currentValue = fieldInput.val();
                console.log('Skilland course mapping field: Current value', currentValue);

                // Create a container for the enhanced field.
                var container = $('<div>').addClass('skilland-course-mapping-field');

                // Create a select dropdown.
                var select = $('<select>')
                    .attr('name', fieldInput.attr('name'))
                    .attr('id', fieldInput.attr('id'))
                    .addClass('form-control')
                    .css('width', '100%');

                // Add a loading option initially.
                select.append($('<option>').text('Loading courses from Skilland...').val(''));

                // Create a hidden input to store the value (for form submission).
                var hiddenInput = $('<input>')
                    .attr('type', 'hidden')
                    .attr('name', fieldInput.attr('name'))
                    .attr('value', currentValue);

                // Replace the text input with our enhanced field.
                fieldInput.after(container);
                container.append(select);
                container.append(hiddenInput);
                fieldInput.hide();

                // Sync select value to hidden input and original field.
                select.on('change', function() {
                    var value = $(this).val();
                    hiddenInput.val(value);
                    fieldInput.val(value);
                });

                // Fetch courses from Skilland via AJAX.
                ajax.call([{
                    methodname: 'mod_skilland_fetch_courses_ajax',
                    args: {moodlecourseid: courseid}
                }])[0].then(function(response) {
                    select.empty();

                    // Add default option.
                    select.append($('<option>').text('Select a Skilland course...').val(''));

                    if (response.courses && response.courses.length > 0) {
                        // Populate dropdown with courses.
                        response.courses.forEach(function(course) {
                            var optionText = course.name;
                            if (course.code) {
                                optionText += ' (' + course.code + ')';
                            }
                            if (course.status) {
                                optionText += ' [' + course.status + ']';
                            }

                            var option = $('<option>')
                                .text(optionText)
                                .val(course.id);

                            if (course.id === currentValue) {
                                option.prop('selected', true);
                                hiddenInput.val(course.id);
                            }

                            select.append(option);
                        });
                    } else {
                        select.append($('<option>').text('No courses available').val(''));
                    }
                }).catch(function(error) {
                    select.empty();
                    select.append($('<option>').text('Error loading courses: ' + (error.message || 'Unknown error')).val(''));

                    // Show error notification.
                    notification.addNotification({
                        message: 'Failed to fetch courses from Skilland. Please check your API configuration.',
                        type: 'error'
                    });

                    // Show the original input as fallback.
                    fieldInput.show();
                    container.hide();
                });
            }
        }
    };
});

