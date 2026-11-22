import Alpine from 'alpinejs';

Alpine.data('composeModal', () => ({
    open: false,
    step: 'picker', // 'picker' or 'editor'
    selectedType: null,

    init() {
        window.addEventListener('open-compose', () => {
            this.open = true;
            this.step = 'picker';
            this.selectedType = null;
        });
    },

    selectType(type) {
        this.selectedType = type;
        this.step = 'editor';
    },

    back() {
        this.step = 'picker';
        this.selectedType = null;
    },

    close() {
        this.open = false;
        this.step = 'picker';
        this.selectedType = null;
    },
}));
