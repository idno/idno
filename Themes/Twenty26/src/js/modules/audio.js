import Alpine from 'alpinejs';

/**
 * Audio player: the theme's own controls for an <audio> element.
 *
 * The element keeps its native controls in the markup, so a post plays without
 * JavaScript. When this component starts it hides them and shows a play button,
 * a seek bar (a range input, so it works from the keyboard) and the times.
 */
Alpine.data('audioPlayer', () => ({
    enhanced: false,
    playing: false,
    current: 0,
    duration: 0,

    init() {
        const audio = this.$refs.audio;
        if (!audio || typeof audio.play !== 'function') {
            return;
        }
        audio.removeAttribute('controls');
        this.enhanced = true;

        const readDuration = () => {
            this.duration = Number.isFinite(audio.duration) ? audio.duration : 0;
        };
        audio.addEventListener('loadedmetadata', readDuration);
        audio.addEventListener('durationchange', readDuration);
        audio.addEventListener('timeupdate', () => { this.current = audio.currentTime; });
        audio.addEventListener('play', () => {
            this.playing = true;
            // One player at a time
            document.querySelectorAll('audio, video').forEach((other) => {
                if (other !== audio && !other.paused) {
                    other.pause();
                }
            });
        });
        audio.addEventListener('pause', () => { this.playing = false; });
        audio.addEventListener('ended', () => { this.playing = false; });
        readDuration();
    },

    toggle() {
        const audio = this.$refs.audio;
        if (audio.paused) {
            const started = audio.play();
            if (started && typeof started.catch === 'function') {
                started.catch(() => { this.playing = false; });
            }
        } else {
            audio.pause();
        }
    },

    seek(event) {
        const audio = this.$refs.audio;
        const to = Number(event.target.value);
        if (Number.isFinite(to)) {
            audio.currentTime = to;
            this.current = to;
        }
    },

    time(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) {
            seconds = 0;
        }
        const whole = Math.floor(seconds);
        const h = Math.floor(whole / 3600);
        const m = Math.floor((whole % 3600) / 60);
        const s = String(whole % 60).padStart(2, '0');

        return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
    },
}));
