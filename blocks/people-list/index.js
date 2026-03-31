/**
 * Internal dependencies
 */
import metadata from './block.json';
import {__} from '@wordpress/i18n';
import {generateUniqueId} from '../common.js';

// Module-level controllers used as fallbacks when per-instance refs are not provided
let invOptionsController = null;
let lastPath = null;
let previewController = null;

/**
 * Every block starts by registering a new block type definition.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
 */
wp.blocks.registerBlockType(metadata.name, {
    title: __('People List', 'TouchPoint-WP'),
    category: metadata.category,
    description: __('A list of People from TouchPoint', 'TouchPoint-WP'),
    icon: metadata.icon,
    apiVersion: metadata.apiVersion,
    attributes: {
        invId: {
            type: 'integer',
            default: 0,
        },
        memTypes: {
            type: 'array',
            default: [],
        },
        genders: {
            type: 'integer',
            default: 0,
        }
    },
    edit: function (props) {
        const {attributes, setAttributes} = props;
        const {invId} = attributes;
        const blockProps = wp.blockEditor.useBlockProps();
        // Use a ref so we generate the id once and it remains stable across renders.
        const placeholderIdRef = wp.element.useRef(generateUniqueId());
        const placeholderId = `tp-people-list-${placeholderIdRef.current}`;
        // Direct ref to the placeholder div so we can reliably update its content
        const placeholderRef = wp.element.useRef(null);
        // Per-instance refs for preview controller and lastPath so multiple blocks don't share state
        const previewControllerRef = wp.element.useRef(null);
        const lastPathRef = wp.element.useRef(null);
        const [filteredInvOptions, setFilteredInvOptions] = wp.element.useState([]);
        const [memTypeOptions, setMemTypeOptions] = wp.element.useState([]);
        const [genderOptions, setGenderOptions] = wp.element.useState([]);
        const [isLoading, setIsLoading] = wp.element.useState(true);
        // State to hold preview HTML so React renders it (avoids mutating DOM nodes React may replace).
        const [previewHtml, setPreviewHtml] = wp.element.useState(__("Loading Preview...", "TouchPoint-WP"));

        const getInvOptionsFromApi = async (searchQ) => {
            if (invOptionsController) {
                invOptionsController.abort(); // Abort previous API call
            }

            invOptionsController = new AbortController(); // Create a new AbortController
            setIsLoading(true);
            try {
                const response = await fetch(`/touchpoint-api/admin/involvementsearch?s=${searchQ}`, {signal: invOptionsController.signal});
                const data = await response.json();
                invOptionsController = null;
                const formattedOptions = data
                    .map((inv) => ({
                        label: `${inv.organizationName}  (${inv.organizationId})`,
                        value: Number(inv.organizationId),
                    }));

                setFilteredInvOptions(formattedOptions);
                setIsLoading(false);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    console.error('Error fetching post types:', error);
                    setIsLoading(false);
                }
            }
        }

        const getMemTypeOptionsFromApi = async () => {
            try {
                const response = await fetch(`/touchpoint-api/admin/memtypes?inv=${invId}`);
                const data = await response.json();
                const formattedOptions = data
                    .map((type) => ({
                        label: type.description,
                        value: type.id,
                    }));

                // Add an option for "All Leaders" with value of -1
                formattedOptions.unshift({
                    label: __('All Leaders', 'TouchPoint-WP'),
                    value: -1,
                });

                // add an option for "All Members" with value of []
                formattedOptions.unshift({
                    label: __('All Members', 'TouchPoint-WP'),
                    value: 0,
                });

                setMemTypeOptions(formattedOptions);
            } catch (error) {
                console.error('Error fetching member types:', error);
            }
        }

        const getGenderOptionsFromApi = async () => {
            try {
                const response = await fetch(`/touchpoint-api/lookup/genders`);
                const data = await response.json();

                // format options for SelectControl, with an "Any" option at the beginning
                const formattedOptions = data
                    .map((gender) => ({
                        label: gender.description,
                        value: gender.id,
                    }));

                formattedOptions.unshift({
                    label: __('Any', 'TouchPoint-WP'),
                    value: 0,
                })

                setGenderOptions(formattedOptions);

            } catch (error) {
                console.error('Error fetching genders:', error);
            }
        }

        wp.element.useEffect(() => {
            getGenderOptionsFromApi();
        }, []);

        // get involvement options from API on initial load
        wp.element.useEffect(() => {
            getInvOptionsFromApi(invId);
        }, [invId]);

        wp.element.useEffect(() => {
            getMemTypeOptionsFromApi(invId);
        }, [invId]);

        // Update preview
        wp.element.useEffect(() => {
            // Some editor render timing can cause the ref to be null on first effect run.
            // Retry a few times with a small delay to ensure the DOM node exists before updating.
            const tryUpdate = (retries = 5) => {
                const el = placeholderRef.current || document.getElementById(placeholderId);
                if (el) {
                    updateListContent(invId, attributes, blockProps, placeholderId, el, previewControllerRef, lastPathRef, setPreviewHtml);
                } else if (retries > 0) {
                    setTimeout(() => tryUpdate(retries - 1), 100);
                } else {
                    // final attempt with whatever we have
                    updateListContent(invId, attributes, blockProps, placeholderId, placeholderRef.current, previewControllerRef, lastPathRef, setPreviewHtml);
                }
            };

            tryUpdate();
        }, [invId, attributes, blockProps.className]);

        return (
            <div {...blockProps}>
                {/* Ensure preview styles are available inside the editor canvas/preview. */}
                <link rel="stylesheet" href="/wp-content/plugins/touchpoint-wp/assets/template/block-preview-style.css?ver=0.0.96" />
                <link rel="stylesheet" href="/wp-content/plugins/touchpoint-wp/assets/template/actions-style.css?ver=0.0.96" />
                <link rel="stylesheet" href="/wp-content/plugins/touchpoint-wp/assets/template/partials-template-style.css?ver=0.0.96" />
                <wp.blockEditor.InspectorControls>
                    <wp.components.PanelBody title={__('Settings', 'TouchPoint-WP')}>
                        <wp.components.ComboboxControl
                            label={__("Involvement", "TouchPoint-WP")}
                            value={invId}
                            onChange={(v) => {
                                const numeric = Number(v);
                                setAttributes({invId: numeric});
                                // pass the newly selected value to the search so suggestions update immediately
                                getInvOptionsFromApi(v);
                            }}
                            isLoading={isLoading}
                            options={filteredInvOptions}
                            onFilterValueChange={getInvOptionsFromApi}
                            __next40pxDefaultSize={true}
                            __nextHasNoMarginBottom={true}
                        />
                    </wp.components.PanelBody>
                </wp.blockEditor.InspectorControls>
                <wp.blockEditor.InspectorAdvancedControls>
                    <wp.components.SelectControl
                        multiple
                        label={__("Filter by Member Types", "TouchPoint-WP")}
                        help={__("This option allows you to filter the people that are shown based on their member type in the selected involvement. By default, all members (not prospect or pending) are shown.", "TouchPoint-WP")}
                        value={attributes.memTypes}
                        options={memTypeOptions}
                        onChange={(selected) => {
                            // selected may be an array of strings (from the UI) or numbers.
                            const arr = Array.isArray(selected) ? selected.map(s => Number(s)) : [Number(selected)];

                            // if "All Leaders" is selected, set memTypes to [-1]
                            if (arr.includes(-1)) {
                                setAttributes({memTypes: [-1]});
                            }
                            // if "All Members" is selected, set memTypes to [0]
                            else if (arr.includes(0)) {
                                setAttributes({memTypes: [0]});
                            }
                            else {
                                setAttributes({memTypes: arr});
                            }
                        }}
                        __next40pxDefaultSize={true}
                        __nextHasNoMarginBottom={true}
                    />
                    <wp.components.SelectControl
                        label={__("Filter by Gender", "TouchPoint-WP")}
                        help={__("This option allows you to filter the people that are shown based on gender. By default, gender filters are not applied.", "TouchPoint-WP")}
                        value={attributes.genders}
                        multiple={false}
                        options={genderOptions}
                        onChange={(selected) => {
                            // normalize to an array of numbers
                            const arr = Array.isArray(selected) ? selected.map(s => Number(s)) : [Number(selected)];
                            // if "Any" (value 0) selected, store empty array to mean no filter
                            if (arr.includes(0)) {
                                setAttributes({genders: 0});
                            } else {
                                setAttributes({genders: arr});
                            }
                        }}
                        __next40pxDefaultSize={true}
                        __nextHasNoMarginBottom={true}
                    />
                </wp.blockEditor.InspectorAdvancedControls>
                <div id={placeholderId} ref={placeholderRef} className="tp-preview-block person-list" data-preview-message={__('Preview Only', 'TouchPoint-WP')} data-preview-length={previewHtml ? previewHtml.length : 0}>
                    <wp.element.RawHTML>{previewHtml}</wp.element.RawHTML>
                </div>
            </div>
         );
    },
    save: function (props) {
        const {attributes} = props;
        const {invId, genders, memTypes} = attributes;

        const blockProps = wp.blockEditor.useBlockProps.save();
        let additionalClasses = blockProps.className || '';

        // remove is-selected from additionalClasses if present.  Replace any double-spaces with singles.
        additionalClasses = additionalClasses.replace('is-selected', '').replace(/\s+/g, ' ').trim();

        const genderClause = genders === 0 ? "" : ` genders=${genders}`
        const memTypesClause = memTypes !== [0] && memTypes.length > 0 ? ` memTypes=${memTypes.join(',')}` : "";

        return `[TP-People class="${additionalClasses}" invId="${invId}"${genderClause}${memTypesClause}]`;
    },
});

