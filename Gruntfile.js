/**
 * Gruntfile for Idno project.
 *
 * This is a grunt task for building various aspects of Idno. You'll only need to
 * use this if you're a developer working on the core.
 * 
 * Installation
 * 
 *  - npm i 
 * 
 * Useful tasks:
 * 
 *  - grunt - builds everything
 *  - grunt build-lang - recompiles gettext corpus (make sure you composer install before hand to get the build scripts!)
 *  - grunt build-css - Compile the SASS
 *  - grunt build-js - minify javascript and pass it through babel
 *  - grunt test - lint your js and css
 *  - grunt watch - look for changes and recompile as needed (useful for development)
 */

/*jshint ignore:start*/
/*jshint ignore:end*/

module.exports = function (grunt) {
  // Project configuration.
  grunt.initConfig({
    pkg: grunt.file.readJSON('package.json'),
    concat: {
      options: {
      },
      js: {
        files: {
          'js/service-worker.es6': [
	      'js/src/ServiceWorker.js'
	  ]
        }
      }
    },
    babel: {
      options: {
	sourceType: "module",
        sourceMaps: true,
        "presets": [
          [
            "@babel/preset-env",
            {
              "loose": true,
              "modules": false,
              "targets": {
                esmodules: true
              }
            },
          ]
        ]
      },
      dist: {
	files: [
	  {
	    expand: true,
	    cwd: 'js/',
	    src: ['*.es6'],
	    dest: 'js/',
	    ext: '.js',
	    extDot: 'last'
	  }]
      },
    },
    terser: {
	options: {
          sourceMap: true,
          ecma: 2017,
	},
        sourceMap: {
          url: "file.js.map"
        },
	dist: {
	    files: [{
		expand: true,
		cwd: "js/",
		src: ["*.js", "!*.min.js"],
		dest: "js",
		ext: ".min.js",
		extDot: 'last'
	    }]
	},
    },
    jshint: {
      // define the files to lint
      files: [
            'Gruntfile.js',
            'js/src/**/*.js'
      ],

      // configure JSHint (documented at http://www.jshint.com/docs/)
      options: {
        // more options here if you want to override JSHint defaults
        globals: {
          console: true,
          module: true,
          document: true,
          self: true,
        },
        node: true,
        browser: true,
	esversion: 6
      }
    },
    watch: {
        options: {
          dateFormat: function(time) {
            grunt.log.writeln('The watch finished in ' + time + 'ms at ' + (new Date()).toString());
            grunt.log.writeln('Waiting for more changes...');
          },
        },
        js: {
            files: ['js/src/**/*.js', 'Gruntfile.js'],
            tasks:  ['build-js', 'jshint']
        } 
    }

  });

  // Load the plugins
  grunt.loadNpmTasks('grunt-babel');
  grunt.loadNpmTasks('grunt-terser');
  grunt.loadNpmTasks('grunt-contrib-concat');
  grunt.loadNpmTasks('grunt-contrib-jshint');
  grunt.loadNpmTasks('grunt-contrib-watch');


  // Tests
  grunt.registerTask('test', ['jshint']);

  // Build language pack
  grunt.registerTask('build-lang', '', function () {

    /*jshint ignore:start*/
    const {execSync} = require('child_process');
    /*jshint ignore:end*/

    console.log("Building language files...");
    execSync('php idno.php build-lang all', {stdio: 'inherit'});
  });

  // Default task(s).
  grunt.registerTask('build-js', ['concat', 'babel', 'terser']);
  grunt.registerTask('default', ['build-js', 'build-lang', 'test']);
};
