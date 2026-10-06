/**
 * Gruntfile for mod_skilland Moodle plugin.
 *
 * Handles AMD module minification compatible with Moodle's module system.
 */

module.exports = function(grunt) {
    'use strict';

    grunt.initConfig({
        pkg: grunt.file.readJSON('package.json'),

        // Clean build directory
        clean: {
            dist: {
                src: ['dist/*', 'dist/.*'],
                options: { dot: true }
            }
        },

        // Copy source files to dist
        copy: {
            main: {
                expand: true,
                cwd: 'src/',
                // Moodle PHPUnit/Behat tests and their fixture API client are never shipped in the release.
                // cli/configure_api.php is a development-only helper, never shipped either.
                src: ['**', '!tests/**', '!classes/local/testing/**', '!amd/build/**', '!cli/configure_api.php'],
                dest: 'dist/'
            },
            docs: {
                files: [
                    {src: 'README.md', dest: 'dist/README.md'},
                    // Moodle's plugin directory reads the changelog from CHANGES.md.
                    {src: 'CHANGELOG.md', dest: 'dist/CHANGES.md'}
                ]
            }
        },

        // Minify AMD modules
        uglify: {
            options: {
                preserveComments: false,
                report: 'min'
            },
            amd: {
                files: [{
                    expand: true,
                    cwd: 'src/amd/src',
                    src: ['**/*.js'],
                    dest: 'dist/amd/build',
                    ext: '.min.js'
                }]
            }
        },

        // Watch for changes during development
        watch: {
            scripts: {
                files: ['src/**/*', 'README.md', 'CHANGELOG.md'],
                tasks: ['build'],
                options: {
                    spawn: false,
                },
            }
        }
    });

    // Load plugins
    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-watch');
    grunt.loadNpmTasks('grunt-contrib-clean');
    grunt.loadNpmTasks('grunt-contrib-copy');

    // Register tasks
    grunt.registerTask('default', ['build']);
    grunt.registerTask('build', ['clean', 'copy:main', 'copy:docs', 'uglify']);
};


