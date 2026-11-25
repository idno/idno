# Building Idno

Once you have run `composer install`, Idno will run out of the box, and doesn't necessarily need to be "built" first. However, as a developer, you will sometimes need to perform certain steps in order for your changes (e.g. javascript / css) to be reflected.

## Building javascript

To simplify deployment of JS and CSS, Idno makes use of Grunt. 

A Gruntfile is provided which will perform minification of js and css for you, as well as execute other useful tasks.

To get going, install grunt, and the other developer dependencies:

```npm install```

Grunt is run through the `grunt` script in `package.json`, so you don't need to install it globally: ```npm run grunt -- <task>```. The tasks are:

* `build-js`: concatenates, transpiles (Babel) and minifies the javascript in `js/src`
* `build-lang`: regenerates the translation templates (see [Languages](languages/index.md))
* `test`: runs `jshint`
* `watch`: rebuilds when your javascript changes
* the default task runs `build-js`, `build-lang` and `test`

### Testing your changes

If you've made JS changes, you can check your code using ```npm run grunt -- test``` to run the `jshint` linter.

### Minify your changes

To get your javascript changes to be used by Idno, you'll need to build minified versions of them. Do this by running ```npm run grunt -- build-js``` (or the default grunt task, ```npm run grunt```, which builds everything).

## Building the Idno 2026 front end

The built-in look, Idno 2026, has its own style sheet and script, `css/idno-2026.min.css` and `js/idno-2026.min.js` (with `js/idno-2026.min.js.map`). They are built with Vite and Tailwind CSS from the sources in `frontend/`, and the built files are committed, so a site never needs to build them. To change them:

```
npm --prefix frontend ci
npm --prefix frontend run build
```

`npm --prefix frontend run dev` rebuilds when a source changes. The build reads the classes the templates use (`templates/default/` and `IdnoPlugins/*/templates/default/`), and writes straight into the project's `css/` and `js/` directories (Vite warns that its output directory is the parent of its root: that is intended, and nothing is emptied). `frontend/` is not served by a web server.

## Style sheets

Idno 2026's style sheet is built by Vite (above); Grunt builds no style sheet. The pages of the installation (`Idno\Install\InstallPage`) and the upgrade page carry their own small inline style, so that they need no file and no request.

!!! note "Watching for changes"
    If you're doing a lot of editing, you're probably going to forget to refresh your changes. Start your development session by running ```npm run grunt -- watch``` to look for changes in your javascript files, and to automatically build your changes!

## Building languages

See [Languages](languages/index.md) for more detail, but in short ```npm run grunt -- build-lang```

!!! note "Vagrant"
    As a developer, you may find it helpful to set up a virtual machine to see your code running. A Vagrant configuration for Idno can be found on [Github](https://github.com/mapkyca/known-vagrant).
