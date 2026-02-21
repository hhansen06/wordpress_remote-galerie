(function () {
    'use strict';

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function () {
        initGalleryWidgets();
    });

    /**
     * Initialize all gallery widgets on the page
     */
    function initGalleryWidgets() {
        const galleries = document.querySelectorAll('.wp-block-gallery-widget-gallery');

        galleries.forEach(gallery => {
            const dates = JSON.parse(gallery.getAttribute('data-dates') || '[]');
            const collections = JSON.parse(gallery.getAttribute('data-collections') || '[]');
            const columns = parseInt(gallery.getAttribute('data-columns') || '3');
            const showTitle = gallery.getAttribute('data-show-title') === 'true';

            if (dates.length === 0 && collections.length === 0) {
                gallery.innerHTML = '<div class="gallery-widget-empty">Keine Galerie ausgewählt.</div>';
                return;
            }

            loadGalleryImages(gallery, dates, collections, columns, showTitle);
        });
    }

    /**
     * Load images for a gallery
     */
    async function loadGalleryImages(container, dates, collections, columns, showTitle) {
        const proxyUrl = galleryWidgetConfig.proxyUrl;

        if (!proxyUrl) {
            container.innerHTML = '<div class="gallery-widget-error">Proxy URL ist nicht konfiguriert.</div>';
            return;
        }

        // Show loading state
        container.innerHTML = '<div class="gallery-widget-loading">Lade Bilder...</div>';

        try {
            const allImages = [];

            // Fetch images for each date
            for (const date of dates) {
                try {
                    const response = await fetch(`${proxyUrl}/images?date=${encodeURIComponent(date)}`, {
                        headers: {
                            'X-WP-Nonce': galleryWidgetConfig.nonce
                        }
                    });
                    if (response.ok) {
                        const data = await response.json();
                        console.log('Date response:', data);
                        // Handle both array and object with items property
                        const images = Array.isArray(data) ? data : (data.items || []);
                        if (Array.isArray(images)) {
                            allImages.push(...images.map(img => ({
                                ...img,
                                sourceType: 'date',
                                source: date
                            })));
                        }
                    }
                } catch (error) {
                    console.error(`Error fetching images for date ${date}:`, error);
                }
            }

            // Fetch images for each collection
            for (const collection of collections) {
                try {
                    const response = await fetch(`${proxyUrl}/images?collection=${encodeURIComponent(collection)}`, {
                        headers: {
                            'X-WP-Nonce': galleryWidgetConfig.nonce
                        }
                    });
                    if (response.ok) {
                        const data = await response.json();
                        console.log('Collection response:', data);
                        // Handle both array and object with items property
                        const images = Array.isArray(data) ? data : (data.items || []);
                        if (Array.isArray(images)) {
                            allImages.push(...images.map(img => ({
                                ...img,
                                sourceType: 'collection',
                                source: collection
                            })));
                        }
                    }
                } catch (error) {
                    console.error(`Error fetching images for collection ${collection}:`, error);
                }
            }

            if (allImages.length === 0) {
                container.innerHTML = '<div class="gallery-widget-empty">Keine Bilder gefunden.</div>';
                return;
            }

            renderGallery(container, allImages, columns, showTitle);

        } catch (error) {
            console.error('Error loading gallery:', error);
            container.innerHTML = '<div class="gallery-widget-error">Fehler beim Laden der Galerie.</div>';
        }
    }

    /**
     * Render the gallery HTML
     */
    function renderGallery(container, images, columns, showTitle) {
        let html = '<div class="gallery-widget-container">';

        if (showTitle) {
            html += '<h3 class="gallery-widget-title">Galerie</h3>';
        }

        html += `<div class="gallery-widget-grid columns-${columns}">`;

        images.forEach((image, index) => {
            // Support multiple field names for backward compatibility
            const imageUrl = image.public_url || image.url || image.src || image.thumbnail || '';
            const thumbUrl = image.thumbnail_url || image.thumbnail || image.public_url || imageUrl;
            const imageTitle = image.title || image.name || '';
            const imageAlt = image.alt || imageTitle || 'Bild';

            console.log('Image object:', image);
            console.log('Using URL:', imageUrl);

            html += `
                <div class="gallery-widget-item" data-index="${index}" data-url="${escapeHtml(imageUrl)}">
                    <img src="${escapeHtml(thumbUrl || imageUrl)}" 
                         alt="${escapeHtml(imageAlt)}" 
                         title="${escapeHtml(imageTitle)}"
                         loading="lazy">
                </div>
            `;
        });

        html += '</div></div>';

        // Add lightbox
        html += `
            <div class="gallery-widget-lightbox" id="lightbox-${generateId()}">
                <button class="gallery-widget-lightbox-close" aria-label="Schließen">✕</button>
                <button class="gallery-widget-lightbox-prev" aria-label="Vorheriges Bild">‹</button>
                <div class="gallery-widget-lightbox-content">
                    <img src="" alt="">
                </div>
                <button class="gallery-widget-lightbox-next" aria-label="Nächstes Bild">›</button>
            </div>
        `;

        container.innerHTML = html;

        // Add click handlers for lightbox
        setupLightbox(container, images);
    }

    /**
     * Setup lightbox functionality
     */
    function setupLightbox(container, images) {
        const items = container.querySelectorAll('.gallery-widget-item');
        const lightbox = container.querySelector('.gallery-widget-lightbox');
        const lightboxImg = lightbox.querySelector('img');
        const closeBtn = lightbox.querySelector('.gallery-widget-lightbox-close');
        const prevBtn = lightbox.querySelector('.gallery-widget-lightbox-prev');
        const nextBtn = lightbox.querySelector('.gallery-widget-lightbox-next');

        let currentIndex = 0;

        // Open lightbox
        items.forEach((item, index) => {
            item.addEventListener('click', function () {
                currentIndex = index;
                showImage(currentIndex);
                lightbox.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        });

        // Close lightbox
        closeBtn.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', function (e) {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });

        // Navigation
        prevBtn.addEventListener('click', function () {
            currentIndex = (currentIndex - 1 + images.length) % images.length;
            showImage(currentIndex);
        });

        nextBtn.addEventListener('click', function () {
            currentIndex = (currentIndex + 1) % images.length;
            showImage(currentIndex);
        });

        // Keyboard navigation
        document.addEventListener('keydown', function (e) {
            if (!lightbox.classList.contains('active')) return;

            if (e.key === 'Escape') {
                closeLightbox();
            } else if (e.key === 'ArrowLeft') {
                prevBtn.click();
            } else if (e.key === 'ArrowRight') {
                nextBtn.click();
            }
        });

        function showImage(index) {
            const image = images[index];
            const imageUrl = image.public_url || image.url || image.src || image.large || '';
            const imageTitle = image.title || image.name || '';

            console.log('Lightbox showing image:', image, 'URL:', imageUrl);
            lightboxImg.src = imageUrl;
            lightboxImg.alt = imageTitle;
        }

        function closeLightbox() {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    /**
     * Helper function to escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Generate a unique ID
     */
    function generateId() {
        return 'id-' + Math.random().toString(36).substr(2, 9);
    }

})();