function updateListContent(invId, attributes, blockProps, placeholderId, placeholderEl, previewControllerRef, lastPathRef, setPreviewHtml) {

     // Prefer the provided placeholder element (ref). Fallback to document lookup when not provided.
     const placeholder = placeholderEl || document.getElementById(placeholderId);
     if (!invId || invId <= 0) {
         const msg = '<div class="tp-preview-block-error">' + __('Please select an Involvement to display.', 'TouchPoint-WP') + '</div>';
         if (setPreviewHtml) {
             setPreviewHtml(msg);
         } else if (placeholder) {
             placeholder.innerHTML = msg;
         }
         return;
     }

    const memTypesParam = attributes.memTypes ? attributes.memTypes.join(',') : '';
    const gendersParam = attributes.genders ? attributes.genders.join(',') : '';
    // encode className to avoid invalid URL characters
    const cls = encodeURIComponent(blockProps.className || '');
    const newPath = `/touchpoint-api/person/list?invId=${encodeURIComponent(invId)}&memType=${encodeURIComponent(memTypesParam)}&gender=${encodeURIComponent(gendersParam)}&class=${cls}&context=block-preview`;

     // Use per-instance refs when provided, otherwise fall back to module-level vars.
     const lastPathHolder = lastPathRef && typeof lastPathRef === 'object' ? lastPathRef : {current: lastPath};
     const previewControllerHolder = previewControllerRef && typeof previewControllerRef === 'object' ? previewControllerRef : {current: previewController};

    if (lastPathHolder.current === newPath) {
        return;
    }

    if (previewControllerHolder.current) {
        try { previewControllerHolder.current.abort(); } catch (e) { /* ignore */ }
    }

    previewControllerHolder.current = new AbortController();
    lastPathHolder.current = newPath;

    // If using module-level fallback, keep module vars in sync
    if (!previewControllerRef) previewController = previewControllerHolder.current;
    if (!lastPathRef) lastPath = lastPathHolder.current;

    fetch(newPath, {signal: previewControllerHolder.current.signal})
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.text();
        })
        .then(data => {
            if (setPreviewHtml) {
                try { setPreviewHtml(data); } catch (e) { console.error('setPreviewHtml error', e); }
            }
            // Also attempt to write directly into the placeholder DOM node in case React render does not take effect
            if (placeholder && placeholder.innerHTML !== data) {
                try { placeholder.innerHTML = data; } catch (e) { console.error('Direct placeholder.innerHTML error', e); }
            }
        })
        .catch((err) => {
            // If the fetch was aborted we don't need to log an error.
            if (err.name !== 'AbortError') {
                console.error('Error fetching preview:', err);
                const msg = '<div class="tp-preview-block-error">' + __('Unable to load preview.', 'TouchPoint-WP') + '</div>';
                if (setPreviewHtml) {
                    try { setPreviewHtml(msg); } catch (e) { console.error('setPreviewHtml error', e); }
                }
                if (placeholder) {
                    try { placeholder.innerHTML = msg; } catch (e) { console.error('Direct placeholder.innerHTML error', e); }
                }
             }
         });
 }
