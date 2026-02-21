(function (wp) {
    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var RangeControl = wp.components.RangeControl;
    var ToggleControl = wp.components.ToggleControl;
    var CheckboxControl = wp.components.CheckboxControl;
    var Spinner = wp.components.Spinner;
    var __ = wp.i18n.__;
    var useEffect = wp.element.useEffect;
    var useState = wp.element.useState;
    var createElement = wp.element.createElement;

    registerBlockType('gallery-widget/gallery', {
        title: __('MediaHUB Gallerie', 'gallery-widget'),
        icon: 'format-gallery',
        category: 'media',
        description: __('Zeigt eine Bildergalerie mit Dates oder Collections an', 'gallery-widget'),
        supports: {
            html: false,
            align: ['wide', 'full']
        },
        attributes: {
            selectedDates: {
                type: 'array',
                default: []
            },
            selectedCollections: {
                type: 'array',
                default: []
            },
            columns: {
                type: 'number',
                default: 3
            },
            showTitle: {
                type: 'boolean',
                default: true
            }
        },

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var selectedDates = attributes.selectedDates;
            var selectedCollections = attributes.selectedCollections;
            var columns = attributes.columns;
            var showTitle = attributes.showTitle;
            var blockProps = useBlockProps();

            var datesState = useState([]);
            var dates = datesState[0];
            var setDates = datesState[1];

            var collectionsState = useState([]);
            var collections = collectionsState[0];
            var setCollections = collectionsState[1];

            var loadingState = useState(true);
            var loading = loadingState[0];
            var setLoading = loadingState[1];

            var baseUrl = galleryWidgetConfig.baseUrl || '';
            var proxyUrl = galleryWidgetConfig.proxyUrl || '';

            // Fetch available dates/collections
            useEffect(function () {
                if (baseUrl && proxyUrl) {
                    // Fetch dates via proxy with cache-busting parameter
                    fetch(proxyUrl + '/dates?nocache=' + Date.now(), {
                        headers: {
                            'X-WP-Nonce': galleryWidgetConfig.nonce
                        }
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            console.log('Dates API response:', data);
                            // Handle different response formats
                            if (Array.isArray(data)) {
                                // Extract date strings from objects like {date: "2024-01-01", count: 5}
                                var dateStrings = data.map(function (item) {
                                    return item.date || item.name || item;
                                });
                                setDates(dateStrings);
                            } else if (data && data.dates && Array.isArray(data.dates)) {
                                setDates(data.dates);
                            } else if (data && typeof data === 'object') {
                                // If data is an object with date keys, extract the keys
                                setDates(Object.keys(data));
                            } else {
                                setDates([]);
                            }
                        })
                        .catch(function (err) {
                            console.error('Error fetching dates:', err);
                            setDates([]);
                        });

                    // Fetch collections via proxy with cache-busting parameter
                    fetch(proxyUrl + '/collections?nocache=' + Date.now(), {
                        headers: {
                            'X-WP-Nonce': galleryWidgetConfig.nonce
                        }
                    })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            console.log('Collections API response:', data);
                            // Handle different response formats
                            if (Array.isArray(data)) {
                                // Extract collection names from objects
                                var collectionNames = data.map(function (item) {
                                    return item.name || item.id || item;
                                });
                                setCollections(collectionNames);
                            } else if (data && data.collections && Array.isArray(data.collections)) {
                                setCollections(data.collections);
                            } else {
                                setCollections([]);
                            }
                        })
                        .catch(function (err) {
                            console.error('Error fetching collections:', err);
                            setCollections([]);
                        })
                        .finally(function () {
                            setLoading(false);
                        });
                } else {
                    setLoading(false);
                }
            }, []);

            var toggleDate = function (date) {
                var newDates = selectedDates.includes(date)
                    ? selectedDates.filter(function (d) { return d !== date; })
                    : selectedDates.concat([date]);
                setAttributes({ selectedDates: newDates });
            };

            var toggleCollection = function (collection) {
                var newCollections = selectedCollections.includes(collection)
                    ? selectedCollections.filter(function (c) { return c !== collection; })
                    : selectedCollections.concat([collection]);
                setAttributes({ selectedCollections: newCollections });
            };

            return createElement(
                'div',
                blockProps,
                createElement(
                    InspectorControls,
                    null,
                    createElement(
                        PanelBody,
                        { title: __('Galerie Einstellungen', 'gallery-widget'), initialOpen: true },
                        createElement(RangeControl, {
                            label: __('Spalten', 'gallery-widget'),
                            value: columns,
                            onChange: function (value) { setAttributes({ columns: value }); },
                            min: 1,
                            max: 6
                        }),
                        createElement(ToggleControl, {
                            label: __('Titel anzeigen', 'gallery-widget'),
                            checked: showTitle,
                            onChange: function (value) { setAttributes({ showTitle: value }); }
                        })
                    ),
                    createElement(
                        PanelBody,
                        { title: __('Dates auswählen', 'gallery-widget'), initialOpen: false },
                        loading ? createElement(Spinner) :
                            !baseUrl ? createElement('p', null, __('Bitte konfigurieren Sie die Base URL in den Plugin-Einstellungen.', 'gallery-widget')) :
                                dates.length === 0 ? createElement('p', null, __('Keine Dates verfügbar.', 'gallery-widget')) :
                                    dates.map(function (date) {
                                        return createElement(CheckboxControl, {
                                            key: date,
                                            label: date,
                                            checked: selectedDates.includes(date),
                                            onChange: function () { toggleDate(date); }
                                        });
                                    })
                    ),
                    createElement(
                        PanelBody,
                        { title: __('Collections auswählen', 'gallery-widget'), initialOpen: false },
                        loading ? createElement(Spinner) :
                            !baseUrl ? createElement('p', null, __('Bitte konfigurieren Sie die Base URL in den Plugin-Einstellungen.', 'gallery-widget')) :
                                collections.length === 0 ? createElement('p', null, __('Keine Collections verfügbar.', 'gallery-widget')) :
                                    collections.map(function (collection) {
                                        var collId = collection.id || collection.name || collection;
                                        var collName = collection.name || collection;
                                        return createElement(CheckboxControl, {
                                            key: collId,
                                            label: collName,
                                            checked: selectedCollections.includes(collId),
                                            onChange: function () { toggleCollection(collId); }
                                        });
                                    })
                    )
                ),
                createElement(
                    'div',
                    { className: 'gallery-widget-editor' },
                    createElement(
                        'div',
                        { className: 'gallery-widget-icon' },
                        createElement('span', { className: 'dashicons dashicons-format-gallery' })
                    ),
                    createElement('h3', null, __('MediaHUB Gallerie', 'gallery-widget')),
                    !baseUrl && createElement(
                        'p',
                        { className: 'gallery-widget-notice' },
                        __('⚠️ Bitte konfigurieren Sie die Base URL in den Plugin-Einstellungen.', 'gallery-widget')
                    ),
                    selectedDates.length > 0 && createElement(
                        'div',
                        { className: 'gallery-widget-selection' },
                        createElement('strong', null, __('Ausgewählte Dates:', 'gallery-widget')),
                        createElement(
                            'ul',
                            null,
                            selectedDates.map(function (date) {
                                return createElement('li', { key: date }, date);
                            })
                        )
                    ),
                    selectedCollections.length > 0 && createElement(
                        'div',
                        { className: 'gallery-widget-selection' },
                        createElement('strong', null, __('Ausgewählte Collections:', 'gallery-widget')),
                        createElement(
                            'ul',
                            null,
                            selectedCollections.map(function (collection) {
                                return createElement('li', { key: collection }, collection);
                            })
                        )
                    ),
                    selectedDates.length === 0 && selectedCollections.length === 0 && baseUrl && createElement(
                        'p',
                        null,
                        __('Wählen Sie Dates oder Collections in den Block-Einstellungen aus.', 'gallery-widget')
                    )
                )
            );
        },

        save: function () {
            return null;
        }
    });
})(window.wp);
