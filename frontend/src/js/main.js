import '../css/main.css';
import Alpine from 'alpinejs';

// Module imports — must come BEFORE Alpine.start() so Alpine components
// (e.g. composeModal) are registered before Alpine scans the DOM.
import './modules/editor.js';
import './modules/compose.js';
import './modules/autosave.js';
import './modules/interactions.js';
import './modules/media.js';
import './modules/mentions.js';

window.Alpine = Alpine;
Alpine.start();
