import Alpine from 'alpinejs';

// Divine particles system
Alpine.data('divineParticles', () => ({
    init() {
        const container = this.$el;
        const createParticle = () => {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            particle.style.left = Math.random() * 100 + '%';
            particle.style.animationDuration = (8 + Math.random() * 12) + 's';
            particle.style.animationDelay = Math.random() * 5 + 's';
            particle.style.width = (2 + Math.random() * 3) + 'px';
            particle.style.height = particle.style.width;
            container.appendChild(particle);
            setTimeout(() => particle.remove(), 20000);
        };
        // Create initial particles
        for (let i = 0; i < 15; i++) {
            setTimeout(createParticle, i * 400);
        }
        // Continuously create particles
        setInterval(createParticle, 1500);
    }
}));

// Gallery lightbox
Alpine.data('lightbox', () => ({
    open: false,
    current: 0,
    images: [],
    init() {
        this.images = Array.from(this.$el.querySelectorAll('[data-lightbox-src]')).map(el => el.dataset.lightboxSrc);
    },
    show(index) { this.current = index; this.open = true; document.body.classList.add('overflow-hidden'); },
    close() { this.open = false; document.body.classList.remove('overflow-hidden'); },
    next() { this.current = (this.current + 1) % this.images.length; },
    prev() { this.current = (this.current - 1 + this.images.length) % this.images.length; },
}));

window.Alpine = Alpine;
Alpine.start();
