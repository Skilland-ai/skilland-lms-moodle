define(['jquery'], function($) {
    return {
        init: function() {
            var devValues = {
                'graphql_endpoint': 'http://host.docker.internal:8000/graphql',
                'frontend_url': 'http://localhost:3000',
                'sso_secret': 'H6kV/79RMH1sDYFFGHLIuAdZPy6N3n1YS6usjzaxu1Q='
            };

            function findInput(shortname) {
                // Try ID first (standard Moodle)
                var id = 'id_s_mod_skilland_' + shortname;
                var $el = $('#' + id);

                // Try Name second
                if ($el.length === 0) {
                    var name = 's_mod_skilland_' + shortname;
                    $el = $('input[name="' + name + '"]');
                }

                // Try searching by specific attribute if all else fails
                if ($el.length === 0) {
                    $el = $('input[name*="' + shortname + '"]');
                }

                return $el;
            }

            function findRow(shortname) {
                var $input = findInput(shortname);
                // Standard Moodle form row is usually a div with class 'form-item' or 'admin_config_row'
                // We'll search up to find the main container row.
                // In admin settings, it's often a row in a table or a div.
                // Let's look for the closest container that seems to be the row.
                var $row = $input.closest('.form-item, .form-group, tr');
                return $row;
            }

            function updateState() {
                var $devToggle = findInput('devmode');
                if ($devToggle.length === 0) {
                    return;
                }

                var isDev = $devToggle.is(':checked');

                $.each(devValues, function(key, val) {
                    var $el = findInput(key);
                    var $row = findRow(key);

                    if ($el.length > 0) {
                        if (isDev) {
                            // Set value
                            $el.val(val).trigger('change');
                            // Hide the row
                            $row.hide();
                        } else {
                            // Show the row
                            $row.show();
                        }
                    }
                });
            }

            // Initial check
            updateState();

            // Listen for changes
            $('body').on('change', 'input[name*="devmode"]', updateState);
        }
    };
});
