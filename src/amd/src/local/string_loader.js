/**
 * Shared helper to fetch a set of language strings through core/str, keyed by name.
 *
 * Wraps Str.get_strings() so callers describe each string once (name, key, optional component
 * and param) and get back an object keyed by name instead of juggling array indices — guards
 * against the Str.get_strings English-fallback race by keeping the resolve/keying logic in one
 * place (SKL-671, SKL-773).
 *
 * @module     mod_skilland/local/string_loader
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/str'], function(Str) {

    /**
     * Fetch every string in entries and resolve them keyed by their entry name.
     *
     * @param {Array} entries Each {name, key, component, param}; component defaults to
     *                        'mod_skilland' when omitted.
     * @return {Promise} Resolved with an object of strings keyed by each entry's name.
     */
    function loadStrings(entries) {
        var requests = entries.map(function(entry) {
            var request = {key: entry.key, component: entry.component || 'mod_skilland'};
            if (entry.param !== undefined) {
                request.param = entry.param;
            }
            return request;
        });
        return Str.get_strings(requests).then(function(values) {
            var strings = {};
            entries.forEach(function(entry, index) {
                strings[entry.name] = values[index];
            });
            return strings;
        });
    }

    return {
        loadStrings: loadStrings
    };
});
