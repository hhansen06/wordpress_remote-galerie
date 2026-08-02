(function () {
    'use strict';

    // Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function () {
        initGalleryWidgets();
        initGalleryArchiveList();
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
     * Initialize standalone archive list items
     */
    function initGalleryArchiveList() {
        const archiveItems = document.querySelectorAll('.gallery-widget-archive-item');

        archiveItems.forEach((item) => {
            const sourceType = item.getAttribute('data-source-type') || '';
            const sourceValue = item.getAttribute('data-source-value') || '';
            const previewCount = parseInt(item.getAttribute('data-preview-count') || '4', 10);
            const selectedPreviews = safeJsonParse(item.getAttribute('data-selected-previews') || '[]', []);
            const content = item.querySelector('.gallery-widget-archive-content');
            const title = item.getAttribute('data-set-title') || 'Galerie';

            if (!content || !sourceType || !sourceValue) {
                return;
            }

            loadArchiveItem(content, title, sourceType, sourceValue, previewCount, selectedPreviews);
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
     * Load one configured archive item
     */
    async function loadArchiveItem(container, title, sourceType, sourceValue, previewCount, selectedPreviews) {
        const proxyUrl = galleryWidgetConfig.proxyUrl;

        if (!proxyUrl) {
            container.innerHTML = '<div class="gallery-widget-error">Proxy URL ist nicht konfiguriert.</div>';
            return;
        }

        container.innerHTML = '<div class="gallery-widget-loading">Lade Galerie...</div>';

        try {
            const queryParam = sourceType === 'date' ? 'date' : 'collection';
            const response = await fetch(`${proxyUrl}/images?${queryParam}=${encodeURIComponent(sourceValue)}`, {
                headers: {
                    'X-WP-Nonce': galleryWidgetConfig.nonce
                }
            });

            if (!response.ok) {
                container.innerHTML = '<div class="gallery-widget-error">Galerie konnte nicht geladen werden.</div>';
                return;
            }

            const data = await response.json();
            const allImages = Array.isArray(data) ? data : (data.items || []);

            if (!Array.isArray(allImages) || allImages.length === 0) {
                container.innerHTML = '<div class="gallery-widget-empty">Keine Bilder gefunden.</div>';
                return;
            }

            const normalizedSelectedPreviews = Array.isArray(selectedPreviews) ? selectedPreviews : [];
            const previewImages = getPreviewImages(allImages, normalizedSelectedPreviews, previewCount);

            renderArchivePreview(container, title, allImages, previewImages);
        } catch (error) {
            console.error('Error loading archive item:', error);
            container.innerHTML = '<div class="gallery-widget-error">Fehler beim Laden der Galerie.</div>';
        }
    }

    /**
     * Determine preview images based on selected previews or fallback count
     */
    function getPreviewImages(allImages, selectedPreviews, previewCount) {
        if (selectedPreviews.length > 0) {
            const selectedSet = new Set(selectedPreviews.map((entry) => String(entry).toLowerCase()));
            const explicitSelection = allImages.filter((image) => {
                const candidates = [
                    image.public_url,
                    image.url,
                    image.src,
                    image.thumbnail_url,
                    image.thumbnail
                ].filter(Boolean);
                return candidates.some((url) => {
                    const hash = extractHashFromUrl(String(url));
                    return hash && selectedSet.has(hash);
                });
            });

            if (explicitSelection.length > 0) {
                return explicitSelection;
            }
        }

        const safePreviewCount = Math.max(1, parseInt(previewCount, 10) || 4);
        return allImages.slice(0, safePreviewCount);
    }

    /**
     * Extract media hash from API/proxy URL
     */
    function extractHashFromUrl(url) {
        const match = url.match(/([a-f0-9]{64})/i);
        return match ? match[1].toLowerCase() : '';
    }

    /**
     * Render archive item preview grid and lightbox
     */
    function renderArchivePreview(container, title, allImages, previewImages) {
        let html = '<div class="gallery-widget-archive-preview">';
        html += `<div class="gallery-widget-archive-meta">${escapeHtml(title)} (${allImages.length} Bilder)</div>`;
        html += '<div class="gallery-widget-grid columns-4">';

        previewImages.forEach((image) => {
            const imageUrl = image.public_url || image.url || image.src || image.thumbnail || '';
            const thumbUrl = image.thumbnail_url || image.thumbnail || imageUrl;
            const imageTitle = image.title || image.name || '';
            const imageAlt = image.alt || imageTitle || 'Bild';
            const allIndex = allImages.indexOf(image);

            html += `
                <div class="gallery-widget-item" data-index="${allIndex}" data-url="${escapeHtml(imageUrl)}">
                    <img src="${escapeHtml(thumbUrl)}"
                         alt="${escapeHtml(imageAlt)}"
                         title="${escapeHtml(imageTitle)}"
                         loading="lazy">
                </div>
            `;
        });

        html += '</div></div>';

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
        setupLightbox(container, allImages);
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
                const configuredIndex = parseInt(item.getAttribute('data-index') || index, 10);
                currentIndex = isNaN(configuredIndex) ? index : configuredIndex;
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
     * Parse JSON with fallback
     */
    function safeJsonParse(value, fallbackValue) {
        try {
            return JSON.parse(value);
        } catch (e) {
            return fallbackValue;
        }
    }

    /**
     * Generate a unique ID
     */
    function generateId() {
        return 'id-' + Math.random().toString(36).substr(2, 9);
    }

})();
