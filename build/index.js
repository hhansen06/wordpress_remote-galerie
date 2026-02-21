import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, ToggleControl, CheckboxControl, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import './editor.css';

registerBlockType('gallery-widget/gallery', {
    edit: (props) => {
        const { attributes, setAttributes } = props;
        const { selectedDates, selectedCollections, columns, showTitle } = attributes;
        const blockProps = useBlockProps();

        const [dates, setDates] = useState([]);
        const [collections, setCollections] = useState([]);
        const [loading, setLoading] = useState(true);
        const [baseUrl, setBaseUrl] = useState('');

        // Fetch base URL and available dates/collections
        useEffect(() => {
            // Get base URL from WordPress options
            wp.apiFetch({ path: '/wp/v2/settings' }).then((settings) => {
                const url = settings.gallery_widget_base_url || '';
                setBaseUrl(url);

                if (url) {
                    // Fetch dates
                    fetch(`${url}/api/public/dates`)
                        .then(response => response.json())
                        .then(data => setDates(Array.isArray(data) ? data : []))
                        .catch(err => console.error('Error fetching dates:', err));

                    // Fetch collections
                    fetch(`${url}/api/public/collections`)
                        .then(response => response.json())
                        .then(data => setCollections(Array.isArray(data) ? data : []))
                        .catch(err => console.error('Error fetching collections:', err))
                        .finally(() => setLoading(false));
                } else {
                    setLoading(false);
                }
            });
        }, []);

        const toggleDate = (date) => {
            const newDates = selectedDates.includes(date)
                ? selectedDates.filter(d => d !== date)
                : [...selectedDates, date];
            setAttributes({ selectedDates: newDates });
        };

        const toggleCollection = (collection) => {
            const newCollections = selectedCollections.includes(collection)
                ? selectedCollections.filter(c => c !== collection)
                : [...selectedCollections, collection];
            setAttributes({ selectedCollections: newCollections });
        };

        return (
            <div {...blockProps}>
                <InspectorControls>
                    <PanelBody title={__('Galerie Einstellungen', 'gallery-widget')}>
                        <RangeControl
                            label={__('Spalten', 'gallery-widget')}
                            value={columns}
                            onChange={(value) => setAttributes({ columns: value })}
                            min={1}
                            max={6}
                        />
                        <ToggleControl
                            label={__('Titel anzeigen', 'gallery-widget')}
                            checked={showTitle}
                            onChange={(value) => setAttributes({ showTitle: value })}
                        />
                    </PanelBody>

                    <PanelBody title={__('Dates auswählen', 'gallery-widget')} initialOpen={true}>
                        {loading ? (
                            <Spinner />
                        ) : !baseUrl ? (
                            <p>{__('Bitte konfigurieren Sie die Base URL in den Plugin-Einstellungen.', 'gallery-widget')}</p>
                        ) : dates.length === 0 ? (
                            <p>{__('Keine Dates verfügbar.', 'gallery-widget')}</p>
                        ) : (
                            dates.map((date) => (
                                <CheckboxControl
                                    key={date}
                                    label={date}
                                    checked={selectedDates.includes(date)}
                                    onChange={() => toggleDate(date)}
                                />
                            ))
                        )}
                    </PanelBody>

                    <PanelBody title={__('Collections auswählen', 'gallery-widget')}>
                        {loading ? (
                            <Spinner />
                        ) : !baseUrl ? (
                            <p>{__('Bitte konfigurieren Sie die Base URL in den Plugin-Einstellungen.', 'gallery-widget')}</p>
                        ) : collections.length === 0 ? (
                            <p>{__('Keine Collections verfügbar.', 'gallery-widget')}</p>
                        ) : (
                            collections.map((collection) => (
                                <CheckboxControl
                                    key={collection.id || collection.name}
                                    label={collection.name || collection}
                                    checked={selectedCollections.includes(collection.id || collection.name || collection)}
                                    onChange={() => toggleCollection(collection.id || collection.name || collection)}
                                />
                            ))
                        )}
                    </PanelBody>
                </InspectorControls>

                <div className="gallery-widget-editor">
                    <div className="gallery-widget-icon">
                        <span className="dashicons dashicons-format-gallery"></span>
                    </div>
                    <h3>{__('MediaHUB Gallerie', 'gallery-widget')}</h3>
                    {!baseUrl && (
                        <p className="gallery-widget-notice">
                            {__('⚠️ Bitte konfigurieren Sie die Base URL in den Plugin-Einstellungen.', 'gallery-widget')}
                        </p>
                    )}
                    {selectedDates.length > 0 && (
                        <div className="gallery-widget-selection">
                            <strong>{__('Ausgewählte Dates:', 'gallery-widget')}</strong>
                            <ul>
                                {selectedDates.map(date => (
                                    <li key={date}>{date}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {selectedCollections.length > 0 && (
                        <div className="gallery-widget-selection">
                            <strong>{__('Ausgewählte Collections:', 'gallery-widget')}</strong>
                            <ul>
                                {selectedCollections.map(collection => (
                                    <li key={collection}>{collection}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {selectedDates.length === 0 && selectedCollections.length === 0 && baseUrl && (
                        <p>{__('Wählen Sie Dates oder Collections in den Block-Einstellungen aus.', 'gallery-widget')}</p>
                    )}
                </div>
            </div>
        );
    },

    save: (props) => {
        const { attributes } = props;
        const blockProps = useBlockProps.save();

        return (
            <div {...blockProps}
                data-dates={JSON.stringify(attributes.selectedDates)}
                data-collections={JSON.stringify(attributes.selectedCollections)}
                data-columns={attributes.columns}
                data-show-title={attributes.showTitle}>
                <div className="gallery-widget-placeholder">
                    {__('Galerie wird geladen...', 'gallery-widget')}
                </div>
            </div>
        );
    }
});
